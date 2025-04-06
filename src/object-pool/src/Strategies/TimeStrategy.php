<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Strategies;

use Carbon\Carbon;
use DateTime;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Hypervel\ObjectPool\Contracts\Recycler;
use Hypervel\ObjectPool\Contracts\TimeStrategy as TimeStrategyContract;
use Psr\Container\ContainerInterface;

class TimeStrategy implements TimeStrategyContract
{
    /**
     * The time interval between recycling operations.
     */
    protected float $recycleInterval;

    /**
     * @param ContainerInterface $container The container instance
     */
    public function __construct(
        protected ContainerInterface $container
    ) {
        $this->recycleInterval = $container->get(Recycler::class)->getInterval();
    }

    /**
     * Determine if enough time has passed to trigger recycling.
     */
    public function shouldRecycle(ObjectPool $pool): bool
    {
        $lastRecycledAt = $pool->getLastRecycledAt();
        $timestamp = $lastRecycledAt instanceof DateTime
            ? $lastRecycledAt->getTimestamp()
            : ($lastRecycledAt ?? 0);

        return ($timestamp + $this->recycleInterval) < Carbon::now()->timestamp;
    }

    /**
     * Recycle a portion of objects in the pool based on the recycle ratio.
     */
    public function recycle(ObjectPool $pool): void
    {
        $recycleCount = floor($pool->getOption()->getRecycleRatio() * $pool->getObjectNumberInPool());
        for ($i = 0; $i < $recycleCount; ++$i) {
            $pool->flushOne();
        }

        $pool->setLastRecycledAt(Carbon::now());
    }

    /**
     * Get the time interval between recycling operations.
     */
    public function getRecycleInterval(): float
    {
        return $this->recycleInterval;
    }
}
