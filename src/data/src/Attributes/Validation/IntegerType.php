<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class IntegerType extends StringValidationAttribute
{
    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'integer';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [];
    }
}
