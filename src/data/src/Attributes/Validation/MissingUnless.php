<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MissingUnless extends StringValidationAttribute
{
    protected FieldReference $field;

    protected array $values;

    /**
     * Create a missing-unless rule attribute.
     */
    public function __construct(
        string|FieldReference $field,
        null|array|bool|int|float|string|BackedEnum|ExternalReference ...$values,
    ) {
        $this->field = $this->parseFieldReference($field);
        $this->values = Arr::flatten($values);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'missing_unless';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->field, $this->values];
    }
}
