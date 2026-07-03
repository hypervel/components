# Permission Review Hardening And Performance Plan

## Objective

Apply the permission package review findings so `src/permission` reads as if it was designed this way from the start: correct guard semantics, team-scoped reverse assignments, hot permission checks that scale with the checked permission rather than the whole catalog, and no half-wired storage mode.

The final package should have:

- no cross-guard deny leakage
- no middleware TypeErrors for authenticated users missing the permission trait
- no malformed pipe-string parsing divergence from Spatie
- no cross-team data loss in reverse role assignment helpers
- no global model-assignment cache bust from plain model deletes
- indexed catalog lookups for common role and permission resolutions
- cheap short-circuiting when no role-permission deny edges exist
- narrowed role-deny checks that inspect only the requested permission
- no duplicate rows in `getPermissionsViaRoles()`
- no `permission.storage.database.connection` config surface
- no dead helpers, stale comments, or stale docs left behind

This is Hypervel 0.4 framework work. Backwards compatibility and diff size are not constraints. The goal is the clean final codebase.

## Research Summary

### Source Files Read

- `src/permission/src/Traits/HasPermissions.php`
- `src/permission/src/Traits/HasRoles.php`
- `src/permission/src/Traits/HasAssignedModels.php`
- `src/permission/src/PermissionRegistrar.php`
- `src/permission/src/Models/Role.php`
- `src/permission/src/Middleware/PermissionMiddleware.php`
- `src/permission/src/Middleware/RoleMiddleware.php`
- `src/permission/src/Middleware/RoleOrPermissionMiddleware.php`
- `src/permission/src/Exceptions/UnauthorizedException.php`
- `src/permission/config/permission.php`
- `src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php`
- `src/permission/database/migrations/add_teams_fields.php.stub`
- `src/permission/src/Support/Config.php`
- `src/database/src/Eloquent/Relations/Concerns/InteractsWithPivotTable.php`
- `src/database/src/Eloquent/Relations/BelongsToMany.php`
- `src/cache/src/StackStore.php`
- `src/boost/docs/cache.md`
- relevant tests under `tests/Permission`
- Spatie reference files under `examples/spatie/permission`

### Key Findings

1. `HasPermissions::hasPermissionTo()` checks deny paths before `filterPermission()`. When no guard is passed, `storedPermissionMatches()` skips guard filtering, so a deny under one guard can block an allow under another guard.

2. A naive default-guard fix would create a fail-open bug for concrete `Permission` objects. If an API-guard `Permission` object is passed while the model default is web, forcing the default guard before matching would hide API forbidden rows.

3. `PermissionMiddleware`, `RoleOrPermissionMiddleware`, and `RoleMiddleware` can call `UnauthorizedException::missingTraitHasRoles(Authorizable $user)` with an authenticated object that is not `Authorizable`. The exception only needs `$user::class`.

4. `HasRoles::convertPipeToArray()` has a porting typo: it compares the first character to itself instead of the last character of the pipe string.

5. `HasAssignedModels::syncModels()` deletes every `model_has_roles` row for the role, across all teams. `assignToModels()` and `removeFromModels()` also need current-team scoping when teams are enabled.

6. `bootHasRoles()` and `bootHasPermissions()` bump the global model-assignment cache token for every delete. Plain model deletes only need local assignment-cache clearing.

7. `PermissionRegistrar::getPermissions()` and `getRoles()` scan the whole hydrated catalog for common exact lookups. This affects `findByName()`, `findById()`, `filterPermission()`, and direct assignment hydration.

8. `hasForbiddenPermissionViaRoles()` materializes every permission's role pivots on every role-deny check.

9. Most deployments have no role-permission deny edges. A catalog-level boolean can skip the role-deny path cheaply.

10. `getPermissionsViaRoles()` can return duplicate permissions when multiple held roles grant the same permission. `getAllPermissions()` already dedupes.

11. `permission.storage.database.connection` is broken and should be removed, not completed. Migrations use it, models do not, and the default defeats normal `php artisan migrate --database=...` behavior.

12. Hypervel's `stack` cache driver is the right hot-read tool for permission catalogs. A separate permission DB connection adds a second pool and breaks transaction coherence with app models.

## Agreed Decisions

1. Fix guard matching by resolving the effective guard per input type:

   - explicit guard argument wins
   - concrete `Permission` object uses its own `guard_name`
   - all other inputs use the model default guard

2. Keep deny checks fail-closed without making concrete permission objects fail open.

3. For non-wildcard `hasPermissionTo()`, resolve the concrete permission once with `filterPermission()` before direct and role grant checks. Public deny helper methods still resolve their own effective guard because callers can use them directly.

4. Widen `UnauthorizedException::missingTraitHasRoles()` to `object`.

5. Treat `HasAssignedModels` team scoping as a correctness/data-loss fix.

6. Remove the global assignment-token bump from delete hooks. Plain model deletes clear only the deleted model's assignment cache.

7. Add registrar catalog indexes instead of a separate direct-permission hydrated memo. This fixes the root O(catalog) lookup cost.

8. Add a serialized `hasForbiddenRolePermissions` catalog flag.

9. Narrow `hasForbiddenPermissionViaRoles()` to the requested permission and effective guard.

10. Add a coroutine-local via-role materialization memo for public getters only, with centralized invalidation.

11. Remove `permission.storage.database.connection` completely.

12. Remove stale docs/comments/code. Do not leave compatibility shims for removed behavior.

## Implementation Order

### 1. Cross-Guard Deny Semantics

#### Why

`storedPermissionMatches()` only applies the guard filter when `$guardName !== null`:

```php
protected function storedPermissionMatches(Model $storedPermission, mixed $permission, ?string $guardName = null): bool
{
    if ($guardName !== null && $storedPermission->getAttribute('guard_name') !== $guardName) {
        return false;
    }

    if ($permission instanceof Permission) {
        return $storedPermission->getKey() === $permission->getKey();
    }

    // ...
}
```

`hasPermissionTo('edit')` passes `null` to both forbidden checks before the allow path resolves the default guard. That means `edit@api` denied can block `edit@web` allowed in default-web contexts.

#### How

Add an effective guard helper near `storedPermissionMatches()`:

```php
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
```

Update public forbidden helper methods to use it:

```php
public function hasForbiddenPermission($permission, ?string $guardName = null): bool
{
    $guardName = $this->guardNameForPermissionMatch($permission, $guardName);

    return $this->getCachedDirectPermissions()
        ->contains(
            fn (Model $storedPermission): bool => $this->pivotIsForbidden($storedPermission)
            && $this->storedPermissionMatches($storedPermission, $permission, $guardName)
        );
}
```

```php
public function hasForbiddenPermissionViaRoles($permission, ?string $guardName = null): bool
{
    if ($this instanceof Role || $this instanceof Permission) {
        return false;
    }

    $guardName = $this->guardNameForPermissionMatch($permission, $guardName);

    // This method is further rewritten in step 7.
}
```

Update non-wildcard `hasPermissionTo()` to resolve once:

```php
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
```

Apply the same resolve-once shape to `Role::hasPermissionTo()`:

```php
public function hasPermissionTo(UnitEnum|int|string|PermissionContract $permission, ?string $guardName = null): bool
{
    if ($this->getWildcardClass()) {
        if ($this->hasForbiddenPermission($permission, $guardName)) {
            return false;
        }

        return $this->hasWildcardPermission($permission, $guardName);
    }

    $permission = $this->filterPermission($permission, $guardName);

    if ($this->hasForbiddenPermission($permission, $guardName)) {
        return false;
    }

    if (! $this->getGuardNames()->contains($permission->guard_name)) {
        throw GuardDoesNotMatch::create($permission->guard_name, $guardName ? collect([$guardName]) : $this->getGuardNames());
    }

    // Existing relation check remains, but now runs after guard-exact deny.
}
```

Keep `storedPermissionMatches()` as the final comparison helper. It can still accept nullable guard, but every deny path should pass an effective guard.

#### Tests

Add tests to `tests/Permission/ForbiddenPermissionTest.php`:

- same permission name exists under `web` and `api`
- user has allow for `web`
- user has direct deny for `api`
- default guard is `web`
- `hasPermissionTo('permission-name')` returns true
- `hasPermissionTo('permission-name', 'api')` returns false
- passing the concrete API permission object returns false
- passing the concrete web permission object returns true

Add role-path equivalents:

- user has a web role allowing `edit`
- user has an api role denying `edit`
- default web string check is true
- explicit api string check is false
- concrete API permission object is false

Add `Role::hasPermissionTo()` tests:

- role with API forbidden permission checked by concrete API permission object returns false
- role with default web context does not miss the concrete object's deny

Run immediately:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/ForbiddenPermissionTest.php
```

### 2. Middleware TypeError

#### Why

Middleware can call:

```php
throw UnauthorizedException::missingTraitHasRoles($user);
```

with an authenticated object that is not `Authorizable`. The exception method only reads the class name:

```php
public static function missingTraitHasRoles(Authorizable $user): static
{
    return new static(403, __('Authorizable class `:class` must use Hypervel\Permission\Traits\HasRoles trait.', [
        'class' => $user::class,
    ]), null, []);
}
```

That should be a 403 package exception, not a TypeError.

#### How

Change the exception signature:

```php
/**
 * Create an exception for a user missing the HasRoles trait.
 */
public static function missingTraitHasRoles(object $user): static
{
    return new static(403, __('Authorizable class `:class` must use Hypervel\Permission\Traits\HasRoles trait.', [
        'class' => $user::class,
    ]), null, []);
}
```

No middleware logic change is needed.

#### Tests

Add tests in:

- `tests/Permission/Middleware/PermissionMiddlewareTest.php`
- `tests/Permission/Middleware/RoleMiddlewareTest.php`
- `tests/Permission/Middleware/RoleOrPermissionMiddlewareTest.php`

Each test should authenticate a model/object that implements `Authenticatable` enough for the guard but does not implement `Authorizable` or the relevant permission trait methods. Assert `UnauthorizedException` is thrown, not `TypeError`.

Run after each file:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/Middleware/PermissionMiddlewareTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Middleware/RoleMiddlewareTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Middleware/RoleOrPermissionMiddlewareTest.php
```

### 3. Pipe String Parsing Typo

#### Why

Hypervel currently has:

```php
$quoteCharacter = substr($pipeString, 0, 1);
$endCharacter = substr($quoteCharacter, -1, 1);
```

Spatie uses:

```php
$endCharacter = substr($pipeString, -1, 1);
```

The current Hypervel code makes `$quoteCharacter !== $endCharacter` dead.

#### How

Change only the second line:

```php
$endCharacter = substr($pipeString, -1, 1);
```

#### Tests

Add or update a role test covering malformed quoted role strings:

```php
$this->assertFalse($user->hasRole('"admin|editor'));
```

The malformed quoted string should be treated as a normal pipe-delimited string, not trimmed as if it had matching quotes.

Run:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/Traits/HasRolesTest.php
```

If the test file has a different name, use the existing file that covers `hasRole()` and pipe strings.

### 4. Team-Scope `HasAssignedModels`

#### Why

`HasAssignedModels` is a reverse assignment API on `Role`. It must honor the current team when teams are enabled.

Current bugs:

```php
$existingIds = $relation->pluck(Config::morphKey())->all();
$relation->attach(array_diff($ids, $existingIds), $teamPivot);
```

`assignToModels()` can skip attaching a current-team row because another team already has the same model id.

```php
$this->relationForModel($morphClass)->detach($ids);
```

`removeFromModels()` depends on relation state for team scoping and should be explicit.

```php
$this->newPivotQueryForRole()->delete();
```

`syncModels()` deletes every model assignment for the role across every team.

#### Required Source Check Before Coding

Before implementing this step, re-read:

- `src/permission/src/Traits/HasAssignedModels.php`
- `src/permission/src/Traits/HasRoles.php::roles()`
- `src/database/src/Eloquent/Relations/Concerns/InteractsWithPivotTable.php::detach()`
- `src/database/src/Eloquent/Relations/Concerns/InteractsWithPivotTable.php::newPivotQuery()`
- `src/database/src/Eloquent/Relations/BelongsToMany.php::wherePivot()`

`newPivotQuery()` applies `pivotWheres`, so a relation returned from `relationForModel()` with `wherePivot($teamsKey, getPermissionsTeamId())` will scope `pluck()` and `detach()`.

#### How

Make `relationForModel()` team-aware:

```php
protected function relationForModel(string $modelClass): MorphToMany
{
    $relation = $this->morphedByMany(
        $modelClass,
        'model',
        Config::modelHasRolesTable(),
        Container::getInstance()->make(PermissionRegistrar::class)->pivotRole,
        Config::morphKey(),
    );

    if (! Config::teamsEnabled()) {
        return $relation;
    }

    return $relation->wherePivot(Config::teamForeignKey(), getPermissionsTeamId());
}
```

Make `newPivotQueryForRole()` team-aware:

```php
private function newPivotQueryForRole(): Builder
{
    $query = $this->getConnection()
        ->table(Config::modelHasRolesTable())
        ->where(Container::getInstance()->make(PermissionRegistrar::class)->pivotRole, $this->getKey());

    if (Config::teamsEnabled()) {
        $query->where(Config::teamForeignKey(), getPermissionsTeamId());
    }

    return $query;
}
```

Keep `teamPivot()` as the single source for attach pivot values:

```php
private function teamPivot(): array
{
    if (! Config::teamsEnabled()) {
        return [];
    }

    return [Config::teamForeignKey() => getPermissionsTeamId()];
}
```

Do not remove the global bumps in `assignToModels()`, `removeFromModels()`, or `syncModels()`. These methods mutate assignments for arbitrary models, so local invalidation is not enough.

#### Tests

Create a teams-specific reverse assignment test file if no suitable one exists:

- `tests/Permission/Traits/TeamHasAssignedModelsTest.php`

This file should enable teams like `TeamHasPermissionsTest`.

Required tests:

1. `assignToModels()` attaches in the current team even when the same user already has the role in another team.
2. `removeFromModels()` removes only the current team assignment.
3. `syncModels()` replaces only current team assignments and leaves other team rows intact.
4. Empty `syncModels([])` clears only current team rows.
5. Raw id paths with explicit model class also honor current team.

Run immediately:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/Traits/TeamHasAssignedModelsTest.php
```

Then run the existing reverse assignment tests:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/Traits/HasAssignedModelsTest.php
```

### 5. Delete-Hook Assignment Cache Invalidation

#### Why

Plain model deletes currently rotate the global model-assignment cache token:

```php
$registrar->bumpModelAssignmentCacheToken();
```

That invalidates every model's role and permission assignment caches when any user is deleted.

Role and Permission model deletes already trigger:

```php
RefreshesPermissionCache::deleted() -> forgetCachedPermissions()
```

which clears the catalog and bumps the assignment token.

#### How

In `HasRoles::bootHasRoles()`:

```php
if ($model instanceof Permission) {
    // existing shared row cleanup
} else {
    // existing model_has_roles cleanup
    $registrar->forgetModelAssignmentCache($model);
}
```

Remove the unconditional token bump from that delete hook.

In `HasPermissions::bootHasPermissions()`:

```php
if (! $model instanceof Permission && ! $model instanceof Role) {
    // existing model_has_permissions cleanup
    Container::getInstance()->make(PermissionRegistrar::class)->forgetModelAssignmentCache($model);
}
```

Remove the unconditional token bump from that delete hook.

Do not add extra cache clearing for Role or Permission deletes in these trait hooks. `RefreshesPermissionCache` owns the global catalog/token invalidation.

#### Tests

Add tests in `tests/Permission/CacheTest.php`:

1. Warm assignment caches for two users.
2. Capture `PermissionRegistrar::modelAssignmentCacheToken()`.
3. Delete one user.
4. Assert the token did not change.
5. Assert the other user's permission/role check still reads correctly.

Add a second test:

1. Capture token.
2. Delete a Role or Permission.
3. Assert token changes through `RefreshesPermissionCache`.

Run:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/CacheTest.php
```

### 6. Registrar Catalog Indexes

#### Why

Common lookups currently do O(catalog) scans:

```php
return $this->filterModels($permissions, $params, $onlyOne);
```

That affects:

- `Permission::findByName()`
- `Permission::findById()`
- `Role::findByName()`
- `Role::findById()`
- `filterPermission()`
- `getCachedDirectPermissions()`

The catalog is already hydrated once per coroutine. Indexes should be built in that same lifecycle.

#### How

Change the catalog shape from:

```php
/**
 * @return array{permissions: Collection, roles: Collection}
 */
private function permissionCatalog(): array
```

to:

```php
/**
 * @return array{
 *     permissions: Collection,
 *     roles: Collection,
 *     permissionByKey: array<string, Model>,
 *     permissionByNameAndGuard: array<string, list<Model>>,
 *     permissionOrderByKey: array<string, int>,
 *     roleByKey: array<string, Model>,
 *     roleByNameAndGuard: array<string, list<Model>>,
 *     roleOrderByKey: array<string, int>,
 *     hasForbiddenRolePermissions: bool
 * }
 */
private function permissionCatalog(): array
```

Build indexes after hydration:

```php
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
    'hasForbiddenRolePermissions' => (bool) ($payload['hasForbiddenRolePermissions'] ?? false),
];
```

Add helpers:

```php
/**
 * @return array<string, Model>
 */
private function indexModelsByKey(Collection $models): array
{
    return $models
        ->mapWithKeys(fn (Model $model): array => [(string) $model->getKey() => $model])
        ->all();
}
```

```php
/**
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
```

```php
private function nameGuardIndexKey(mixed $name, mixed $guardName): string
{
    return (string) $guardName . "\0" . (string) $name;
}
```

Use null-byte as an internal separator so names containing `|` do not collide.

Add a stable catalog-order map so list lookups can use indexes without changing public collection order:

```php
/**
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
```

Add fast-path filtering:

```php
protected function indexedModels(array $params, bool $onlyOne, string $modelType): ?Collection
{
    if ($params === []) {
        return null;
    }

    $catalog = $this->permissionCatalog();
    $keyName = $modelType === 'permission'
        ? Guard::getModelKeyName($this->permissionClass)
        : Guard::getModelKeyName($this->roleClass);

    $byKey = $catalog[$modelType === 'permission' ? 'permissionByKey' : 'roleByKey'];
    $byNameAndGuard = $catalog[$modelType === 'permission' ? 'permissionByNameAndGuard' : 'roleByNameAndGuard'];
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

            if ($guardNames !== null && $modelType === 'role' && ! $this->roleMatchesCurrentTeam($model)) {
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
```

Add the role team filter used by indexed `Role::findByName()` and `Role::findById()` lookups:

```php
private function roleMatchesCurrentTeam(Model $role): bool
{
    if (! $this->teams) {
        return true;
    }

    $teamId = $this->getPermissionsTeamId();
    $roleTeamId = $role->getAttribute($this->teamsKey);

    return $roleTeamId === null || self::attributeMatches($roleTeamId, $teamId);
}
```

This mirrors `Role::findByParam()`'s existing team semantics: when teams are enabled, role lookup accepts global roles (`team_id` null) and roles matching the current team. The final one-item result still comes from catalog order, which matches the existing query's unordered first-row behavior without adding a new priority rule.

Add the collection builder used by both indexed branches:

```php
/**
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
```

Keep the public methods behavior-preserving:

```php
public function getPermissions(array $params = [], bool $onlyOne = false, ?string $permissionClass = null): Collection
{
    if ($permissionClass !== null && $permissionClass !== $this->permissionClass) {
        return $this->filterModels($this->getPermissionsWithRoles($permissionClass), $params, $onlyOne);
    }

    $indexed = $this->indexedModels($params, $onlyOne, 'permission');

    if ($indexed !== null) {
        return $indexed;
    }

    return $this->filterModels($this->permissionCatalog()['permissions'], $params, $onlyOne);
}
```

Do the same for `getRoles()`.

Update `Role::findByName()` and `Role::findById()` to use the registrar catalog instead of a direct DB query:

```php
protected static function getRoles(array $params = [], bool $onlyOne = false): Collection
{
    return Container::getInstance()->make(PermissionRegistrar::class)
        ->getRoles($params, $onlyOne, static::class);
}
```

```php
protected static function getRole(array $params = []): ?RoleContract
{
    /** @var null|RoleContract */
    return static::getRoles($params, true)->first();
}
```

Then use `static::getRole(['name' => $name, 'guard_name' => $guardName])` and `static::getRole([Guard::getModelKeyName(static::class) => $id, 'guard_name' => $guardName])` in `findByName()` and `findById()`. Keep `findByParam()` for `findOrCreate()` and `create()` because those are write-side uniqueness checks where the direct DB query remains the authoritative guard before insert.

Apply the same write-side rule to `Permission::create()`. It currently checks uniqueness through `getPermission()`, which reads the coroutine-local catalog. If another coroutine creates the same permission after this coroutine hydrated its catalog, the pre-check can miss and the insert surfaces a raw database unique-constraint exception instead of `PermissionAlreadyExists`.

Add a direct DB lookup helper for permission creation:

```php
protected static function findByParam(array $params = []): ?PermissionContract
{
    $query = static::query();

    foreach ($params as $key => $value) {
        $query->where($key, $value);
    }

    return $query->first();
}
```

Then update `Permission::create()` to use `static::findByParam(['name' => $attributes['name'], 'guard_name' => $attributes['guard_name']])` for its duplicate check. Update `Permission::findOrCreate()` to use the same direct DB lookup before creating, so a stale coroutine catalog returns the existing permission instead of delegating to `create()` and receiving `PermissionAlreadyExists`. Keep `getPermission()` for `findByName()` and `findById()` because those are read-side APIs that should benefit from the catalog.

Catalog-resolved roles now behave like catalog-resolved permissions: they are hydrated from the cache payload, so attributes listed in `permission.cache.column_names_except` are absent. By default that means `created_at`, `updated_at`, and `deleted_at` are not present on roles returned by `Role::findByName()` and `Role::findById()`. This is intentional for the read-side catalog path; tests should verify behavior through key/name/guard/team data rather than reintroducing timestamps into the catalog.

Preserve return type and visible behavior:

- return `Collection`
- `onlyOne` returns empty collection or a one-item collection
- list lookups should preserve catalog order unless a test proves callers do not observe the order
- fall back to `filterModels()` for any param shape not exactly handled
- use `!== null` for the indexed fast path so an empty lookup result is still returned directly

#### Add Forbidden Edge Flag

Update serialized payload:

```php
private function getSerializedPermissionsForCache(): array
{
    $except = $this->config->array('permission.cache.column_names_except', ['created_at', 'updated_at', 'deleted_at']);
    $permissions = $this->getPermissionsWithRoles();
    $hasForbiddenRolePermissions = false;

    return [
        'permissions' => $permissions
            ->map(function (Model $permission) use (&$hasForbiddenRolePermissions, $except): array {
                $roles = $this->relationCollection($permission, 'roles')
                    ->map(function (Model $role) use ($permission, &$hasForbiddenRolePermissions): array {
                        $isForbidden = $this->pivotIsForbidden($role);
                        $hasForbiddenRolePermissions = $hasForbiddenRolePermissions || $isForbidden;

                        return [
                            'pivot' => [
                                $this->pivotPermission => $permission->getKey(),
                                $this->pivotRole => $role->getKey(),
                                'is_forbidden' => $isForbidden,
                            ],
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
        'hasForbiddenRolePermissions' => $hasForbiddenRolePermissions,
    ];
}
```

Because this changes only the value stored under the package cache key and the package already tolerates recaching, no migration is needed. The hydration path should use `?? false` so old cache payloads are harmless until they expire or are flushed.

#### Tests

Add registrar tests in the existing `tests/Permission/Integration/PermissionRegistrarTest.php` when they target registrar lookup behavior. Use `tests/Permission/CacheTest.php` only for cache invalidation behavior.

1. `findByName()` returns the correct permission for same name across guards.
2. `findById()` with guard returns the correct permission.
3. list lookup by ids preserves expected order.
4. missing lookup returns an empty collection through registrar and still throws through model `findBy*`.
5. role lookups by name and key use the registrar catalog and still throw the same exceptions when missing.
6. team-enabled role lookup still finds global roles and current-team roles, and does not find another team's role.
7. when a global role and current-team role share name and guard, lookup preserves the existing first-row/catalog-order behavior rather than inventing a new priority rule.
8. `Role::findOrCreate()` and `Role::create()` still use the write-side DB uniqueness path.
9. stale coroutine catalog plus a duplicate `Permission::create()` still throws `PermissionAlreadyExists`, not a raw database unique-constraint exception.
10. stale coroutine catalog plus `Permission::findOrCreate()` returns the existing DB row instead of throwing.
11. catalog-resolved roles expose the expected key/name/guard/team data and do not require timestamp attributes that the catalog intentionally strips.
12. old cache payload without `hasForbiddenRolePermissions` is treated as false if practical to seed.

Performance behavior can be tested by creating unrelated permissions and asserting a permission check result remains correct. Avoid brittle tests that depend on private loop counts unless a clean spy pattern already exists.

Run:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/CacheTest.php
```

Run the registrar test immediately after updating it:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/Integration/PermissionRegistrarTest.php
```

### 7. Narrow Role-Deny Checks

#### Why

Current role-deny check:

```php
return $this->getPermissionsViaRolesWithPivots()
    ->contains(
        fn (Model $storedPermission): bool => $this->pivotIsForbidden($storedPermission)
        && $this->storedPermissionMatches($storedPermission, $permission, $guardName)
    );
```

`getPermissionsViaRolesWithPivots()` walks the whole permission catalog. A check for one permission should inspect one permission.

#### How

Add registrar method:

```php
/**
 * Determine if any cached role-permission edge is forbidden.
 */
public function hasForbiddenRolePermissions(): bool
{
    return (bool) $this->permissionCatalog()['hasForbiddenRolePermissions'];
}
```

Add a no-throw permission resolver in `HasPermissions`:

```php
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
```

Then rewrite `hasForbiddenPermissionViaRoles()`:

```php
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
```

Keep the fast ordering:

1. role/permission model early return
2. no cached roles early return
3. no forbidden role edges early return
4. resolve requested permission
5. inspect only that permission's role pivots

#### Tests

Add to `tests/Permission/ForbiddenPermissionTest.php`:

- forbidden role permission under another guard does not deny default guard
- forbidden role permission for a different permission does not deny requested permission
- explicit guard with role deny still denies
- concrete permission object from denied guard is denied
- missing permission still returns false from `checkPermissionTo()` and throws from `hasPermissionTo()` as before
- no roles returns false without needing catalog side effects

Run:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/ForbiddenPermissionTest.php
```

### 8. Via-Role Materialization Memo And Deduping

#### Why

`getPermissionsViaRoles()` and `getAllPermissions()` call `getPermissionsViaRolesWithPivots()`, which materializes the full permission set through held roles. After step 7, that materializer is no longer used by the hot `hasPermissionTo()` deny path, but public getters can call it repeatedly in one coroutine.

`getPermissionsViaRoles()` also lacks `unique()`.

#### How

Add a new context key:

```php
public const MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY = '__permission.model_via_role_permissions';
```

Add registrar helpers:

```php
public function rememberModelViaRolePermissions(Model $model, Closure $callback): Collection
{
    $key = $this->modelRuntimeCacheKey($model);
    $items = CoroutineContext::get(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, []);

    if (isset($items[$key]) && $items[$key] instanceof Collection) {
        return $items[$key];
    }

    $items[$key] = $callback();
    CoroutineContext::set(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, $items);

    return $items[$key];
}
```

```php
public function forgetModelViaRolePermissions(Model $model): void
{
    $items = CoroutineContext::get(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, []);
    unset($items[$this->modelRuntimeCacheKey($model)]);
    CoroutineContext::set(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, $items);
}
```

```php
protected function modelRuntimeCacheKey(Model $model): string
{
    $teamId = $this->teams ? (string) ($this->getPermissionsTeamId() ?? 'global') : 'none';
    $segments = [
        $this->modelAssignmentCacheToken(),
        $model->getMorphClass(),
        $model->getKey(),
        $teamId,
    ];

    $scope = $this->cacheKeyScopeSegment();

    if ($scope !== '') {
        array_unshift($segments, $scope);
    }

    return implode(':', $segments);
}
```

Replace the body of `wildcardPermissionIndexKey()` with this helper too:

```php
protected function wildcardPermissionIndexKey(Model $record): string
{
    return $this->modelRuntimeCacheKey($record);
}
```

This avoids two independent copies of the same runtime key formula drifting apart.

Clear this context in `clearPermissionsCollection()`:

```php
CoroutineContext::forget(self::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY);
```

Extend `forgetModelAssignmentCache()`:

```php
public function forgetModelAssignmentCache(Model $model): void
{
    $cache = $this->cacheRepository();

    $cache->forget($this->modelCacheKey($this->modelRolesCacheKeyPrefix, $model));
    $cache->forget($this->modelCacheKey($this->modelPermissionsCacheKeyPrefix, $model));

    $this->forgetModelViaRolePermissions($model);
    $this->forgetWildcardPermissionIndex($model);
}
```

Extend `forgetModelRoleCache()`:

```php
public function forgetModelRoleCache(Model $model): void
{
    $this->cacheRepository()->forget(
        $this->modelCacheKey($this->modelRolesCacheKeyPrefix, $model)
    );

    $this->forgetModelViaRolePermissions($model);
    $this->forgetWildcardPermissionIndex($model);
}
```

Extend `forgetModelPermissionCache()` so direct permission mutations also clear the wildcard index in one place:

```php
public function forgetModelPermissionCache(Model $model): void
{
    $this->cacheRepository()->forget(
        $this->modelCacheKey($this->modelPermissionsCacheKeyPrefix, $model)
    );

    $this->forgetWildcardPermissionIndex($model);
}
```

After centralizing wildcard invalidation in the registrar, remove redundant `$this->forgetWildcardPermissionIndex()` calls from trait mutation methods that immediately called one of these registrar forget helpers. Keep direct calls only where no registrar model-cache helper is invoked.

Apply this exact keep/remove list:

| File / method | Current site | Action | Why |
|---|---:|---|---|
| `HasPermissions::attachPermissions()` | around line 644 | Keep for unsaved models; remove from branches that already called `forgetModelPermissionCache()` or `forgetCachedPermissions()` by restructuring the method so the direct call only runs when no cache-clearing helper ran | unsaved models only queue assignments and have no registrar cache helper to centralize through |
| `HasPermissions::attachQueuedPermissionAssignments()` | around line 791 | Remove | saved model flush goes through `forgetModelPermissionCache()` or `forgetCachedPermissions()` |
| `HasPermissions::syncPermissions()` | around line 916 | Remove for saved models; keep only in any unsaved/queued branch that does not call a registrar helper | saved direct mutations go through `forgetModelPermissionCache()` |
| `HasPermissions::syncPermissionsWithForbidden()` | around line 962 | Keep | this is the unsaved queued branch and no registrar model-cache helper runs |
| `HasPermissions::syncPermissionsWithForbidden()` | around line 990 | Remove | saved direct mutations go through `forgetModelPermissionCache()` |
| `HasPermissions::revokePermissionTo()` | around line 1015 | Remove | saved direct mutations go through `forgetModelPermissionCache()` |
| `HasRoles::assignRole()` | around line 321 | Keep for unsaved models; remove from branches that already called `forgetModelRoleCache()` or `forgetCachedPermissions()` by restructuring the method so the direct call only runs when no cache-clearing helper ran | unsaved models only queue assignments and have no registrar cache helper to centralize through |
| `HasRoles::attachQueuedRoleAssignments()` | around line 366 | Remove | saved model flush goes through `forgetModelRoleCache()` or `forgetCachedPermissions()` |
| `HasRoles::removeRole()` | around line 407 | Remove | saved role mutations go through `forgetModelRoleCache()` |
| `HasRoles::syncRoles()` | around line 472 | Remove | saved role mutations go through `forgetModelRoleCache()`; the unsaved path delegates to `assignRole()` which keeps the direct call |

This enumeration is intentionally specific because removing the unsaved/queued direct calls would create stale wildcard indexes within the same coroutine.

Use the memo only in `getPermissionsViaRolesWithPivots()`:

```php
protected function getPermissionsViaRolesWithPivots(): Collection
{
    if ($this instanceof Role || $this instanceof Permission) {
        return collect();
    }

    return $this->permissionRegistrar()->rememberModelViaRolePermissions(
        $this,
        fn (): Collection => $this->loadPermissionsViaRolesWithPivots(),
    );
}
```

Move the existing materialization body into:

```php
protected function loadPermissionsViaRolesWithPivots(): Collection
{
    $roles = $this->getCachedRoles();

    if ($roles->isEmpty()) {
        return collect();
    }

    // existing full materialization for public getters
}
```

Update `getPermissionsViaRoles()` to dedupe:

```php
return $permissions
    ->reject(fn (Model $permission): bool => isset($forbiddenPermissionKeys[$this->permissionComparisonKey($permission)]))
    ->unique(fn (Model $permission): string => $this->permissionComparisonKey($permission))
    ->sort()
    ->values();
```

Also update `getAllPermissions()` to use the same comparison key:

```php
return $directPermissions
    ->merge($viaRolePermissions)
    ->reject(fn (Model $permission): bool => isset($forbiddenPermissionKeys[$this->permissionComparisonKey($permission)]))
    ->unique(fn (Model $permission): string => $this->permissionComparisonKey($permission))
    ->sort()
    ->values();
```

Use `permissionComparisonKey()` rather than bare id so same id under different guards or unusual custom models cannot collide.

#### Invalidation Rules

The memo is safe only with explicit invalidation:

- `forgetCachedPermissions()` clears the whole context through `clearPermissionsCollection()`
- `forgetModelRoleCache($model)` clears the model's via-role memo
- `forgetModelAssignmentCache($model)` must clear both direct model assignment cache and via-role memo, because delete hooks use it
- role-permission changes call `forgetCachedPermissions()`, so token and catalog clearing invalidate every via-role memo
- role assignment changes call `forgetModelRoleCache($model)`, so target model memo clears immediately
- `HasAssignedModels` global bumps invalidate by token

Do not add a direct-permission hydrated memo in this plan. Catalog indexes remove the bigger direct path cost without a second per-model hydration cache.

#### Tests

Add to `tests/Permission/CacheTest.php`:

1. Warm `getPermissionsViaRoles()`.
2. Mutate the model's roles in the same coroutine.
3. Assert later `getPermissionsViaRoles()` reflects the mutation.
4. Warm `getAllPermissions()`.
5. Mutate a role's permission edges.
6. Assert later `getAllPermissions()` reflects the mutation.

Add to `tests/Permission/ForbiddenPermissionTest.php`:

- same permission granted through two roles appears once from `getPermissionsViaRoles()`
- forbidden duplicate remains excluded

Run:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/CacheTest.php
./vendor/bin/phpunit --no-progress tests/Permission/ForbiddenPermissionTest.php
```

### 9. Remove Storage Connection Option

#### Why

Current config:

```php
'storage' => [
    'database' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
    ],
],
```

Current migrations:

```php
public function getConnection(): ?string
{
    return config('permission.storage.database.connection') ?: parent::getConnection();
}
```

Problems:

- config is truthy by default, so `php artisan migrate --database=...` is ignored for permission tables
- models never set the configured connection
- permission assignment tables point at app models through `model_type` and `model_id`
- split DB storage loses normal transaction coherence with users and app models
- a second DB connection adds another pool per worker
- Hypervel already has the stack cache driver for hot reads

#### How

Remove from `src/permission/config/permission.php`:

```php
'storage' => [
    'database' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
    ],
],
```

Remove `getConnection()` from:

- `src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php`
- `src/permission/database/migrations/add_teams_fields.php.stub`

The migrations should use normal framework behavior:

```php
Schema::create(...);
Schema::table(...);
Schema::dropIfExists(...);
```

Remove the `$schema = Schema::connection($this->getConnection())` local entirely, and convert all `$schema->create(...)`, `$schema->table(...)`, `$schema->hasColumn(...)`, and `$schema->dropIfExists(...)` calls in both `up()` and `down()` to the `Schema::` facade directly. The migrator scopes the default connection for the migration body, so plain `Schema::` honors `--database` and reads as the intended framework-native design.

Remove from `src/permission/src/Support/Config.php`:

```php
public static function storageConnection(): ?string
{
    return self::repository()->get('permission.storage.database.connection');
}
```

Remove any tests or setup lines that set `permission.storage.database.connection`, including `tests/Permission/TestCase.php`.

Do not add a replacement config key.

Remove the live documentation surface for this option:

- remove the `Database Connection` ToC entry from `src/boost/docs/permission.md`
- remove the `<a name="database-connection"></a>` section from `src/boost/docs/permission.md`
- verify `src/permission/README.md` does not document a separate storage connection
- ignore historical `docs/plans/*.md` references when checking for live docs

#### Tests

The schema/config tests do not need source changes unless implementation reveals a failing assertion; run them as regressions after removing the config key and migration override:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/SchemaConfigTest.php
./vendor/bin/phpunit --no-progress tests/Permission/CustomSchemaConfigTest.php
```

Add an explicit regression assertion if the existing tests do not already cover it: permission package tables must be created on the test connection used by `migrateFreshUsing()` after the storage connection option is gone.

Run the full permission test suite after all permission changes:

```shell
./vendor/bin/phpunit --no-progress tests/Permission
```

### 10. Minor Cleanup And Docs

#### Redundant `withPivot`

Current:

```php
$relation = $this->morphToMany(...)->withPivot('is_forbidden');

if (! Config::teamsEnabled()) {
    return $relation;
}

$teamsKey = Config::teamForeignKey();
$relation->withPivot($teamsKey, 'is_forbidden');
```

Target:

```php
$teamsKey = Config::teamForeignKey();
$relation->withPivot($teamsKey);
```

Only add the team pivot in the team branch because `is_forbidden` was already added.

#### README And Boost Docs

`src/boost/docs/permission.md` already says allowed getters return allowed permissions only. Add concise bullets under `Differences From Spatie Laravel Permission` in both `src/permission/README.md` and `src/boost/docs/permission.md`:

```md
- `getDirectPermissions()`, `getPermissionsViaRoles()`, `getAllPermissions()`, and `getPermissionNames()` return effective allowed permissions. Explicit denies are exposed through `hasForbiddenPermission()` and `hasForbiddenPermissionViaRoles()`.
- Undefined `permission.cache.store` values fail fast through Hypervel's cache manager instead of silently falling back to an array store.
```

Do not add stale notes about removed storage connection config. The option should not appear in docs after implementation.

#### Tests

No separate docs tests are needed. Run the focused source tests already listed.

## Full Verification Commands

After all focused tests pass:

```shell
./vendor/bin/phpstan
./vendor/bin/php-cs-fixer fix
composer test:parallel
```

Run these from the components repository root:

```shell
/home/binaryfire/workspace/monorepo/contrib/hypervel/components
```

Do not run `vendor/bin/paratest` directly. Use `composer test:parallel`.

## Fresh Review Checklist Before Implementation

Before coding each item, re-read the files listed for that item. Do not rely on this plan alone.

For every modified file:

- check adjacent code for now-stale comments
- remove dead helpers made unnecessary by the change
- keep method placement logical
- preserve Laravel/Spatie ordering where porting order matters
- keep method docblocks title-style and concise
- avoid runtime guards that only exist for static analysis
- prefer local `@var` assertions if PHPStan needs narrowing
- run tests immediately after each test file change

Specific review checks:

1. Cross-guard tests cover direct user, role-via-user, and Role model paths.
2. Concrete permission object checks never become fail-open.
3. Wildcard checks still deny explicit forbidden permissions before wildcard allow.
4. `HasAssignedModels` team tests prove other teams survive assign, remove, sync, and empty sync.
5. Delete hooks do not bump global token for plain models.
6. Role/Permission deletes still bump global token through `RefreshesPermissionCache`.
7. Catalog indexes preserve `Collection` return behavior and only fast-path supported param shapes.
8. Old cache payloads without `hasForbiddenRolePermissions` are safe.
9. Via-role memo clears on same-coroutine role assignment mutation and role-permission mutation.
10. Storage connection config has zero remaining references.
11. README and Boost docs have no stale storage-connection language.

## Expected Final State

After this plan is implemented:

- permission checks use guard-exact deny matching
- role-deny checks do not scan every permission for every ability
- the registrar's catalog is still the single source for role and permission lookup data in a coroutine
- indexes are internal and invalidated with the catalog
- read-side role and permission lookups use the catalog, while write-side uniqueness checks use direct DB queries
- via-role materialization is memoized only for public collection getters and invalidated centrally
- reverse assignment helpers are team-safe
- package migrations use normal framework migration connection behavior
- users who need split RBAC storage must provide custom models and migrations deliberately
- docs describe only the final supported behavior
