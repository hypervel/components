<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hypervel\Queue\RedisQueue;
use Psr\Container\ContainerInterface;

class RedisJob extends Job
{
    /**
     * The JSON decoded version of "$job".
     */
    protected array $decoded = [];

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected RedisQueue $redis,
        protected string $job,
        protected string $reserved,
        protected string $connectionName,
        protected ?string $queue
    ) {
        // The $job variable is the original job JSON as it existed in the ready queue while
        // the $reserved variable is the raw JSON in the reserved queue. The exact format
        // of the reserved job is required in order for us to properly delete its data.

        $this->decoded = $this->payload();
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        return $this->job;
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        $this->redis->deleteReserved($this->queue, $this);
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);

        $this->redis->deleteAndRelease($this->queue, $this, $delay);
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return ($this->decoded['attempts'] ?? null) + 1;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): ?string
    {
        return $this->decoded['id'] ?? null;
    }

    /**
     * Get the underlying Redis factory implementation.
     */
    public function getRedisQueue(): RedisQueue
    {
        return $this->redis;
    }

    /**
     * Get the underlying reserved Redis job.
     */
    public function getReservedJob(): string
    {
        return $this->reserved;
    }
}
