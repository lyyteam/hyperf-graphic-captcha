<?php

declare(strict_types=1);

namespace Lyyteam\HyperfGraphicCaptcha\Controller;

use Hyperf\HttpServer\Contract\RequestInterface;
use Lyyteam\HyperfGraphicCaptcha\Captcha;
use Lyyteam\HyperfGraphicCaptcha\Support\RoutePrefix;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/*
 * HTTP controller that outputs a captcha image for the registered route.
 */
class CaptchaController
{
    // Holds the DI container used to make a fresh Captcha per request
    private ContainerInterface $container;

    /*
     * @param ContainerInterface $container Application container
     */
    public function __construct(ContainerInterface $container)
    {
        // Stores the container for request-scoped Captcha creation
        $this->container = $container;
    }

    /*
     * Handles GET /captcha[/{config}] and returns PNG or API payload.
     * @param RequestInterface $request Current HTTP request
     * @param null|string $config Optional named captcha profile
     * @return ResponseInterface|array
     */
    public function index(RequestInterface $request, ?string $config = null): ResponseInterface|array
    {
        // Prefers route attribute, then query string, for the named profile
        $config_name = RoutePrefix::sanitizeConfigName($config);
        if ($config_name === null) {
            // Holds optional config name from query (?config=verify)
            $query_config = $request->input('config');
            if (is_array($query_config)) {
                $query_config = $query_config[0] ?? null;
            }
            $config_name = is_scalar($query_config)
                ? RoutePrefix::sanitizeConfigName((string) $query_config)
                : null;
        }

        try {
            // Holds a fresh Captcha instance to avoid coroutine state leakage
            $captcha = $this->container->make(Captcha::class);

            return $captcha->create($config_name);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Failed to generate captcha.', 0, $throwable);
        }
    }
}
