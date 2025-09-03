<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class SupervisorOptions
{
    /**
     * The queue that should be utilized.
     */
    public ?string $queue = null;

    /**
     * Indicates if the supervisor should auto-scale.
     */
    public bool $autoScale;

    /**
     * The working directories that new workers should be started from.
     */
    public ?string $directory = null;

    /**
     * The number of seconds to wait before retrying a job that encountered an uncaught exception.
     */
    public int $retryAfter;

    /**
     * Create a new worker options instance.
     *
     * @param string $name the name of the supervisor
     * @param string $connection the queue connection that should be utilized
     * @param string $workersName the name of the workers
     * @param bool|string $balance indicates the balancing strategy the supervisor should use
     * @param int|string $backoff the number of seconds to wait before retrying a job that encountered an uncaught exception
     * @param int $maxTime the maximum number of seconds a worker may live
     * @param int $maxJobs the maximum number of jobs to run
     * @param int $maxProcesses the maximum number of total processes to start when auto-scaling
     * @param int $minProcesses the minimum number of processes to assign per working when auto-scaling
     * @param int $memory the maximum amount of RAM the worker may consume
     * @param int $timeout the maximum number of seconds a child worker may run
     * @param int $sleep the number of seconds to wait in between polling the queue
     * @param int $maxTries the maximum amount of times a job may be attempted
     * @param bool $force indicates if the worker should run in maintenance mode
     * @param int $nice the process priority
     * @param int $balanceCooldown the number of seconds to wait in between auto-scaling attempts
     * @param int $balanceMaxShift the maximum number of processes to increase or decrease per one scaling
     * @param int $parentId the parent process identifier
     * @param int $rest the number of seconds to rest between jobs
     * @param ?string $autoScalingStrategy indicates whether auto-scaling strategy should use "time" (time-to-complete) or "size" (total count of jobs) strategies
     */
    public function __construct(
        public string $name,
        public string $connection,
        ?string $queue = null,
        public string $workersName = 'default',
        public bool|string $balance = 'off',
        public int|string $backoff = 0,
        public int $maxTime = 0,
        public int $maxJobs = 0,
        public int $maxProcesses = 1,
        public int $minProcesses = 1,
        public int $memory = 128,
        public int $timeout = 60,
        public int $sleep = 3,
        public int $maxTries = 0,
        public bool $force = false,
        public int $nice = 0,
        public int $balanceCooldown = 3,
        public int $balanceMaxShift = 1,
        public int $parentId = 0,
        public int $rest = 0,
        public ?string $autoScalingStrategy = 'time'
    ) {
        $this->queue = $queue ?: config('queue.connections.' . $connection . '.queue');
    }

    /**
     * Create a fresh options instance with the given queue.
     */
    public function withQueue(string $queue): static
    {
        return tap(clone $this, function ($options) use ($queue) {
            $options->queue = $queue;
        });
    }

    /**
     * Determine if a balancing strategy should be used.
     */
    public function balancing(): bool
    {
        return in_array($this->balance, ['simple', 'auto']);
    }

    /**
     * Determine if auto-scaling should be applied.
     */
    public function autoScaling(): bool
    {
        return $this->balance !== 'simple';
    }

    /**
     * Determine if auto-scaling should be based on the number of jobs on the queue instead of time-to-clear.
     */
    public function autoScaleByNumberOfJobs(): bool
    {
        return $this->autoScalingStrategy === 'size';
    }

    /**
     * Get the command-line representation of the options for a supervisor.
     */
    public function toSupervisorCommand(): string
    {
        return SupervisorCommandString::fromOptions($this);
    }

    /**
     * Get the command-line representation of the options for a worker.
     */
    public function toWorkerCommand(): string
    {
        return WorkerCommandString::fromOptions($this);
    }

    /**
     * Convert the options to a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Convert the options to a raw array.
     */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'backoff' => $this->backoff,
            'force' => $this->force,
            'maxProcesses' => $this->maxProcesses,
            'minProcesses' => $this->minProcesses,
            'maxTries' => $this->maxTries,
            'maxTime' => $this->maxTime,
            'maxJobs' => $this->maxJobs,
            'memory' => $this->memory,
            'nice' => $this->nice,
            'name' => $this->name,
            'workersName' => $this->workersName,
            'sleep' => $this->sleep,
            'timeout' => $this->timeout,
            'balanceCooldown' => $this->balanceCooldown,
            'balanceMaxShift' => $this->balanceMaxShift,
            'parentId' => $this->parentId,
            'rest' => $this->rest,
            'autoScalingStrategy' => $this->autoScalingStrategy,
        ];
    }

    /**
     * Create a new options instance from the given array.
     */
    public static function fromArray(array $array): static
    {
        return tap(new static($array['name'], $array['connection']), function ($options) use ($array) {
            foreach ($array as $key => $value) {
                $options->{$key} = $value;
            }
        });
    }
}
