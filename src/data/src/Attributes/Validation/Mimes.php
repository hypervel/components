<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Mimes extends StringValidationAttribute
{
    protected array $mimes;

    /**
     * Create a new MIME validation attribute.
     */
    public function __construct(string|array|ExternalReference ...$mimes)
    {
        $this->mimes = Arr::flatten($mimes);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'mimes';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->mimes];
    }
}
