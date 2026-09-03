<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ExcludeWith extends StringValidationAttribute
{
    protected FieldReference $field;

    /**
     * Create a new exclude-with validation attribute.
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
        return 'exclude_with';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->field];
    }
}
