<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Closure;
use Hypervel\Cache\CacheManager;
use Hypervel\Container\Container as BaseContainer;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Access\Authorizable;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\PermissionsTeamResolver;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Support\Arr;
use InvalidArgumentException;

class PermissionRegistrar
{
    public const MODEL_ROLES_CACHE_KEY_PREFIX = 'hypervel.permission.cache.model.roles';

    public const MODEL_PERMISSIONS_CACHE_KEY_PREFIX = 'hypervel.permission.cache.model.permissions';

    public const MODEL_CACHE_VERSION_KEY = 'hypervel.permission.cache.model.version';

    public const PERMISSION_CATALOG_CONTEXT_KEY = '__permission.catalog';

    public const WILDCARD_PERMISSION_INDEX_CONTEXT_KEY = '__permission.wildcard_index';

    protected string $permissionClass;

    protected string $roleClass;

    protected ?string $teamClass;

    public string $pivotRole;

    public string $pivotPermission;

    public int $cacheExpirationTime;

    public bool $teams;

    protected PermissionsTeamResolver $teamResolver;

    public string $teamsKey;

    public string $cacheKey;

    protected string $modelRolesCacheKeyPrefix;

    protected string $modelPermissionsCacheKeyPrefix;

    protected string $modelCacheVersionKey;

    protected ?string $cacheStoreName = null;

    /**
     * Create a new permission registrar.
     */
    public function __construct(
        protected CacheManager $cacheManager,
        protected ConfigRepository $config,
        protected Container $app,
    ) {
        $this->initializeCache();
    }

    /**
     * Initialize cache and config-backed registrar state.
     *
     * Boot or tests only. The values are stored on the singleton registrar and
     * affect every later permission lookup in this worker.
     */
    public function initializeCache(): void
    {
        /** @var class-string<Permission> $permissionClass */
        $permissionClass = $this->config->get('permission.models.permission', Permission::class);
        /** @var class-string<Role> $roleClass */
        $roleClass = $this->config->get('permission.models.role', Role::class);
        /** @var null|class-string<Model> $teamClass */
        $teamClass = $this->config->get('permission.models.team');
        /** @var class-string<PermissionsTeamResolver> $teamResolverClass */
        $teamResolverClass = $this->config->get('permission.team_resolver', DefaultTeamResolver::class);

        $this->permissionClass = $permissionClass;
        $this->roleClass = $roleClass;
        $this->teamClass = $teamClass;
        $this->teamResolver = $this->app->make($teamResolverClass);

        $this->cacheExpirationTime = $this->config->integer('permission.cache.expiration_seconds', 86400);
        $this->teams = $this->config->boolean('permission.teams', false);
        $this->teamsKey = $this->config->string('permission.column_names.team_foreign_key', 'team_id');

        $this->cacheKey = $this->config->string('permission.cache.keys.roles', 'hypervel.permission.cache.roles');
        $this->modelRolesCacheKeyPrefix = $this->config->string('permission.cache.keys.model_roles', self::MODEL_ROLES_CACHE_KEY_PREFIX);
        $this->modelPermissionsCacheKeyPrefix = $this->config->string('permission.cache.keys.model_permissions', self::MODEL_PERMISSIONS_CACHE_KEY_PREFIX);
        $this->modelCacheVersionKey = $this->config->string('permission.cache.keys.model_version', self::MODEL_CACHE_VERSION_KEY);

        $pivotRole = $this->config->get('permission.column_names.role_pivot_key');
        $pivotPermission = $this->config->get('permission.column_names.permission_pivot_key');
        $this->pivotRole = is_string($pivotRole) && $pivotRole !== '' ? $pivotRole : 'role_id';
        $this->pivotPermission = is_string($pivotPermission) && $pivotPermission !== '' ? $pivotPermission : 'permission_id';

        $cacheStore = $this->config->string('permission.cache.store', 'default');
        $this->cacheStoreName = $cacheStore === 'default' ? null : $cacheStore;

        $this->clearPermissionsCollection();
        $this->validateModelClasses();
    }

    /**
     * Validate the configured model classes.
     */
    protected function validateModelClasses(): void
    {
        if (! is_a($this->roleClass, RoleContract::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Role class "%s" must implement "%s" interface.',
                $this->roleClass,
                RoleContract::class,
            ));
        }

        if (! is_a($this->permissionClass, PermissionContract::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Permission class "%s" must implement "%s" interface.',
                $this->permissionClass,
                PermissionContract::class,
            ));
        }
    }

    /**
     * Get the configured cache repository.
     */
    protected function configuredCacheRepository(): Repository
    {
        return $this->cacheManager->store($this->cacheStoreName);
    }

    /**
     * Get the memoized cache repository for the current coroutine.
     */
    protected function cacheRepository(): Repository
    {
        return $this->cacheManager->memo($this->cacheStoreName);
    }

    /**
     * Set the current permissions team id.
     */
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        $this->teamResolver->setPermissionsTeamId($id);
    }

    /**
     * Get the current permissions team id.
     */
    public function getPermissionsTeamId(): int|string|null
    {
        return $this->teamResolver->getPermissionsTeamId();
    }

    /**
     * Register the permission check method on the gate.
     */
    public function registerPermissions(Gate $gate): bool
    {
        $gate->before(function (Authorizable $user, string $ability, array &$arguments = []) {
            $guard = null;

            if (is_string($arguments[0] ?? null) && ! class_exists($arguments[0])) {
                $guard = array_shift($arguments);
            }

            if (method_exists($user, 'checkPermissionTo')) {
                return $user->checkPermissionTo($ability, $guard) ?: null;
            }

            return null;
        });

        return true;
    }

    /**
     * Flush the permission cache.
     */
    public function forgetCachedPermissions(): bool
    {
        $this->clearPermissionsCollection();
        $this->bumpModelAssignmentCacheVersion();

        return $this->cacheRepository()->forget($this->cacheKey);
    }

    /**
     * Forget a model's direct role and permission assignment caches.
     */
    public function forgetModelAssignmentCache(Model $model): void
    {
        $cache = $this->cacheRepository();

        $cache->forget($this->modelCacheKey($this->modelRolesCacheKeyPrefix, $model));
        $cache->forget($this->modelCacheKey($this->modelPermissionsCacheKeyPrefix, $model));
    }

    /**
     * Forget a model's cached role assignments.
     */
    public function forgetModelRoleCache(Model $model): void
    {
        $this->cacheRepository()->forget(
            $this->modelCacheKey($this->modelRolesCacheKeyPrefix, $model)
        );
    }

    /**
     * Forget a model's cached permission assignments.
     */
    public function forgetModelPermissionCache(Model $model): void
    {
        $this->cacheRepository()->forget(
            $this->modelCacheKey($this->modelPermissionsCacheKeyPrefix, $model)
        );
    }

    /**
     * Remember a model's role assignment ids.
     *
     * @param Closure(): array<int, array<string, mixed>> $callback
     * @return array<int, array<string, mixed>>
     */
    public function rememberModelRoleAssignments(Model $model, Closure $callback): array
    {
        return $this->cacheRepository()->remember(
            $this->modelCacheKey($this->modelRolesCacheKeyPrefix, $model),
            $this->cacheExpirationTime,
            $callback,
        );
    }

    /**
     * Remember a model's permission assignment ids.
     *
     * @param Closure(): array<int, array<string, mixed>> $callback
     * @return array<int, array<string, mixed>>
     */
    public function rememberModelPermissionAssignments(Model $model, Closure $callback): array
    {
        return $this->cacheRepository()->remember(
            $this->modelCacheKey($this->modelPermissionsCacheKeyPrefix, $model),
            $this->cacheExpirationTime,
            $callback,
        );
    }

    /**
     * Build the cache key for model assignment caches.
     */
    protected function modelCacheKey(string $prefix, Model $model): string
    {
        $teamId = $this->teams ? (string) ($this->getPermissionsTeamId() ?? 'global') : 'none';

        return implode(':', [
            $prefix,
            $this->modelAssignmentCacheVersion(),
            $model->getMorphClass(),
            $model->getKey(),
            $teamId,
        ]);
    }

    /**
     * Get the current model assignment cache version.
     */
    public function modelAssignmentCacheVersion(): int
    {
        return $this->cacheRepository()->rememberForever($this->modelCacheVersionKey, fn () => 1);
    }

    /**
     * Bump the model assignment cache version.
     */
    public function bumpModelAssignmentCacheVersion(): int
    {
        $cache = $this->cacheRepository();
        $cache->add($this->modelCacheVersionKey, 1);

        $version = $cache->increment($this->modelCacheVersionKey);

        if (is_int($version)) {
            return $version;
        }

        $version = ((int) $cache->get($this->modelCacheVersionKey, 1)) + 1;
        $cache->forever($this->modelCacheVersionKey, $version);

        return $version;
    }

    /**
     * Forget the cached wildcard permission index.
     */
    public function forgetWildcardPermissionIndex(?Model $record = null): void
    {
        $indexes = CoroutineContext::get(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, []);

        if ($record) {
            unset($indexes[$this->wildcardPermissionIndexKey($record)]);
            CoroutineContext::set(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, $indexes);

            return;
        }

        CoroutineContext::forget(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY);
    }

    /**
     * Get the wildcard permission index for a model.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getWildcardPermissionIndex(Model $record): array
    {
        $key = $this->wildcardPermissionIndexKey($record);
        $indexes = CoroutineContext::get(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, []);

        if (isset($indexes[$key])) {
            return $indexes[$key];
        }

        $getWildcardClass = Closure::fromCallable([$record, 'getWildcardClass']);

        /** @var array<string, array<string, mixed>> $index */
        $index = $this->app->make($getWildcardClass(), ['record' => $record])->getIndex();

        $indexes[$key] = $index;
        CoroutineContext::set(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, $indexes);

        return $index;
    }

    /**
     * Build the coroutine-local wildcard permission index key.
     */
    protected function wildcardPermissionIndexKey(Model $record): string
    {
        $teamId = $this->teams ? (string) ($this->getPermissionsTeamId() ?? 'global') : 'none';

        return implode(':', [
            $this->modelAssignmentCacheVersion(),
            $record->getMorphClass(),
            $record->getKey(),
            $teamId,
        ]);
    }

    /**
     * Clear already-loaded permissions collection.
     */
    public function clearPermissionsCollection(): void
    {
        CoroutineContext::forget(self::PERMISSION_CATALOG_CONTEXT_KEY);
        CoroutineContext::forget(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY);
    }

    /**
     * Get the hydrated permission catalog.
     *
     * @return array{permissions: Collection, roles: Collection}
     */
    private function permissionCatalog(): array
    {
        $catalog = CoroutineContext::get(self::PERMISSION_CATALOG_CONTEXT_KEY);

        if (is_array($catalog)) {
            return $catalog;
        }

        /** @var array{permissions: array<int, array<string, mixed>>, roles: array<int, array<string, mixed>>} $payload */
        $payload = $this->cacheRepository()->remember(
            $this->cacheKey,
            $this->cacheExpirationTime,
            fn () => $this->getSerializedPermissionsForCache(),
        );

        $roles = $this->getHydratedRoleCollection($payload['roles']);

        $catalog = [
            'roles' => $roles,
            'permissions' => $this->getHydratedPermissionCollection($payload['permissions'], $roles),
        ];

        CoroutineContext::set(self::PERMISSION_CATALOG_CONTEXT_KEY, $catalog);

        return $catalog;
    }

    /**
     * Get the permissions based on the passed params.
     *
     * @param array<string, mixed> $params
     */
    public function getPermissions(array $params = [], bool $onlyOne = false, ?string $permissionClass = null): Collection
    {
        if ($permissionClass !== null && $permissionClass !== $this->permissionClass) {
            return $this->filterModels(
                $this->getPermissionsWithRoles($permissionClass),
                $params,
                $onlyOne,
            );
        }

        $permissions = $this->permissionCatalog()['permissions'];

        return $this->filterModels($permissions, $params, $onlyOne);
    }

    /**
     * Get the roles based on the passed params.
     *
     * @param array<string, mixed> $params
     */
    public function getRoles(array $params = [], bool $onlyOne = false, ?string $roleClass = null): Collection
    {
        if ($roleClass !== null && $roleClass !== $this->roleClass) {
            return $this->filterModels(
                $roleClass::select()->get(),
                $params,
                $onlyOne,
            );
        }

        $roles = $this->permissionCatalog()['roles'];

        return $this->filterModels($roles, $params, $onlyOne);
    }

    /**
     * Filter a model collection by attributes.
     *
     * @param array<string, mixed> $params
     */
    protected function filterModels(Collection $models, array $params, bool $onlyOne): Collection
    {
        $method = $onlyOne ? 'first' : 'filter';

        $result = $models->{$method}(static function (Model $model) use ($params): bool {
            return array_all($params, fn ($value, $attribute) => self::attributeMatches(
                $model->getAttribute($attribute),
                $value,
            ));
        });

        if ($onlyOne) {
            return new Collection($result ? [$result] : []);
        }

        return $result;
    }

    /**
     * Determine if an attribute matches a requested value.
     */
    protected static function attributeMatches(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected)) {
            return array_any($expected, fn ($value): bool => self::attributeMatches($actual, $value));
        }

        if ($actual === $expected) {
            return true;
        }

        return (is_int($actual) || is_string($actual))
            && (is_int($expected) || is_string($expected))
            && (string) $actual === (string) $expected;
    }

    /**
     * Get the permission model class.
     *
     * @return class-string<Permission>
     */
    public function getPermissionClass(): string
    {
        return $this->permissionClass;
    }

    /**
     * Set the permission model class.
     *
     * Boot or tests only. The model class is stored on the singleton registrar
     * and affects every later permission lookup in this worker.
     *
     * @param class-string<Permission> $permissionClass
     */
    public function setPermissionClass(string $permissionClass): static
    {
        $this->permissionClass = $permissionClass;
        $this->app->bind(PermissionContract::class, $permissionClass);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Get the role model class.
     *
     * @return class-string<Role>
     */
    public function getRoleClass(): string
    {
        return $this->roleClass;
    }

    /**
     * Set the role model class.
     *
     * Boot or tests only. The model class is stored on the singleton registrar
     * and affects every later role lookup in this worker.
     *
     * @param class-string<Role> $roleClass
     */
    public function setRoleClass(string $roleClass): static
    {
        $this->roleClass = $roleClass;
        $this->app->bind(RoleContract::class, $roleClass);
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Get the team model class.
     *
     * @return null|class-string<Model>
     */
    public function getTeamClass(): ?string
    {
        return $this->teamClass;
    }

    /**
     * Set the team model class.
     *
     * Boot or tests only. The model class is stored on the singleton registrar
     * and affects every later team permission lookup in this worker.
     *
     * @param null|class-string<Model> $teamClass
     */
    public function setTeamClass(?string $teamClass): static
    {
        $this->teamClass = $teamClass;
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Get the cache repository.
     */
    public function getCacheRepository(): Repository
    {
        return $this->configuredCacheRepository();
    }

    /**
     * Get the cache store.
     */
    public function getCacheStore(): Store
    {
        return $this->configuredCacheRepository()->getStore();
    }

    /**
     * Get permissions with their roles.
     */
    protected function getPermissionsWithRoles(?string $permissionClass = null): Collection
    {
        $permissionClass ??= $this->permissionClass;

        return $permissionClass::select()->with('roles')->get();
    }

    /**
     * Get roles for cache.
     */
    protected function getRolesForCache(): Collection
    {
        return $this->roleClass::select()->get();
    }

    /**
     * Serialize permissions for cache.
     *
     * @return array{permissions: array<int, array<string, mixed>>, roles: array<int, array<string, mixed>>}
     */
    private function getSerializedPermissionsForCache(): array
    {
        $except = $this->config->array('permission.cache.column_names_except', ['created_at', 'updated_at', 'deleted_at']);

        return [
            'permissions' => $this->getPermissionsWithRoles()
                ->map(fn (Model $permission): array => [
                    'attributes' => Arr::except($permission->getAttributes(), $except),
                    'roles' => $this->relationCollection($permission, 'roles')
                        ->map(fn (Model $role): array => [
                            'pivot' => [
                                $this->pivotPermission => $permission->getKey(),
                                $this->pivotRole => $role->getKey(),
                                'is_forbidden' => $this->pivotIsForbidden($role),
                            ],
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'roles' => $this->getRolesForCache()
                ->map(fn (Model $role): array => [
                    'attributes' => Arr::except($role->getAttributes(), $except),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Determine if a hydrated pivot marks the permission as forbidden.
     */
    protected function pivotIsForbidden(Model $model): bool
    {
        $pivot = $model->getRelation('pivot');

        return $pivot instanceof Pivot && (bool) $pivot->getAttribute('is_forbidden');
    }

    /**
     * Get a hydrated relation collection.
     */
    protected function relationCollection(Model $model, string $relation): Collection
    {
        $value = $model->getRelation($relation);

        return $value instanceof Collection ? $value : new Collection;
    }

    /**
     * Get the hydrated permission collection.
     *
     * @param array<int, array<string, mixed>> $permissions
     */
    private function getHydratedPermissionCollection(array $permissions, Collection $roles): Collection
    {
        $permissionInstance = (new ($this->getPermissionClass())())->newInstance([], true);

        return Collection::make(array_map(
            fn (array $item) => (clone $permissionInstance)
                ->setRawAttributes((array) $item['attributes'], true)
                ->setRelation('roles', $this->getHydratedPermissionRoleCollection((array) $item['roles'], $permissionInstance, $roles)),
            $permissions,
        ));
    }

    /**
     * Get the hydrated role collection.
     *
     * @param array<int, array<string, mixed>> $roles
     */
    private function getHydratedRoleCollection(array $roles): Collection
    {
        $roleInstance = (new ($this->getRoleClass())())->newInstance([], true);

        return Collection::make(array_map(
            fn (array $item): Model => (clone $roleInstance)->setRawAttributes((array) $item['attributes'], true),
            $roles,
        ));
    }

    /**
     * Get the hydrated role collection for a cached permission.
     *
     * @param array<int, array<string, mixed>> $roles
     */
    private function getHydratedPermissionRoleCollection(array $roles, Model $permission, Collection $roleCatalog): Collection
    {
        return Collection::make(array_values(array_filter(array_map(function (array $item) use ($permission, $roleCatalog): ?Model {
            $role = $roleCatalog->first(fn (Model $role): bool => self::attributeMatches(
                $role->getKey(),
                $item['pivot'][$this->pivotRole] ?? null,
            ));

            if (! $role) {
                return null;
            }

            $role = clone $role;
            $role->setRelation('pivot', Pivot::fromRawAttributes(
                $permission,
                (array) $item['pivot'],
                $this->config->string('permission.table_names.role_has_permissions'),
                true,
            ));

            return $role;
        }, $roles))));
    }

    /**
     * Determine if a value is a UUID or ULID.
     */
    public static function isUid(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $uid = preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;

        if ($uid) {
            return true;
        }

        return strlen($value) === 26
            && strspn($value, '0123456789ABCDEFGHJKMNPQRSTVWXYZabcdefghjkmnpqrstvwxyz') === 26
            && $value[0] <= '7';
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        $app = BaseContainer::getInstance();

        if ($app->bound(self::class)) {
            $app->forgetInstance(self::class);
        }
    }
}
