<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

use Hypervel\Horizon\MasterSupervisor;
use stdClass;

interface MasterSupervisorRepository
{
    /**
     * Get the names of all the master supervisors currently running.
     */
    public function names(): array;

    /**
     * Get information on all of the master supervisors.
     */
    public function all(): array;

    /**
     * Get information on a master supervisor by name.
     */
    public function find(string $name): ?stdClass;

    /**
     * Get information on the given master supervisors.
     */
    public function get(array $names): array;

    /**
     * Update the information about the given master supervisor.
     */
    public function update(MasterSupervisor $master): void;

    /**
     * Remove the master supervisor information from storage.
     */
    public function forget(string $name): void;

    /**
     * Remove expired master supervisors from storage.
     */
    public function flushExpired(): void;
}
