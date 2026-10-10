<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class AcceptedIf extends StringValidationAttribute
{
    protected FieldReference $field;

    /**
     * Create an accepted-if rule attribute.
     */
    public function __construct(
        string|FieldReference $field,
        protected string|bool|int|float|BackedEnum|ExternalReference $value,
    ) {
        $this->field = $this->parseFieldReference($field);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'accepted_if';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [
            $this->field,
            $this->value,
        ];
    }

    // The inherited create() keeps the parsed value as written: converting '1' to 'true', as Spatie does,
    // changes what a string or integer dependent matches.
}
