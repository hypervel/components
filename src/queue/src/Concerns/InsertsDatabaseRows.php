<?php

declare(strict_types=1);

namespace Hypervel\Queue\Concerns;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\PdoConnection;
use RuntimeException;

trait InsertsDatabaseRows
{
    /**
     * Insert database rows without exceeding the connection's binding limit.
     *
     * @param non-empty-array<array<string, mixed>> $rows
     */
    protected function insertDatabaseRows(
        ConnectionInterface $connection,
        string $table,
        array $rows,
    ): void {
        $rowsPerInsert = intdiv($this->maxBindings($connection), count(reset($rows)));

        if (count($rows) <= $rowsPerInsert) {
            $this->insertDatabaseRowChunk($connection, $table, $rows);

            return;
        }

        $connection->transaction(function () use ($connection, $table, $rows, $rowsPerInsert): void {
            foreach (array_chunk($rows, $rowsPerInsert) as $chunk) {
                $this->insertDatabaseRowChunk($connection, $table, $chunk);
            }
        });
    }

    /**
     * Insert one database row chunk.
     *
     * @param non-empty-array<array<string, mixed>> $rows
     */
    protected function insertDatabaseRowChunk(ConnectionInterface $connection, string $table, array $rows): void
    {
        if (! $connection->table($table)->insert($rows)) {
            throw new RuntimeException('Unable to insert queued jobs into the database.');
        }
    }

    /**
     * Get the statement binding limit without requiring a PDO-backed custom driver.
     */
    protected function maxBindings(ConnectionInterface $connection): int
    {
        return $connection instanceof PdoConnection
            ? $connection->maxBindings()
            : PdoConnection::DEFAULT_MAX_BINDINGS;
    }
}
