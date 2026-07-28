<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Queue\WorkerOptions;
use Hypervel\Queue\WorkerStopReason;

class WorkerStopping
{
    /**
     * Create a new event instance.
     *
     * @param null|float|int $memoryUsage the memory usage of the worker in megabytes
     */
    public function __construct(
        public int $status = 0,
        public ?WorkerOptions $workerOptions = null,
        public ?WorkerStopReason $reason = null,
        public ?int $jobsProcessed = null,
        public float|int|null $lastJobProcessedAt = null,
        public float|int|null $memoryUsage = null,
    ) {
    }
}
