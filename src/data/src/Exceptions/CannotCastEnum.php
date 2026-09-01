<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Support\DataProperty;

class CannotCastEnum extends Exception
{
    /**
     * Create an exception for an invalid backed-enum value.
     *
     * @param class-string $type
     */
    public static function create(string $type, mixed $value, DataProperty $property): self
    {
        return new self(
            "Could not cast value [" . self::describe($value) . "] for property "
            . "[{$property->className}::\${$property->name}] to enum [{$type}]."
        );
    }

    /**
     * Describe a value without triggering object string conversion.
     */
    protected static function describe(mixed $value): string
    {
        return is_scalar($value) || $value === null
            ? var_export($value, true)
            : get_debug_type($value);
    }
}
