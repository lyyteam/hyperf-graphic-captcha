<?php

declare(strict_types=1);

namespace Lyyteam\HyperfGraphicCaptcha;

use Lyyteam\HyperfGraphicCaptcha\Listener\RegisterCaptchaListener;

/*
 * Registers dependencies, annotations, listeners, and publishable config for the component.
 */
class ConfigProvider
{
    /*
     * Returns the Hyperf ConfigProvider payload merged at framework boot.
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                // Captcha is request-made via helpers/controller; no singleton binding needed
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'listeners' => [
                RegisterCaptchaListener::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'Graphic captcha configuration file.',
                    'source' => __DIR__ . '/../publish/captcha.php',
                    'destination' => (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4))
                        . '/config/autoload/captcha.php',
                ],
            ],
        ];
    }
}
