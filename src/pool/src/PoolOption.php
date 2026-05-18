<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hypervel\Contracts\Pool\PoolOptionInterface;

/**
 * Configuration options for a connection pool.
 */
class PoolOption implements PoolOptionInterface
{
    /**
     * @param int $minConnections Minimum connections to maintain in the pool
     * @param int $maxConnections Maximum connections allowed in the pool
     * @param float $connectTimeout Timeout in seconds for establishing a connection
     * @param float $waitTimeout Timeout in seconds for waiting to get a connection from pool
     * @param float $heartbeat Heartbeat interval in seconds (-1 to disable)
     * @param float $maxIdleTime Maximum idle time in seconds before connection is closed
     * @param array<int, string> $events Events to trigger on connection lifecycle
     */
    public function __construct(
        private int $minConnections = 1,
        private int $maxConnections = 10,
        private float $connectTimeout = 10.0,
        private float $waitTimeout = 3.0,
        private float $heartbeat = -1,
        private float $maxIdleTime = 60.0,
        private array $events = [],
    ) {
    }

    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    /**
     * Set the maximum number of connections in the pool.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setMaxConnections(int $maxConnections): static
    {
        $this->maxConnections = $maxConnections;

        return $this;
    }

    public function getMinConnections(): int
    {
        return $this->minConnections;
    }

    /**
     * Set the minimum number of connections in the pool.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setMinConnections(int $minConnections): static
    {
        $this->minConnections = $minConnections;

        return $this;
    }

    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * Set the timeout for establishing a connection.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setConnectTimeout(float $connectTimeout): static
    {
        $this->connectTimeout = $connectTimeout;

        return $this;
    }

    public function getHeartbeat(): float
    {
        return $this->heartbeat;
    }

    /**
     * Set the heartbeat interval in seconds.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setHeartbeat(float $heartbeat): static
    {
        $this->heartbeat = $heartbeat;

        return $this;
    }

    public function getWaitTimeout(): float
    {
        return $this->waitTimeout;
    }

    /**
     * Set the timeout for waiting to get a connection from the pool.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setWaitTimeout(float $waitTimeout): static
    {
        $this->waitTimeout = $waitTimeout;

        return $this;
    }

    public function getMaxIdleTime(): float
    {
        return $this->maxIdleTime;
    }

    /**
     * Set the maximum idle time before a connection is closed.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setMaxIdleTime(float $maxIdleTime): static
    {
        $this->maxIdleTime = $maxIdleTime;

        return $this;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Set the events to trigger on connection lifecycle.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setEvents(array $events): static
    {
        $this->events = $events;

        return $this;
    }
}
