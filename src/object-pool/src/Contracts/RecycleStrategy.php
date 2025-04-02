<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use Hypervel\ObjectPool\ObjectPool;

interface RecycleStrategy
{
    /**
     * Determine if the pool should recycle objects at this moment.
     */
    public function shouldRecycle(ObjectPool $pool): bool;

    /**
     * Perform the recycling operation on the pool.
     */
    public function recycle(ObjectPool $pool): void;
}
