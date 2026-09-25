<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Queue\WorkerOptions;

class WorkerInterrupted
{
    /**
     * Create a new event instance.
     *
     * @param int $signal the signal that interrupted the worker
     */
    public function __construct(
        public int $signal,
        public ?string $connectionName = null,
        public ?string $queue = null,
        public ?WorkerOptions $workerOptions = null,
    ) {
    }
}
