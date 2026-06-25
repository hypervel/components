<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

interface Wildcard
{
    /**
     * Get the wildcard permission index.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getIndex(): array;

    /**
     * Determine if the wildcard permission implies another permission.
     *
     * @param array<string, array<string, mixed>> $index
     */
    public function implies(string $permission, string $guardName, array $index): bool;
}
