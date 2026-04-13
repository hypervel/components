<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Support\Arr;

abstract class DatabaseInspectionCommand extends Command
{
    /**
     * Get a human-readable name for the given connection.
     *
     * @deprecated
     */
    protected function getConnectionName(ConnectionInterface $connection, string $database): string
    {
        return $connection->getDriverTitle();
    }

    /**
     * Get the number of open connections for a database.
     *
     * @deprecated
     */
    protected function getConnectionCount(ConnectionInterface $connection): ?int
    {
        return $connection->threadCount();
    }

    /**
     * Get the connection configuration details for the given connection.
     */
    protected function getConfigFromDatabase(?string $database): array
    {
        $database ??= config('database.default');

        return Arr::except(config('database.connections.' . $database), ['password']);
    }
}
