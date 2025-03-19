<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hypervel\Queue\DatabaseQueue;
use Psr\Container\ContainerInterface;

class DatabaseJob extends Job
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected DatabaseQueue $database,
        protected DatabaseJobRecord $job,
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

        $this->database->deleteAndRelease($this->queue, $this, $delay);
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        $this->database->deleteReserved($this->queue, (string) $this->job->id);
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return (int) $this->job->attempts;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return (string) $this->job->id;
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        return $this->job->payload;
    }

    /**
     * Get the database job record.
     */
    public function getJobRecord(): DatabaseJobRecord
    {
        return $this->job;
    }
}
