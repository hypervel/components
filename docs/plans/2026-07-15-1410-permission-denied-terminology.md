# Permission Denied Terminology Plan

## Objective

Align Hypervel Permission's explicit negative permission effect with the established authorization vocabulary of **allow** and **deny**.

The finished package must read as if explicit denial had always been designed with this vocabulary:

- public methods use `deny` / `denied` consistently;
- assignment pivots store a boolean `is_denied` effect;
- runtime variables, helpers, cache payloads, tests, package metadata, and documentation use the same terms;
- `givePermissionTo()` remains the positive Spatie-shaped assignment API;
- `revokePermissionTo()` continues to remove an assignment edge rather than creating a denied edge;
- behavior, query shape, cache architecture, events, partitioning, teams, guards, wildcard checks, UUID/ULID support, and integer support do not otherwise change.

This is a direct source migration. Backwards compatibility and upgrade choreography are not design constraints. Do not add deprecated aliases, dual columns, fallback cache fields, compatibility reads, follow-up migrations, or transitional documentation. The final tree must contain one vocabulary and one implementation.

## Why Deny Is The Correct Vocabulary

### Authorization conventions

The package models a binary effect on an existing permission assignment edge. One state grants the permission and the other explicitly rejects it. `allow` / `deny` is the clearest pair for that model:

- [AWS IAM policy evaluation](https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_policies_evaluation-logic.html) describes `Allow` and `Deny` effects and specifies that an explicit deny overrides an allow.
- [XACML 3.0](https://docs.oasis-open.org/xacml/3.0/xacml-3.0-core-spec-en.html) uses `Permit` and `Deny` authorization decisions.
- [Cedar authorization](https://docs.cedarpolicy.com/auth/authorization.html) uses policy-language verbs `permit` and `forbid`, but its authorization engine still returns `Allow` or `Deny`. This shows that `forbid` is valid inside Cedar's specifically paired policy grammar, not that it is the clearest name for a general permission-assignment API.
- Hypervel Auth already exposes `Response::allow()`, `Response::deny()`, `Response::allowed()`, and `Response::denied()`, plus Gate methods such as `allows()` and `denies()`. Permission should use the same framework vocabulary.

The current package already calls the positive effect `allowed` in the dual-effect synchronization API and documentation. Renaming the negative half to `denied` removes a mixed `allowed` / `forbidden` vocabulary.

### Spatie baseline

The local upstream reference at `examples/spatie/permission` has no negative assignment effect or corresponding public API. This capability is Hypervel-owned, so choosing the terminology does not diverge from an upstream Spatie method name. Spatie's positive APIs remain unchanged.

### Repository inventory

A case-insensitive source audit found the Permission-specific terminology in 28 live package/document/test files, four prior Permission plan documents, and 14 files under `_archive/`. Two other plan files contain unrelated ordinary-English uses that must remain unchanged. The live implementation is concentrated in the stock migration, three effect-bearing relations, `HasPermissions`, `PermissionRegistrar`, the About feature label, the package README, and the Boost Permission guide. No config key, contract interface, event class, exception class, command class, Blade directive, middleware name, or route macro carries the terminology.

The same whole-tree audit also found unrelated HTTP 403 APIs and ordinary-English uses. Those are classified explicitly below rather than being swept into this change.

### Explicit denial versus implicit denial

The package must distinguish:

- **implicit denial**: no effective allowed permission exists; and
- **explicit denial**: an assignment row exists with `is_denied = true`, which overrides matching allowed edges.

Methods such as `hasDeniedPermission()` inspect explicit denied edges. They do not answer whether the final authorization result is false for any reason. Their docblocks and user documentation must say this explicitly.

## Settled Public API

The final public API is:

| Purpose | Final API | Contract |
|---|---|---|
| Add or flip an allowed edge | `givePermissionTo(...$permissions): static` | Unchanged Spatie-shaped API. |
| Add or flip a denied edge | `denyPermissionTo(...$permissions): static` | Stores `is_denied = true`. |
| Remove either effect | `revokePermissionTo($permission): static` | Deletes the assignment edge; it does not deny it. |
| Replace both effect sets | `syncPermissionEffects(array\|Collection $allowed = [], array\|Collection $denied = []): array` | Denied input wins if the same permission appears in both lists. |
| Inspect a direct explicit deny | `hasDeniedPermission($permission, ?string $guardName = null): bool` | Checks direct denied edges only. |
| Inspect a role-derived explicit deny | `hasDeniedPermissionViaRoles($permission, ?string $guardName = null): bool` | Checks denied role-permission edges inherited by the model. |
| Skip unnecessary via-role work | `PermissionRegistrar::hasDeniedRolePermissions(): bool` | Reports whether the hydrated catalog has any denied role-permission edge. |

Remove the old public names outright. Do not retain aliases or deprecation wrappers. `tests/Permission/PublicApiTest.php` must prove the final method names and parameter names, including `syncPermissionEffects` parameters `allowed` and `denied`.

The complete symbol and storage rename map is:

| Existing name | Final name |
|---|---|
| `giveForbiddenTo()` | `denyPermissionTo()` |
| `hasForbiddenPermission()` | `hasDeniedPermission()` |
| `hasForbiddenPermissionViaRoles()` | `hasDeniedPermissionViaRoles()` |
| `syncPermissionsWithForbidden(allowed:, forbidden:)` | `syncPermissionEffects(allowed:, denied:)` |
| `PermissionRegistrar::hasForbiddenRolePermissions()` | `PermissionRegistrar::hasDeniedRolePermissions()` |
| `is_forbidden` | `is_denied` |
| `forbiddenPermissionKeys()` | `deniedPermissionKeys()` |
| `pivotIsForbidden()` | `pivotIsDenied()` |
| `permissionEffectIsForbidden()` | `permissionEffectIsDenied()` |
| `hasForbiddenRolePermissions` cache field | `hasDeniedRolePermissions` cache field |

Correct the adjacent inaccurate Spatie-derived docblock while editing the API pair:

```php
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
```

The explicit-denial query methods use accurate Laravel-style title docblocks:

```php
/**
 * Determine if the model has an explicit denied direct permission.
 *
 * @param int|Permission|string|UnitEnum $permission
 */
public function hasDeniedPermission($permission, ?string $guardName = null): bool;

/**
 * Determine if the model has an explicit denied permission via roles.
 *
 * @param int|Permission|string|UnitEnum $permission
 */
public function hasDeniedPermissionViaRoles($permission, ?string $guardName = null): bool;
```

## Schema Contract

Rename the assignment effect column in the stock migration:

```php
$table->boolean('is_denied')->default(false);
```

Apply this to:

- `model_has_permissions`;
- `role_has_permissions`.

Keep the boolean representation. The data model is an assignment edge with one of two effects, not an extensible policy-effect enum. A string or enum column would add storage, indexing, validation, and branching complexity without representing another supported state.

Edit `src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php` in place. Do not add an upgrade migration. Application-owned custom migrations, including the partitioned schema examples, use `is_denied` directly.

The column remains outside the primary key. One permission assignment identity has exactly one row, and assigning the opposite effect updates that row.

## Runtime Semantics That Must Not Change

This work changes vocabulary, not authorization behavior.

### Effective permission checks

`hasPermissionTo()` and the wildcard path must check direct and inherited explicit denies before returning an allow:

```php
if ($this->hasDeniedPermission($permission, $guardName)) {
    return false;
}

if ($this->hasDeniedPermissionViaRoles($permission, $guardName)) {
    return false;
}
```

Role checks use `hasDeniedPermission()` before accepting a direct role-permission allow.

### Relations and query scopes

Every Permission relation that hydrates an effect pivot must request the final column:

```php
->withPivot('is_denied');
```

This applies to:

- model-to-permission relations in `HasPermissions`;
- role-to-permission relations in `Models/Role`;
- permission-to-role relations in `Models/Permission`.

The `permission()` and `withoutPermission()` scopes keep their existing SQL structure. Only the effect-column identifier and internal boolean parameter name change:

```php
protected function whereDirectPermissionEffect(
    Builder $query,
    int|string $permissionId,
    bool $denied,
): Builder {
    $permissionKey = Guard::getModelKeyName($this->getPermissionClass());
    $pivotTable = $this instanceof Role
        ? Config::roleHasPermissionsTable()
        : Config::modelHasPermissionsTable();

    return $query
        ->where(Config::permissionsTable() . ".{$permissionKey}", $permissionId)
        ->where("{$pivotTable}.is_denied", $denied);
}
```

Rename the sibling inherited-role predicate in the same pass:

```php
protected function whereRolePermissionEffect(
    Builder $query,
    int|string $permissionId,
    bool $denied,
): Builder {
    $permissionKey = Guard::getModelKeyName($this->getPermissionClass());

    return $query
        ->where(Config::permissionsTable() . ".{$permissionKey}", $permissionId)
        ->where(Config::roleHasPermissionsTable() . '.is_denied', $denied);
}
```

There must be no additional query, join, cache read, or allocation on a hot path. The generated SQL is identical except for the renamed physical column.

### Writes and synchronization

Keep the existing effect-aware synchronizer and its performance characteristics. Rename its data vocabulary coherently:

```php
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
    PermissionRelationContext $context,
    bool $detaching,
): array;
```

Desired and current assignment entries use `is_denied`. Rename effect-specific batches to `$attachAllowed`, `$attachDenied`, `$updateAllowed`, and `$updateDenied`. Preserve:

- one pivot-only current-state read;
- one bulk detach when needed;
- at most one allowed attach and one denied attach;
- at most one allowed-effect update and one denied-effect update;
- the existing transaction and single `touchIfTouching()` call;
- caller-order and driver-independent change-set behavior;
- integer, UUID, and ULID key normalization.

The effect updates become:

```php
if ($updateAllowed !== []) {
    $relation->newPivotQuery()
        ->whereIn($relatedPivotKey, $updateAllowed)
        ->update(['is_denied' => false]);
}

if ($updateDenied !== []) {
    $relation->newPivotQuery()
        ->whereIn($relatedPivotKey, $updateDenied)
        ->update(['is_denied' => true]);
}
```

Retain the comment explaining why these raw batched effect updates are correct for Permission's timestamp-free, non-custom pivots. Update the comment's terminology only.

The final dual-effect sync implementation is named and documented as follows:

```php
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
public function syncPermissionEffects(
    array|Collection $allowed = [],
    array|Collection $denied = [],
): array;
```

The implementation must continue to remove any denied IDs from the allowed list, so denied wins when an ID is present in both inputs. Unsaved assignment batching must use `denied` in its private batch identities and preserve all existing captured partition/team context behavior.

### Events

Do not rename or reshape Permission assignment events. They report requested permission IDs, not effect names, and no event class or payload currently exposes the old vocabulary. Calls through `denyPermissionTo()` and `syncPermissionEffects()` must retain the existing event count, timing, listener guards, and payload format.

The event tests are renamed only where test method names or setup calls describe the denied operation.

## Cache Contract

Rename every cache representation atomically with the source schema:

- hydrated pivot key `is_denied`;
- serialized role-permission pivot key `is_denied`;
- model direct-permission assignment array key `is_denied`;
- coroutine catalog field `hasDeniedRolePermissions`;
- public fast-path method `hasDeniedRolePermissions()`.

The final serialized and hydrated catalog shapes are:

```php
/**
 * @return array{
 *     permissions: array<int, array<string, mixed>>,
 *     roles: array<int, array<string, mixed>>,
 *     hasDeniedRolePermissions: bool
 * }
 */
private function getSerializedPermissionsForCache(): array;
```

```php
$catalog = [
    // Existing hydrated collections and indexes remain unchanged.
    'hasDeniedRolePermissions' => (bool) $payload['hasDeniedRolePermissions'],
];
```

Treat both `hasDeniedRolePermissions` and the per-model assignment `is_denied` effect as required fields in package-generated cache payloads. Remove their optional old-payload annotations and null-coalescing fallbacks, along with the compatibility test that accepted a catalog payload without its flag. Retaining a field under the legacy name or silently treating a missing security-relevant value as false would contradict the greenfield requirement and could skip a required explicit-deny check. The stock migration clears the Permission catalog cache when it runs, and no cross-version cache compatibility layer is part of this design. Do not add a new runtime guard or custom exception for a missing field: package-generated payloads always contain both values, direct access exposes an invalid payload instead of masking it, and extra corrupt-cache machinery would be outside this rename.

Configured cache names, partition cache segments, team segments, guard behavior, assignment tokens, invalidation paths, TTLs, and per-coroutine memoization remain unchanged. This rename changes one internal serialized payload field but adds no cache operation and changes no cache identity. Permission has no separate custom cache-key resolver: the former cache-only resolver was removed when generic row partitioning became the complete SQL-and-cache isolation mechanism, and this terminology work must not reintroduce it.

## Internal Naming

Rename all implementation concepts together so no translation layer remains. Representative final names include:

| Current concept | Final name |
|---|---|
| effect boolean parameters | `$denied`, `$isDenied` |
| denied input IDs | `$deniedIds` |
| denied pivot data | `$deniedPivot` |
| denied attach/update batches | `$attachDenied`, `$updateDenied` |
| denied lookup builder | `deniedPermissionKeys()` |
| hydrated effect check | `pivotIsDenied()` |
| raw effect normalization | `permissionEffectIsDenied()` |
| catalog aggregate | `$hasDeniedRolePermissions` |

Update array-shape annotations, inline strings used only for private queued-assignment grouping, comments, and docblocks at the same time. Do not leave private adapters with the old vocabulary.

## Source Changes By File

### `src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php`

- Rename both assignment-effect columns to `is_denied`.
- Keep their type, default, position, keys, foreign keys, and table structure unchanged.

### `src/permission/src/Traits/HasPermissions.php`

- Rename the public negative-effect methods to the settled API.
- Correct the `givePermissionTo()` and `denyPermissionTo()` docblocks.
- Rename all negative-effect parameters, locals, helpers, pivot keys, queue batch labels, query predicates, array keys, and comments.
- Preserve all current authorization, event, deferred-assignment, transaction, partition, team, guard, wildcard, and synchronization logic.
- Use `is_denied` for relations, cache assignment hydration, current-pivot reads, inserts, and effect updates.
- Ensure direct/via-role allowed collections reject any explicit denied edge through `deniedPermissionKeys()` and `pivotIsDenied()`.

### `src/permission/src/PermissionRegistrar.php`

- Rename serialized pivot fields and the catalog aggregate to denied terminology.
- Rename `hasForbiddenRolePermissions()` to `hasDeniedRolePermissions()`.
- Rename `pivotIsForbidden()` to `pivotIsDenied()` and read `is_denied`.
- Make `hasDeniedRolePermissions` required in serialized payload shapes and hydration.
- Preserve catalog query counts, cache identities, relation hydration, partition scoping, and coroutine memoization.

### `src/permission/src/Models/Role.php`

- Hydrate `is_denied` on role-permission relations.
- Call `hasDeniedPermission()` and `pivotIsDenied()` in normal and wildcard checks.
- Preserve guard validation and relation semantics.

### `src/permission/src/Models/Permission.php`

- Hydrate `is_denied` on permission-role relations.
- Make no other behavioral change.

### `src/permission/src/PermissionServiceProvider.php`

- Change the `about` command feature label to `Denied Permissions`.
- Do not change feature enablement behavior.

## Documentation Changes

### `src/permission/README.md`

Describe the Hypervel extension as explicit denied permissions. Show the final API names and `allow` / `deny` effect pairing. Keep the concise package overview and all unrelated differences from Spatie unchanged.

Final wording must make these points clear:

- a denied assignment is explicit;
- it overrides direct or role-granted allows;
- the effect lives on the existing assignment edge;
- assigning the opposite effect flips that edge;
- effective permission collection APIs return allowed permissions;
- explicit denied edges are inspectable through the two final query methods.

### `src/boost/docs/permission.md`

Rename the section and anchor to:

```markdown
<a name="denied-permissions"></a>
### Denied Permissions
```

Rename the in-page table-of-contents entry and anchor together so the link remains valid:

```markdown
- [Denied Permissions](#denied-permissions)
```

Use `is_denied`, `denyPermissionTo()`, `hasDeniedPermission()`, `hasDeniedPermissionViaRoles()`, and `syncPermissionEffects(allowed:, denied:)` in prose and examples. Describe the behavior as explicit permission denial. Update the revocation section to say that revocation removes either an allowed or denied edge.

Update both `is_denied` columns in the custom partitioned migration example. The documentation remains generic: workspace partitioning may remain an example, but the terminology work must not imply any tenancy feature or dependency.

### Existing implementation plans

The checked-in plan documents are active design/context references and must not teach stale symbols. Review all six files found by the case-insensitive sweep:

1. `docs/plans/2026-06-24-1015-permission-fresh-spatie-port.md` — update Permission effect terminology, final symbols, schema snippets, test references, and acceptance text.
2. `docs/plans/2026-07-02-0930-permission-forbidden-single-state-hardening.md` — rename the file with `mv` to `docs/plans/2026-07-02-0930-permission-denied-single-state-hardening.md`, then update its title, final terminology, schema/code snippets, test filename, commands, and acceptance text.
3. `docs/plans/2026-07-02-1415-permission-review-hardening-and-performance.md` — update Permission effect terminology, symbols, code snippets, test filename, and commands.
4. `docs/plans/2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md` — review but do not edit its ordinary-English descriptions of behavior that PHP forbids.
5. `docs/plans/2026-07-13-1015-permission-row-partitioning.md` — update effect terminology, public/private symbols, schema examples, synchronization descriptions, test language, and checklists without changing the partition design.
6. `docs/plans/2026-07-06-1145-stack-cache-tags-and-tag-architecture-refactor.md` — review but do not edit its ordinary-English `Forbidden:` comment-policy label.

Git history preserves the old point-in-time spelling. The working tree should use the final names wherever a plan describes the live Permission design.

## Explicit Exclusions

### `_archive/`

Do not edit anything under `_archive/`. It is a parked historical snapshot, is not autoloaded/tested/analyzed, and intentionally preserves superseded source and documentation. The case-insensitive inventory currently finds 14 archive files; they remain untouched.

### HTTP and ordinary-English uses

Do not rename HTTP 403 concepts or unrelated English, including:

- `Response::forbidden()` and HTTP client status helpers;
- `assertForbidden()`;
- `ForbiddenException` classes;
- the 403 exception view;
- Horizon, Sentry, routing, filesystem, Passkeys, validation, and HTTP tests referring to HTTP forbidden responses;
- generated `dist` assets containing HTTP/UI text;
- `PermissionPartitionNotResolved` prose saying an unpartitioned fallback is not allowed;
- the stack-cache plan's ordinary-English `Forbidden:` guidance label.

This is not a repository-wide lexical replacement.

## Test Migration

Rename the primary test file with `mv`:

```text
tests/Permission/ForbiddenPermissionTest.php
→ tests/Permission/DeniedPermissionTest.php
```

Rename its class to `DeniedPermissionTest` and rewrite its test method names, variables, fixture names, pivot attributes, calls, and assertions in final terminology. Preserve every scenario and assertion.

Update all live affected tests:

- `tests/Permission/CacheTest.php`
- `tests/Permission/Commands/CommandTest.php`
- `tests/Permission/CustomSchemaConfigTest.php`
- `tests/Permission/DeletionTest.php`
- `tests/Permission/Events/EventTest.php`
- `tests/Permission/DeniedPermissionTest.php`
- `tests/Permission/GateTest.php`
- `tests/Permission/Integration/PartitionQueryCountTest.php`
- `tests/Permission/Integration/PermissionRegistrarTest.php`
- `tests/Permission/PartitionAuthorizationTest.php`
- `tests/Permission/PartitionCoroutineIsolationTest.php`
- `tests/Permission/PartitionRelationsTest.php`
- `tests/Permission/PartitionTeamsTest.php`
- `tests/Permission/PartitionTestCase.php`
- `tests/Permission/PublicApiTest.php`
- `tests/Permission/SchemaConfigTest.php`
- `tests/Permission/TestCase.php`
- `tests/Permission/Traits/HasPermissionsTest.php`
- `tests/Permission/Traits/TeamHasPermissionsTest.php`
- `tests/Integration/Database/PermissionPartitionTest.php`

The test update is not only lexical. Verify the following contracts explicitly.

### Public API

- `denyPermissionTo` exists with variadic `permissions`.
- `syncPermissionEffects` exists with parameters named `allowed` and `denied`.
- the removed public names do not exist.
- `hasDeniedPermission`, `hasDeniedPermissionViaRoles`, and `hasDeniedRolePermissions` are exercised directly.

### Schema and relations

- stock, custom-schema, team, partition-unit, and database-integration schemas create `is_denied` on the two effect-bearing pivots.
- model, role, and permission relations hydrate `is_denied`.
- raw relation attach/update tests use `is_denied`.

### Authorization semantics

- direct denied assignments override direct and role allows.
- denied role assignments override direct and other-role allows.
- allowed assignment flips a denied edge and denied assignment flips an allowed edge.
- denied wins when the same permission appears in both `syncPermissionEffects` lists.
- revocation removes denied edges.
- guards, teams, partitions, wildcard checks, query scopes, custom keys, integer IDs, UUIDs, and coroutine isolation retain current behavior.

### Cache and invalidation

- global cached role pivots hydrate `is_denied`.
- the catalog reports `hasDeniedRolePermissions` accurately.
- model permission caches require and retain `is_denied` effect data.
- direct and role mutations invalidate the same caches as before.
- partition and team cache identities remain isolated.
- configured cache key names and partition-derived cache identities remain unchanged.
- remove the obsolete missing-old-payload compatibility test rather than renaming it.

Replace that deleted compatibility test with direct security-relevant coverage of the real serialization path:

```php
public function testCatalogReportsWhetherItContainsDeniedRolePermissions(): void
{
    $registrar = $this->app->make(PermissionRegistrar::class);

    $this->assertFalse($registrar->hasDeniedRolePermissions());

    $this->testUserRole->denyPermissionTo($this->testUserPermission);

    $this->assertTrue($registrar->hasDeniedRolePermissions());
}
```

This test must serialize and hydrate the normal catalog in both states. The Role mutation performs the package's normal catalog invalidation between assertions. It replaces compatibility coverage with a stronger direct check that the fast-path flag cannot incorrectly skip inherited explicit denies.

Also exercise the per-model assignment cache through its real producer and hydrator. Grant the permission through a Role, deny it directly, query a fresh persisted subject with no loaded `permissions` relation, and assert that `hasDeniedPermission()` is true while `hasPermissionTo()` remains false. The competing Role allow makes the final assertion prove that the cache-hydrated direct deny overrides an actual allow. Assert that the relation remains unloaded so the test cannot pass through the loaded-relation shortcut.

### Performance and query shape

- partition query-count tests retain the same counts and write batching.
- unchanged effects are not rewritten or reported as updated.
- mixed effect flips still use exactly two target-value update statements.
- warm authorization remains query-free.
- no test expectation is weakened merely to accommodate the rename.

## Implementation Order

Follow the repository rule of one file at a time and run focused tests immediately after each coherent source change:

1. Rename the stock migration column and update base test schemas.
2. Rename model relation pivots.
3. Rename `PermissionRegistrar` cache fields/helpers and update its focused cache tests.
4. Rename `HasPermissions` public API and internals, then rename and run the primary denied-permission test.
5. Update `Role`, followed by Gate/wildcard/role tests.
6. Update the remaining unit and integration tests one file at a time.
7. Update README and Boost documentation.
8. Rename and update the four Permission-related plan documents; review and retain the unrelated lifecycle-audit and stack-cache occurrences.
9. Perform the exhaustive terminology and diff audit.
10. Run the full repository quality command.

All edits are manual and targeted with `apply_patch`; file renames use `mv`. Do not use `sed`, `awk`, a loop, or a script to rewrite files in bulk without explicit owner approval. Search commands may aggregate results but must not mutate files.

## Focused Verification Commands

Run focused suites as their files are updated:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/DeniedPermissionTest.php
./vendor/bin/phpunit --no-progress tests/Permission/CacheTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PublicApiTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Events/EventTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Traits/HasPermissionsTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Traits/TeamHasPermissionsTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionAuthorizationTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionRelationsTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Integration/PartitionQueryCountTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/PermissionPartitionTest.php
```

Then run the complete affected package suite:

```shell
./vendor/bin/phpunit --no-progress tests/Permission
./vendor/bin/phpunit --no-progress tests/Integration/Database/PermissionPartitionTest.php
```

Run supported database integration coverage through the existing database workflow/environment for MySQL, MariaDB, PostgreSQL, and SQLite. This is important because the synchronizer reads native boolean/0-1 pivot values across drivers.

Finally run:

```shell
composer fix
```

`composer fix` is the authoritative final gate and runs code formatting, both PHPStan configurations, the parallel test suite, the Testbench package suite, and the dogfood package suite in repository-defined order.

## Exhaustive Terminology Audit

After implementation, perform a case-insensitive search of the whole working tree. Classify every remaining old negative-effect occurrence rather than assuming the search should be empty.

Expected retained categories are only:

- `_archive/` historical snapshot;
- HTTP 403 APIs/tests/views/exceptions;
- generated HTTP/UI assets;
- ordinary English unrelated to Permission;
- this implementation plan's explicit before/after research and migration record.

There must be no old negative-effect terminology in:

- live `src/permission` source, migration, README, cache shapes, or package metadata;
- live Permission tests, including the database integration fixture;
- Boost Permission documentation;
- the four Permission-related prior implementation plans;
- live filenames or class names.

Also search explicitly for every removed symbol and column so casing or a partial word cannot hide a stale reference. Inspect `git diff --check`, `git status --short`, and the full `git diff` file by file. Confirm `_archive/` and unrelated HTTP files have no diff.

## Acceptance Criteria

- [ ] Public negative-effect APIs use `deny` / `denied` exclusively.
- [ ] `givePermissionTo()` and `revokePermissionTo()` retain their established meanings.
- [ ] Assignment pivots use only `is_denied` in live source, schemas, relations, caches, tests, and docs.
- [ ] The permission catalog uses required `hasDeniedRolePermissions` data with no legacy fallback.
- [ ] Internal helpers, variables, array shapes, comments, and queue labels use denied terminology.
- [ ] Explicit denied checks remain distinct from implicit denial.
- [ ] Direct, role-derived, wildcard, guard, team, partition, query-scope, cache, event, and deferred-assignment behavior remains unchanged.
- [ ] No database query, cache operation, hot-path branch, or synchronization write is added.
- [ ] Integer, UUID, and ULID keys retain native database column behavior and current normalization.
- [ ] Cache invalidation, configured cache keys, partition-derived identities, and coroutine memoization remain intact.
- [ ] README, Boost docs, About output, and Permission design plans describe only the final API.
- [ ] The primary test file/class and the Permission single-state plan filename use denied terminology.
- [ ] `_archive/` is untouched.
- [ ] HTTP 403 and unrelated ordinary-English uses are untouched.
- [ ] Focused tests, affected Permission suites, supported-database integration tests, and `composer fix` pass.
- [ ] The final diff contains no compatibility alias, workaround, dead code, stale comment, stale documentation, or unrelated edit.
