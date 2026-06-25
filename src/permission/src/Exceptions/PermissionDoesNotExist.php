<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use InvalidArgumentException;

class PermissionDoesNotExist extends InvalidArgumentException
{
    /**
     * Create a new permission does not exist exception.
     */
    public static function create(string $permissionName, ?string $guardName): static
    {
        return new static(__('There is no permission named `:permission` for guard `:guard`.', [
            'permission' => $permissionName,
            'guard' => $guardName,
        ]));
    }

    /**
     * Create a new permission does not exist exception for an id.
     */
    public static function withId(int|string $permissionId, ?string $guardName): static
    {
        return new static(__('There is no [permission] with ID `:id` for guard `:guard`.', [
            'id' => $permissionId,
            'guard' => $guardName,
        ]));
    }
}
