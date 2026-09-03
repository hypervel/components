<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class BooleanType extends StringValidationAttribute
{
    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'boolean';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [];
    }
}
