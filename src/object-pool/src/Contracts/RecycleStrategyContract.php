<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use Hypervel\ObjectPool\ObjectPool;

interface RecycleStrategyContract
{
    public function shouldRecycle(ObjectPool $pool, array $context = []): bool;

    public function recycle(ObjectPool $pool): void;
}