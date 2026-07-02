# Permission Forbidden Single-State Hardening Plan

## Objective

Fix the permission package so forbidden permissions are modeled as a single state on each assignment edge, not as a second row that can coexist beside an allowed row.

The final package must read as if it was designed this way from the start:

- one database row per subject / permission / team edge
- `is_forbidden` is the effect stored on that edge
- write methods flip the edge effect instead of creating competing rows
- read methods treat forbidden as deny and never depend on relation order
- query scopes use the same effective permission semantics as `hasPermissionTo()`
- allowed permission accessors do not return forbidden rows
- assignment cache invalidation uses a fresh namespace token instead of numeric increments
- README and Boost docs describe the final behavior without stale dual-row wording

This PR is for Hypervel 0.4. Churn and backwards compatibility do not matter here; the target is the best final codebase.

## Current Bug And Root Cause

Greptile flagged order-dependent reads in:

- `src/permission/src/Traits/HasPermissions.php::hasDirectPermission()`
- `src/permission/src/Models/Role.php::hasPermissionTo()`

The real root cause is schema and write behavior, not only those readers.

Current migrations include `is_forbidden` in the primary key:

```php
$table->primary(
    [$pivotPermission, $modelMorphKey, 'model_type', 'is_forbidden'],
    'model_has_permissions_permission_model_type_primary',
);

$table->primary(
    [$pivotPermission, $pivotRole, 'is_forbidden'],
    'role_has_permissions_permission_id_role_id_primary',
);
```

That allows two rows for the same edge:

```text
model_id=1, permission_id=5, is_forbidden=false
model_id=1, permission_id=5, is_forbidden=true
```

Then reads that call `first()` can return whichever row the relation gives first. Even when a caller checks forbidden before allowed, the data model is still contradictory and has to be defended in too many places.

The correct model is one row per edge:

```text
model_id=1, permission_id=5, is_forbidden=true
```

Assigning allow or deny for the same edge updates that row's effect.

During implementation, `scopePermission()` was also found to ignore forbidden
effects. A model where `hasPermissionTo()` returns `false` could still match
`User::permission()` because the scope counted forbidden direct pivots and
forbidden role-permission pivots as grants. That is the same class of
forbidden-correctness bug and must be fixed in this plan.

## Agreed Decisions

1. Keep forbidden permissions as a Hypervel improvement.

   Explicit deny is useful in role/permission systems. A forbidden assignment must win over a direct allow, a role allow, or another role's allow.

2. Store forbidden as an effect, not an identity field.

   `is_forbidden` stays as a boolean column on `model_has_permissions` and `role_has_permissions`, but it must not be part of the primary key.

3. Make write paths enforce single-state.

   `givePermissionTo()` and `giveForbiddenTo()` must insert missing edges and update existing edges to the requested effect. They must not rely on duplicate rows.

4. Keep defensive read hardening.

   Once schema and writes are fixed, duplicates cannot be created through public APIs. Readers still handle bad manual data by making forbidden win deterministically.

5. Exclude forbidden rows from allowed permission accessors.

   `getDirectPermissions()` and `getPermissionNames()` are allow-oriented APIs. They must not return explicit denies.

6. Make permission query scopes match effective permission semantics.

   `permission($permissions)` must return models that effectively have at
   least one requested permission. For each requested permission, an allow from
   a direct assignment or a role assignment counts only when there is no direct
   or role deny for that same permission. `withoutPermission($permissions)` must
   be the exact negation of `permission($permissions)`.

7. Replace numeric assignment cache versions with unique namespace tokens.

   The current numeric `increment()` path is not needed. A fresh ULID token is simpler and avoids store-specific increment behavior. This is not a hot permission-check path; it runs when assignment cache namespaces are invalidated.

8. Update docs to describe the final model only.

   Documentation must not mention the old dual-row shape or leave wording that suggests allow and forbid rows can coexist for the same edge.

## Files To Change

### Schema Definitions

All three schema definitions must change together:

- `src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php`
- `src/permission/database/migrations/add_teams_fields.php.stub`
- `tests/Permission/TestCase.php`

The cache key config also changes as part of the token rename:

- `src/permission/config/permission.php`

Current primary keys:

```php
// Base migration, teams enabled.
$table->primary(
    [$teamForeignKey, $pivotPermission, $modelMorphKey, 'model_type', 'is_forbidden'],
    'model_has_permissions_permission_model_type_primary',
);

// Base migration, teams disabled.
$table->primary(
    [$pivotPermission, $modelMorphKey, 'model_type', 'is_forbidden'],
    'model_has_permissions_permission_model_type_primary',
);

// Base migration, role permissions.
$table->primary(
    [$pivotPermission, $pivotRole, 'is_forbidden'],
    'role_has_permissions_permission_id_role_id_primary',
);
```

Target primary keys:

```php
// Base migration, teams enabled.
$table->primary(
    [$teamForeignKey, $pivotPermission, $modelMorphKey, 'model_type'],
    'model_has_permissions_permission_model_type_primary',
);

// Base migration, teams disabled.
$table->primary(
    [$pivotPermission, $modelMorphKey, 'model_type'],
    'model_has_permissions_permission_model_type_primary',
);

// Base migration, role permissions.
$table->primary(
    [$pivotPermission, $pivotRole],
    'role_has_permissions_permission_id_role_id_primary',
);
```

The teams upgrade stub target changes only the `primary()` column list:

```php
$table->primary(
    [$teamForeignKey, $pivotPermission, $modelMorphKey, 'model_type'],
    'model_has_permissions_permission_model_type_primary',
);
```

Keep the surrounding `dropForeign()`, `dropPrimary()`, and re-add `foreign()` scaffolding exactly as the stub has it today.

The custom-primary-key test schema must match the same shape:

```php
$table->primary(
    [$pivotPermission, $modelMorphKey, 'model_type'],
    'model_has_permissions_permission_model_type_primary',
);

$table->primary(
    [$pivotPermission, $pivotRole],
    'role_has_permissions_permission_id_role_id_primary',
);
```

Keep:

```php
$table->boolean('is_forbidden')->default(false);
```

### Assignment Writes

File:

- `src/permission/src/Traits/HasPermissions.php`

Current write path:

```php
private function attachPermissions(array $permissions, bool $isForbidden): static
{
    $permissions = $this->collectPermissions($permissions);
    $model = $this;
    $registrar = $this->permissionRegistrar();
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

    // cache/event cleanup...
}
```

Target behavior:

- collect permission ids
- build the team pivot once
- for persisted models, find existing related permission ids regardless of effect
- attach missing ids with the requested `is_forbidden` value
- update existing ids whose pivot effect differs
- do not keep same-flag dedup logic
- unset the relation after mutation
- keep existing cache, event, and wildcard invalidation behavior

Target shape:

```php
private function attachPermissions(array $permissions, bool $isForbidden): static
{
    $permissions = $this->collectPermissions($permissions);
    $model = $this;
    $registrar = $this->permissionRegistrar();
    $pivot = $this->permissionAssignmentPivot($isForbidden);

    if ($model->exists) {
        $this->upsertPermissionAssignments($permissions, $pivot);
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
```

Add a helper for the pivot data so direct and queued paths share the same source:

```php
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
```

Add a helper that flips existing rows and inserts only missing rows:

```php
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
```

Upsert notes:

- `permissions()` already scopes model permissions by team through `wherePivot($teamsKey, getPermissionsTeamId())`, so `updateExistingPivot()` and `attach()` operate in the current team context for user/model assignments.
- For model assignments, pass the full pivot data to `attach()` so new rows get the team id, but pass only `['is_forbidden' => ...]` to `updateExistingPivot()`. The relation already scopes the update to the current team, so setting the team column again is noise.
- Role permission assignments have no team pivot by design, so the same helper works for `Role`.
- The helper queries the relation fresh instead of using `loadMissing()`. This matters because callers may already have a stale `permissions` relation loaded, and queued assignments can process allow/deny calls back-to-back after save.
- `touchIfTouching()` is called once after a changed batch so flipping multiple rows does not touch repeatedly, and no-op calls do not touch related models.

Add a helper for assignment sets that are known to have no existing rows:

```php
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
```

This helper is only for paths that have already proved the assignment set is
empty for the target `(model, permission, team)` identity. Do not replace the
normal persisted `givePermissionTo()` / `giveForbiddenTo()` path with it.
Known-empty paths use `attachPermissionAssignments()` to avoid the read that
`upsertPermissionAssignments()` needs for possibly-existing rows.

Place `permissionAssignmentPivot()`, `upsertPermissionAssignments()`, and
`attachPermissionAssignments()` next to `attachPermissions()` and the `give*`
methods, not at the end of the trait.

### Queued Assignments For Unsaved Models

Current queued path:

```php
protected function attachQueuedPermissionAssignments(): void
{
    if ($this->queuedPermissionAssignments === []) {
        return;
    }

    $registrar = $this->permissionRegistrar();

    foreach ($this->queuedPermissionAssignments as $assignment) {
        $this->permissions()->attach($assignment['permissions'], $assignment['pivot']);
    }

    $this->queuedPermissionAssignments = [];
    $this->unsetRelation('permissions');

    // cache cleanup...
}
```

This will hit primary-key conflicts under the new schema if an unsaved model receives both allow and forbid for the same permission before it is saved.

Target:

```php
protected function attachQueuedPermissionAssignments(): void
{
    if ($this->queuedPermissionAssignments === []) {
        return;
    }

    $registrar = $this->permissionRegistrar();
    $assignments = $this->collapseQueuedPermissionAssignments();

    foreach ($assignments as $assignment) {
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

    $this->forgetWildcardPermissionIndex();
}
```

Add helpers for collapsing and batching queued assignments:

```php
/**
 * Collapse queued permission assignments to their final edge state.
 *
 * @return array<int, array{permissions: array<int, int|string>, pivot: array<string, mixed>}>
 */
private function collapseQueuedPermissionAssignments(): array
{
    $collapsed = [];

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
```

This keeps queued calls ordered per assignment edge. For example:

```php
$user = new User;
$user->givePermissionTo('edit articles');
$user->giveForbiddenTo('edit articles');
$user->save();
```

The saved row must be forbidden. Reversing the calls must save an allowed row.
The collapse key is permission id plus team identity, not permission id alone:

```php
$user = new User;
setPermissionsTeamId(1);
$user->givePermissionTo('edit articles');
setPermissionsTeamId(2);
$user->givePermissionTo('edit articles');
$user->save();
```

This must create one row for team 1 and one row for team 2. Only repeated queued
assignments for the same permission and same team collapse, with the last effect
winning.

Do not dispatch `PermissionAttachedEvent` from `attachQueuedPermissionAssignments()`.
The existing API dispatches at `givePermissionTo()` / `giveForbiddenTo()` call
time, including unsaved-model queued calls. The queued flush must only persist
rows and invalidate caches/wildcard indexes, or queued calls would double-fire
events.

### Sync Behavior

`syncPermissionsWithForbidden()` already builds one `$syncData[$permissionId]` entry per permission id:

```php
foreach ($allowedIds as $permissionId) {
    $syncData[$permissionId] = $teamPivot + ['is_forbidden' => false];
}

foreach ($forbiddenIds as $permissionId) {
    $syncData[$permissionId] = $teamPivot + ['is_forbidden' => true];
}

$changes = $this->permissions()->sync($syncData);
```

Because `sync()` updates existing pivot attributes for existing ids, this method is already aligned with the target single-state model.

Still verify these paths with tests:

- allow to forbid returns the changed permission id under `updated`
- forbid to allow returns the changed permission id under `updated`
- permission present in both `allowed` and `forbidden` ends forbidden
- custom primary key tests still pass

`syncPermissions()` should collect ids once, detach existing rows, then use the
known-empty insert helper. Do not call `givePermissionTo()` after detaching: it
would re-collect string inputs and route through the upsert helper, adding a
read that is unnecessary after the set has been emptied.

```php
public function syncPermissions(...$permissions): static
{
    $permissions = $this->collectPermissions($permissions);
    $model = $this;

    if ($this->exists) {
        $this->permissions()->detach();
        $this->attachPermissionAssignments(
            $permissions,
            $this->permissionAssignmentPivot(false),
        );
        $model->unsetRelation('permissions');
    } else {
        $this->queuePermissionAssignments(
            $permissions,
            $this->permissionAssignmentPivot(false),
        );
    }

    if ($this instanceof Role) {
        $this->forgetCachedPermissions();
    } elseif ($model->exists) {
        $this->permissionRegistrar()->forgetModelPermissionCache($model);
    }

    $this->dispatchPermissionAttachedEvent($permissions);
    $this->forgetWildcardPermissionIndex();

    return $this;
}
```

This preserves the existing public event timing: `syncPermissions()` dispatches
the attach event once, while queued unsaved-model flushes do not dispatch again.

### Revoke Behavior

`revokePermissionTo()` detaches the one edge regardless of current effect:

```php
public function revokePermissionTo($permission): static
{
    $storedPermission = $this->getStoredPermission($permission);

    $this->permissions()->detach($storedPermission);

    // cache/event cleanup...
}
```

No source change is expected here, but add coverage proving a direct deny can be revoked:

```php
$user->giveForbiddenTo('edit articles');
$user->revokePermissionTo('edit articles');

$this->assertFalse($user->hasForbiddenPermission('edit articles'));
$this->assertFalse($user->hasPermissionTo('edit articles'));
$this->assertSame([], $user->getDirectPermissions()->all());
```

### Read Hardening

File:

- `src/permission/src/Traits/HasPermissions.php`

Current:

```php
public function hasDirectPermission($permission): bool
{
    $permission = $this->filterPermission($permission);

    $matchedPermission = $this->getCachedDirectPermissions()
        ->first(fn (Model $directPermission): bool => $directPermission->getKey() === $permission->getKey());

    return $matchedPermission !== null
        && ! $this->pivotIsForbidden($matchedPermission);
}
```

Target:

```php
public function hasDirectPermission($permission): bool
{
    $permission = $this->filterPermission($permission);

    $matches = $this->getCachedDirectPermissions()
        ->filter(fn (Model $directPermission): bool => $directPermission->getKey() === $permission->getKey());

    return $matches->isNotEmpty()
        && ! $matches->contains(fn (Model $directPermission): bool => $this->pivotIsForbidden($directPermission));
}
```

This is defense against bad/manual data. With the new schema and write path, public APIs do not create duplicates.

File:

- `src/permission/src/Models/Role.php`

Current:

```php
$matchedPermission = $this->loadMissing('permissions')
    ->getRelation('permissions')
    ->first(fn (Model $rolePermission): bool => $rolePermission->getKey() === $permission->getKey());
$pivot = $matchedPermission?->getRelation('pivot');

return $matchedPermission !== null
    && (! $pivot instanceof Pivot || ! (bool) $pivot->getAttribute('is_forbidden'));
```

Target:

```php
$matches = $this->loadMissing('permissions')
    ->getRelation('permissions')
    ->filter(fn (Model $rolePermission): bool => $rolePermission->getKey() === $permission->getKey());

return $matches->isNotEmpty()
    && ! $matches->contains(fn (Model $rolePermission): bool => $this->pivotIsForbidden($rolePermission));
```

This removes the last direct `Pivot` class reference in `Role.php`. Remove the `use Hypervel\Database\Eloquent\Relations\Pivot;` import.

### Effective Permission Query Scopes

File:

- `src/permission/src/Traits/HasPermissions.php`

`scopePermission()` currently counts direct and role permission rows without
checking the forbidden effect:

```php
return $query->where(
    fn (Builder $query) => $query
        ->{! $without ? 'whereHas' : 'whereDoesntHave'}(
            'permissions',
            fn (Builder $subQuery) => $subQuery
                ->whereIn(Config::permissionsTable() . ".{$permissionKey}", array_column($permissions, $permissionKey))
        )
        ->when(
            count($roleIdsWithPermissions),
            fn ($whenQuery) => $whenQuery
                ->{! $without ? 'orWhereHas' : 'whereDoesntHave'}(
                    'roles',
                    fn (Builder $subQuery) => $subQuery
                        ->whereIn(Config::rolesTable() . ".{$roleKey}", $roleIdsWithPermissions)
                )
        )
);
```

This is wrong for forbidden permissions:

- direct forbidden rows match `permission()` as if they were allows
- role forbidden rows match `permission()` as if they were allows
- `withoutPermission()` is not a true inverse once deny semantics are involved

Target semantics for one model `M` and permission `p`:

```text
directAllow(M, p) = direct edge for p with is_forbidden=false
directDeny(M, p)  = direct edge for p with is_forbidden=true
roleAllow(M, p)   = assigned role edge for p with is_forbidden=false
roleDeny(M, p)    = assigned role edge for p with is_forbidden=true

effective(M, p) = (directAllow(M, p) OR roleAllow(M, p))
                  AND NOT (directDeny(M, p) OR roleDeny(M, p))
```

For a set of requested permissions:

```text
permission([p1, p2]) = effective(M, p1) OR effective(M, p2)
withoutPermission([p1, p2]) = NOT permission([p1, p2])
```

The deny check must be correlated to the same permission. A model with allow
`P1` and deny `P2` must still match `permission([P1, P2])` through `P1`.

Do not keep the current precomputed `$roleIdsWithPermissions` path. It is built
from hydrated permission-role relations and cannot express the role-permission
pivot effect cleanly. Use relation existence queries so SQL checks the actual
assignment edges.

Target shape:

```php
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
```

Add helpers near `scopePermission()`:

```php
/**
 * Add an effective permission predicate for the given permission ids.
 *
 * @param array<int, int|string> $permissionIds
 */
protected function whereEffectivePermission(Builder $query, array $permissionIds): Builder
{
    if ($permissionIds === []) {
        return $query->whereRaw('1 = 0');
    }

    foreach ($permissionIds as $index => $permissionId) {
        $method = $index === 0 ? 'where' : 'orWhere';

        $query->{$method}(fn (Builder $query) => $query
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
```

Important implementation constraints:

- The direct branch must be wrapped in `whereHas('permissions', ...)`. The
  `permissions` table and the permission pivot table are not joined into the
  outer model query; direct-effect predicates are valid only inside the
  relationship existence subquery.
- Use qualified pivot table columns inside `whereHas()` callbacks. The callback
  receives an Eloquent builder, not the `BelongsToMany` relation object, so
  `wherePivot()` is not available there.
- Preserve the `Role` subject behavior. `Role::permission()` must check only
  the role's own `role_has_permissions` edge and must not traverse
  `roles.permissions`.
- Do not change `scopeRole()` or `scopeWithoutRole()`. Role assignment has no
  forbidden effect column; it is pure membership.
- The direct branch inherits team scoping from `permissions()` for models. The
  nested role branch inherits team scoping from `roles()`, while
  `role_has_permissions` remains unscoped by team by design.
- Query scopes evaluate concrete stored permission grants. They cannot expand
  wildcard permission grammar in SQL; document this boundary in README and Boost
  docs.

Leave `scopeWithoutPermission()` as the one-line delegate:

```php
public function scopeWithoutPermission(Builder $query, $permissions): Builder
{
    return $this->scopePermission($query, $permissions, true);
}
```

Do not duplicate complement logic in `scopeWithoutPermission()`. `scopePermission()`
is the single source of truth and handles `$without` by negating the effective
permission predicate.

### Allowed Permission Accessors

Files:

- `src/permission/src/Traits/HasRoles.php`
- `src/permission/src/Traits/HasPermissions.php`

Current:

```php
public function getDirectPermissions(): Collection
{
    return $this->getCachedDirectPermissions();
}

public function getPermissionNames(): Collection
{
    return $this->getCachedDirectPermissions()->pluck('name');
}
```

Target:

```php
/**
 * Return allowed direct permissions.
 */
protected function allowedDirectPermissions(): Collection
{
    return $this->getCachedDirectPermissions()
        ->reject(fn (Model $permission): bool => $this->pivotIsForbidden($permission))
        ->values();
}

public function getPermissionNames(): Collection
{
    return $this->allowedDirectPermissions()->pluck('name');
}

public function getDirectPermissions(): Collection
{
    return $this->allowedDirectPermissions();
}
```

Place `allowedDirectPermissions()` in `HasPermissions` near `getCachedDirectPermissions()` / other direct-permission helpers. `getPermissionNames()` also lives in `HasPermissions`, and `Role` uses `HasPermissions` without `HasRoles`, so `getPermissionNames()` must not call `getDirectPermissions()`. Keep `getDirectPermissions()` in `HasRoles` as the public user-model API, but make it delegate to the shared helper.

### Global Catalog Cache Payload

File:

- `src/permission/src/PermissionRegistrar.php`

The global catalog payload already carries role-permission pivot effects:

```php
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
```

Hydration already reattaches the pivot:

```php
$role->setRelation('pivot', Pivot::fromRawAttributes(
    $permission,
    (array) $item['pivot'],
    $this->config->string('permission.table_names.role_has_permissions'),
    true,
));
```

During implementation, verify this still passes after single-state writes and schema changes. Keep tests around:

- payload includes `is_forbidden=false` for allowed role permissions
- payload includes `is_forbidden=true` for forbidden role permissions
- hydrated cache preserves forbidden role behavior after clearing coroutine-local catalog state

### Assignment Cache Namespace Token

File:

- `src/permission/src/PermissionRegistrar.php`

Current:

```php
public function modelAssignmentCacheVersion(): int
{
    return $this->cacheRepository()->rememberForever(
        $this->scopedCacheKey($this->modelCacheVersionKey),
        fn () => 1,
    );
}

public function bumpModelAssignmentCacheVersion(): int
{
    $cache = $this->cacheRepository();
    $key = $this->scopedCacheKey($this->modelCacheVersionKey);
    $cache->add($key, 1);

    $version = $cache->increment($key);

    if (is_int($version)) {
        return $version;
    }

    $version = ((int) $cache->get($key, 1)) + 1;
    $cache->forever($key, $version);

    return $version;
}
```

Target:

```php
use Hypervel\Support\Str;

public function modelAssignmentCacheToken(): string
{
    return $this->cacheRepository()->rememberForever(
        $this->scopedCacheKey($this->modelCacheTokenKey),
        fn (): string => $this->newModelAssignmentCacheToken(),
    );
}

public function bumpModelAssignmentCacheToken(): string
{
    $token = $this->newModelAssignmentCacheToken();

    $this->cacheRepository()->forever(
        $this->scopedCacheKey($this->modelCacheTokenKey),
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
```

Rename the concept everywhere:

```php
public const MODEL_CACHE_TOKEN_KEY = 'hypervel.permission.cache.model.token';

protected string $modelCacheTokenKey;
```

Use config key `permission.cache.keys.model_token` with default `self::MODEL_CACHE_TOKEN_KEY`.

Update `src/permission/config/permission.php`:

```php
'cache' => [
    'expiration_seconds' => 86400,
    'store' => env('PERMISSION_CACHE_STORE', 'default'),
    'keys' => [
        'roles' => 'hypervel.permission.cache.roles',
        'model_roles' => 'hypervel.permission.cache.model.roles',
        'model_permissions' => 'hypervel.permission.cache.model.permissions',
        'model_token' => 'hypervel.permission.cache.model.token',
    ],
    'column_names_except' => ['created_at', 'updated_at', 'deleted_at'],
],
```

Update all source callers:

```php
$registrar->bumpModelAssignmentCacheToken();
```

Place `newModelAssignmentCacheToken()` next to `modelAssignmentCacheToken()` and `bumpModelAssignmentCacheToken()`.

Why ULID:

- `Hypervel\Support\Str::ulid()` exists and returns a unique 26-character token.
- The token is not security material; it only namespaces cache keys.
- `xxh128` is excellent for hashing existing input into a stable key, but it is not an ID generator. Using it here would still require unique input and would make the code less direct.
- The method runs only when assignment cache namespaces are seeded or bumped, not on every permission check.

Two concurrent first-ever reads of `modelAssignmentCacheToken()` can generate different ULIDs and briefly disagree before the cache store settles. That causes a one-off cache miss and recompute from the database, not stale authorization data, because both keys point to independently recomputed assignment data. This is an acceptable trade for removing store-specific increment behavior from invalidation.

Replace tests that assume numeric ordering. For example, rename `testPermissionCacheResetBumpsModelAssignmentCacheVersion()` to `testPermissionCacheResetChangesModelAssignmentCacheToken()` and replace `assertGreaterThan()` with `assertNotSame()`:

```php
$firstToken = $registrar->modelAssignmentCacheToken();

$registrar->forgetCachedPermissions();

$this->assertNotSame($firstToken, $registrar->modelAssignmentCacheToken());
```

Keep `modelCacheKey()` and `wildcardPermissionIndexKey()` logic intact except for calling `modelAssignmentCacheToken()`; both already interpolate the namespace value as a string segment. `tests/Permission/WildcardPermissionTest.php` calls the bump method for its side effect only, so update the method name to `bumpModelAssignmentCacheToken()` but no assertion change is needed.

### Docs

Files:

- `src/permission/README.md`
- `src/boost/docs/permission.md`

Update docs to say:

- `is_forbidden` is an effect column on permission assignment tables.
- Each assignment edge has one row.
- Calling `givePermissionTo()` after `giveForbiddenTo()` flips that edge back to allowed.
- Calling `giveForbiddenTo()` after `givePermissionTo()` flips that edge to forbidden.
- `revokePermissionTo()` removes the edge regardless of whether it is allowed or forbidden.
- `getDirectPermissions()` and `getPermissionNames()` return allowed direct permissions, not explicit denies.
- `permission()` and `withoutPermission()` query scopes use effective concrete
  permission semantics: an allow counts only when the same permission is not
  denied directly or through a role.
- wildcard permission checks still happen through runtime permission checks; SQL
  query scopes evaluate stored concrete permission grants and do not expand the
  wildcard grammar.
- Permission assignment caches use namespace tokens; resetting permission cache makes old model assignment keys unreachable until TTL cleanup.
- Update the existing Boost docs prose that says "assignment-cache version" to "assignment-cache token", including the current text around `src/boost/docs/permission.md:1081` and `src/boost/docs/permission.md:1178`.

Suggested Boost docs replacement around forbidden permissions:

```md
Forbidden permissions explicitly deny access. The permission assignment tables store
`is_forbidden` as the effect for the assignment edge, so a model or role has one
row for a given permission in the current team context.

Calling `giveForbiddenTo` for an allowed permission flips that assignment to a
deny. Calling `givePermissionTo` for a forbidden permission flips it back to an
allow.
```

Suggested retrieval note:

```md
`getDirectPermissions`, `getPermissionsViaRoles`, `getAllPermissions`, and
`getPermissionNames` return allowed permissions. Explicitly forbidden permissions
are checked through `hasForbiddenPermission` and
`hasForbiddenPermissionViaRoles`.
```

Suggested query-scope note:

```md
The `permission` and `withoutPermission` query scopes filter by effective stored
permissions. Direct and role-granted denies override allows for the same
permission. Wildcard permission strings are evaluated by runtime permission
checks such as `hasPermissionTo`; query scopes match stored concrete permission
records.
```

Suggested cache note:

```md
Model role and direct-permission assignment cache keys include a namespace token.
When roles, permissions, or assignment-wide state changes, the package writes a
new token so older per-model cache keys are bypassed and expire naturally through
the configured cache TTL.
```

Update README differences:

```md
- Hypervel adds forbidden permissions. A forbidden permission explicitly denies
  an ability and wins over direct or role-granted allows. The deny flag is stored
  as the effect on the assignment row, so assigning allow or deny for the same
  model/role and permission updates the existing edge.
- Hypervel's cache config uses `expiration_seconds` and separate named cache keys
  so role, model-role, model-permission, and assignment-token caches can be
  invalidated independently.
```

Remove or rewrite any wording that implies an allowed and forbidden row can both exist for the same assignment edge.

## Testing Plan

Run tests after each changed test file.

### Focused Tests To Add Or Update

File:

- `tests/Permission/ForbiddenPermissionTest.php`

Add imports as needed:

```php
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
```

Add/update coverage:

```php
public function testDirectForbiddenPermissionFlipsExistingAllowedPermission(): void
{
    $this->testUser->givePermissionTo('edit-articles');
    $this->testUser->giveForbiddenTo('edit-articles');

    $this->testUser->refresh();

    $this->assertSame(1, $this->testUser->permissions()->count());
    $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
    $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
    $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    $this->assertSame([], $this->testUser->getPermissionNames()->all());
}

public function testDirectAllowedPermissionFlipsExistingForbiddenPermission(): void
{
    $this->testUser->giveForbiddenTo('edit-articles');
    $this->testUser->givePermissionTo('edit-articles');

    $this->testUser->refresh();

    $this->assertSame(1, $this->testUser->permissions()->count());
    $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));
    $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
    $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
    $this->assertSame(['edit-articles'], $this->testUser->getPermissionNames()->all());
}
```

Role edge flips:

```php
public function testRoleForbiddenPermissionFlipsExistingAllowedPermission(): void
{
    $this->testUserRole->givePermissionTo('edit-articles');
    $this->testUserRole->giveForbiddenTo('edit-articles');

    $this->testUserRole->refresh();

    $this->assertSame(1, $this->testUserRole->permissions()->count());
    $this->assertTrue($this->testUserRole->hasForbiddenPermission('edit-articles'));
    $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
}

public function testRoleAllowedPermissionFlipsExistingForbiddenPermission(): void
{
    $this->testUserRole->giveForbiddenTo('edit-articles');
    $this->testUserRole->givePermissionTo('edit-articles');

    $this->testUserRole->refresh();

    $this->assertSame(1, $this->testUserRole->permissions()->count());
    $this->assertFalse($this->testUserRole->hasForbiddenPermission('edit-articles'));
    $this->assertTrue($this->testUserRole->hasPermissionTo('edit-articles'));
}
```

Queued unsaved-model flips:

```php
public function testQueuedDirectForbiddenPermissionFlipsExistingQueuedAllowedPermission(): void
{
    $user = new User(['email' => 'queued@example.com']);

    $user->givePermissionTo('edit-articles');
    $user->giveForbiddenTo('edit-articles');
    $user->save();

    $user->refresh();

    $this->assertSame(1, $user->permissions()->count());
    $this->assertTrue($user->hasForbiddenPermission('edit-articles'));
    $this->assertFalse($user->hasPermissionTo('edit-articles'));
}

public function testQueuedDirectAllowedPermissionFlipsExistingQueuedForbiddenPermission(): void
{
    $user = new User(['email' => 'queued@example.com']);

    $user->giveForbiddenTo('edit-articles');
    $user->givePermissionTo('edit-articles');
    $user->save();

    $user->refresh();

    $this->assertSame(1, $user->permissions()->count());
    $this->assertFalse($user->hasForbiddenPermission('edit-articles'));
    $this->assertTrue($user->hasPermissionTo('edit-articles'));
}
```

Add a team-aware queued assignment test in `TeamHasPermissionsTest`:

```php
public function testQueuedPermissionAssignmentsKeepSeparateTeamEdges(): void
{
    $user = new User(['email' => 'queued-teams@example.com']);

    setPermissionsTeamId(1);
    $user->givePermissionTo('edit-articles');

    setPermissionsTeamId(2);
    $user->givePermissionTo('edit-articles');

    $user->save();

    setPermissionsTeamId(1);
    $this->assertTrue($user->hasPermissionTo('edit-articles'));
    $this->assertSame(1, $user->permissions()->count());

    setPermissionsTeamId(2);
    $user->unsetRelation('permissions');
    $this->assertTrue($user->hasPermissionTo('edit-articles'));
    $this->assertSame(1, $user->permissions()->count());
}
```

Keep these existing query-count tests at two queries:

- `testItDoesNotRunUnnecessarySqlWhenAssigningNewPermissions`
- `testItDoesNotLetQueuedGivePermissionToInterfereWithOtherObjects`
- `testItDoesNotLetQueuedSyncPermissionsInterfereWithOtherObjects`

They verify that `syncPermissions()` and queued unsaved-model flushes use the
known-empty path instead of reading current pivots before attaching.

Revoke deny:

```php
public function testRevokePermissionRemovesDirectForbiddenPermission(): void
{
    $this->testUser->giveForbiddenTo('edit-articles');
    $this->testUser->revokePermissionTo('edit-articles');

    $this->testUser->refresh();

    $this->assertSame(0, $this->testUser->permissions()->count());
    $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));
    $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
}
```

Layered reveal:

```php
public function testRemovingDirectForbiddenPermissionRevealsRoleAllowedPermission(): void
{
    $this->testUserRole->givePermissionTo('edit-articles');
    $this->testUser->assignRole($this->testUserRole);
    $this->testUser->giveForbiddenTo('edit-articles');

    $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));

    $this->testUser->revokePermissionTo('edit-articles');

    $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
}
```

Bad/manual data defense for direct permissions:

```php
public function testDirectPermissionChecksDenyWhenRelationContainsDuplicateEffects(): void
{
    $permission = $this->app->make(PermissionContract::class)::findByName('edit-articles');
    $allowed = clone $permission;
    $forbidden = clone $permission;

    $allowed->setRelation('pivot', Pivot::fromRawAttributes(
        $this->testUser,
        [
            $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
            Config::morphKey() => $this->testUser->getKey(),
            'model_type' => $this->testUser->getMorphClass(),
            'is_forbidden' => false,
        ],
        Config::modelHasPermissionsTable(),
        true,
    ));

    $forbidden->setRelation('pivot', Pivot::fromRawAttributes(
        $this->testUser,
        [
            $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
            Config::morphKey() => $this->testUser->getKey(),
            'model_type' => $this->testUser->getMorphClass(),
            'is_forbidden' => true,
        ],
        Config::modelHasPermissionsTable(),
        true,
    ));

    $this->testUser->setRelation('permissions', collect([$allowed, $forbidden]));

    $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
}
```

This does not weaken the final schema. It proves the defensive read path with an in-memory relation containing bad duplicate data.

Bad/manual data defense for role permissions:

```php
public function testRolePermissionChecksDenyWhenRelationContainsDuplicateEffects(): void
{
    $permission = $this->app->make(PermissionContract::class)::findByName('edit-articles');
    $allowed = clone $permission;
    $forbidden = clone $permission;

    $allowed->setRelation('pivot', Pivot::fromRawAttributes(
        $this->testUserRole,
        [
            $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
            $this->app->make(PermissionRegistrar::class)->pivotRole => $this->testUserRole->getKey(),
            'is_forbidden' => false,
        ],
        Config::roleHasPermissionsTable(),
        true,
    ));

    $forbidden->setRelation('pivot', Pivot::fromRawAttributes(
        $this->testUserRole,
        [
            $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
            $this->app->make(PermissionRegistrar::class)->pivotRole => $this->testUserRole->getKey(),
            'is_forbidden' => true,
        ],
        Config::roleHasPermissionsTable(),
        true,
    ));

    $this->testUserRole->setRelation('permissions', collect([$allowed, $forbidden]));

    $this->assertFalse($this->testUserRole->hasDirectPermission('edit-articles'));
    $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
}
```

The `hasDirectPermission()` assertion exercises the hardened duplicate-match filter. `Role::hasPermissionTo()` first calls `hasForbiddenPermission()`, so its assertion confirms the public role permission check still denies, but it does not by itself prove the rewritten `$matches` block in `Role::hasPermissionTo()` is reached.

### Query Scopes

Files:

- `tests/Permission/Traits/HasPermissionsTest.php`
- `tests/Permission/Traits/TeamHasPermissionsTest.php`
- `tests/Permission/ForbiddenPermissionTest.php`

Add coverage for effective permission query scopes:

```php
public function testPermissionScopeExcludesDirectForbiddenPermission(): void
{
    $this->testUser->giveForbiddenTo('edit-articles');

    $this->assertFalse(User::permission('edit-articles')->get()->contains($this->testUser));
    $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains($this->testUser));
}

public function testPermissionScopeExcludesRoleForbiddenPermission(): void
{
    $this->testUserRole->giveForbiddenTo('edit-articles');
    $this->testUser->assignRole($this->testUserRole);

    $this->assertFalse(User::permission('edit-articles')->get()->contains($this->testUser));
    $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains($this->testUser));
}

public function testPermissionScopeLetsDirectDenyOverrideRoleAllow(): void
{
    $this->testUserRole->givePermissionTo('edit-articles');
    $this->testUser->assignRole($this->testUserRole);
    $this->testUser->giveForbiddenTo('edit-articles');

    $this->assertFalse(User::permission('edit-articles')->get()->contains($this->testUser));
    $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains($this->testUser));
}

public function testPermissionScopeLetsRoleDenyOverrideDirectAllow(): void
{
    $this->testUserRole->giveForbiddenTo('edit-articles');
    $this->testUser->assignRole($this->testUserRole);
    $this->testUser->givePermissionTo('edit-articles');

    $this->assertFalse(User::permission('edit-articles')->get()->contains($this->testUser));
    $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains($this->testUser));
}
```

Add tests that catch per-permission correlation bugs:

```php
public function testPermissionScopeMatchesAllowedPermissionWhenDifferentRequestedPermissionIsDenied(): void
{
    $this->testUser->givePermissionTo('edit-articles');
    $this->testUser->giveForbiddenTo('edit-news');

    $this->assertTrue(User::permission(['edit-articles', 'edit-news'])->get()->contains($this->testUser));
}

public function testPermissionScopeMatchesSecondAllowedPermissionWhenFirstRequestedPermissionIsDenied(): void
{
    $this->testUserRole->giveForbiddenTo('edit-articles');
    $this->testUser->assignRole($this->testUserRole);
    $this->testUser->givePermissionTo('edit-articles');
    $this->testUser->givePermissionTo('edit-news');

    $this->assertTrue(User::permission(['edit-articles', 'edit-news'])->get()->contains($this->testUser));
}
```

Add empty-set complement coverage:

```php
public function testPermissionScopeWithNoPermissionsMatchesNoModels(): void
{
    $this->assertFalse(User::permission([])->get()->contains($this->testUser));
}

public function testWithoutPermissionScopeWithNoPermissionsMatchesAllModels(): void
{
    $this->assertTrue(User::withoutPermission([])->get()->contains($this->testUser));
}
```

Add role-subject coverage:

```php
public function testRolePermissionScopeExcludesForbiddenRolePermissionEdges(): void
{
    $this->testUserRole->giveForbiddenTo('edit-articles');

    $this->assertFalse($this->app->make(RoleContract::class)::permission('edit-articles')->get()->contains($this->testUserRole));
}
```

Keep the existing allow-only scope tests green. They prove the reworked scope
preserves the public "any requested permission" behavior.

### Teams

File:

- `tests/Permission/Traits/TeamHasPermissionsTest.php`

Add or update coverage:

- allow then forbid in team 1 leaves one row in team 1 and does not affect team 2
- forbid then allow in team 1 leaves one allowed row in team 1
- `getPermissionNames()` excludes forbidden direct permissions in the active team
- `revokePermissionTo()` removes a forbidden row in the active team only
- `permission()` respects direct allow/deny effects in the active team
- role-granted permissions match in a team where the role is assigned and do not
  match in a team where the role is not assigned

### Cache

File:

- `tests/Permission/CacheTest.php`

Replace the existing numeric version test:

```php
public function testPermissionCacheResetChangesModelAssignmentCacheToken(): void
{
    $this->testUser->assignRole('testRole');
    $registrar = $this->app->make(PermissionRegistrar::class);

    $this->assertTrue($this->testUser->hasRole('testRole'));

    $firstToken = $registrar->modelAssignmentCacheToken();

    $registrar->forgetCachedPermissions();

    $this->assertNotSame($firstToken, $registrar->modelAssignmentCacheToken());
    $this->assertTrue($this->testUser->hasRole('testRole'));
}
```

Add token shape check:

```php
$this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $registrar->modelAssignmentCacheToken());
```

`Str::ulid()` currently returns Symfony's ULID string format, which is uppercase Crockford base32.

Keep scoped cache resolver tests and adjust names from "version" to "token" where relevant:

- global permission catalog scope
- model assignment token scope
- model role assignment cache scope
- model direct-permission assignment cache scope
- wildcard index scope

In `tests/Permission/CacheTest.php`, the scoped-resolver test currently asserts exact old key strings. Update them:

```php
$this->assertContains('hypervel.permission.cache.model.token:tenant-a', $keys);
$this->assertContains('hypervel.permission.cache.model.token:tenant-b', $keys);
```

### Schema

Files:

- `tests/Permission/SchemaConfigTest.php`
- `tests/Permission/CustomSchemaConfigTest.php`

Add assertions for primary key shape where the schema inspection API supports it. If index introspection is too driver-specific, assert behavior instead:

```php
$this->testUser->givePermissionTo('edit-articles');
$this->testUser->giveForbiddenTo('edit-articles');

$this->assertSame(1, $this->testUser->permissions()->count());
```

Run the custom model test path too, because `tests/Permission/TestCase.php` has its own schema with UUID pivot keys.

### Commands To Run

After each changed test file:

```sh
./vendor/bin/phpunit --no-progress tests/Permission/ForbiddenPermissionTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Traits/HasPermissionsTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Traits/TeamHasPermissionsTest.php
./vendor/bin/phpunit --no-progress tests/Permission/CacheTest.php
./vendor/bin/phpunit --no-progress tests/Permission/SchemaConfigTest.php
./vendor/bin/phpunit --no-progress tests/Permission/CustomSchemaConfigTest.php
```

After implementation:

```sh
composer fix
```

`composer fix` runs php-cs-fixer, PHPStan, and the parallel test suite.

## Implementation Checklist

1. Change all schema primary keys to remove `is_forbidden` from identity while keeping the column.
2. Add permission assignment pivot/upsert/known-empty attach helpers in `HasPermissions`.
3. Update `attachPermissions()` to call the upsert helper for persisted models.
4. Update `attachQueuedPermissionAssignments()` to collapse queued assignments by permission and team, then attach with the known-empty helper after save.
5. Remove same-flag dedup logic that only existed for dual-row schema.
6. Update `syncPermissions()` to collect ids once and attach through the known-empty helper after detach.
7. Preserve permission attach event timing: dispatch from give/sync methods, not from queued flushes.
8. Harden `hasDirectPermission()` and `Role::hasPermissionTo()` to deny if any matching pivot is forbidden.
9. Rework `scopePermission()` around the effective permission predicate, and keep `scopeWithoutPermission()` as a delegate to `scopePermission(..., true)`.
10. Add `allowedDirectPermissions()` in `HasPermissions`, make `getPermissionNames()` use it, and make `HasRoles::getDirectPermissions()` delegate to it.
11. Verify and keep global catalog serialization/hydration of `is_forbidden`.
12. Rename numeric model assignment cache versions to ULID namespace tokens across source, config, tests, README, and Boost docs.
13. Update tests for forbidden flips, queued flips, query-count preservation, revoke deny, effective scopes, teams, cache token, and accessors.
14. Update README and Boost docs, including the concrete-grant query-scope boundary for wildcard permissions.
15. Run focused tests after each changed test file.
16. Run `composer fix`.
17. Remove the dead `Pivot` import from `Role.php`.
18. Self-review for stale imports, dead helpers, stale docs, stale comments, and old "version" names/wording where the concept is now a token.

## Self-Review Checklist After Implementation

Check all of these before asking for review:

- `grep -R "is_forbidden.*primary\|primary.*is_forbidden" -n src/permission tests/Permission` returns nothing outside archived material.
- No public write path can create two rows for the same edge.
- `givePermissionTo()` after `giveForbiddenTo()` flips deny to allow.
- `giveForbiddenTo()` after `givePermissionTo()` flips allow to deny.
- queued unsaved model calls preserve call order and end with one row.
- queued unsaved model calls for the same permission in different teams create
  separate team edges.
- queued unsaved model flushes do not dispatch a second attach event.
- `syncPermissions()` collects ids once and does not read current pivots after
  detaching.
- `syncPermissionsWithForbidden()` still reports `attached`, `detached`, and `updated` correctly.
- existing query-count tests for new permissions and queued unsaved-model
  assignments still expect two queries and pass.
- `revokePermissionTo()` removes a direct deny.
- direct deny hides role allow, and removing the direct deny reveals the role allow.
- role deny hides direct allow.
- role deny hides another role's allow.
- `permission()` uses effective permission semantics and does not include models
  denied directly or through roles for the same permission.
- `withoutPermission()` is the exact complement of `permission()`.
- deny checks in query scopes are correlated to the same requested permission,
  so allow `P1` plus deny `P2` still matches `permission([P1, P2])`.
- `permission([])` matches no models and `withoutPermission([])` matches all
  models.
- `Role::permission()` checks only `role_has_permissions` and does not traverse
  `roles.permissions`.
- wildcard docs state that permission query scopes filter concrete stored grants
  and do not expand wildcard grammar.
- allowed accessors exclude forbidden rows.
- global catalog cache payload contains `is_forbidden` and hydration preserves it.
- model assignment cache keys include a ULID token.
- tests no longer compare assignment cache namespace values numerically.
- `grep -R "assertGreaterThan.*modelAssignmentCache\\|modelAssignmentCache.*assertGreaterThan" -n tests/Permission` returns nothing.
- `grep -R "modelAssignmentCacheVersion\|bumpModelAssignmentCacheVersion\|modelCacheVersion\|model_version\|MODEL_CACHE_VERSION\|cache\\.model\\.version\|model\\.version" -n src/permission tests/Permission src/boost/docs/permission.md src/permission/README.md` returns nothing.
- `grep -R "assignment-cache version\|cache version" -n src/permission tests/Permission src/boost/docs/permission.md src/permission/README.md` returns nothing.
- permission cache docs and comments describe the assignment cache namespace as a token, not a numeric version.
- `grep -R "use Hypervel\\\\Database\\\\Eloquent\\\\Relations\\\\Pivot;" -n src/permission/src/Models/Role.php` returns nothing.
- docs no longer imply dual allow/forbid rows can coexist for one edge.
- all changed files have no unused imports, dead code, stale comments, or stale wording.

## Expected Commit Split After Everything Is Green

The final split can be decided after implementation. The expected clean split is:

1. schema and write/read behavior for single-state forbidden permissions
2. effective permission query-scope behavior
3. regression tests for forbidden single-state and effective-scope behavior
4. assignment cache namespace token cleanup
5. permission docs/README updates

This remains one PR.
