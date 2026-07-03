<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
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
     * Get the permission registrar.
     */
    protected function permissionRegistrar(): PermissionRegistrar
    {
        return Container::getInstance()->make(PermissionRegistrar::class);
    }

    /**
     * Get the event dispatcher.
     */
    protected function eventDispatcher(): Dispatcher
    {
        return Container::getInstance()->make(Dispatcher::class);
    }

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

                Container::getInstance()->make(PermissionRegistrar::class)->forgetModelAssignmentCache($model);
            }

            if ($model instanceof Role) {
                $registrar = Container::getInstance()->make(PermissionRegistrar::class);

                $model->getConnection()
                    ->table(Config::modelHasRolesTable())
                    ->where($registrar->pivotRole, $model->getKey())
                    ->delete();

                $model->getConnection()
                    ->table(Config::roleHasPermissionsTable())
                    ->where($registrar->pivotRole, $model->getKey())
                    ->delete();
            }
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
            $this->permissionClass = $this->permissionRegistrar()->getPermissionClass();
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
            $this->permissionRegistrar()->pivotPermission
        )->withPivot('is_forbidden');

        if (! Config::teamsEnabled()) {
            return $relation;
        }

        $teamsKey = Config::teamForeignKey();
        $relation->withPivot($teamsKey);

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

        $registrar = $this->permissionRegistrar();
        $permissionKey = Guard::getModelKeyName($this->getPermissionClass());
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
     * Return allowed direct permissions.
     */
    protected function allowedDirectPermissions(): Collection
    {
        return $this->getCachedDirectPermissions()
            ->reject(fn (Model $permission): bool => $this->pivotIsForbidden($permission))
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
        $permissionKey = Guard::getModelKeyName($this->getPermissionClass());
        $permissionIds = array_column($permissions, $permissionKey);
        $effectivePermission = fn (Builder $query): Builder => $this->whereEffectivePermission(
            $query,
            $permissionIds,
        );

        return $without
            ? $query->whereNot($effectivePermission)
            : $query->where($effectivePermission);
    }

    /**
     * Add an effective permission predicate for the given permission ids.
     *
     * @param array<int, int|string> $permissionIds
     */
    protected function whereEffectivePermission(Builder $query, array $permissionIds): Builder
    {
        if ($permissionIds === []) {
            // No requested permissions means no effective grant; whereNot() turns this into the exact complement.
            $query->whereRaw('1 = 0');

            return $query;
        }

        foreach ($permissionIds as $index => $permissionId) {
            $method = $index === 0 ? 'where' : 'orWhere';

            $query->{$method}(
                fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $this->wherePermissionEffect($query, $permissionId, false))
                    ->whereNot(fn (Builder $query) => $this->wherePermissionEffect($query, $permissionId, true))
            );
        }

        return $query;
    }

    /**
     * Add a permission-effect predicate for direct and role-granted permissions.
     */
    protected function wherePermissionEffect(Builder $query, int|string $permissionId, bool $forbidden): Builder
    {
        $query->whereHas(
            'permissions',
            fn (Builder $query) => $this->whereDirectPermissionEffect($query, $permissionId, $forbidden),
        );

        if (! $this instanceof Role) {
            $query->orWhereHas(
                'roles.permissions',
                fn (Builder $query) => $this->whereRolePermissionEffect($query, $permissionId, $forbidden),
            );
        }

        return $query;
    }

    /**
     * Add a direct permission-effect predicate.
     */
    protected function whereDirectPermissionEffect(Builder $query, int|string $permissionId, bool $forbidden): Builder
    {
        $permissionKey = Guard::getModelKeyName($this->getPermissionClass());
        $pivotTable = $this instanceof Role
            ? Config::roleHasPermissionsTable()
            : Config::modelHasPermissionsTable();

        return $query
            ->where(Config::permissionsTable() . ".{$permissionKey}", $permissionId)
            ->where("{$pivotTable}.is_forbidden", $forbidden);
    }

    /**
     * Add a role permission-effect predicate.
     */
    protected function whereRolePermissionEffect(Builder $query, int|string $permissionId, bool $forbidden): Builder
    {
        $permissionKey = Guard::getModelKeyName($this->getPermissionClass());

        return $query
            ->where(Config::permissionsTable() . ".{$permissionKey}", $permissionId)
            ->where(Config::roleHasPermissionsTable() . '.is_forbidden', $forbidden);
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
        if ($this->getWildcardClass()) {
            if ($this->hasForbiddenPermission($permission, $guardName)) {
                return false;
            }

            if ($this->hasForbiddenPermissionViaRoles($permission, $guardName)) {
                return false;
            }

            return $this->hasWildcardPermission($permission, $guardName);
        }

        $permission = $this->filterPermission($permission, $guardName);

        if ($this->hasForbiddenPermission($permission, $guardName)) {
            return false;
        }

        if ($this->hasForbiddenPermissionViaRoles($permission, $guardName)) {
            return false;
        }

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

        return Container::getInstance()->make($this->getWildcardClass(), ['record' => $this])->implies(
            $permission,
            $guardName,
            $this->permissionRegistrar()->getWildcardPermissionIndex($this),
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

        $matches = $this->getCachedDirectPermissions()
            ->filter(fn (Model $directPermission): bool => $directPermission->getKey() === $permission->getKey());

        return $matches->isNotEmpty()
            && ! $matches->contains(fn (Model $directPermission): bool => $this->pivotIsForbidden($directPermission));
    }

    /**
     * Return all the permissions the model has via roles.
     */
    public function getPermissionsViaRoles(): Collection
    {
        $permissions = $this->getPermissionsViaRolesWithPivots();
        $forbiddenPermissionKeys = $this->forbiddenPermissionKeys($permissions);

        return $permissions
            ->reject(fn (Model $permission): bool => isset($forbiddenPermissionKeys[$this->permissionComparisonKey($permission)]))
            ->unique(fn (Model $permission): string => $this->permissionComparisonKey($permission))
            ->sort()
            ->values();
    }

    /**
     * Return all the permissions the model has, both directly and via roles.
     */
    public function getAllPermissions(): Collection
    {
        $directPermissions = $this->getCachedDirectPermissions();
        $viaRolePermissions = $this instanceof Permission
            ? collect()
            : $this->getPermissionsViaRolesWithPivots();
        $forbiddenPermissionKeys = $this->forbiddenPermissionKeys($directPermissions, $viaRolePermissions);

        return $directPermissions
            ->merge($viaRolePermissions)
            ->reject(fn (Model $permission): bool => isset($forbiddenPermissionKeys[$this->permissionComparisonKey($permission)]))
            ->unique(fn (Model $permission): string => $this->permissionComparisonKey($permission))
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
        $registrar = $this->permissionRegistrar();
        $pivot = $this->permissionAssignmentPivot($isForbidden);
        $cacheCleared = false;

        if ($model->exists) {
            $this->upsertPermissionAssignments($permissions, $pivot);
            $model->unsetRelation('permissions');
        } else {
            $this->queuePermissionAssignments($permissions, $pivot);
        }

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
            $cacheCleared = true;
        } elseif ($model->exists) {
            $registrar->forgetModelPermissionCache($model);
            $cacheCleared = true;
        }

        if (! $cacheCleared) {
            $this->forgetWildcardPermissionIndex();
        }

        $this->dispatchPermissionAttachedEvent($permissions);

        return $this;
    }

    /**
     * Build permission assignment pivot attributes.
     *
     * @return array<string, mixed>
     */
    private function permissionAssignmentPivot(bool $isForbidden): array
    {
        $registrar = $this->permissionRegistrar();

        $teamPivot = $registrar->teams && ! $this instanceof Role
            ? [$registrar->teamsKey => getPermissionsTeamId()] : [];

        return $teamPivot + ['is_forbidden' => $isForbidden];
    }

    /**
     * Insert missing permission assignments and update existing effects.
     *
     * @param array<int, int|string> $permissions
     * @param array<string, mixed> $pivot
     */
    private function upsertPermissionAssignments(array $permissions, array $pivot): void
    {
        if ($permissions === []) {
            return;
        }

        $relation = $this->permissions();
        $currentPermissions = $relation->get()
            ->keyBy(fn (Model $permission): string => (string) $permission->getKey());
        $effect = ['is_forbidden' => (bool) $pivot['is_forbidden']];

        $attach = [];
        $updated = false;

        foreach ($permissions as $permission) {
            $key = (string) $permission;
            $currentPermission = $currentPermissions->get($key);

            if (! $currentPermission instanceof Model) {
                $attach[] = $permission;
                continue;
            }

            if ($this->pivotIsForbidden($currentPermission) !== $effect['is_forbidden']) {
                $updated = $relation->updateExistingPivot($permission, $effect, false) > 0 || $updated;
            }
        }

        if ($attach !== []) {
            $relation->attach($attach, $pivot, false);
            $updated = true;
        }

        if ($updated) {
            $relation->touchIfTouching();
        }
    }

    /**
     * Insert permission assignments into an empty assignment set.
     *
     * @param array<int, int|string> $permissions
     * @param array<string, mixed> $pivot
     */
    private function attachPermissionAssignments(array $permissions, array $pivot): void
    {
        if ($permissions === []) {
            return;
        }

        $relation = $this->permissions();

        $relation->attach($permissions, $pivot, false);
        $relation->touchIfTouching();
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
     * Replace queued permission assignments for the given scope.
     *
     * @param array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>}> $assignments
     * @param array<string, mixed> $scopePivot
     */
    private function replaceQueuedPermissionAssignments(array $assignments, array $scopePivot): void
    {
        $teamKey = $this->queuedPermissionAssignmentTeamKey($scopePivot);
        $this->queuedPermissionAssignments = array_values(array_filter(
            $this->queuedPermissionAssignments,
            fn (array $assignment): bool => $this->queuedPermissionAssignmentTeamKey($assignment['pivot']) !== $teamKey,
        ));

        foreach ($assignments as $assignment) {
            if ($assignment['permissions'] === []) {
                continue;
            }

            $this->queuePermissionAssignments($assignment['permissions'], $assignment['pivot']);
        }
    }

    /**
     * Attach permission assignments queued before the model was saved.
     */
    protected function attachQueuedPermissionAssignments(): void
    {
        if ($this->queuedPermissionAssignments === []) {
            return;
        }

        $registrar = $this->permissionRegistrar();

        foreach ($this->collapseQueuedPermissionAssignments() as $assignment) {
            $this->attachPermissionAssignments(
                $assignment['permissions'],
                $assignment['pivot'],
            );
        }

        $this->queuedPermissionAssignments = [];
        $this->unsetRelation('permissions');

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        } else {
            $registrar->forgetModelPermissionCache($this);
        }
    }

    /**
     * Collapse queued permission assignments to their final edge state.
     *
     * @return array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>}>
     */
    private function collapseQueuedPermissionAssignments(): array
    {
        $collapsed = [];

        // Collapse by edge first so the last queued effect wins for each permission and team.
        foreach ($this->queuedPermissionAssignments as $assignment) {
            foreach ($assignment['permissions'] as $permission) {
                $key = $permission . '|' . $this->queuedPermissionAssignmentTeamKey($assignment['pivot']);

                $collapsed[$key] = [
                    'permission' => $permission,
                    'pivot' => $assignment['pivot'],
                ];
            }
        }

        $batches = [];

        // Then batch by pivot so each distinct team and effect can be inserted together.
        foreach ($collapsed as $assignment) {
            $pivot = $assignment['pivot'];
            $batchKey = $this->queuedPermissionAssignmentTeamKey($pivot) . '|'
                . ((bool) $pivot['is_forbidden'] ? 'forbidden' : 'allowed');

            $batches[$batchKey] ??= [
                'permissions' => [],
                'pivot' => $pivot,
            ];
            $batches[$batchKey]['permissions'][] = $assignment['permission'];
        }

        return array_values($batches);
    }

    /**
     * Build the queued assignment team key.
     *
     * @param array<string, mixed> $pivot
     */
    private function queuedPermissionAssignmentTeamKey(array $pivot): string
    {
        $registrar = $this->permissionRegistrar();

        if (! $registrar->teams || $this instanceof Role) {
            return 'none';
        }

        return (string) ($pivot[$registrar->teamsKey] ?? 'global');
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

        $events = $this->eventDispatcher();

        if ($events->hasListeners(PermissionAttachedEvent::class)) {
            $events->dispatch(new PermissionAttachedEvent($this, $permissions));
        }
    }

    public function forgetWildcardPermissionIndex(): void
    {
        $this->permissionRegistrar()->forgetWildcardPermissionIndex(
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
        $permissions = $this->collectPermissions($permissions);
        $model = $this;
        $cacheCleared = false;

        if ($this->exists) {
            $pivot = $this->permissionAssignmentPivot(false);

            $this->permissions()->detach();
            $this->attachPermissionAssignments(
                $permissions,
                $pivot,
            );
            $model->unsetRelation('permissions');
        } else {
            $pivot = $this->permissionAssignmentPivot(false);

            $this->replaceQueuedPermissionAssignments(
                [
                    [
                        'permissions' => $permissions,
                        'pivot' => $pivot,
                    ],
                ],
                $pivot,
            );
        }

        if ($model->exists) {
            if ($this instanceof Role) {
                $this->forgetCachedPermissions();
                $cacheCleared = true;
            } else {
                $this->permissionRegistrar()->forgetModelPermissionCache($model);
                $cacheCleared = true;
            }
        }

        if (! $cacheCleared) {
            $this->forgetWildcardPermissionIndex();
        }

        $this->dispatchPermissionAttachedEvent($permissions);

        return $this;
    }

    /**
     * Remove all current permissions and set allowed and forbidden permissions.
     *
     * For unsaved models, assignments are queued until the model is saved and
     * the returned change set is empty because no database rows are changed yet.
     *
     * @param array<array-key, mixed>|Collection<array-key, mixed> $allowed
     * @param array<array-key, mixed>|Collection<array-key, mixed> $forbidden
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}
     */
    public function syncPermissionsWithForbidden(array|Collection $allowed = [], array|Collection $forbidden = []): array
    {
        $model = $this;

        $allowedIds = $this->collectPermissions($allowed);
        $forbiddenIds = $this->collectPermissions($forbidden);
        $allowedIds = array_values(array_filter(
            $allowedIds,
            fn (int|string $allowedId): bool => ! in_array($allowedId, $forbiddenIds, true),
        ));
        $permissions = array_merge($allowedIds, $forbiddenIds);
        $allowedPivot = $this->permissionAssignmentPivot(false);
        $forbiddenPivot = $this->permissionAssignmentPivot(true);

        if (! $model->exists) {
            $this->replaceQueuedPermissionAssignments(
                [
                    [
                        'permissions' => $allowedIds,
                        'pivot' => $allowedPivot,
                    ],
                    [
                        'permissions' => $forbiddenIds,
                        'pivot' => $forbiddenPivot,
                    ],
                ],
                $allowedPivot,
            );

            $this->forgetWildcardPermissionIndex();
            $this->dispatchPermissionAttachedEvent($permissions);

            return ['attached' => [], 'detached' => [], 'updated' => []];
        }

        $syncData = [];

        foreach ($allowedIds as $permissionId) {
            $syncData[$permissionId] = $allowedPivot;
        }

        foreach ($forbiddenIds as $permissionId) {
            $syncData[$permissionId] = $forbiddenPivot;
        }

        /** @var array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>} $changes */
        $changes = $this->permissions()->sync($syncData);

        $this->unsetRelation('permissions');

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        } else {
            $this->permissionRegistrar()->forgetModelPermissionCache($model);
        }

        $this->dispatchPermissionAttachedEvent($permissions);

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
            $this->permissionRegistrar()->forgetModelPermissionCache($this);
        }

        $this->dispatchPermissionDetachedEvent($storedPermission);

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

        $events = $this->eventDispatcher();

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
        $guardName = $this->guardNameForPermissionMatch($permission, $guardName);

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

        if ($roles->isEmpty()) {
            return false;
        }

        $registrar = $this->permissionRegistrar();

        if (! $registrar->hasForbiddenRolePermissions()) {
            return false;
        }

        $guardName = $this->guardNameForPermissionMatch($permission, $guardName);
        $storedPermission = $this->permissionForMatch($permission, $guardName);

        if (! $storedPermission instanceof Model) {
            return false;
        }

        $roleIds = array_flip($roles->map(fn (Model $role): string => (string) $role->getKey())->all());

        return $this->relationCollection($storedPermission, 'roles')
            ->contains(
                fn (Model $role): bool => isset($roleIds[(string) $role->getKey()])
                    && $this->pivotIsForbidden($role)
            );
    }

    /**
     * Return permissions granted through the model's roles with role-permission pivot data.
     */
    protected function getPermissionsViaRolesWithPivots(): Collection
    {
        if ($this instanceof Role || $this instanceof Permission) {
            return collect();
        }

        if (! $this->exists) {
            return $this->loadPermissionsViaRolesWithPivots();
        }

        return $this->permissionRegistrar()->rememberModelViaRolePermissions(
            $this,
            fn (): Collection => $this->loadPermissionsViaRolesWithPivots(),
        );
    }

    /**
     * Load permissions granted through the model's roles with role-permission pivot data.
     */
    protected function loadPermissionsViaRolesWithPivots(): Collection
    {
        if ($this instanceof Role || $this instanceof Permission) {
            return collect();
        }

        $roles = $this->getCachedRoles();

        if ($roles->isEmpty()) {
            return collect();
        }

        $roleIds = array_flip($roles->map(fn (Model $role): string => (string) $role->getKey())->all());

        return $this->permissionRegistrar()
            ->getPermissions([], false, $this->getPermissionClass())
            ->flatMap(
                fn (Model $permission): Collection => $this->relationCollection($permission, 'roles')
                    ->filter(fn (Model $role): bool => isset($roleIds[(string) $role->getKey()]))
                    ->map(fn (Model $role): Model => $this->permissionWithRolePivot($permission, $role))
            );
    }

    /**
     * Resolve a permission for matching without throwing.
     */
    protected function permissionForMatch(mixed $permission, string $guardName): ?Model
    {
        $permissionKey = Guard::getModelKeyName($this->getPermissionClass());

        if ($permission instanceof Permission) {
            if (! $permission instanceof Model || $permission->guard_name !== $guardName) {
                return null;
            }

            return $this->permissionRegistrar()
                ->getPermissions([$permissionKey => $permission->getKey(), 'guard_name' => $guardName], true, $this->getPermissionClass())
                ->first();
        }

        $permission = enum_value($permission);

        if (! is_string($permission) && ! is_int($permission)) {
            return null;
        }

        $params = is_int($permission) || PermissionRegistrar::isUid($permission)
            ? [$permissionKey => $permission, 'guard_name' => $guardName]
            : ['name' => $permission, 'guard_name' => $guardName];

        return $this->permissionRegistrar()
            ->getPermissions($params, true, $this->getPermissionClass())
            ->first();
    }

    /**
     * Build lookup keys for forbidden permissions.
     */
    protected function forbiddenPermissionKeys(Collection ...$permissionCollections): array
    {
        $keys = [];

        foreach ($permissionCollections as $permissions) {
            foreach ($permissions as $permission) {
                if ($permission instanceof Model && $this->pivotIsForbidden($permission)) {
                    $keys[$this->permissionComparisonKey($permission)] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Build a permission comparison key.
     */
    protected function permissionComparisonKey(Model $permission): string
    {
        return $permission->getAttribute('guard_name') . ':' . $permission->getKey();
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
     * Resolve the guard to use when matching stored permissions.
     */
    protected function guardNameForPermissionMatch(mixed $permission, ?string $guardName = null): string
    {
        if ($guardName !== null) {
            return $guardName;
        }

        if ($permission instanceof Permission) {
            $permissionGuard = $permission->guard_name ?? null;

            if (is_string($permissionGuard) && $permissionGuard !== '') {
                return $permissionGuard;
            }
        }

        return $this->getDefaultGuardName();
    }

    /**
     * Get the permission names.
     */
    public function getPermissionNames(): Collection
    {
        return $this->allowedDirectPermissions()->pluck('name');
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
        $this->permissionRegistrar()->forgetCachedPermissions();
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
