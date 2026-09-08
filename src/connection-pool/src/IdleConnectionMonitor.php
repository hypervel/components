<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool;

use Hypervel\Coordinator\Timer;
use InvalidArgumentException;
use Throwable;

/**
 * Check one idle connection at each interval, independently of borrowing activity.
 */
class IdleConnectionMonitor
{
    protected Timer $timer;

    protected ?int $timerId = null;

    protected bool $started = false;

    /**
     * Create a monitor with its own timer.
     */
    public function __construct(
        protected ConnectionPool $pool,
        protected float $interval,
        ?Timer $timer = null,
    ) {
        if (! is_finite($interval) || $interval <= 0.0) {
            throw new InvalidArgumentException('The idle check interval must be a finite positive number.');
        }

        $this->timer = $timer ?? new Timer;
    }

    /**
     * Start checking idle connections until stopped or the worker exits.
     */
    public function start(): void
    {
        if ($this->started || $this->pool->isClosed()) {
            return;
        }

        // Timer creation invokes synchronous coroutine hooks before returning its ID.
        $this->started = true;

        try {
            $timerId = $this->timer->tick($this->interval, function (bool $isClosing): void {
                if ($isClosing || $this->pool->isClosed()) {
                    $this->stop();

                    return;
                }

                $this->pool->checkIdleConnection();
            });
        } catch (Throwable $exception) {
            $this->started = false;

            throw $exception;
        }

        if (! $this->started || $this->pool->isClosed()) {
            $this->started = false;
            $this->timer->clear($timerId);

            return;
        }

        $this->timerId = $timerId;
    }

    /**
     * Stop checking idle connections.
     */
    public function stop(): void
    {
        $timerId = $this->timerId;
        $this->timerId = null;
        $this->started = false;

        if ($timerId !== null) {
            $this->timer->clear($timerId);
        }
    }
}
