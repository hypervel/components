<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use DateTime;
use Hyperf\Coordinator\Timer;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\Contracts\Recycler;
use RuntimeException;

class ObjectRecycler implements Recycler
{
    /**
     * Timer instance for scheduling recycle operations.
     */
    protected ?Timer $timer = null;

    /**
     * ID of the current timer for recycling.
     */
    protected ?int $timerId = null;

    /**
     * Creates a new object recycler with the given configuration.
     *
     * @param PoolFactory $manager the pool manager instance to manage object pools
     * @param float $interval the interval between automatic recycle checks in seconds
     */
    public function __construct(
        protected PoolFactory $manager,
        protected float $interval = 10.0,
    ) {
    }

    /**
     * Get the time interval for recycling operations.
     */
    public function getInterval(): float
    {
        return $this->interval;
    }

    /**
     * Set the time interval for recycling operations.
     */
    public function setInterval(float $interval): void
    {
        if ($interval <= 0) {
            throw new RuntimeException('Interval must be greater than 0.');
        }

        $this->interval = $interval;
    }

    /**
     * Get the timer for scheduling recycle operations.
     */
    public function getTimer(): Timer
    {
        if ($this->timer) {
            return $this->timer;
        }

        return $this->timer = new Timer();
    }

    /**
     * Set the timer for scheduling recycle operations.
     */
    public function setTimer(Timer $timer): void
    {
        $this->timer = $timer;
    }

    /**
     * Get the ID of the current timer for recycling.
     */
    public function getTimerId(): ?int
    {
        return $this->timerId;
    }

    /**
     * Start objects recycling with the current timer.
     */
    public function start(): void
    {
        if ($this->timerId) {
            return;
        }

        $this->timerId = $this->getTimer()->tick(
            $this->interval,
            fn () => $this->recycleObjects()
        );
    }

    /**
     * Stops automatic recycling of objects in managed pools.
     */
    public function stop(): void
    {
        if ($this->timerId) {
            $this->getTimer()->clear($this->timerId);
        }

        $this->timerId = null;
    }

    /**
     * Gets the timestamp of the last recycling operation for a specific pool.
     */
    public function getLastRecycledAt(string $name): null|DateTime|int
    {
        return $this->manager->get($name)
            ->getLastRecycledAt();
    }

    /**
     * Recycles objects in all managed pools that need recycling.
     */
    protected function recycleObjects(): void
    {
        foreach ($this->manager->pools() as $pool) {
            $strategy = $pool->getRecycleStrategy();

            if ($strategy->shouldRecycle($pool)) {
                $strategy->recycle($pool);
            }
        }
    }
}
