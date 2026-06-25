<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Contracts\Wildcard;
use Hypervel\Permission\Events\PermissionAttachedEvent;
use Hypervel\Permission\Events\PermissionDetachedEvent;
use Hypervel\Permission\Exceptions\GuardDoesNotMatch;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\WildcardPermissionInvalidArgument;
use Hypervel\Permission\Exceptions\WildcardPermissionNotImplementsContract;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use UnitEnum;

use function Hypervel\Support\enum_value;

trait HasPermissions
{
    private ?string $permissionClass = null;

    private ?string $wildcardClass = null;

    /**
     * @var array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>}>
     */
    private array $queuedPermissionAssignments = [];

    /**
     * Boot the permission relation cleanup callback.
     */
    public static function bootHasPermissions(): void
    {
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            if (! $model instanceof Permission && ! $model instanceof Role) {
                $model->getConnection()
                    ->table(Config::modelHasPermissionsTable())
                    ->where(Config::morphKey(), $model->getKey())
                    ->where('model_type', $model->getMorphClass())
                    ->delete();
            }

            if ($model instanceof Role) {
                $model->getConnection()
                    ->table(Config::modelHasRolesTable())
                    ->where(app(PermissionRegistrar::class)->pivotRole, $model->getKey())
                    ->delete();

                $model->getConnection()
                    ->table(Config::roleHasPermissionsTable())
                    ->where(app(PermissionRegistrar::class)->pivotRole, $model->getKey())
                    ->delete();
            }

            app(PermissionRegistrar::class)->bumpModelAssignmentCacheVersion();
        });

        static::saved(function (Model $model): void {
            if (method_exists($model, 'attachQueuedPermissionAssignments')) {
                $model->attachQueuedPermissionAssignments();
            }
        });
    }

    /**
     * Get the permission model class.
     */
    public function getPermissionClass(): string
    {
        if (! $this->permissionClass) {
            $this->permissionClass = app(PermissionRegistrar::class)->getPermissionClass();
        }

        return $this->permissionClass;
    }

    /**
     * Get the wildcard permission class.
     */
    public function getWildcardClass(): string
    {
        if (! is_null($this->wildcardClass)) {
            return $this->wildcardClass;
        }

        $this->wildcardClass = '';

        if (Config::wildcardPermissionsEnabled()) {
            $this->wildcardClass = Config::wildcardPermissionClass();

            if (! is_subclass_of($this->wildcardClass, Wildcard::class)) {
                throw WildcardPermissionNotImplementsContract::create();
            }
        }

        return $this->wildcardClass;
    }

    /**
     * A model may have multiple direct permissions.
     */
    public function permissions(): BelongsToMany
    {
        $relation = $this->morphToMany(
            Config::permissionModel(),
            'model',
            Config::modelHasPermissionsTable(),
            Config::morphKey(),
            app(PermissionRegistrar::class)->pivotPermission
        )->withPivot('is_forbidden');

        if (! Config::teamsEnabled()) {
            return $relation;
        }

        $teamsKey = Config::teamForeignKey();
        $relation->withPivot($teamsKey, 'is_forbidden');

        return $relation->wherePivot($teamsKey, getPermissionsTeamId());
    }

    /**
     * Get cached direct permission assignments for this model.
     */
    protected function getCachedDirectPermissions(): Collection
    {
        $model = $this;

        if ($this instanceof Role || $this instanceof Permission || ! $model->exists || $this->relationLoaded('permissions')) {
            $this->loadMissing('permissions');

            return $this->relationCollection($this, 'permissions');
        }

        $registrar = app(PermissionRegistrar::class);
        $permissionKey = (new ($this->getPermissionClass())())->getKeyName();
        $assignments = $registrar->rememberModelPermissionAssignments(
            $model,
            fn (): array => $this->permissions()
                ->get()
                ->map(fn (Model $permission): array => [
                    $permissionKey => $permission->getKey(),
                    'is_forbidden' => $this->pivotIsForbidden($permission),
                ])
                ->values()
                ->all(),
        );

        $permissions = $registrar->getPermissions(
            [$permissionKey => array_column($assignments, $permissionKey)],
            false,
            $this->getPermissionClass(),
        )->keyBy(fn (Model $permission): string => (string) $permission->getKey());

        return Collection::make($assignments)
            ->map(function (array $assignment) use ($permissions, $model, $permissionKey, $registrar): ?Model {
                $permission = $permissions->get((string) $assignment[$permissionKey]);

                if (! $permission instanceof Model) {
                    return null;
                }

                $pivot = [
                    $registrar->pivotPermission => $permission->getKey(),
                    Config::morphKey() => $model->getKey(),
                    'model_type' => $model->getMorphClass(),
                    'is_forbidden' => (bool) ($assignment['is_forbidden'] ?? false),
                ];

                if ($registrar->teams) {
                    $pivot[$registrar->teamsKey] = getPermissionsTeamId();
                }

                $permission = clone $permission;
                $permission->setRelation('pivot', Pivot::fromRawAttributes(
                    $model,
                    $pivot,
                    Config::modelHasPermissionsTable(),
                    true,
                ));

                return $permission;
            })
            ->filter()
            ->values();
    }

    /**
     * Scope the model query to certain permissions only.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    public function scopePermission(Builder $query, $permissions, bool $without = false): Builder
    {
        $permissions = $this->convertToPermissionModels($permissions);

        $permissionKey = (new ($this->getPermissionClass())())->getKeyName();
        $roleKey = (new ($this instanceof Role ? static::class : $this->getRoleClass())())->getKeyName();

        $rolesWithPermissions = $this instanceof Role ? [] : array_unique(
            array_reduce($permissions, fn ($result, $permission) => array_merge($result, $this->relationCollection($permission, 'roles')->all()), [])
        );

        return $query->where(
            fn (Builder $query) => $query
                ->{! $without ? 'whereHas' : 'whereDoesntHave'}(
                    'permissions',
                    fn (Builder $subQuery) => $subQuery
                        ->whereIn(Config::permissionsTable() . ".{$permissionKey}", array_column($permissions, $permissionKey))
                )
                ->when(
                    count($rolesWithPermissions),
                    fn ($whenQuery) => $whenQuery
                        ->{! $without ? 'orWhereHas' : 'whereDoesntHave'}(
                            'roles',
                            fn (Builder $subQuery) => $subQuery
                                ->whereIn(Config::rolesTable() . ".{$roleKey}", array_column($rolesWithPermissions, $roleKey))
                        )
                )
        );
    }

    /**
     * Scope the model query to only those without certain permissions,
     * whether indirectly by role or by direct permission.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    public function scopeWithoutPermission(Builder $query, $permissions): Builder
    {
        return $this->scopePermission($query, $permissions, true);
    }

    /**
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     *
     * @throws PermissionDoesNotExist
     */
    protected function convertToPermissionModels($permissions): array
    {
        if ($permissions instanceof Collection) {
            $permissions = $permissions->all();
        }

        return array_map(function ($permission) {
            if ($permission instanceof Permission) {
                return $permission;
            }

            $permission = enum_value($permission);

            $method = is_int($permission) || PermissionRegistrar::isUid($permission) ? 'findById' : 'findByName';

            return $this->getPermissionClass()::{$method}($permission, $this->getDefaultGuardName());
        }, Arr::wrap($permissions));
    }

    /**
     * Find a permission.
     *
     * @param int|Permission|string|UnitEnum $permission
     *
     * @throws PermissionDoesNotExist
     */
    public function filterPermission($permission, ?string $guardName = null): Permission
    {
        $permission = enum_value($permission);

        if (is_int($permission) || PermissionRegistrar::isUid($permission)) {
            $permission = $this->getPermissionClass()::findById(
                $permission,
                $guardName ?? $this->getDefaultGuardName()
            );
        }

        if (is_string($permission)) {
            $permission = $this->getPermissionClass()::findByName(
                $permission,
                $guardName ?? $this->getDefaultGuardName()
            );
        }

        if (! $permission instanceof Permission) {
            throw new PermissionDoesNotExist;
        }

        return $permission;
    }

    /**
     * Determine if the model may perform the given permission.
     *
     * @param int|Permission|string|UnitEnum $permission
     *
     * @throws PermissionDoesNotExist
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->hasForbiddenPermission($permission, $guardName)) {
            return false;
        }

        if ($this->hasForbiddenPermissionViaRoles($permission, $guardName)) {
            return false;
        }

        if ($this->getWildcardClass()) {
            return $this->hasWildcardPermission($permission, $guardName);
        }

        $permission = $this->filterPermission($permission, $guardName);

        return $this->hasDirectPermission($permission) || $this->hasPermissionViaRole($permission);
    }

    /**
     * Validates a wildcard permission against all permissions of a user.
     *
     * @param int|Permission|string|UnitEnum $permission
     */
    protected function hasWildcardPermission($permission, ?string $guardName = null): bool
    {
        $guardName = $guardName ?? $this->getDefaultGuardName();

        $permission = enum_value($permission);

        if (is_int($permission) || PermissionRegistrar::isUid($permission)) {
            $permission = $this->getPermissionClass()::findById($permission, $guardName);
        }

        if ($permission instanceof Permission) {
            $guardName = $permission->guard_name ?? $guardName;
            $permission = $permission->name;
        }

        if (! is_string($permission)) {
            throw WildcardPermissionInvalidArgument::create();
        }

        return app($this->getWildcardClass(), ['record' => $this])->implies(
            $permission,
            $guardName,
            app(PermissionRegistrar::class)->getWildcardPermissionIndex($this),
        );
    }

    /**
     * An alias to hasPermissionTo(), but avoids throwing an exception.
     *
     * @param int|Permission|string|UnitEnum $permission
     */
    public function checkPermissionTo($permission, ?string $guardName = null): bool
    {
        try {
            return $this->hasPermissionTo($permission, $guardName);
        } catch (PermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * Determine if the model has any of the given permissions.
     *
     * @param array|Collection|int|Permission|string|UnitEnum ...$permissions
     */
    public function hasAnyPermission(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if ($this->checkPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the model has all of the given permissions.
     *
     * @param array|Collection|int|Permission|string|UnitEnum ...$permissions
     */
    public function hasAllPermissions(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if (! $this->checkPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the model has, via roles, the given permission.
     */
    protected function hasPermissionViaRole(Permission $permission): bool
    {
        if ($this instanceof Role) {
            return false;
        }

        if (! $permission instanceof Model) {
            return false;
        }

        return $this->hasRole(
            $this->relationCollection($permission, 'roles')
                ->reject(fn (Model $role): bool => $this->pivotIsForbidden($role))
        );
    }

    /**
     * Determine if the model has the given permission.
     *
     * @param int|Permission|string|UnitEnum $permission
     *
     * @throws PermissionDoesNotExist
     */
    public function hasDirectPermission($permission): bool
    {
        $permission = $this->filterPermission($permission);

        $matchedPermission = $this->getCachedDirectPermissions()
            ->first(fn (Model $directPermission): bool => $directPermission->getKey() === $permission->getKey());

        return $matchedPermission !== null
            && ! $this->pivotIsForbidden($matchedPermission);
    }

    /**
     * Return all the permissions the model has via roles.
     */
    public function getPermissionsViaRoles(): Collection
    {
        if ($this instanceof Role || $this instanceof Permission) {
            return collect();
        }

        $roles = $this->getCachedRoles();
        $roleKey = (new ($this->getRoleClass())())->getKeyName();
        $roleIds = $roles->map(fn (Model $role): string => (string) $role->getKey())->all();

        return app(PermissionRegistrar::class)
            ->getPermissions([], false, $this->getPermissionClass())
            ->flatMap(
                fn (Model $permission): Collection => $this->relationCollection($permission, 'roles')
                    ->filter(fn (Model $role): bool => in_array((string) $role->getAttribute($roleKey), $roleIds, true))
                    ->map(fn (Model $role): Model => $this->permissionWithRolePivot($permission, $role))
            )
            ->reject(fn (Model $permission): bool => $this->pivotIsForbidden($permission))
            ->sort()->values();
    }

    /**
     * Return all the permissions the model has, both directly and via roles.
     */
    public function getAllPermissions(): Collection
    {
        /** @var Collection $permissions */
        $permissions = $this->getCachedDirectPermissions();

        if (! $this instanceof Permission) {
            $permissions = $permissions->merge($this->getPermissionsViaRoles());
        }

        return $permissions
            ->reject(
                fn (Model $permission): bool => $this->hasForbiddenPermission($permission->getKey(), $permission->getAttribute('guard_name'))
                || $this->hasForbiddenPermissionViaRoles($permission->getKey(), $permission->getAttribute('guard_name'))
            )
            ->unique(fn (Model $permission): string => (string) $permission->getKey())
            ->sort()
            ->values();
    }

    /**
     * Returns array of permissions ids.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    private function collectPermissions(...$permissions): array
    {
        return collect($permissions)
            ->flatten()
            ->reduce(function ($array, $permission) {
                if ($permission === null || $permission === '') {
                    return $array;
                }

                $permission = $this->getStoredPermission($permission);
                if (! $permission instanceof Permission) {
                    return $array;
                }

                if (! in_array($permission->getKey(), $array, true)) {
                    $this->ensureModelSharesGuard($permission);
                    $array[] = $permission->getKey();
                }

                return $array;
            }, []);
    }

    /**
     * Grant the given permission(s) to a role.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    public function givePermissionTo(...$permissions): static
    {
        return $this->attachPermissions($permissions, false);
    }

    /**
     * Grant the given forbidden permission(s) to a role.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    public function giveForbiddenTo(...$permissions): static
    {
        return $this->attachPermissions($permissions, true);
    }

    /**
     * Attach permissions with the given forbidden flag.
     *
     * @param array<int, mixed> $permissions
     */
    private function attachPermissions(array $permissions, bool $isForbidden): static
    {
        $permissions = $this->collectPermissions($permissions);
        $model = $this;
        $registrar = app(PermissionRegistrar::class);
        $teamPivot = $registrar->teams && ! $this instanceof Role
            ? [$registrar->teamsKey => getPermissionsTeamId()] : [];
        $pivot = $teamPivot + ['is_forbidden' => $isForbidden];

        if ($model->exists) {
            $currentPermissions = $this->relationCollection($this->loadMissing('permissions'), 'permissions')
                ->filter(fn (Model $permission): bool => $this->pivotIsForbidden($permission) === $isForbidden)
                ->map(fn (Model $permission) => $permission->getKey())
                ->toArray();

            $this->permissions()->attach(array_diff($permissions, $currentPermissions), $pivot);
            $model->unsetRelation('permissions');
        } else {
            $this->queuePermissionAssignments($permissions, $pivot);
        }

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        } elseif ($model->exists) {
            $registrar->forgetModelPermissionCache($model);
        }

        $this->dispatchPermissionAttachedEvent($permissions);

        $this->forgetWildcardPermissionIndex();

        return $this;
    }

    /**
     * Queue permission assignments until the model is saved.
     *
     * @param array<int, int|string> $permissions
     * @param array<string, mixed> $pivot
     */
    protected function queuePermissionAssignments(array $permissions, array $pivot): void
    {
        $this->queuedPermissionAssignments[] = [
            'permissions' => $permissions,
            'pivot' => $pivot,
        ];
    }

    /**
     * Attach permission assignments queued before the model was saved.
     */
    protected function attachQueuedPermissionAssignments(): void
    {
        if ($this->queuedPermissionAssignments === []) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);

        foreach ($this->queuedPermissionAssignments as $assignment) {
            $this->permissions()->attach($assignment['permissions'], $assignment['pivot']);
        }

        $this->queuedPermissionAssignments = [];
        $this->unsetRelation('permissions');

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        } else {
            $registrar->forgetModelPermissionCache($this);
        }

        $this->forgetWildcardPermissionIndex();
    }

    /**
     * Dispatch the permission attached event when enabled and listened for.
     *
     * @param array<int, int|string> $permissions
     */
    protected function dispatchPermissionAttachedEvent(array $permissions): void
    {
        if (! Config::eventsEnabled()) {
            return;
        }

        $events = app(Dispatcher::class);

        if ($events->hasListeners(PermissionAttachedEvent::class)) {
            $events->dispatch(new PermissionAttachedEvent($this, $permissions));
        }
    }

    public function forgetWildcardPermissionIndex(): void
    {
        app(PermissionRegistrar::class)->forgetWildcardPermissionIndex(
            $this instanceof Role ? null : $this,
        );
    }

    /**
     * Remove all current permissions and set the given ones.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    public function syncPermissions(...$permissions): static
    {
        if ($this->exists) {
            $this->collectPermissions($permissions);
            $this->permissions()->detach();
            $this->setRelation('permissions', collect());
        }

        $this->givePermissionTo($permissions);

        return $this;
    }

    /**
     * Remove all current permissions and set allowed and forbidden permissions.
     *
     * @param array<array-key, mixed>|Collection<array-key, mixed> $allowed
     * @param array<array-key, mixed>|Collection<array-key, mixed> $forbidden
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}
     */
    public function syncPermissionsWithForbidden(array|Collection $allowed = [], array|Collection $forbidden = []): array
    {
        $model = $this;

        if (! $model->exists) {
            return ['attached' => [], 'detached' => [], 'updated' => []];
        }

        $allowedIds = $this->collectPermissions($allowed);
        $forbiddenIds = $this->collectPermissions($forbidden);
        $allowedIds = array_values(array_filter(
            $allowedIds,
            fn (int|string $allowedId): bool => ! in_array($allowedId, $forbiddenIds, true),
        ));
        $registrar = app(PermissionRegistrar::class);
        $teamPivot = $registrar->teams && ! $this instanceof Role
            ? [$registrar->teamsKey => getPermissionsTeamId()] : [];

        $syncData = [];

        foreach ($allowedIds as $permissionId) {
            $syncData[$permissionId] = $teamPivot + ['is_forbidden' => false];
        }

        foreach ($forbiddenIds as $permissionId) {
            $syncData[$permissionId] = $teamPivot + ['is_forbidden' => true];
        }

        /** @var array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>} $changes */
        $changes = $this->permissions()->sync($syncData);

        $this->unsetRelation('permissions');

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        } else {
            app(PermissionRegistrar::class)->forgetModelPermissionCache($model);
        }

        $this->forgetWildcardPermissionIndex();

        return $changes;
    }

    /**
     * Revoke the given permission(s).
     *
     * @param Permission|Permission[]|string|string[]|UnitEnum $permission
     */
    public function revokePermissionTo($permission): static
    {
        $storedPermission = $this->getStoredPermission($permission);

        $this->permissions()->detach($storedPermission);

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        } else {
            app(PermissionRegistrar::class)->forgetModelPermissionCache($this);
        }

        $this->dispatchPermissionDetachedEvent($storedPermission);

        $this->forgetWildcardPermissionIndex();

        $this->unsetRelation('permissions');

        return $this;
    }

    /**
     * Dispatch the permission detached event when enabled and listened for.
     */
    protected function dispatchPermissionDetachedEvent(mixed $permission): void
    {
        if (! Config::eventsEnabled()) {
            return;
        }

        $events = app(Dispatcher::class);

        if ($events->hasListeners(PermissionDetachedEvent::class)) {
            $events->dispatch(new PermissionDetachedEvent($this, $permission));
        }
    }

    /**
     * Determine if the model has a forbidden direct permission.
     *
     * @param int|Permission|string|UnitEnum $permission
     */
    public function hasForbiddenPermission($permission, ?string $guardName = null): bool
    {
        return $this->getCachedDirectPermissions()
            ->contains(
                fn (Model $storedPermission): bool => $this->pivotIsForbidden($storedPermission)
                && $this->storedPermissionMatches($storedPermission, $permission, $guardName)
            );
    }

    /**
     * Determine if the model has a forbidden permission via roles.
     *
     * @param int|Permission|string|UnitEnum $permission
     */
    public function hasForbiddenPermissionViaRoles($permission, ?string $guardName = null): bool
    {
        if ($this instanceof Role || $this instanceof Permission) {
            return false;
        }

        $roles = $this->getCachedRoles();
        $roleKey = (new ($this->getRoleClass())())->getKeyName();
        $roleIds = $roles->map(fn (Model $role): string => (string) $role->getKey())->all();

        return app(PermissionRegistrar::class)
            ->getPermissions([], false, $this->getPermissionClass())
            ->flatMap(
                fn (Model $permission): Collection => $this->relationCollection($permission, 'roles')
                    ->filter(fn (Model $role): bool => in_array((string) $role->getAttribute($roleKey), $roleIds, true))
                    ->map(fn (Model $role): Model => $this->permissionWithRolePivot($permission, $role))
            )
            ->contains(
                fn (Model $storedPermission): bool => $this->pivotIsForbidden($storedPermission)
                && $this->storedPermissionMatches($storedPermission, $permission, $guardName)
            );
    }

    /**
     * Clone a permission with the matching role-permission pivot.
     */
    protected function permissionWithRolePivot(Model $permission, Model $role): Model
    {
        $permission = clone $permission;
        $permission->setRelation('pivot', $role->getRelation('pivot'));

        return $permission;
    }

    /**
     * Determine if a hydrated pivot marks the permission as forbidden.
     */
    protected function pivotIsForbidden(Model $model): bool
    {
        if (! $model->relationLoaded('pivot')) {
            return false;
        }

        $pivot = $model->getRelation('pivot');

        return $pivot instanceof Pivot && (bool) $pivot->getAttribute('is_forbidden');
    }

    /**
     * Get a hydrated relation collection.
     */
    protected function relationCollection(Model $model, string $relation): Collection
    {
        if (! $model->relationLoaded($relation)) {
            $model->loadMissing($relation);
        }

        $value = $model->getRelation($relation);

        return $value instanceof Collection ? $value : new Collection;
    }

    /**
     * Determine if a stored permission matches an input permission.
     */
    protected function storedPermissionMatches(Model $storedPermission, mixed $permission, ?string $guardName = null): bool
    {
        if ($guardName !== null && $storedPermission->getAttribute('guard_name') !== $guardName) {
            return false;
        }

        if ($permission instanceof Permission) {
            return $storedPermission->getKey() === $permission->getKey();
        }

        $permission = enum_value($permission);

        if (is_int($permission) || PermissionRegistrar::isUid($permission)) {
            return (string) $storedPermission->getKey() === (string) $permission;
        }

        return is_string($permission)
            && $storedPermission->getAttribute('name') === $permission;
    }

    /**
     * Get the permission names.
     */
    public function getPermissionNames(): Collection
    {
        return $this->getCachedDirectPermissions()->pluck('name');
    }

    /**
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     * @return Collection|Permission|Permission[]
     */
    protected function getStoredPermission($permissions)
    {
        $permissions = enum_value($permissions);

        if (is_int($permissions) || PermissionRegistrar::isUid($permissions)) {
            return $this->getPermissionClass()::findById($permissions, $this->getDefaultGuardName());
        }

        if (is_string($permissions)) {
            return $this->getPermissionClass()::findByName($permissions, $this->getDefaultGuardName());
        }

        if (is_array($permissions)) {
            $permissions = array_map(fn ($permission) => $permission instanceof Permission ? $permission->name : enum_value($permission), $permissions);

            return $this->getPermissionClass()::whereIn('name', $permissions)
                ->whereIn('guard_name', $this->getGuardNames())
                ->get();
        }

        return $permissions;
    }

    /**
     * @param Permission|Role $roleOrPermission
     *
     * @throws GuardDoesNotMatch
     */
    protected function ensureModelSharesGuard($roleOrPermission): void
    {
        if (! $this->getGuardNames()->contains($roleOrPermission->guard_name)) {
            throw GuardDoesNotMatch::create($roleOrPermission->guard_name, $this->getGuardNames());
        }
    }

    protected function getGuardNames(): Collection
    {
        return Guard::getNames($this);
    }

    protected function getDefaultGuardName(): string
    {
        return Guard::getDefaultName($this);
    }

    /**
     * Forget the cached permissions.
     */
    public function forgetCachedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Check if the model has All of the requested Direct permissions.
     *
     * @param array|Collection|int|Permission|string|UnitEnum ...$permissions
     */
    public function hasAllDirectPermissions(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if (! $this->hasDirectPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the model has Any of the requested Direct permissions.
     *
     * @param array|Collection|int|Permission|string|UnitEnum ...$permissions
     */
    public function hasAnyDirectPermission(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if ($this->hasDirectPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
