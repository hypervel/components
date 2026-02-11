<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\Connection as DatabaseConnection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\Traits\CanConfigureMigrationCommands;

trait RefreshDatabase
{
    use CanConfigureMigrationCommands;

    /**
     * Define hooks to migrate the database before and after each test.
     *
     * For tests using RunTestsInCoroutine, the transaction and post-refresh
     * hooks are deferred to setUpRefreshDatabaseInCoroutine() to keep all
     * transaction state in the same coroutine.
     */
    public function refreshDatabase(): void
    {
        $this->beforeRefreshingDatabase();

        // Restore in-memory database BEFORE migrations for all tests.
        // This ensures the correct ordering: restore cached PDO → run migrations → begin transaction.
        // For in-memory SQLite, this avoids overwriting a freshly migrated schema later.
        if ($this->usingInMemoryDatabase()) {
            $this->restoreInMemoryDatabase();
        }

        $this->refreshTestDatabase();

        // For coroutine tests, these run in setUpRefreshDatabaseInCoroutine()
        // to maintain correct ordering: transaction → afterRefreshing → test
        if (! in_array(RunTestsInCoroutine::class, class_uses_recursive(static::class), true)) {
            $this->afterRefreshingDatabase();
            $this->refreshModelBootedStates();
        }
    }

    /**
     * Refresh the model booted states.
     */
    protected function refreshModelBootedStates(): void
    {
        Model::clearBootedModels();
    }

    /**
     * Restore the in-memory database between tests.
     */
    protected function restoreInMemoryDatabase(): void
    {
        $database = $this->app->get(DatabaseManager::class);

        foreach ($this->connectionsToTransact() as $name) {
            if (isset(RefreshDatabaseState::$inMemoryConnections[$name])) {
                $database->connection($name)
                    ->setPdo(RefreshDatabaseState::$inMemoryConnections[$name])
                    ->setEventDispatcher($this->app->get(Dispatcher::class));
            }
        }
    }

    /**
     * Determine if an in-memory database is being used.
     */
    protected function usingInMemoryDatabase(): bool
    {
        $config = $this->app->get('config');

        return $config->get("database.connections.{$this->getRefreshConnection()}.database") === ':memory:';
    }

    /**
     * Refresh a conventional test database.
     *
     * Runs migrations if needed. For non-coroutine tests, also starts the
     * wrapper transaction. For coroutine tests, transaction handling is
     * deferred to setUpRefreshDatabaseInCoroutine().
     */
    protected function refreshTestDatabase(): void
    {
        $shouldMockOutput = true;
        if ($hasMockConsoleOutput = property_exists($this, 'mockConsoleOutput')) {
            $shouldMockOutput = $this->mockConsoleOutput;

            $this->mockConsoleOutput = false;
        }

        $migrateRefresh = property_exists($this, 'migrateRefresh') && (bool) $this->migrateRefresh;
        if ($migrateRefresh || ! RefreshDatabaseState::$migrated) {
            $this->command('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = true;
            if ($migrateRefresh) {
                $this->migrateRefresh = false;
            }
        }

        if ($hasMockConsoleOutput) {
            $this->mockConsoleOutput = $shouldMockOutput;
        }

        // For coroutine tests, transaction handling happens in setUpRefreshDatabaseInCoroutine()
        if (! in_array(RunTestsInCoroutine::class, class_uses_recursive(static::class), true)) {
            $this->beginDatabaseTransactionWork();

            $this->beforeApplicationDestroyed(function () {
                $this->rollbackDatabaseTransactionWork();
            });
        }
    }

    /**
     * Start database transaction in the test coroutine.
     *
     * Called by RunTestsInCoroutine before the test runs. Maintains correct
     * ordering: transaction starts, then afterRefreshingDatabase runs, then
     * test executes. This keeps all transaction state in the same coroutine.
     *
     * Note: restoreInMemoryDatabase() runs earlier in refreshDatabase() before
     * migrations, which is the correct ordering for in-memory SQLite.
     */
    protected function setUpRefreshDatabaseInCoroutine(): void
    {
        $this->beginDatabaseTransactionWork();
        $this->afterRefreshingDatabase();
        $this->refreshModelBootedStates();
    }

    /**
     * Rollback database transaction in the test coroutine.
     *
     * Called by RunTestsInCoroutine after the test runs.
     */
    protected function tearDownRefreshDatabaseInCoroutine(): void
    {
        $this->rollbackDatabaseTransactionWork();
    }

    /**
     * Start transactions on all connections.
     */
    protected function beginDatabaseTransactionWork(): void
    {
        $database = $this->app->get(DatabaseManager::class);
        $connections = $this->connectionsToTransact();

        // Create a testing-aware transaction manager that properly handles afterCommit callbacks
        $this->app->instance(
            'db.transactions',
            $transactionsManager = new DatabaseTransactionsManager($connections)
        );

        foreach ($connections as $name) {
            $connection = $database->connection($name);

            // Set the testing transaction manager on the connection
            $connection->setTransactionManager($transactionsManager);

            if ($this->usingInMemoryDatabase()) {
                RefreshDatabaseState::$inMemoryConnections[$name] ??= $connection->getPdo();
            }

            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();
            $connection->beginTransaction();

            if ($dispatcher) {
                $connection->setEventDispatcher($dispatcher);
            }
        }
    }

    /**
     * Rollback transactions on all connections.
     */
    protected function rollbackDatabaseTransactionWork(): void
    {
        $database = $this->app->get(DatabaseManager::class);

        foreach ($this->connectionsToTransact() as $name) {
            $connection = $database->connection($name);
            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();

            if (! $connection->getPdo()->inTransaction()) {
                RefreshDatabaseState::$migrated = false;
            }

            if ($connection instanceof DatabaseConnection) {
                $connection->forgetRecordModificationState();
            }

            $connection->rollBack();

            if ($dispatcher) {
                $connection->setEventDispatcher($dispatcher);
            }
        }
    }

    /**
     * Run the given callback without firing any model events.
     */
    protected function withoutModelEvents(callable $callback, ?string $connection = null): void
    {
        $connection = $this->app->get(DatabaseManager::class)
            ->connection($connection);
        $dispatcher = $connection->getEventDispatcher();

        $callback();

        $connection->setEventDispatcher($dispatcher);
    }

    /**
     * The database connections that should have transactions.
     */
    protected function connectionsToTransact(): array
    {
        return property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact : [null];
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

    protected function getRefreshConnection(): string
    {
        return $this->app
            ->get('config')
            ->get('database.default');
    }
}
