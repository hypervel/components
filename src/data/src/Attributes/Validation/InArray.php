<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InArray extends StringValidationAttribute
{
    protected FieldReference $field;

    /**
     * Create a new in-array validation attribute.
     */
    public function __construct(
        string|FieldReference $field,
    ) {
        $this->field = $this->parseFieldReference($field);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'in_array';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->field];
    }
}
