<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Console\Kernel as FoundationConsoleKernel;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Arr;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Database\MigrateProcessor;
use InvalidArgumentException;

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
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->afterApplicationCreated(static function (): void {
                static::usesTestingFeature(new ResetRefreshDatabaseState());
            });
        }
    }

    protected function tearDownInteractsWithMigrations(): void
    {
        $hasInMemoryConnections = ! empty(RefreshDatabaseState::$inMemoryConnections);

        if (
            (count($this->cachedTestMigratorProcessors) > 0 && static::usesRefreshDatabaseTestingConcern())
            || ($hasInMemoryConnections && $this->usesSqliteInMemoryDatabaseConnection())
        ) {
            ResetRefreshDatabaseState::run();
        }

        foreach ($this->cachedTestMigratorProcessors as $migrator) {
            $migrator->rollback();
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
            && static::usesRefreshDatabaseTestingConcern()
            && RefreshDatabaseState::$migrated === false
            && RefreshDatabaseState::$lazilyRefreshed === false
        ) {
            /** @var list<string>|string $paths */
            load_migration_paths($app, $paths);

            return;
        }

        /** @var array<string, mixed>|string $paths */
        $migrator = new MigrateProcessor($this, $this->resolvePackageMigrationsOptions($paths));
        $migrator->up();

        array_unshift($this->cachedTestMigratorProcessors, $migrator);

        $this->resetApplicationArtisanCommands($app);
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

        $migrator = new MigrateProcessor($this, $this->resolveHypervelMigrationsOptions($options));
        $migrator->up();

        array_unshift($this->cachedTestMigratorProcessors, $migrator);

        $this->resetApplicationArtisanCommands($app);
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

        $migrator = new MigrateProcessor($this, $this->resolveHypervelMigrationsOptions($database));
        $migrator->up();

        array_unshift($this->cachedTestMigratorProcessors, $migrator);

        $this->resetApplicationArtisanCommands($app);
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

    protected function resetApplicationArtisanCommands(ApplicationContract $app): void
    {
        $kernel = $app->make(ConsoleKernelContract::class);

        if ($kernel instanceof FoundationConsoleKernel) {
            $kernel->setArtisan(null);
        }
    }
}
