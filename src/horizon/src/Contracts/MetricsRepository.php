<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface MetricsRepository
{
    /**
     * Get all of the class names that have metrics measurements.
     */
    public function measuredJobs(): array;

    /**
     * Get all of the queues that have metrics measurements.
     */
    public function measuredQueues(): array;

    /**
     * Get the jobs processed per minute since the last snapshot.
     */
    public function jobsProcessedPerMinute(): float;

    /**
     * Get the application's total throughput since the last snapshot.
     */
    public function throughput(): int;

    /**
     * Get the throughput for a given job.
     */
    public function throughputForJob(string $job): int;

    /**
     * Get the throughput for a given queue.
     */
    public function throughputForQueue(string $queue): int;

    /**
     * Get the average runtime for a given job in milliseconds.
     */
    public function runtimeForJob(string $job): float;

    /**
     * Get the average runtime for a given queue in milliseconds.
     */
    public function runtimeForQueue(string $queue): float;

    /**
     * Get the queue that has the longest runtime.
     */
    public function queueWithMaximumRuntime(): ?string;

    /**
     * Get the queue that has the most throughput.
     */
    public function queueWithMaximumThroughput(): ?string;

    /**
     * Increment the metrics information for a job.
     */
    public function incrementJob(string $job, ?float $runtime): void;

    /**
     * Increment the metrics information for a queue.
     */
    public function incrementQueue(string $queue, ?float $runtime): void;

    /**
     * Get all of the snapshots for the given job.
     */
    public function snapshotsForJob(string $job): array;

    /**
     * Get all of the snapshots for the given queue.
     */
    public function snapshotsForQueue(string $queue): array;

    /**
     * Store a snapshot of the metrics information.
     */
    public function snapshot(): void;

    /**
     * Attempt to acquire a lock to monitor the queue wait times.
     */
    public function acquireWaitTimeMonitorLock(): bool;

    /**
     * Clear the metrics for a key.
     */
    public function forget(string $key): void;

    /**
     * Delete all stored metrics information.
     */
    public function clear(): void;
}
