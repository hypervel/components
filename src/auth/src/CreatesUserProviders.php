<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Contracts\Auth\UserProvider;
use InvalidArgumentException;

trait CreatesUserProviders
{
    /**
     * The registered custom provider creators.
     */
    protected array $customProviderCreators = [];

    /**
     * Create the user provider implementation for the driver.
     *
     * @throws InvalidArgumentException
     */
    public function createUserProvider(?string $provider = null): ?UserProvider
    {
        if (is_null($config = $this->getProviderConfiguration($provider))) {
            return null;
        }

        if (isset($this->customProviderCreators[$driver = ($config['driver'] ?? null)])) {
            return call_user_func(
                $this->customProviderCreators[$driver],
                $this->app,
                $config
            );
        }

        return match ($driver) {
            'database' => $this->createDatabaseProvider($config),
            'eloquent' => $this->createEloquentProvider($config),
            default => throw new InvalidArgumentException(
                "Authentication user provider [{$driver}] is not defined."
            ),
        };
    }

    /**
     * Get the user provider configuration.
     */
    protected function getProviderConfiguration(?string $provider): ?array
    {
        if ($provider = $provider ?: $this->getDefaultUserProvider()) {
            return $this->app['config']['auth.providers.' . $provider];
        }

        return null;
    }

    /**
     * Create an instance of the database user provider.
     */
    protected function createDatabaseProvider(array $config): DatabaseUserProvider
    {
        return new DatabaseUserProvider(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
        );
    }

    /**
     * Create an instance of the Eloquent user provider.
     */
    protected function createEloquentProvider(array $config): EloquentUserProvider
    {
        $provider = new EloquentUserProvider($this->app['hash'], $config['model']);

        if (! empty($config['cache']['enabled'])) {
            $provider->enableCache(
                $config['cache']['store'] ?? null,
                (int) ($config['cache']['ttl'] ?? 300),
                $config['cache']['prefix'] ?? 'auth_users',
                $config['cache']['tags'] ?? null,
            );
        }

        return $provider;
    }

    /**
     * Get the default user provider name.
     */
    public function getDefaultUserProvider(): string
    {
        return $this->app['config']['auth.defaults.provider'];
    }
}
