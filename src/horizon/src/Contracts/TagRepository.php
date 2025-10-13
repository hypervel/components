<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface TagRepository
{
    /**
     * Get the currently monitored tags.
     */
    public function monitoring(): array;

    /**
     * Return the tags which are being monitored.
     */
    public function monitored(array $tags): array;

    /**
     * Start monitoring the given tag.
     */
    public function monitor(string $tag): void;

    /**
     * Stop monitoring the given tag.
     */
    public function stopMonitoring(string $tag): void;

    /**
     * Store the tags for the given job.
     */
    public function add(string $id, array $tags): void;

    /**
     * Store the tags for the given job temporarily.
     */
    public function addTemporary(int $minutes, string $id, array $tags): void;

    /**
     * Get the number of jobs matching a given tag.
     */
    public function count(string $tag): int;

    /**
     * Get all of the job IDs for a given tag.
     */
    public function jobs(string $tag): array;

    /**
     * Paginate the job IDs for a given tag.
     */
    public function paginate(string $tag, int $startingAt = 0, int $limit = 25): array;

    /**
     * Delete the given tag from storage.
     */
    public function forget(string $tag): void;
}
