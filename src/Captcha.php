<?php

declare(strict_types=1);

namespace Lyyteam\HyperfGraphicCaptcha;

use Closure;
use Exception;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\RequestInterface;
use Lyyteam\HyperfGraphicCaptcha\Support\RoutePrefix;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use RuntimeException;
use Throwable;

/*
 * Graphic captcha generator and verifier for Hyperf.
 * Instances are request-scoped; mutable image state stays local to each call.
 */
class Captcha
{
    // Default cookie name that carries the captcha cache key for browser flows
    private const DEFAULT_COOKIE_NAME = 'graphic_captcha_key';

    // Default cache key prefix used when storing captcha hashes
    private const DEFAULT_CACHE_PREFIX = 'graphic_captcha:';

    // Maps legacy camelCase config keys onto snake_case option names
    private const KEY_ALIASES = [
        'codeSet' => 'code_set',
        'useZh' => 'use_zh',
        'zhSet' => 'zh_set',
        'useImgBg' => 'use_img_bg',
        'curveStrength' => 'curve_strength',
        'noiseStrength' => 'noise_strength',
        'fontSize' => 'font_size',
        'imageH' => 'image_h',
        'imageW' => 'image_w',
    ];

    // Holds the application configuration service
    private ConfigInterface $config;

    // Holds the PSR-16 cache used to persist captcha hashes with TTL
    private CacheInterface $cache;

    // Holds the PSR-7 response prototype used when returning PNG output
    private ResponseInterface $response;

    // Holds the current HTTP request used to resolve captcha keys from cookie/header/input
    private RequestInterface $request;

    /*
     * Creates a captcha service with config, cache, response, and request dependencies.
     * @param ConfigInterface $config Application config repository
     * @param CacheInterface $cache PSR-16 cache (Hyperf Cache)
     * @param ResponseInterface $response PSR-7 response prototype
     * @param RequestInterface $request Current HTTP request
     */
    public function __construct(
        ConfigInterface $config,
        CacheInterface $cache,
        ResponseInterface $response,
        RequestInterface $request
    ) {
        // Stores the injected config repository for later option resolution
        $this->config = $config;
        // Stores the injected cache for captcha hash persistence
        $this->cache = $cache;
        // Stores the injected response prototype for image responses
        $this->response = $response;
        // Stores the injected request for resolving captcha cache keys
        $this->request = $request;
    }

    /*
     * Builds a captcha image (or API payload) and stores the answer hash in cache.
     * @param null|string $config_name Named config group under captcha.*; null uses defaults
     * @return ResponseInterface|array PNG response, or array when api mode is enabled
     * @throws Exception
     */
    public function create(?string $config_name = null): ResponseInterface|array
    {
        // Holds a sanitized named profile so direct create() calls stay safe
        $safe_config_name = $config_name === null || $config_name === ''
            ? null
            : RoutePrefix::sanitizeConfigName($config_name);

        // Fails fast when GD / FreeType is unavailable instead of fatal erroring mid-render
        $this->assertGdAvailable();

        // Holds fully resolved and sanitized captcha drawing options for this request
        $options = $this->normalizeOptions($this->resolveOptions($safe_config_name));

        // Arithmetic mode forces ASCII digits and a fixed visible length hint
        if (! empty($options['math'])) {
            $options['use_zh'] = false;
            $options['length'] = 5;
        }

        // Holds the generated plaintext value and bcrypt hash (not yet persisted)
        $generator = $this->generate($options);

        // Holds drawable glyphs used for both width estimation and later rendering
        $preview_glyphs = array_values(array_filter(
            $this->splitCaptchaText((string) $generator['value'], ! empty($options['use_zh'])),
            static fn (string $glyph): bool => $glyph !== ''
        ));
        // Fails before GD allocation when generation produced nothing drawable
        if ($preview_glyphs === []) {
            throw new RuntimeException('Failed to split captcha text.');
        }

        // Auto-calculates image width when the config leaves it at zero
        if ((int) $options['image_w'] <= 0) {
            // Uses real drawn glyph count so Chinese / math widths stay accurate
            $draw_length = max(1, count($preview_glyphs));
            // Stores font size used for width formula
            $font_size = (float) $options['font_size'];
            $options['image_w'] = (int) ($draw_length * $font_size * 1.5 + $draw_length * $font_size / 2);
        }

        // Auto-calculates image height when the config leaves it at zero
        if ((int) $options['image_h'] <= 0) {
            $options['image_h'] = (int) ((float) $options['font_size'] * 2.5);
        }

        // Casts and clamps dimensions so GD never allocates unbounded memory
        $options['image_w'] = max(20, min(800, (int) $options['image_w']));
        $options['image_h'] = max(20, min(300, (int) $options['image_h']));
        // Ensures the canvas is at least tall enough for the selected font baseline
        $options['image_h'] = max($options['image_h'], min(300, (int) $options['font_size'] + 8));

        // Holds the truecolor GD image resource for the captcha canvas
        $image = @imagecreatetruecolor($options['image_w'], $options['image_h']);
        if ($image === false) {
            throw new RuntimeException('Failed to create captcha image resource.');
        }

        // Holds raw PNG bytes produced inside the guarded block
        $content = null;

        try {
            imagealphablending($image, true);
            imagesavealpha($image, true);

            // Holds the allocated background color with configured alpha transparency
            $background_color = imagecolorallocatealpha(
                $image,
                (int) $options['bg'][0],
                (int) $options['bg'][1],
                (int) $options['bg'][2],
                (int) $options['alpha']
            );
            if ($background_color === false) {
                throw new RuntimeException('Failed to allocate captcha background color.');
            }
            imagefill($image, 0, 0, $background_color);

            // Holds a random dark color used for captcha glyphs and curves
            $color = imagecolorallocate($image, mt_rand(1, 150), mt_rand(1, 150), mt_rand(1, 150));
            if ($color === false) {
                throw new RuntimeException('Failed to allocate captcha font color.');
            }

            // Holds the absolute path to the selected TrueType/OpenType font file
            $font_path = $this->resolveFontPath($options);

            // Applies enabled interference painters before drawing text
            $this->applyInterference($image, $color, $options);

            // Holds individual characters (or math symbols) to render (reuse precomputed glyphs)
            $text = $preview_glyphs;

            // Tracks how many glyphs were drawn successfully
            $drawn_glyphs = 0;
            // Holds the maximum drawable X coordinate inside the canvas
            $max_x = max(0, (int) $options['image_w'] - 1);
            // Holds the maximum drawable Y baseline inside the canvas
            $max_y = max(0, (int) $options['image_h'] - 1);
            // Holds glyph count used for spacing decisions
            $glyph_count = count($text);
            // Holds classic spacing factor (math stays tighter)
            $spacing_factor = ! empty($options['math']) ? 1.0 : 1.5;
            // Holds whether classic spacing would overflow the canvas (then distribute evenly)
            $classic_span = (int) ($options['font_size'] * $glyph_count * $spacing_factor);
            $use_even_spacing = $classic_span >= (int) $options['image_w'];
            // Holds horizontal slot width when evenly distributing glyphs
            $even_slot = $glyph_count > 0 ? ((int) $options['image_w'] / ($glyph_count + 1)) : 0.0;
            // Holds a safe Y jitter upper bound that keeps the baseline on-canvas
            $y_jitter_max = max(0, min(20, $max_y - (int) $options['font_size']));

            // Draws each captcha character onto the canvas
            foreach ($text as $index => $char) {
                // Stores horizontal position for the current glyph (clamped into the canvas)
                if ($use_even_spacing) {
                    $x = (int) round($even_slot * ($index + 1));
                } else {
                    $x = (int) ($options['font_size'] * ($index + 1) * $spacing_factor);
                }
                $x = min($max_x, max(0, $x));
                // Stores vertical position for the current glyph (clamped into the canvas)
                $y = (int) ($options['font_size'] + ($y_jitter_max > 0 ? mt_rand(0, $y_jitter_max) : 0));
                $y = min($max_y, max(1, $y));
                // Stores rotation angle; arithmetic captcha stays upright
                $angle = ! empty($options['math']) ? 0 : mt_rand(-40, 40);

                // Holds imagettftext result (array of bounds) or false on failure
                $draw_result = @imagettftext(
                    $image,
                    (int) $options['font_size'],
                    $angle,
                    $x,
                    $y,
                    $color,
                    $font_path,
                    $char
                );
                if ($draw_result !== false) {
                    ++$drawn_glyphs;
                }
            }

            // Requires every glyph to render; partial draws would mismatch the hashed answer
            if ($drawn_glyphs !== $glyph_count) {
                throw new RuntimeException('Failed to draw all captcha glyphs with the selected font.');
            }

            // Holds the output-buffer nesting level before we start our own buffer
            $buffer_level = ob_get_level();
            if (@ob_start() === false) {
                throw new RuntimeException('Failed to open output buffer for captcha PNG encoding.');
            }
            try {
                // Holds whether PNG encoding succeeded (false still may leave empty buffer data)
                $png_ok = @imagepng($image);
                $content = ob_get_clean();
                if ($png_ok === false) {
                    throw new RuntimeException('Failed to encode captcha PNG.');
                }
            } catch (Throwable $throwable) {
                // Discards only buffers opened by this method
                while (ob_get_level() > $buffer_level) {
                    ob_end_clean();
                }
                throw $throwable;
            }
        } finally {
            imagedestroy($image);
        }

        if ($content === false || $content === null || $content === '') {
            throw new RuntimeException('Failed to render captcha PNG content.');
        }

        // Holds the public cache key clients must submit for verification
        $cache_key = (string) $generator['cache_key'];
        // Persists the hash only after a successful image render to avoid orphan cache entries
        $this->persistCaptchaHash($cache_key, (string) $generator['hash'], $options);

        try {
            // Returns base64 API payload when api mode is enabled
            if (! empty($options['api'])) {
                return [
                    'key' => $cache_key,
                    'img' => 'data:image/png;base64,' . base64_encode($content),
                ];
            }

            // Holds cookie name used to deliver the cache key to browsers
            $cookie_name = (string) $options['cookie_name'];
            // Holds cookie Max-Age aligned with captcha expire
            $cookie_max_age = (int) $options['expire'];
            // Holds whether the current request is HTTPS (enables Secure cookie flag)
            $is_https = $this->isHttpsRequest();
            // Holds the Set-Cookie header value for the captcha cache key
            $set_cookie = sprintf(
                '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
                $cookie_name,
                rawurlencode($cache_key),
                $cookie_max_age,
                $is_https ? '; Secure' : ''
            );

            // Holds the PSR-7 response carrying the PNG body and captcha key
            $png_response = $this->response
                ->withStatus(200)
                ->withHeader('Content-Type', 'image/png')
                ->withHeader('Content-Length', (string) strlen($content))
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0')
                ->withHeader('X-Captcha-Key', $cache_key)
                ->withAddedHeader('Set-Cookie', $set_cookie)
                ->withBody(new SwooleStream($content));

            return $png_response;
        } catch (Throwable $throwable) {
            // Rolls back the cache entry when the response cannot be assembled
            $this->forgetCacheKey($this->buildCacheItemKey($cache_key, $options));
            throw $throwable;
        }
    }

    /*
     * Verifies a user-submitted captcha code against the cache hash.
     * @param string $code User input to validate
     * @param null|string $cache_key Public captcha key; null resolves from request input/header/cookie
     * @return bool True when the code matches and the cache entry still exists
     */
    public function check(string $code, ?string $cache_key = null): bool
    {
        // Fail closed when mbstring is missing (normalize / compare depend on it)
        if (! function_exists('mb_strtolower')
            || ! function_exists('mb_strlen')
            || ! function_exists('mb_check_encoding')) {
            return false;
        }

        // Holds resolved and sanitized root options for cache prefix and cookie name
        $options = $this->normalizeOptions($this->resolveOptions(null));
        // Holds the public captcha key used to locate the cache entry
        $resolved_key = $cache_key;
        if ($resolved_key === null || $resolved_key === '') {
            $resolved_key = $this->resolveCacheKeyFromRequest($options);
        }

        if ($resolved_key === null || $resolved_key === '') {
            return false;
        }

        // Normalizes key casing / encoding so cookie/header variants still match generated hex keys
        $resolved_key = strtolower(trim(rawurldecode($resolved_key)));

        // Rejects malformed keys early to avoid cache driver errors / probing
        if (! $this->isValidPublicCacheKey($resolved_key)) {
            return false;
        }

        // Holds the full cache item key including configured prefix
        $cache_item_key = $this->buildCacheItemKey($resolved_key, $options);

        try {
            // Holds the bcrypt hash previously stored for this captcha
            $hash = $this->cache->get($cache_item_key);
        } catch (Throwable $throwable) {
            // Cache backend failures must not break the request; treat as invalid captcha
            unset($throwable);

            return false;
        }

        // Consumes immediately after read so parallel requests cannot reuse the same challenge
        $this->forgetCacheKey($cache_item_key);

        if (! is_string($hash) || $hash === '' || ! $this->isBcryptHash($hash)) {
            return false;
        }

        // Rejects invalid UTF-8 before mb_* / password_verify to avoid warnings and false matches
        if (! mb_check_encoding($code, 'UTF-8')) {
            return false;
        }

        // Holds the normalized lowercase user input for comparison
        $normalized_code = mb_strtolower(trim($code), 'UTF-8');
        // Rejects empty or absurdly long submissions before password_verify
        if ($normalized_code === '' || mb_strlen($normalized_code, 'UTF-8') > 64) {
            return false;
        }

        // Holds whether password_verify accepts the submitted code
        return (bool) @password_verify($normalized_code, $hash);
    }

    /*
     * Detects HTTPS including common reverse-proxy forwarded proto headers.
     * @return bool True when the client-facing request should be treated as HTTPS
     */
    protected function isHttpsRequest(): bool
    {
        try {
            if (strtolower($this->request->getUri()->getScheme()) === 'https') {
                return true;
            }

            // Holds X-Forwarded-Proto from TLS-terminating proxies (first value only)
            $forwarded_proto = $this->normalizeRequestValue($this->request->header('X-Forwarded-Proto'));
            if ($forwarded_proto !== null) {
                // Holds the first hop when the header is a comma-separated list
                $forwarded_proto = strtolower(trim(explode(',', $forwarded_proto, 2)[0]));
                if ($forwarded_proto === 'https') {
                    return true;
                }
            }

            // Holds legacy Front-End-Https header used by some proxies
            $front_end_https = $this->normalizeRequestValue($this->request->header('Front-End-Https'));
            if ($front_end_https !== null && strtolower($front_end_https) === 'on') {
                return true;
            }
        } catch (Throwable $throwable) {
            unset($throwable);
        }

        return false;
    }

    /*
     * Ensures GD/FreeType/mbstring are available before any image allocation.
     * @throws RuntimeException When required runtime capabilities are missing
     */
    protected function assertGdAvailable(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required to generate captchas.');
        }

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext') || ! function_exists('imagepng')) {
            throw new RuntimeException('PHP GD must be compiled with FreeType and PNG support.');
        }

        if (! function_exists('mb_strtolower') || ! function_exists('mb_str_split') || ! function_exists('mb_check_encoding')) {
            throw new RuntimeException('PHP mbstring extension is required to generate captchas.');
        }
    }

    /*
     * Validates that a client-supplied public cache key matches the generated format.
     * @param string $cache_key Public captcha key
     * @return bool True when the key is a 32-char lowercase hex string
     */
    protected function isValidPublicCacheKey(string $cache_key): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $cache_key);
    }

    /*
     * Checks whether a cache payload looks like a bcrypt hash before password_verify.
     * @param string $hash Candidate hash from cache
     * @return bool True when the value appears to be bcrypt
     */
    protected function isBcryptHash(string $hash): bool
    {
        return (bool) preg_match('/^\$2[ayb]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash);
    }

    /*
     * Deletes a cache item while swallowing backend exceptions.
     * @param string $cache_item_key Full cache item key
     */
    protected function forgetCacheKey(string $cache_item_key): void
    {
        try {
            $this->cache->delete($cache_item_key);
        } catch (Throwable $throwable) {
            // Deletion failure must not flip a successful verification into an exception
            unset($throwable);
        }
    }

    /*
     * Resolves the public captcha key from request input, header, or cookie.
     * @param array<string, mixed> $options Resolved captcha options
     * @return null|string Public captcha key or null when missing
     */
    protected function resolveCacheKeyFromRequest(array $options): ?string
    {
        try {
            // Holds captcha key submitted as form/query field captcha_key
            $from_input = $this->normalizeRequestValue($this->request->input('captcha_key'));
            if ($from_input !== null) {
                return $from_input;
            }

            // Holds captcha key from the X-Captcha-Key request header
            $from_header = $this->normalizeRequestValue($this->request->header('X-Captcha-Key'));
            if ($from_header !== null) {
                return $from_header;
            }

            // Holds cookie name configured for browser-based flows
            $cookie_name = (string) ($options['cookie_name'] ?? self::DEFAULT_COOKIE_NAME);
            // Holds captcha key previously set on the captcha image response
            $from_cookie = $this->normalizeRequestValue($this->request->cookie($cookie_name));
            if ($from_cookie !== null) {
                return $from_cookie;
            }
        } catch (Throwable $throwable) {
            // Missing request context (CLI / broken coroutine) must not crash verification
            unset($throwable);
        }

        return null;
    }

    /*
     * Normalizes request header/input/cookie values into a non-empty string.
     * @param mixed $value Raw request value which may be string, array, or null
     * @return null|string Normalized string or null when missing
     */
    protected function normalizeRequestValue(mixed $value): ?string
    {
        if (is_array($value)) {
            // Holds the first element when frameworks return multi-value headers
            $value = $value[0] ?? null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        // Holds the trimmed string form of the scalar value
        $string_value = trim((string) $value);

        return $string_value !== '' ? $string_value : null;
    }

    /*
     * Splits captcha plaintext into drawable characters (Unicode-aware for Chinese).
     * @param string $value Captcha plaintext drawn on the image
     * @param bool $use_zh Whether the value is Chinese text
     * @return array<int, string> List of characters
     */
    protected function splitCaptchaText(string $value, bool $use_zh): array
    {
        if ($value === '') {
            return [];
        }

        if ($use_zh) {
            // Holds Unicode code-point characters for Chinese captcha text
            $characters = mb_str_split($value, 1, 'UTF-8');

            return is_array($characters) ? $characters : [];
        }

        return str_split($value);
    }

    /*
     * Builds the full cache item key from the public captcha key and prefix.
     * @param string $cache_key Public captcha key returned to the client
     * @param array<string, mixed> $options Resolved captcha options
     * @return string Full cache item key
     */
    protected function buildCacheItemKey(string $cache_key, array $options): string
    {
        // Holds the configured cache key prefix
        $prefix = (string) ($options['cache_prefix'] ?? self::DEFAULT_CACHE_PREFIX);

        return $prefix . $cache_key;
    }

    /*
     * Resolves captcha options from defaults, root config, then optional named group.
     * Named groups inherit root settings (e.g. cache_prefix) and only override declared keys.
     * @param null|string $config_name Named group under captcha.* or null for root config
     * @return array<string, mixed> Normalized snake_case options
     */
    protected function resolveOptions(?string $config_name = null): array
    {
        // Holds built-in defaults before file/config overrides
        $options = $this->defaultOptions();
        // Holds raw captcha config; non-array / config failures are treated as missing config
        try {
            $raw_captcha_config = $this->config->get('captcha', []);
        } catch (Throwable $throwable) {
            unset($throwable);
            $raw_captcha_config = [];
        }
        // Holds raw config array loaded from the captcha config file
        $file_config = is_array($raw_captcha_config) ? $raw_captcha_config : [];
        // Always applies root-level options first so named groups inherit cache_prefix etc.
        $options = $this->applyOverrides($options, $this->extractRootOverrides($file_config));
        // Holds root storage keys that named profiles must never override (check() always uses root)
        $root_cache_prefix = $options['cache_prefix'];
        $root_cookie_name = $options['cookie_name'];

        if ($config_name !== null && $config_name !== '') {
            // Holds a named captcha profile such as captcha.verify (must be an assoc array)
            $named_overrides = null;
            try {
                $named_overrides = $this->config->get('captcha.' . $config_name);
            } catch (Throwable $throwable) {
                unset($throwable);
            }
            if (! is_array($named_overrides) && isset($file_config[$config_name])) {
                $named_overrides = $file_config[$config_name];
            }
            // Ignores scalar collisions like root 'api' => false when calling create('api')
            if (is_array($named_overrides) && $this->isAssocConfigArray($named_overrides)) {
                $options = $this->applyOverrides($options, $named_overrides);
            }
        }

        // Forces storage identity to root values so create/check never disagree on prefix/cookie
        $options['cache_prefix'] = $root_cache_prefix;
        $options['cookie_name'] = $root_cookie_name;

        return $options;
    }

    /*
     * Applies a config override map onto the current options using key aliases.
     * @param array<string, mixed> $options Current options
     * @param array $overrides Raw override map
     * @return array<string, mixed>
     */
    protected function applyOverrides(array $options, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            // Holds the normalized snake_case option name
            $normalized_key = self::KEY_ALIASES[$key] ?? (string) $key;

            // Legacy use_curve / use_noise: false forces strength to 0 unless strength is also set
            if ($normalized_key === 'use_curve' || $normalized_key === 'useCurve') {
                if (! $this->toBool($value)
                    && ! array_key_exists('curve_strength', $overrides)
                    && ! array_key_exists('curveStrength', $overrides)) {
                    $options['curve_strength'] = 0;
                }
                continue;
            }
            if ($normalized_key === 'use_noise' || $normalized_key === 'useNoise') {
                if (! $this->toBool($value)
                    && ! array_key_exists('noise_strength', $overrides)
                    && ! array_key_exists('noiseStrength', $overrides)) {
                    $options['noise_strength'] = 0;
                }
                continue;
            }

            if (array_key_exists($normalized_key, $options) || $normalized_key === 'interfere') {
                $options[$normalized_key] = $value;
            }
        }

        return $options;
    }

    /*
     * Clamps and sanitizes option values so GD / Cache / Cookie never receive unsafe input.
     * @param array<string, mixed> $options Raw resolved options
     * @return array<string, mixed> Sanitized options
     */
    protected function normalizeOptions(array $options): array
    {
        // Holds built-in defaults used when user overrides are empty/invalid
        $defaults = $this->defaultOptions();

        $options['length'] = $this->toBoundedInt($options['length'] ?? null, 1, 20, (int) $defaults['length']);
        $options['font_size'] = $this->toBoundedInt($options['font_size'] ?? null, 10, 72, (int) $defaults['font_size']);
        $options['expire'] = $this->toBoundedInt($options['expire'] ?? null, 1, 86400, (int) $defaults['expire']);
        $options['alpha'] = $this->toBoundedInt($options['alpha'] ?? null, 0, 127, (int) $defaults['alpha']);
        $options['image_w'] = $this->toBoundedInt($options['image_w'] ?? null, 0, 800, (int) $defaults['image_w']);
        $options['image_h'] = $this->toBoundedInt($options['image_h'] ?? null, 0, 300, (int) $defaults['image_h']);
        $options['use_zh'] = $this->toBool($options['use_zh'] ?? false);
        $options['use_img_bg'] = $this->toBool($options['use_img_bg'] ?? false);
        // Clamps interference strength to 0–10 (0 = off; 5 ≈ historical default look)
        $options['curve_strength'] = $this->toBoundedInt(
            $options['curve_strength'] ?? null,
            0,
            10,
            (int) $defaults['curve_strength']
        );
        $options['noise_strength'] = $this->toBoundedInt(
            $options['noise_strength'] ?? null,
            0,
            10,
            (int) $defaults['noise_strength']
        );
        $options['math'] = $this->toBool($options['math'] ?? false);
        $options['api'] = $this->toBool($options['api'] ?? false);
        $options['code_set'] = is_scalar($options['code_set'] ?? null)
            ? (string) $options['code_set']
            : (string) $defaults['code_set'];
        $options['zh_set'] = is_scalar($options['zh_set'] ?? null)
            ? (string) $options['zh_set']
            : (string) $defaults['zh_set'];
        // Holds basename-only font name with control characters stripped
        $fontttf = basename(str_replace('\\', '/', (string) ($options['fontttf'] ?? '')));
        $fontttf = preg_replace('/[\x00-\x1F\x7F]/', '', $fontttf) ?? '';
        $options['fontttf'] = ($fontttf === '.' || $fontttf === '..') ? '' : $fontttf;
        $options['route_prefix'] = RoutePrefix::normalize($options['route_prefix'] ?? RoutePrefix::DEFAULT);

        // Restores defaults when character pools were cleared or are not valid UTF-8
        if (trim($options['code_set']) === '' || ! mb_check_encoding($options['code_set'], 'UTF-8')) {
            $options['code_set'] = (string) $defaults['code_set'];
        }
        if (trim($options['zh_set']) === '' || ! mb_check_encoding($options['zh_set'], 'UTF-8')) {
            $options['zh_set'] = (string) $defaults['zh_set'];
        }

        // Holds a sanitized cookie name safe for Set-Cookie headers (must start with a letter)
        $cookie_name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($options['cookie_name'] ?? ''));
        $options['cookie_name'] = ($cookie_name !== null
            && $cookie_name !== ''
            && (bool) preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $cookie_name))
            ? $cookie_name
            : self::DEFAULT_COOKIE_NAME;

        // Holds a sanitized cache prefix without control characters (capped length)
        $cache_prefix = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($options['cache_prefix'] ?? ''));
        if ($cache_prefix === null || $cache_prefix === '' || strlen($cache_prefix) > 128) {
            $cache_prefix = self::DEFAULT_CACHE_PREFIX;
        }
        $options['cache_prefix'] = $cache_prefix;

        if (! is_array($options['bg'] ?? null) || count($options['bg']) < 3) {
            $options['bg'] = [243, 251, 254];
        }
        $options['bg'] = [
            $this->toBoundedInt($options['bg'][0] ?? null, 0, 255, 243),
            $this->toBoundedInt($options['bg'][1] ?? null, 0, 255, 251),
            $this->toBoundedInt($options['bg'][2] ?? null, 0, 255, 254),
        ];

        $options['interfere'] = $this->normalizeInterfereMap($options['interfere'] ?? null);

        return $options;
    }

    /*
     * Safely casts mixed config values to an int clamped within [min, max].
     * Invalid types / non-numeric strings fall back to $default instead of TypeError.
     * @param mixed $value Raw config value
     * @param int $min Inclusive lower bound
     * @param int $max Inclusive upper bound
     * @param int $default Fallback when the value cannot be interpreted
     * @return int Bounded integer
     */
    protected function toBoundedInt(mixed $value, int $min, int $max, int $default): int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || (is_resource($value)) || $value === null) {
            return max($min, min($max, $default));
        }

        if (is_float($value) && ! is_finite($value)) {
            return max($min, min($max, $default));
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || ! is_numeric($value)) {
                return max($min, min($max, $default));
            }
        }

        return max($min, min($max, (int) $value));
    }

    /*
     * Normalizes the interfere map: remaps legacy keys and drops unknown painters.
     * @param mixed $interfere Raw interfere config
     * @return array<string, Closure|string>
     */
    protected function normalizeInterfereMap(mixed $interfere): array
    {
        // Holds the built-in default interference map
        $defaults = [
            'use_img_bg' => 'background',
            'curve_strength' => 'writeCurve',
            'noise_strength' => 'writeNoise',
        ];

        if (! is_array($interfere) || $interfere === []) {
            return $defaults;
        }

        // Holds legacy / camelCase key remaps onto current option names
        $key_aliases = [
            'useImgBg' => 'use_img_bg',
            'useCurve' => 'curve_strength',
            'use_curve' => 'curve_strength',
            'useNoise' => 'noise_strength',
            'use_noise' => 'noise_strength',
            'curveStrength' => 'curve_strength',
            'noiseStrength' => 'noise_strength',
        ];
        // Holds method names allowed for built-in painters
        $allowed_methods = ['background', 'writeCurve', 'writeNoise'];
        // Holds the sanitized interfere map
        $normalized = [];

        foreach ($interfere as $key => $method) {
            // Holds the normalized option key for this painter entry
            $flag = $key_aliases[(string) $key] ?? (string) $key;
            if ($flag === '') {
                continue;
            }

            if ($method instanceof Closure) {
                $normalized[$flag] = $method;
                continue;
            }

            if (! is_string($method) || ! in_array($method, $allowed_methods, true)) {
                continue;
            }

            $normalized[$flag] = $method;
        }

        return $normalized === [] ? $defaults : $normalized;
    }

    /*
     * Converts mixed config values to bool without the PHP "(bool)'false' === true" pitfall.
     * @param mixed $value Raw config value
     * @return bool Normalized boolean
     */
    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            // Holds filter_var result which correctly maps "false"/"off"/"0" to false
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $filtered ?? false;
        }

        // Arrays/objects/resources are never valid captcha flag values
        return false;
    }

    /*
     * Returns the built-in default option set for captcha generation.
     * @return array<string, mixed>
     */
    protected function defaultOptions(): array
    {
        return [
            'code_set' => '2345678abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY',
            'expire' => 1800,
            'use_zh' => false,
            'zh_set' => '们以我到他会作时要动国产的一是工就年阶义发成部民可出能方进在了不和有大这主中人上为来分生对于学下级地个用同行面说种过命度革而多子后自社加小机也经力线本电高量长党得实家定深法表着水理化争现所二起政三好十战无农使性前等反体合斗路图把结第里正新开论之物从当两些还天资事队批点育重其思与间内去因件日利相由压员气业代全组数果期导平各基或月毛然如应形想制心样干都向变关问比展那它最及外没看治提五解系林者米群头意只明四道马认次文通但条较克又公孔领军流入接席位情运器并飞原油放立题质指建区验活众很教决特此常石强极土少已根共直团统式转别造切九你取西持总料连任志观调七么山程百报更见必真保热委手改管处己将修支识病象几先老光专什六型具示复安带每东增则完风回南广劳轮科北打积车计给节做务被整联步类集号列温装即毫知轴研单色坚据速防史拉世设达尔场织历花受求传口断况采精金界品判参层止边清至万确究书术状厂须离再目海交权且儿青才证低越际八试规斯近注办布门铁需走议县兵固除般引齿千胜细影济白格效置推空配刀叶率述今选养德话查差半敌始片施响收华觉备名红续均药标记难存测士身紧液派准斤角降维板许破述技消底床田势端感往神便贺村构照容非搞亚磨族火段算适讲按值美态黄易彪服早班麦削信排台声该击素张密害侯草何树肥继右属市严径螺检左页抗苏显苦英快称坏移约巴材省黑武培著河帝仅针怎植京助升王眼她抓含苗副杂普谈围食射源例致酸旧却充足短划剂宣环落首尺波承粉践府鱼随考刻靠够满夫失包住促枝局菌杆周护岩师举曲春元超负砂封换太模贫减阳扬江析亩木言球朝医校古呢稻宋听唯输滑站另卫字鼓刚写刘微略范供阿块某功套友限项余倒卷创律雨让骨远帮初皮播优占死毒圈伟季训控激找叫云互跟裂粮粒母练塞钢顶策双留误础吸阻故寸盾晚丝女散焊功株亲院冷彻弹错散商视艺灭版烈零室轻血倍缺厘泵察绝富城冲喷壤简否柱李望盘磁雄似困巩益洲脱投送奴侧润盖挥距触星松送获兴独官混纪依未突架宽冬章湿偏纹吃执阀矿寨责熟稳夺硬价努翻奇甲预职评读背协损棉侵灰虽矛厚罗泥辟告卵箱掌氧恩爱停曾溶营终纲孟钱待尽俄缩沙退陈讨奋械载胞幼哪剥迫旋征槽倒握担仍呀鲜吧卡粗介钻逐弱脚怕盐末阴丰雾冠丙街莱贝辐肠付吉渗瑞惊顿挤秒悬姆烂森糖圣凹陶词迟蚕亿矩康遵牧遭幅园腔订香肉弟屋敏恢忘编印蜂急拿扩伤飞露核缘游振操央伍域甚迅辉异序免纸夜乡久隶缸夹念兰映沟乙吗儒杀汽磷艰晶插埃燃欢铁补咱芽永瓦倾阵碳演威附牙芽永瓦斜灌欧献顺猪洋腐请透司危括脉宜笑若尾束壮暴企菜穗楚汉愈绿拖牛份染既秋遍锻玉夏疗尖殖井费州访吹荣铜沿替滚客召旱悟刺脑措贯藏敢令隙炉壳硫煤迎铸粘探临薄旬善福纵择礼愿伏残雷延烟句纯渐耕跑泽慢栽鲁赤繁境潮横掉锥希池败船假亮谓托伙哲怀割摆贡呈劲财仪沉炼麻罪祖息车穿货销齐鼠抽画饲龙库守筑房歌寒喜哥洗蚀废纳腹乎录镜妇恶脂庄擦险赞钟摇典柄辩竹谷卖乱虚桥奥伯赶垂途额壁网截野遗静谋弄挂课镇妄盛耐援扎虑键归符庆聚绕摩忙舞遇索顾胶羊湖钉仁音迹碎伸灯避泛亡答勇频皇柳哈揭甘诺概宪浓岛袭谁洪谢炮浇斑讯懂灵蛋闭孩释乳巨徒私银伊景坦累匀霉杜乐勒隔弯绩招绍胡呼痛峰零柴簧午跳居尚丁秦稍追梁折耗碱殊岗挖氏刃剧堆赫荷胸衡勤膜篇登驻案刊秧缓凸役剪川雪链渔啦脸户洛孢勃盟买杨宗焦赛旗滤硅炭股坐蒸凝竟陷枪黎救冒暗洞犯筒您宋弧爆谬涂味津臂障褐陆啊健尊豆拔莫抵桑坡缝警挑污冰柬嘴啥饭塑寄赵喊垫丹渡耳刨虎笔稀昆浪萨茶滴浅拥穴覆伦娘吨浸袖珠雌妈紫戏塔锤震岁貌洁剖牢锋疑霸闪埔猛诉刷狠忽灾闹乔唐漏闻沈熔氯荒茎男凡抢像浆旁玻亦忠唱蒙予纷捕锁尤乘乌智淡允叛畜俘摸锈扫毕璃宝芯爷鉴秘净蒋钙肩腾枯抛轨堂拌爸循诱祝励肯酒绳穷塘燥泡袋朗喂铝软渠颗惯贸粪综墙趋彼届墨碍启逆卸航衣孙龄岭骗休借',
            'use_img_bg' => false,
            // Curve interference intensity (0–10); 0 = off; higher is harder to read
            'curve_strength' => 5,
            // Noise interference intensity (0–10); 0 = off; higher is harder to read
            'noise_strength' => 5,
            'image_h' => 0,
            'image_w' => 0,
            'length' => 5,
            'font_size' => 25,
            'fontttf' => '',
            'bg' => [243, 251, 254],
            'math' => false,
            'alpha' => 0,
            'api' => false,
            'route_prefix' => '/captcha',
            'cache_prefix' => self::DEFAULT_CACHE_PREFIX,
            'cookie_name' => self::DEFAULT_COOKIE_NAME,
            'interfere' => [
                'use_img_bg' => 'background',
                'curve_strength' => 'writeCurve',
                'noise_strength' => 'writeNoise',
            ],
        ];
    }

    /*
     * Filters nested named profiles out of the root captcha config array.
     * Associative arrays (except bg/interfere) are treated as named profiles, not root options.
     * @param array $file_config Full captcha.php contents
     * @return array Root-level option overrides only
     */
    protected function extractRootOverrides(array $file_config): array
    {
        // Holds option keys that belong to the root captcha profile
        $known_keys = array_merge(array_keys($this->defaultOptions()), array_keys(self::KEY_ALIASES));
        // Holds the filtered root overrides
        $overrides = [];

        foreach ($file_config as $key => $value) {
            // Holds the string form of the current config key
            $string_key = (string) $key;
            // Holds the snake_case equivalent when an alias exists
            $normalized_key = self::KEY_ALIASES[$string_key] ?? $string_key;

            // Skips named profile blocks such as 'verify' => [...] or 'api' => ['api' => true]
            if ($this->isNamedProfileValue($normalized_key, $value)) {
                continue;
            }

            // Keeps legacy use_curve / use_noise so applyOverrides can map false → strength 0
            if (in_array($normalized_key, ['use_curve', 'use_noise'], true)
                || in_array($string_key, ['use_curve', 'use_noise', 'useCurve', 'useNoise'], true)) {
                $overrides[$string_key] = $value;
                continue;
            }

            if (in_array($normalized_key, $known_keys, true) || in_array($string_key, $known_keys, true)) {
                $overrides[$string_key] = $value;
            }
        }

        return $overrides;
    }

    /*
     * Detects whether a config value is a named captcha profile rather than a root option.
     * @param string $normalized_key Snake_case option name
     * @param mixed $value Config value
     * @return bool True when the value should be treated as a named profile
     */
    protected function isNamedProfileValue(string $normalized_key, mixed $value): bool
    {
        // bg and interfere are legitimate associative/list option arrays at root level
        if (in_array($normalized_key, ['bg', 'interfere'], true)) {
            return false;
        }

        return $this->isAssocConfigArray($value);
    }

    /*
     * Returns true when the value is a non-list associative array (named profile shape).
     * @param mixed $value Candidate config value
     * @return bool
     */
    protected function isAssocConfigArray(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        return ! array_is_list($value);
    }

    /*
     * Generates captcha plaintext and bcrypt hash without writing to cache yet.
     * Persistence happens only after the image is rendered successfully.
     * @param array<string, mixed> $options Resolved captcha options
     * @return array{value: string, hash: string, cache_key: string}
     * @throws Exception
     */
    protected function generate(array $options): array
    {
        // Holds the human-visible captcha string drawn on the image
        $bag = '';
        // Holds the value that will be hashed and verified later
        $answer = '';

        if (! empty($options['math'])) {
            $options['use_zh'] = false;
            $options['length'] = 5;
            // Holds the left operand of the arithmetic captcha
            $x = random_int(10, 30);
            // Holds the right operand of the arithmetic captcha
            $y = random_int(1, 9);
            $bag = "{$x} + {$y} = ";
            $answer = (string) ($x + $y);
        } else {
            if (! empty($options['use_zh'])) {
                // Holds Chinese characters split into a list (drops whitespace-only glyphs)
                $characters = array_values(array_filter(
                    $this->splitCaptchaText((string) $options['zh_set'], true),
                    static fn (string $glyph): bool => $glyph !== '' && trim($glyph) !== ''
                ));
            } else {
                // Holds ASCII captcha alphabet as a list of single-byte characters
                $characters = preg_split('//u', (string) $options['code_set'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                // Keeps only printable single-byte glyphs for non-Chinese mode
                $characters = array_values(array_filter(
                    $characters,
                    static fn (string $glyph): bool => strlen($glyph) === 1
                        && ctype_graph($glyph)
                ));
            }

            if ($characters === []) {
                throw new RuntimeException('Captcha character set is empty.');
            }

            // Holds the number of characters to generate (at least 1)
            $length = max(1, (int) $options['length']);
            for ($i = 0; $i < $length; ++$i) {
                $bag .= $characters[random_int(0, count($characters) - 1)];
            }

            $answer = mb_strtolower($bag, 'UTF-8');
        }

        try {
            // Holds the bcrypt hash that will be persisted after a successful render
            $hash = password_hash($answer, PASSWORD_BCRYPT, ['cost' => 10]);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Failed to hash captcha answer.', 0, $throwable);
        }
        if ($hash === false) {
            throw new RuntimeException('Failed to hash captcha answer.');
        }

        try {
            // Holds the public key returned to the client for later verification
            $cache_key = bin2hex(random_bytes(16));
        } catch (Throwable $throwable) {
            throw new RuntimeException('Failed to generate captcha cache key.', 0, $throwable);
        }

        return [
            'value' => $bag,
            'hash' => $hash,
            'cache_key' => $cache_key,
        ];
    }

    /*
     * Writes the captcha hash into cache after the image has been rendered successfully.
     * @param string $cache_key Public captcha key
     * @param string $hash Bcrypt hash of the answer
     * @param array<string, mixed> $options Resolved captcha options
     */
    protected function persistCaptchaHash(string $cache_key, string $hash, array $options): void
    {
        // Holds the full cache item key including prefix
        $cache_item_key = $this->buildCacheItemKey($cache_key, $options);
        // Holds captcha lifetime in seconds used as cache TTL (minimum 1 second)
        $ttl = max(1, (int) $options['expire']);

        try {
            // Holds whether the cache driver accepted the write
            $stored = $this->cache->set($cache_item_key, $hash, $ttl);
        } catch (CacheInvalidArgumentException|Throwable $throwable) {
            throw new RuntimeException('Failed to store captcha hash in cache.', 0, $throwable);
        }

        if ($stored === false) {
            throw new RuntimeException('Failed to store captcha hash in cache.');
        }
    }

    /*
     * Picks a font file path based on use_zh and optional fontttf setting.
     * Restricts resolution to the package assets directory to prevent path traversal.
     * @param array<string, mixed> $options Resolved captcha options
     * @return string Absolute font file path
     */
    protected function resolveFontPath(array $options): string
    {
        // Holds the directory containing English or Chinese fonts
        $ttf_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
            . (! empty($options['use_zh']) ? 'zhttfs' : 'ttfs') . DIRECTORY_SEPARATOR;
        // Holds the real absolute assets directory used as a jail boundary
        $real_dir = realpath($ttf_path);
        if ($real_dir === false || ! is_dir($real_dir)) {
            throw new RuntimeException('Captcha font directory is not readable: ' . $ttf_path);
        }

        // Holds the configured font file name when provided (basename only)
        $fontttf = (string) ($options['fontttf'] ?? '');
        // Rejects empty / dot-segment basenames that cannot be real font files
        if ($fontttf === '' || $fontttf === '.' || $fontttf === '..') {
            $fontttf = '';
        }
        // Holds the lowercase extension of the configured font file
        $configured_extension = strtolower(substr($fontttf, -4));

        if ($fontttf === '' || ($configured_extension !== '.ttf' && $configured_extension !== '.otf')) {
            // Holds candidate font filenames discovered in the assets directory
            $ttfs = [];
            // Holds the directory handle while scanning font files
            $dir = dir($real_dir);
            if ($dir === false) {
                throw new RuntimeException('Captcha font directory is not readable: ' . $real_dir);
            }

            try {
                while (false !== ($file = $dir->read())) {
                    // Holds the lowercase extension for the current file
                    $extension = strtolower(substr($file, -4));
                    if ($extension === '.ttf' || $extension === '.otf') {
                        $ttfs[] = $file;
                    }
                }
            } finally {
                $dir->close();
            }

            if ($ttfs === []) {
                throw new RuntimeException('No captcha font files found in: ' . $real_dir);
            }

            $fontttf = $ttfs[array_rand($ttfs)];
        }

        // Holds the candidate absolute path before realpath jail check
        $font_path = $real_dir . DIRECTORY_SEPARATOR . $fontttf;
        // Holds the canonical absolute path of the selected font
        $real_font = realpath($font_path);
        // Holds normalized directory path with forward slashes for cross-platform compare
        $normalized_dir = rtrim(str_replace('\\', '/', $real_dir), '/') . '/';
        // Holds normalized font path with forward slashes for cross-platform compare
        $normalized_font = $real_font === false ? '' : str_replace('\\', '/', $real_font);
        if ($real_font === false
            || ! is_file($real_font)
            || ! is_readable($real_font)
            || ! str_starts_with($normalized_font, $normalized_dir)) {
            throw new RuntimeException('Captcha font file does not exist or is outside assets: ' . $fontttf);
        }

        return $real_font;
    }

    /*
     * Runs configured interference painters (background, curve, noise, or closures).
     * @param \GdImage|resource $image GD image resource
     * @param int $color Allocated drawing color
     * @param array<string, mixed> $options Resolved captcha options
     */
    protected function applyInterference(mixed $image, int $color, array $options): void
    {
        // Holds the already-sanitized interference map of option key => painter
        $interfere = is_array($options['interfere'] ?? null)
            ? $options['interfere']
            : [
                'use_img_bg' => 'background',
                'curve_strength' => 'writeCurve',
                'noise_strength' => 'writeNoise',
            ];

        foreach ($interfere as $flag => $method) {
            // Holds normalized option flag name
            $flag = (string) $flag;

            if ($method instanceof Closure) {
                try {
                    $method($image, $options['image_w'], $options['image_h'], $options['font_size'], $color);
                } catch (Throwable $throwable) {
                    // Custom interference closures must not abort captcha generation
                    unset($throwable);
                }
                continue;
            }

            // Strength-based painters run when strength > 0; bool flags use empty() check
            if ($flag === 'curve_strength' || $flag === 'noise_strength') {
                if ((int) ($options[$flag] ?? 0) <= 0) {
                    continue;
                }
            } elseif (empty($options[$flag])) {
                continue;
            }

            if (! is_string($method)) {
                continue;
            }

            if ($method === 'background' || $method === 'writeCurve' || $method === 'writeNoise') {
                try {
                    $this->{$method}($image, $color, $options);
                } catch (Throwable $throwable) {
                    // Built-in interference failures must not abort captcha generation
                    unset($throwable);
                }
            }
        }
    }

    /*
     * Draws connected sine curves as interference lines; density follows curve_strength.
     * @param \GdImage|resource $image GD image resource
     * @param int $color Pixel color for the curve
     * @param array<string, mixed> $options Resolved captcha options
     */
    protected function writeCurve(mixed $image, int $color, array $options): void
    {
        // Holds curve intensity 0–10 (0 skips drawing entirely)
        $strength = max(0, min(10, (int) ($options['curve_strength'] ?? 5)));
        if ($strength === 0) {
            return;
        }

        // Holds image width used by the curve formulas
        $image_w = (int) $options['image_w'];
        // Holds image height used by the curve formulas
        $image_h = (int) $options['image_h'];
        // Holds font size used to thicken the curve stroke
        $font_size = (int) $options['font_size'];
        // Holds how many full two-segment curves to paint (1 at low, up to 3 at max)
        $curve_count = max(1, min(3, (int) ceil($strength / 4)));
        // Holds stroke thickness scaled so level 5 matches the historical font_size/5 look
        $stroke = max(1, min(40, (int) round($font_size / 5 * $strength / 5)));
        // Holds amplitude scale relative to level 5 (capped so curves stay on-canvas)
        $amp_ratio = min(1.0, $strength / 5);

        for ($curve_index = 0; $curve_index < $curve_count; ++$curve_index) {
            $this->paintSineCurvePair($image, $color, $image_w, $image_h, $stroke, $amp_ratio);
        }
    }

    /*
     * Paints one pair of continuous sine segments across the captcha canvas.
     * @param \GdImage|resource $image GD image resource
     * @param int $color Pixel color for the curve
     * @param int $image_w Canvas width
     * @param int $image_h Canvas height
     * @param int $stroke Stroke thickness in pixels
     * @param float $amp_ratio Amplitude ratio relative to half image height (0–1)
     */
    protected function paintSineCurvePair(
        mixed $image,
        int $color,
        int $image_w,
        int $image_h,
        int $stroke,
        float $amp_ratio
    ): void {
        if ($image_w < 1 || $image_h < 1 || $stroke < 1) {
            return;
        }

        // Holds safe amplitude upper bound (mt_rand requires min <= max)
        $max_amplitude = max(1, (int) round(($image_h / 2) * max(0.15, $amp_ratio)));
        // Holds safe vertical/phase offset bound
        $offset_bound = max(0, (int) ($image_h / 4));
        // Holds safe period upper bound
        $period_max = max($image_h, $image_w * 2, 1);

        // Amplitude of the first sine segment
        $A = mt_rand(1, $max_amplitude);
        // Vertical offset of the first sine segment
        $b = mt_rand(-$offset_bound, $offset_bound);
        // Phase offset of the first sine segment
        $f = mt_rand(-$offset_bound, $offset_bound);
        // Period of the first sine segment
        $T = mt_rand(max(1, $image_h), $period_max);
        // Angular frequency derived from the period
        $w = (2 * M_PI) / $T;
        // Start x of the first curve segment
        $px1 = 0;
        // Holds safe end-x range for the first curve segment
        $px2_min = max(0, (int) ($image_w / 2));
        $px2_max = max($px2_min, (int) ($image_w * 0.8));
        // End x of the first curve segment
        $px2 = mt_rand($px2_min, $px2_max);
        // Latest y used to stitch the second segment
        $py = 0.0;
        // Holds last x used after the first loop for continuity
        $px = $px1;

        for ($px = $px1; $px <= $px2; ++$px) {
            $py = $A * sin($w * $px + $f) + $b + $image_h / 2;
            // Holds remaining pixels to paint for stroke thickness
            $i = $stroke;
            while ($i > 0) {
                // Holds clamped pixel coordinates so GD never paints outside the canvas
                $draw_x = (int) ($px + $i);
                $draw_y = (int) ($py + $i);
                if ($draw_x >= 0 && $draw_x < $image_w && $draw_y >= 0 && $draw_y < $image_h) {
                    imagesetpixel($image, $draw_x, $draw_y, $color);
                }
                --$i;
            }
        }

        // Amplitude of the second sine segment
        $A = mt_rand(1, $max_amplitude);
        // Phase offset of the second sine segment
        $f = mt_rand(-$offset_bound, $offset_bound);
        // Period of the second sine segment
        $T = mt_rand(max(1, $image_h), $period_max);
        // Angular frequency of the second sine segment
        $w = (2 * M_PI) / $T;
        // Vertical offset that keeps the second segment continuous
        $b = $py - $A * sin($w * $px + $f) - $image_h / 2;
        $px1 = $px2;
        $px2 = $image_w;

        for ($px = $px1; $px <= $px2; ++$px) {
            $py = $A * sin($w * $px + $f) + $b + $image_h / 2;
            // Holds remaining pixels to paint for stroke thickness
            $i = $stroke;
            while ($i > 0) {
                // Holds clamped pixel coordinates so GD never paints outside the canvas
                $draw_x = (int) ($px + $i);
                $draw_y = (int) ($py + $i);
                if ($draw_x >= 0 && $draw_x < $image_w && $draw_y >= 0 && $draw_y < $image_h) {
                    imagesetpixel($image, $draw_x, $draw_y, $color);
                }
                --$i;
            }
        }
    }

    /*
     * Scatters light-colored random characters as noise; density follows noise_strength.
     * @param \GdImage|resource $image GD image resource
     * @param int $color Unused primary color (noise uses its own colors)
     * @param array<string, mixed> $options Resolved captcha options
     */
    protected function writeNoise(mixed $image, int $color, array $options): void
    {
        // Primary color is unused for noise; kept for a uniform painter signature
        unset($color);

        // Holds noise intensity 0–10 (0 skips drawing entirely)
        $strength = max(0, min(10, (int) ($options['noise_strength'] ?? 5)));
        if ($strength === 0) {
            return;
        }

        // Holds the character pool used for noise glyphs
        $code_set = '2345678abcdefhijkmnpqrstuvwxyz';
        // Holds image width for random noise placement
        $image_w = (int) $options['image_w'];
        // Holds image height for random noise placement
        $image_h = (int) $options['image_h'];
        // Holds batch count scaled so level 5 matches the historical 10 batches (hard-capped)
        $batch_count = max(1, min(40, (int) round(10 * $strength / 5)));
        // Holds glyphs per batch scaled so level 5 matches the historical 5 glyphs (hard-capped)
        $glyphs_per_batch = max(1, min(20, (int) round(5 * $strength / 5)));

        for ($i = 0; $i < $batch_count; ++$i) {
            // Holds a light random color for this noise batch
            $noise_color = imagecolorallocate($image, mt_rand(150, 225), mt_rand(150, 225), mt_rand(150, 225));
            if ($noise_color === false) {
                continue;
            }
            for ($j = 0; $j < $glyphs_per_batch; ++$j) {
                imagestring(
                    $image,
                    5,
                    mt_rand(-10, max(0, $image_w)),
                    mt_rand(-10, max(0, $image_h)),
                    $code_set[mt_rand(0, 29)],
                    $noise_color
                );
            }
        }
    }

    /*
     * Copies a random JPEG from assets/bgs onto the captcha canvas.
     * @param \GdImage|resource $image GD image resource
     * @param int $color Unused primary color
     * @param array<string, mixed> $options Resolved captcha options
     */
    protected function background(mixed $image, int $color, array $options): void
    {
        // Primary color is unused for backgrounds; kept for painter signature parity
        unset($color);

        // Holds the directory that stores JPEG background images
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'bgs' . DIRECTORY_SEPARATOR;
        // Holds the real absolute backgrounds directory used as a jail boundary
        $real_dir = realpath($path);
        if ($real_dir === false || ! is_dir($real_dir)) {
            return;
        }

        // Holds the directory handle while scanning backgrounds
        $dir = dir($real_dir);
        if ($dir === false) {
            return;
        }

        // Holds absolute paths of discovered JPEG backgrounds inside the jail
        $bgs = [];
        // Holds normalized directory prefix for path traversal checks
        $dir_prefix = rtrim(str_replace('\\', '/', $real_dir), '/') . '/';
        try {
            while (false !== ($file = $dir->read())) {
                if (! isset($file[0]) || $file[0] === '.') {
                    continue;
                }

                // Holds the lowercase filename for extension checks (.jpg / .jpeg)
                $lower_file = strtolower($file);
                if (! str_ends_with($lower_file, '.jpg') && ! str_ends_with($lower_file, '.jpeg')) {
                    continue;
                }

                // Holds the candidate background path before realpath
                $candidate = $real_dir . DIRECTORY_SEPARATOR . basename($file);
                // Holds the canonical path of the candidate JPEG
                $real_file = realpath($candidate);
                // Holds normalized file path for cross-platform prefix compare
                $normalized_file = $real_file === false ? '' : str_replace('\\', '/', $real_file);
                if ($real_file === false
                    || ! is_file($real_file)
                    || ! is_readable($real_file)
                    || ! str_starts_with($normalized_file, $dir_prefix)) {
                    continue;
                }

                $bgs[] = $real_file;
            }
        } finally {
            $dir->close();
        }

        if ($bgs === []) {
            return;
        }

        // Holds the randomly selected background file path
        $gb = $bgs[array_rand($bgs)];
        // Holds intrinsic width/height of the background JPEG
        $size = @getimagesize($gb);
        if ($size === false || empty($size[0]) || empty($size[1])) {
            return;
        }

        // Holds decoded JPEG width
        $width = (int) $size[0];
        // Holds decoded JPEG height
        $height = (int) $size[1];
        // Skips oversized backgrounds that could exhaust memory when decoded
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
            return;
        }

        // Holds the GD resource loaded from the JPEG background
        $bg_image = @imagecreatefromjpeg($gb);
        if ($bg_image === false) {
            return;
        }

        try {
            @imagecopyresampled(
                $image,
                $bg_image,
                0,
                0,
                0,
                0,
                (int) $options['image_w'],
                (int) $options['image_h'],
                $width,
                $height
            );
        } finally {
            @imagedestroy($bg_image);
        }
    }
}
