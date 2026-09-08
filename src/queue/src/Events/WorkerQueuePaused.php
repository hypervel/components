<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class WorkerQueuePaused
{
    /**
     * Create a new event instance.
     *
     * @param string $queue the name of the queue the worker found to be paused
     */
    public function __construct(
        public string $connectionName,
        public string $queue,
    ) {
    }
}
