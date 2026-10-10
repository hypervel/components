<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class CouldNotCreateValidationRule extends Exception
{
    /**
     * Create an exception for a rule string with no matching validation attribute.
     */
    public static function create(string $rule): self
    {
        return new self("Could not create a validation rule for: `{$rule}`");
    }
}
