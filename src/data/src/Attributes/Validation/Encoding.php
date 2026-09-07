<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Encoding extends StringValidationAttribute
{
    /**
     * Create an encoding rule attribute.
     */
    public function __construct(protected string|ExternalReference $encoding)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'encoding';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->encoding];
    }
}
