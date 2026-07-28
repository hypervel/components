<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hypervel\Contracts\Container\Container;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\RedisQueue;

class RedisJob extends Job
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Container $container,
        protected RedisQueue $redis,
        protected string $job,
        protected string $reserved,
        protected string $connectionName,
        protected string $queue,
        protected ?int $attempts,
    ) {
        // The $job variable is the original job JSON as it existed in the ready queue while
        // the $reserved variable is the raw JSON in the reserved queue. The exact format
        // of the reserved job is required in order for us to properly delete its data.
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
        return $this->attempts ?? 1;
    }

    /**
     * Get the decoded body of the job.
     */
    public function payload(): array
    {
        $payload = parent::payload();

        if ($this->attempts === null) {
            throw $this->payloadException ??= new InvalidPayloadException(
                'The Redis queue job payload does not contain a valid attempts count.',
                $this->job,
            );
        }

        return $payload;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): ?string
    {
        try {
            $id = parent::payload()['id'] ?? null;
        } catch (InvalidPayloadException) {
            return null;
        }

        return is_string($id) ? $id : null;
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
