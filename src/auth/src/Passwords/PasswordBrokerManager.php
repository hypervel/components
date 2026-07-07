<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Auth\PasswordBrokerFactory as FactoryContract;
use Hypervel\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * @mixin PasswordBrokerContract
 */
class PasswordBrokerManager implements FactoryContract
{
    /**
     * The coroutine context key holding the per-request default broker override.
     */
    public const string DEFAULT_BROKER_CONTEXT_KEY = '__auth.passwords.default_broker';

    /**
     * The array of created "drivers".
     */
    protected array $brokers = [];

    /**
     * Create a new PasswordBroker manager instance.
     */
    public function __construct(
        protected Container $app,
    ) {
    }

    /**
     * Attempt to get the broker from the local cache.
     */
    public function broker(?string $name = null): PasswordBrokerContract
    {
        $name ??= $this->getDefaultDriver();

        return $this->brokers[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the given broker.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): PasswordBrokerContract
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Password resetter [{$name}] is not defined.");
        }

        // The password broker uses a token repository to validate tokens and send user
        // password e-mails, as well as validating that password reset process as an
        // aggregate service of sorts providing a convenient interface for resets.
        return new PasswordBroker(
            $this->createTokenRepository($config),
            $this->app->make('auth')->createUserProvider($config['provider'] ?? null),
            $name,
            $this->app->bound('events') ? $this->app->make('events') : null,
            timeboxDuration: $this->app->make('config')->integer('auth.timebox_duration', 200000),
        );
    }

    /**
     * Create a token repository instance based on the given configuration.
     */
    protected function createTokenRepository(array $config): TokenRepositoryInterface
    {
        // Fail fast: a missing app key must not silently hash reset tokens with an empty key.
        $key = $this->app->make('config')->string('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        if (isset($config['driver']) && $config['driver'] === 'cache') {
            return new CacheTokenRepository(
                $this->app->make('cache')->store($config['store'] ?? null),
                $this->app->make('hash'),
                $key,
                ($config['expire'] ?? 60) * 60,
                $config['throttle'] ?? 0,
            );
        }

        return new DatabaseTokenRepository(
            $this->app->make('db')->connection($config['connection'] ?? null),
            $this->app->make('hash'),
            $config['table'],
            $key,
            ($config['expire'] ?? 60) * 60,
            $config['throttle'] ?? 0,
        );
    }

    /**
     * Get the password broker configuration.
     */
    protected function getConfig(string $name): ?array
    {
        return $this->app->make('config')->get("auth.passwords.{$name}");
    }

    /**
     * Resolve the password broker name declared by the given guard.
     *
     * @throws InvalidArgumentException
     */
    public function resolveBrokerNameForGuard(string $guard): ?string
    {
        $config = $this->app->make('config');
        $key = "auth.guards.{$guard}.passwords";

        if (! $config->has($key)) {
            return null;
        }

        $name = $config->string($key);

        return $name !== '' ? $name : null;
    }

    /**
     * Get the default password broker name.
     *
     * Resolves the coroutine-scoped override first, then the broker declared
     * by the current default guard's "passwords" key.
     *
     * @throws InvalidArgumentException when the current default guard does not declare a broker
     */
    public function getDefaultDriver(): string
    {
        if (CoroutineContext::has(self::DEFAULT_BROKER_CONTEXT_KEY)) {
            return CoroutineContext::get(self::DEFAULT_BROKER_CONTEXT_KEY);
        }

        $guard = $this->app->make(AuthFactoryContract::class)->getDefaultDriver();

        if (($name = $this->resolveBrokerNameForGuard($guard)) !== null) {
            return $name;
        }

        throw new InvalidArgumentException(
            "Auth guard [{$guard}] does not declare a passwords broker. Set auth.guards.{$guard}.passwords."
        );
    }

    /**
     * Set the default password broker name.
     *
     * Uses coroutine Context so one request's override doesn't affect others.
     */
    public function setDefaultDriver(string $name): void
    {
        CoroutineContext::set(self::DEFAULT_BROKER_CONTEXT_KEY, $name);
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->broker()->{$method}(...$parameters);
    }
}
