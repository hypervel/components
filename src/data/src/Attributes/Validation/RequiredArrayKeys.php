<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class RequiredArrayKeys extends StringValidationAttribute
{
    protected array $values;

    /**
     * Create a required-array-keys rule attribute.
     */
    public function __construct(string|array|ExternalReference ...$values)
    {
        $this->values = Arr::flatten($values);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'required_array_keys';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->values];
    }
}
