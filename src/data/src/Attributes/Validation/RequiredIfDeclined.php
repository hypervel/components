<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Data\Support\Validation\RequiringRule;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class RequiredIfDeclined extends StringValidationAttribute implements RequiringRule
{
    protected FieldReference $field;

    /**
     * Create a required-if-declined rule attribute.
     */
    public function __construct(string|FieldReference $field)
    {
        $this->field = $this->parseFieldReference($field);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'required_if_declined';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->field];
    }
}
