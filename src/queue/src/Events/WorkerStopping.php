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
     * @param bool $terminatesImmediately whether the process terminates as soon as listeners return; when true, listeners must not start cleanup that must finish after they return
     */
    public function __construct(
        public ?string $connectionName = null,
        public ?string $queue = null,
        public int $status = 0,
        public ?WorkerOptions $workerOptions = null,
        public ?WorkerStopReason $reason = null,
        public ?int $jobsProcessed = null,
        public float|int|null $lastJobProcessedAt = null,
        public float|int|null $memoryUsage = null,
        public bool $terminatesImmediately = false,
    ) {
    }
}
