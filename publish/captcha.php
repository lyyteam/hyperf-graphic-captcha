<?php

declare(strict_types=1);

/*
 * Default graphic captcha configuration for Hyperf.
 * Named groups can be added as nested arrays (e.g. 'verify' => [...]).
 * Omit keys you do not need to override; built-in defaults fill the rest.
 */
return [
    // Number of characters drawn on the captcha image
    'length' => 5,
    // Characters used when generating a non-Chinese captcha
    'code_set' => '2345678abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY',
    // Captcha lifetime in seconds
    'expire' => 1800,
    // Whether to generate a Chinese-character captcha
    'use_zh' => false,
    // Whether to use an arithmetic expression instead of characters
    'math' => false,
    // Whether to paint a JPEG background image behind the text
    'use_img_bg' => false,
    // Font size in pixels
    'font_size' => 25,
    // Curve interference strength 0–10 (0 off; 5 default look; higher = harder to read)
    'curve_strength' => 5,
    // Noise interference strength 0–10 (0 off; 5 default look; higher = harder to read)
    'noise_strength' => 5,
    // Specific font file name; empty means pick a random font from assets
    'fontttf' => '',
    // RGB background color used when use_img_bg is false
    'bg' => [243, 251, 254],
    // Image height in pixels; 0 means auto-calculate from font size
    'image_h' => 0,
    // Image width in pixels; 0 means auto-calculate from length and font size
    'image_w' => 0,
    // Background alpha from 0 (opaque) to 127 (transparent)
    'alpha' => 0,
    // When true, create() returns base64 payload instead of an image response
    'api' => false,
    // Route path prefix used by captcha_src() / captcha_img()
    'route_prefix' => '/captcha',
    // Cache key prefix for storing captcha hashes
    'cache_prefix' => 'graphic_captcha:',
    // Cookie name that carries the captcha key for browser image flows
    'cookie_name' => 'graphic_captcha_key',

    // Example named profile:
    // 'verify' => [
    //     'length' => 4,
    //     'curve_strength' => 2,
    //     'noise_strength' => 1,
    // ],
];
