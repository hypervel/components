<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

interface TimeRecycleStrategyContract extends RecycleStrategyContract
{
    public function getRecycleTime(): float;
}
