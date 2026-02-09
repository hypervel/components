<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Pool;

interface PoolOptionInterface
{
    /**
     * Get the maximum number of connections in the pool.
     */
    public function getMaxConnections(): int;

    /**
     * Get the minimum number of connections in the pool.
     */
    public function getMinConnections(): int;

    /**
     * Get the connection timeout in seconds.
     */
    public function getConnectTimeout(): float;

    /**
     * Get the wait timeout in seconds for acquiring a connection.
     */
    public function getWaitTimeout(): float;

    /**
     * Get the heartbeat interval in seconds.
     */
    public function getHeartbeat(): float;

    /**
     * Get the maximum idle time in seconds before a connection is closed.
     */
    public function getMaxIdleTime(): float;

    /**
     * Get the events to trigger on connection lifecycle.
     */
    public function getEvents(): array;
}
