<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool;

use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\Contracts\ConnectionPool\Connection as ConnectionContract;
use Hypervel\Contracts\ConnectionPool\ConnectionPool as ConnectionPoolContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Swoole\Coroutine\CanceledException;
use Throwable;

abstract class Connection implements ConnectionContract
{
    protected float $lastUseTime = 0.0;

    protected float $lastReleaseTime = 0.0;

    protected bool $invalid = false;

    private ?Dispatcher $dispatcher = null;

    private ?StdoutLoggerInterface $logger = null;

    public function __construct(
        protected Container $container,
        protected ConnectionPoolContract $pool
    ) {
        if ($this->container->bound('events')) {
            $this->dispatcher = $this->container->make('events');
        }

        if ($this->container->has(StdoutLoggerInterface::class)) {
            $this->logger = $this->container->make(StdoutLoggerInterface::class);
        }
    }

    /**
     * Release the connection back to the pool.
     */
    public function release(): void
    {
        $failure = null;

        try {
            $this->dispatchReleasingEvent();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->pool->release($this);
        } catch (Throwable $exception) {
            if (! $failure instanceof CanceledException) {
                throw $exception;
            }

            // Preserve the listener or logger cancellation over secondary cleanup failures.
            if (! $exception instanceof CanceledException) {
                try {
                    $this->logger?->error((string) $exception);
                } catch (Throwable) {
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Dispatch the release event and report ordinary listener failures.
     */
    private function dispatchReleasingEvent(): void
    {
        try {
            $this->lastReleaseTime = hrtime(true) / 1e9;
            $events = $this->pool->getOptions()->events;

            if (in_array(ConnectionReleasing::class, $events, true)
                && $this->dispatcher?->hasListeners(ConnectionReleasing::class)
            ) {
                $this->dispatcher->dispatch(new ConnectionReleasing($this));
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger?->error((string) $exception);
        }
    }

    /**
     * Discard the connection from its pool.
     */
    public function discard(): void
    {
        $this->pool->discard($this);
    }

    /**
     * Get the underlying connection.
     */
    public function getConnection(): mixed
    {
        return $this->getActiveConnection();
    }

    /**
     * Check if the connection is still valid based on idle time.
     */
    public function check(): bool
    {
        if ($this->invalid) {
            return false;
        }

        $maxIdleTime = $this->pool->getOptions()->maxIdleTime;
        $now = hrtime(true) / 1e9;

        if ($maxIdleTime !== null && $now > $maxIdleTime + max($this->lastReleaseTime, $this->lastUseTime)) {
            return false;
        }

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
     * Mark the connection as invalid.
     */
    protected function markInvalid(): void
    {
        $this->invalid = true;
    }

    /**
     * Mark the connection as valid.
     */
    protected function markValid(): void
    {
        $this->invalid = false;
    }

    /**
     * Get the active connection, reconnecting if necessary.
     */
    abstract public function getActiveConnection(): mixed;
}
