<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
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
     * @var array<int, array{roles: array<int, int|string>, pivot: array<string, mixed>}>
     */
    private array $queuedRoleAssignments = [];

    /**
     * Boot the role relation cleanup and queued assignment callbacks.
     */
    public static function bootHasRoles(): void
    {
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $registrar = Container::getInstance()->make(PermissionRegistrar::class);

            if ($model instanceof Permission) {
                $model->getConnection()
                    ->table(Config::modelHasPermissionsTable())
                    ->where($registrar->pivotPermission, $model->getKey())
                    ->delete();

                $model->getConnection()
                    ->table(Config::roleHasPermissionsTable())
                    ->where($registrar->pivotPermission, $model->getKey())
                    ->delete();
            } else {
                $model->getConnection()
                    ->table(Config::modelHasRolesTable())
                    ->where(Config::morphKey(), $model->getKey())
                    ->where('model_type', $model->getMorphClass())
                    ->delete();

                $registrar->forgetModelAssignmentCache($model);
            }
        });

        static::saved(function (Model $model): void {
            if (method_exists($model, 'attachQueuedRoleAssignments')) {
                $model->attachQueuedRoleAssignments();
            }
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
        $relation = $this->morphToMany(
            Config::roleModel(),
            'model',
            Config::modelHasRolesTable(),
            Config::morphKey(),
            $this->permissionRegistrar()->pivotRole
        );

        if (! Config::teamsEnabled()) {
            return $relation;
        }

        $teamsKey = Config::teamForeignKey();
        $relation->withPivot($teamsKey);
        $teamField = Config::rolesTable() . '.' . $teamsKey;

        return $relation->wherePivot($teamsKey, getPermissionsTeamId())
            ->where(fn ($q) => $q->whereNull($teamField)->orWhere($teamField, getPermissionsTeamId()));
    }

    /**
     * Get cached role assignments for this model.
     */
    protected function getCachedRoles(): Collection
    {
        $model = $this;

        if ($this instanceof Permission || ! $model->exists || $this->relationLoaded('roles')) {
            return $this->relationCollection($this->loadMissing('roles'), 'roles');
        }

        $registrar = $this->permissionRegistrar();
        $roleKey = Guard::getModelKeyName($this->getRoleClass());
        $assignments = $registrar->rememberModelRoleAssignments(
            $model,
            fn (): array => $this->roles()
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
     * @param array|Collection|int|Role|string|UnitEnum $roles
     */
    public function scopeRole(Builder $query, $roles, ?string $guard = null, bool $without = false): Builder
    {
        if ($roles instanceof Collection) {
            $roles = $roles->all();
        }

        $roles = array_map(function ($role) use ($guard) {
            if ($role instanceof Role) {
                return $role;
            }

            $role = enum_value($role);

            $method = is_int($role) || PermissionRegistrar::isUid($role) ? 'findById' : 'findByName';

            return $this->getRoleClass()::{$method}($role, $guard ?: $this->getDefaultGuardName());
        }, Arr::wrap($roles));

        $key = Guard::getModelKeyName($this->getRoleClass());

        return $query->{! $without ? 'whereHas' : 'whereDoesntHave'}(
            'roles',
            fn (Builder $subQuery) => $subQuery
                ->whereIn(Config::rolesTable() . ".{$key}", array_column($roles, $key))
        );
    }

    /**
     * Scope the model query to only those without certain roles.
     *
     * @param array|Collection|int|Role|string|UnitEnum $roles
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
            $relation = $this->morphToMany(
                Config::permissionModel(),
                'model',
                Config::modelHasRolesTable(),
                Config::morphKey(),
                Config::teamForeignKey()
            );

            $relation->whereRaw('1 = 0');

            return $relation;
        }

        $relation = $this->morphToMany(
            Config::teamModel(),
            'model',
            Config::modelHasRolesTable(),
            Config::morphKey(),
            Config::teamForeignKey()
        );

        $relation->distinct();

        return $relation;
    }

    /**
     * Scope the model query to certain teams only.
     *
     * @param array|Collection|int|Model|string $teams
     */
    public function scopeTeam(Builder $query, $teams, bool $without = false): Builder
    {
        $teamModel = Config::teamModel();

        if ($teams instanceof Collection) {
            $teams = $teams->all();
        }

        $teamIds = array_map(
            fn ($team) => $team instanceof $teamModel ? $team->getKey() : $team,
            Arr::wrap($teams),
        );

        $pivotTable = Config::modelHasRolesTable();
        $morphKey = Config::morphKey();
        $teamsKey = Config::teamForeignKey();

        $query->{! $without ? 'whereExists' : 'whereNotExists'}(
            fn ($subQuery) => $subQuery
                ->from($pivotTable)
                ->whereColumn($morphKey, $query->getModel()->getQualifiedKeyName())
                ->where('model_type', $query->getModel()->getMorphClass())
                ->whereIn($teamsKey, $teamIds)
        );

        return $query;
    }

    /**
     * Scope the model query to those without certain teams.
     *
     * @param array|Collection|int|Model|string $teams
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
    private function collectRoles(...$roles): array
    {
        return collect($roles)
            ->flatten()
            ->reduce(function ($array, $role) {
                if ($role === null || $role === '') {
                    return $array;
                }

                $role = $this->getStoredRole($role);

                if (! in_array($role->getKey(), $array, true)) {
                    $this->ensureModelSharesGuard($role);
                    $array[] = $role->getKey();
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
        $roles = $this->collectRoles($roles);

        $model = $this;
        $registrar = $this->permissionRegistrar();
        $teamPivot = $registrar->teams && ! $this instanceof Permission
            ? [$registrar->teamsKey => getPermissionsTeamId()] : [];
        $cacheCleared = false;

        if ($model->exists) {
            if ($registrar->teams) {
                // explicit reload in case team has been changed since last load
                $this->load('roles');
            }

            $currentRoles = $this->getCachedRoles()->map(fn ($role) => $role->getKey())->toArray();

            $this->roles()->attach(array_diff($roles, $currentRoles), $teamPivot);
            $model->unsetRelation('roles');
        } else {
            $this->queueRoleAssignments($roles, $teamPivot);
        }

        if ($this instanceof Permission) {
            $this->forgetCachedPermissions();
            $cacheCleared = true;
        } elseif ($model->exists) {
            $registrar->forgetModelRoleCache($model);
            $cacheCleared = true;
        }

        if (! $cacheCleared) {
            $this->forgetWildcardPermissionIndex();
        }

        $this->dispatchRoleAttachedEvent($roles);

        return $this;
    }

    /**
     * Queue role assignments until the model is saved.
     *
     * @param array<int, int|string> $roles
     * @param array<string, mixed> $pivot
     */
    protected function queueRoleAssignments(array $roles, array $pivot): void
    {
        $this->queuedRoleAssignments[] = [
            'roles' => $roles,
            'pivot' => $pivot,
        ];
    }

    /**
     * Attach role assignments queued before the model was saved.
     */
    protected function attachQueuedRoleAssignments(): void
    {
        if ($this->queuedRoleAssignments === []) {
            return;
        }

        $registrar = $this->permissionRegistrar();

        foreach ($this->queuedRoleAssignments as $assignment) {
            $this->roles()->attach($assignment['roles'], $assignment['pivot']);
        }

        $this->queuedRoleAssignments = [];
        $this->unsetRelation('roles');

        if ($this instanceof Permission) {
            $this->forgetCachedPermissions();
        } else {
            $registrar->forgetModelRoleCache($this);
        }
    }

    /**
     * Dispatch the role attached event when enabled and listened for.
     *
     * @param array<int, int|string> $roles
     */
    protected function dispatchRoleAttachedEvent(array $roles): void
    {
        if (! Config::eventsEnabled()) {
            return;
        }

        $events = $this->eventDispatcher();

        if ($events->hasListeners(RoleAttachedEvent::class)) {
            $events->dispatch(new RoleAttachedEvent($this, $roles));
        }
    }

    /**
     * Revoke the given role from the model.
     *
     * @param array|Collection|int|Role|string|UnitEnum ...$role
     * @return $this
     */
    public function removeRole(...$role): static
    {
        $roles = $this->collectRoles($role);

        $this->roles()->detach($roles);

        $this->unsetRelation('roles');

        if ($this instanceof Permission) {
            $this->forgetCachedPermissions();
        } else {
            $this->permissionRegistrar()->forgetModelRoleCache($this);
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
        if (! Config::eventsEnabled()) {
            return;
        }

        $events = $this->eventDispatcher();

        if ($events->hasListeners(RoleDetachedEvent::class)) {
            $events->dispatch(new RoleDetachedEvent($this, $roles));
        }
    }

    /**
     * Remove all current roles and set the given ones.
     *
     * @param array|Collection|int|Role|string|UnitEnum ...$roles
     * @return $this
     */
    public function syncRoles(...$roles): static
    {
        if (! $this->exists) {
            return $this->assignRole($roles);
        }

        $roles = $this->collectRoles($roles);

        if (Config::eventsEnabled()) {
            $currentRoles = $this->roles()->get();
            if ($currentRoles->isNotEmpty()) {
                $this->removeRole($currentRoles);
            }
        } else {
            $this->roles()->detach();
            $this->setRelation('roles', collect());
        }

        $registrar = $this->permissionRegistrar();
        $teamPivot = $registrar->teams && ! $this instanceof Permission
            ? [$registrar->teamsKey => getPermissionsTeamId()] : [];

        if ($roles !== []) {
            $this->roles()->attach($roles, $teamPivot);
        }

        $this->unsetRelation('roles');

        if ($this instanceof Permission) {
            $this->forgetCachedPermissions();
        } else {
            $registrar->forgetModelRoleCache($this);
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

            return $guard
                ? $roleCollection->where('guard_name', $guard)->contains($key, $roles)
                : $roleCollection->contains($key, $roles);
        }

        if (is_string($roles)) {
            $roleNames = $guard
                ? $roleCollection->where('guard_name', $guard)->pluck('name')
                : $roleCollection->pluck('name');

            return $roleNames->contains(fn ($name): bool => enum_value($name) === $roles);
        }

        if ($roles instanceof Role) {
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
            return $roles->intersect($guard ? $roleCollection->where('guard_name', $guard) : $roleCollection)->isNotEmpty();
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
            return $roleCollection->contains($roles->getKeyName(), $roles->getKey());
        }

        $roles = collect()->make($roles)->map(fn ($role) => $role instanceof Role ? $role->name : enum_value($role));

        $roleNames = $guard
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
            $roles = [$roles->name];
        }

        $roles = collect()->make($roles)->map(
            fn ($role) => $role instanceof Role ? $role->name : enum_value($role)
        );

        return $roleCollection->count() === $roles->count() && $this->hasAllRoles($roles, $guard);
    }

    /**
     * Return allowed permissions directly assigned to the model.
     */
    public function getDirectPermissions(): Collection
    {
        return $this->allowedDirectPermissions();
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
     * @param mixed $role
     */
    protected function getStoredRole($role): Role
    {
        $role = enum_value($role);

        if (is_int($role) || PermissionRegistrar::isUid($role)) {
            return $this->getRoleClass()::findById($role, $this->getDefaultGuardName());
        }

        if (is_string($role)) {
            return $this->getRoleClass()::findByName($role, $this->getDefaultGuardName());
        }

        return $role;
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
