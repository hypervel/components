<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Database\Eloquent\Model;
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

        $this->refreshModelBootedStates();

        $this->beforeApplicationDestroyed(function () {
            $this->command('migrate:rollback');

            RefreshDatabaseState::$migrated = false;
        });
    }

    /**
     * Refresh the model booted states.
     *
     * Clears the static booted model tracking so that models re-register
     * their event listeners with the current event dispatcher. This is
     * necessary when Event::fake() creates a new EventFake, otherwise
     * model event callbacks point to the old dispatcher.
     */
    protected function refreshModelBootedStates(): void
    {
        Model::clearBootedModels();
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
