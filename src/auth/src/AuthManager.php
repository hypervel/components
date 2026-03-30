<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Factory as FactoryContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * @mixin Guard
 * @mixin StatefulGuard
 */
class AuthManager implements FactoryContract
{
    use CreatesUserProviders;

    /**
     * Context key for the default guard override.
     */
    public const string DEFAULT_GUARD_CONTEXT_KEY = '__auth.defaults.guard';

    /**
     * Context key for the user resolver callback override.
     */
    protected const string RESOLVER_CONTEXT_KEY = '__auth.resolver';

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The array of created "drivers".
     */
    protected array $guards = [];

    /**
     * The user resolver shared by various services.
     *
     * Determines the default user for Gate, Request, and the Authenticatable contract.
     */
    protected Closure $userResolver;

    /**
     * Create a new Auth manager instance.
     */
    public function __construct(
        protected Container $app,
    ) {
        $this->userResolver = fn ($guard = null) => $this->guard($guard)->user();
    }

    /**
     * Attempt to get the guard from the local cache.
     */
    public function guard(?string $name = null): Guard|StatefulGuard
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->guards[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the given guard.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Guard|StatefulGuard
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
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
        return $this->customCreators[$config['driver']]($this->app, $name, $config);
    }

    /**
     * Create a session based authentication guard.
     */
    public function createSessionDriver(string $name, array $config): SessionGuard
    {
        $guard = new SessionGuard(
            $name,
            $this->createUserProvider($config['provider'] ?? null),
            $this->app['session.store'],
            $this->app,
            rehashOnLogin: $this->app['config']->get('hashing.rehash_on_login', true),
            timeboxDuration: $this->app['config']->get('auth.timebox_duration', 200000),
            hashKey: $this->app['config']->get('app.key'),
        );

        // When using the remember me functionality of the authentication services we
        // will need to be set the encryption instance of the guard, which allows
        // secure, encrypted cookie values to get generated for those cookies.
        $guard->setCookieJar($this->app['cookie']);

        $guard->setDispatcher($this->app['events']);

        if (isset($config['remember'])) {
            $guard->setRememberDuration($config['remember']);
        }

        return $guard;
    }

    /**
     * Create a token based authentication guard.
     */
    public function createTokenDriver(string $name, array $config): TokenGuard
    {
        // The token guard implements a basic API token based guard implementation
        // that takes an API token field from the request and matches it to the
        // user in the database or another persistence layer where users are.
        return new TokenGuard(
            $name,
            $this->createUserProvider($config['provider'] ?? null),
            $this->app,
            $config['input_key'] ?? 'api_token',
            $config['storage_key'] ?? 'api_token',
            $config['hash'] ?? false,
        );
    }

    /**
     * Create a JWT based authentication guard.
     */
    public function createJwtDriver(string $name, array $config): JwtGuard
    {
        return new JwtGuard(
            $name,
            $this->createUserProvider($config['provider'] ?? null),
            $this->app->make('jwt'),
            $this->app,
            (int) $this->app['config']->get('jwt.ttl', 120),
        );
    }

    /**
     * Get the guard configuration.
     */
    protected function getConfig(string $name): ?array
    {
        return $this->app['config']["auth.guards.{$name}"];
    }

    /**
     * Get the default authentication driver name.
     *
     * In Swoole, the default guard can be overridden per-request via Context
     * (e.g. when middleware calls shouldUse()), falling back to config.
     */
    public function getDefaultDriver(): string
    {
        if ($driver = CoroutineContext::get(self::DEFAULT_GUARD_CONTEXT_KEY)) {
            return $driver;
        }

        return $this->app['config']['auth.defaults.guard'];
    }

    /**
     * Set the default guard driver the factory should serve.
     */
    public function shouldUse(?string $name): void
    {
        $name = $name ?: $this->getDefaultDriver();

        $this->setDefaultDriver($name);

        $this->resolveUsersUsing(fn ($name = null) => $this->guard($name)->user());
    }

    /**
     * Set the default authentication driver name.
     *
     * Uses coroutine Context so one request's override doesn't affect others.
     */
    public function setDefaultDriver(string $name): void
    {
        CoroutineContext::set(self::DEFAULT_GUARD_CONTEXT_KEY, $name);
    }

    /**
     * Register a new callback based request guard.
     */
    public function viaRequest(string $driver, callable $callback): static
    {
        return $this->extend($driver, function ($app, $name) use ($callback) {
            return new RequestGuard($name, $callback, $app, $this->createUserProvider());
        });
    }

    /**
     * Get the user resolver callback.
     *
     * Checks coroutine Context first for per-request overrides, then falls
     * back to the process-global default resolver.
     */
    public function userResolver(): Closure
    {
        if ($resolver = CoroutineContext::get(self::RESOLVER_CONTEXT_KEY)) {
            return $resolver;
        }

        return $this->userResolver;
    }

    /**
     * Set the callback to be used to resolve users.
     *
     * Uses coroutine Context so one request's override doesn't affect others.
     */
    public function resolveUsersUsing(Closure $userResolver): static
    {
        CoroutineContext::set(self::RESOLVER_CONTEXT_KEY, $userResolver);

        return $this;
    }

    /**
     * Register a custom driver creator Closure.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Register a custom provider creator Closure.
     */
    public function provider(string $name, Closure $callback): static
    {
        $this->customProviderCreators[$name] = $callback;

        return $this;
    }

    /**
     * Determine if any guards have already been resolved.
     */
    public function hasResolvedGuards(): bool
    {
        return count($this->guards) > 0;
    }

    /**
     * Forget all of the resolved guard instances.
     */
    public function forgetGuards(): static
    {
        $this->guards = [];

        return $this;
    }

    /**
     * Get all of the created guard instances.
     */
    public function getGuards(): array
    {
        return $this->guards;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(Container $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->guard()->{$method}(...$parameters);
    }
}
