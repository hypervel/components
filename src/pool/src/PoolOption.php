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
     * Lowest max_lifetime fraction assigned to a connection generation.
     */
    public const MIN_LIFETIME_JITTER_BASIS = 9000;

    /**
     * Scale used for jitter basis values.
     */
    public const LIFETIME_JITTER_SCALE = 10000;

    /**
     * @param int $minConnections Minimum connections to maintain in the pool
     * @param int $maxConnections Maximum connections allowed in the pool
     * @param float $connectTimeout Timeout in seconds for establishing a connection
     * @param float $waitTimeout Timeout in seconds for waiting to get a connection from pool
     * @param float $heartbeat Heartbeat interval in seconds (-1 to disable)
     * @param float $heartbeatTimeout Heartbeat timeout in seconds
     * @param float $maxIdleTime Maximum idle time in seconds before connection is closed
     * @param float $maxLifetime Maximum lifetime in seconds before connection is recycled (-1 to disable)
     * @param array<int, string> $events Events to trigger on connection lifecycle
     */
    public function __construct(
        private int $minConnections = 1,
        private int $maxConnections = 10,
        private float $connectTimeout = 10.0,
        private float $waitTimeout = 3.0,
        private float $heartbeat = -1,
        private float $heartbeatTimeout = 1.0,
        private float $maxIdleTime = 60.0,
        private float $maxLifetime = -1.0,
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

    public function getHeartbeatTimeout(): float
    {
        return $this->heartbeatTimeout;
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

    /**
     * Set the heartbeat timeout in seconds.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setHeartbeatTimeout(float $heartbeatTimeout): static
    {
        $this->heartbeatTimeout = $heartbeatTimeout;

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

    /**
     * Get the maximum lifetime in seconds before a connection is recycled.
     */
    public function getMaxLifetime(): float
    {
        return $this->maxLifetime;
    }

    /**
     * Return a jittered lifetime deadline for a connection generation.
     */
    public static function jitteredLifetimeDeadline(float $createdAt, float $maxLifetime): float
    {
        if ($maxLifetime <= 0) {
            return 0.0;
        }

        $factor = random_int(self::MIN_LIFETIME_JITTER_BASIS, self::LIFETIME_JITTER_SCALE) / self::LIFETIME_JITTER_SCALE;

        return $createdAt + ($maxLifetime * $factor);
    }

    /**
     * Set the maximum lifetime in seconds before a connection is recycled.
     *
     * Boot-only. The value persists on the worker-lifetime pool option and is
     * read by every subsequent pool operation. Per-request use races across
     * coroutines.
     */
    public function setMaxLifetime(float $maxLifetime): static
    {
        $this->maxLifetime = $maxLifetime;

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
