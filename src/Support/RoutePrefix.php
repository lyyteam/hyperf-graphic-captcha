<?php

declare(strict_types=1);

namespace Lyyteam\HyperfGraphicCaptcha\Support;

/*
 * Shared helpers for normalizing captcha route prefixes and profile names.
 */
final class RoutePrefix
{
    // Default HTTP path used when config is missing or invalid
    public const DEFAULT = '/captcha';

    /*
     * Normalizes a configured route prefix to a single leading-slash path.
     * @param mixed $route_prefix Raw captcha.route_prefix config value
     * @return string Safe route prefix such as /captcha
     */
    public static function normalize(mixed $route_prefix): string
    {
        // Holds the string form of the configured prefix
        $prefix = trim((string) $route_prefix);
        // Keeps only safe path characters (letters, digits, underscore, hyphen, slash)
        $prefix = preg_replace('/[^A-Za-z0-9_\/-]/', '', $prefix) ?? '';
        // Collapses duplicate slashes that may appear after sanitizing
        $prefix = preg_replace('#/+#', '/', $prefix) ?? '';
        $prefix = '/' . trim($prefix, '/');

        if ($prefix === '/' || $prefix === '' || strlen($prefix) > 128) {
            return self::DEFAULT;
        }

        return $prefix;
    }

    /*
     * Sanitizes a named captcha profile segment for URLs and route params.
     * @param null|string $config_name Raw profile name
     * @return null|string Safe profile name or null when invalid/empty
     */
    public static function sanitizeConfigName(?string $config_name): ?string
    {
        if ($config_name === null) {
            return null;
        }

        // Holds the trimmed candidate profile name
        $trimmed_name = trim($config_name);
        if ($trimmed_name === '') {
            return null;
        }

        // Rejects any name that is not entirely composed of safe URL/path characters
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $trimmed_name)) {
            return null;
        }

        return $trimmed_name;
    }
}
