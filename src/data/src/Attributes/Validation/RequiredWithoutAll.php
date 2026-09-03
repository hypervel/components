<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Data\Support\Validation\RequiringRule;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class RequiredWithoutAll extends StringValidationAttribute implements RequiringRule
{
    protected array $fields = [];

    /**
     * Create a required-without-all rule attribute.
     */
    public function __construct(array|string|FieldReference ...$fields)
    {
        foreach (Arr::flatten($fields) as $field) {
            $this->fields[] = $this->parseFieldReference($field);
        }
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'required_without_all';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [
            $this->fields,
        ];
    }
}
