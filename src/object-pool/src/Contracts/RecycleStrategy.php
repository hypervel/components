<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use Hypervel\ObjectPool\ObjectPool;

interface RecycleStrategy
{
    /**
     * Determines if the pool should recycle objects at this time.
     */
    public function shouldRecycle(ObjectPool $pool): bool;

    /**
     * Performs the recycling operation on the pool.
     */
    public function recycle(ObjectPool $pool): void;

    /**
     * Returns the timestamp of the last recycling operation.
     */
    public function getLastRecycledTimestamp(): int;
}
