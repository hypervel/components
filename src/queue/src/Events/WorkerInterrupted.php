<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Queue\WorkerOptions;

class WorkerInterrupted
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $signal,
        public string $connectionName,
        public string $queue,
        public WorkerOptions $workerOptions,
    ) {
    }
}
