<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use DateTimeInterface;
use Exception;

class CannotCastDate extends Exception
{
    /**
     * Create an exception for a value that matches no configured date format.
     *
     * @param non-empty-list<string> $formats
     * @param class-string<DateTimeInterface> $type
     */
    public static function create(array $formats, string $type, mixed $value): self
    {
        $value = is_scalar($value) || $value === null
            ? var_export($value, true)
            : get_debug_type($value);

        return new self(
            "Could not cast value [{$value}] to date [{$type}] using formats ["
            . implode(', ', $formats) . '].'
        );
    }
}
