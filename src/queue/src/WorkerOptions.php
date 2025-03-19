<?php

declare(strict_types=1);

namespace Hypervel\Queue;

class WorkerOptions
{
    /**
     * Create a new worker options instance.
     *
     * @param string $name the name of the worker
     * @param int|int[] $backoff
     * @param int $memory the number of seconds to wait before retrying a job that encountered an uncaught exception
     * @param int $timeout the maximum amount of RAM the worker may consume
     * @param int $sleep the number of seconds to wait in between polling the queue
     * @param int $maxTries the number of seconds to rest between jobs
     * @param bool $force indicates if the worker should run in maintenance mode
     * @param bool $stopWhenEmpty indicates if the worker should stop when the queue is empty
     * @param int $maxJobs the maximum number of jobs to run
     * @param int $maxTime the maximum number of seconds a worker may live
     * @param int $rest the number of seconds to rest between jobs
     * @param int $concurrency the number of jobs to process at once
     */
    public function __construct(
        public string $name = 'default',
        public array|int $backoff = 0,
        public int $memory = 128,
        public int $timeout = 60,
        public int $sleep = 3,
        public int $maxTries = 1,
        public bool $force = false,
        public bool $stopWhenEmpty = false,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public int $rest = 0,
        public int $concurrency = 1,
        public int $monitorInterval = 1,
    ) {
    }
}
