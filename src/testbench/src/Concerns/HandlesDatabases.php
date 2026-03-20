<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Closure;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Features\TestingFeature;

use function Hypervel\Testbench\hypervel_or_fail;

/**
 * @internal
 */
trait HandlesDatabases
{
    /**
     * Setup database requirements.
     *
     * @param Closure():void $callback
     */
    protected function setUpDatabaseRequirements(Closure $callback): void
    {
        $app = hypervel_or_fail($this->app);

        TestingFeature::run(
            testCase: $this,
            attribute: fn () => $this->parseTestMethodAttributes($app, RequiresDatabase::class),
        );

        $app['events']->listen(DatabaseRefreshed::class, function () {
            $this->defineDatabaseMigrationsAfterDatabaseRefreshed();
        });

        if (static::usesTestingConcern(WithHypervelMigrations::class)) {
            $this->setUpWithHypervelMigrations(); /* @phpstan-ignore method.notFound */
        }

        TestingFeature::run(
            testCase: $this,
            attribute: fn () => $this->parseTestMethodAttributes($app, WithMigration::class),
        );

        $attributeCallbacks = TestingFeature::run(
            testCase: $this,
            default: function () {
                $this->defineDatabaseMigrations();
                $this->beforeApplicationDestroyed(fn () => $this->destroyDatabaseMigrations());
            },
            attribute: fn () => $this->parseTestMethodAttributes($app, DefineDatabase::class),
            pest: function () {
                $this->defineDatabaseMigrationsUsingPest(); /* @phpstan-ignore method.notFound */
                $this->beforeApplicationDestroyed(fn () => $this->destroyDatabaseMigrationsUsingPest()); /* @phpstan-ignore method.notFound */
            },
        )->get('attribute');

        $callback();

        $attributeCallbacks->handle();

        TestingFeature::run(
            testCase: $this,
            default: fn () => $this->defineDatabaseSeeders(),
            pest: fn () => $this->defineDatabaseSeedersUsingPest(), /* @phpstan-ignore method.notFound */
        );
    }

    /**
     * Determine if using in-memory SQLite database connection.
     */
    protected function usesSqliteInMemoryDatabaseConnection(?string $connection = null): bool
    {
        $app = hypervel_or_fail($this->app);

        /** @var \Hypervel\Config\Repository $config */
        $config = $app->make('config');

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
     * Define database migrations after database refreshed.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        // Define database migrations after database refreshed.
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
}
