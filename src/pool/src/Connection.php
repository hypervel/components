<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Events\ReleaseConnection;
use Swoole\Coroutine\CanceledException;
use Throwable;

abstract class Connection implements ConnectionInterface
{
    protected float $lastUseTime = 0.0;

    protected float $lastReleaseTime = 0.0;

    protected bool $invalid = false;

    private ?Dispatcher $dispatcher = null;

    private ?StdoutLoggerInterface $logger = null;

    public function __construct(
        protected Container $container,
        protected PoolInterface $pool
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
        $cancellation = null;

        try {
            try {
                $this->lastReleaseTime = hrtime(true) / 1e9;
                $events = $this->pool->getOption()->getEvents();

                if (in_array(ReleaseConnection::class, $events, true)
                    && $this->dispatcher?->hasListeners(ReleaseConnection::class)
                ) {
                    $this->dispatcher->dispatch(new ReleaseConnection($this));
                }
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            } catch (Throwable $exception) {
                $this->logger?->error((string) $exception);
            }
        } catch (CanceledException $exception) {
            // Logging an ordinary listener failure may itself be canceled.
            $cancellation = $exception;
        } finally {
            if ($cancellation === null) {
                $this->pool->release($this);
            } else {
                try {
                    $this->pool->release($this);
                } catch (CanceledException) {
                    // The listener or logger cancellation remains primary.
                } catch (Throwable $exception) {
                    try {
                        $this->logger?->error((string) $exception);
                    } catch (Throwable) {
                    }
                }
            }
        }

        if ($cancellation !== null) {
            throw $cancellation;
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
     * Get the underlying connection, with retry on failure.
     */
    public function getConnection(): mixed
    {
        try {
            return $this->getActiveConnection();
        } catch (CanceledException $exception) {
            throw $exception;
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
        if ($this->invalid) {
            return false;
        }

        $maxIdleTime = $this->pool->getOption()->getMaxIdleTime();
        $now = hrtime(true) / 1e9;

        if ($now > $maxIdleTime + max($this->lastReleaseTime, $this->lastUseTime)) {
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
