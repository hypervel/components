<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use InvalidArgumentException;

class WildcardPermissionInvalidArgument extends InvalidArgumentException
{
    /**
     * Create a new wildcard permission invalid argument exception.
     */
    public static function create(): static
    {
        return new static(__('Wildcard permission must be string, permission id or permission instance'));
    }
}
