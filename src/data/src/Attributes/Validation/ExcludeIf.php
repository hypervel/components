<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ExcludeIf extends StringValidationAttribute
{
    protected FieldReference $field;

    /**
     * Create a new exclude-if validation attribute.
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
        return 'exclude_if';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return parent::create(
            $parameters[0],
            self::parseBooleanValue($parameters[1]),
        );
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
}
