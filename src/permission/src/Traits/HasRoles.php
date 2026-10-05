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
use UnitEnum;

use function Hypervel\Support\enum_value;

trait HasRoles
{
    use HasPermissions;

    private ?string $roleClass = null;

    /**
     * @var array<string, array{roles: array<int, int|string>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}>
     */
    private array $queuedRoleAssignments = [];

    /**
     * Boot role cleanup.
     */
    public static function bootHasRoles(): void
    {
        static::deleted(function (Model $model): void {
            if (! static::shouldDeletePermissionAssignments($model)) {
                return;
            }

            $registrar = Container::getInstance()->make(PermissionRegistrar::class);
            $modelKey = $model->getKey();

            if ($model instanceof Permission) {
                $partition = static::permissionRecordDeletionPartition($model, $registrar);

                $registrar->runPermissionStorageMutationAfterSubjectCommit(
                    $model->getConnection(),
                    static fn () => $registrar->getPermissionConnection()->transaction(
                        static fn () => static::deletePermissionRecordAssignments(
                            $modelKey,
                            Config::modelHasPermissionsTable(),
                            $registrar->pivotPermission,
                            $partition,
                        ),
                    ),
                );

                return;
            }

            if ($model instanceof Role) {
                return;
            }

            $morphType = $model->getMorphClass();

            $registrar->runPermissionStorageMutationAfterSubjectCommit(
                $model->getConnection(),
                static function () use ($modelKey, $morphType, $registrar): void {
                    $registrar->getPermissionConnection()->transaction(
                        static function () use ($modelKey, $morphType, $registrar): void {
                            $contexts = static::deleteSubjectAssignments(
                                $morphType,
                                $modelKey,
                                Config::modelHasRolesTable(),
                            );

                            if ($contexts === null) {
                                $registrar->invalidateModelRoleCacheForIdentityAfterMutation(
                                    $morphType,
                                    (string) $modelKey,
                                    null,
                                    null,
                                );

                                return;
                            }

                            foreach ($contexts as $context) {
                                $registrar->invalidateModelRoleCacheForIdentityAfterMutation(
                                    $morphType,
                                    (string) $modelKey,
                                    $context->partition,
                                    $context->team,
                                );
                            }
                        },
                    );
                },
            );

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
        $context = $this->assignmentContext($registrar);

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
     * @return Builder<static>
     */
    public function scopeRole(
        Builder $query,
        array|Collection|int|Role|string|UnitEnum $roles,
        ?string $guard = null,
        bool $without = false,
    ): Builder {
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
     * @return Builder<static>
     */
    public function scopeWithoutRole(Builder $query, array|Collection|int|Role|string|UnitEnum $roles, ?string $guard = null): Builder
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
     * @return Builder<static>
     */
    public function scopeTeam(Builder $query, array|Collection|int|Model|string $teams, bool $without = false): Builder
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
     * @return Builder<static>
     */
    public function scopeWithoutTeam(Builder $query, array|Collection|int|Model|string $teams): Builder
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
     * @return $this
     */
    public function assignRole(array|Collection|int|Role|string|UnitEnum|null ...$roles): static
    {
        $registrar = $this->permissionRegistrar();
        $context = $this->assignmentContext($registrar);
        $registrar->ensureTeamIsSelectedForMutation($context);
        $roles = $this->collectRoles($roles, $context->partition);

        if ($roles === []) {
            $this->dispatchRoleAttachedEvent($roles);

            return $this;
        }

        if (! $this->exists) {
            $this->queueRoleAssignments($roles, $context, $registrar->getAssignmentPivotClass($this, 'roles'));
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
        $this->invalidateRoleAssignmentCaches($registrar, $context);
        $this->dispatchRoleAttachedEvent($roles);

        return $this;
    }

    /**
     * Queue role assignments until the model is saved.
     *
     * @param array<int, int|string> $roles
     * @param class-string<Pivot> $pivotClass
     */
    protected function queueRoleAssignments(
        array $roles,
        PermissionRelationContext $context,
        string $pivotClass,
    ): void {
        $identity = $context->identity();
        $queuedRoles = $this->queuedRoleAssignments[$identity]['roles'] ?? [];

        foreach ($roles as $role) {
            if (! in_array($role, $queuedRoles, true)) {
                $queuedRoles[] = $role;
            }
        }

        $this->queuedRoleAssignments[$identity] = [
            'roles' => $queuedRoles,
            'context' => $context,
            'pivotClass' => $pivotClass,
        ];
    }

    /**
     * Replace role assignments queued for a captured context.
     *
     * @param array<int, int|string> $roles
     * @param class-string<Pivot> $pivotClass
     */
    protected function replaceQueuedRoleAssignments(
        array $roles,
        PermissionRelationContext $context,
        string $pivotClass,
    ): void {
        unset($this->queuedRoleAssignments[$context->identity()]);

        if ($roles !== []) {
            $this->queueRoleAssignments($roles, $context, $pivotClass);
        }
    }

    /**
     * Remove role assignments queued for a captured context.
     *
     * @param array<int, int|string> $roles
     */
    protected function removeQueuedRoleAssignments(
        array $roles,
        PermissionRelationContext $context,
    ): void {
        $identity = $context->identity();

        if (! isset($this->queuedRoleAssignments[$identity])) {
            return;
        }

        $remainingRoles = array_values(array_filter(
            $this->queuedRoleAssignments[$identity]['roles'],
            fn (int|string $role): bool => ! in_array($role, $roles, true),
        ));

        if ($remainingRoles === []) {
            unset($this->queuedRoleAssignments[$identity]);

            return;
        }

        $this->queuedRoleAssignments[$identity]['roles'] = $remainingRoles;
    }

    /**
     * Flush all assignments queued before the model was saved.
     */
    protected function flushQueuedPermissionAssignments(): void
    {
        $roleAssignments = $this->queuedRoleAssignments;
        $permissionAssignments = $this->queuedPermissionAssignments;

        if ($roleAssignments === [] && $permissionAssignments === []) {
            return;
        }

        $registrar = $this->permissionRegistrar();

        $registrar->getPermissionConnection()->transaction(function () use ($roleAssignments, $permissionAssignments): void {
            foreach ($roleAssignments as $assignment) {
                $relation = $this->roleAssignmentRelation($assignment['context']);

                if ($assignment['pivotClass'] !== Pivot::class) {
                    $relation->using($assignment['pivotClass']);
                }

                $relation->attach($assignment['roles'], $this->roleAssignmentPivot($assignment['context']));
            }

            $this->attachQueuedPermissionAssignments($permissionAssignments);
        });

        $this->queuedRoleAssignments = [];
        $this->queuedPermissionAssignments = [];

        if ($roleAssignments !== []) {
            $this->unsetRelation('roles');
        }

        if ($permissionAssignments !== []) {
            $this->unsetRelation('permissions');
        }

        foreach ($roleAssignments as $assignment) {
            $this->invalidateRoleAssignmentCaches($registrar, $assignment['context']);
        }

        foreach ($permissionAssignments as $assignment) {
            $this->invalidatePermissionAssignmentCaches($registrar, $assignment['context']);
        }
    }

    /**
     * Invalidate the caches a role assignment change affects.
     */
    private function invalidateRoleAssignmentCaches(
        PermissionRegistrar $registrar,
        PermissionRelationContext $context,
    ): void {
        if ($this instanceof Permission) {
            $registrar->invalidatePermissionCatalogAfterMutation($context->partition);

            return;
        }

        $registrar->invalidateModelRoleCacheAfterMutation($this, $context->partition, $context->team);
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
     * @return $this
     */
    public function removeRole(array|Collection|int|Role|string|UnitEnum|null ...$role): static
    {
        $registrar = $this->permissionRegistrar();
        $context = $this->assignmentContext($registrar);
        $registrar->ensureTeamIsSelectedForMutation($context);
        $roles = $this->collectRoles($role, $context->partition);

        if ($roles === []) {
            $this->dispatchRoleDetachedEvent($roles);

            return $this;
        }

        if (! $this->exists) {
            $this->removeQueuedRoleAssignments($roles, $context);
            $this->dispatchRoleDetachedEvent($roles);

            return $this;
        }

        $this->requireModelKey($this);

        $relation = $this->roles();

        if ($relation->detach($roles) > 0) {
            $this->unsetRelation('roles');
            $this->invalidateRoleAssignmentCaches($registrar, $this->permissionRelationContext($relation));
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
     * @return $this
     */
    public function syncRoles(array|Collection|int|Role|string|UnitEnum|null ...$roles): static
    {
        $registrar = $this->permissionRegistrar();
        $context = $this->assignmentContext($registrar);
        $registrar->ensureTeamIsSelectedForMutation($context);
        $roles = $this->collectRoles($roles, $context->partition);

        if (! $this->exists) {
            $this->replaceQueuedRoleAssignments($roles, $context, $registrar->getAssignmentPivotClass($this, 'roles'));
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
            $registrar->getPermissionConnection()->transaction(function () use ($context, $relation, $rolesToAttach, $rolesToDetach): void {
                if ($rolesToDetach !== []) {
                    $relation->detach($rolesToDetach, false);
                }

                if ($rolesToAttach !== []) {
                    $relation->attach($rolesToAttach, $this->roleAssignmentPivot($context), false);
                }

                $relation->touchIfTouching();
            });

            $this->unsetRelation('roles');
            $this->invalidateRoleAssignmentCaches($registrar, $context);
        }

        if ($detachedEventRoles !== []) {
            $this->dispatchRoleDetachedEvent($detachedEventRoles);
        }

        $this->dispatchRoleAttachedEvent($roles);

        return $this;
    }

    /**
     * Determine if the model has (one of) the given role(s).
     */
    public function hasRole(array|Collection|int|Role|string|UnitEnum $roles, ?string $guard = null): bool
    {
        $roleCollection = $this->getCachedRoles();

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        // An enum names a role, even when its value is an integer or a UUID.
        if ($roles instanceof UnitEnum) {
            $roles = (string) enum_value($roles);
        } elseif (is_int($roles) || PermissionRegistrar::isUid($roles)) {
            $key = Guard::getModelKeyName($this->getRoleClass());

            return $guard !== null && $guard !== ''
                ? $roleCollection->where('guard_name', $guard)->contains($key, $roles)
                : $roleCollection->contains($key, $roles);
        }

        if (is_string($roles)) {
            $roleNames = $guard !== null && $guard !== ''
                ? $roleCollection->where('guard_name', $guard)->pluck('name')
                : $roleCollection->pluck('name');

            return $roleNames->contains(fn ($name): bool => (string) enum_value($name) === $roles);
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

        $this->ensureRoleCollectionMatchesPartition($roles);

        return $roles->intersect(
            $guard !== null && $guard !== '' ? $roleCollection->where('guard_name', $guard) : $roleCollection
        )->isNotEmpty();
    }

    /**
     * Determine if the model has any of the given role(s).
     *
     * Alias to hasRole() but without Guard controls
     */
    public function hasAnyRole(array|Collection|int|Role|string|UnitEnum ...$roles): bool
    {
        return $this->hasRole($roles);
    }

    /**
     * Determine if the model has all of the given role(s).
     */
    public function hasAllRoles(array|Collection|Role|string|UnitEnum $roles, ?string $guard = null): bool
    {
        $roleCollection = $this->getCachedRoles();

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        // Enums reach the name comparison below unconverted. Converting one here would
        // send a UUID value to hasRole() as a string, which it looks up as a role key.
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
     */
    public function hasExactRoles(array|Collection|Role|string|UnitEnum $roles, ?string $guard = null): bool
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
     * @return Model&Role
     */
    protected function getStoredRole(
        int|Role|string|UnitEnum $role,
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
        if ($partition) {
            /** @var Model&Role $role */
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
