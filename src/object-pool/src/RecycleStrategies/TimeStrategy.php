<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\RecycleStrategies;

use Hypervel\ObjectPool\Contracts\TimeRecycleStrategy;
use Hypervel\ObjectPool\ObjectPool;

class TimeStrategy implements TimeRecycleStrategy
{
    public function __construct(
        protected float $recycleTime = 10.0,
        protected float $recycleRatio = 0.2,
        protected int $lastRecycledTimestamp = 0,
    ) {
    }

    public function shouldRecycle(ObjectPool $pool): bool
    {
        return ($this->lastRecycledTimestamp + $this->getRecycleTime()) < time();
    }

    public function recycle(ObjectPool $pool): void
    {
        $recycleCount = floor($this->recycleRatio * $pool->getObjectNumberInPool());
        for ($i = 0; $i <= $recycleCount; ++$i) {
            $pool->flushOne();
        }
        $this->lastRecycledTimestamp = time();
    }

    public function getLastRecycledTimestamp(): int
    {
        return $this->lastRecycledTimestamp;
    }

    public function getRecycleTime(): float
    {
        return $this->recycleTime;
    }

    public function setRecycleTime(float $recycleTime): static
    {
        $this->recycleTime = $recycleTime;

        return $this;
    }

    public function getRecycleRatio(): float
    {
        return $this->recycleRatio;
    }

    public function setRecycleRatio(float $recycleRatio): static
    {
        $this->recycleRatio = $recycleRatio;

        return $this;
    }
}
