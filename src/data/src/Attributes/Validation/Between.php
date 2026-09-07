<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Between extends StringValidationAttribute
{
    /**
     * Create a between rule attribute.
     */
    public function __construct(
        protected int|float|string|ExternalReference $min,
        protected int|float|string|ExternalReference $max,
    ) {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'between';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->min, $this->max];
    }
}
