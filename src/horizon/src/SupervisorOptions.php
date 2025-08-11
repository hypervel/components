<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class SupervisorOptions
{
    /**
     * The name of the supervisor.
     */
    public string $name;

    /**
     * The name of the workers.
     */
    public string $workersName;

    /**
     * The queue connection that should be utilized.
     */
    public string $connection;

    /**
     * The queue that should be utilized.
     */
    public ?string $queue = null;

    /**
     * Indicates the balancing strategy the supervisor should use.
     */
    public string $balance = 'off';

    /**
     * Indicates whether auto-scaling strategy should use "time" (time-to-complete) or "size" (total count of jobs) strategies.
     */
    public ?string $autoScalingStrategy = null;

    /**
     * Indicates if the supervisor should auto-scale.
     */
    public bool $autoScale;

    /**
     * The maximum number of total processes to start when auto-scaling.
     */
    public int $maxProcesses = 1;

    /**
     * The minimum number of processes to assign per working when auto-scaling.
     */
    public int $minProcesses = 1;

    /**
     * The parent process identifier.
     */
    public int $parentId = 0;

    /**
     * The process priority.
     */
    public int $nice = 0;

    /**
     * The working directories that new workers should be started from.
     */
    public ?string $directory = null;

    /**
     * The number of seconds to wait in between auto-scaling attempts.
     */
    public int $balanceCooldown = 3;

    /**
     * The maximum number of processes to increase or decrease per one scaling.
     */
    public int $balanceMaxShift = 1;

    /**
     * The number of seconds to wait before retrying a job that encountered an uncaught exception.
     */
    public int $backoff;

    /**
     * The maximum number of jobs to run.
     */
    public int $maxJobs;

    /**
     * The maximum number of seconds a worker may live.
     */
    public int $maxTime;

    /**
     * The maximum amount of RAM the worker may consume.
     */
    public int $memory;

    /**
     * The maximum number of seconds a child worker may run.
     */
    public int $timeout;

    /**
     * The number of seconds to wait in between polling the queue.
     */
    public int $sleep;

    /**
     * The maximum amount of times a job may be attempted.
     */
    public int $maxTries;

    /**
     * Indicates if the worker should run in maintenance mode.
     */
    public bool $force;

    /**
     * The number of seconds to rest between jobs.
     */
    public int $rest;

    /**
     * The number of seconds to wait before retrying a job that encountered an uncaught exception.
     */
    public int $retryAfter;

    /**
     * Create a new worker options instance.
     */
    public function __construct(
        string $name,
        string $connection,
        ?string $queue = null,
        string $workersName = 'default',
        string $balance = 'off',
        int $backoff = 0,
        int $maxTime = 0,
        int $maxJobs = 0,
        int $maxProcesses = 1,
        int $minProcesses = 1,
        int $memory = 128,
        int $timeout = 60,
        int $sleep = 3,
        int $maxTries = 0,
        bool $force = false,
        int $nice = 0,
        int $balanceCooldown = 3,
        int $balanceMaxShift = 1,
        int $parentId = 0,
        int $rest = 0,
        ?string $autoScalingStrategy = 'time'
    ) {
        $this->name = $name;
        $this->connection = $connection;
        $this->queue = $queue ?: config('queue.connections.' . $connection . '.queue');
        $this->workersName = $workersName;
        $this->balance = $balance;
        $this->backoff = $backoff;
        $this->maxTime = $maxTime;
        $this->maxJobs = $maxJobs;
        $this->maxProcesses = $maxProcesses;
        $this->minProcesses = $minProcesses;
        $this->memory = $memory;
        $this->timeout = $timeout;
        $this->sleep = $sleep;
        $this->maxTries = $maxTries;
        $this->force = $force;
        $this->nice = $nice;
        $this->balanceCooldown = $balanceCooldown;
        $this->balanceMaxShift = $balanceMaxShift;
        $this->parentId = $parentId;
        $this->rest = $rest;
        $this->autoScalingStrategy = $autoScalingStrategy;
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
