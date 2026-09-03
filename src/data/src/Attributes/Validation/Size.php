<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Size extends StringValidationAttribute
{
    /**
     * Create a size rule attribute.
     */
    public function __construct(protected int|float|string|ExternalReference $size)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'size';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->size];
    }
}
