<?php

declare(strict_types=1);

namespace Lyyteam\HyperfGraphicCaptcha\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Lyyteam\HyperfGraphicCaptcha\Controller\CaptchaController;
use Lyyteam\HyperfGraphicCaptcha\Support\RoutePrefix;
use Psr\Container\ContainerInterface;
use Throwable;

/*
 * Registers captcha routes and the optional validation rule during application boot.
 */
class RegisterCaptchaListener implements ListenerInterface
{
    // Tracks whether routes were already registered in this worker process
    private static bool $routes_registered = false;

    // Tracks whether the validation rule was already registered in this worker process
    private static bool $validation_registered = false;

    // Holds the DI container for resolving router and validator factories
    private ContainerInterface $container;

    /*
     * @param ContainerInterface $container Application container
     */
    public function __construct(ContainerInterface $container)
    {
        // Stores the container for deferred service resolution
        $this->container = $container;
    }

    /*
     * Declares the framework events this listener cares about.
     * @return array<class-string>
     */
    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /*
     * Registers HTTP routes and extends the validator when available.
     * @param object $event BootApplication event instance
     */
    public function process(object $event): void
    {
        // Event payload is unused; boot timing is what matters
        unset($event);

        try {
            $this->registerRoutes();
        } catch (Throwable $throwable) {
            // Route registration failure must not prevent the rest of boot
            unset($throwable);
        }

        try {
            $this->registerValidationRule();
        } catch (Throwable $throwable) {
            // Validation rule registration is optional and must not break boot
            unset($throwable);
        }
    }

    /*
     * Adds GET {prefix} and GET {prefix}/{config} to the HTTP router once per worker.
     */
    private function registerRoutes(): void
    {
        if (self::$routes_registered) {
            return;
        }

        if (! $this->container->has(DispatcherFactory::class)) {
            return;
        }

        // Holds the configured captcha route prefix (defaults to /captcha)
        $route_prefix = RoutePrefix::DEFAULT;
        if ($this->container->has(ConfigInterface::class)) {
            try {
                // Holds the config repository for reading captcha.route_prefix
                $config = $this->container->get(ConfigInterface::class);
                $route_prefix = RoutePrefix::normalize($config->get('captcha.route_prefix', RoutePrefix::DEFAULT));
            } catch (Throwable $throwable) {
                // Falls back to the default prefix when config lookup fails
                unset($throwable);
                $route_prefix = RoutePrefix::DEFAULT;
            }
        }

        // Holds the router dispatcher factory for the http server
        $dispatcher_factory = $this->container->get(DispatcherFactory::class);
        // Holds the mutable router used to append captcha endpoints
        $router = $dispatcher_factory->getRouter('http');

        $router->addRoute(['GET'], $route_prefix, [CaptchaController::class, 'index']);
        $router->addRoute(
            ['GET'],
            $route_prefix . '/{config:[A-Za-z0-9_-]+}',
            [CaptchaController::class, 'index']
        );

        // Marks registered only after both routes succeed to avoid partial double-registration
        self::$routes_registered = true;
    }

    /*
     * Extends hyperf/validation with a `captcha` rule when the package is installed.
     */
    private function registerValidationRule(): void
    {
        if (self::$validation_registered) {
            return;
        }

        if (! interface_exists(ValidatorFactoryInterface::class)) {
            return;
        }

        if (! $this->container->has(ValidatorFactoryInterface::class)) {
            return;
        }

        // Holds the validator factory used to register custom rules
        $validator_factory = $this->container->get(ValidatorFactoryInterface::class);
        $validator_factory->extend(
            'captcha',
            static function ($attribute, $value, $parameters, $validator): bool {
                // Attribute name is unused for captcha verification
                unset($attribute, $parameters);

                if (! is_scalar($value) || is_bool($value)) {
                    return false;
                }

                try {
                    // Holds request data collected by the validator instance
                    $data = is_object($validator) && method_exists($validator, 'getData')
                        ? (array) $validator->getData()
                        : [];
                    // Holds the public captcha key submitted alongside the code
                    $cache_key = $data['captcha_key'] ?? null;
                    if (is_array($cache_key)) {
                        $cache_key = $cache_key[0] ?? null;
                    }

                    // Passes through captcha_check so empty/wrong answers still consume the challenge
                    return captcha_check(
                        (string) $value,
                        is_scalar($cache_key) && ! is_bool($cache_key) && (string) $cache_key !== ''
                            ? (string) $cache_key
                            : null
                    );
                } catch (Throwable $throwable) {
                    // Validation must fail closed when captcha infrastructure errors
                    unset($throwable);

                    return false;
                }
            },
            ':attribute 验证码错误!'
        );

        self::$validation_registered = true;
    }
}
