<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Event\ReleaseConnection;
use Throwable;

/**
 * Abstract base class for pooled connections.
 *
 * Provides common functionality for connection lifecycle management
 * including release handling, health checking, and usage tracking.
 */
abstract class Connection implements ConnectionInterface
{
    protected float $lastUseTime = 0.0;

    protected float $lastReleaseTime = 0.0;

    private ?Dispatcher $dispatcher = null;

    private ?StdoutLoggerInterface $logger = null;

    public function __construct(
        protected Container $container,
        protected PoolInterface $pool
    ) {
        if ($this->container->has(Dispatcher::class)) {
            $this->dispatcher = $this->container->get(Dispatcher::class);
        }

        if ($this->container->has(StdoutLoggerInterface::class)) {
            $this->logger = $this->container->get(StdoutLoggerInterface::class);
        }
    }

    /**
     * Release the connection back to the pool.
     */
    public function release(): void
    {
        try {
            $this->lastReleaseTime = microtime(true);
            $events = $this->pool->getOption()->getEvents();

            if (in_array(ReleaseConnection::class, $events, true)) {
                $this->dispatcher?->dispatch(new ReleaseConnection($this));
            }
        } catch (Throwable $exception) {
            $this->logger?->error((string) $exception);
        } finally {
            $this->pool->release($this);
        }
    }

    /**
     * Get the underlying connection, with retry on failure.
     */
    public function getConnection(): mixed
    {
        try {
            return $this->getActiveConnection();
        } catch (Throwable $exception) {
            $this->logger?->warning('Get connection failed, try again. ' . $exception);

            return $this->getActiveConnection();
        }
    }

    /**
     * Check if the connection is still valid based on idle time.
     */
    public function check(): bool
    {
        $maxIdleTime = $this->pool->getOption()->getMaxIdleTime();
        $now = microtime(true);

        if ($now > $maxIdleTime + $this->lastUseTime) {
            return false;
        }

        $this->lastUseTime = $now;

        return true;
    }

    /**
     * Get the last use time.
     */
    public function getLastUseTime(): float
    {
        return $this->lastUseTime;
    }

    /**
     * Get the last release time.
     */
    public function getLastReleaseTime(): float
    {
        return $this->lastReleaseTime;
    }

    /**
     * Get the active connection, reconnecting if necessary.
     */
    abstract public function getActiveConnection(): mixed;
}
