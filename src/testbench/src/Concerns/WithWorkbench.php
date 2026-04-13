<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray;
use Hypervel\Testbench\Workbench\Workbench;

trait WithWorkbench
{
    use InteractsWithWorkbench;

    /**
     * Bootstrap with Workbench.
     *
     * @internal
     */
    protected function setUpWithWorkbench(): void
    {
        $app = $this->app;

        $config = static::cachedConfigurationForWorkbench();

        if ($app === null || $config === null) {
            return;
        }

        Workbench::start($app, $config);

        $seeders = $config['seeders'] ?? false;

        $seeders = static::usesTestingConcern(CanConfigureMigrationCommands::class)
            ? $this->mergeSeedersForWorkbench($config)
            : ($config['seeders'] ?? false);

        (new LoadMigrationsFromArray(
            $config['migrations'] ?? [],
            $seeders,
        ))->bootstrap($app);
    }

    /**
     * Bootstrap discover routes.
     *
     * @internal
     */
    protected function bootDiscoverRoutesForWorkbench(object $app): void
    {
        $config = static::cachedConfigurationForWorkbench();

        if ($config !== null) {
            Workbench::discoverRoutes($app, $config);
        }
    }

    /**
     * Merge seeders for Workbench.
     *
     * @return array<int, class-string>|false
     */
    protected function mergeSeedersForWorkbench(ConfigContract $config): array|false
    {
        $seeders = $config['seeders'] ?? false;

        if ($this->shouldSeed() === false || $seeders === false) {
            return false;
        }

        $testCaseSeeder = $this->seeder();

        $testCaseSeeder = $testCaseSeeder !== false
            ? $testCaseSeeder
            : \Database\Seeders\DatabaseSeeder::class;

        $seeders = (new Collection($seeders))
            ->reject(static fn (mixed $seeder): bool => $seeder === $testCaseSeeder)
            ->values();

        return $seeders->isEmpty() ? false : $seeders->all();
    }
}
