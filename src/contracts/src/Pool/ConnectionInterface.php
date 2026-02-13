<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Pool;

interface ConnectionInterface
{
    /**
     * Get the real connection from pool.
     */
    public function getConnection(): mixed;

    /**
     * Reconnect the connection.
     */
    public function reconnect(): bool;

    /**
     * Check the connection is valid.
     */
    public function check(): bool;

    /**
     * Close the connection.
     */
    public function close(): bool;

    /**
     * Release the connection to pool.
     */
    public function release(): void;
}
