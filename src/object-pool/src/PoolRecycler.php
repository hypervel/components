<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Coordinator\Timer;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\Recycler;
use InvalidArgumentException;
use Throwable;

class PoolRecycler implements Recycler
{
    protected ?Timer $timer = null;

    protected ?int $timerId = null;

    protected float $interval;

    /**
     * Create a pool recycler.
     */
    public function __construct(
        protected Factory $manager,
        float $interval = 10.0,
    ) {
        $this->setInterval($interval);
    }

    /**
     * Get the maintenance interval in seconds.
     */
    public function getInterval(): float
    {
        return $this->interval;
    }

    /**
     * Set the maintenance interval in seconds.
     *
     * Boot-only. The interval persists on the singleton recycler for the worker
     * lifetime and controls every subsequently scheduled maintenance loop.
     */
    public function setInterval(float $interval): void
    {
        if (! is_finite($interval) || $interval <= 0.0) {
            throw new InvalidArgumentException('The recycler interval must be a finite number greater than 0.');
        }

        $this->interval = $interval;
    }

    /**
     * Get the timer used to schedule maintenance.
     */
    public function getTimer(): Timer
    {
        return $this->timer ??= new Timer;
    }

    /**
     * Set the timer used to schedule maintenance.
     *
     * Boot or tests only. Replacing the timer after start would diverge from
     * the already-scheduled loop retained by the previous timer.
     */
    public function setTimer(Timer $timer): void
    {
        $this->timer = $timer;
    }

    /**
     * Get the active maintenance timer ID.
     */
    public function getTimerId(): ?int
    {
        return $this->timerId;
    }

    /**
     * Start periodic pool maintenance.
     */
    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $this->timerId = $this->getTimer()->tick(
            $this->interval,
            function (): void {
                try {
                    $this->maintainPools();
                } catch (Throwable $exception) {
                    PoolErrorReporter::report($exception);
                }
            },
        );
    }

    /**
     * Stop periodic pool maintenance.
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            $this->getTimer()->clear($this->timerId);
        }

        $this->timerId = null;
    }

    /**
     * Evict idle pools and maintain live pools.
     */
    protected function maintainPools(): void
    {
        foreach ($this->manager->pools() as $identity => $pool) {
            if ($pool->isIdle()) {
                $this->manager->remove($identity, $pool);

                continue;
            }

            $pool->sweepExpired();
            $pool->trimIdle();
        }
    }
}
