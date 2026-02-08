<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Contracts\Cache\Factory as CacheManager;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class PermissionManager implements Contracts\Factory
{
    protected ?string $roleClass = null;

    protected ?string $permissionClass = null;

    protected ?Repository $cache = null;

    protected ?int $cacheTtl = null;

    protected ?string $allRolesPermissionsCacheKey = null;

    protected ?string $ownerRolesCacheKeyPrefix = null;

    protected ?string $ownerPermissionsCacheKeyPrefix = null;

    public function __construct(
        protected ContainerInterface $app,
        protected CacheManager $cacheManager
    ) {
        $this->roleClass = $this->getConfig('models.role') ?: Role::class;
        $this->permissionClass = $this->getConfig('models.permission') ?: Permission::class;
        $this->initializeCache();
        $this->validateModelClasses();
    }

    /**
     * Validate that model classes implement required interfaces.
     *
     * @throws InvalidArgumentException When model classes do not implement required interfaces
     */
    protected function validateModelClasses(): void
    {
        if (! is_a($this->roleClass, Contracts\Role::class, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Role class "%s" must implement "%s" interface',
                    $this->roleClass,
                    Contracts\Role::class
                )
            );
        }

        if (! is_a($this->permissionClass, Contracts\Permission::class, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Permission class "%s" must implement "%s" interface',
                    $this->permissionClass,
                    Contracts\Permission::class
                )
            );
        }
    }

    public function getRoleClass(): string
    {
        return $this->roleClass;
    }

    public function getPermissionClass(): string
    {
        return $this->permissionClass;
    }

    protected function initializeCache(): void
    {
        $this->cache = $this->getCacheStoreFromConfig();
        $this->cacheTtl = $this->getConfig('cache.expiration_seconds');
        $this->allRolesPermissionsCacheKey = $this->getConfig('cache.keys.roles');
        $this->ownerRolesCacheKeyPrefix = $this->getConfig('cache.keys.owner_roles');
        $this->ownerPermissionsCacheKeyPrefix = $this->getConfig('cache.keys.owner_permissions');
    }

    protected function getConfig(string $name): mixed
    {
        return $this->app->get(ConfigRepository::class)->get("permission.{$name}");
    }

    public function getCache(): Repository
    {
        if ($this->cache === null) {
            $this->cache = $this->getCacheStoreFromConfig();
        }

        return $this->cache;
    }

    protected function getCacheStoreFromConfig(): Repository
    {
        // the 'default' fallback here is from the permission.php config file,
        // where 'default' means to use config(cache.default)
        $cacheDriver = $this->getConfig('cache.store') ?? 'default';

        // when 'default' is specified, no action is required since we already have the default instance
        if ($cacheDriver === 'default') {
            return $this->cacheManager->store();
        }

        // if an undefined cache store is specified, fallback to 'array' which is Laravel's closest equiv to 'none'
        if (! array_key_exists($cacheDriver, config('cache.stores'))) {
            $cacheDriver = 'array';
        }

        return $this->cacheManager->store($cacheDriver);
    }

    /**
     * Generate cache key for owner's roles.
     */
    public function getOwnerRolesCacheKey(string $ownerType, int|string $ownerId): string
    {
        return "{$this->ownerRolesCacheKeyPrefix}:{$ownerType}:{$ownerId}";
    }

    /**
     * Generate cache key for owner's permissions.
     */
    public function getOwnerPermissionsCacheKey(string $ownerType, int|string $ownerId): string
    {
        return "{$this->ownerPermissionsCacheKeyPrefix}:{$ownerType}:{$ownerId}";
    }

    /**
     * Get all roles with their permissions from cache or database.
     */
    public function getAllRolesWithPermissions(): array
    {
        $cache = $this->getCache();

        return $cache->remember($this->allRolesPermissionsCacheKey, $this->cacheTtl, function () {
            $roleClass = $this->getRoleClass();

            return $roleClass::with('permissions')->get()->mapWithKeys(function ($role) {
                return [
                    $role->id => [
                        'role' => $role->toArray(),
                        'permissions' => $role->permissions->toArray(),
                    ],
                ];
            })->toArray();
        });
    }

    /**
     * Cache only owner's roles.
     */
    public function cacheOwnerRoles(string $ownerType, int|string $ownerId, array $roles): void
    {
        $cache = $this->getCache();

        $rolesCacheKey = $this->getOwnerRolesCacheKey($ownerType, $ownerId);
        $cache->put($rolesCacheKey, $roles, $this->cacheTtl);
    }

    /**
     * Cache only owner's permissions.
     *
     * @param array<Contracts\Permission> $permissions
     */
    public function cacheOwnerPermissions(string $ownerType, int|string $ownerId, array $permissions): void
    {
        $cache = $this->getCache();

        $permissionsCacheKey = $this->getOwnerPermissionsCacheKey($ownerType, $ownerId);
        $cache->put($permissionsCacheKey, $permissions, $this->cacheTtl);
    }

    /**
     * Get owner's cached roles data.
     */
    public function getOwnerCachedRoles(string $ownerType, int|string $ownerId): ?array
    {
        $cache = $this->getCache();
        $cacheKey = $this->getOwnerRolesCacheKey($ownerType, $ownerId);

        return $cache->get($cacheKey);
    }

    /**
     * Get owner's cached permissions data.
     */
    public function getOwnerCachedPermissions(string $ownerType, int|string $ownerId): ?array
    {
        $cache = $this->getCache();
        $cacheKey = $this->getOwnerPermissionsCacheKey($ownerType, $ownerId);

        return $cache->get($cacheKey);
    }

    /**
     * Clear all roles and permissions cache.
     */
    public function clearAllRolesPermissionsCache(): void
    {
        $cache = $this->getCache();
        $cache->forget($this->allRolesPermissionsCacheKey);
    }

    /**
     * Clear specific owner's cache.
     */
    public function clearOwnerCache(string $ownerType, int|string $ownerId): void
    {
        $cache = $this->getCache();

        // Clear separate role and permission caches
        $cache->forget($this->getOwnerRolesCacheKey($ownerType, $ownerId));
        $cache->forget($this->getOwnerPermissionsCacheKey($ownerType, $ownerId));
    }
}
