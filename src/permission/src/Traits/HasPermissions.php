<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Contracts\Wildcard;
use Hypervel\Permission\Events\PermissionAttachedEvent;
use Hypervel\Permission\Events\PermissionDetachedEvent;
use Hypervel\Permission\Exceptions\GuardDoesNotMatch;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\PermissionPartitionViolation;
use Hypervel\Permission\Exceptions\WildcardPermissionInvalidArgument;
use Hypervel\Permission\Exceptions\WildcardPermissionNotImplementsContract;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Relations\PartitionedBelongsToMany;
use Hypervel\Permission\Relations\PartitionedMorphToMany;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use UnexpectedValueException;
use UnitEnum;

use function Hypervel\Support\enum_value;

trait HasPermissions
{
    use BuildsPermissionRelations;

    private ?string $permissionClass = null;

    private ?string $wildcardClass = null;

    /**
     * @var array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}>
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
     * Boot permission cleanup and queued assignment handling.
     */
    public static function bootHasPermissions(): void
    {
        static::deleting(function (Model $model): void {
            if (! static::shouldDeletePermissionAssignments($model)) {
                return;
            }

            // Validate only so a later deleting listener can still veto before assignments are touched.
            static::requireDeletionModelKey($model);

            if ($model instanceof Permission || $model instanceof Role) {
                static::permissionRecordDeletionPartition(
                    $model,
                    Container::getInstance()->make(PermissionRegistrar::class),
                );
            }
        });

        static::deleted(function (Model $model): void {
            if (! static::shouldDeletePermissionAssignments($model)) {
                return;
            }

            if ($model instanceof Permission) {
                return;
            }

            $registrar = Container::getInstance()->make(PermissionRegistrar::class);
            $modelKey = static::requireDeletionModelKey($model);

            if ($model instanceof Role) {
                $partition = static::permissionRecordDeletionPartition($model, $registrar);

                $registrar->runPermissionStorageMutationAfterSubjectCommit(
                    $model->getConnection(),
                    static fn () => $registrar->getPermissionConnection()->transaction(
                        static fn () => static::deletePermissionRecordAssignments(
                            $modelKey,
                            Config::modelHasRolesTable(),
                            $registrar->pivotRole,
                            $partition,
                        ),
                    ),
                );

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
                                Config::modelHasPermissionsTable(),
                            );

                            if ($contexts === null) {
                                $registrar->invalidateModelPermissionCacheForIdentityAfterMutation(
                                    $morphType,
                                    (string) $modelKey,
                                    null,
                                    null,
                                );

                                return;
                            }

                            foreach ($contexts as $context) {
                                $registrar->invalidateModelPermissionCacheForIdentityAfterMutation(
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

            $registrar->forgetLoadedRelationProvenance($model, 'permissions');
        });

        static::saved(function (Model $model): void {
            if (method_exists($model, 'flushQueuedPermissionAssignments')) {
                $registrar = Container::getInstance()->make(PermissionRegistrar::class);
                $registrar->runPermissionStorageMutationAfterSubjectCommit(
                    $model->getConnection(),
                    fn () => $model->flushQueuedPermissionAssignments(),
                );
            }
        });
    }

    /**
     * Delete the model and its permission assignments atomically.
     */
    public function delete(): int|bool|null
    {
        if (! $this->exists || ! static::shouldDeletePermissionAssignments($this)) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(
            fn (): int|bool|null => parent::delete(),
        );
    }

    /**
     * Determine whether deleting the model removes permission assignments.
     */
    protected static function shouldDeletePermissionAssignments(Model $model): bool
    {
        return ! method_exists($model, 'isForceDeleting') || $model->isForceDeleting();
    }

    /**
     * Get the model key required for permission assignment cleanup.
     */
    protected static function requireDeletionModelKey(Model $model): mixed
    {
        $modelKey = $model->getKey();

        if ($modelKey === null) {
            throw new MissingAttributeException($model, $model->getKeyName());
        }

        return $modelKey;
    }

    /**
     * Delete one kind of assignment for a hard-deleted subject.
     *
     * @return null|array<int, PermissionRelationContext>
     */
    protected static function deleteSubjectAssignments(
        string $morphType,
        mixed $modelKey,
        string $table,
    ): ?array {
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);
        $connection = $registrar->getPermissionConnection();
        $morphKey = Config::morphKey();
        $partitionColumn = PermissionRegistrar::partitionColumn();

        if ($partitionColumn === null && ! $registrar->teams) {
            $connection->table($table)
                ->where($morphKey, $modelKey)
                ->where(Config::MORPH_TYPE, $morphType)
                ->delete();

            return null;
        }

        $columns = [];

        if ($partitionColumn !== null) {
            $columns[] = $partitionColumn;
        }

        if ($registrar->teams) {
            $columns[] = $registrar->teamsKey;
        }

        $scopes = $connection->table($table)
            ->select($columns)
            ->where($morphKey, $modelKey)
            ->where(Config::MORPH_TYPE, $morphType)
            ->distinct()
            ->get();
        $contexts = [];

        foreach ($scopes as $scope) {
            $partition = null;

            if ($partitionColumn !== null) {
                $partitionValue = $scope->{$partitionColumn};

                if (! is_int($partitionValue) && ! is_string($partitionValue)) {
                    throw new UnexpectedValueException(sprintf(
                        'Permission assignment partition column "%s" contained %s; expected int or string.',
                        $partitionColumn,
                        get_debug_type($partitionValue),
                    ));
                }

                $partition = new PermissionPartition($partitionColumn, $partitionValue);
            }

            $team = $registrar->teams ? $scope->{$registrar->teamsKey} : null;

            if ($team !== null && ! is_int($team) && ! is_string($team)) {
                throw new UnexpectedValueException(sprintf(
                    'Permission assignment team column "%s" contained %s; expected int, string, or null.',
                    $registrar->teamsKey,
                    get_debug_type($team),
                ));
            }

            $contexts[] = new PermissionRelationContext(
                $partition,
                $registrar->teams,
                $team,
            );
        }

        $connection->table($table)
            ->where($morphKey, $modelKey)
            ->where(Config::MORPH_TYPE, $morphType)
            ->delete();

        return $contexts;
    }

    /**
     * Delete assignment pivots owned by a Role or Permission record.
     */
    protected static function deletePermissionRecordAssignments(
        mixed $modelKey,
        string $modelAssignmentsTable,
        string $pivotKey,
        ?PermissionPartition $partition,
    ): void {
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);
        $connection = $registrar->getPermissionConnection();
        $modelAssignments = $connection
            ->table($modelAssignmentsTable)
            ->where($pivotKey, $modelKey);
        $rolePermissions = $connection
            ->table(Config::roleHasPermissionsTable())
            ->where($pivotKey, $modelKey);

        if ($partition) {
            $modelAssignments->where($partition->column, $partition->value);
            $rolePermissions->where($partition->column, $partition->value);
        }

        $modelAssignments->delete();
        $rolePermissions->delete();
    }

    /**
     * Resolve and validate a permission record's deletion partition.
     */
    protected static function permissionRecordDeletionPartition(
        Model $model,
        PermissionRegistrar $registrar,
    ): ?PermissionPartition {
        $partition = PermissionRegistrar::partitioningEnabled()
            ? $registrar->partitionFromRecord($model)
            : null;

        if (! $partition) {
            return null;
        }

        /** @var PermissionPartition $current */
        $current = $registrar->resolvePartition();

        if ($current->column !== $partition->column
            || ! $current->matches($partition->value)) {
            throw PermissionPartitionViolation::forModel(
                $model,
                $current,
                $partition->value,
            );
        }

        return $partition;
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
        return $this->permissionAssignmentRelation();
    }

    /**
     * Build the direct permission assignment relation for a captured context.
     */
    protected function permissionAssignmentRelation(
        ?PermissionRelationContext $context = null,
    ): BelongsToMany {
        $registrar = $this->permissionRegistrar();
        $teamScoped = $registrar->teams && ! $this instanceof Role;

        return $this->permissionMorphToMany(
            Config::permissionModel(),
            Config::modelHasPermissionsTable(),
            Config::morphKey(),
            $registrar->pivotPermission,
            'permissions',
            teamScoped: $teamScoped,
            context: $context,
        )->withPivot('is_denied');
    }

    /**
     * Get the immutable context captured by a permission assignment relation.
     */
    protected function permissionRelationContext(BelongsToMany $relation): PermissionRelationContext
    {
        /** @var PartitionedBelongsToMany|PartitionedMorphToMany $relation */
        return $relation->getPermissionRelationContext();
    }

    /**
     * Forget an assignment relation loaded for an earlier context.
     */
    protected function forgetStalePermissionRelation(PermissionRegistrar $registrar, string $relation): void
    {
        if ($this->relationLoaded($relation)
            && ! $registrar->loadedRelationIsCurrent($this, $relation)) {
            $this->unsetRelation($relation);
        }
    }

    /**
     * Get cached direct permission assignments for this model.
     */
    protected function getCachedDirectPermissions(): Collection
    {
        $model = $this;
        $registrar = $this->permissionRegistrar();
        $context = $this->permissionAssignmentContext($registrar);

        $this->forgetStalePermissionRelation($registrar, 'permissions');

        if ($this instanceof Role || $this instanceof Permission || ! $model->exists || $this->relationLoaded('permissions')) {
            return $this->relationCollection($this, 'permissions');
        }

        return $registrar->rememberModelDirectPermissions($model, function () use ($context, $model, $registrar): Collection {
            $permissionKey = Guard::getModelKeyName($this->getPermissionClass());
            $assignments = $registrar->rememberModelPermissionAssignments(
                $model,
                fn (): array => $this->permissionAssignmentRelation($context)
                    ->get()
                    ->map(fn (Model $permission): array => [
                        $permissionKey => $permission->getKey(),
                        'is_denied' => $this->pivotIsDenied($permission),
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
                ->map(function (array $assignment) use ($permissions, $model, $permissionKey, $registrar, $context): ?Model {
                    $permission = $permissions->get((string) $assignment[$permissionKey]);

                    if (! $permission instanceof Model) {
                        return null;
                    }

                    $pivot = [
                        $registrar->pivotPermission => $permission->getKey(),
                        Config::morphKey() => $model->getKey(),
                        Config::MORPH_TYPE => $model->getMorphClass(),
                        'is_denied' => (bool) $assignment['is_denied'],
                    ];

                    if ($registrar->teams) {
                        $pivot[$registrar->teamsKey] = $context->team;
                    }

                    if ($context->partition) {
                        $pivot[$context->partition->column] = $context->partition->value;
                    }

                    $permission = clone $permission;
                    $morphPivot = MorphPivot::fromRawAttributes(
                        $model,
                        $pivot,
                        Config::modelHasPermissionsTable(),
                        true,
                    );
                    $morphPivot
                        ->setPivotKeys(Config::morphKey(), $registrar->pivotPermission)
                        ->setRelatedModel($permission)
                        ->setMorphType(Config::MORPH_TYPE)
                        ->setMorphClass($model->getMorphClass());

                    $pivotWheres = [];
                    $pivotWhereNulls = [];

                    if ($context->partition) {
                        $pivotWheres[] = [
                            $context->partition->column,
                            '=',
                            $context->partition->value,
                        ];
                    }

                    if ($context->teamScoped) {
                        if ($context->team === null) {
                            $pivotWhereNulls[] = [$registrar->teamsKey];
                        } else {
                            $pivotWheres[] = [$registrar->teamsKey, '=', $context->team];
                        }
                    }

                    if ($pivotWheres !== [] || $pivotWhereNulls !== []) {
                        $morphPivot->setPivotConstraints(
                            wheres: $pivotWheres,
                            whereIns: [],
                            whereNulls: $pivotWhereNulls,
                            whereBetweens: [],
                        );
                    }

                    $permission->setRelation('pivot', $morphPivot);

                    return $permission;
                })
                ->filter()
                ->values();
        });
    }

    /**
     * Get direct permissions for a model-returning public API.
     */
    protected function directPermissionsForModelResult(): Collection
    {
        if (! $this->exists) {
            return $this->getCachedDirectPermissions();
        }

        $registrar = $this->permissionRegistrar();

        if ($registrar->getAssignmentPivotClass($this, 'permissions') === Pivot::class) {
            return $this->getCachedDirectPermissions();
        }

        return $this->relationCollection($this, 'permissions');
    }

    /**
     * Return allowed direct permissions.
     */
    protected function allowedDirectPermissions(): Collection
    {
        return $this->getCachedDirectPermissions()
            ->reject(fn (Model $permission): bool => $this->pivotIsDenied($permission))
            ->values();
    }

    /**
     * Scope the model query to certain permissions only.
     *
     * @param Builder<static> $query
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     * @return Builder<static>
     */
    public function scopePermission(Builder $query, $permissions, bool $without = false): Builder
    {
        $permissions = $this->convertToPermissionModels($permissions);
        $permissionIds = array_map(
            fn ($permission) => $this->requireModelKey($permission),
            $permissions,
        );
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
    protected function wherePermissionEffect(Builder $query, int|string $permissionId, bool $denied): Builder
    {
        $query->whereHas(
            'permissions',
            fn (Builder $query) => $this->whereDirectPermissionEffect($query, $permissionId, $denied),
        );

        if (! $this instanceof Role) {
            $query->orWhereHas(
                'roles.permissions',
                fn (Builder $query) => $this->whereRolePermissionEffect($query, $permissionId, $denied),
            );
        }

        return $query;
    }

    /**
     * Add a direct permission-effect predicate.
     */
    protected function whereDirectPermissionEffect(Builder $query, int|string $permissionId, bool $denied): Builder
    {
        $permissionKey = Guard::getModelKeyName($this->getPermissionClass());
        $pivotTable = $this instanceof Role
            ? Config::roleHasPermissionsTable()
            : Config::modelHasPermissionsTable();

        return $query
            ->where(Config::permissionsTable() . ".{$permissionKey}", $permissionId)
            ->where("{$pivotTable}.is_denied", $denied);
    }

    /**
     * Add a role permission-effect predicate.
     */
    protected function whereRolePermissionEffect(Builder $query, int|string $permissionId, bool $denied): Builder
    {
        $permissionKey = Guard::getModelKeyName($this->getPermissionClass());

        return $query
            ->where(Config::permissionsTable() . ".{$permissionKey}", $permissionId)
            ->where(Config::roleHasPermissionsTable() . '.is_denied', $denied);
    }

    /**
     * Scope the model query to only those without certain permissions,
     * whether indirectly by role or by direct permission.
     *
     * @param Builder<static> $query
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     * @return Builder<static>
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

        $partition = $this->permissionRegistrar()->resolvePartition();

        return array_map(function ($permission) use ($partition) {
            if ($permission instanceof Permission) {
                $this->ensurePermissionMatchesPartition($permission, $partition);

                return $permission;
            }

            $permission = enum_value($permission);

            $method = is_int($permission) || PermissionRegistrar::isUid($permission) ? 'findById' : 'findByName';

            $permission = $this->getPermissionClass()::{$method}($permission, $this->getDefaultGuardName());
            $this->ensurePermissionMatchesPartition($permission, $partition);

            return $permission;
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

        $this->ensurePermissionMatchesPartition(
            $permission,
            $this->permissionRegistrar()->resolvePartition(),
        );

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
            if ($this->hasDeniedPermission($permission, $guardName)) {
                return false;
            }

            if ($this->hasDeniedPermissionViaRoles($permission, $guardName)) {
                return false;
            }

            return $this->hasWildcardPermission($permission, $guardName);
        }

        $permission = $this->filterPermission($permission, $guardName);

        if ($this->hasDeniedPermission($permission, $guardName)) {
            return false;
        }

        if ($this->hasDeniedPermissionViaRoles($permission, $guardName)) {
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
                ->reject(fn (Model $role): bool => $this->pivotIsDenied($role))
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
            && ! $matches->contains(fn (Model $directPermission): bool => $this->pivotIsDenied($directPermission));
    }

    /**
     * Return all the permissions the model has via roles.
     */
    public function getPermissionsViaRoles(): Collection
    {
        $permissions = $this->getPermissionsViaRolesWithPivots();
        $deniedPermissionKeys = $this->deniedPermissionKeys($permissions);

        return $permissions
            ->reject(fn (Model $permission): bool => isset($deniedPermissionKeys[$this->permissionComparisonKey($permission)]))
            ->unique(fn (Model $permission): string => $this->permissionComparisonKey($permission))
            ->sort()
            ->values();
    }

    /**
     * Return all the permissions the model has, both directly and via roles.
     */
    public function getAllPermissions(): Collection
    {
        $directPermissions = $this->directPermissionsForModelResult();
        $viaRolePermissions = $this instanceof Permission
            ? collect()
            : $this->getPermissionsViaRolesWithPivots();
        $deniedPermissionKeys = $this->deniedPermissionKeys($directPermissions, $viaRolePermissions);

        return $directPermissions
            ->merge($viaRolePermissions)
            ->reject(fn (Model $permission): bool => isset($deniedPermissionKeys[$this->permissionComparisonKey($permission)]))
            ->unique(fn (Model $permission): string => $this->permissionComparisonKey($permission))
            ->sort()
            ->values();
    }

    /**
     * Returns array of permissions ids.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    private function collectPermissions(
        mixed $permissions,
        ?PermissionPartition $partition,
    ): array {
        return collect(Arr::wrap($permissions))
            ->flatten()
            ->reduce(function ($array, $permission) use ($partition) {
                if ($permission === null || $permission === '') {
                    return $array;
                }

                $permission = $this->getStoredPermission($permission, $partition);
                if (! $permission instanceof Permission) {
                    return $array;
                }

                $permissionKey = $this->requireModelKey($permission);

                if (! in_array($permissionKey, $array, true)) {
                    $this->ensureModelSharesGuard($permission);
                    $array[] = $permissionKey;
                }

                return $array;
            }, []);
    }

    /**
     * Get a required model key.
     */
    private function requireModelKey(Model $model): mixed
    {
        $key = $model->getKey();

        if ($key === null) {
            throw new MissingAttributeException($model, $model->getKeyName());
        }

        return $key;
    }

    /**
     * Grant the given permission(s) to the model.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    public function givePermissionTo(...$permissions): static
    {
        return $this->attachPermissions($permissions, false);
    }

    /**
     * Deny the given permission(s) for the model.
     *
     * @param array|Collection|int|Permission|string|UnitEnum $permissions
     */
    public function denyPermissionTo(...$permissions): static
    {
        return $this->attachPermissions($permissions, true);
    }

    /**
     * Attach permissions with the given denied flag.
     *
     * @param array<int, mixed> $permissions
     */
    private function attachPermissions(array $permissions, bool $isDenied): static
    {
        $model = $this;
        $registrar = $this->permissionRegistrar();
        $context = $this->permissionAssignmentContext($registrar);
        $registrar->ensureTeamIsSelectedForMutation($context);
        $permissions = $this->collectPermissions($permissions, $context->partition);

        if ($permissions === []) {
            $this->dispatchPermissionAttachedEvent($permissions);

            return $this;
        }

        if (! $model->exists) {
            $this->queuePermissionAssignments(
                $permissions,
                $this->permissionAssignmentPivot($isDenied, $context),
                $context,
                $registrar->getAssignmentPivotClass($this, 'permissions'),
            );
            $this->dispatchPermissionAttachedEvent($permissions);

            return $this;
        }

        $this->requireModelKey($model);

        $relation = $this->permissions();
        $context = $this->permissionRelationContext($relation);

        $changes = $this->synchronizePermissionAssignments(
            $isDenied ? [] : $permissions,
            $isDenied ? $permissions : [],
            $relation,
            $context,
            false,
        );
        if ($changes['attached'] === [] && $changes['updated'] === []) {
            $this->dispatchPermissionAttachedEvent($permissions);

            return $this;
        }

        $model->unsetRelation('permissions');

        if ($this instanceof Role) {
            $registrar->invalidatePermissionCatalogAfterMutation($context->partition);
        } else {
            $registrar->invalidateModelPermissionCacheAfterMutation(
                $model,
                $context->partition,
                $context->team,
            );
        }

        $this->dispatchPermissionAttachedEvent($permissions);

        return $this;
    }

    /**
     * Build permission assignment pivot attributes.
     *
     * @return array<string, mixed>
     */
    private function permissionAssignmentPivot(
        bool $isDenied,
        PermissionRelationContext $context,
    ): array {
        $registrar = $this->permissionRegistrar();

        $pivot = ['is_denied' => $isDenied];

        if ($context->partition) {
            $pivot[$context->partition->column] = $context->partition->value;
        }

        if ($context->teamScoped) {
            $pivot[$registrar->teamsKey] = $context->team;
        }

        return $pivot;
    }

    /**
     * Build a collision-safe assignment ID identity.
     */
    protected function assignmentIdIdentity(int|string $id): string
    {
        return PermissionPartition::encodeCacheSegment($id);
    }

    /**
     * Index assignment IDs by their collision-safe identities.
     *
     * @param array<int, int|string> $ids
     * @return array<string, int|string>
     */
    protected function indexAssignmentIds(array $ids): array
    {
        $indexed = [];

        foreach ($ids as $id) {
            $indexed[$this->assignmentIdIdentity($id)] = $id;
        }

        return $indexed;
    }

    /**
     * Normalize an ID read directly from an assignment pivot.
     */
    protected function normalizeRelatedPivotId(BelongsToMany $relation, mixed $id): int|string
    {
        return $relation->getRelated()->getKeyType() === 'int'
            ? (int) $id
            : (string) $id;
    }

    /**
     * Read current assignment pivots through their captured relation constraints.
     *
     * @param array<int, string> $columns
     * @param null|array<int, int|string> $ids
     */
    protected function readCurrentAssignmentPivots(
        BelongsToMany $relation,
        array $columns,
        ?array $ids = null,
    ): Collection {
        $query = $relation->newPivotQuery()->select($columns);

        if ($ids !== null) {
            $query->whereIn($relation->getRelatedPivotKeyName(), $ids);
        }

        return $query->get();
    }

    /**
     * Synchronize direct permission assignment presence and effects.
     *
     * @param array<int, int|string> $allowed
     * @param array<int, int|string> $denied
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}
     */
    private function synchronizePermissionAssignments(
        array $allowed,
        array $denied,
        BelongsToMany $relation,
        PermissionRelationContext $context,
        bool $detaching,
    ): array {
        $desired = [];

        foreach ($this->indexAssignmentIds($allowed) as $identity => $permission) {
            $desired[$identity] = [
                'id' => $permission,
                'is_denied' => false,
            ];
        }

        foreach ($this->indexAssignmentIds($denied) as $identity => $permission) {
            $desired[$identity] = [
                'id' => $permission,
                'is_denied' => true,
            ];
        }

        return $this->permissionRegistrar()->getPermissionConnection()->transaction(function () use ($context, $desired, $detaching, $relation): array {
            $relatedPivotKey = $relation->getRelatedPivotKeyName();
            $pivots = $this->readCurrentAssignmentPivots(
                $relation,
                [$relatedPivotKey, 'is_denied'],
                $detaching ? null : array_column($desired, 'id'),
            );

            $current = [];

            foreach ($pivots as $pivot) {
                $id = $this->normalizeRelatedPivotId($relation, $pivot->{$relatedPivotKey});

                $current[$this->assignmentIdIdentity($id)] = [
                    'id' => $id,
                    'is_denied' => $this->permissionEffectIsDenied($pivot->is_denied),
                ];
            }

            $changes = [
                'attached' => [],
                'detached' => [],
                'updated' => [],
            ];
            $attachAllowed = [];
            $attachDenied = [];
            $updateAllowed = [];
            $updateDenied = [];

            if ($detaching) {
                foreach (array_diff_key($current, $desired) as $assignment) {
                    $changes['detached'][] = $assignment['id'];
                }
            }

            foreach ($desired as $identity => $assignment) {
                $currentAssignment = $current[$identity] ?? null;

                if ($currentAssignment === null) {
                    $changes['attached'][] = $assignment['id'];

                    if ($assignment['is_denied']) {
                        $attachDenied[] = $assignment['id'];
                    } else {
                        $attachAllowed[] = $assignment['id'];
                    }

                    continue;
                }

                if ($currentAssignment['is_denied'] !== $assignment['is_denied']) {
                    $changes['updated'][] = $assignment['id'];

                    if ($assignment['is_denied']) {
                        $updateDenied[] = $assignment['id'];
                    } else {
                        $updateAllowed[] = $assignment['id'];
                    }
                }
            }

            if ($changes['detached'] !== []) {
                $relation->detach($changes['detached'], false);
            }

            if ($attachAllowed !== []) {
                $relation->attach(
                    $attachAllowed,
                    $this->permissionAssignmentPivot(false, $context),
                    false,
                );
            }

            if ($attachDenied !== []) {
                $relation->attach(
                    $attachDenied,
                    $this->permissionAssignmentPivot(true, $context),
                    false,
                );
            }

            if ($updateAllowed !== []) {
                // Stock pivots update in bulk; custom pivots retain their casts and model events.
                if ($relation->getPivotClass() === Pivot::class) {
                    $relation->newPivotQuery()
                        ->whereIn($relatedPivotKey, $updateAllowed)
                        ->update(['is_denied' => false]);
                } else {
                    foreach ($updateAllowed as $id) {
                        $relation->updateExistingPivot($id, ['is_denied' => false], false);
                    }
                }
            }

            if ($updateDenied !== []) {
                if ($relation->getPivotClass() === Pivot::class) {
                    $relation->newPivotQuery()
                        ->whereIn($relatedPivotKey, $updateDenied)
                        ->update(['is_denied' => true]);
                } else {
                    foreach ($updateDenied as $id) {
                        $relation->updateExistingPivot($id, ['is_denied' => true], false);
                    }
                }
            }

            if ($changes['attached'] !== []
                || $changes['detached'] !== []
                || $changes['updated'] !== []) {
                $relation->touchIfTouching();
            }

            return $changes;
        });
    }

    /**
     * Insert permission assignments into an empty assignment set.
     *
     * @param array<int, int|string> $permissions
     * @param array<string, mixed> $pivot
     * @param class-string<Pivot> $pivotClass
     */
    protected function attachPermissionAssignments(
        array $permissions,
        array $pivot,
        ?PermissionRelationContext $context,
        string $pivotClass,
    ): void {
        if ($permissions === []) {
            return;
        }

        $relation = $this->permissionAssignmentRelation($context);

        if ($pivotClass !== Pivot::class) {
            $relation->using($pivotClass);
        }

        $relation->attach($permissions, $pivot, false);
        $relation->touchIfTouching();
    }

    /**
     * Queue permission assignments until the model is saved.
     *
     * @param array<int, int|string> $permissions
     * @param array<string, mixed> $pivot
     * @param class-string<Pivot> $pivotClass
     */
    protected function queuePermissionAssignments(
        array $permissions,
        array $pivot,
        PermissionRelationContext $context,
        string $pivotClass,
    ): void {
        $this->queuedPermissionAssignments[] = [
            'permissions' => $permissions,
            'pivot' => $pivot,
            'context' => $context,
            'pivotClass' => $pivotClass,
        ];
    }

    /**
     * Replace queued permission assignments for the given scope.
     *
     * @param array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}> $assignments
     */
    private function replaceQueuedPermissionAssignments(
        array $assignments,
        PermissionRelationContext $context,
    ): void {
        $scopeKey = $context->identity();

        $this->queuedPermissionAssignments = array_values(array_filter(
            $this->queuedPermissionAssignments,
            fn (array $assignment): bool => $assignment['context']->identity() !== $scopeKey,
        ));

        foreach ($assignments as $assignment) {
            if ($assignment['permissions'] === []) {
                continue;
            }

            $this->queuePermissionAssignments(
                $assignment['permissions'],
                $assignment['pivot'],
                $assignment['context'],
                $assignment['pivotClass'],
            );
        }
    }

    /**
     * Remove permission assignments queued for a captured context.
     *
     * @param array<int, int|string> $permissions
     */
    protected function removeQueuedPermissionAssignments(
        array $permissions,
        PermissionRelationContext $context,
    ): void {
        $identity = $context->identity();
        $assignments = [];

        foreach ($this->queuedPermissionAssignments as $assignment) {
            if ($assignment['context']->identity() !== $identity) {
                $assignments[] = $assignment;
                continue;
            }

            $remainingPermissions = array_values(array_filter(
                $assignment['permissions'],
                fn (int|string $permission): bool => ! in_array($permission, $permissions, true),
            ));

            if ($remainingPermissions === $assignment['permissions']) {
                $assignments[] = $assignment;
                continue;
            }

            if ($remainingPermissions !== []) {
                $assignment['permissions'] = $remainingPermissions;
                $assignments[] = $assignment;
            }
        }

        $this->queuedPermissionAssignments = $assignments;
    }

    /**
     * Flush permission assignments queued before the model was saved.
     */
    protected function flushQueuedPermissionAssignments(): void
    {
        $assignments = $this->collapseQueuedPermissionAssignments();

        if ($assignments === []) {
            return;
        }

        $registrar = $this->permissionRegistrar();
        $this->ensureQueuedPermissionAssignmentTeamsSelected($assignments, $registrar);

        $registrar->getPermissionConnection()->transaction(
            fn () => $this->attachQueuedPermissionAssignmentBatches($assignments),
        );

        $this->clearQueuedPermissionAssignments();
        $this->unsetRelation('permissions');
        $this->invalidateQueuedPermissionAssignmentContexts($assignments);
    }

    /**
     * Attach collapsed queued permission assignment batches.
     *
     * @param array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}> $assignments
     */
    protected function attachQueuedPermissionAssignmentBatches(array $assignments): void
    {
        foreach ($assignments as $assignment) {
            $this->attachPermissionAssignments(
                $assignment['permissions'],
                $assignment['pivot'],
                $assignment['context'],
                $assignment['pivotClass'],
            );
        }
    }

    /**
     * Ensure queued permission assignments have their required team context.
     *
     * @param array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}> $assignments
     */
    protected function ensureQueuedPermissionAssignmentTeamsSelected(
        array $assignments,
        PermissionRegistrar $registrar,
    ): void {
        foreach ($assignments as $assignment) {
            $registrar->ensureTeamIsSelectedForMutation($assignment['context']);
        }
    }

    /**
     * Clear queued permission assignments after their transaction commits.
     */
    protected function clearQueuedPermissionAssignments(): void
    {
        $this->queuedPermissionAssignments = [];
    }

    /**
     * Invalidate the captured contexts of committed permission assignments.
     *
     * @param array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}> $assignments
     */
    protected function invalidateQueuedPermissionAssignmentContexts(array $assignments): void
    {
        $registrar = $this->permissionRegistrar();
        $contexts = [];

        foreach ($assignments as $assignment) {
            $contexts[$assignment['context']->identity()] = $assignment['context'];
        }

        foreach ($contexts as $context) {
            if ($this instanceof Role) {
                $registrar->invalidatePermissionCatalogAfterMutation($context->partition);
            } else {
                $registrar->invalidateModelPermissionCacheAfterMutation(
                    $this,
                    $context->partition,
                    $context->team,
                );
            }
        }
    }

    /**
     * Collapse queued permission assignments to their final edge state.
     *
     * @return array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>, context: PermissionRelationContext, pivotClass: class-string<Pivot>}>
     */
    protected function collapseQueuedPermissionAssignments(): array
    {
        $collapsed = [];

        // Collapse by edge first so the last queued effect wins for each permission and team.
        foreach ($this->queuedPermissionAssignments as $assignment) {
            foreach ($assignment['permissions'] as $permission) {
                $key = PermissionPartition::encodeCacheSegment($permission)
                    . ':' . $assignment['context']->identity();

                $collapsed[$key] = [
                    'permission' => $permission,
                    'pivot' => $assignment['pivot'],
                    'context' => $assignment['context'],
                    'pivotClass' => $assignment['pivotClass'],
                ];
            }
        }

        $batches = [];

        // Then batch by pivot so each distinct team and effect can be inserted together.
        foreach ($collapsed as $assignment) {
            $pivot = $assignment['pivot'];
            $batchKey = $assignment['context']->identity() . ':'
                . ((bool) $pivot['is_denied'] ? 'denied' : 'allowed') . ':'
                . PermissionPartition::encodeCacheSegment($assignment['pivotClass']);

            $batches[$batchKey] ??= [
                'permissions' => [],
                'pivot' => $pivot,
                'context' => $assignment['context'],
                'pivotClass' => $assignment['pivotClass'],
            ];
            $batches[$batchKey]['permissions'][] = $assignment['permission'];
        }

        return array_values($batches);
    }

    /**
     * Dispatch the permission attached event when enabled and listened for.
     *
     * @param array<int, int|string> $permissions
     */
    protected function dispatchPermissionAttachedEvent(array $permissions): void
    {
        if (! $this->permissionAttachedEventIsListenedFor()) {
            return;
        }

        $this->eventDispatcher()->dispatch(new PermissionAttachedEvent($this, $permissions));
    }

    /**
     * Determine whether the permission attached event has listeners.
     */
    protected function permissionAttachedEventIsListenedFor(): bool
    {
        return Config::eventsEnabled()
            && $this->eventDispatcher()->hasListeners(PermissionAttachedEvent::class);
    }

    /**
     * Forget the wildcard permission index.
     */
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
        $registrar = $this->permissionRegistrar();
        $context = $this->permissionAssignmentContext($registrar);
        $registrar->ensureTeamIsSelectedForMutation($context);
        $permissions = $this->collectPermissions($permissions, $context->partition);

        if (! $this->exists) {
            $this->replaceQueuedPermissionAssignments(
                [
                    [
                        'permissions' => $permissions,
                        'pivot' => $this->permissionAssignmentPivot(false, $context),
                        'context' => $context,
                        'pivotClass' => $registrar->getAssignmentPivotClass($this, 'permissions'),
                    ],
                ],
                $context,
            );
            $this->dispatchPermissionAttachedEvent($permissions);

            return $this;
        }

        $this->requireModelKey($this);

        $relation = $this->permissions();
        $context = $this->permissionRelationContext($relation);
        $detachedPermissions = $this->permissionDetachedEventIsListenedFor()
            ? $relation->get()
            : new Collection;

        $changes = $this->synchronizePermissionAssignments(
            $permissions,
            [],
            $relation,
            $context,
            true,
        );

        if ($changes['attached'] !== []
            || $changes['detached'] !== []
            || $changes['updated'] !== []) {
            $this->unsetRelation('permissions');

            if ($this instanceof Role) {
                $registrar->invalidatePermissionCatalogAfterMutation($context->partition);
            } else {
                $registrar->invalidateModelPermissionCacheAfterMutation(
                    $this,
                    $context->partition,
                    $context->team,
                );
            }
        }

        if ($detachedPermissions->isNotEmpty()) {
            $this->dispatchPermissionDetachedEvent($detachedPermissions);
        }

        $this->dispatchPermissionAttachedEvent($permissions);

        return $this;
    }

    /**
     * Remove all current permissions and set allowed and denied permissions.
     *
     * For unsaved models, assignments are queued until the model is saved and
     * the returned change set is empty because no database rows are changed yet.
     *
     * @param array<array-key, mixed>|Collection<array-key, mixed> $allowed
     * @param array<array-key, mixed>|Collection<array-key, mixed> $denied
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}
     */
    public function syncPermissionEffects(array|Collection $allowed = [], array|Collection $denied = []): array
    {
        $registrar = $this->permissionRegistrar();
        $context = $this->permissionAssignmentContext($registrar);
        $registrar->ensureTeamIsSelectedForMutation($context);

        $allowedIds = $this->collectPermissions($allowed, $context->partition);
        $deniedIds = $this->collectPermissions($denied, $context->partition);
        $allowedIds = array_values(array_filter(
            $allowedIds,
            fn (int|string $allowedId): bool => ! in_array($allowedId, $deniedIds, true),
        ));
        $permissions = array_merge($allowedIds, $deniedIds);

        if (! $this->exists) {
            $pivotClass = $registrar->getAssignmentPivotClass($this, 'permissions');

            $this->replaceQueuedPermissionAssignments(
                [
                    [
                        'permissions' => $allowedIds,
                        'pivot' => $this->permissionAssignmentPivot(false, $context),
                        'context' => $context,
                        'pivotClass' => $pivotClass,
                    ],
                    [
                        'permissions' => $deniedIds,
                        'pivot' => $this->permissionAssignmentPivot(true, $context),
                        'context' => $context,
                        'pivotClass' => $pivotClass,
                    ],
                ],
                $context,
            );
            $this->dispatchPermissionAttachedEvent($permissions);

            return ['attached' => [], 'detached' => [], 'updated' => []];
        }

        $this->requireModelKey($this);

        $relation = $this->permissions();
        $context = $this->permissionRelationContext($relation);
        $detachedPermissions = $this->permissionDetachedEventIsListenedFor()
            ? $relation->get()
            : new Collection;

        $changes = $this->synchronizePermissionAssignments(
            $allowedIds,
            $deniedIds,
            $relation,
            $context,
            true,
        );

        if ($changes['attached'] !== []
            || $changes['detached'] !== []
            || $changes['updated'] !== []) {
            $this->unsetRelation('permissions');

            if ($this instanceof Role) {
                $registrar->invalidatePermissionCatalogAfterMutation($context->partition);
            } else {
                $registrar->invalidateModelPermissionCacheAfterMutation(
                    $this,
                    $context->partition,
                    $context->team,
                );
            }
        }

        if ($detachedPermissions->isNotEmpty()) {
            $this->dispatchPermissionDetachedEvent($detachedPermissions);
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
        $registrar = $this->permissionRegistrar();
        $context = $this->permissionAssignmentContext($registrar);
        $registrar->ensureTeamIsSelectedForMutation($context);
        $storedPermission = $this->getStoredPermission($permission, $context->partition);
        $permissions = $this->collectPermissions($storedPermission, $context->partition);

        if ($permissions === []) {
            $this->dispatchPermissionDetachedEvent($storedPermission);

            return $this;
        }

        if (! $this->exists) {
            $this->removeQueuedPermissionAssignments($permissions, $context);
            $this->dispatchPermissionDetachedEvent($storedPermission);

            return $this;
        }

        $this->requireModelKey($this);

        $relation = $this->permissions();
        $context = $this->permissionRelationContext($relation);
        $detached = $relation->detach($storedPermission);

        if ($detached > 0) {
            if ($this instanceof Role) {
                $registrar->invalidatePermissionCatalogAfterMutation($context->partition);
            } else {
                $registrar->invalidateModelPermissionCacheAfterMutation(
                    $this,
                    $context->partition,
                    $context->team,
                );
            }

            $this->unsetRelation('permissions');
        }

        $this->dispatchPermissionDetachedEvent($storedPermission);

        return $this;
    }

    /**
     * Dispatch the permission detached event when enabled and listened for.
     */
    protected function dispatchPermissionDetachedEvent(mixed $permission): void
    {
        if (! $this->permissionDetachedEventIsListenedFor()) {
            return;
        }

        $this->eventDispatcher()->dispatch(new PermissionDetachedEvent($this, $permission));
    }

    /**
     * Determine whether the permission detached event has listeners.
     */
    protected function permissionDetachedEventIsListenedFor(): bool
    {
        return Config::eventsEnabled()
            && $this->eventDispatcher()->hasListeners(PermissionDetachedEvent::class);
    }

    /**
     * Determine if the model has an explicit denied direct permission.
     *
     * @param int|Permission|string|UnitEnum $permission
     */
    public function hasDeniedPermission($permission, ?string $guardName = null): bool
    {
        $guardName = $this->guardNameForPermissionMatch($permission, $guardName);

        return $this->getCachedDirectPermissions()
            ->contains(
                fn (Model $storedPermission): bool => $this->pivotIsDenied($storedPermission)
                && $this->storedPermissionMatches($storedPermission, $permission, $guardName)
            );
    }

    /**
     * Determine if the model has an explicit denied permission via roles.
     *
     * @param int|Permission|string|UnitEnum $permission
     */
    public function hasDeniedPermissionViaRoles($permission, ?string $guardName = null): bool
    {
        if ($this instanceof Role || $this instanceof Permission) {
            return false;
        }

        $roles = $this->getCachedRoles();

        if ($roles->isEmpty()) {
            return false;
        }

        $registrar = $this->permissionRegistrar();

        if (! $registrar->hasDeniedRolePermissions()) {
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
                    && $this->pivotIsDenied($role)
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
        $registrar = $this->permissionRegistrar();
        $partition = $registrar->resolvePartition();

        return $registrar
            ->getPermissions([], false, $this->getPermissionClass())
            ->flatMap(
                fn (Model $permission): Collection => $this->relationCollection($permission, 'roles')
                    ->filter(fn (Model $role): bool => isset($roleIds[(string) $role->getKey()]))
                    ->map(fn (Model $role): Model => $this->permissionWithRolePivot(
                        $permission,
                        $role,
                        $registrar,
                        $partition,
                    ))
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
     * Build lookup keys for denied permissions.
     */
    protected function deniedPermissionKeys(Collection ...$permissionCollections): array
    {
        $keys = [];

        foreach ($permissionCollections as $permissions) {
            foreach ($permissions as $permission) {
                if ($permission instanceof Model && $this->pivotIsDenied($permission)) {
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
    protected function permissionWithRolePivot(
        Model $permission,
        Model $role,
        PermissionRegistrar $registrar,
        ?PermissionPartition $partition,
    ): Model {
        $permission = clone $permission;
        /** @var Pivot $cachedPivot */
        $cachedPivot = $role->getRelation('pivot');
        $pivot = Pivot::fromRawAttributes(
            $role,
            $cachedPivot->getAttributes(),
            Config::roleHasPermissionsTable(),
            true,
        );
        $pivot->setPivotKeys($registrar->pivotRole, $registrar->pivotPermission)
            ->setRelatedModel($permission);

        if ($partition) {
            $pivot->setPivotConstraints(
                wheres: [[$partition->column, '=', $partition->value]],
                whereIns: [],
                whereNulls: [],
                whereBetweens: [],
            );
        }

        $permission->setRelation('pivot', $pivot);

        return $permission;
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

        return $pivot instanceof Pivot
            && $this->permissionEffectIsDenied($pivot->getAttribute('is_denied'));
    }

    /**
     * Normalize a permission assignment effect.
     */
    protected function permissionEffectIsDenied(mixed $value): bool
    {
        // Framework connectors disable stringified fetches, so booleans arrive as bool or 0/1.
        return (bool) $value;
    }

    /**
     * Get a hydrated relation collection.
     */
    protected function relationCollection(Model $model, string $relation): Collection
    {
        $registrar = $this->permissionRegistrar();

        if ($model->relationLoaded($relation)
            && ! $registrar->loadedRelationIsCurrent($model, $relation)) {
            $model->unsetRelation($relation);
        }

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
        if ($permission instanceof Permission) {
            $this->ensurePermissionMatchesPartition(
                $permission,
                $this->permissionRegistrar()->resolvePartition(),
            );
        }

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
     * @return Collection|(Model&Permission)
     */
    protected function getStoredPermission(
        $permissions,
        ?PermissionPartition $partition = null,
    ) {
        $partition ??= $this->permissionRegistrar()->resolvePartition();
        $permissions = enum_value($permissions);

        if (is_int($permissions) || PermissionRegistrar::isUid($permissions)) {
            $permission = $this->getPermissionClass()::findById($permissions, $this->getDefaultGuardName());
            $this->ensurePermissionMatchesPartition($permission, $partition);

            return $permission;
        }

        if (is_string($permissions)) {
            $permission = $this->getPermissionClass()::findByName($permissions, $this->getDefaultGuardName());
            $this->ensurePermissionMatchesPartition($permission, $partition);

            return $permission;
        }

        if (is_array($permissions)) {
            $permissions = array_map(function ($permission) use ($partition) {
                if ($permission instanceof Permission) {
                    $this->ensurePermissionMatchesPartition($permission, $partition);

                    return $permission->name;
                }

                return enum_value($permission);
            }, $permissions);

            return $this->getPermissionClass()::whereIn('name', $permissions)
                ->whereIn('guard_name', $this->getGuardNames())
                ->get();
        }

        if ($permissions instanceof Permission) {
            $this->ensurePermissionMatchesPartition($permissions, $partition);
        }

        return $permissions;
    }

    /**
     * Capture the partition and team for a direct permission operation.
     */
    private function permissionAssignmentContext(PermissionRegistrar $registrar): PermissionRelationContext
    {
        $partition = $registrar->resolvePartition();

        if ($partition) {
            $attributes = $this->getAttributes();

            if ($this instanceof Role
                || $this instanceof Permission
                || (array_key_exists($partition->column, $attributes)
                    && $attributes[$partition->column] !== null)) {
                $registrar->ensureModelMatchesPartition($this, $partition);
            }
        }

        $teamScoped = $registrar->teams && ! $this instanceof Role;

        return new PermissionRelationContext(
            $partition,
            $teamScoped,
            $teamScoped ? $registrar->getPermissionsTeamId() : null,
        );
    }

    /**
     * Ensure a supplied permission belongs to the captured partition.
     */
    private function ensurePermissionMatchesPartition(
        Permission $permission,
        ?PermissionPartition $partition,
    ): void {
        if ($partition && $permission instanceof Model) {
            $this->permissionRegistrar()->ensureModelMatchesPartition($permission, $partition);
        }
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

    /**
     * Get the guard names for the model.
     */
    protected function getGuardNames(): Collection
    {
        return Guard::getNames($this);
    }

    /**
     * Get the default guard name for the model.
     */
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
