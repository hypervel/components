<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

interface ClearableQueue
{
    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(string $queue): int;
}
