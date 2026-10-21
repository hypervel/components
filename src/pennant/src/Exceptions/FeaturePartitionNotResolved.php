<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Exceptions;

use RuntimeException;

class FeaturePartitionNotResolved extends RuntimeException
{
    /**
     * Create a new unresolved feature partition exception.
     */
    public static function forStore(string $store, string $column): static
    {
        return new static("The [{$column}] partition of Pennant store [{$store}] could not be resolved.");
    }
}
