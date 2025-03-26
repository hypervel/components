<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\RecycleStrategies;

use Hypervel\ObjectPool\Contracts\TimeRecycleStrategy;
use Hypervel\ObjectPool\ObjectPool;

class TimeStrategy implements TimeRecycleStrategy
{
    /**
     * @param float $recycleTime Time interval between recycling operations in seconds
     * @param float $recycleRatio Percentage of objects to recycle each time
     * @param int $lastRecycledTimestamp Timestamp of the last recycling operation
     */
    public function __construct(
        protected float $recycleTime = 10.0,
        protected float $recycleRatio = 0.2,
        protected int $lastRecycledTimestamp = 0,
    ) {
    }

    /**
     * Determines if enough time has passed to trigger recycling.
     */
    public function shouldRecycle(ObjectPool $pool): bool
    {
        return ($this->lastRecycledTimestamp + $this->getRecycleTime()) < time();
    }

    /**
     * Recycles a portion of objects in the pool based on the recycle ratio.
     */
    public function recycle(ObjectPool $pool): void
    {
        $recycleCount = floor($this->recycleRatio * $pool->getObjectNumberInPool());
        for ($i = 0; $i <= $recycleCount; ++$i) {
            $pool->flushOne();
        }
        $this->lastRecycledTimestamp = time();
    }

    /**
     * Gets the timestamp of the last recycling operation.
     */
    public function getLastRecycledTimestamp(): int
    {
        return $this->lastRecycledTimestamp;
    }

    /**
     * Gets the time interval between recycling operations.
     */
    public function getRecycleTime(): float
    {
        return $this->recycleTime;
    }

    /**
     * Sets the time interval between recycling operations.
     */
    public function setRecycleTime(float $recycleTime): static
    {
        $this->recycleTime = $recycleTime;

        return $this;
    }

    /**
     * Gets the percentage of objects to recycle each time.
     */
    public function getRecycleRatio(): float
    {
        return $this->recycleRatio;
    }

    /**
     * Sets the percentage of objects to recycle each time.
     */
    public function setRecycleRatio(float $recycleRatio): static
    {
        $this->recycleRatio = $recycleRatio;

        return $this;
    }
}
