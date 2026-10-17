<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Exceptions;

use Exception;

class NotImplementedException extends Exception
{
    /**
     * Create an exception for an unimplemented method.
     */
    public static function forMethod(string $class, string $method): static
    {
        return new static("The method [{$class}@{$method}] is not implemented yet.");
    }
}
