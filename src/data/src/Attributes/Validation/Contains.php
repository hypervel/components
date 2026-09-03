<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Contains extends StringValidationAttribute
{
    protected array $values;

    /**
     * Create a contains rule attribute.
     */
    public function __construct(array|int|string|BackedEnum|ExternalReference ...$values)
    {
        $this->values = Arr::flatten($values);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'contains';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->values];
    }
}
