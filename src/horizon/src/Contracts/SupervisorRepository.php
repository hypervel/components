<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

use Hypervel\Horizon\Supervisor;

interface SupervisorRepository
{
    /**
     * Get the names of all the supervisors currently running.
     */
    public function names(): array;

    /**
     * Get information on all of the supervisors.
     */
    public function all(): array;

    /**
     * Get information on a supervisor by name.
     */
    public function find(string $name): array;

    /**
     * Get information on the given supervisors.
     */
    public function get(array $names): array;

    /**
     * Get the longest active timeout setting for a supervisor.
     */
    public function longestActiveTimeout(): int;

    /**
     * Update the information about the given supervisor process.
     */
    public function update(Supervisor $supervisor): void;

    /**
     * Remove the supervisor information from storage.
     */
    public function forget(array|string $names): void;

    /**
     * Remove expired supervisors from storage.
     */
    public function flushExpired(): void;
}
