<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Queue\ClearableQueue;

class ClearableQueuePoolProxy extends QueuePoolProxy implements ClearableQueue
{
    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(?string $queue): int
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }
}
