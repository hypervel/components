<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface ProcessRepository
{
    /**
     * Get all of the orphan process IDs and the times they were observed.
     */
    public function allOrphans(string $master): array;

    /**
     * Record the given process IDs as orphaned.
     */
    public function orphaned(string $master, array $processIds): array;

    /**
     * Get the process IDs orphaned for at least the given number of seconds.
     */
    public function orphanedFor(string $master, int $seconds): array;

    /**
     * Remove the given process IDs from the orphan list.
     */
    public function forgetOrphans(string $master, array $processIds): void;
}
