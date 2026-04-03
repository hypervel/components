<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

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

        if (
            static::usesRefreshDatabaseTestingConcern()
            && RefreshDatabaseState::$migrated === false
            && RefreshDatabaseState::$lazilyRefreshed === false
        ) {
            after_resolving($this->app, 'migrator', static function ($migrator, $app): void {
                $migrator->path(default_migration_path());
            });
        } else {
            $this->loadHypervelMigrations();
        }
    }
}
