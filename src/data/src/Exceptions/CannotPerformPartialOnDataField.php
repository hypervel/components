<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use InvalidArgumentException;

class CannotPerformPartialOnDataField extends InvalidArgumentException
{
    /**
     * Create an exception for an invalid partial path declaration.
     */
    public static function invalidPath(string $path): self
    {
        return new self("Partial path [{$path}] is invalid.");
    }

    /**
     * Create an exception for an unknown data property.
     *
     * @param class-string $class
     */
    public static function missingProperty(
        string $operation,
        string $field,
        string $class,
    ): self {
        return new self(sprintf(
            'Cannot %s unknown data property [%s] on [%s].',
            $operation,
            $field,
            $class,
        ));
    }
}
