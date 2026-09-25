<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Queue\ClearableQueue;
use UnitEnum;

class ClearableQueuePoolProxy extends QueuePoolProxy implements ClearableQueue
{
    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(UnitEnum|string|null $queue = null): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }
}
