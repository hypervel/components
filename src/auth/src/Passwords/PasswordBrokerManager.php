<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

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
        $name = $name ?: $this->getDefaultDriver();

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
            $this->app['auth']->createUserProvider($config['provider'] ?? null),
            $this->app['events'] ?? null,
            timeboxDuration: $this->app['config']->get('auth.timebox_duration', 200000),
        );
    }

    /**
     * Create a token repository instance based on the given configuration.
     */
    protected function createTokenRepository(array $config): TokenRepositoryInterface
    {
        $key = $this->app['config']['app.key'];

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        if (isset($config['driver']) && $config['driver'] === 'cache') {
            return new CacheTokenRepository(
                $this->app['cache']->store($config['store'] ?? null),
                $this->app['hash'],
                $key,
                ($config['expire'] ?? 60) * 60,
                $config['throttle'] ?? 0,
            );
        }

        return new DatabaseTokenRepository(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
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
        return $this->app['config']["auth.passwords.{$name}"];
    }

    /**
     * Get the default password broker name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app['config']['auth.defaults.passwords'];
    }

    /**
     * Set the default password broker name.
     *
     * WARNING: Mutates process-global config. Not safe for per-request use under Swoole.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->app['config']['auth.defaults.passwords'] = $name;
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->broker()->{$method}(...$parameters);
    }
}
