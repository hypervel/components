<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\RefreshDatabaseState;

use function Hypervel\Testbench\after_resolving;
use function Hypervel\Testbench\default_migration_path;

trait WithHypervelMigrations
{
    use InteractsWithWorkbench;

    /**
     * @internal
     */
    protected function setUpWithHypervelMigrations(): void
    {
        $loadHypervelMigrations = static::cachedConfigurationForWorkbench()?->getWorkbenchAttributes()['install'] ?? false;

        if (! ($loadHypervelMigrations && is_dir(default_migration_path()))) {
            return;
        }

        $migrateRefresh = property_exists($this, 'migrateRefresh')
            && (bool) $this->migrateRefresh;
        $refreshesDatabase = static::usesTestingConcern(DatabaseMigrations::class)
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

        if ($refreshesDatabase) {
            after_resolving($this->app, 'migrator', static function ($migrator, $app): void {
                $migrator->path(default_migration_path());
            });
        } else {
            $this->loadHypervelMigrations();
        }
    }
}
