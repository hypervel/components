<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Support\DataProperty;

class CannotSetComputedValue extends Exception
{
    /**
     * Create an exception for supplied computed input.
     */
    public static function create(DataProperty $property): self
    {
        return new self(
            "Cannot set property [{$property->className}::\${$property->name}] because it is computed."
        );
    }
}
