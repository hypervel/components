<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

use UnitEnum;

interface IndexAwareQueue
{
    /**
     * Pop the next job using its position in the worker's queue priority list.
     */
    public function pop(UnitEnum|string|null $queue = null, int $index = 0): ?Job;
}
