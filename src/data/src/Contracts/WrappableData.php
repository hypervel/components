<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

use Hypervel\Data\Support\Wrapping\Wrap;

interface WrappableData
{
    /**
     * Disable wrapping for the data object.
     */
    public function withoutWrapping(): static;

    /**
     * Wrap the data object with the given key.
     */
    public function wrap(string $key): static;

    /**
     * Get the current wrapping definition.
     */
    public function getWrap(): Wrap;
}
