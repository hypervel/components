<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hyperf\Database\Connection as DatabaseConnection;
use Hyperf\DbConnection\Db;

trait DatabaseTransactions
{
    /**
     * Handle database transactions on the specified connections.
     */
    public function beginDatabaseTransaction(): void
    {
        $database = $this->app->get(Db::class);

        foreach ($this->connectionsToTransact() as $name) {
            $connection = $database->connection($name);
            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();
            $connection->beginTransaction();
            $connection->setEventDispatcher($dispatcher);
        }

        $this->beforeApplicationDestroyed(function () use ($database) {
            foreach ($this->connectionsToTransact() as $name) {
                $connection = $database->connection($name);
                $dispatcher = $connection->getEventDispatcher();

                $connection->unsetEventDispatcher();

                if ($connection instanceof DatabaseConnection) {
                    $connection->resetRecordsModified();
                }

                $connection->rollBack();
                $connection->setEventDispatcher($dispatcher);
                // this will trigger a database refresh warning
                // $connection->disconnect();
            }
        });
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
