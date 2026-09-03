<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MissingWith extends StringValidationAttribute
{
    protected array $fields = [];

    /**
     * Create a missing-with rule attribute.
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
        return 'missing_with';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->fields];
    }
}
