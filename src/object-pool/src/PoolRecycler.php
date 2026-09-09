<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\Contracts\ObjectPool\Recycler;
use Hypervel\Coordinator\Timer;
use InvalidArgumentException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class PoolRecycler implements Recycler
{
    protected Timer $timer;

    protected ?int $timerId = null;

    /**
     * Create a pool recycler.
     */
    public function __construct(
        protected Factory $manager,
        protected float $interval = 10.0,
        ?Timer $timer = null,
    ) {
        if (! is_finite($interval) || $interval <= 0.0) {
            throw new InvalidArgumentException('The recycler interval must be a finite number greater than 0.');
        }

        $this->timer = $timer ?? new Timer;
    }

    /**
     * Get the maintenance interval in seconds.
     */
    public function getInterval(): float
    {
        return $this->interval;
    }

    /**
     * Start periodic pool maintenance.
     *
     * Boot-only. Starting during a request schedules worker-wide maintenance
     * that affects every subsequently registered pool.
     */
    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $this->timerId = $this->timer->tick(
            $this->interval,
            function (): void {
                try {
                    $this->maintainPools();
                } catch (CanceledException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    PoolErrorReporter::report($exception);
                }
            },
        );
    }

    /**
     * Stop periodic pool maintenance.
     *
     * Boot or tests only. Stopping disables automatic maintenance for every
     * pool in the worker.
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            $this->timer->clear($this->timerId);
        }

        $this->timerId = null;
    }

    /**
     * Evict idle pools and maintain live pools.
     */
    protected function maintainPools(): void
    {
        foreach ($this->manager->getPools() as $identity => $pool) {
            // A throwing public-contract pool must not starve unrelated pools of maintenance.
            try {
                if ($pool->isIdleExpired()) {
                    $this->manager->purge($identity, $pool);

                    continue;
                }

                $pool->sweepExpired();
                $pool->trimIdle();
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                PoolErrorReporter::report(new RuntimeException(
                    "Pool maintenance failed for [{$identity}].",
                    previous: $exception,
                ));
            }
        }
    }
}
