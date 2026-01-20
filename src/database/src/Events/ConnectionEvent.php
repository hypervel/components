<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Connection;

abstract class ConnectionEvent
{
    /**
     * The name of the connection.
     */
    public string $connectionName;

    /**
     * The database connection instance.
     */
    public Connection $connection;

    /**
     * Create a new event instance.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->connectionName = $connection->getName();
    }
}
