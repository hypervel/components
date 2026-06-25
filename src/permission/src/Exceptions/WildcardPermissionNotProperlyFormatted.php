<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use InvalidArgumentException;

class WildcardPermissionNotProperlyFormatted extends InvalidArgumentException
{
    /**
     * Create a new wildcard permission formatting exception.
     */
    public static function create(string $permission): static
    {
        return new static(__('Wildcard permission `:permission` is not properly formatted.', [
            'permission' => $permission,
        ]));
    }
}
