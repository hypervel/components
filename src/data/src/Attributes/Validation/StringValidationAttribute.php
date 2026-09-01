<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

abstract class StringValidationAttribute extends ValidationAttribute
{
    /**
     * Get the rule parameters.
     */
    abstract public function parameters(): array;

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static(...$parameters);
    }
}
