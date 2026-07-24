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
     * Get the managed-connection floor for excess-idle trimming.
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
     * Get the heartbeat timeout in seconds.
     */
    public function getHeartbeatTimeout(): float;

    /**
     * Get the maximum idle time in seconds before a connection is closed.
     */
    public function getMaxIdleTime(): float;

    /**
     * Get the maximum lifetime in seconds before a connection is recycled.
     */
    public function getMaxLifetime(): float;

    /**
     * Get the events to trigger on connection lifecycle.
     */
    public function getEvents(): array;
}
