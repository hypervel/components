<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\Exceptions\RoleDoesNotExist;
use UnitEnum;

/**
 * @property int|string $id
 * @property string $name
 * @property null|string $guard_name
 *
 * @mixin \Hypervel\Permission\Models\Role
 *
 * @phpstan-require-extends \Hypervel\Permission\Models\Role
 */
interface Role
{
    /**
     * Get the permissions given to this role.
     */
    public function permissions(): BelongsToMany;

    /**
     * Find a role by its name and guard name.
     *
     * @throws RoleDoesNotExist
     */
    public static function findByName(UnitEnum|string $name, ?string $guardName): self;

    /**
     * Find a role by its id and guard name.
     *
     * @throws RoleDoesNotExist
     */
    public static function findById(int|string $id, ?string $guardName): self;

    /**
     * Find or create a role by its name and guard name.
     */
    public static function findOrCreate(UnitEnum|string $name, ?string $guardName): self;

    /**
     * Determine if the user may perform the given permission.
     */
    public function hasPermissionTo(UnitEnum|int|string|Permission $permission, ?string $guardName = null): bool;
}
