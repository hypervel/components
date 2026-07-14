# Generic Permission Row Partitioning Plan

## Objective

Add a generic, opt-in row-partition dimension to `hypervel/permission` so every role, permission, assignment, relation, query scope, cache entry, mutation, command, and lifecycle cleanup operates inside one application-defined partition.

The feature is not a tenancy subsystem and must not know what a tenant is. Permission receives only:

- the authorization-table column that stores the partition;
- a boot-registered callback that resolves the current opaque scalar value from in-memory request, command, or job context.

Multi-tenancy is one possible use, but workspaces, installations, realms, organizations, environments, and other row-partition schemes are equally valid.

The finished package must have these properties:

- Partitioning is disabled by default and unpartitioned applications retain their current Laravel-shaped API and behavior.
- Applications opt in with one boot-only registration. There is no framework-owned partition model, middleware, command option, migration, or context implementation.
- Enabling partitioning adds no database query to ordinary reads, writes, cache hits, or cache misses. Existing SQL receives one additional bound predicate and pivot inserts receive one additional value.
- Missing partition context fails closed. It can never fall back to an unpartitioned query or cache key.
- Role and permission instance writes remain protected even though Eloquent instance updates, deletes, refreshes, and queued-model restoration intentionally bypass global scopes.
- All five authorization tables use the same non-null partition column.
- All package-built pivot relations enforce that column for reads, relation-existence queries, eager loads, attach, detach, toggle, sync, pivot lookup, and pivot update.
- Caller-supplied pivot data cannot override the captured partition or move an existing edge to another partition.
- Partition and teams are independent nested dimensions. Guards remain independent authorization dimensions.
- Loaded Eloquent relations, shared-cache entries, assignment tokens, wildcard indexes, and coroutine-local memos cannot bleed across partitions or teams.
- Normal mutation invalidation is partition-specific and derives the partition from persisted Role or Permission records when possible.
- Hard deletion of a globally identified subject discovers affected partition/team scopes once per owning assignment table, deletes its assignments, and forgets only that subject's discovered cache identities.
- Stock commands operate in the ambient partition and fail closed when it is absent. Permission does not invent a generic partition enumerator or `--partition` option.
- The misleading cache-only contextual isolation API is removed rather than retained as an overlapping, security-incomplete alternative.
- The stock migration remains unpartitioned. Applications that opt in own their customized schema.
- `src/boost/docs/permission.md` and `src/permission/README.md` describe the feature generically, contain complete schema and lifecycle guidance, and contain no stale recommendation to use cache namespacing for row isolation.
- The final source contains no obsolete resolver code, stale comments, dead helpers, compatibility shims, or documentation that describes the superseded behavior.

Backwards compatibility, diff size, and churn are not constraints. Laravel and Spatie public APIs remain intact; Hypervel-owned APIs may be replaced where the old design is unsafe or misleading.

## Research Completed

### Repository instructions

`AGENTS.md` was re-read in full before this plan was created. The implementation must particularly honor:

- Laravel-shaped public APIs;
- coroutine-local request state;
- worker-lifetime review for static callbacks and singleton-held registries;
- boot-only warnings on worker-lifetime mutators;
- `flushState()` cleanup for framework static state;
- broad `src/` and `tests/` searches;
- correctness and final design quality over minimizing churn;
- one source file and one test class at a time;
- per-test-class verification, package PHPStan, then `composer test:parallel`.

### Hypervel Permission source read

The following implementation surfaces were read and traced:

- `src/permission/src/PermissionRegistrar.php`
- `src/permission/src/PermissionServiceProvider.php`
- `src/permission/src/Models/Role.php`
- `src/permission/src/Models/Permission.php`
- `src/permission/src/Traits/HasRoles.php`
- `src/permission/src/Traits/HasPermissions.php`
- `src/permission/src/Traits/HasAssignedModels.php`
- `src/permission/src/Traits/RefreshesPermissionCache.php`
- all Permission commands and assignment events
- Permission Role and Permission contracts
- `src/permission/src/Support/Config.php`
- the package config and stock migration
- `src/permission/README.md`
- all of `src/boost/docs/permission.md`
- the current Permission test base, cache/query-count tests, model tests, relation/assignment tests, teams tests, forbidden/wildcard tests, command tests, event tests, and custom-model fixtures.

### Eloquent and cache internals read

The design was checked against:

- `src/database/src/Eloquent/Relations/BelongsToMany.php`
- `src/database/src/Eloquent/Relations/MorphToMany.php`
- `src/database/src/Eloquent/Relations/Concerns/InteractsWithPivotTable.php`
- `src/database/src/Eloquent/Concerns/HasRelationships.php`
- Eloquent model save, update, delete, refresh, restoration, increment/decrement, and soft-delete paths
- `src/queue/src/SerializesAndRestoresModelIdentifiers.php`
- `src/cache/src/SwooleStore.php`
- `src/cache/src/SwooleTable.php`
- Permission cleanup registration in `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`.

Important verified behavior:

1. `BelongsToMany::withPivotValue()` already supplies the pivot read predicate, base insert value, pivot hydration value, and `newPivotQuery()` constraint.
2. Native `formatAttachRecord()` merges caller attributes after base pivot values, so caller attributes can override `withPivotValue()` unless the package rejects and re-forces the invariant.
3. Native `updateExistingPivot()` writes caller attributes through a pivot query that is already constrained by `withPivotValue()`, but it can still update the invariant column unless the package strips it.
4. `sync`, `syncWithPivotValues`, `toggle`, `detach`, current-pivot lookup, and custom-pivot lookup all funnel through those native relation mechanisms. Overriding `newPivot()` or `newPivotQuery()` is unnecessary.
5. Eloquent model `save()`, `delete()`, `refresh()`, `fresh()`, increment/decrement, and queued model restoration use unscoped or base model queries. A global scope alone cannot protect existing Role or Permission instances.
6. `saveQuietly()`, `deleteQuietly()`, and `restoreQuietly()` suppress events. Partition SQL predicates, insert values, and immutable-key checks therefore live in model query/write overrides and remain enforced. Cache invalidation, deferred-assignment flushing, pivot cleanup, and other event-driven package behavior intentionally follow Laravel's normal event-muting semantics; do not invent a second lifecycle system for quiet operations.
7. Queued Eloquent models are restored through `newQueryForRestoration()`, which deliberately starts without global scopes.
8. Hypervel's Swoole cache store hashes logical cache keys with `xxh128` before using them as native Swoole Table keys, so a raw UUID partition segment does not threaten the native table key length. More partitions do increase row count and therefore require appropriate table capacity.
9. Hypervel's `SwooleTable` wrapper rejects oversized string values before native Swoole can truncate them. No Swoole corruption change belongs in this work.
10. `ContextServiceProvider` hydrates the application-facing Context from the queue payload on `JobProcessing`; Worker and SyncQueue dispatch that event before `CallQueuedHandler` unserializes the command. Partition context is therefore present when `SerializesModels::__unserialize()` restores queued Role and Permission models.
11. `EloquentUserProvider` has a separate auth-user-cache `resolveCacheKeyUsing()` API. It keys already identifier-specific user-cache entries and is not Permission's unsafe cache-catalog isolation API. This work removes only `PermissionRegistrar::resolveCacheKeyUsing()`; do not rename or remove the independent Auth API merely because the method names coincide.
12. Eloquent fires `saved` before `finishSave()` synchronizes a newly inserted model's original attributes. During that callback, `wasRecentlyCreated` is true, the inserted partition exists in raw current attributes, and raw original attributes do not yet contain it. Record-derived invalidation must therefore prefer a present raw original value, then fall back to a present raw current value only for a newly inserted model. It must never read the fallback through casts/accessors or use it for an existing narrowed model.
13. Eloquent eager loading constructs each relation under `Relation::noConstraints()` on an attribute-less, non-persisted `newInstance()` prototype. Partition-aware Role/Permission relations must allow only that prototype to define the relation; real persisted parents and unsaved parents carrying an explicit partition attribute still validate. The eager query remains protected by the captured related-table and pivot predicates, while `addEagerConstraints()` supplies the real parent keys.
14. Unsaved-subject assignment queues currently retain only Role/Permission IDs and pivot data, then resolve one ambient relation context again from the model's `saved` callback. If context changes before the model is saved, the captured pivot and newly resolved relation disagree. Multiple deferred batches can also partially commit before a later batch fails, leaving the retained queue inconsistent with the database on retry. Deferred assignments must retain their immutable `PermissionRelationContext`, flush every Role and Permission batch in one model-connection transaction, clear queues only after commit, and invalidate each captured context only after commit.
15. Pure-add APIs currently queue and invalidate even when their resolved Role or Permission ID list is empty. The replacement/sync queue path already skips empty lists. `assignRole()`, `givePermissionTo()`, and `giveForbiddenTo()` must treat an empty resolved add as no write, queue, or cache mutation, while still resolving and validating the current partition before the no-op guard. Their existing Spatie-shaped events continue reporting the collected request, including an empty or already-satisfied request; event behavior is a public contract independent of whether a pivot write was necessary. Sync-to-empty remains a real detach mutation for saved models.
16. `syncRoles()` delegates to `assignRole()` for an unsaved subject, so it appends instead of replacing earlier queued roles. `removeRole()` and `revokePermissionTo()` issue no-op database deletes for unsaved subjects without reconciling their queued entries. Deferred sync/removal must update only the exact captured `PermissionRelationContext` queue, drop empty entries, and preserve other contexts. Existing assignment events still dispatch synchronously at the public method call boundary; the later saved callback emits nothing because it only commits already-reported deferred intent.
17. Assignment events are established Spatie-shaped public APIs that describe the collected assignment request, not a database change-set. Preserve their pre-branch payload types, requested IDs, no-op behavior, and saved/unsaved dispatch timing. In particular, `PermissionDetachedEvent` receives the stored Permission model or collection, and Permission sync emits only its established attached event. Do not add queries, sorting, payload conversion, or event suppression to make these events report persisted deltas. Keep `eventsEnabled()` and `hasListeners()` guards so disabled or unobserved events add no construction/dispatch overhead.
18. Native `BelongsToMany::sync()` cannot produce truthful direct-permission effect deltas when desired records carry `is_forbidden` attributes. It calls `updateExistingPivot()` for every current record with non-empty attributes, rewrites unchanged effects, and treats driver matched-row counts as `updated`. Role sync is unaffected because plain Role IDs have empty per-record attributes. Direct-permission add/sync must share one pivot-only read/compare/minimal-write synchronizer that returns PHP-computed deltas.
19. Package-owned operations that genuinely perform multiple writes must be atomic. A failed later write after an earlier delete/attach otherwise leaves a partial authorization graph. Direct-permission synchronization, Role bulk replacement, `syncModels()`, deferred multi-context flushing, multi-table record cleanup, and discovery-plus-delete subject cleanup use model-connection transactions and invalidate only after commit. Simple one-write attach/detach methods use native `attach()` / `detach()` without adding package transaction policy; applications wrap Permission calls in their own transaction when they need atomicity with model touches or other application work.
20. Pre-branch Hypervel Role synchronization already uses one detach and one bulk attach, unlike native `BelongsToMany::sync()`'s per-ID inserts. Preserve that efficient bulk replacement shape with a captured partition/team relation and wrap the two writes in one transaction. Do not add delta indexing or sorting. When `RoleDetachedEvent` is observed, one listener-gated pivot-only read captures the pre-operation current IDs required by that established event; the ordinary path performs no current-pivot read. This replaces pre-branch full Role hydration with the same query count and lower cost on the observed-event path.
21. `removeRole()` and `revokePermissionTo()` preserve their established request-oriented events. Issue the normal captured-scope detach without an event-only discovery read, then dispatch the collected Role IDs or stored Permission model/collection exactly as before. Do not branch SQL shape on listener presence. Event guards still prevent construction and dispatch when disabled or unobserved.
22. Existing-model save protection has two distinct failure modes: a persisted record whose raw original partition differs from ambient context, and an attempted mutation of the immutable partition column while the original still matches context. Combining those checks reports the raw original for both, producing a nonsensical same-value mismatch on the dirty-only path. Keep the wrong-context diagnostic record-focused, give the dirty branch a dedicated immutable-partition diagnostic naming both the raw persisted and attempted values, and test both failures independently. Casts and accessors must not participate in either diagnostic or invariant.
23. Reverse Role assignment helpers can write several morph-class batches, while `syncModels()` can commit its scope-wide delete before any replacement attach succeeds. Precompute and validate every group before writing. `assignToModels()` and `removeFromModels()` execute zero or one morph-class write directly and use one parent Role connection transaction only when several morph-class writes must succeed together. `syncModels()` always keeps one transaction around its delete and replacement inserts. Perform cache/token/relation cleanup only after successful writes. Permission authorization tables and pivots form one shared schema on the parent Role/Permission connection; split authorization-table connections are not supported.
24. `partitionFromRecord()` must report an absent or invalid raw persisted partition as a record constraint violation, not fabricate an `unresolved` ambient partition merely to reuse a context-mismatch message. The new exception covers context mismatch, immutable-column mutation, missing/invalid persisted values, and conflicting pivot input, so use the general `PermissionPartitionViolation` name rather than a mismatch-only name. Use distinct factories for each invariant. Render `null`, an empty string, and non-scalar types visibly without dumping state, and test each raw-record failure with an unchanged database row.
25. Unsaved Role and Permission queue mutations still clear wildcard runtime state before any pivot exists. Authorization reads do not consume deferred queues, so those clears cannot expose queued intent; for Roles they clear the whole ambient partition and can target the wrong partition when the queue retained another captured context. Remove every pre-save wildcard clear. The successful saved-callback flush is the sole deferred invalidation boundary: after commit it invalidates each distinct stored partition/team context exactly, while rollback retains queues and cache state unchanged.
26. The direct-permission synchronizer batches attaches and detaches but performs one `updateExistingPivot()` statement per real `is_forbidden` flip. Since the effect has only two target values, collect changed IDs into allowed and forbidden groups and execute at most two captured-scope `whereIn(...)->update(...)` statements inside the existing transaction. This bypasses native pivot timestamp/custom-class hooks intentionally because Permission assignment pivots have neither timestamps nor a custom pivot class by design; record that assumption beside the code and revisit batching if the schema contract changes. The Hypervel-owned `syncPermissionsWithForbidden()` return value remains a PHP-computed accurate change-set and preserves natural pivot-read order; assignment events remain request-oriented and do not consume this change-set.
27. Current Spatie and Hypervel `HasAssignedModels` treat `assignToModels()` on an unsaved Role as a fluent no-op, but `removeFromModels()` still issues a useless null-key delete and `syncModels()` attempts non-null/FK-invalid pivot inserts with a null Role key. Preserve the existing public API contract by adding the same top-level unsaved guard to all three methods. Do not invent a deferred reverse-assignment queue or change the established method to throw. Record this as a fixed upstream bug and recommend the same two guards back to Spatie.
28. Saved `assignRole()`, `removeRole()`, and `revokePermissionTo()` retain native plain `attach()` / `detach()` semantics. `touchIfTouching()` can perform real application-configured model touches, but making a simple pivot write and those optional touches atomic is application transaction policy rather than a Permission-owned guarantee. Role/Permission record deletion is different because the package itself owns two pivot-table cleanups: wrap both captured-partition deletes in one model-connection transaction and invalidate only after the model deletion commits. Built-in Role and Permission models do not use soft deletes; an application subclass that does must wrap `forceDelete()` in an application transaction when the record row and package cleanup must commit together because Eloquent has no `forceDeleteOrFail()`.
29. Consolidating all subject cleanup into `HasRoles` broke the supported composition of a model using `HasPermissions` without `HasRoles`, while moving Role/Permission record cleanup into `RefreshesPermissionCache` made cleanup depend on an optional cache concern instead of the public authorization traits. Preserve the Spatie-shaped ownership boundary: `HasPermissions` owns direct-subject and Role-record cleanup; `HasRoles` owns role-subject and Permission-record cleanup; `RefreshesPermissionCache` owns saved/deleted catalog invalidation only. Each subject trait discovers and deletes its own table. This costs two cold-path discovery reads for a full `HasRoles` subject instead of one UNION, but it preserves every public trait composition and avoids shared deletion machinery.
30. Hypervel's per-model assignment cache includes team in its identity. Hard deletion removes a subject's rows across every team, so invalidating only the ambient team leaves stale entries even when row partitioning is disabled. Each owning trait must discover exactly the enabled cache-key dimensions in its table: partition and team, partition only, team only, or none. A partition assignment-token bump could invalidate all teams without discovery, but would invalidate every subject in that partition—or the whole application when unpartitioned. Reject that broad invalidation for a single-subject deletion. Use one indexed discovery read per owning table on this cold path and forget only the affected subject identities.
31. The supported-database integration schema exposed two test-infrastructure defects. Its generated `model_has_permissions_workspace_id_model_type_model_test_id_index` identifier is 65 characters, while MySQL and MariaDB allow 64; the shared SQLite-backed partition fixture contains the same latent defect. Give both subject lookup indexes stable explicit names in both fixtures and in application schema guidance. The failed setup also proved that Hypervel's authoritative global cleanup was registered only for PHPUnit's `Test\Finished`, which PHPUnit does not emit when setup fails before `wasPrepared` becomes true. Keep the existing `Finished` cleanup timing for prepared tests, track one pending test from `PreparationStarted`, clean an unprepared predecessor before the next test captures globals, and add an `ExecutionFinished` backstop for the final test in a worker. This is framework-wide cleanup reliability, not a Permission-local reset. PHPUnit restores its own error and exception handlers independently; the risky handler reports from this failure disappear when the invalid index no longer aborts setup.
32. Tests for the cleanup registry called `AfterEachTestCleanup::forgetCallbacks()` in `tearDown()`, which also removed suite-level app and package callbacks discovered once during extension bootstrap. Exercise the all-callback reset against an isolated test subclass, give temporary callbacks collision-resistant test-class names, and remove only those names through `AfterEachTestCleanup::forget()`. Worker-lifetime test-state registrations must survive every framework test that touches the registry.

### Upstream references checked

- Spatie Laravel Permission main repository and current Role, Permission, registrar, and trait implementations: <https://github.com/spatie/laravel-permission>
- Laravel 12.x `BelongsToMany`, `MorphToMany`, and `InteractsWithPivotTable`: <https://github.com/laravel/framework/tree/12.x/src/Illuminate/Database/Eloquent/Relations>

The feature must remain an internal Hypervel adaptation. Existing Spatie/Laravel relation return types, assignment method signatures, command names, events, contracts, and Gate integration remain unchanged.

## Current Correctness Gaps

### Cache namespacing is not row isolation

`PermissionRegistrar::resolveCacheKeyUsing()` only changes cache keys. It does not constrain:

- Role or Permission model queries;
- pivot reads or writes;
- `role()` / `permission()` scopes;
- reverse assignment helpers;
- deletes and restores;
- commands and seeders;
- loaded relation reuse.

Every current use and test of the resolver treats it as contextual database isolation. That is security-misleading. Keeping it as an independent dimension would also make correct record-derived invalidation impossible because a changed database record cannot enumerate arbitrary cache-only scopes.

Decision: remove `resolveCacheKeyUsing()`, `$cacheKeyResolver`, `cacheKeyScopeSegment()`, and their tests/docs completely. Replace their intended use with real row partitioning. Do not leave a deprecated wrapper or compatibility alias.

### A model global scope is necessary but insufficient

A partition global scope correctly protects normal Role and Permission builders, eager loads, relation-existence queries, and catalog queries. It does not protect existing model instances because Eloquent intentionally uses unscoped base queries for instance writes and restoration.

Decision: combine a named global scope with base-model trait overrides for insert, save/delete keys, select keys, and queued restoration.

### Native pivot invariants need two narrow overrides

`withPivotValue()` does almost all required work and should be reused. The only unsafe native semantics are:

- caller attach attributes override base pivot values;
- caller pivot updates may attempt to modify the invariant column.

Decision: internal relation subclasses override only `formatAttachRecord()` and `updateExistingPivot()`. They do not reimplement Laravel's pivot engine.

### Loaded relations can cross context boundaries

`getCachedRoles()` and `getCachedDirectPermissions()` trust any already-loaded relation. Changing partition or team on the same model instance can therefore reuse rows loaded under the old context. The current Teams documentation tells applications to manually unset relations, which is easy to miss and should not remain necessary.

Decision: package-owned relations record provenance for lazy and eager loads, including empty eager loads. Permission helpers trust a loaded relation only when the exact collection object and all dimensions that constrained that relation still match.

### Unsaved assignment queues must not depend on save-time context

`assignRole()` and `givePermissionTo()` accept assignments before a subject has been inserted. Those assignments are deferred until the model's `saved` callback, but the partition and team are part of the assignment itself and may differ from the ambient context at save time. Re-resolving ambient context in the callback both breaks valid global subjects and makes a missing save-time context fail after the subject insert.

Decision: store the immutable `PermissionRelationContext` captured at the assignment call with every queued entry. Group and deduplicate deferred Role and Permission writes by a collision-safe identity derived from `PermissionPartition::encodeCacheSegment()`. Flush all queued Role and Permission batches inside one transaction on the saved model's connection, using only the stored contexts. Clear the queues after commit and then perform one exact cache invalidation per distinct context. If any batch fails, the transaction rolls back every deferred pivot write and the queues remain intact for a clean retry.

This queue is in-process deferred work on one unsaved Eloquent instance. It is separate from Hypervel queue jobs and Context payload propagation; unsaved models with pending assignments are not serialized as queue-job model identifiers.

### Empty pure-add calls are validated no-ops

An empty resolved input to `assignRole()`, `givePermissionTo()`, or `giveForbiddenTo()` does not describe an authorization mutation. Queuing an inert entry, writing a pivot, or invalidating cache for it is wrong. Its established request-oriented attach event still dispatches when enabled and observed, including for an empty collected request.

Decision: resolve the relation context and collect/validate supplied models first, then return immediately when the collected ID list is empty. This single placement covers zero arguments and inputs filtered to empty while preserving fail-closed subject validation: an empty add on a partition-bearing subject from A under context B still throws `PermissionPartitionViolation`. Do not place a pre-context zero-argument shortcut before validation. Do not apply the guard to `syncRoles([])`, `syncPermissions([])`, or `syncPermissionsWithForbidden([], [])`; on a saved model, those calls intentionally remove existing assignments and invalidate their caches.

### Deferred queues preserve context; assignment events preserve their public contract

An unsaved subject has no assignment edge yet, but the package's established assignment events report the public method request rather than a later database delta. Queue operations therefore update intended deferred state and dispatch their existing events synchronously at the method call boundary:

- `syncRoles()` replaces queued roles only in the captured partition/team context;
- `removeRole()` removes matching queued roles only from that context;
- `revokePermissionTo()` removes matching queued permissions from both allowed and forbidden batches only in that context;
- entries that become empty are removed;
- other queued partition/team contexts remain unchanged;
- unsaved add, sync, remove, and revoke operations retain their pre-branch requested-input event behavior;
- the saved-callback flush emits no second event because the deferred request was already reported synchronously in its original application context.

For saved subjects, events also retain the pre-branch request-oriented contract. `RoleAttachedEvent`, `RoleDetachedEvent`, and `PermissionAttachedEvent` receive collected requested ID arrays, including already-satisfied or empty requests. `PermissionDetachedEvent` receives the stored Permission model or collection returned by `getStoredPermission()`. Permission synchronization emits only its established attached event; it does not invent a detached event from the internal change-set. Invalidate changed cache state before dispatch so listeners observe current authorization data, but do not invalidate an already-correct cache solely because a no-op request still emits its public event.

Saved Role synchronization uses one captured relation to detach the old set and bulk-attach the requested replacement inside one transaction. This preserves pre-branch Hypervel's efficient bulk write shape while preventing a failed attach from leaving all Roles removed. It does not compute deltas. Only an observed `RoleDetachedEvent` triggers one pre-operation pivot-only ID read because its established payload is the current set; remove/revoke events need no such read because their payload is the already-known request. Saved direct-permission add/sync uses the transactional pivot-only effect-aware synchronizer because the `is_forbidden` edge state requires real comparison and minimal updates.

The Role sync transaction is a deliberate correctness improvement, not a restoration of pre-branch transaction behavior. Both Role sync events dispatch after commit. Their payloads and order remain unchanged, but a detached-event listener that queries the database sees the final replacement graph instead of the pre-branch mid-sync empty graph. Post-commit visibility is the correct contract for the new atomic operation.

Detach and revoke issue the same blind captured-scope delete regardless of listener presence. Event listeners do not change SQL shape. Dispatch the collected Role IDs or stored Permission value after the mutation path using the existing guarded event helpers.

The direct-permission synchronizer's `attached` and effect `updated` return values preserve caller order. Its detached return values preserve the natural pivot-read order, matching the prior native relation-sync behavior as closely as the custom effect-aware implementation permits. Do not sort them or expose any of these internal deltas through assignment events.

The direct-permission synchronizer is also the single implementation for `givePermissionTo()` and `giveForbiddenTo()` with detaching disabled. Its pivot-only read replaces the existing full Permission-model join/hydration read. Narrow non-detaching reads to requested IDs; sync reads the full captured partition/team pivot scope to compute removals. Within one model-connection transaction, batch allowed/forbidden inserts, group real effect flips by their target boolean into at most two scoped updates, touch once, and return driver-independent PHP-computed deltas. One shared `permissionEffectIsForbidden()` normalizes hydrated and raw pivot effects; comment that it relies on framework connections returning native booleans/0-1 integers, and protect that assumption with supported-database integration tests. The raw effect updates rely on the package's timestamp-free, non-custom Permission assignment pivot schema; if that schema contract changes, the batching path must add the corresponding timestamp/custom-pivot behavior.

Keep the collision-safe ID indexing, raw-pivot normalization, and optional-ID-constrained pivot-only read directly with the permission synchronizer. Desired and current Permission IDs both normalize then pass through `PermissionPartition::encodeCacheSegment()`, so driver-hydrated string `'5'` matches desired integer `5` while carried write/return IDs retain the proper key type. Do not retain a generic assignment synchronization engine after the Role synchronizer is removed.

### Subject deletion needs an explicit identity invariant

Permission can discover assignments for `(model_type, model_id)` across partitions, but it cannot determine whether the same pair represents unrelated local records whose IDs collide.

Decision: partition-enabled schemas must guarantee that `(model_type, model_id)` is a globally stable subject identity across the permission dataset. Native UUID/ULID morph IDs are the recommended design. Central integer IDs are valid if they are globally unique. Reused per-partition integer IDs are not valid for a shared partitioned Permission schema and must be migrated before opt-in.

This makes cross-partition hard-delete cleanup deterministic and prevents deleting another subject's assignments.

## Final Public API

### Registration

Use the repository's `resolve...Using` convention for Hypervel-owned resolver registration:

```php
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Context;

public function register(): void
{
    PermissionRegistrar::resolvePartitionUsing(
        column: 'workspace_id',
        resolver: fn (): int|string|null => Context::get('workspace_id'),
    );
}
```

`partitionUsing()` was provisional. `resolvePartitionUsing()` is the final name because the API registers a value resolver and `AGENTS.md` requires that naming pattern.

Use `Context`, including hidden context where appropriate, when the value must propagate to queued jobs. Hypervel captures Context across the queue boundary and hydrates it before queued model restoration and job execution.

### Registration rules

```php
/**
 * Configure the permission row partition resolver.
 *
 * Boot-only. The column and callback persist in static properties for the
 * worker lifetime and affect every subsequent permission operation.
 *
 * @param Closure(): (int|string|null) $resolver
 */
public static function resolvePartitionUsing(string $column, Closure $resolver): void;
```

- Register exactly once in an application service provider's `register()` method, before any provider resolves `PermissionRegistrar` or the Gate. Permission's provider binds the registrar lazily, so normal provider registration order satisfies this; an unusual provider that resolves the Gate/registrar during `register()` must run after the partition registration provider.
- A second registration throws `PermissionPartitionAlreadyConfigured`.
- Registration after the registrar has initialized throws the same configuration exception rather than silently changing worker behavior after unpartitioned cache/query use.
- `$column` must be a non-empty simple SQL identifier matching `/^[A-Za-z_][A-Za-z0-9_]*$/D`. Qualified names and expressions are rejected.
- Resolver results may be `int`, non-empty `string`, or `null`.
- The application must return one stable canonical representation for the same partition throughout its lifetime (for example, lowercase canonical UUID text rather than alternating UUID case/forms). Permission intentionally treats different non-equivalent strings as different SQL/cache identities.
- `null` and `''` mean unresolved and throw `PermissionPartitionNotResolved` whenever an operation requires the partition.
- `0` and `'0'` are valid partition values.
- Booleans, floats, arrays, objects, and resources throw `UnexpectedValueException` with the returned type.
- `PermissionRegistrar::flushState()` clears all registration state and the initialized flag for authoritative test cleanup.
- Do not add config-file closure support. Config is worker-lifetime state and is the wrong place for request/job context resolution.

### No ambient override API

Do not add `forPartition()`, `setPartition()`, `withoutPartition()`, a global reset token, or generic command options. Applications own their partition domain and establish context before calling the normal Permission API.

### Laravel/Spatie APIs remain unchanged

Keep all existing public relation return types and assignment APIs:

```php
public function roles(): BelongsToMany;
public function permissions(): BelongsToMany;
public function users(): BelongsToMany;
public function teams(): BelongsToMany;
```

Keep event constructor shapes and runtime payload contracts unchanged. Do not append a partition argument to Spatie-shaped public events or convert `PermissionDetachedEvent`'s stored Permission payload to IDs. Assignment events report collected method inputs at the original public call boundary for saved and unsaved subjects. The later saved-callback flush does not emit a duplicate event. Queued jobs/listeners use Hypervel Context propagation, and serialized Role/Permission models restore through the partition-aware restoration query.

## New Internal Types

### `PermissionPartition`

Add `src/permission/src/Support/PermissionPartition.php` as an immutable resolved value object:

```php
final readonly class PermissionPartition
{
    public function __construct(
        public string $column,
        public int|string $value,
    ) {
    }

    public function matches(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && (string) $value === (string) $this->value;
    }

    public function cacheSegment(): string
    {
        return self::encodeCacheSegment($this->column)
            . ':' . self::encodeCacheSegment($this->value);
    }

    public static function encodeCacheSegment(int|string|null $value): string
    {
        $value = $value === null ? 'n:' : 'v:' . (string) $value;

        return strlen($value) . ':' . $value;
    }
}
```

String-normalized comparison is deliberate: database drivers commonly hydrate integer identifiers as strings, while values such as `'01'` remain distinct from `'1'`.

Length-prefixed segments prevent collisions when values contain ordinary separators. The raw canonical partition remains visible in logical cache keys; do not hash it inside Permission.

### Exceptions

Add focused exceptions under `src/permission/src/Exceptions/`:

- `PermissionPartitionAlreadyConfigured extends LogicException`
- `PermissionPartitionNotResolved extends RuntimeException`
- `PermissionPartitionViolation extends LogicException`
- `PermissionPartitionModelNotSupported extends LogicException`

Each exposes a named constructor and a message containing the configured column and relevant model/value without dumping unrelated context.

Representative messages:

```text
Permission partitioning has already been configured or the registrar has already initialized.
Permission partition [workspace_id] could not be resolved; no unpartitioned fallback is allowed.
Model [App\Models\Role] belongs to partition [A], but the current permission partition is [B].
Partitioned Permission model [App\Models\Role] must extend [Hypervel\Permission\Models\Role].
```

### `PermissionRelationContext`

Add `src/permission/src/Support/PermissionRelationContext.php` as an immutable snapshot held by a relation:

```php
final readonly class PermissionRelationContext
{
    public function __construct(
        public ?PermissionPartition $partition,
        public bool $teamScoped,
        public int|string|null $team,
    ) {
    }
}
```

Only relations that actually apply a team predicate are team-scoped. Catalog `Permission::roles()`, `Role::permissions()`, reverse `users()`, and `teams()` relations must not be invalidated merely because the ambient team changed when their SQL did not use the team.

## Registrar Design

### Static configuration, not static request state

Add bounded worker-lifetime configuration only:

```php
protected static ?string $partitionColumn = null;

/** @var null|Closure(): (int|string|null) */
protected static ?Closure $partitionResolver = null;

protected static bool $initialized = false;
```

Never store a resolved partition value in a static property or singleton property. Resolve it into a short-lived `PermissionPartition` for each query construction or mutation boundary.

The singleton registrar may own a `WeakMap` relation-provenance registry because its keys are model objects, entries are weak, and no request value is used as a singleton-wide current value.

### Resolution methods

Add:

```php
public static function partitioningEnabled(): bool;

public function resolvePartition(): ?PermissionPartition;

public function partitionFromRecord(Model $model): PermissionPartition;

public function ensureModelMatchesPartition(Model $model, PermissionPartition $partition): void;
```

`resolvePartition()` returns `null` only when the feature is disabled. Once configured, unresolved context always throws.

`partitionFromRecord()` reads the configured column from persisted model attributes and never trusts mutable ambient context. Missing, empty, or non-scalar record values throw `PermissionPartitionViolation::forMissingRecordPartition()` because partition-enabled Role and Permission rows must contain a valid non-null scalar. The diagnostic names the model and column and renders `null`, an empty string, or the invalid raw type directly; it never fabricates or resolves ambient context.

For a newly inserted model, Eloquent dispatches `saved` before synchronizing original attributes. Use this original-first raw algorithm so create invalidation works without weakening existing-record safety or changing Eloquent dirty tracking:

```php
$original = $model->getRawOriginal();

if (array_key_exists($column, $original)) {
    $value = $original[$column];
} elseif ($model->wasRecentlyCreated) {
    $attributes = $model->getAttributes();
    $value = array_key_exists($column, $attributes) ? $attributes[$column] : null;
} else {
    $value = null;
}
```

Both branches deliberately read raw arrays. `HasPermissionPartition::getAttributesForInsert()` writes the resolver scalar directly into raw attributes immediately before SQL, so the fallback exactly matches the inserted value and the cache identity already built from the resolved partition. Calling `getAttribute()` here would allow a cast or accessor to derive a different cache identity and leave stale entries behind.

The original branch must precede `wasRecentlyCreated`: that flag remains true for the lifetime of the newly created instance, including later saves after `syncOriginal()` has populated the authoritative original partition. A narrowed existing model has `wasRecentlyCreated === false`, so an absent original column still fails closed. Do not pre-seed original attributes during insertion; doing so would corrupt Eloquent's dirty/change semantics for other lifecycle listeners.

### Model validation

Keep current interface validation for unpartitioned applications to preserve the Spatie/Laravel customization API.

When partitioning is enabled, additionally require:

```php
is_a($this->roleClass, Models\Role::class, true);
is_a($this->permissionClass, Models\Permission::class, true);
```

This opt-in requirement guarantees that custom models inherit the package's partition global scope and unscoped-write protections. Update both `initializeCache()` and `setRoleClass()` / `setPermissionClass()` validation so a runtime test override cannot install an unsafe class.

### Cache identities

Remove all custom cache-key resolver methods and build every relevant key from the resolved partition:

```php
protected function partitionedCacheKey(
    string $key,
    ?PermissionPartition $partition = null,
): string {
    if (! static::partitioningEnabled()) {
        return $key;
    }

    $partition ??= $this->resolvePartition();

    return $key . ':partition:' . $partition->cacheSegment();
}
```

Use a single collision-safe segment helper for every variable model-cache component, including morph class, model key, and team. Do not retain the current ambiguous raw `implode(':', ...)` construction.

Logical identities become:

```text
{catalog}:partition:{column-len}:{column}:{value-len}:{value}
{token}:partition:{...}
{model-role-prefix}:partition:{...}:{token}:{morph}:{id}:{team}
{model-permission-prefix}:partition:{...}:{token}:{morph}:{id}:{team}
```

The partition applies to:

- serialized permission/role catalog;
- model role assignment cache;
- model direct-permission assignment cache;
- assignment namespace token;
- hydrated catalog map in `CoroutineContext`;
- via-role permission memo;
- wildcard index;
- loaded-relation provenance identity.

The catalog remains guard-inclusive as it is today. Guard is an authorization lookup dimension, not a separate catalog cache.

### Partition-specific invalidation

Refactor registrar invalidation into ambient and explicit-record paths:

```php
public function forgetCachedPermissions(): bool
{
    return $this->forgetCachedPermissionsFor($this->resolvePartition());
}

public function forgetCachedPermissionsFor(?PermissionPartition $partition): bool;

public function forgetModelAssignmentCacheFor(
    Model $model,
    ?PermissionPartition $partition,
    int|string|null $team,
): void;

public function forgetModelAssignmentCacheForIdentity(
    string $morphType,
    int|string $modelKey,
    ?PermissionPartition $partition,
    int|string|null $team,
): void;

public function bumpModelAssignmentCacheTokenFor(
    ?PermissionPartition $partition,
): string;
```

When partitioning is disabled, `null` keeps current behavior.

The model overload delegates to the identity overload using `getMorphClass()`. `HasAssignedModels` already groups reverse-assignment inputs by model class and ID; for `assignToModels()` and `removeFromModels()`, it must normalize that class through the related model's `getMorphClass()` and call the identity overload for every affected raw ID instead of querying subject tables. This matters when an application uses a morph map: PHP class names and stored/cache morph types are not interchangeable. Subject hard-deletion cleanup uses the same canonical morph type for every discovered partition/team tuple.

`syncModels()` is the deliberate exception to exact ordinary assignment invalidation. It deletes every existing subject edge for a Role, including subjects absent from the replacement input. Discovering all removed identities would add a query. Preserve its current zero-added-query shape and bump only the captured partition's assignment token after the write. This invalidates assignment entries across that partition, never another partition. Ambient `bumpModelAssignmentCacheToken()` delegates to the explicit-partition overload; catalog mutations use the same explicit overload.

Role/Permission model lifecycle invalidation calls `partitionFromRecord($model)`. Subject assignment mutations use the `PermissionPartition` captured when the relation/mutation is built. Do not re-resolve after SQL before invalidating.

Catalog invalidation for one partition:

1. removes only that partition's shared catalog key;
2. writes a new assignment token only for that partition;
3. removes or naturally bypasses only matching coroutine-local catalog, wildcard, and via-role entries.

Other partitions' shared cache keys and tokens remain unchanged.

### Relation provenance registry

Initialize a registrar-owned `WeakMap<Model, array<string, ...>>`. Store:

- the relation name;
- a `WeakReference` to the exact loaded `Collection` object;
- the captured `PermissionRelationContext`.

Add methods equivalent to:

```php
public function markLoadedRelation(
    Model $model,
    string $relation,
    Collection $collection,
    PermissionRelationContext $context,
): void;

public function loadedRelationIsCurrent(Model $model, string $relation): bool;

public function forgetLoadedRelationProvenance(Model $model, ?string $relation = null): void;
```

`loadedRelationIsCurrent()` must verify:

- the model still has the relation loaded;
- the stored weak reference resolves to the exact currently attached collection;
- the current resolved partition matches the captured partition;
- the current team matches when and only when the relation SQL was team-scoped.

When neither partitioning nor teams are active, manually loaded relations retain existing behavior and need no marker.

This provenance protects context correctness, not arbitrary cross-coroutine mutation of one Eloquent object. Like all mutable Eloquent state, one model instance and its `relations` array belong to one coroutine. Different coroutines may represent the same database subject, but each must hydrate its own model instance. Permission makes resolver/cache/provenance services coroutine-safe; it does not make a single shared mutable model object coroutine-safe.

## Role And Permission Model Protection

### Named global scope

Add `src/permission/src/Scopes/PermissionPartitionScope.php` implementing Eloquent `Scope`:

```php
public function apply(Builder $builder, Model $model): void
{
    $partition = Container::getInstance()
        ->make(PermissionRegistrar::class)
        ->resolvePartition();

    if ($partition) {
        $builder->where(
            $builder->qualifyColumn($partition->column),
            $partition->value,
        );
    }
}
```

The scope object is stateless. It resolves context when each ordinary query is built/applied, so model boot cannot capture one request's partition.

### Base-model trait

Add `src/permission/src/Traits/HasPermissionPartition.php` and use it in both built-in base models.

The trait must:

1. register `PermissionPartitionScope`;
2. populate or validate the partition before every insert, including `saveQuietly()`;
3. revalidate and re-force the partition after `creating` listeners and immediately before Eloquent snapshots the insert attributes;
4. constrain and validate instance save/delete keys;
5. constrain and validate instance refresh/fresh keys;
6. constrain queued model restoration even though other global scopes remain intentionally skipped;
7. prohibit changing the partition column on an existing record.

Representative overrides:

```php
protected function performInsert(Builder $query): bool
{
    $partition = $this->permissionRegistrar()->resolvePartition();

    if ($partition) {
        if (array_key_exists($partition->column, $this->attributes)) {
            $this->permissionRegistrar()->ensureModelMatchesPartition($this, $partition);
        }

        $this->setAttribute($partition->column, $partition->value);
    }

    return parent::performInsert($query);
}
```

`Model::performInsert()` fires `creating` after the first assignment and only then calls `getAttributesForInsert()`. Therefore the trait must also make the final pre-SQL snapshot authoritative:

```php
protected function getAttributesForInsert(): array
{
    $partition = $this->permissionRegistrar()->resolvePartition();

    if ($partition) {
        $this->permissionRegistrar()->ensureModelMatchesPartition($this, $partition);
        $this->attributes[$partition->column] = $partition->value;
    }

    return parent::getAttributesForInsert();
}
```

This second check prevents a `creating` listener, mutator, `forceFill()`, or raw-attribute replacement from changing the invariant between the initial check and the insert. It deliberately writes the canonical scalar directly after validation so an application mutator cannot transform the partition value.

```php
protected function setKeysForSaveQuery(Builder $query): Builder
{
    $query = parent::setKeysForSaveQuery($query);
    $partition = $this->permissionRegistrar()->resolvePartition();

    if (! $partition) {
        return $query;
    }

    $original = $this->getRawOriginal($partition->column);

    if (! $partition->matches($original)) {
        throw PermissionPartitionViolation::forModel($this, $partition, $original);
    }

    if ($this->isDirty($partition->column)) {
        $attributes = $this->getAttributes();

        throw PermissionPartitionViolation::forImmutablePartition(
            $this,
            $partition,
            $original,
            $attributes[$partition->column] ?? null,
        );
    }

    return $query->where(
        $this->qualifyColumn($partition->column),
        $partition->value,
    );
}
```

```php
protected function setKeysForSelectQuery(Builder $query): Builder
{
    $query = parent::setKeysForSelectQuery($query);
    $partition = $this->permissionRegistrar()->resolvePartition();

    if (! $partition) {
        return $query;
    }

    $this->permissionRegistrar()->ensureModelMatchesPartition($this, $partition);

    return $query->where(
        $this->qualifyColumn($partition->column),
        $partition->value,
    );
}
```

```php
public function newQueryForRestoration(array|int|string $ids): Builder
{
    $query = parent::newQueryForRestoration($ids);
    $partition = $this->permissionRegistrar()->resolvePartition();

    return $partition
        ? $query->where($this->qualifyColumn($partition->column), $partition->value)
        : $query;
}
```

Do not rely on creating/saving/deleting events for these security properties. Model events remain responsible for cache invalidation, not query isolation.

An existing-model `increment()` / `decrement()` reaches `setKeysForSaveQuery()` through `Model::incrementOrDecrement()`, so the partition mismatch/dirty check and explicit predicate apply. The non-existing/static form and Eloquent-builder increment/decrement paths use `newQueryWithoutRelationships()` / `toBase()`, so the named global scope supplies the current partition predicate. Add tests for all three shapes, including quiet instance variants. As with any bulk update, a static/builder call must not include the partition column in its `$extra` payload.

A narrowed `select()` may deliberately omit the partition column. Such a Role or Permission can read its selected scalar columns, but building its partition-scoped relations or performing any later instance save, refresh-sensitive lifecycle work, or delete must fail closed because neither relation construction nor `partitionFromRecord()` can derive a trustworthy partition. Document and test that models intended for relations or lifecycle mutation must be hydrated with their partition column; never fall back to ambient context to compensate for a narrowed record.

### Supplied model validation

Before accepting supplied Role or Permission models, validate the record attribute against the captured current partition. Apply this centrally from:

- `collectRoles()` / `getStoredRole()`;
- `collectPermissions()` / `getStoredPermission()` / `filterPermission()`;
- `scopeRole()` and `scopePermission()` conversion;
- Role/Permission relation construction;
- reverse assignment helpers.

When a subject model exposes the configured partition attribute with a non-null value, also reject assignment under a conflicting current partition. Global subject models without that attribute, or with an explicit null global value, remain assignable in multiple partitions.

Raw subject IDs cannot be validated without an extra database query and must not add one. The globally stable morph identity schema invariant and database constraints protect those paths.

## Internal Partition-Aware Relations

### Relation construction

Add package-internal helpers that construct:

- `Relations\PartitionedBelongsToMany extends BelongsToMany`
- `Relations\PartitionedMorphToMany extends MorphToMany`

Do not change Eloquent's `HasRelationships` API. Permission models/traits instantiate the internal subclasses with explicit relation names and the same keys Laravel's helpers currently derive.

At relation construction:

1. resolve `PermissionPartition` exactly once;
2. validate Role/Permission parent records and partition-bearing subject records;
3. remove `PermissionPartitionScope` from related Role/Permission builders and add an explicit related-table predicate using the captured value;
4. call `withPivot($column)` and `withPivotValue($column, $value)`;
5. retain `PermissionRelationContext` on the relation.

Eloquent eager loading calls relation methods on `newInstance()` prototypes with `exists === false` and no attributes. Parent validation must distinguish those relation-definition prototypes from records:

```php
if ($this instanceof Role || $this instanceof Permission) {
    // Eager loading defines relations on an attribute-less, non-persisted prototype.
    if ($this->exists || array_key_exists($partition->column, $attributes)) {
        $registrar->ensureModelMatchesPartition($this, $partition);
    }

    return;
}

if (array_key_exists($partition->column, $attributes)
    && $attributes[$partition->column] !== null) {
    $registrar->ensureModelMatchesPartition($this, $partition);
}
```

The asymmetry is intentional. Role and Permission are always partition-bound, so a present-null value or a narrowed persisted record fails closed. Subjects may be global, so an absent or explicit-null subject partition is valid. All comparisons use raw attributes rather than casts/accessors because the raw resolver scalar drives SQL and cache identity.

Removing the dynamic global scope from the related builder is essential. Otherwise a relation created in partition A and executed after ambient context changes to B would combine pivot A with related-table B. The explicit captured predicate keeps the entire relation internally consistent.

Representative construction:

```php
$partition = $registrar->resolvePartition();
$related = new $relatedClass;
$query = $related->newQuery();

if ($partition && ($related instanceof Role || $related instanceof Permission)) {
    $query->withoutGlobalScope(PermissionPartitionScope::class)
        ->where($related->qualifyColumn($partition->column), $partition->value);
}

$relation = new PartitionedMorphToMany(
    $query,
    $this,
    'model',
    $table,
    $foreignPivotKey,
    $relatedPivotKey,
    $this->getKeyName(),
    $related->getKeyName(),
    $relationName,
    $inverse,
    $registrar,
    $context,
);

if ($partition) {
    $relation->withPivot($partition->column)
        ->withPivotValue($partition->column, $partition->value);
}
```

Use these relations for every authorization-table relation:

- `HasRoles::roles()`
- `HasPermissions::permissions()`
- `Role::permissions()`
- `Permission::roles()`
- `Role::users()`
- `Permission::users()`
- `HasAssignedModels::relationForModel()`
- `HasRoles::teams()` because it reads `model_has_roles`.

### Shared invariant concern

Use one internal concern in both relation subclasses. Override only:

```php
protected function formatAttachRecord(
    int|string $key,
    mixed $value,
    array $attributes,
    bool $hasTimestamps,
): array {
    if (! $this->partition) {
        return parent::formatAttachRecord($key, $value, $attributes, $hasTimestamps);
    }

    [, $merged] = $this->extractAttachIdAndAttributes($key, $value, $attributes);

    $this->assertPartitionAttributes($merged);

    return array_replace(
        parent::formatAttachRecord($key, $value, $attributes, $hasTimestamps),
        [$this->partition->column => $this->partition->value],
    );
}
```

```php
public function updateExistingPivot(
    mixed $id,
    array $attributes,
    bool $touch = true,
): int {
    if (! $this->partition) {
        return parent::updateExistingPivot($id, $attributes, $touch);
    }

    $this->assertPartitionAttributes($attributes);
    unset($attributes[$this->partition->column]);

    return parent::updateExistingPivot($id, $attributes, $touch);
}
```

When partitioning is disabled, the concern delegates directly to the parent behavior.

`assertPartitionAttributes()` permits omission or an equivalent driver-normalized value and throws on conflict. The final `array_replace()` ensures the captured invariant wins even if future Laravel merge order changes.

Do not override `newPivot()`, `newPivotQuery()`, `attach()`, `detach()`, `sync()`, `toggle()`, or relation-existence methods. Native Laravel behavior plus `withPivotValue()` already covers them and preserves bulk inserts.

Manual raw pivot-table queries or direct saves of a generic Pivot model remain outside package API guarantees, just like all raw database writes. Document that they require correct partition predicates/values and explicit cache reset.

### Lazy/eager provenance hooks

Both subclasses override:

- `getResults()` to mark the returned collection for lazy loading;
- `initRelation()` to mark each initial empty eager collection;
- `match()` to mark each final matched or still-empty collection.

Mark after delegating to the parent and keep the native return values/signatures. Empty eager results must be marked so an empty result from partition A cannot be trusted in partition B.

### Relation helper consumption

Refactor specifically `HasPermissions::relationCollection()` and its cached authorization-assignment helpers:

```php
if ($model->relationLoaded($relation)
    && ! $registrar->loadedRelationIsCurrent($model, $relation)) {
    $model->unsetRelation($relation);
}

if (! $model->relationLoaded($relation)) {
    $model->load($relation);
}
```

Do not add provenance loading behavior to `PermissionRegistrar::relationCollection()` at serialization time. That helper reads relations from freshly queried current-partition catalog models; attempting to reload there could introduce per-Permission queries and break the three-query catalog invariant.

Hydrated catalog relations are rebuilt without SQL, so `PermissionRegistrar::getHydratedPermissionCollection()` must mark each cached Permission `roles` collection with a partition-only relation context immediately after `setRelation()`.

This also fixes the existing Teams requirement to manually unset `roles` and `permissions` after switching teams. Remove that stale instruction from the docs and add tests proving automatic reload.

## Query And Mutation Coverage

### Role and Permission models

The named global scope and model trait cover:

- `query`, `select`, `where`, `find`, route binding, counts, existence, aggregates;
- `create`, `findByName`, `findById`, `findOrCreate`, `createOrFirst`, `createOrRestore`;
- ordinary, quiet, soft-delete, force-delete, restore, refresh, and increment/decrement instance paths;
- relation eager loads and relationship existence;
- queued Role/Permission restoration.

Same-name Role and Permission rows are valid in different partitions because application uniqueness includes the partition.

### Pivot mutations

Partition-aware relations cover:

- `assignRole`, `removeRole`, `syncRoles`, queued role assignment;
- `givePermissionTo`, `giveForbiddenTo`, `revokePermissionTo`, `syncPermissions`, `syncPermissionsWithForbidden`, queued permission assignment;
- `assignToModels`, `removeFromModels`, `syncModels`;
- Role-Permission assignment from either model direction;
- public relation `attach`, `detach`, `toggle`, `sync`, `syncWithoutDetaching`, `syncWithPivotValues`, `updateExistingPivot`, and `...OrFail` variants.

Every operation must capture one `PermissionPartition` before it starts resolving supplied models or writing rows and use that object through validation, relation construction, SQL, and invalidation.

Saved simple forward assignment mutations use native `attach()` / `detach()` without adding a transaction around one package write and optional application-configured model touches. Applications that require those writes to share a transaction boundary wrap the Permission method in their normal connection transaction.

Reverse Role assignment helpers group and validate all supplied subject models before writing. Assign/remove execute directly for at most one morph-class write group and use one parent Role connection transaction only when several groups must succeed together. `syncModels()` always keeps one transaction around its delete and replacement inserts. Exact cache invalidation for assign/remove, the captured-partition token bump for sync, and loaded `users` cleanup happen only after successful writes. The authorization tables and their pivots are one shared Permission schema on that connection.

For assignments queued on an unsaved subject, capture and retain the full `PermissionRelationContext` at the call boundary. The `saved` callback must not resolve ambient partition or team context. It groups deferred entries by the stored context, deduplicates Role IDs within each context, preserves Permission replacement/forbidden semantics within each context, and writes all Role and Permission batches in one transaction on the subject model's connection. Queues are cleared only after commit; exact per-context cache invalidation runs after commit and is deduplicated across Role and Permission batches.

Pure-add methods skip queue, write, and invalidation after context/model validation when their collected ID list is empty, but retain the existing requested-input attach event. Sync methods retain their native empty-input detach meaning.

Unsaved sync/removal methods reconcile queued entries by `PermissionRelationContext::identity()` and dispatch the same request-oriented events as before. Saved add/remove methods also preserve their established requested payloads. Saved Role sync uses transactional captured-scope detach plus one bulk attach. Saved direct-permission add/sync uses the transactional pivot-only effect-aware synchronizer internally while its events remain request-oriented.

Deferred queue edits do not invalidate any cache before save because no persisted authorization edge has changed and authorization reads do not consume the queues. After the saved-callback transaction commits, its captured-context invalidation is the sole cache boundary; a rollback changes neither shared nor coroutine-local cache state.

### Raw package queries

Add explicit captured partition predicates to raw Query Builder paths that do not pass through relations:

- `HasAssignedModels::newPivotQueryForRole()`;
- `HasRoles::scopeTeam()` raw `whereExists` query;
- Role/Permission hard-delete pivot cleanup;
- subject assignment discovery and deletion.

Search both `src/` and `tests/` for every authorization table helper after implementation and verify each result is either:

- a partition-aware relation;
- a Role/Permission Eloquent query protected by scope;
- an explicit raw query with a bound partition;
- an intentionally cross-partition/team subject-deletion discovery query for one owning assignment table.

### Guards, forbidden permissions, and wildcards

No special query system is added for these features:

- guard matching remains inside the already partitioned catalog;
- `is_forbidden` remains an edge attribute on partitioned pivots;
- wildcard indexes use partitioned model runtime cache identities;
- via-role permission materialization uses partitioned catalog and assignment identities.

Add tests for direct and inherited allow/deny conflicts, wildcard grants/denies, and same names under multiple guards in two partitions.

## Deletion And Lifecycle Cleanup

### Preserve public trait deletion ownership

Each public trait owns the rows its API creates, matching every supported trait composition:

- `HasPermissions::bootHasPermissions()` owns ordinary subject cleanup in `model_has_permissions`, Role-record cleanup in `model_has_roles` and `role_has_permissions`, and queued direct-permission save behavior.
- `HasRoles::bootHasRoles()` owns ordinary subject cleanup in `model_has_roles` and Permission-record cleanup in `model_has_permissions` and `role_has_permissions`.
- `RefreshesPermissionCache` owns only saved/deleted Role and Permission catalog invalidation.

A model using `HasRoles` also uses `HasPermissions`, so both independent subject callbacks run once. A model using only `HasPermissions` receives direct-permission cleanup without depending on `HasRoles`. Custom unpartitioned Role and Permission contract models retain their trait-owned pivot cleanup even when they do not use `RefreshesPermissionCache`.

Soft deletes continue preserving assignments until force deletion, matching current documented semantics.

### Role and Permission deletion

Before deleting a Role or Permission:

1. derive partition from the record attribute;
2. verify ambient partition matches;
3. delete only pivot rows matching both record ID and record partition;
4. let the model delete use `setKeysForSaveQuery()` with the same partition;
5. invalidate that record partition after deletion.

The two captured-partition pivot deletes run inside one transaction on the Role/Permission model connection. Do not use ambient context alone for invalidation.

Built-in Role and Permission models are not soft deleting. If an application subclass adds `SoftDeletes`, package cleanup still waits for force deletion. Eloquent has no `forceDeleteOrFail()`, so applications that need the pivot cleanup and force-deleted model row to commit or roll back together must wrap `forceDelete()` in an application transaction.

### Subject deletion

For a hard/force deletion, each owning subject trait handles its own assignment table:

1. when partitioning and/or teams are enabled, select the distinct enabled cache-key dimensions for the subject's morph type/key from that table;
2. delete the subject's rows from that table across every discovered scope;
3. forget only that subject's matching role or direct-permission cache keys for each discovered identity;
4. clear provenance only for the relation owned by that trait.

When neither partitioning nor teams are enabled, the trait issues only its existing delete and current unscoped cache forget; no discovery query is needed. When either dimension is enabled, a `HasPermissions`-only subject performs one discovery/delete pair. A full `HasRoles` subject performs one pair for each of `model_has_roles` and `model_has_permissions`.

Representative discovery shape:

```php
$scopes = $connection->table($assignmentTable)
    ->select(...$enabledCacheDimensions)
    ->where(Config::morphKey(), $model->getKey())
    ->where('model_type', $model->getMorphClass())
    ->distinct()
    ->get();
```

These are the only intentional deletion-discovery queries. Each is an indexed read on a cold hard-delete path. Cleanup cost is one discovery query per owning assignment table plus exact cache forgets for the discovered subject identities.

Each table's discovery and delete execute in one subject-connection transaction, and that trait's cache cleanup runs only after commit. The independent public trait callbacks intentionally do not invent a cross-trait lifecycle coordinator. Callers that need both assignment tables and the subject row to commit or roll back together use `deleteOrFail()` or wrap plain `delete()` in an application transaction, matching Eloquent's existing transaction APIs.

The per-partition assignment token is not used here. Bumping it would avoid team discovery but would invalidate every subject cache in the partition, or the entire application in unpartitioned mode, for one deleted subject.

## Cache And Command Semantics

### Normal cache behavior

- Warm checks remain zero database queries.
- A cold catalog remains three queries: permissions, eager role pivots/roles, and roles.
- Assignment cache misses keep their current query counts.
- The resolver is an in-memory Context lookup and performs no query.
- Existing cache TTL behavior remains.
- Swoole stores hash the longer logical key, so applications only need to size table row count/value capacity for their workload; Permission requires no Swoole-specific branch.
- Partitioned and unpartitioned modes use identical query counts for the same synchronization operation. Saved Role sync uses the pre-branch bulk delete/insert shape in both modes. Direct-permission synchronization reads current pivots in both modes to compare `is_forbidden` effects and avoid rewriting unchanged edges; this mode-independent read is not partition overhead.

### Cache reset

`PermissionRegistrar::forgetCachedPermissions()` and `permission:cache-reset` operate only on the ambient partition when partitioning is enabled.

- Missing context throws `PermissionPartitionNotResolved`.
- No silent global clear exists.
- No hot-path global generation token is added.
- Applications that deliberately perform cross-partition bulk maintenance enumerate their own domain, establish each context, and invoke the normal reset.

Raw SQL or Eloquent bulk updates/deletes bypass model events. Documentation must require a reset in each affected established partition after such writes.

Bulk Eloquent updates remain partition-filtered by the global scope, but applications must never write the partition column itself through a bulk update. Explicit scope removal, `newQueryWithoutScopes()`, `forceDelete()` on an Eloquent builder, `toBase()` / `getQuery()`, direct Query Builder writes, truncation, and insert-from-select are deliberate low-level escape hatches and are not row-isolation APIs. The documentation must name these paths plainly: application code using one owns the partition predicate/value, database transaction, and exact per-partition cache reset. Package internals must not use any of them without the explicit coverage classified in this plan.

Name static Eloquent passthrough writes explicitly in that documentation: `Role::insert()`, `Permission::insert()`, `insertOrIgnore()`, `insertGetId()`, and `upsert()` do not instantiate models and therefore bypass `performInsert()` / `getAttributesForInsert()`. Static/builder `update()`, `increment()`, and `decrement()` receive the global-scope predicate but bypass lifecycle invalidation and must not write the partition column. These APIs retain their Laravel behavior; they are intentional application-owned bulk/raw boundaries, not package mutation APIs.

### Other commands

`permission:create-role`, `permission:create-permission`, `permission:assign-role`, and `permission:show` naturally use the ambient partition through normal model and relation paths. They do not receive generic partition options.

`permission:setup-teams` only generates a schema migration and does not resolve or enumerate data partitions.

Commands, seeders, scheduled tasks, and jobs must establish application context before using Permission. This is application orchestration, not a Permission-owned resolver. The same rule applies to boot/test-only registrar mutators such as `setRoleClass()`, `setPermissionClass()`, and `setTeamClass()` when they invalidate cache after partitioning is enabled; normal applications should configure model classes through `permission.models` before the registrar is constructed.

## Application-Owned Schema Contract

### Stock migration

Do not add a nullable or generic partition column to `src/permission/database/migrations/2025_07_02_000000_create_permission_tables.php`. Non-partitioned applications should not pay schema or index cost for an unused feature.

Do not add `partition_column` to `permission.php`. Registration is the single source of truth and avoids static config closures.

### Required opt-in schema

Applications enabling partitioning add the same non-null native column to:

- `roles`
- `permissions`
- `role_has_permissions`
- `model_has_roles`
- `model_has_permissions`

Use native UUID/ULID columns when the partition value is UUID/ULID. Do not store UUID values in generic text or JSON columns.

Example using a generic workspace partition and UUID role, permission, and subject IDs:

```php
$partition = 'workspace_id';

Schema::create('permissions', function (Blueprint $table) use ($partition): void {
    $table->uuid($partition);
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('guard_name');
    $table->timestamps();

    $table->unique([$partition, 'id']);
    $table->unique([$partition, 'name', 'guard_name']);
});

Schema::create('roles', function (Blueprint $table) use ($partition): void {
    $table->uuid($partition);
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('guard_name');
    $table->timestamps();

    $table->unique([$partition, 'id']);
    $table->unique([$partition, 'name', 'guard_name']);
});
```

The `(partition, id)` unique keys are required referenced keys for composite foreign keys even when `id` is already globally unique.

```php
Schema::create('role_has_permissions', function (Blueprint $table) use ($partition): void {
    $table->uuid($partition);
    $table->uuid('permission_id');
    $table->uuid('role_id');
    $table->boolean('is_forbidden')->default(false);

    $table->primary([$partition, 'permission_id', 'role_id']);

    $table->foreign([$partition, 'permission_id'])
        ->references([$partition, 'id'])
        ->on('permissions')
        ->cascadeOnDelete();

    $table->foreign([$partition, 'role_id'])
        ->references([$partition, 'id'])
        ->on('roles')
        ->cascadeOnDelete();
});
```

```php
Schema::create('model_has_roles', function (Blueprint $table) use ($partition): void {
    $table->uuid($partition);
    $table->uuid('role_id');
    $table->uuidMorphs('model');

    $table->primary([$partition, 'role_id', 'model_id', 'model_type']);
    $table->index(
        [$partition, 'model_type', 'model_id'],
        'model_has_roles_partition_subject_index',
    );

    $table->foreign([$partition, 'role_id'])
        ->references([$partition, 'id'])
        ->on('roles')
        ->cascadeOnDelete();
});

Schema::create('model_has_permissions', function (Blueprint $table) use ($partition): void {
    $table->uuid($partition);
    $table->uuid('permission_id');
    $table->uuidMorphs('model');
    $table->boolean('is_forbidden')->default(false);

    $table->primary([$partition, 'permission_id', 'model_id', 'model_type']);
    $table->index(
        [$partition, 'model_type', 'model_id'],
        'model_has_permissions_partition_subject_index',
    );

    $table->foreign([$partition, 'permission_id'])
        ->references([$partition, 'id'])
        ->on('permissions')
        ->cascadeOnDelete();
});
```

Applications may add a foreign key from the partition column to their own partition-owner table. Permission must not assume such a table exists.

Use explicit names for partition-leading subject indexes. The example's default identifiers fit supported engine limits, but applications may configure longer partition or morph-key columns whose generated index names exceed MySQL and MariaDB's 64-character limit.

### Teams nested inside partitions

When teams are enabled, add the team column after the partition in relevant primary/unique indexes:

```php
$table->unique([$partition, $teamKey, 'name', 'guard_name']);
$table->primary([$partition, $teamKey, 'role_id', 'model_id', 'model_type']);
$table->primary([$partition, $teamKey, 'permission_id', 'model_id', 'model_type']);
```

The SQL dimensions are:

```sql
where workspace_id = ?
  and team_id = ?
  and guard_name = ?
```

Partition is never implemented as a Permission team. An application may use partitions without teams or many teams inside one partition.

Document the existing database-specific nullable-team uniqueness caveat: MySQL/MariaDB allow multiple rows with `NULL` in a unique key. Applications requiring exactly one global-team Role per name must use a non-null sentinel or a database-appropriate normalized/generated uniqueness key. Do not pretend the portable index alone enforces `NULLS NOT DISTINCT` everywhere.

### Index order

Where Permission always filters by partition first, indexes begin with the partition column. This generally reduces scanned entries. Include `EXPLAIN`-friendly examples in docs, but do not promise a specific optimizer plan across engines.

## Documentation Changes

### `src/permission/README.md`

- Add generic row partitioning to Features.
- Replace the `resolveCacheKeyUsing()` difference bullet with `resolvePartitionUsing()` and explain that it scopes SQL, pivots, relations, cache, and invalidation.
- State that partition schemas and context are application-owned.
- Use workspace/realm terminology; mention multi-tenancy only as one example.
- Retain the Swoole/coroutine explanation.

### `src/boost/docs/permission.md`

Add a first-class `Row Partitioning` section and table-of-contents entry. Cover:

1. purpose and generic examples;
2. boot-only registration in `register()`;
3. fail-closed context behavior;
4. complete application migration examples for all five tables;
5. custom Role/Permission models extending package bases;
6. globally stable polymorphic subject IDs;
7. teams and guards as independent dimensions;
8. jobs/commands/seeders establishing Context;
9. cache identity and partition-specific automatic invalidation;
10. raw/bulk database write reset responsibilities;
11. partition-local `permission:cache-reset` behavior;
12. query-count/performance guarantees and index guidance;
13. Swoole cache capacity guidance without implying silent truncation;
14. no framework-owned partition model or context implementation.

Replace the current Caching subsection that recommends `resolveCacheKeyUsing()`. It must explicitly say cache namespacing alone is not row isolation.

Update Custom Models:

- unpartitioned custom implementations retain the existing contract rule;
- partition-enabled Role/Permission models must extend the package bases.

Update Teams to remove manual `unsetRelation()` instructions. Explain that package-loaded permission relations are reloaded automatically when their partition/team provenance no longer matches.

Update Performance to describe partition segments rather than custom cache scopes and state exact normal query counts.

Update Testing and Seeding with context establishment examples.

Update Differences From Spatie with the generic partition feature.

Also correct the existing stock-migration note that suggests changing indexes merely to reuse a Role or Permission name across guards. The shipped `['name', 'guard_name']` uniqueness already permits that. The partitioned equivalent is `[$partition, 'name', 'guard_name']` (and includes the team column for team-scoped roles); no stale contrary statement may remain.

Do not describe a framework tenancy package, tenant resolver, tenant middleware, landlord, or tenant-owned default schema. A short sentence may say “for example, multi-tenancy” after generic use cases are established.

## Test Architecture

### Global cleanup after unprepared tests

Keep `AfterEachTestSubscriber` as the cleanup owner and normal `Test\Finished` subscriber. Add `AfterEachTestPreparationStartedSubscriber` and `AfterEachTestExecutionFinishedSubscriber`, both delegating to the same owner instance created by `AfterEachTestExtension`.

The owner tracks one `cleanupPending` boolean:

- `PreparationStarted` first flushes a prior pending test, then marks the current test pending in `finally`;
- `Finished` clears the flag before running cleanup for a prepared test;
- `ExecutionFinished` clears and runs any final pending cleanup;
- clearing before cleanup gives callbacks, Mockery verification, pooled-connection cleanup, and framework resets exactly-once semantics even when one stage throws;
- the `finally` is required because PHPUnit converts exceptions from external event subscribers into runner warnings and continues the current test.

Do not emit synthetic lifecycle events inside the parent PHPUnit process when testing this state. Unit tests drive fresh owner instances directly. End-to-end tests run real child PHPUnit processes to prove setup errors, setup skips/incompletes, data-provider rows, and the last-test backstop cannot leak framework static state.

Tests that register temporary `AfterEachTestCleanup` callbacks use test-class callback names and remove only those names. Tests that exercise the all-callback reset use a subclass with its own static registry, so they cannot erase app or package callbacks registered once for the PHPUnit worker.

Update `src/boost/docs/testing.md` to direct ordinary tests to `AfterEachTestCleanup::forget($name)` when removing one temporary callback and retain the warning that `forgetCallbacks()` clears the worker's complete app/package registry.

### Shared partition fixtures

Add a justified partition-specific base test case because multiple test classes need the same registration and five-table schema:

- `tests/Permission/PartitionTestCase.php`
- `tests/Permission/Fixtures/PartitionContext.php`
- `tests/Permission/Fixtures/Models/PartitionedRole.php`
- `tests/Permission/Fixtures/Models/PartitionedPermission.php`
- `tests/Permission/Fixtures/Models/PartitionedUser.php`
- `tests/Permission/Fixtures/Models/GlobalPartitionUser.php`
- `tests/Permission/Fixtures/Models/GlobalPartitionPermissionsOnlyUser.php`
- `tests/Permission/Fixtures/Models/SoftDeletingGlobalPartitionUser.php`
- `tests/Permission/Fixtures/Models/HasPermissionsOnlyUser.php`

Use fixed UUIDs for two generic workspaces. `PartitionContext` writes through Hypervel `Context`, not a static property. Custom Role/Permission fixtures extend package bases and use native UUID primary keys.

The partition base creates all five authorization tables with composite constraints and creates globally unique UUID subject records. Do not mutate the stock package migration.

### Registration and fail-closed tests

Add `tests/Permission/PartitionRegistrationTest.php` covering:

- disabled compatibility and unchanged cache keys/queries;
- valid int, string, UUID, `0`, and `'0'` values;
- null and empty-string failure;
- invalid resolver return types;
- invalid/qualified/empty column names;
- duplicate registration;
- registration after registrar initialization;
- normal application-provider `register()` ordering succeeds before lazy registrar/Gate resolution, while deliberately resolving either first makes later registration fail clearly;
- `flushState()` clears resolver, column, and initialized state;
- partition mode rejects configured Role/Permission models that only implement contracts instead of extending bases;
- unpartitioned mode still permits the existing contract customization API.

### Model and query tests

Add `tests/Permission/PartitionModelTest.php` covering:

- same-name roles and permissions in A and B;
- normal builders return only current rows;
- `findByName`, `findById`, `findOrCreate`, create race fallback, restore, update, and delete are scoped;
- conflicting create attributes throw;
- changing partition on an existing model throws a dedicated immutability diagnostic naming the raw persisted and attempted values, while using a stale record under another partition reports the distinct raw record/context mismatch; both leave the database row untouched;
- a `creating` listener, mutator, `forceFill()`, or raw-attribute replacement cannot override the final inserted partition;
- stale A model save/delete/refresh under B throws;
- `saveQuietly`, `deleteQuietly`, `restoreQuietly`, increment/decrement, and queued restoration remain protected;
- existing, quiet, static, and builder increment/decrement SQL contains the expected partition predicate, while a conflicting existing-model `$extra` partition value throws;
- narrowed Role/Permission models missing the partition attribute fail closed before lifecycle mutation/invalidation;
- record-derived invalidation uses the record's partition;
- newly created Role/Permission invalidation uses the inserted raw partition during the `saved`-before-`syncOriginal()` window;
- creating and then updating the same instance uses its synchronized raw original on the second save even though `wasRecentlyCreated` remains true;
- partition-column casts/accessors cannot alter the raw record-derived cache identity or prevent exact catalog/token invalidation;
- model queries contain exactly one partition predicate with the expected binding.

### Relation and mutation tests

Add `tests/Permission/PartitionRelationsTest.php` covering every relation from both directions:

- subject roles and permissions;
- Role permissions/users;
- Permission roles/users;
- reverse `assignToModels`, `removeFromModels`, and `syncModels`;
- `teams()` relation;
- lazy, eager, nested eager, `whereHas`, `whereDoesntHave`, `withCount`, `has`, and query scopes;
- `attach`, keyed attach attributes, detach, toggle, sync, sync-with-pivot-values, update-existing-pivot, and `...OrFail` variants;
- bulk attach remains one insert rather than N custom-pivot saves;
- pivot payload receives the captured partition automatically;
- same supplied partition is accepted;
- conflicting supplied partition throws before SQL;
- pivot update cannot move an edge;
- stale Role/Permission models from another partition are rejected;
- partition-bearing local subject models from another partition are rejected;
- global subjects can have different assignments in A and B;
- teams nest inside partition and neither dimension replaces the other.
- unsaved global subjects retain the partition/team captured by each queued Role and Permission assignment even when saved under another or missing ambient context;
- one unsaved global subject may queue assignments in multiple partitions and teams, with Role IDs deduplicated per captured context and Permission replacement/forbidden behavior kept per captured context;
- all deferred Role and Permission batches flush atomically: a forced later-batch failure rolls back earlier batches, retains the queues, and a clean retry inserts each edge exactly once;
- deferred assignment invalidation occurs once per distinct captured context and only after a successful commit.
- deferred queue add, sync, remove, and revoke change no wildcard, catalog, or model runtime cache before save; a multi-partition queue invalidates every stored context only after commit and changes no cache state on rollback;
- empty `assignRole()`, `givePermissionTo()`, and `giveForbiddenTo()` calls on saved and unsaved subjects perform no queue, pivot write, or invalidation while retaining their requested-input attach event;
- empty pure-add calls still reject a partition-bearing subject whose record partition conflicts with the current context;
- saved `syncRoles([])`, `syncPermissions([])`, and `syncPermissionsWithForbidden([], [])` still detach existing edges and invalidate, proving the pure-add guard does not change sync semantics.
- unsaved role sync replaces only its captured context instead of appending, and unsaved role/permission removal drops matching queued IDs without touching other contexts;
- queued entries that become empty are removed;
- deferred operations dispatch their established requested-input events at the public call boundary, and the saved-callback flush emits no duplicate event even when save runs under another or missing ambient context;
- saved no-op add/remove/revoke operations preserve their established event payloads without performing cache invalidation solely for the event;
- saved Role sync uses one listener-gated pivot-only read to emit detached events for the previous set and an attached event for the requested replacement after commit, while Permission sync emits only the requested attached event;
- listener-enabled and listener-free remove/revoke operations use the same one blind delete with no discovery read;
- Role events and `PermissionAttachedEvent` receive requested ID arrays, while `PermissionDetachedEvent` retains its stored Permission model/collection payload and docblock;
- the direct-permission public change-set preserves caller order for desired-side entries and natural pivot-read order for detached entries without sorting;
- unchanged permission effects are neither rewritten nor reported as updated on any supported database;
- mixed permission effect synchronization batches all real flips into exactly two target-value updates while leaving unchanged edges unwritten;
- a permission present in both allowed and forbidden sync input deterministically resolves to forbidden;
- listeners observe invalidated, post-mutation authorization state.
- Role sync and permission synchronization roll back all earlier writes when any later batch/update fails, retain pre-operation cache state, emit no post-failure events, and retry cleanly;
- syncing many new Roles uses one bulk insert rather than one insert per Role;
- reverse assignment helpers use no outer transaction for zero/one write group, roll back every earlier morph-class batch when a later group fails, and `syncModels()` preserves the old graph when replacement fails; invalidation/token changes occur only after successful writes;
- application transactions around simple saved assign/remove/revoke operations include the Permission pivot write and any configured model touches in the application's chosen boundary;

### Loaded relation provenance tests

Add `tests/Permission/PartitionRelationProvenanceTest.php` covering:

- a loaded A role relation is not reused in B;
- a loaded A direct-permission relation is not reused in B;
- empty lazy and eager results are not reused;
- nested eager role-permission relations are marked;
- nested `with('roles.permissions')` constructs and hydrates both prototype-defined relation levels without throwing or widening their captured partition predicates;
- replacing a relation collection manually invalidates the old marker;
- switching team inside one partition reloads team-scoped relations automatically;
- partition-only catalog relations are not needlessly invalidated by a team switch;
- WeakMap entries do not retain discarded model/collection objects.

### Cache and invalidation tests

Replace the current cache-key-resolver tests in `tests/Permission/CacheTest.php` with real partition tests in `tests/Permission/PartitionCacheTest.php`:

- catalog keys differ by partition with collision-safe segments;
- model-role, model-permission, token, wildcard, and via-role identities differ;
- separator-containing values cannot collide;
- catalog payload contains only current partition rows;
- warm A data never appears in B;
- direct and via-role allow/deny caches are isolated;
- wildcard indexes are isolated;
- catalog mutation or reverse `syncModels()` in A changes only A's relevant catalog/token and leaves B cache entries/token untouched;
- exact subject assignment mutations leave both partition tokens unchanged;
- record save/delete invalidates the record partition;
- ordinary subject assignment mutation forgets only that model/partition/team;
- reverse assign/remove by raw subject ID forgets exact morph-ID/partition/team keys without loading subject rows;
- reverse `syncModels()` changes only the captured partition token and adds no discovery query;
- cache reset clears ambient partition only;
- cache reset without context throws;
- raw write documentation behavior is represented by explicit reset tests;
- bulk updates retain the partition predicate, and documented low-level scope-removal/raw-builder escape hatches are never used internally without explicit predicates;
- Swoole logical keys work through the store's hashed table keys; no Permission-level hash is added.

### Deletion tests

Add `tests/Permission/PartitionDeletionTest.php` covering:

- Role and Permission hard deletes remove only same-partition edges;
- soft deletes retain edges and force deletes clean them;
- a forced failure in the second Role/Permission record-pivot cleanup rolls back the first delete, while `deleteOrFail()` includes a normal model-row deletion in the same transaction and application transaction wrapping provides the equivalent force-delete boundary;
- `HasPermissions`-only subjects clean direct assignments without depending on `HasRoles`;
- full `HasRoles` subjects run each owning trait once and clean both assignment tables without duplicate deletes;
- global subject hard deletion discovers assignments across partitions and teams once per owning assignment table;
- unpartitioned teams-only deletion discovers exact team identities, while unpartitioned deletion without teams performs no discovery query;
- partition-only and combined partition/team deletion forget every affected non-ambient subject cache identity;
- custom unpartitioned Role and Permission contract models retain record-pivot cleanup without using `RefreshesPermissionCache`;
- `deleteOrFail()` rolls back both independent trait cleanups with the subject row;
- exact model caches for every discovered tuple are forgotten;
- unrelated subject and partition caches remain;
- no discovery query occurs only when both partitioning and teams are disabled;
- stable morph identity requirement is enforced by fixture design and documented.

### Guards, forbidden, wildcard, and teams

Extend or add focused cases so both partitions cover:

- same names under different guards;
- direct forbidden override;
- role-inherited forbidden override;
- wildcard allow and deny;
- team-global and team-specific roles inside each partition;
- `role()`, `withoutRole()`, `permission()`, `withoutPermission()`, `team()`, and `withoutTeam()` scopes.

### Commands, seeders, and jobs

Add `tests/Permission/Commands/PartitionCommandTest.php`:

- create-role, create-permission, assign-role, show, and cache-reset operate in current partition;
- no command accepts/invents a partition option;
- missing context fails closed;
- A command does not change B data/cache;
- setup-teams remains schema-only.

Add `tests/Permission/PartitionQueueContextTest.php`:

- Context is dehydrated/hydrated through the queue test path;
- a serialized partitioned Role/Permission restores only under its captured Context;
- restoration without context throws;
- restoration under a different partition cannot retrieve the model;
- a job permission check sees the dispatched partition.

### Coroutine isolation

Add `tests/Permission/PartitionCoroutineIsolationTest.php` using `parallel()` and forced interleaving:

```php
$userId = $this->user->getKey();

[$a, $b] = parallel([
    function () use ($userId): bool {
        Context::add('workspace_id', self::PARTITION_A);

        $user = GlobalPartitionUser::query()->findOrFail($userId);
        usleep(5000);

        return $user->hasRole('owner');
    },
    function () use ($userId): bool {
        Context::add('workspace_id', self::PARTITION_B);

        $user = GlobalPartitionUser::query()->findOrFail($userId);
        usleep(5000);

        return $user->hasRole('viewer');
    },
]);
```

Prove partition resolution, catalog memo, assignment memo, wildcard index, and loaded relation provenance do not use process-global current state.

Each closure must load its own subject model instance. Never share `$this->user` across the parallel closures: Eloquent's mutable `relations` array is instance state and is not safe for simultaneous mutation by two coroutines.

### Query-count and SQL-shape tests

Add `tests/Permission/Integration/PartitionQueryCountTest.php` or extend the current integration cache test with an isolated partition schema:

- unpartitioned cold catalog: 3 queries;
- partitioned cold catalog: 3 queries;
- warm checks: 0 queries in both modes;
- assignment cache miss counts remain equal;
- partitioned and unpartitioned saved sync counts remain equal; Role sync normally uses one delete plus one bulk insert and adds one pivot-only read only when `RoleDetachedEvent` is observed, while permission effect-aware sync uses one current-pivot read;
- permission effect synchronization uses zero, one, or two scoped update statements regardless of the number of real flips;
- listener-free and listener-enabled detach use the same one blind delete with no event-only pivot read;
- each partitioned SQL statement adds a bound `workspace_id = ?` predicate;
- resolver closure execution never appears in the query log;
- normal mutations do not add partition-discovery queries;
- global subject hard delete adds one discovery query per owning assignment table when partitioning and/or teams are enabled, and none when both are disabled.

### Supported database integration

Add `tests/Integration/Database/PermissionPartitionTest.php`. The existing `databases.yml` runs all files in `tests/Integration/Database` under MySQL, MariaDB, and PostgreSQL; the normal suite covers SQLite.

For every supported engine verify:

- composite `(partition, id)` referenced keys migrate;
- composite pivot foreign keys accept same-partition rows;
- cross-partition Role/Permission pivot inserts are rejected;
- same name/guard is allowed across partitions and rejected inside one partition;
- UUID partition/role/permission/morph values round-trip without text coercion bugs;
- partition-leading indexes exist with their explicit portable names and expected ordered columns;
- ordinary package relation operations succeed.

Do not weaken constraints for SQLite to make tests easier. Enable foreign keys through the normal test connection behavior.

### Public API and event tests

Extend `tests/Permission/PublicApiTest.php` to assert public relation return types remain the Laravel base classes and existing method signatures are unchanged.

Extend event tests to lock the complete pre-branch contract under unpartitioned and partitioned operations: requested Role/Permission ID arrays, stored Permission detach payloads, saved and unsaved timing, no-op/empty requests, Role sync's detach-plus-attach events, Permission sync's attached-only event, and no duplicate event from deferred saved-callback flushing. Do not change event constructors or add partition properties.

## File-By-File Implementation Order

Implement and test one file at a time in this order:

1. Add partition exceptions alphabetically.
2. Add `Support/PermissionPartition.php`.
3. Add `Support/PermissionRelationContext.php`.
4. Add `Scopes/PermissionPartitionScope.php`.
5. Add the relation invariant concern.
6. Add `Relations/PartitionedBelongsToMany.php`.
7. Add `Relations/PartitionedMorphToMany.php`.
8. Add `Traits/HasPermissionPartition.php`.
9. Refactor `PermissionRegistrar.php`: registration, validation, partition values, cache identities, targeted invalidation, provenance, and complete removal of cache-key resolver code.
10. Update `Models/Permission.php`.
11. Update `Models/Role.php`.
12. Update `Traits/RefreshesPermissionCache.php` for record-derived cache invalidation only.
13. Update `Traits/HasAssignedModels.php`.
14. Update `Traits/HasPermissions.php`; retain direct-subject and Role-record cleanup ownership.
15. Update `Traits/HasRoles.php`; preserve independent public-trait subject and record cleanup ownership.
16. Audit all commands; change only behavior/docs/tests that require ambient partition handling. Do not add partition options.
17. Update `PermissionServiceProvider.php` About output to include “Row Partitioning” when registered, without resolving the current value.
18. Add partition fixtures and the partition test base.
19. Port each test class listed above one at a time, running it immediately.
20. Delete obsolete cache-key-resolver tests and verify zero symbol references.
21. Add supported-database integration coverage.
22. Repair framework test cleanup for tests that fail before PHPUnit emits `Finished`, with direct state-machine and child-process regression coverage, isolated callback-registry tests, and targeted callback-removal guidance in `src/boost/docs/testing.md`.
23. Update `src/permission/README.md`.
24. Update `src/boost/docs/permission.md` in full, including TOC, partition section, caching, teams, custom models, commands, testing/seeding, performance, and differences.
25. Broadly audit source, tests, docs, comments, and plans for stale `resolveCacheKeyUsing`, `cacheKeyResolver`, contextual cache-isolation claims, manual relation-unset instructions, and unpartitioned raw authorization-table paths.

Do not modify the stock Permission migration or config unless implementation evidence contradicts this plan and the owner approves the change.

## Verification Commands

Run from the components repository root.

After each new/changed test class:

```shell
./vendor/bin/phpunit --no-progress tests/Permission/DeletionTest.php
./vendor/bin/phpunit --no-progress tests/Permission/TeamDeletionTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionRegistrationTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionModelTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionRelationsTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionRelationProvenanceTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionCacheTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionDeletionTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionQueueContextTest.php
./vendor/bin/phpunit --no-progress tests/Permission/PartitionCoroutineIsolationTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Commands/PartitionCommandTest.php
./vendor/bin/phpunit --no-progress tests/Permission/Integration/PartitionQueryCountTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/PermissionPartitionTest.php
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/AfterEachTestCleanupTest.php
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/AfterEachTestExtensionTest.php
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/TestStateRegistrarsTest.php
```

Then run all Permission tests:

```shell
./vendor/bin/phpunit --no-progress tests/Permission
```

Run static analysis:

```shell
./vendor/bin/phpstan analyse src/permission/src
composer analyse
```

Run stale-reference audits:

```shell
grep -RnsE "resolveCacheKeyUsing|cacheKeyResolver|cacheKeyScopeSegment" \
    src/permission/src src/permission/README.md src/boost/docs/permission.md tests/Permission
grep -RnsE "modelHasRolesTable|modelHasPermissionsTable|roleHasPermissionsTable|rolesTable|permissionsTable" src/permission/src
grep -RnsE "belongsToMany\(|morphToMany\(|morphedByMany\(" src/permission/src
```

The first search must return zero results in live Permission source, tests, README, and Boost documentation. Historical plan files may name the removed symbols while explaining why they were removed. Every authorization table/relation result from the other searches must be classified under the coverage rules above.

Run the full suite:

```shell
composer test:parallel
```

Database CI must run `tests/Integration/Database/PermissionPartitionTest.php` under:

- SQLite
- MySQL 8 and 9 jobs already defined by `databases.yml`
- MariaDB 10 and 11 jobs already defined by `databases.yml`
- PostgreSQL 17 and 18 jobs already defined by `databases.yml`.

## Final Acceptance Checklist

- [ ] No Permission query or package-owned raw authorization-table operation can omit the partition when enabled.
- [ ] No missing resolver value falls back to unpartitioned SQL, cache, command, or queued restoration.
- [ ] Existing Role/Permission instance writes and quiet operations are protected despite Eloquent's unscoped internals.
- [ ] Unsaved assignment queues retain their captured relation contexts, flush atomically without ambient re-resolution, and invalidate only committed contexts.
- [ ] Empty pure-add calls are validated no-write/no-cache operations that retain their established requested-input events, while sync-to-empty remains a real detach mutation.
- [ ] Deferred queue replacement/removal is context-exact, emits its established call-boundary event without a saved-callback duplicate, and cannot leave empty entries.
- [ ] Assignment events preserve pre-branch payload types, requested-input meaning, no-op behavior, and saved/unsaved dispatch timing.
- [ ] Direct-permission synchronization reads scoped pivots once, performs only real presence/effect writes, and returns identical exact deltas on every supported database.
- [ ] Genuine package-owned multi-write paths are transactional; simple one-write mutations retain native `attach()` / `detach()` semantics and can join an application-owned transaction.
- [ ] Role sync uses one captured-scope delete and one bulk insert in a transaction, with no delta machinery and only one listener-gated pivot read for the established detached-event payload.
- [ ] Reverse model assign/remove avoids a transaction for zero/one morph-class write and is failure-atomic across several groups; `syncModels()` remains transactional, while whole subject deletion uses `deleteOrFail()` or an application transaction when both trait cleanups and the subject row need one boundary.
- [ ] Role/Permission record pivot cleanup is failure-atomic; normal row deletion uses `deleteOrFail()` for a whole-operation boundary and soft-deleting application subclasses use an application transaction around `forceDelete()` when required.
- [ ] Related Role/Permission queries and pivot queries use the same once-captured partition.
- [ ] Native `withPivotValue()` is reused; only attach override and pivot-movement prevention are custom.
- [ ] Laravel/Spatie relation types, assignment APIs, commands, events, contracts, Gate, middleware, and Blade surfaces remain intact.
- [ ] Same-name roles/permissions work across partitions and collide inside one partition.
- [ ] Partition, team, and guard compose independently.
- [ ] Direct, inherited, forbidden, wildcard, and query-scope paths are isolated.
- [ ] Loaded relation provenance handles lazy, eager, nested, and empty collections.
- [ ] Switching teams no longer requires manual relation unsetting.
- [ ] Shared-cache and coroutine-local identities include collision-safe partition segments.
- [ ] Normal invalidation changes only the affected partition.
- [ ] Subject hard deletion uses one discovery query per owning assignment table only when partitioning and/or teams require exact identities, followed by exact per-subject cache cleanup.
- [ ] Warm checks remain zero queries; cold catalog remains three.
- [ ] Resolver lookup performs no database query.
- [ ] Default migration/config remain clean and unpartitioned.
- [ ] Application schema examples use non-null native types, partition-leading indexes, composite foreign keys, and globally stable morph IDs.
- [ ] Configurable partition subject indexes have explicit portable names in tests and documentation.
- [ ] Framework static cleanup runs exactly once for prepared tests and for tests that fail before PHPUnit emits `Finished`, including the final test in a worker.
- [ ] Cleanup-registry tests cannot remove suite-level app or package callbacks registered for the PHPUnit worker.
- [ ] `resolveCacheKeyUsing()` and every stale cache-only isolation claim are removed.
- [ ] `src/permission/README.md` is current.
- [ ] `src/boost/docs/permission.md` fully documents the feature without implying framework tenancy support.
- [ ] PHPStan, Permission tests, supported-database integration tests, and `composer test:parallel` pass.
- [ ] No obsolete code, comments, docs, tests, or compatibility shims remain.
