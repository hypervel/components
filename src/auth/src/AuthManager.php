<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\Contracts\Factory as AuthFactoryContract;
use Hypervel\Auth\Contracts\Guard;
use Hypervel\Auth\Contracts\StatefulGuard;
use Hypervel\Auth\Guards\JwtGuard;
use Hypervel\Auth\Guards\RequestGuard;
use Hypervel\Auth\Guards\SessionGuard;
use Hypervel\JWT\JWTManager;
use Hypervel\Session\Contracts\Session as SessionContract;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class AuthManager implements AuthFactoryContract
{
    use CreatesUserProviders;

    /**
     * The array of created "drivers".
     */
    protected array $guards = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The user resolver shared by various services.
     *
     * Determines the default user for Authenticatable contract.
     */
    protected Closure $userResolver;

    /**
     * The auth configuration.
     */
    protected ConfigInterface $config;

    public function __construct(
        protected ContainerInterface $app
    ) {
        $this->config = $this->app->get(ConfigInterface::class);
        $this->userResolver = function ($guard = null) {
            return $this->guard($guard)->user();
        };
    }

    /**
     * Attempt to get the guard from the local cache.
     */
    public function guard(?string $name = null): Guard|StatefulGuard
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->guards[$name] ?? $this->guards[$name] = $this->resolve($name);
    }

    /**
     * Resolve the given guard.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Guard
    {
        if (! $config = $this->getConfig($name)) {
            throw new InvalidArgumentException("Auth guard [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($name, $config);
        }

        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($name, $config);
        }

        throw new InvalidArgumentException(
            "Auth driver [{$config['driver']}] for guard [{$name}] is not defined."
        );
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(string $name, array $config): mixed
    {
        return $this->customCreators[$config['driver']]($name, $config);
    }

    /**
     * Create a session based authentication guard.
     */
    public function createSessionDriver(string $name, array $config): SessionGuard
    {
        return new SessionGuard(
            $name,
            $this->createUserProvider($config['provider'] ?? null),
            $this->app->get(SessionContract::class)
        );
    }

    /**
     * Create a jwt based authentication guard.
     */
    public function createJwtDriver(string $name, array $config): JwtGuard
    {
        return new JwtGuard(
            $name,
            $this->createUserProvider($config['provider'] ?? null),
            $this->app->get(JWTManager::class),
            $this->app->get(RequestInterface::class),
            (int) $this->config->get('jwt.ttl', 120)
        );
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @return $this
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Register a custom provider creator Closure.
     *
     * @return $this
     */
    public function provider(string $name, Closure $callback): static
    {
        $this->customProviderCreators[$name] = $callback;

        return $this;
    }

    /**
     * Get the default authentication driver name.
     */
    public function getDefaultDriver(): string
    {
        if ($driver = Context::get('__auth.defaults.guard')) {
            return $driver;
        }

        return $this->config->get('auth.defaults.guard');
    }

    /**
     * Set the default guard the factory should serve.
     */
    public function shouldUse(?string $name): void
    {
        $name = $name ?: $this->getDefaultDriver();

        $this->setDefaultDriver($name);

        $this->resolveUsersUsing(function ($name = null) {
            return $this->guard($name)->user();
        });
    }

    /**
     * Set the default authentication driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        Context::set('__auth.defaults.guard', $name);
    }

    /**
     * Register a new callback based request guard.
     */
    public function viaRequest(string $driver, callable $callback): static
    {
        return $this->extend($driver, function () use ($callback) {
            return new RequestGuard($this->createUserProvider(), $callback);
        });
    }

    /**
     * Get the user resolver callback.
     */
    public function userResolver(): Closure
    {
        if ($resolver = Context::get('__auth.resolver')) {
            return $resolver;
        }

        return $this->userResolver;
    }

    /**
     * Get the user resolver callback.
     *
     * @return $this
     */
    public function resolveUsersUsing(Closure $userResolver): static
    {
        Context::set('__auth.resolver', $userResolver);

        return $this;
    }

    /**
     * Get the guard configuration.
     */
    protected function getConfig(string $name): array
    {
        return $this->config->get("auth.guards.{$name}", []);
    }

    public function getGuards(): array
    {
        return $this->guards;
    }

    /**
     * Set the application instance used by the manager.
     *
     * @return $this
     */
    public function setApplication(ContainerInterface $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->guard()->{$method}(...$parameters);
    }
}
