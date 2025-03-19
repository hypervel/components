<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use DateInterval;
use DateTimeInterface;
use Hyperf\Stringable\Str;
use Throwable;

class FakeJob extends Job
{
    /**
     * The number of seconds the released job was delayed.
     */
    public int $releaseDelay = 0;

    /**
     * The number of attempts made to process the job.
     */
    public int $attempts = 1;

    public function __construct(
        public array $payload = []
    ) {
    }

    /**
     * The exception the job failed with.
     */
    public ?Throwable $failedWith = null;

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return json_encode($this->payload);
    }

    /**
     * Get the decoded body of the job.
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(DateInterval|DateTimeInterface|int $delay = 0): void
    {
        $this->released = true;
        $this->releaseDelay = $delay;
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    /**
     * Delete the job, call the "failed" method, and raise the failed job event.
     */
    public function fail(?Throwable $exception = null): void
    {
        $this->failed = true;
        $this->failedWith = $exception;
    }
}
