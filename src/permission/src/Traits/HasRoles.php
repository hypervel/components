<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use TypeError;
use UnitEnum;

use function Hypervel\Support\enum_value;

trait HasRoles
{
    use HasPermissions;

    private ?string $roleClass = null;

    /**
     * @var array<string, array{roles: array<int, int|string>, pivot: array<string, mixed>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}>
     */
    private array $queuedRoleAssignments = [];

    /**
     * Boot role cleanup.
     */
    public static function bootHasRoles(): void
    {
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $registrar = Container::getInstance()->make(PermissionRegistrar::class);

            if ($model instanceof Permission) {
                static::deletePermissionRecordAssignments(
                    $model,
                    Config::modelHasPermissionsTable(),
                    $registrar->pivotPermission,
                );

                return;
            }

            if ($model instanceof Role) {
                return;
            }

            $contexts = static::deleteSubjectAssignments(
                $model,
                Config::modelHasRolesTable(),
            );

            if ($contexts === null) {
                $registrar->forgetModelRoleCache($model);
            } else {
                foreach ($contexts as $context) {
                    $registrar->forgetModelRoleCacheFor(
                        $model,
                        $context->partition,
                        $context->team,
                    );
                }
            }

            $registrar->forgetLoadedRelationProvenance($model, 'roles');
        });
    }

    /**
     * Get the role model class.
     */
    public function getRoleClass(): string
    {
        if (! $this->roleClass) {
            $this->roleClass = $this->permissionRegistrar()->getRoleClass();
        }

        return $this->roleClass;
    }

    /**
     * A model may have multiple roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->roleAssignmentRelation();
    }

    /**
     * Build the role assignment relation for a captured context.
     */
    protected function roleAssignmentRelation(
        ?PermissionRelationContext $context = null,
    ): BelongsToMany {
        $registrar = $this->permissionRegistrar();
        $teamScoped = $registrar->teams && ! $this instanceof Permission;

        $relation = $this->permissionMorphToMany(
            Config::roleModel(),
            Config::modelHasRolesTable(),
            Config::morphKey(),
            $registrar->pivotRole,
            'roles',
            teamScoped: $teamScoped,
            context: $context,
        );

        if (! $teamScoped) {
            return $relation;
        }

        $teamField = Config::rolesTable() . '.' . $registrar->teamsKey;
        $team = $context === null
            ? $registrar->getPermissionsTeamId()
            : $context->team;

        return $relation->where(
            fn ($query) => $query->whereNull($teamField)->orWhere($teamField, $team),
        );
    }

    /**
     * Get cached role assignments for this model.
     */
    protected function getCachedRoles(): Collection
    {
        $model = $this;
        $registrar = $this->permissionRegistrar();
        $context = $this->roleAssignmentContext($registrar);

        $this->forgetStalePermissionRelation($registrar, 'roles');

        if ($this instanceof Permission || ! $model->exists || $this->relationLoaded('roles')) {
            return $this->relationCollection($this, 'roles');
        }

        $roleKey = Guard::getModelKeyName($this->getRoleClass());
        $assignments = $registrar->rememberModelRoleAssignments(
            $model,
            fn (): array => $this->roleAssignmentRelation($context)
                ->get()
                ->map(fn (Model $role): array => [$roleKey => $role->getKey()])
                ->values()
                ->all(),
        );

        return $registrar
            ->getRoles([$roleKey => array_column($assignments, $roleKey)], false, $this->getRoleClass())
            ->values();
    }

    /**
     * Scope the model query to certain roles only.
     *
     * @param Builder<static> $query
     * @param array|Collection|int|Role|string|UnitEnum $roles
     * @return Builder<static>
     */
    public function scopeRole(Builder $query, $roles, ?string $guard = null, bool $without = false): Builder
    {
        if ($roles instanceof Collection) {
            $roles = $roles->all();
        }

        $partition = $this->permissionRegistrar()->resolvePartition();

        $roles = array_map(function ($role) use ($guard, $partition) {
            if ($role instanceof Role) {
                $this->ensureRoleMatchesPartition($role, $partition);

                return $role;
            }

            $role = enum_value($role);

            $method = is_int($role) || PermissionRegistrar::isUid($role) ? 'findById' : 'findByName';

            $role = $this->getRoleClass()::{$method}(
                $role,
                $guard === null || $guard === '' ? $this->getDefaultGuardName() : $guard
            );
            $this->ensureRoleMatchesPartition($role, $partition);

            return $role;
        }, Arr::wrap($roles));

        $key = Guard::getModelKeyName($this->getRoleClass());
        $roleIds = array_map(
            fn ($role) => $this->requireModelKey($role),
            $roles,
        );

        return $query->{! $without ? 'whereHas' : 'whereDoesntHave'}(
            'roles',
            fn (Builder $subQuery) => $subQuery
                ->whereIn(Config::rolesTable() . ".{$key}", $roleIds)
        );
    }

    /**
     * Scope the model query to only those without certain roles.
     *
     * @param Builder<static> $query
     * @param array|Collection|int|Role|string|UnitEnum $roles
     * @return Builder<static>
     */
    public function scopeWithoutRole(Builder $query, $roles, ?string $guard = null): Builder
    {
        return $this->scopeRole($query, $roles, $guard, true);
    }

    /**
     * A model may be part of multiple teams.
     *
     * When the teams feature is disabled this returns an empty BelongsToMany so
     * tooling that introspects model relations (e.g. ide-helper:models) does not
     * break. Querying it is a no-op and produces no rows.
     */
    public function teams(): BelongsToMany
    {
        if (! Config::teamsEnabled()) {
            $relation = $this->permissionMorphToMany(
                Config::permissionModel(),
                Config::modelHasRolesTable(),
                Config::morphKey(),
                Config::teamForeignKey(),
                'teams',
            );

            $relation->whereRaw('1 = 0');

            return $relation;
        }

        $relation = $this->permissionMorphToMany(
            Config::teamModel(),
            Config::modelHasRolesTable(),
            Config::morphKey(),
            Config::teamForeignKey(),
            'teams',
        );

        $relation->distinct();

        return $relation;
    }

    /**
     * Scope the model query to certain teams only.
     *
     * @param Builder<static> $query
     * @param array|Collection|int|Model|string $teams
     * @return Builder<static>
     */
    public function scopeTeam(Builder $query, $teams, bool $without = false): Builder
    {
        $teamModel = Config::teamModel();

        if ($teams instanceof Collection) {
            $teams = $teams->all();
        }

        $teamIds = array_map(
            fn ($team) => $team instanceof $teamModel ? $this->requireModelKey($team) : $team,
            Arr::wrap($teams),
        );

        $pivotTable = Config::modelHasRolesTable();
        $morphKey = Config::morphKey();
        $teamsKey = Config::teamForeignKey();
        $partition = $this->permissionRegistrar()->resolvePartition();

        $query->{! $without ? 'whereExists' : 'whereNotExists'}(
            function ($subQuery) use ($pivotTable, $morphKey, $query, $teamsKey, $teamIds, $partition) {
                $subQuery->from($pivotTable)
                    ->whereColumn($morphKey, $query->getModel()->getQualifiedKeyName())
                    ->where(Config::MORPH_TYPE, $query->getModel()->getMorphClass())
                    ->whereIn($teamsKey, $teamIds);

                if ($partition) {
                    $subQuery->where($partition->column, $partition->value);
                }
            }
        );

        return $query;
    }

    /**
     * Scope the model query to those without certain teams.
     *
     * @param Builder<static> $query
     * @param array|Collection|int|Model|string $teams
     * @return Builder<static>
     */
    public function scopeWithoutTeam(Builder $query, $teams): Builder
    {
        return $this->scopeTeam($query, $teams, true);
    }

    /**
     * Returns array of role ids.
     *
     * @param array|Collection|int|Role|string|UnitEnum $roles
     */
    private function collectRoles(
        mixed $roles,
        ?PermissionPartition $partition,
    ): array {
        return collect(Arr::wrap($roles))
            ->flatten()
            ->reduce(function ($array, $role) use ($partition) {
                if ($role === null || $role === '') {
                    return $array;
                }

                $role = $this->getStoredRole($role, $partition);
                $roleKey = $this->requireModelKey($role);

                if (! in_array($roleKey, $array, true)) {
                    $this->ensureModelSharesGuard($role);
                    $array[] = $roleKey;
                }

                return $array;
            }, []);
    }

    /**
     * Assign the given role to the model.
     *
     * @param array|Collection|int|Role|string|UnitEnum ...$roles
     * @return $this
     */
    public function assignRole(...$roles): static
    {
        $registrar = $this->permissionRegistrar();
        $context = $this->roleAssignmentContext($registrar);
        $roles = $this->collectRoles($roles, $context->partition);

        if ($roles === []) {
            $this->dispatchRoleAttachedEvent($roles);

            return $this;
        }

        if (! $this->exists) {
            $this->queueRoleAssignments(
                $roles,
                $this->roleAssignmentPivot($context),
                $context,
                $registrar->getAssignmentPivotClass($this, 'roles'),
            );
            $this->dispatchRoleAttachedEvent($roles);

            return $this;
        }

        $this->requireModelKey($this);

        $relation = $this->roles();
        $context = $this->permissionRelationContext($relation);
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        $currentRoles = $this->readCurrentAssignmentPivots($relation, [$relatedPivotKey], $roles)
            ->map(fn (object $pivot): int|string => $this->normalizeRelatedPivotId(
                $relation,
                $pivot->{$relatedPivotKey},
            ))
            ->all();
        $attachedRoles = array_values(array_filter(
            $roles,
            fn (int|string $role): bool => ! in_array($role, $currentRoles, true),
        ));

        if ($attachedRoles === []) {
            $this->dispatchRoleAttachedEvent($roles);

            return $this;
        }

        $relation->attach($attachedRoles, $this->roleAssignmentPivot($context));
        $this->unsetRelation('roles');

        if ($this instanceof Permission) {
            $registrar->forgetCachedPermissionsFor($context->partition);
        } else {
            $registrar->forgetModelRoleCacheFor(
                $this,
                $context->partition,
                $context->team,
            );
        }

        $this->dispatchRoleAttachedEvent($roles);

        return $this;
    }

    /**
     * Queue role assignments until the model is saved.
     *
     * @param array<int, int|string> $roles
     * @param array<string, mixed> $pivot
     * @param class-string<Pivot> $pivotClass
     */
    protected function queueRoleAssignments(
        array $roles,
        array $pivot,
        PermissionRelationContext $context,
        string $pivotClass,
    ): void {
        $identity = $context->identity() . ':' . PermissionPartition::encodeCacheSegment($pivotClass);
        $queuedRoles = $this->queuedRoleAssignments[$identity]['roles'] ?? [];

        foreach ($roles as $role) {
            if (! in_array($role, $queuedRoles, true)) {
                $queuedRoles[] = $role;
            }
        }

        $this->queuedRoleAssignments[$identity] = [
            'roles' => $queuedRoles,
            'pivot' => $pivot,
            'context' => $context,
            'pivotClass' => $pivotClass,
        ];
    }

    /**
     * Replace role assignments queued for a captured context.
     *
     * @param array<int, int|string> $roles
     * @param array<string, mixed> $pivot
     * @param class-string<Pivot> $pivotClass
     */
    protected function replaceQueuedRoleAssignments(
        array $roles,
        array $pivot,
        PermissionRelationContext $context,
        string $pivotClass,
    ): void {
        $identity = $context->identity() . ':' . PermissionPartition::encodeCacheSegment($pivotClass);

        if ($roles === []) {
            unset($this->queuedRoleAssignments[$identity]);

            return;
        }

        $this->queuedRoleAssignments[$identity] = [
            'roles' => $roles,
            'pivot' => $pivot,
            'context' => $context,
            'pivotClass' => $pivotClass,
        ];
    }

    /**
     * Remove role assignments queued for a captured context.
     *
     * @param array<int, int|string> $roles
     * @param class-string<Pivot> $pivotClass
     */
    protected function removeQueuedRoleAssignments(
        array $roles,
        PermissionRelationContext $context,
        string $pivotClass,
    ): void {
        $identity = $context->identity() . ':' . PermissionPartition::encodeCacheSegment($pivotClass);
        $assignment = $this->queuedRoleAssignments[$identity] ?? null;

        if ($assignment === null) {
            return;
        }

        $remainingRoles = array_values(array_filter(
            $assignment['roles'],
            fn (int|string $role): bool => ! in_array($role, $roles, true),
        ));

        if ($remainingRoles === $assignment['roles']) {
            return;
        }

        if ($remainingRoles === []) {
            unset($this->queuedRoleAssignments[$identity]);

            return;
        }

        $assignment['roles'] = $remainingRoles;
        $this->queuedRoleAssignments[$identity] = $assignment;
    }

    /**
     * Flush all assignments queued before the model was saved.
     */
    protected function flushQueuedPermissionAssignments(): void
    {
        $roleAssignments = array_values($this->queuedRoleAssignments);
        $permissionAssignments = $this->collapseQueuedPermissionAssignments();

        if ($roleAssignments === [] && $permissionAssignments === []) {
            return;
        }

        $this->getConnection()->transaction(function () use ($roleAssignments, $permissionAssignments): void {
            foreach ($roleAssignments as $assignment) {
                $relation = $this->roleAssignmentRelation($assignment['context']);

                if ($assignment['pivotClass'] !== Pivot::class) {
                    $relation->using($assignment['pivotClass']);
                }

                $relation->attach($assignment['roles'], $assignment['pivot']);
            }

            $this->attachQueuedPermissionAssignmentBatches($permissionAssignments);
        });

        $this->queuedRoleAssignments = [];
        $this->clearQueuedPermissionAssignments();

        if ($roleAssignments !== []) {
            $this->unsetRelation('roles');
        }

        if ($permissionAssignments !== []) {
            $this->unsetRelation('permissions');
        }

        $contexts = [];

        foreach ([...$roleAssignments, ...$permissionAssignments] as $assignment) {
            $contexts[$assignment['context']->identity()] = $assignment['context'];
        }

        $registrar = $this->permissionRegistrar();

        foreach ($contexts as $context) {
            if ($this instanceof Permission) {
                $registrar->forgetCachedPermissionsFor($context->partition);
            } else {
                $registrar->forgetModelAssignmentCacheFor(
                    $this,
                    $context->partition,
                    $context->team,
                );
            }
        }
    }

    /**
     * Dispatch the role attached event when enabled and listened for.
     *
     * @param array<int, int|string> $roles
     */
    protected function dispatchRoleAttachedEvent(array $roles): void
    {
        if (! $this->roleAttachedEventIsListenedFor()) {
            return;
        }

        $this->eventDispatcher()->dispatch(new RoleAttachedEvent($this, $roles));
    }

    /**
     * Determine whether the role attached event has listeners.
     */
    protected function roleAttachedEventIsListenedFor(): bool
    {
        return Config::eventsEnabled()
            && $this->eventDispatcher()->hasListeners(RoleAttachedEvent::class);
    }

    /**
     * Revoke the given role from the model.
     *
     * @param array|Collection|int|Role|string|UnitEnum ...$role
     * @return $this
     */
    public function removeRole(...$role): static
    {
        $registrar = $this->permissionRegistrar();
        $context = $this->roleAssignmentContext($registrar);
        $roles = $this->collectRoles($role, $context->partition);

        if ($roles === []) {
            $this->dispatchRoleDetachedEvent($roles);

            return $this;
        }

        if (! $this->exists) {
            $this->removeQueuedRoleAssignments(
                $roles,
                $context,
                $registrar->getAssignmentPivotClass($this, 'roles'),
            );
            $this->dispatchRoleDetachedEvent($roles);

            return $this;
        }

        $this->requireModelKey($this);

        $relation = $this->roles();
        $context = $this->permissionRelationContext($relation);
        $detached = $relation->detach($roles);

        if ($detached > 0) {
            $this->unsetRelation('roles');

            if ($this instanceof Permission) {
                $registrar->forgetCachedPermissionsFor($context->partition);
            } else {
                $registrar->forgetModelRoleCacheFor(
                    $this,
                    $context->partition,
                    $context->team,
                );
            }
        }

        $this->dispatchRoleDetachedEvent($roles);

        return $this;
    }

    /**
     * Dispatch the role detached event when enabled and listened for.
     *
     * @param array<int, int|string> $roles
     */
    protected function dispatchRoleDetachedEvent(array $roles): void
    {
        if (! $this->roleDetachedEventIsListenedFor()) {
            return;
        }

        $this->eventDispatcher()->dispatch(new RoleDetachedEvent($this, $roles));
    }

    /**
     * Determine whether the role detached event has listeners.
     */
    protected function roleDetachedEventIsListenedFor(): bool
    {
        return Config::eventsEnabled()
            && $this->eventDispatcher()->hasListeners(RoleDetachedEvent::class);
    }

    /**
     * Remove all current roles and set the given ones.
     *
     * @param array|Collection|int|Role|string|UnitEnum ...$roles
     * @return $this
     */
    public function syncRoles(...$roles): static
    {
        $registrar = $this->permissionRegistrar();
        $context = $this->roleAssignmentContext($registrar);
        $roles = $this->collectRoles($roles, $context->partition);

        if (! $this->exists) {
            $pivotClass = $registrar->getAssignmentPivotClass($this, 'roles');

            $this->replaceQueuedRoleAssignments(
                $roles,
                $this->roleAssignmentPivot($context),
                $context,
                $pivotClass,
            );
            $this->dispatchRoleAttachedEvent($roles);

            return $this;
        }

        $this->requireModelKey($this);

        $relation = $this->roles();
        $context = $this->permissionRelationContext($relation);
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        $currentRoles = $this->readCurrentAssignmentPivots($relation, [$relatedPivotKey])
            ->map(fn (object $pivot): int|string => $this->normalizeRelatedPivotId(
                $relation,
                $pivot->{$relatedPivotKey},
            ))
            ->all();
        $currentRolesByIdentity = $this->indexAssignmentIds($currentRoles);
        $desiredRolesByIdentity = $this->indexAssignmentIds($roles);
        $rolesToDetach = array_values(array_diff_key($currentRolesByIdentity, $desiredRolesByIdentity));
        $rolesToAttach = array_values(array_diff_key($desiredRolesByIdentity, $currentRolesByIdentity));
        $detachedEventRoles = $this->roleDetachedEventIsListenedFor() ? $currentRoles : [];

        if ($rolesToDetach !== [] || $rolesToAttach !== []) {
            $this->getConnection()->transaction(function () use ($context, $relation, $rolesToAttach, $rolesToDetach): void {
                if ($rolesToDetach !== []) {
                    $relation->detach($rolesToDetach, false);
                }

                if ($rolesToAttach !== []) {
                    $relation->attach($rolesToAttach, $this->roleAssignmentPivot($context), false);
                }

                $relation->touchIfTouching();
            });

            $this->unsetRelation('roles');

            if ($this instanceof Permission) {
                $registrar->forgetCachedPermissionsFor($context->partition);
            } else {
                $registrar->forgetModelRoleCacheFor(
                    $this,
                    $context->partition,
                    $context->team,
                );
            }
        }

        if ($detachedEventRoles !== []) {
            $this->dispatchRoleDetachedEvent($detachedEventRoles);
        }

        $this->dispatchRoleAttachedEvent($roles);

        return $this;
    }

    /**
     * Determine if the model has (one of) the given role(s).
     *
     * @param array|Collection|int|Role|string|UnitEnum $roles
     */
    public function hasRole($roles, ?string $guard = null): bool
    {
        $roleCollection = $this->getCachedRoles();

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        if ($roles instanceof UnitEnum) {
            $roles = enum_value($roles);
        }

        if (is_int($roles) || PermissionRegistrar::isUid($roles)) {
            $key = Guard::getModelKeyName($this->getRoleClass());

            return $guard !== null && $guard !== ''
                ? $roleCollection->where('guard_name', $guard)->contains($key, $roles)
                : $roleCollection->contains($key, $roles);
        }

        if (is_string($roles)) {
            $roleNames = $guard !== null && $guard !== ''
                ? $roleCollection->where('guard_name', $guard)->pluck('name')
                : $roleCollection->pluck('name');

            return $roleNames->contains(fn ($name): bool => enum_value($name) === $roles);
        }

        if ($roles instanceof Role) {
            $this->ensureRoleMatchesPartition(
                $roles,
                $this->permissionRegistrar()->resolvePartition(),
            );

            return $roleCollection->contains($roles->getKeyName(), $roles->getKey());
        }

        if (is_array($roles)) {
            foreach ($roles as $role) {
                if ($this->hasRole($role, $guard)) {
                    return true;
                }
            }

            return false;
        }

        if ($roles instanceof Collection) {
            $this->ensureRoleCollectionMatchesPartition($roles);

            return $roles->intersect(
                $guard !== null && $guard !== '' ? $roleCollection->where('guard_name', $guard) : $roleCollection
            )->isNotEmpty();
        }

        throw new TypeError('Unsupported type for $roles parameter to hasRole().');
    }

    /**
     * Determine if the model has any of the given role(s).
     *
     * Alias to hasRole() but without Guard controls
     *
     * @param array|Collection|int|Role|string|UnitEnum $roles
     */
    public function hasAnyRole(...$roles): bool
    {
        return $this->hasRole($roles);
    }

    /**
     * Determine if the model has all of the given role(s).
     *
     * @param array|Collection|Role|string|UnitEnum $roles
     */
    public function hasAllRoles($roles, ?string $guard = null): bool
    {
        $roleCollection = $this->getCachedRoles();

        $roles = enum_value($roles);

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        if (is_string($roles)) {
            return $this->hasRole($roles, $guard);
        }

        if ($roles instanceof Role) {
            $this->ensureRoleMatchesPartition(
                $roles,
                $this->permissionRegistrar()->resolvePartition(),
            );

            return $roleCollection->contains($roles->getKeyName(), $roles->getKey());
        }

        $roles = collect()->make($roles);
        $this->ensureRoleCollectionMatchesPartition($roles);
        $roles = $roles->map(fn ($role) => $role instanceof Role ? $role->name : enum_value($role));

        $roleNames = $guard !== null && $guard !== ''
            ? $roleCollection->where('guard_name', $guard)->pluck('name')
            : $this->getRoleNames();

        $roleNames = $roleNames->transform(fn ($roleName) => enum_value($roleName));

        return $roles->intersect($roleNames)->count() === $roles->count();
    }

    /**
     * Determine if the model has exactly all of the given role(s).
     *
     * @param array|Collection|Role|string|UnitEnum $roles
     */
    public function hasExactRoles($roles, ?string $guard = null): bool
    {
        $roleCollection = $this->getCachedRoles();

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        if (is_string($roles)) {
            $roles = [$roles];
        }

        if ($roles instanceof Role) {
            $this->ensureRoleMatchesPartition(
                $roles,
                $this->permissionRegistrar()->resolvePartition(),
            );

            $roles = [$roles->name];
        }

        $roles = collect()->make($roles);
        $this->ensureRoleCollectionMatchesPartition($roles);
        $roles = $roles->map(
            fn ($role) => $role instanceof Role ? $role->name : enum_value($role)
        );

        return $roleCollection->count() === $roles->count() && $this->hasAllRoles($roles, $guard);
    }

    /**
     * Return allowed permissions directly assigned to the model.
     */
    public function getDirectPermissions(): Collection
    {
        return $this->directPermissionsForModelResult()
            ->reject(fn (Model $permission): bool => $this->pivotIsDenied($permission))
            ->values();
    }

    /**
     * Get the role names.
     */
    public function getRoleNames(): Collection
    {
        return $this->getCachedRoles()->pluck('name');
    }

    /**
     * Get a stored role instance.
     *
     * @param int|Role|string|UnitEnum $role
     * @return Model&Role
     */
    protected function getStoredRole(
        $role,
        ?PermissionPartition $partition = null,
    ): Role {
        $partition ??= $this->permissionRegistrar()->resolvePartition();
        $role = enum_value($role);

        if (is_int($role) || PermissionRegistrar::isUid($role)) {
            $role = $this->getRoleClass()::findById($role, $this->getDefaultGuardName());
            $this->ensureRoleMatchesPartition($role, $partition);

            return $role;
        }

        if (is_string($role)) {
            $role = $this->getRoleClass()::findByName($role, $this->getDefaultGuardName());
            $this->ensureRoleMatchesPartition($role, $partition);

            return $role;
        }

        $this->ensureRoleMatchesPartition($role, $partition);

        return $role;
    }

    /**
     * Capture the partition and team for a role assignment operation.
     */
    private function roleAssignmentContext(PermissionRegistrar $registrar): PermissionRelationContext
    {
        $partition = $registrar->resolvePartition();

        if ($partition) {
            $attributes = $this->getAttributes();

            if ($this instanceof Permission
                || (array_key_exists($partition->column, $attributes)
                    && $attributes[$partition->column] !== null)) {
                $registrar->ensureModelMatchesPartition($this, $partition);
            }
        }

        $teamScoped = $registrar->teams && ! $this instanceof Permission;

        return new PermissionRelationContext(
            $partition,
            $teamScoped,
            $teamScoped ? $registrar->getPermissionsTeamId() : null,
        );
    }

    /**
     * Build role assignment pivot attributes for a captured context.
     *
     * @return array<string, null|int|string>
     */
    private function roleAssignmentPivot(PermissionRelationContext $context): array
    {
        $pivot = [];

        if ($context->partition) {
            $pivot[$context->partition->column] = $context->partition->value;
        }

        if ($context->teamScoped) {
            $pivot[$this->permissionRegistrar()->teamsKey] = $context->team;
        }

        return $pivot;
    }

    /**
     * Ensure a supplied role belongs to the captured partition.
     */
    private function ensureRoleMatchesPartition(Role $role, ?PermissionPartition $partition): void
    {
        if ($partition && $role instanceof Model) {
            $this->permissionRegistrar()->ensureModelMatchesPartition($role, $partition);
        }
    }

    /**
     * Ensure supplied Role models belong to the current permission partition.
     */
    private function ensureRoleCollectionMatchesPartition(Collection $roles): void
    {
        if (! PermissionRegistrar::partitioningEnabled()
            || ! $roles->contains(fn ($role): bool => $role instanceof Role)) {
            return;
        }

        $partition = $this->permissionRegistrar()->resolvePartition();

        foreach ($roles as $role) {
            if ($role instanceof Role) {
                $this->ensureRoleMatchesPartition($role, $partition);
            }
        }
    }

    /**
     * Convert a pipe-delimited role string to an array.
     */
    protected function convertPipeToArray(string $pipeString): array
    {
        $pipeString = trim($pipeString);

        if (strlen($pipeString) <= 2) {
            return [str_replace('|', '', $pipeString)];
        }

        $quoteCharacter = substr($pipeString, 0, 1);
        $endCharacter = substr($pipeString, -1, 1);

        if ($quoteCharacter !== $endCharacter) {
            return explode('|', $pipeString);
        }

        if (! in_array($quoteCharacter, ["'", '"'], true)) {
            return explode('|', $pipeString);
        }

        return explode('|', trim($pipeString, $quoteCharacter));
    }
}
