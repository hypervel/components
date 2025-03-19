<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Psr\Container\ContainerInterface;

class SyncJob extends Job
{
    /**
     * The class name of the job.
     */
    protected string $job;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected string $payload,
        protected string $connectionName,
        protected ?string $queue
    ) {
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return 1;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return '';
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        return $this->payload;
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return 'sync';
    }
}
