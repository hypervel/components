<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class GreaterThan extends StringValidationAttribute
{
    protected int|float|string|FieldReference $field;

    /**
     * Create a new greater-than validation attribute.
     */
    public function __construct(
        int|float|string|FieldReference $field,
    ) {
        $this->field = is_numeric($field) ? $field : $this->parseFieldReference($field);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'gt';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->field];
    }
}
