<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

interface TimeStrategy extends RecycleStrategy
{
    /**
     * Get the time interval between recycling operations.
     */
    public function getRecycleInterval(): float;
}
