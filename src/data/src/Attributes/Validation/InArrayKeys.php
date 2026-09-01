<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InArrayKeys extends StringValidationAttribute
{
    protected array $keys;

    /**
     * Create an in-array-keys rule attribute.
     */
    public function __construct(array|int|string|BackedEnum|ExternalReference ...$keys)
    {
        $this->keys = Arr::flatten($keys);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'in_array_keys';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->keys];
    }
}
