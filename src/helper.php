<?php

declare(strict_types=1);

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Lyyteam\HyperfGraphicCaptcha\Captcha;
use Lyyteam\HyperfGraphicCaptcha\Support\RoutePrefix;
use Psr\Http\Message\ResponseInterface;

if (! function_exists('captcha')) {
    /*
     * Creates a captcha image response (or API array) using the given config profile.
     * @param null|string $config Named captcha profile under captcha.*; null uses defaults
     * @return ResponseInterface|array
     */
    function captcha(?string $config = null): ResponseInterface|array
    {
        // Holds a sanitized named profile (invalid names fall back to default)
        $safe_config = RoutePrefix::sanitizeConfigName($config);

        try {
            // Holds the application container for making a fresh Captcha
            $container = ApplicationContext::getContainer();
            // Holds a request-scoped Captcha instance
            $captcha = $container->make(Captcha::class);

            return $captcha->create($safe_config);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('Failed to generate captcha.', 0, $throwable);
        }
    }
}

if (! function_exists('captcha_src')) {
    /*
     * Builds the public URL path for requesting a captcha image.
     * @param null|string $config Named captcha profile appended to the route
     * @return string Captcha image URL path
     */
    function captcha_src(?string $config = null): string
    {
        // Holds the default prefix when config lookup fails
        $route_prefix = RoutePrefix::DEFAULT;

        try {
            // Holds the application container for reading captcha config
            $container = ApplicationContext::getContainer();
            // Holds the config repository when available
            $config_repository = $container->get(ConfigInterface::class);
            $route_prefix = RoutePrefix::normalize(
                $config_repository->get('captcha.route_prefix', RoutePrefix::DEFAULT)
            );
        } catch (\Throwable $throwable) {
            // Falls back to the default prefix when the container is unavailable
            unset($throwable);
        }

        // Holds a sanitized profile segment safe for URL paths
        $safe_config = RoutePrefix::sanitizeConfigName($config);
        if ($safe_config !== null) {
            return $route_prefix . '/' . $safe_config;
        }

        return $route_prefix;
    }
}

if (! function_exists('captcha_img')) {
    /*
     * Renders an HTML img tag that loads and refreshes the captcha on click.
     * @param string $id Named captcha profile (same as captcha_src)
     * @param string $domid Optional DOM id attribute value for the img element
     * @return string HTML markup for the captcha image
     */
    function captcha_img(string $id = '', string $domid = ''): string
    {
        // Holds the captcha image URL used by the img tag
        $src = captcha_src($id === '' ? null : $id);
        // Holds a DOM id containing only safe HTML id characters (rejects injection vectors)
        $safe_domid = $domid === '' ? '' : (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/', $domid) ? $domid : '');
        // Holds the optional id="..." attribute fragment
        $dom_id_attr = $safe_domid === '' ? '' : "id='" . htmlspecialchars($safe_domid, ENT_QUOTES, 'UTF-8') . "'";
        // Holds the HTML-escaped image source for attribute output
        $escaped_src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

        return "<img src='{$escaped_src}' alt='captcha' {$dom_id_attr} onclick='this.src=\"{$escaped_src}?\"+Math.random();' />";
    }
}

if (! function_exists('captcha_check')) {
    /*
     * Validates a user-submitted captcha code against the cache entry.
     * @param string $value User input to verify
     * @param null|string $cache_key Public captcha key; null resolves from request input/header/cookie
     * @return bool True when the captcha matches and has not expired
     */
    function captcha_check(string $value, ?string $cache_key = null): bool
    {
        try {
            // Holds the application container for making a fresh Captcha
            $container = ApplicationContext::getContainer();
            // Holds a request-scoped Captcha instance
            $captcha = $container->make(Captcha::class);

            return $captcha->check($value, $cache_key);
        } catch (\Throwable $throwable) {
            // Fail closed when DI / cache / request context is unavailable
            unset($throwable);

            return false;
        }
    }
}
