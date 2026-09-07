<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Decimal extends StringValidationAttribute
{
    /**
     * Create a decimal rule attribute.
     */
    public function __construct(
        protected int|string|ExternalReference $min,
        protected int|string|ExternalReference|null $max = null,
    ) {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'decimal';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->min, $this->max];
    }
}
