<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use BackedEnum;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\PermissionManager;
use InvalidArgumentException;
use UnitEnum;

/**
 * Trait HasRole.
 *
 * This trait provides methods to check if a owner has a specific role
 * and to retrieve all roles assigned to the owner.
 *
 * @property-read Collection<Role> $roles
 */
trait HasRole
{
    private ?string $roleClass = null;

    public function getRoleClass(): string
    {
        if ($this->roleClass === null) {
            $this->roleClass = app(PermissionManager::class)->getRoleClass();
        }

        return $this->roleClass;
    }

    /**
     * A owner may have multiple roles.
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(
            $this->getRoleClass(),
            config('permission.table_names.owner_name', 'owner'),
            config('permission.table_names.owner_has_roles', 'owner_has_roles'),
            config('permission.column_names.owner_morph_key', 'owner_id'),
            config('permission.column_names.role_pivot_key', 'role_id')
        );
    }

    /**
     * Check if the owner has a specific role.
     */
    public function hasRole(BackedEnum|int|string|UnitEnum $role): bool
    {
        $this->loadMissing('roles');

        [$field, $value] = $this->normalizeRoleValue($role);

        return $this->roles->contains($field, $value);
    }

    /**
     * Normalize role value to field and value pair.
     */
    private function normalizeRoleValue(BackedEnum|int|string|UnitEnum $role): array
    {
        $value = $this->extractRoleValue($role);
        $isId = $this->isRoleIdType($role);

        return $isId
            ? [(new ($this->getRoleClass())())->getKeyName(), $value]
            : ['name', $value];
    }

    /**
     * Extract the actual value from a role of any supported type.
     */
    private function extractRoleValue(BackedEnum|int|string|UnitEnum $role): int|string
    {
        return match (true) {
            $role instanceof BackedEnum => $role->value,
            $role instanceof UnitEnum => $role->name,
            default => $role
        };
    }

    /**
     * Check if the role should be treated as an ID (int) rather than name (string).
     */
    private function isRoleIdType(BackedEnum|int|string|UnitEnum $role): bool
    {
        return match (true) {
            is_int($role) => true,
            $role instanceof BackedEnum => is_int($role->value),
            is_string($role), $role instanceof UnitEnum => false,
            default => throw new InvalidArgumentException('Invalid role type')
        };
    }

    /**
     * Separate roles array into IDs and names collections.
     */
    private function separateRolesByType(array $roles): array
    {
        $roleIds = collect();
        $roleNames = collect();

        foreach ($roles as $role) {
            $value = $this->extractRoleValue($role);

            if ($this->isRoleIdType($role)) {
                $roleIds->push($value);
            } else {
                $roleNames->push($value);
            }
        }

        return [$roleIds, $roleNames];
    }

    /**
     * Check if the owner has any of the specified roles.
     *
     * @param array<int, BackedEnum|int|string|UnitEnum> $roles
     */
    public function hasAnyRoles(array $roles): bool
    {
        $this->loadMissing('roles');

        return collect($roles)->some(fn ($role) => $this->hasRole($role));
    }

    /**
     * Check if the owner has all of the specified roles.
     *
     * @param array<int, BackedEnum|int|string|UnitEnum> $roles
     */
    public function hasAllRoles(array $roles): bool
    {
        $this->loadMissing('roles');

        return collect($roles)->every(fn ($role) => $this->hasRole($role));
    }

    /**
     * Get only the roles that match the specified roles from the owner's assigned roles.
     *
     * @param array<int, BackedEnum|int|string|UnitEnum> $roles
     */
    public function onlyRoles(array $roles): Collection
    {
        $this->loadMissing('roles');

        [$inputRoleIds, $inputRoleNames] = $this->separateRolesByType($roles);

        $keyName = (new ($this->getRoleClass())())->getKeyName();
        $currentRoleIds = $this->roles->pluck($keyName);
        $currentRoleNames = $this->roles->pluck('name');

        $intersectedIds = $currentRoleIds->intersect($inputRoleIds);
        $intersectedNames = $currentRoleNames->intersect($inputRoleNames);

        return $this->roles->filter(
            fn (Role $role) => $intersectedIds->contains($role->getKey()) || $intersectedNames->contains($role->name)
        )
            ->values();
    }

    /**
     * Assign roles to the owner.
     */
    public function assignRole(array|BackedEnum|int|string|UnitEnum ...$roles): static
    {
        $this->loadMissing('roles');
        $roles = $this->collectRoles($roles);

        $currentRoles = $this->roles->map(fn (Role $role) => $role->getKey())->toArray();
        $this->roles()->attach(array_diff($roles, $currentRoles));

        $this->unsetRelation('roles');

        return $this;
    }

    /**
     * Revoke the given role from owner.
     */
    public function removeRole(array|BackedEnum|int|string|UnitEnum ...$roles): static
    {
        $detachRoles = $this->collectRoles($roles);

        $this->roles()->detach($detachRoles);

        return $this;
    }

    /**
     * Synchronize the owner's roles with the given role list.
     */
    public function syncRoles(array|BackedEnum|int|string|UnitEnum ...$roles): static
    {
        $roles = $this->collectRoles($roles);

        $this->roles()->sync($roles);

        return $this;
    }

    /**
     * Returns array of role ids.
     */
    private function collectRoles(array|BackedEnum|int|string|UnitEnum ...$roles): array
    {
        $roles = collect($roles)
            ->flatten()
            ->values()
            ->all();
        [$roleIds, $roleNames] = $this->separateRolesByType($roles);

        $roleInstance = new ($this->getRoleClass())();
        $keyName = $roleInstance->getKeyName();
        $query = $roleInstance::query();
        $query->where(function (Builder $query) use ($keyName, $roleIds, $roleNames) {
            if ($roleIds->isNotEmpty()) {
                $query->orWhereIn($keyName, $roleIds);
            }

            if ($roleNames->isNotEmpty()) {
                $query->orWhereIn('name', $roleNames);
            }
        });

        return $query->pluck('id')->toArray();
    }
}
