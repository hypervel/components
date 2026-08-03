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
use Hypervel\Permission\Exceptions\PermissionPartitionAlreadyConfigured;
use Hypervel\Permission\Exceptions\PermissionPartitionModelNotSupported;
use Hypervel\Permission\Exceptions\PermissionPartitionNotResolved;
use Hypervel\Permission\Exceptions\PermissionPartitionViolation;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Str;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;
use WeakMap;
use WeakReference;

use function Hypervel\Support\enum_value;

class PermissionRegistrar
{
    public const MODEL_ROLES_CACHE_KEY_PREFIX = 'hypervel.permission.cache.model.roles';

    public const MODEL_PERMISSIONS_CACHE_KEY_PREFIX = 'hypervel.permission.cache.model.permissions';

    public const MODEL_CACHE_TOKEN_KEY = 'hypervel.permission.cache.model.token';

    public const PERMISSION_CATALOG_CONTEXT_KEY = '__permission.catalog';

    public const WILDCARD_PERMISSION_INDEX_CONTEXT_KEY = '__permission.wildcard_index';

    public const MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY = '__permission.model_via_role_permissions';

    protected static ?string $partitionColumn = null;

    /** @var null|Closure(): (null|int|string) */
    protected static ?Closure $partitionResolver = null;

    protected static bool $initialized = false;

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

    protected string $modelCacheTokenKey;

    protected ?string $cacheStoreName = null;

    /**
     * @var WeakMap<Model, array<string, array{collection: WeakReference, context: PermissionRelationContext}>>
     */
    protected WeakMap $loadedRelationProvenance;

    /**
     * Create a new permission registrar.
     */
    public function __construct(
        protected CacheManager $cacheManager,
        protected ConfigRepository $config,
        protected Container $app,
    ) {
        static::$initialized = true;
        $this->loadedRelationProvenance = new WeakMap;
        $this->initializeCache();
    }

    /**
     * Configure the permission row partition resolver.
     *
     * Register before resolving the Permission registrar or Gate.
     *
     * Boot-only. The column and callback persist in static properties for the
     * worker lifetime and affect every subsequent permission operation.
     *
     * @param Closure(): (null|int|string) $resolver
     */
    public static function resolvePartitionUsing(string $column, Closure $resolver): void
    {
        if (static::$initialized || static::$partitionResolver !== null) {
            throw PermissionPartitionAlreadyConfigured::create();
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Permission partition column "%s" must be a simple SQL identifier.',
                $column,
            ));
        }

        static::$partitionColumn = $column;
        static::$partitionResolver = $resolver;
    }

    /**
     * Determine whether permission row partitioning is configured.
     */
    public static function partitioningEnabled(): bool
    {
        return static::$partitionResolver !== null;
    }

    /**
     * Get the configured permission partition column.
     */
    public static function partitionColumn(): ?string
    {
        return static::$partitionColumn;
    }

    /**
     * Resolve the current permission partition.
     */
    public function resolvePartition(): ?PermissionPartition
    {
        if (! static::$partitionResolver || ! static::$partitionColumn) {
            return null;
        }

        $value = (static::$partitionResolver)();

        if ($value === null || $value === '') {
            throw PermissionPartitionNotResolved::forColumn(static::$partitionColumn);
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new UnexpectedValueException(sprintf(
                'Permission partition resolver for column "%s" returned %s; expected int, string, or null.',
                static::$partitionColumn,
                get_debug_type($value),
            ));
        }

        return new PermissionPartition(static::$partitionColumn, $value);
    }

    /**
     * Resolve a permission partition from a persisted record.
     */
    public function partitionFromRecord(Model $model): PermissionPartition
    {
        $column = static::$partitionColumn;

        if ($column === null) {
            throw new LogicException('Permission row partitioning is not configured.');
        }

        $original = $model->getRawOriginal();

        if (array_key_exists($column, $original)) {
            $value = $original[$column];
        } elseif ($model->wasRecentlyCreated) {
            // Eloquent fires "saved" before synchronizing a newly inserted model's original attributes.
            $attributes = $model->getAttributes();
            $value = array_key_exists($column, $attributes) ? $attributes[$column] : null;
        } else {
            $value = null;
        }

        if ((! is_int($value) && ! is_string($value)) || $value === '') {
            throw PermissionPartitionViolation::forMissingRecordPartition($model, $column, $value);
        }

        return new PermissionPartition($column, $value);
    }

    /**
     * Ensure a model belongs to the captured permission partition.
     */
    public function ensureModelMatchesPartition(Model $model, PermissionPartition $partition): void
    {
        $attributes = $model->getAttributes();
        $actual = array_key_exists($partition->column, $attributes)
            ? $attributes[$partition->column]
            : null;

        if (! $partition->matches($actual)) {
            throw PermissionPartitionViolation::forModel($model, $partition, $actual);
        }
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
        $permissionClass = $this->config->string('permission.models.permission', Permission::class);
        /** @var class-string<Role> $roleClass */
        $roleClass = $this->config->string('permission.models.role', Role::class);
        /** @var null|class-string<Model> $teamClass */
        $teamClass = $this->config->get('permission.models.team');
        /** @var class-string<PermissionsTeamResolver> $teamResolverClass */
        $teamResolverClass = $this->config->string('permission.team_resolver', DefaultTeamResolver::class);

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
        $this->modelCacheTokenKey = $this->config->string('permission.cache.keys.model_token', self::MODEL_CACHE_TOKEN_KEY);

        $pivotRole = $this->config->get('permission.column_names.role_pivot_key');
        $pivotPermission = $this->config->get('permission.column_names.permission_pivot_key');
        $this->pivotRole = is_string($pivotRole) && $pivotRole !== '' ? $pivotRole : 'role_id';
        $this->pivotPermission = is_string($pivotPermission) && $pivotPermission !== '' ? $pivotPermission : 'permission_id';

        $cacheStore = $this->config->string('permission.cache.store', 'default');
        $this->cacheStoreName = $cacheStore === 'default' ? null : $cacheStore;

        $this->clearAllPermissionRuntimeState();
        $this->validateModelClasses();
        $this->validateCacheColumnExclusions();
    }

    /**
     * Validate the configured model classes.
     */
    protected function validateModelClasses(): void
    {
        $this->validateRoleClass($this->roleClass);
        $this->validatePermissionClass($this->permissionClass);
    }

    /**
     * Validate that cache serialization retains required model columns.
     */
    protected function validateCacheColumnExclusions(): void
    {
        $except = $this->config->array('permission.cache.column_names_except', ['created_at', 'updated_at', 'deleted_at']);
        $partitionColumn = static::partitionColumn();
        $roleColumns = [(new $this->roleClass)->getKeyName(), 'name', 'guard_name'];
        $permissionColumns = [(new $this->permissionClass)->getKeyName(), 'name', 'guard_name'];

        if ($this->teams) {
            $roleColumns[] = $this->teamsKey;
        }

        if ($partitionColumn !== null) {
            $roleColumns[] = $partitionColumn;
            $permissionColumns[] = $partitionColumn;
        }

        $excludedRoleColumns = array_values(array_intersect($roleColumns, $except));
        $excludedPermissionColumns = array_values(array_intersect($permissionColumns, $except));

        if ($excludedRoleColumns === [] && $excludedPermissionColumns === []) {
            return;
        }

        $violations = [];

        if ($excludedRoleColumns !== []) {
            $violations[] = 'role columns [' . implode(', ', $excludedRoleColumns) . ']';
        }

        if ($excludedPermissionColumns !== []) {
            $violations[] = 'permission columns [' . implode(', ', $excludedPermissionColumns) . ']';
        }

        throw new InvalidArgumentException(
            'Permission cache column exclusions cannot contain required ' . implode(' or ', $violations) . '.'
        );
    }

    /**
     * Validate a configured role model class.
     *
     * @param class-string $roleClass
     */
    protected function validateRoleClass(string $roleClass): void
    {
        if (! is_a($roleClass, RoleContract::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Role class "%s" must implement "%s" interface.',
                $roleClass,
                RoleContract::class,
            ));
        }

        if (static::partitioningEnabled() && ! is_a($roleClass, Role::class, true)) {
            throw PermissionPartitionModelNotSupported::forModel($roleClass, Role::class);
        }
    }

    /**
     * Validate a configured permission model class.
     *
     * @param class-string $permissionClass
     */
    protected function validatePermissionClass(string $permissionClass): void
    {
        if (! is_a($permissionClass, PermissionContract::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Permission class "%s" must implement "%s" interface.',
                $permissionClass,
                PermissionContract::class,
            ));
        }

        if (static::partitioningEnabled() && ! is_a($permissionClass, Permission::class, true)) {
            throw PermissionPartitionModelNotSupported::forModel($permissionClass, Permission::class);
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
        return $this->forgetCachedPermissionsFor($this->resolvePartition());
    }

    /**
     * Flush the permission cache for an explicit partition.
     */
    public function forgetCachedPermissionsFor(?PermissionPartition $partition): bool
    {
        $this->clearPermissionRuntimeStateFor($partition);
        $this->bumpModelAssignmentCacheTokenFor($partition);

        return $this->cacheRepository()->forget(
            $this->partitionedCacheKey($this->cacheKey, $partition),
        );
    }

    /**
     * Forget a model's direct role and permission assignment caches.
     */
    public function forgetModelAssignmentCache(Model $model): void
    {
        $this->forgetModelAssignmentCacheFor(
            $model,
            $this->resolvePartition(),
            $this->teams ? $this->getPermissionsTeamId() : null,
        );
    }

    /**
     * Forget a model's assignment caches for an explicit partition and team.
     */
    public function forgetModelAssignmentCacheFor(
        Model $model,
        ?PermissionPartition $partition,
        int|string|null $team,
    ): void {
        $this->forgetModelAssignmentCacheForIdentity(
            $model->getMorphClass(),
            (string) $model->getKey(),
            $partition,
            $team,
        );
    }

    /**
     * Forget assignment caches for an explicit morph identity, partition, and team.
     */
    public function forgetModelAssignmentCacheForIdentity(
        string $morphType,
        int|string $modelKey,
        ?PermissionPartition $partition,
        int|string|null $team,
    ): void {
        $cache = $this->cacheRepository();

        $cache->forget($this->modelCacheKeyForIdentity(
            $this->modelRolesCacheKeyPrefix,
            $morphType,
            $modelKey,
            $partition,
            $team,
        ));
        $cache->forget($this->modelCacheKeyForIdentity(
            $this->modelPermissionsCacheKeyPrefix,
            $morphType,
            $modelKey,
            $partition,
            $team,
        ));

        $runtimeKey = $this->modelRuntimeCacheKeyForIdentity(
            $morphType,
            $modelKey,
            $partition,
            $team,
        );

        $this->forgetRuntimeCacheItem(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, $runtimeKey);
        $this->forgetRuntimeCacheItem(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, $runtimeKey);
    }

    /**
     * Forget a model's cached role assignments.
     */
    public function forgetModelRoleCache(Model $model): void
    {
        $this->forgetModelRoleCacheFor(
            $model,
            $this->resolvePartition(),
            $this->teams ? $this->getPermissionsTeamId() : null,
        );
    }

    /**
     * Forget a model's role assignment cache for an explicit partition and team.
     */
    public function forgetModelRoleCacheFor(
        Model $model,
        ?PermissionPartition $partition,
        int|string|null $team,
    ): void {
        $this->cacheRepository()->forget($this->modelCacheKeyForIdentity(
            $this->modelRolesCacheKeyPrefix,
            $model->getMorphClass(),
            (string) $model->getKey(),
            $partition,
            $team,
        ));

        $runtimeKey = $this->modelRuntimeCacheKeyForIdentity(
            $model->getMorphClass(),
            (string) $model->getKey(),
            $partition,
            $team,
        );

        $this->forgetRuntimeCacheItem(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, $runtimeKey);
        $this->forgetRuntimeCacheItem(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, $runtimeKey);
    }

    /**
     * Forget a model's cached permission assignments.
     */
    public function forgetModelPermissionCache(Model $model): void
    {
        $this->forgetModelPermissionCacheFor(
            $model,
            $this->resolvePartition(),
            $this->teams ? $this->getPermissionsTeamId() : null,
        );
    }

    /**
     * Forget a model's permission assignment cache for an explicit partition and team.
     */
    public function forgetModelPermissionCacheFor(
        Model $model,
        ?PermissionPartition $partition,
        int|string|null $team,
    ): void {
        $this->cacheRepository()->forget($this->modelCacheKeyForIdentity(
            $this->modelPermissionsCacheKeyPrefix,
            $model->getMorphClass(),
            (string) $model->getKey(),
            $partition,
            $team,
        ));

        $runtimeKey = $this->modelRuntimeCacheKeyForIdentity(
            $model->getMorphClass(),
            (string) $model->getKey(),
            $partition,
            $team,
        );

        $this->forgetRuntimeCacheItem(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, $runtimeKey);
    }

    /**
     * Remember a model's permissions granted through roles.
     *
     * @param Closure(): BaseCollection $callback
     */
    public function rememberModelViaRolePermissions(Model $model, Closure $callback): BaseCollection
    {
        $key = $this->modelRuntimeCacheKey($model);
        $items = CoroutineContext::get(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, []);

        if (isset($items[$key]) && $items[$key] instanceof BaseCollection) {
            return $items[$key];
        }

        $items[$key] = $callback();
        CoroutineContext::set(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, $items);

        return $items[$key];
    }

    /**
     * Forget a model's permissions granted through roles.
     */
    public function forgetModelViaRolePermissions(Model $model): void
    {
        $this->forgetRuntimeCacheItem(
            self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY,
            $this->modelRuntimeCacheKey($model),
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
     * @param Closure(): array<int, array{is_denied: bool, ...<string, int|string>}> $callback
     * @return array<int, array{is_denied: bool, ...<string, int|string>}>
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
        return $this->modelCacheKeyForIdentity(
            $prefix,
            $model->getMorphClass(),
            (string) $model->getKey(),
            $this->resolvePartition(),
            $this->teams ? $this->getPermissionsTeamId() : null,
        );
    }

    /**
     * Build an assignment cache key for an explicit identity and context.
     */
    protected function modelCacheKeyForIdentity(
        string $prefix,
        string $morphType,
        int|string $modelKey,
        ?PermissionPartition $partition,
        int|string|null $team,
    ): string {
        return implode(':', [
            $this->partitionedCacheKey($prefix, $partition),
            PermissionPartition::encodeCacheSegment($this->modelAssignmentCacheToken($partition)),
            PermissionPartition::encodeCacheSegment($morphType),
            PermissionPartition::encodeCacheSegment($modelKey),
            PermissionPartition::encodeCacheSegment($this->teams ? $team : null),
        ]);
    }

    /**
     * Build the runtime cache key segment for coroutine-local model state.
     */
    protected function modelRuntimeCacheKey(Model $model): string
    {
        return $this->modelRuntimeCacheKeyForIdentity(
            $model->getMorphClass(),
            (string) $model->getKey(),
            $this->resolvePartition(),
            $this->teams ? $this->getPermissionsTeamId() : null,
        );
    }

    /**
     * Build a coroutine-local cache key for an explicit identity and context.
     */
    protected function modelRuntimeCacheKeyForIdentity(
        string $morphType,
        int|string $modelKey,
        ?PermissionPartition $partition,
        int|string|null $team,
    ): string {
        return implode(':', [
            $this->partitionRuntimePrefix($partition),
            PermissionPartition::encodeCacheSegment($this->modelAssignmentCacheToken($partition)),
            PermissionPartition::encodeCacheSegment($morphType),
            PermissionPartition::encodeCacheSegment($modelKey),
            PermissionPartition::encodeCacheSegment($this->teams ? $team : null),
        ]);
    }

    /**
     * Get the current model assignment cache token.
     */
    public function modelAssignmentCacheToken(?PermissionPartition $partition = null): string
    {
        $partition = $this->resolvedPartitionArgument($partition);

        return $this->cacheRepository()->rememberForever(
            $this->partitionedCacheKey($this->modelCacheTokenKey, $partition),
            fn (): string => $this->newModelAssignmentCacheToken(),
        );
    }

    /**
     * Bump the model assignment cache token.
     */
    public function bumpModelAssignmentCacheToken(): string
    {
        return $this->bumpModelAssignmentCacheTokenFor($this->resolvePartition());
    }

    /**
     * Bump the model assignment cache token for an explicit partition.
     */
    public function bumpModelAssignmentCacheTokenFor(?PermissionPartition $partition): string
    {
        $partition = $this->resolvedPartitionArgument($partition);
        $token = $this->newModelAssignmentCacheToken();

        $this->cacheRepository()->forever(
            $this->partitionedCacheKey($this->modelCacheTokenKey, $partition),
            $token,
        );

        return $token;
    }

    /**
     * Create a new model assignment cache namespace token.
     */
    protected function newModelAssignmentCacheToken(): string
    {
        return (string) Str::ulid();
    }

    /**
     * Forget the cached wildcard permission index.
     */
    public function forgetWildcardPermissionIndex(?Model $record = null): void
    {
        if ($record) {
            $this->forgetRuntimeCacheItem(
                self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY,
                $this->wildcardPermissionIndexKey($record),
            );

            return;
        }

        if (! static::partitioningEnabled()) {
            CoroutineContext::forget(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY);

            return;
        }

        $this->forgetRuntimeCacheItemsForPartition(
            self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY,
            $this->resolvePartition(),
        );
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
        return $this->modelRuntimeCacheKey($record);
    }

    /**
     * Forget one item from a coroutine-local permission cache.
     */
    protected function forgetRuntimeCacheItem(string $contextKey, string $itemKey): void
    {
        $items = CoroutineContext::get($contextKey, []);
        unset($items[$itemKey]);
        CoroutineContext::set($contextKey, $items);
    }

    /**
     * Forget coroutine-local permission cache items for a partition.
     */
    protected function forgetRuntimeCacheItemsForPartition(
        string $contextKey,
        ?PermissionPartition $partition,
    ): void {
        $items = CoroutineContext::get($contextKey, []);
        $prefix = $this->partitionRuntimePrefix($partition) . ':';

        foreach (array_keys($items) as $itemKey) {
            if (is_string($itemKey) && str_starts_with($itemKey, $prefix)) {
                unset($items[$itemKey]);
            }
        }

        CoroutineContext::set($contextKey, $items);
    }

    /**
     * Clear already-loaded permissions collection.
     */
    public function clearPermissionsCollection(): void
    {
        $this->clearAllPermissionRuntimeState();
    }

    /**
     * Clear all permission runtime state in the current coroutine.
     */
    protected function clearAllPermissionRuntimeState(): void
    {
        CoroutineContext::forget(self::PERMISSION_CATALOG_CONTEXT_KEY);
        CoroutineContext::forget(self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY);
        CoroutineContext::forget(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY);
    }

    /**
     * Clear permission runtime state for an explicit partition.
     */
    protected function clearPermissionRuntimeStateFor(?PermissionPartition $partition): void
    {
        if (! static::partitioningEnabled()) {
            $this->clearAllPermissionRuntimeState();

            return;
        }

        $catalogs = CoroutineContext::get(self::PERMISSION_CATALOG_CONTEXT_KEY, []);
        unset($catalogs[$this->partitionedCacheKey($this->cacheKey, $partition)]);
        CoroutineContext::set(self::PERMISSION_CATALOG_CONTEXT_KEY, $catalogs);

        $this->forgetRuntimeCacheItemsForPartition(
            self::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY,
            $partition,
        );
        $this->forgetRuntimeCacheItemsForPartition(
            self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY,
            $partition,
        );
    }

    /**
     * Mark the context that produced a loaded permission relation.
     */
    public function markLoadedRelation(
        Model $model,
        string $relation,
        BaseCollection $collection,
        PermissionRelationContext $context,
    ): void {
        if (! static::partitioningEnabled() && ! $this->teams) {
            return;
        }

        $relations = $this->loadedRelationProvenance[$model] ?? [];
        $relations[$relation] = [
            'collection' => WeakReference::create($collection),
            'context' => $context,
        ];
        $this->loadedRelationProvenance[$model] = $relations;
    }

    /**
     * Determine whether a loaded permission relation matches current context.
     */
    public function loadedRelationIsCurrent(Model $model, string $relation): bool
    {
        if (! static::partitioningEnabled() && ! $this->teams) {
            return true;
        }

        if (! $model->relationLoaded($relation)) {
            return false;
        }

        $provenance = ($this->loadedRelationProvenance[$model] ?? [])[$relation] ?? null;

        if ($provenance === null
            || $provenance['collection']->get() !== $model->getRelation($relation)) {
            return false;
        }

        $capturedPartition = $provenance['context']->partition;
        $currentPartition = $this->resolvePartition();

        if (($capturedPartition === null) !== ($currentPartition === null)) {
            return false;
        }

        if ($capturedPartition
            && ($currentPartition === null
                || $capturedPartition->column !== $currentPartition->column
                || ! $capturedPartition->matches($currentPartition->value))) {
            return false;
        }

        return ! $provenance['context']->teamScoped
            || self::attributeMatches(
                $provenance['context']->team,
                $this->getPermissionsTeamId(),
            );
    }

    /**
     * Forget loaded permission relation provenance for a model.
     */
    public function forgetLoadedRelationProvenance(Model $model, ?string $relation = null): void
    {
        if (! isset($this->loadedRelationProvenance[$model])) {
            return;
        }

        if ($relation === null) {
            unset($this->loadedRelationProvenance[$model]);

            return;
        }

        $relations = $this->loadedRelationProvenance[$model];
        unset($relations[$relation]);

        if ($relations === []) {
            unset($this->loadedRelationProvenance[$model]);

            return;
        }

        $this->loadedRelationProvenance[$model] = $relations;
    }

    /**
     * Get the hydrated permission catalog.
     *
     * @return array{
     *     permissions: Collection,
     *     roles: Collection,
     *     permissionByKey: array<string, Model>,
     *     permissionByNameAndGuard: array<string, list<Model>>,
     *     permissionOrderByKey: array<string, int>,
     *     roleByKey: array<string, Model>,
     *     roleByNameAndGuard: array<string, list<Model>>,
     *     roleOrderByKey: array<string, int>,
     *     hasDeniedRolePermissions: bool
     * }
     */
    private function permissionCatalog(): array
    {
        $contextKey = $this->getCacheKey();
        $catalogs = CoroutineContext::get(self::PERMISSION_CATALOG_CONTEXT_KEY, []);

        if (isset($catalogs[$contextKey]) && is_array($catalogs[$contextKey])) {
            /** @var array{permissions: Collection, roles: Collection, permissionByKey: array<string, Model>, permissionByNameAndGuard: array<string, list<Model>>, permissionOrderByKey: array<string, int>, roleByKey: array<string, Model>, roleByNameAndGuard: array<string, list<Model>>, roleOrderByKey: array<string, int>, hasDeniedRolePermissions: bool} */
            return $catalogs[$contextKey];
        }

        /** @var array{permissions: array<int, array<string, mixed>>, roles: array<int, array<string, mixed>>, hasDeniedRolePermissions: bool} $payload */
        $payload = $this->cacheRepository()->remember(
            $contextKey,
            $this->cacheExpirationTime,
            fn () => $this->getSerializedPermissionsForCache(),
        );

        $roles = $this->getHydratedRoleCollection($payload['roles']);
        $permissions = $this->getHydratedPermissionCollection($payload['permissions'], $roles);

        $catalog = [
            'roles' => $roles,
            'permissions' => $permissions,
            'permissionByKey' => $this->indexModelsByKey($permissions),
            'permissionByNameAndGuard' => $this->indexModelsByNameAndGuard($permissions),
            'permissionOrderByKey' => $this->indexModelOrderByKey($permissions),
            'roleByKey' => $this->indexModelsByKey($roles),
            'roleByNameAndGuard' => $this->indexModelsByNameAndGuard($roles),
            'roleOrderByKey' => $this->indexModelOrderByKey($roles),
            'hasDeniedRolePermissions' => (bool) $payload['hasDeniedRolePermissions'],
        ];

        $catalogs[$contextKey] = $catalog;
        CoroutineContext::set(self::PERMISSION_CATALOG_CONTEXT_KEY, $catalogs);

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
            $this->validatePermissionClass($permissionClass);

            return $this->filterModels(
                $this->getPermissionsWithRoles($permissionClass),
                $params,
                $onlyOne,
            );
        }

        $indexed = $this->indexedModels($params, $onlyOne, 'permission');

        if ($indexed !== null) {
            return $indexed;
        }

        return $this->filterModels($this->permissionCatalog()['permissions'], $params, $onlyOne);
    }

    /**
     * Get the roles based on the passed params.
     *
     * @param array<string, mixed> $params
     */
    public function getRoles(array $params = [], bool $onlyOne = false, ?string $roleClass = null): Collection
    {
        if ($roleClass !== null && $roleClass !== $this->roleClass) {
            $this->validateRoleClass($roleClass);

            return $this->filterModels(
                $roleClass::select()->get(),
                $params,
                $onlyOne,
            );
        }

        $indexed = $this->indexedModels($params, $onlyOne, 'role');

        if ($indexed !== null) {
            return $indexed;
        }

        return $this->filterModels($this->permissionCatalog()['roles'], $params, $onlyOne);
    }

    /**
     * Get indexed models for supported exact lookup shapes.
     *
     * @param array<string, mixed> $params
     */
    protected function indexedModels(array $params, bool $onlyOne, string $modelType): ?Collection
    {
        if ($params === []) {
            return null;
        }

        $catalog = $this->permissionCatalog();
        $keyName = $modelType === 'permission'
            ? Guard::getModelKeyName($this->permissionClass)
            : Guard::getModelKeyName($this->roleClass);

        /** @var array<string, Model> $byKey */
        $byKey = $catalog[$modelType === 'permission' ? 'permissionByKey' : 'roleByKey'];
        /** @var array<string, list<Model>> $byNameAndGuard */
        $byNameAndGuard = $catalog[$modelType === 'permission' ? 'permissionByNameAndGuard' : 'roleByNameAndGuard'];
        /** @var array<string, int> $orderByKey */
        $orderByKey = $catalog[$modelType === 'permission' ? 'permissionOrderByKey' : 'roleOrderByKey'];

        if (array_key_exists($keyName, $params) && count(array_diff_key($params, [$keyName => true, 'guard_name' => true])) === 0) {
            $ids = Arr::wrap($params[$keyName]);
            $guardNames = array_key_exists('guard_name', $params) ? Arr::wrap($params['guard_name']) : null;
            $matches = [];

            foreach ($ids as $id) {
                $model = $byKey[(string) $id] ?? null;

                if (! $model instanceof Model) {
                    continue;
                }

                if ($guardNames !== null && ! self::attributeMatches($model->getAttribute('guard_name'), $guardNames)) {
                    continue;
                }

                if ($modelType === 'role' && ! $this->roleMatchesCurrentTeam($model)) {
                    continue;
                }

                $matches[(string) $model->getKey()] = $model;
            }

            return $this->collectionFromIndexedModels($matches, $orderByKey, $onlyOne);
        }

        if (array_key_exists('name', $params) && array_key_exists('guard_name', $params) && count($params) === 2) {
            $names = Arr::wrap($params['name']);
            $guards = Arr::wrap($params['guard_name']);
            $matches = [];

            foreach ($guards as $guardName) {
                foreach ($names as $name) {
                    foreach ($byNameAndGuard[$this->nameGuardIndexKey($name, $guardName)] ?? [] as $model) {
                        if ($modelType === 'role' && ! $this->roleMatchesCurrentTeam($model)) {
                            continue;
                        }

                        $matches[(string) $model->getKey()] = $model;
                    }
                }
            }

            return $this->collectionFromIndexedModels($matches, $orderByKey, $onlyOne);
        }

        return null;
    }

    /**
     * Determine if a cached role matches the current team scope.
     */
    private function roleMatchesCurrentTeam(Model $role): bool
    {
        if (! $this->teams) {
            return true;
        }

        $teamId = $this->getPermissionsTeamId();
        $roleTeamId = $role->getAttribute($this->teamsKey);

        return $roleTeamId === null || self::attributeMatches($roleTeamId, $teamId);
    }

    /**
     * Build a collection from indexed models in catalog order.
     *
     * @param array<string, Model> $models
     * @param array<string, int> $orderByKey
     */
    private function collectionFromIndexedModels(array $models, array $orderByKey, bool $onlyOne): Collection
    {
        uasort($models, fn (Model $a, Model $b): int => ($orderByKey[(string) $a->getKey()] ?? PHP_INT_MAX)
            <=> ($orderByKey[(string) $b->getKey()] ?? PHP_INT_MAX));

        $collection = new Collection(array_values($models));

        return $onlyOne ? $collection->take(1)->values() : $collection->values();
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
     * Get the current global permission cache key.
     */
    public function getCacheKey(): string
    {
        return $this->partitionedCacheKey($this->cacheKey);
    }

    /**
     * Add the permission partition to a cache key.
     */
    protected function partitionedCacheKey(
        string $key,
        ?PermissionPartition $partition = null,
    ): string {
        $partition = $this->resolvedPartitionArgument($partition);

        return $partition
            ? $key . ':partition:' . $partition->cacheSegment()
            : $key;
    }

    /**
     * Resolve an optional explicit partition argument.
     */
    protected function resolvedPartitionArgument(?PermissionPartition $partition): ?PermissionPartition
    {
        return static::partitioningEnabled()
            ? ($partition ?? $this->resolvePartition())
            : null;
    }

    /**
     * Build the runtime key prefix for a partition.
     */
    protected function partitionRuntimePrefix(?PermissionPartition $partition): string
    {
        return $this->partitionedCacheKey('permission-runtime', $partition);
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
        $this->validatePermissionClass($permissionClass);
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
        $this->validateRoleClass($roleClass);
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
     * @return array{permissions: array<int, array<string, mixed>>, roles: array<int, array<string, mixed>>, hasDeniedRolePermissions: bool}
     */
    private function getSerializedPermissionsForCache(): array
    {
        $except = $this->config->array('permission.cache.column_names_except', ['created_at', 'updated_at', 'deleted_at']);
        $hasDeniedRolePermissions = false;
        $partition = $this->resolvePartition();

        return [
            'permissions' => $this->getPermissionsWithRoles()
                ->map(function (Model $permission) use ($except, &$hasDeniedRolePermissions, $partition): array {
                    $roles = $this->relationCollection($permission, 'roles')
                        ->map(function (Model $role) use ($permission, &$hasDeniedRolePermissions, $partition): array {
                            $isDenied = $this->pivotIsDenied($role);
                            $hasDeniedRolePermissions = $hasDeniedRolePermissions || $isDenied;
                            $pivot = [
                                $this->pivotPermission => $permission->getKey(),
                                $this->pivotRole => $role->getKey(),
                                'is_denied' => $isDenied,
                            ];

                            if ($partition) {
                                $pivot[$partition->column] = $partition->value;
                            }

                            return [
                                'pivot' => $pivot,
                            ];
                        })
                        ->values()
                        ->all();

                    return [
                        'attributes' => Arr::except($permission->getAttributes(), $except),
                        'roles' => $roles,
                    ];
                })
                ->values()
                ->all(),
            'roles' => $this->getRolesForCache()
                ->map(fn (Model $role): array => [
                    'attributes' => Arr::except($role->getAttributes(), $except),
                ])
                ->values()
                ->all(),
            'hasDeniedRolePermissions' => $hasDeniedRolePermissions,
        ];
    }

    /**
     * Determine if any cached role-permission edge is denied.
     */
    public function hasDeniedRolePermissions(): bool
    {
        return (bool) $this->permissionCatalog()['hasDeniedRolePermissions'];
    }

    /**
     * Determine if a hydrated pivot marks the permission as denied.
     */
    protected function pivotIsDenied(Model $model): bool
    {
        if (! $model->relationLoaded('pivot')) {
            return false;
        }

        $pivot = $model->getRelation('pivot');

        return $pivot instanceof Pivot && (bool) $pivot->getAttribute('is_denied');
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
        $rolesByKey = $roles->keyBy(fn (Model $role): string => (string) $role->getKey());
        $context = new PermissionRelationContext($this->resolvePartition(), false, null);

        return Collection::make(array_map(
            function (array $item) use ($permissionInstance, $rolesByKey, $context): Model {
                $permission = (clone $permissionInstance)->setRawAttributes((array) $item['attributes'], true);
                $roles = $this->getHydratedPermissionRoleCollection((array) $item['roles'], $permission, $rolesByKey);

                $permission->setRelation('roles', $roles);
                $this->markLoadedRelation($permission, 'roles', $roles, $context);

                return $permission;
            },
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
     * Index models by primary key.
     *
     * @return array<string, Model>
     */
    private function indexModelsByKey(Collection $models): array
    {
        return $models
            ->mapWithKeys(fn (Model $model): array => [(string) $model->getKey() => $model])
            ->all();
    }

    /**
     * Index models by name and guard.
     *
     * @return array<string, list<Model>>
     */
    private function indexModelsByNameAndGuard(Collection $models): array
    {
        $indexed = [];

        foreach ($models as $model) {
            $indexed[$this->nameGuardIndexKey($model->getAttribute('name'), $model->getAttribute('guard_name'))][] = $model;
        }

        return $indexed;
    }

    /**
     * Build the name and guard index key.
     */
    private function nameGuardIndexKey(mixed $name, mixed $guardName): string
    {
        // Null byte avoids collisions with names or guards containing ordinary separators.
        return (string) enum_value($guardName) . "\0" . (string) enum_value($name);
    }

    /**
     * Index model catalog order by primary key.
     *
     * @return array<string, int>
     */
    private function indexModelOrderByKey(Collection $models): array
    {
        $order = [];

        foreach ($models->values() as $index => $model) {
            $order[(string) $model->getKey()] = $index;
        }

        return $order;
    }

    /**
     * Get the hydrated role collection for a cached permission.
     *
     * @param array<int, array<string, mixed>> $roles
     */
    private function getHydratedPermissionRoleCollection(array $roles, Model $permission, Collection $roleCatalog): Collection
    {
        return Collection::make(array_values(array_filter(array_map(function (array $item) use ($permission, $roleCatalog): ?Model {
            $roleKey = $item['pivot'][$this->pivotRole] ?? null;
            $role = $roleKey === null ? null : $roleCatalog->get((string) $roleKey);

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
        static::$partitionColumn = null;
        static::$partitionResolver = null;
        static::$initialized = false;

        $app = BaseContainer::getInstance();

        if ($app->bound(self::class)) {
            $app->forgetInstance(self::class);
        }
    }
}
