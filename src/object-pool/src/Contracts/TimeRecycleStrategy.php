<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

interface TimeRecycleStrategy extends RecycleStrategy
{
    public function getRecycleTime(): float;
}
