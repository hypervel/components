<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Support\DataProperty;

class CannotTransformData extends Exception
{
    /**
     * Create an exception for a lazy value that cannot be persisted.
     */
    public static function nonConstructableLazy(DataProperty $property): self
    {
        return new self(
            "Lazy property [{$property->className}::\${$property->name}] does not resolve to constructable data. "
            . 'Conditional and relational lazy values must be included before persistence; callback and Inertia lazy values cannot be persisted.'
        );
    }
}
