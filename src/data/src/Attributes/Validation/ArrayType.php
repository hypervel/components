<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ArrayType extends StringValidationAttribute
{
    /** @var list<string|ExternalReference> */
    protected array $keys;

    /**
     * Create an array rule attribute.
     */
    public function __construct(array|string|ExternalReference ...$keys)
    {
        $this->keys = Arr::flatten($keys);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'array';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return $this->keys;
    }
}
