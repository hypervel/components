<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class CurrentPassword extends StringValidationAttribute
{
    /**
     * Create a current-password rule attribute.
     */
    public function __construct(protected string|BackedEnum|ExternalReference|null $guard = null)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'current_password';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->guard];
    }
}
