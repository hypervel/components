<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Contracts\Attributes\Invokable;

/**
 * Provides hooks for defining database migrations and seeders.
 *
 * @property null|\Hypervel\Contracts\Foundation\Application $app
 */
trait HandlesDatabases
{
    /**
     * Determine if using in-memory SQLite database connection.
     */
    protected function usesSqliteInMemoryDatabaseConnection(?string $connection = null): bool
    {
        if ($this->app === null) {
            return false;
        }

        /** @var \Hypervel\Contracts\Config\Repository $config */
        $config = $this->app->make('config');

        $connection ??= $config->get('database.default');

        /** @var null|array{driver: string, database: string} $database */
        $database = $config->get("database.connections.{$connection}");

        if ($database === null || $database['driver'] !== 'sqlite') {
            return false;
        }

        return $database['database'] === ':memory:'
            || str_contains($database['database'], '?mode=memory')
            || str_contains($database['database'], '&mode=memory');
    }

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        // Define database migrations.
    }

    /**
     * Destroy database migrations.
     */
    protected function destroyDatabaseMigrations(): void
    {
        // Destroy database migrations.
    }

    /**
     * Define database seeders.
     */
    protected function defineDatabaseSeeders(): void
    {
        // Define database seeders.
    }

    /**
     * Define database migrations after database refreshed.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        // Define database migrations after database refreshed.
    }

    /**
     * Setup database requirements.
     *
     * Processes WithConfig and WithMigration attributes before running migrations,
     * then executes the callback (which typically runs migrations),
     * and finally runs seeders.
     */
    protected function setUpDatabaseRequirements(callable $callback): void
    {
        // Process WithConfig attributes BEFORE database connections are established
        $this->resolvePhpUnitAttributes()
            ->filter(static fn ($attrs, string $key) => $key === WithConfig::class)
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof Invokable)
            ->each(fn ($instance) => $instance($this->app));

        // Process WithMigration attributes BEFORE migrations run
        $this->resolvePhpUnitAttributes()
            ->filter(static fn ($attrs, string $key) => $key === WithMigration::class)
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof Invokable)
            ->each(fn ($instance) => $instance($this->app));

        $this->defineDatabaseMigrations();
        $this->beforeApplicationDestroyed(fn () => $this->destroyDatabaseMigrations());

        $callback();

        $this->defineDatabaseSeeders();
    }
}
