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
     * Get the provider name declared by the current default guard.
     */
    public function getDefaultUserProvider(): ?string
    {
        return $this->app->make('config')->get('auth.guards.' . $this->getDefaultDriver() . '.provider');
    }

    /**
     * Get the user provider configuration.
     */
    protected function getProviderConfiguration(?string $provider): ?array
    {
        if (is_null($provider)) {
            return null;
        }

        return $this->app->make('config')->get('auth.providers.' . $provider);
    }

    /**
     * Create an instance of the database user provider.
     */
    protected function createDatabaseProvider(array $config): DatabaseUserProvider
    {
        return new DatabaseUserProvider(
            $this->app->make('db')->connection($config['connection'] ?? null),
            $this->app->make('hash'),
            $config['table'],
        );
    }

    /**
     * Create an instance of the Eloquent user provider.
     */
    protected function createEloquentProvider(array $config): EloquentUserProvider
    {
        $provider = new EloquentUserProvider($this->app->make('hash'), $config['model']);

        if (! empty($config['cache']['enabled'])) {
            $ttl = $config['cache']['ttl'] ?? 300;

            if (! is_int($ttl) || $ttl <= 0) {
                throw new InvalidArgumentException('The auth user cache TTL must be a positive integer.');
            }

            $provider->enableCache(
                $config['cache']['store'] ?? null,
                $ttl,
                $config['cache']['prefix'] ?? 'auth_users',
                $config['cache']['tags'] ?? null,
            );
        }

        return $provider;
    }
}
