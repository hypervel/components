<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use BackedEnum;
use Hyperf\Collection\Collection as BaseCollection;
use Hyperf\Database\Model\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
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
     * Get PermissionManager instance.
     */
    protected function getPermissionManager(): PermissionManager
    {
        return app(PermissionManager::class);
    }

    /**
     * Get owner type for cache key generation.
     */
    protected function getOwnerType(): string
    {
        return static::class;
    }

    /**
     * Get cached or fresh permissions for this owner.
     *
     * @return Collection<Permission>
     */
    protected function getCachedPermissions(): Collection
    {
        $manager = $this->getPermissionManager();
        $cachedPermissions = $manager->getOwnerCachedPermissions($this->getOwnerType(), $this->getKey());

        if (! empty($cachedPermissions)) {
            return $this->permissions()->getRelated()->hydrate($cachedPermissions);
        }

        // Load from database and cache
        $this->loadMissing('permissions');
        $permissions = $this->permissions;

        // Cache the permissions data
        $manager->cacheOwnerPermissions(
            $this->getOwnerType(),
            $this->getKey(),
            $permissions->toArray()
        );

        return $permissions;
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
     * Return all the permissions the model has, both direcstly and via roles.
     *
     * @return BaseCollection<Permission>
     */
    public function getAllPermissions(): BaseCollection
    {
        $directPermissions = $this->getCachedPermissions()->toBase();
        $rolePermissions = $this->getPermissionsViaRoles();

        // Filter out forbidden permissions from direct permissions
        $filteredDirect = $directPermissions->reject(function ($permission) {
            return isset($permission['pivot']) && $permission['pivot']['is_forbidden'] == true;
        });

        // Merge direct permissions with role permissions and remove duplicates by id
        return $filteredDirect->merge($rolePermissions)->unique('id');
    }

    /**
     * Get all permissions via roles.
     * This method returns all permissions that the owner has through its roles.
     * It does not include direct permissions.
     *
     * @return BaseCollection<Permission>
     */
    public function getPermissionsViaRoles(): BaseCollection
    {
        if (is_a($this->getOwnerType(), Role::class, true)) {
            return collect();
        }

        // Use cached all roles with permissions
        $manager = $this->getPermissionManager();
        $allRolesWithPermissions = $manager->getAllRolesWithPermissions();

        // Get cached roles (this method should be available if HasRole trait is used)
        if (method_exists($this, 'getCachedRoles')) {
            $ownerRoles = $this->getCachedRoles();
        } else {
            $this->loadMissing('roles');
            $ownerRoles = $this->roles;
        }

        $permissions = collect();
        foreach ($ownerRoles as $role) {
            $roleId = is_array($role) ? $role['id'] : $role->id;
            if (isset($allRolesWithPermissions[$roleId])) {
                $rolePermissions = collect($allRolesWithPermissions[$roleId]['permissions'])
                    ->where('pivot.is_forbidden', false);
                $permissions = $permissions->merge($rolePermissions);
            }
        }

        return $permissions;
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
        $ownerPermissions = $this->getCachedPermissions();

        [$field, $value] = $this->normalizePermissionValue($permission);

        return $ownerPermissions
            ->where($field, $value)
            ->where('pivot.is_forbidden', false)
            ->isNotEmpty();
    }

    /**
     * Check if the owner has permission via roles.
     */
    public function hasPermissionViaRoles(BackedEnum|int|string|UnitEnum $permission): bool
    {
        if (is_a($this->getOwnerType(), Role::class, true)) {
            return false;
        }

        // Use cached all roles with permissions
        $manager = $this->getPermissionManager();
        $allRolesWithPermissions = $manager->getAllRolesWithPermissions();

        // Get cached roles (this method should be available if HasRole trait is used)
        if (method_exists($this, 'getCachedRoles')) {
            $ownerRoles = $this->getCachedRoles();
        } else {
            $this->loadMissing('roles');
            $ownerRoles = $this->roles;
        }

        [$field, $value] = $this->normalizePermissionValue($permission);

        foreach ($ownerRoles as $role) {
            $roleId = $role->id ?? $role['id'];
            if (isset($allRolesWithPermissions[$roleId])) {
                $rolePermissions = collect($allRolesWithPermissions[$roleId]['permissions']);
                if ($rolePermissions->where($field, $value)->where('pivot.is_forbidden', false)->isNotEmpty()) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * Check if the owner has all direct permissions.
     */
    public function hasAllDirectPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): bool
    {
        return collect($permissions)->flatten()->every(fn ($permission) => $this->hasDirectPermission($permission));
    }

    /**
     * Check if the owner has any direct permissions.
     */
    public function hasAnyDirectPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): bool
    {
        return collect($permissions)->flatten()->some(fn ($permission) => $this->hasDirectPermission($permission));
    }

    /**
     * Give permission to the owner.
     */
    public function givePermissionTo(array|BackedEnum|int|string|UnitEnum ...$permissions): static
    {
        $result = $this->attachPermission($permissions);
        if (is_a($this->getOwnerType(), Role::class, true)) {
            $this->getPermissionManager()->clearAllRolesPermissionsCache();

            return $result;
        }

        // Clear owner cache when permissions are modified
        $this->getPermissionManager()->clearOwnerCache($this->getOwnerType(), $this->getKey());

        return $result;
    }

    /**
     * Give forbidden permission to the owner.
     */
    public function giveForbiddenTo(array|BackedEnum|int|string|UnitEnum ...$permissions): static
    {
        $result = $this->attachPermission($permissions, true);
        if (is_a($this->getOwnerType(), Role::class, true)) {
            $this->getPermissionManager()->clearAllRolesPermissionsCache();

            return $result;
        }

        // Clear owner cache when permissions are modified
        $this->getPermissionManager()->clearOwnerCache($this->getOwnerType(), $this->getKey());

        return $result;
    }

    /**
     * Revoke permission from the owner.
     */
    public function revokePermissionTo(array $permissions): static
    {
        $detachPermissions = $this->collectPermissions($permissions);

        $this->permissions()->detach($detachPermissions);

        // Clear owner cache when permissions are modified
        $this->getPermissionManager()->clearOwnerCache($this->getOwnerType(), $this->getKey());

        return $this;
    }

    /**
     * Synchronize the owner's permissions with the given permission list.
     */
    public function syncPermissions(array|BackedEnum|int|string|UnitEnum ...$permissions): array
    {
        $permissions = $this->collectPermissions($permissions);

        $result = $this->permissions()->sync($permissions);

        // Clear owner cache when permissions are modified
        $this->getPermissionManager()->clearOwnerCache($this->getOwnerType(), $this->getKey());

        return $result;
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
     *
     * @param array<BackedEnum|int|string|UnitEnum> $permissions
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
     *
     * @param array<BackedEnum|int|string|UnitEnum> $permissions
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
        $ownerPermissions = $this->getCachedPermissions();

        [$field, $value] = $this->normalizePermissionValue($permission);

        return $ownerPermissions
            ->where($field, $value)
            ->where('pivot.is_forbidden', true)
            ->isNotEmpty();
    }

    /**
     * Check if the owner has a forbidden permission via roles.
     */
    public function hasForbiddenPermissionViaRoles(BackedEnum|int|string|UnitEnum $permission): bool
    {
        if (is_a(static::class, Role::class, true)) {
            return false;
        }

        // Use cached all roles with permissions
        $manager = $this->getPermissionManager();
        $allRolesWithPermissions = $manager->getAllRolesWithPermissions();

        // Get cached roles (this method should be available if HasRole trait is used)
        if (method_exists($this, 'getCachedRoles')) {
            $ownerRoles = $this->getCachedRoles();
        } else {
            $this->loadMissing('roles');
            $ownerRoles = $this->roles;
        }
        [$field, $value] = $this->normalizePermissionValue($permission);

        foreach ($ownerRoles as $role) {
            $roleId = $role->id ?? $role['id'];
            if (isset($allRolesWithPermissions[$roleId])) {
                $rolePermissions = collect($allRolesWithPermissions[$roleId]['permissions']);
                if ($rolePermissions->where($field, $value)->where('pivot.is_forbidden', true)->isNotEmpty()) {
                    return true;
                }
            }
        }

        return false;
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
