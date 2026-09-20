<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

use UnitEnum;

interface ClearableQueue
{
    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(UnitEnum|string|null $queue): int;
}
