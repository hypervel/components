<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

class WorkerQueueResumed
{
    /**
     * Create a new event instance.
     *
     * @param string $queue the name of the queue the worker found to be resumed
     */
    public function __construct(
        public string $connectionName,
        public string $queue,
    ) {
    }
}
