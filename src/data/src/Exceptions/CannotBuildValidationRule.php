<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class CannotBuildValidationRule extends Exception
{
    /**
     * Create an exception for an incomplete rule declaration.
     */
    public static function create(string $message): self
    {
        return new self($message);
    }
}
