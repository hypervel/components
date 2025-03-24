<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\RecycleStrategies;

use Hypervel\ObjectPool\Contracts\TimeRecycleStrategyContract;
use Hypervel\ObjectPool\ObjectPool;

class TimeRecycleStrategy implements TimeRecycleStrategyContract
{
    public function __construct(
        protected float $recycleTime = 10.0,
        protected float $recycleRatio = 0.2
    ) {
    }

    public function shouldRecycle(ObjectPool $pool, array $context = []): bool
    {
        $lastRecycledTimestamp = $context['last_recycled_timestamp'] ?? 0;

        return ($lastRecycledTimestamp + $this->getRecycleTime()) < time();
    }

    public function recycle(ObjectPool $pool): void
    {
        $recycleCount = floor($this->recycleRatio * $pool->getObjectNumberInPool());
        for ($i = 0; $i <= $recycleCount; ++$i) {
            $pool->flushOne();
        }
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
