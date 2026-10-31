<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ExcludeUnless extends StringValidationAttribute
{
    protected FieldReference $field;

    protected array $values;

    /**
     * Create a new exclude-unless validation attribute.
     */
    public function __construct(
        string|FieldReference $field,
        protected string|bool|int|float|BackedEnum|ExternalReference $value,
        string|bool|int|float|BackedEnum|ExternalReference ...$values,
    ) {
        $this->field = $this->parseFieldReference($field);
        $this->values = $values;
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'exclude_unless';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [
            $this->field,
            $this->value,
            $this->values,
        ];
    }
}
