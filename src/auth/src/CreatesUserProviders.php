<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Auth\Providers\DatabaseUserProvider;
use Hypervel\Auth\Providers\EloquentUserProvider;
use Hypervel\Hashing\Contracts\Hasher as HashContract;
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
     *
     * @param null|string $provider
     */
    protected function getProviderConfiguration($provider): ?array
    {
        if ($provider = $provider ?: $this->getDefaultUserProvider()) {
            return $this->config->get("auth.providers.{$provider}");
        }

        return null;
    }

    /**
     * Create an instance of the database user provider.
     */
    protected function createDatabaseProvider(array $config): DatabaseUserProvider
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)
            ->connection($config['connection'] ?? null);

        return new DatabaseUserProvider(
            $connection,
            $this->app->make(HashContract::class),
            $config['table']
        );
    }

    /**
     * Create an instance of the Eloquent user provider.
     */
    protected function createEloquentProvider(array $config): EloquentUserProvider
    {
        return new EloquentUserProvider(
            $this->app->make(HashContract::class),
            $config['model']
        );
    }

    /**
     * Get the default user provider name.
     */
    public function getDefaultUserProvider(): string
    {
        return $this->config->get('auth.defaults.provider');
    }
}
