<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DoesntEndWith extends StringValidationAttribute
{
    protected array $values;

    /**
     * Create a new doesn't-end-with validation attribute.
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
        return 'doesnt_end_with';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->values];
    }
}
