<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Data\Support\Validation\RequiringRule;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class RequiredIf extends StringValidationAttribute implements RequiringRule
{
    protected FieldReference $field;

    protected array $values;

    /**
     * Create a required-if rule attribute.
     */
    public function __construct(
        string|FieldReference $field,
        array|bool|int|float|string|BackedEnum|ExternalReference|null ...$values,
    ) {
        $this->field = $this->parseFieldReference($field);
        $this->values = Arr::flatten($values);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'required_if';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [
            $this->field,
            $this->values,
        ];
    }
}
