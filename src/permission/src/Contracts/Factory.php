<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

use Hypervel\Cache\Contracts\Repository;

interface Factory
{
    public function getRoleClass();

    public function getPermissionClass();

    public function getCache(): ?Repository;

    public function getOwnerRolesCacheKey(string $ownerType, int|string $ownerId): string;

    public function getOwnerPermissionsCacheKey(string $ownerType, int|string $ownerId): string;

    public function getAllRolesWithPermissions(): array;

    public function cacheOwnerRoles(string $ownerType, int|string $ownerId, array $roles): void;

    public function cacheOwnerPermissions(string $ownerType, int|string $ownerId, array $permissions): void;

    public function getOwnerCachedRoles(string $ownerType, int|string $ownerId): ?array;

    public function getOwnerCachedPermissions(string $ownerType, int|string $ownerId): ?array;

    public function clearAllRolesPermissionsCache(): void;

    public function clearOwnerCache(string $ownerType, int|string $ownerId): void;
}
