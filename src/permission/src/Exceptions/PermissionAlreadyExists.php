<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use InvalidArgumentException;

class PermissionAlreadyExists extends InvalidArgumentException
{
    /**
     * Create a new permission already exists exception.
     */
    public static function create(string $permissionName, string $guardName): static
    {
        return new static(__('A `:permission` permission already exists for guard `:guard`.', [
            'permission' => $permissionName,
            'guard' => $guardName,
        ]));
    }
}
