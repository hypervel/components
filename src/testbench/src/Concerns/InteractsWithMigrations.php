<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Foundation\Console\Kernel as FoundationConsoleKernel;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Arr;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Database\MigrateProcessor;
use InvalidArgumentException;
use Throwable;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\load_migration_paths;

/**
 * @internal
 */
trait InteractsWithMigrations
{
    /**
     * @var array<int, MigrateProcessor>
     */
    protected array $cachedTestMigratorProcessors = [];

    protected function setUpInteractsWithMigrations(): void
    {
        if ($this->usesInMemoryDatabaseForMigrationState()) {
            $this->afterApplicationCreated(static function (): void {
                static::usesTestingFeature(new ResetRefreshDatabaseState);
            });
        }
    }

    protected function tearDownInteractsWithMigrations(): void
    {
        $hasInMemoryConnections = ! empty(RefreshDatabaseState::$inMemoryConnections);
        $preservesInMemoryDatabase = static::usesTestingConcern(DatabaseTruncation::class)
            && ! static::usesTestingConcern(DatabaseMigrations::class);
        $processors = $this->cachedTestMigratorProcessors;
        $this->cachedTestMigratorProcessors = [];
        $failure = null;

        try {
            if (
                (count($processors) > 0 && static::usesRefreshDatabaseTestingConcern())
                || (
                    ! $preservesInMemoryDatabase
                    && $hasInMemoryConnections
                    && $this->usesInMemoryDatabaseForMigrationState()
                )
            ) {
                ResetRefreshDatabaseState::run();
            }
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        foreach ($processors as $migrator) {
            try {
                $migrator->rollback();
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @api
     *
     * @param array<int|string, mixed>|string $paths
     */
    protected function loadMigrationsFrom(array|string $paths): void
    {
        /** @var ApplicationContract $app */
        $app = $this->app;

        if (
            (is_string($paths) || Arr::isList($paths))
            && $this->shouldRegisterMigrationPaths()
        ) {
            /** @var list<string>|string $paths */
            load_migration_paths($app, $paths);

            return;
        }

        /** @var array<string, mixed>|string $paths */
        $this->runMigrationProcessor($app, $this->resolvePackageMigrationsOptions($paths));
    }

    /**
     * Determine whether migration paths should be registered for an upcoming migration.
     */
    protected function shouldRegisterMigrationPaths(): bool
    {
        $migrateRefresh = property_exists($this, 'migrateRefresh')
            && (bool) $this->migrateRefresh;

        // List and string paths target the default connection; named options use a processor.
        return static::usesTestingConcern(DatabaseMigrations::class)
            || (
                static::usesTestingConcern(DatabaseTruncation::class)
                && (
                    RefreshDatabaseState::$migrated === false
                    || $this->usesSqliteInMemoryDatabaseConnection()
                )
            )
            || (
                static::usesRefreshDatabaseTestingConcern()
                && (
                    $migrateRefresh
                    || (
                        RefreshDatabaseState::$migrated === false
                        && RefreshDatabaseState::$lazilyRefreshed === false
                    )
                )
            );
    }

    /**
     * @internal
     *
     * @param array<string, mixed>|string $paths
     * @return array<string, mixed>
     */
    protected function resolvePackageMigrationsOptions(array|string $paths = []): array
    {
        $options = is_array($paths) ? $paths : ['--path' => $paths];

        if (isset($options['--realpath']) && ! is_bool($options['--realpath'])) {
            throw new InvalidArgumentException('Expect --realpath to be a boolean.');
        }

        $options['--realpath'] = true;

        return $options;
    }

    /**
     * Migrate Hypervel's default migrations.
     *
     * @api
     *
     * @param array<string, mixed>|string $database
     */
    protected function loadHypervelMigrations(array|string $database = []): void
    {
        /** @var ApplicationContract $app */
        $app = $this->app;

        $options = $this->resolveHypervelMigrationsOptions($database);
        $options['--path'] = default_migration_path();
        $options['--realpath'] = true;

        $this->runMigrationProcessor($app, $options);
    }

    /**
     * Migrate all Hypervel migrations.
     *
     * @api
     *
     * @param array<string, mixed>|string $database
     */
    protected function runHypervelMigrations(array|string $database = []): void
    {
        /** @var ApplicationContract $app */
        $app = $this->app;

        $this->runMigrationProcessor($app, $this->resolveHypervelMigrationsOptions($database));
    }

    /**
     * @internal
     *
     * @param array<string, mixed>|string $database
     * @return array<string, mixed>
     */
    protected function resolveHypervelMigrationsOptions(array|string $database = []): array
    {
        return is_array($database) ? $database : ['--database' => $database];
    }

    /**
     * Run and retain a migration processor for teardown.
     *
     * @param array<string, mixed> $options
     */
    protected function runMigrationProcessor(ApplicationContract $app, array $options): void
    {
        $migrator = new MigrateProcessor(
            $this,
            $app->make(Migrator::class),
            $options,
        );

        try {
            $migrator->up();
        } catch (Throwable $throwable) {
            try {
                $migrator->rollback();
            } catch (Throwable) {
                // Preserve the migration failure when compensating rollback also fails.
            }

            throw $throwable;
        }

        array_unshift($this->cachedTestMigratorProcessors, $migrator);

        $this->resetApplicationArtisanCommands($app);
    }

    /**
     * Determine whether the active database concerns retain in-memory state.
     */
    protected function usesInMemoryDatabaseForMigrationState(): bool
    {
        if (
            static::usesRefreshDatabaseTestingConcern()
            && $this->usingInMemoryDatabases() /* @phpstan-ignore method.notFound */
        ) {
            return true;
        }

        if (static::usesTestingConcern(DatabaseTruncation::class)) {
            // Every cached truncation PDO needs the same class-boundary state owner.
            return $this->usingInMemoryDatabasesForTruncation(); /* @phpstan-ignore method.notFound */
        }

        return $this->usesSqliteInMemoryDatabaseConnection();
    }

    protected function resetApplicationArtisanCommands(ApplicationContract $app): void
    {
        $kernel = $app->make(ConsoleKernelContract::class);

        if ($kernel instanceof FoundationConsoleKernel) {
            $kernel->setArtisan(null);
        }
    }
}
