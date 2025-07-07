<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use BackedEnum;
use Hyperf\Database\Model\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionManager;
use InvalidArgumentException;
use UnitEnum;

/**
 * Trait HasPermission.
 *
 * This trait provides methods to check if a owner has a specific permission
 * and to manage permissions assigned to the owner.
 *
 * @property-read Collection<Permission> $permissions
 */
trait HasPermission
{
    private ?string $permissionClass = null;

    public function getPermissionClass(): string
    {
        if ($this->permissionClass === null) {
            $this->permissionClass = app(PermissionManager::class)->getPermissionClass();
        }

        return $this->permissionClass;
    }

    /**
     * A model may have multiple direct permissions.
     */
    public function permissions(): MorphToMany
    {
        return $this->morphToMany(
            $this->getPermissionClass(),
            config('permission.table_names.owner_name', 'owner'),
            config('permission.table_names.owner_has_permissions', 'owner_has_permissions'),
            config('permission.column_names.owner_morph_key', 'owner_id'),
            config('permission.column_names.permission_pivot_key', 'permission_id')
        )
            ->withPivot(['is_forbidden']);
    }

    /**
     * Check if the owner has a specific permission.
     */
    public function hasPermission(BackedEnum|int|string|UnitEnum $permission): bool
    {
        // First check if there's a direct forbidden permission - this takes highest priority
        if ($this->hasForbiddenPermission($permission)) {
            return false;
        }

        // Check if any role has forbidden permission - this also overrides direct permissions
        if ($this->hasForbiddenPermissionViaRoles($permission)) {
            return false;
        }

        return $this->hasDirectPermission($permission) || $this->hasPermissionViaRoles($permission);
    }

    /**
     * Check if the owner has a direct permission.
     */
    public function hasDirectPermission(BackedEnum|int|string|UnitEnum $permission): bool
    {
        $this->loadMissing('permissions');

        [$field, $value] = $this->normalizePermissionValue($permission);

        return $this->permissions
            ->where($field, $value)
            ->where('pivot.is_forbidden', false)
            ->isNotEmpty();
    }

    /**
     * Check if the owner has permission via roles.
     */
    public function hasPermissionViaRoles(BackedEnum|int|string|UnitEnum $permission): bool
    {
        if (is_a($this, Role::class)) {
            return false;
        }

        $this->loadMissing('roles.permissions');

        [$field, $value] = $this->normalizePermissionValue($permission);

        return $this->roles
            ->pluck('permissions')
            ->flatten()
            ->where($field, $value)
            ->where('pivot.is_forbidden', false)
            ->isNotEmpty();
    }

    /**
     * Check if the owner has any of the specified permissions.
     */
    public function hasAnyPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): bool
    {
        return collect($permissions)->flatten()->some(fn ($permission) => $this->hasPermission($permission));
    }

    /**
     * Check if the owner has all of the specified permissions.
     */
    public function hasAllPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): bool
    {
        return collect($permissions)->flatten()->every(fn ($permission) => $this->hasPermission($permission));
    }

    public function hasAllDirectPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): bool
    {
        return collect($permissions)->flatten()->every(fn ($permission) => $this->hasDirectPermission($permission));
    }

    public function hasAnyDirectPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): bool
    {
        return collect($permissions)->flatten()->some(fn ($permission) => $this->hasDirectPermission($permission));
    }

    /**
     * Give permission to the owner.
     */
    public function givePermissionTo(array|BackedEnum|int|string|UnitEnum ...$permissions): static
    {
        return $this->attachPermission($permissions);
    }

    /**
     * Give forbidden permission to the owner.
     */
    public function giveForbiddenTo(array|BackedEnum|int|string|UnitEnum ...$permissions): static
    {
        return $this->attachPermission($permissions, true);
    }

    /**
     * Revoke permission from the owner.
     */
    public function revokePermissionTo(array|BackedEnum|int|string|UnitEnum ...$permissions): static
    {
        $detachPermissions = $this->collectPermissions($permissions);

        $this->permissions()->detach($detachPermissions);

        return $this;
    }

    /**
     * Synchronize the owner's permissions with the given permission list.
     */
    public function syncPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): static
    {
        $permissions = $this->collectPermissions($permissions);

        $this->permissions()->sync($permissions);

        return $this;
    }

    /**
     * Normalize permission value to field and value pair.
     */
    private function normalizePermissionValue(BackedEnum|int|string|UnitEnum $permission): array
    {
        $value = $this->extractPermissionValue($permission);
        $isId = $this->isPermissionIdType($permission);

        return $isId
            ? [(new ($this->getPermissionClass())())->getKeyName(), $value]
            : ['name', $value];
    }

    /**
     * Extract the actual value from a permission of any supported type.
     */
    private function extractPermissionValue(BackedEnum|int|string|UnitEnum $permission): int|string
    {
        return match (true) {
            $permission instanceof BackedEnum => $permission->value,
            $permission instanceof UnitEnum => $permission->name,
            default => $permission
        };
    }

    /**
     * Check if the permission should be treated as an ID (int) rather than name (string).
     */
    private function isPermissionIdType(BackedEnum|int|string|UnitEnum $permission): bool
    {
        return match (true) {
            is_int($permission) => true,
            $permission instanceof BackedEnum => is_int($permission->value),
            is_string($permission), $permission instanceof UnitEnum => false,
            default => throw new InvalidArgumentException('Invalid permission type')
        };
    }

    /**
     * Separate permissions array into IDs and names collections.
     */
    private function separatePermissionsByType(array $permissions): array
    {
        $permissionIds = collect();
        $permissionNames = collect();

        foreach ($permissions as $permission) {
            $value = $this->extractPermissionValue($permission);

            if ($this->isPermissionIdType($permission)) {
                $permissionIds->push($value);
            } else {
                $permissionNames->push($value);
            }
        }

        return [$permissionIds, $permissionNames];
    }

    /**
     * Attach permission to the owner.
     */
    private function attachPermission(array $permissions, bool $isForbidden = false): static
    {
        $permissions = $this->collectPermissions($permissions);

        // Get existing permissions with the same is_forbidden value
        $currentPermissions = $this->permissions
            ->where('pivot.is_forbidden', $isForbidden)
            ->map(fn (Permission $permission) => $permission->getKey())
            ->toArray();

        // Only attach permissions that don't already exist with the same is_forbidden value
        $permissionsToAttach = array_diff($permissions, $currentPermissions);
        if (! empty($permissionsToAttach)) {
            $this->permissions()->attach($permissionsToAttach, [
                'is_forbidden' => $isForbidden,
            ]);
        }

        $this->unsetRelation('permissions');

        return $this;
    }

    /**
     * Check if the owner has a forbidden permission.
     */
    public function hasForbiddenPermission(BackedEnum|int|string|UnitEnum $permission): bool
    {
        $this->loadMissing('permissions');

        [$field, $value] = $this->normalizePermissionValue($permission);

        return $this->permissions
            ->where($field, $value)
            ->where('pivot.is_forbidden', true)
            ->isNotEmpty();
    }

    /**
     * Check if the owner has a forbidden permission via roles.
     */
    public function hasForbiddenPermissionViaRoles(BackedEnum|int|string|UnitEnum $permission): bool
    {
        if (is_a($this, Role::class)) {
            return false;
        }

        $this->loadMissing('roles.permissions');

        [$field, $value] = $this->normalizePermissionValue($permission);

        return $this->roles
            ->pluck('permissions')
            ->flatten()
            ->where($field, $value)
            ->where('pivot.is_forbidden', true)
            ->isNotEmpty();
    }

    /**
     * Returns array of permission ids.
     */
    private function collectPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): array
    {
        $permissions = collect($permissions)
            ->flatten()
            ->values()
            ->all();
        [$permissionIds, $permissionNames] = $this->separatePermissionsByType($permissions);

        $permissionInstance = new ($this->getPermissionClass())();
        $keyName = $permissionInstance->getKeyName();
        $query = $permissionInstance::query();

        if ($permissionIds->isNotEmpty()) {
            $query->whereIn($keyName, $permissionIds);
        }

        if ($permissionNames->isNotEmpty()) {
            $query->orWhereIn('name', $permissionNames);
        }

        return $query->pluck('id')->toArray();
    }
}
