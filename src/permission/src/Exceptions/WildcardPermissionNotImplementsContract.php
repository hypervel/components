<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use InvalidArgumentException;

class WildcardPermissionNotImplementsContract extends InvalidArgumentException
{
    /**
     * Create a new wildcard permission contract exception.
     */
    public static function create(): static
    {
        return new static(__('Wildcard permission class must implement Hypervel\Permission\Contracts\Wildcard contract'));
    }
}
