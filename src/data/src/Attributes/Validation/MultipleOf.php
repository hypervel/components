<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MultipleOf extends StringValidationAttribute
{
    /**
     * Create a new multiple-of validation attribute.
     */
    public function __construct(protected int|float|string|ExternalReference $value)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'multiple_of';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->value];
    }
}
