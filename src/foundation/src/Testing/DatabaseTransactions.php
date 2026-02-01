<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Database\Connection as DatabaseConnection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

trait DatabaseTransactions
{
    /**
     * Handle database transactions on the specified connections.
     *
     * For tests using RunTestsInCoroutine, this method does nothing - the actual
     * transaction work is done in setUpDatabaseTransactionsInCoroutine() to keep
     * all transaction state in the same coroutine.
     *
     * For non-coroutine tests, this starts the transaction immediately and
     * registers a rollback callback.
     */
    public function beginDatabaseTransaction(): void
    {
        // If using RunTestsInCoroutine, defer to coroutine-aware methods
        if (in_array(RunTestsInCoroutine::class, class_uses_recursive(static::class), true)) {
            return;
        }

        // Non-coroutine path: start transaction and register rollback callback
        $this->beginDatabaseTransactionWork();

        $this->beforeApplicationDestroyed(function () {
            $this->rollbackDatabaseTransactionWork();
        });
    }

    /**
     * Start database transaction in the test coroutine.
     *
     * Called by RunTestsInCoroutine before the test runs. Keeps all transaction
     * state in the same coroutine, avoiding Context handoff issues.
     */
    protected function setUpDatabaseTransactionsInCoroutine(): void
    {
        $this->beginDatabaseTransactionWork();
    }

    /**
     * Rollback database transaction in the test coroutine.
     *
     * Called by RunTestsInCoroutine after the test runs.
     */
    protected function tearDownDatabaseTransactionsInCoroutine(): void
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

            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();
            $connection->beginTransaction();

            if ($dispatcher !== null) {
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

            if ($connection instanceof DatabaseConnection) {
                $connection->forgetRecordModificationState();
            }

            $connection->rollBack();

            if ($dispatcher !== null) {
                $connection->setEventDispatcher($dispatcher);
            }
        }
    }

    /**
     * The database connections that should have transactions.
     */
    protected function connectionsToTransact(): array
    {
        return property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact : [null];
    }
}
