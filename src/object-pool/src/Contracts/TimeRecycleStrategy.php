<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

interface TimeRecycleStrategy extends RecycleStrategy
{
    /**
     * Gets the time interval between recycling operations.
     */
    public function getRecycleTime(): float;
}
