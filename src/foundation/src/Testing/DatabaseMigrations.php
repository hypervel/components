<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Foundation\Testing\Traits\CanConfigureMigrationCommands;

trait DatabaseMigrations
{
    use CanConfigureMigrationCommands;

    /**
     * Define hooks to migrate the database before and after each test.
     */
    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();

        $this->command('migrate:fresh', $this->migrateFreshUsing());

        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function () {
            $this->command('migrate:rollback');

            RefreshDatabaseState::$migrated = false;
        });
    }

    /**
     * Perform any work that should take place before the database has started refreshing.
     */
    protected function beforeRefreshingDatabase(): void
    {
        // ...
    }

    /**
     * Perform any work that should take place once the database has finished refreshing.
     */
    protected function afterRefreshingDatabase(): void
    {
        // ...
    }
}
