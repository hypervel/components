<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use InvalidArgumentException;

class RoleDoesNotExist extends InvalidArgumentException
{
    /**
     * Create a new role does not exist exception for a name.
     */
    public static function named(string $roleName, ?string $guardName): static
    {
        return new static(__('There is no role named `:role` for guard `:guard`.', [
            'role' => $roleName,
            'guard' => $guardName,
        ]));
    }

    /**
     * Create a new role does not exist exception for an id.
     */
    public static function withId(int|string $roleId, ?string $guardName): static
    {
        return new static(__('There is no role with ID `:id` for guard `:guard`.', [
            'id' => $roleId,
            'guard' => $guardName,
        ]));
    }
}
