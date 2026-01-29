<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Foundation\Testing\Attributes\WithMigration;
use Hypervel\Foundation\Testing\Contracts\Attributes\Invokable;

/**
 * Provides hooks for defining database migrations and seeders.
 */
trait HandlesDatabases
{
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
     * Processes WithMigration attributes before running migrations,
     * then executes the callback (which typically runs migrations),
     * and finally runs seeders.
     */
    protected function setUpDatabaseRequirements(callable $callback): void
    {
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
