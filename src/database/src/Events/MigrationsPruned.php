<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Connection;

class MigrationsPruned
{
    /**
     * The database connection instance.
     */
    public Connection $connection;

    /**
     * The database connection name.
     */
    public ?string $connectionName;

    /**
     * The path to the directory where migrations were pruned.
     */
    public string $path;

    /**
     * Create a new event instance.
     */
    public function __construct(Connection $connection, string $path)
    {
        $this->connection = $connection;
        $this->connectionName = $connection->getName();
        $this->path = $path;
    }
}
