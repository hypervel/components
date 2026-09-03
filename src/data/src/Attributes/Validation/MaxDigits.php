<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MaxDigits extends StringValidationAttribute
{
    /**
     * Create a new max-digits validation attribute.
     */
    public function __construct(protected int|string|ExternalReference $value)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'max_digits';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->value];
    }
}
