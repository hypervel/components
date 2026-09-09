<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ConnectionPool;

interface Connection
{
    /**
     * Return the underlying connection.
     */
    public function getConnection(): mixed;

    /**
     * Reconnect the connection.
     *
     * Return true if the connection is available for use when reconnection completes.
     */
    public function reconnect(): bool;

    /**
     * Determine if the connection is valid.
     */
    public function check(): bool;

    /**
     * Close the connection.
     */
    public function close(): bool;

    /**
     * Release the connection to its pool.
     */
    public function release(): void;

    /**
     * Discard the connection from its pool.
     */
    public function discard(): void;
}
