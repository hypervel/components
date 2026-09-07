<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Contracts\Body;

interface MergeableBody extends BodyRepository
{
    /**
     * Get the raw data in the repository.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array;

    /**
     * Merge arrays into the repository.
     *
     * @param array<mixed, mixed> ...$arrays
     * @return $this
     */
    public function merge(array ...$arrays): static;
}
