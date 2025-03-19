<?php

declare(strict_types=1);

namespace Hypervel\Queue\Contracts;

use Throwable;

interface Job
{
    /**
     * Get the UUID of the job.
     */
    public function uuid(): ?string;

    /**
     * Get the job identifier.
     */
    public function getJobId(): null|int|string;

    /**
     * Fire the job.
     */
    public function fire(): void;

    /**
     * Release the job back into the queue after the specified delay.
     */
    public function release(int $delay = 0): void;

    /**
     * Determine if the job was released back into the queue.
     */
    public function isReleased(): bool;

    /**
     * Delete the job from the queue.
     */
    public function delete(): void;

    /**
     * Determine if the job has been deleted.
     */
    public function isDeleted(): bool;

    /**
     * Determine if the job has been deleted or released.
     */
    public function isDeletedOrReleased(): bool;

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int;

    /**
     * Determine if the job has been marked as a failure.
     */
    public function hasFailed(): bool;

    /**
     * Mark the job as "failed".
     */
    public function markAsFailed(): void;

    /**
     * Delete the job, call the "failed" method, and raise the failed job event.
     */
    public function fail(?Throwable $e = null): void;

    /**
     * Get the number of times to attempt a job.
     */
    public function maxTries(): ?int;

    /**
     * Get the maximum number of exceptions allowed, regardless of attempts.
     */
    public function maxExceptions(): ?int;

    /**
     * Get the number of seconds the job can run.
     */
    public function timeout(): ?int;

    /**
     * Get the timestamp indicating when the job should timeout.
     */
    public function retryUntil(): ?int;

    /**
     * Get the name of the queued job class.
     */
    public function getName(): string;

    /**
     * Get the resolved name of the queued job class.
     */
    public function resolveName(): string;

    /**
     * Get the name of the connection the job belongs to.
     */
    public function getConnectionName(): string;

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string;

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string;
}
