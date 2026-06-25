<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use UnitEnum;

/**
 * @property int|string $id
 * @property string $name
 * @property null|string $guard_name
 *
 * @mixin \Hypervel\Permission\Models\Permission
 *
 * @phpstan-require-extends \Hypervel\Permission\Models\Permission
 */
interface Permission
{
    /**
     * Get the roles that have this permission.
     */
    public function roles(): BelongsToMany;

    /**
     * Find a permission by its name.
     *
     * @throws PermissionDoesNotExist
     */
    public static function findByName(UnitEnum|string $name, ?string $guardName): self;

    /**
     * Find a permission by its id.
     *
     * @throws PermissionDoesNotExist
     */
    public static function findById(int|string $id, ?string $guardName): self;

    /**
     * Find or Create a permission by its name and guard name.
     */
    public static function findOrCreate(UnitEnum|string $name, ?string $guardName): self;
}
