<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

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
     */
    protected function setUpDatabaseRequirements(callable $callback): void
    {
        $this->defineDatabaseMigrations();
        $this->beforeApplicationDestroyed(fn () => $this->destroyDatabaseMigrations());

        $callback();

        $this->defineDatabaseSeeders();
    }
}
