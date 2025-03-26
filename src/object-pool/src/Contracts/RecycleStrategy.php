<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use Hypervel\ObjectPool\ObjectPool;

interface RecycleStrategy
{
    public function shouldRecycle(ObjectPool $pool): bool;

    public function recycle(ObjectPool $pool): void;

    public function getLastRecycledTimestamp(): int;
}
