<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use InvalidArgumentException;

class RoleAlreadyExists extends InvalidArgumentException
{
    /**
     * Create a new role already exists exception.
     */
    public static function create(string $roleName, string $guardName): static
    {
        return new static(__('A role `:role` already exists for guard `:guard`.', [
            'role' => $roleName,
            'guard' => $guardName,
        ]));
    }
}
