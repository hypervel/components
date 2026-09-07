# Permission Audit Remediation Plan

## Objective

Resolve permission audit findings 43–47 and the directly related defects exposed while tracing them. The result must preserve Spatie-style public APIs, remain safe in Hypervel's long-lived coroutine runtime, keep cache hits lock-free, and avoid broad invalidation when the affected subjects are known.

The work covers:

- transaction-safe permission catalog and assignment caching;
- one authoritative database connection for permission tables, pivot work, and cache settlement;
- correct pivot-model and transactional relation behavior when relation endpoints use different connection names;
- direct-permission hydration reuse within one coroutine;
- team filtering on every role-catalog path;
- configured custom model compatibility for both roles and permissions;
- fail-fast team selection on all supported write paths;
- exact invalidation for targeted reverse model mutations and safe generation rotation for bulk replacement and hard deletion;
- safe cache-store topology validation at the shared coordinator boundary;
- narrow assignment-generation rotation;
- custom primary-key normalization.

The Passport-related permission TODOs in `docs/todo.md` remain: Passport is not currently ported, so those test gaps are not completed by this work.

## References

- Current Hypervel permission source under `src/permission/src/`.
- Current cache coordination under `src/cache/src/ModelCacheCoordinator.php` and `src/cache/src/MemoizedStore.php`.
- Current permission documentation in `src/docs/permission.md`.
- Current package upstream under `examples/laravel-permission` in the monorepo.
- Hypervel transaction settlement under `src/database/src/DatabaseTransactionsManager.php`, `src/database/src/Concerns/ManagesTransactions.php`, and `src/foundation/src/Testing/DatabaseTransactionsManager.php`.

## Verified defects

| Finding | Defect | Required result |
|---|---|---|
| 43 | Catalog and assignment cache entries are forgotten immediately inside database transactions. A same-transaction read can publish uncommitted state; rollback then leaves false grants or revocations cached for the full TTL. A concurrent read can also republish stale committed state between immediate invalidation and commit. | Coordinate cold fills and committed invalidation per exact cache key, keep dirty transaction reads coroutine-local, and settle shared cache state only after commit. |
| 44 | Direct permission rows are memoized, but every non-loaded read rebuilds permission models and morph pivots. A single permission check can repeat this hydration. | Memoize the hydrated direct-permission collection per coroutine and invalidate it with the same exact assignment boundaries. |
| 45 | The incompatible-class and non-indexed role fallbacks omit the current-team filter. They can return a role from another team, including as the first result for `$onlyOne`. | Apply the same team predicate before result selection on every fallback path without slowing the indexed path. |
| 46 | Requesting a base role or permission class while a compatible configured subclass is active bypasses the shared catalog and queries repeatedly. Requested and configured primary-key names can also differ. | Normalize compatible class requests to the configured catalog and primary key; memoize genuinely incompatible catalogs once per coroutine and partition. |
| 47 | Team-enabled write paths accept a missing current team until a database constraint fails, and SQLite can store a null pivot. Reads already fail closed. | Throw one package exception before role/permission lookup or mutation on every supported high-level and direct relation write path. Keep reads and global roles unchanged. |

Tracing also confirmed these same-root defects:

- `HasAssignedModels::syncModels()` originally rotated the partition-wide assignment generation, but replacing that with exact invalidation introduced a concurrent phantom hole. The old-identity query runs before normalization and before the replacement transaction begins; a subject assigned after that query can be deleted by the later bulk replacement without entering the invalidation set. Moving the query into the transaction cannot prevent a new row that was absent from the query. The bulk operation therefore cannot enumerate every removed subject exactly across all supported databases.
- Deferred assignment-generation rotation originally left the committed token active inside bulk synchronization and hard deletion. A subject read after pivot mutation could publish the uncommitted assignment view under that active namespace; rollback then left the poisoned entry addressable for the full TTL. Catalog filtering can discard stale extra IDs after a committed deletion, but it cannot restore an ID missing from a rollback-poisoned assignment payload.
- Role and permission save events rotate the assignment generation even for creates, renames, and role-permission edge changes that only affect the catalog. This causes needless global invalidation.
- `ModelCacheCoordinator` sees `MemoizedStore`, not the real configured store. Unsafe stack and failover stores can therefore pass capability checks even though their value and lock operations do not share one coherence boundary.
- Permission pivot ownership changes with relation direction and pivot implementation. Default pivots write through the related builder, custom pivots use the parent connection, package transaction wrappers frequently use the subject connection, and cache settlement follows the subject model. A rollback can therefore leave committed assignments and stale cache state when the same logical database is exposed through different connection names.
- Subject cleanup runs from `deleting` on the subject connection. It can remove assignments before a delete that later fails, and it targets the wrong database when permission storage uses another connection. Queued assignments can likewise commit to permission storage before a subject transaction commits.
- The database relation layer has the same default/custom pivot split, and every `*OrFail` pivot wrapper transacts the parent connection even when the pivot query writes elsewhere.

## Design invariants

1. Cache hits remain lock-free.
2. A shared value is published only while holding the exact cache-key lock.
3. Shared invalidation uses the same exact lock as publication.
4. An open transaction never publishes its uncommitted view to shared cache.
5. Dirty transaction reads query at most once per cache key and source connection identity until another mutation changes the view.
6. Each mutation clears the current coroutine's raw and hydrated values for its affected keys before any later read.
7. Commit invalidates the shared key immediately, even when a different connection still has an open mutation for that key. Rollback removes only its own dirty marker and does not invalidate shared state.
8. Catalog changes do not rotate the assignment generation unless existing subject assignments can no longer be enumerated exactly.
9. Known subject changes invalidate exact subject keys; they do not rotate a partition-wide token.
10. Team filtering happens before `$onlyOne` selection.
11. Compatible configured model subclasses share the configured catalog. Genuinely incompatible model classes keep their own coroutine-local catalog.
12. Missing-team guards apply to writes only. Null-team reads stay empty and global roles remain valid.
13. Every permission table, pivot query, pivot model, package transaction, and assignment-cache settlement uses the configured Permission model's connection. Role and Permission models must resolve to that same connection name; subject models may use another connection.
14. Deferred subject lifecycle work captures durable scalar identity and context only; it never reads later-mutated model state.

## 1. Validate the real cache topology in `ModelCacheCoordinator`

Add a read-only `MemoizedStore::getInnerStore()` accessor for its wrapped store. `CacheManager::memo()` wraps one normal store, so no recursive unwrapping or generic store-inspection abstraction is needed.

Keep `CacheManager::forgetDriver()` internally consistent for its caller by also forgetting that coroutine's memo wrapper for the driver. The manager's store map is worker-scoped while memo wrappers are coroutine-local; replacing only the manager entry leaves the calling coroutine attached to the discarded store. Other live coroutines may still hold their existing wrappers, as documented, and do not justify a cross-coroutine registry.

At the lazy coordination boundary in `ModelCacheCoordinator::lock()`:

1. Read the repository's store.
2. If it is `MemoizedStore`, unwrap it once for topology validation.
3. Reject `StackStore` and `FailoverStore` with a clear configuration exception.
4. Retain the existing `LockProvider` capability check and verify that `lock()` returns a `RefreshableLock`.
5. Acquire the lock directly from the validated inner store. `MemoizedStore::lock()` forwards to that same store, so routing through the wrapper adds no behavior.

Stack and failover stores are unsafe here because cache values and locks are not guaranteed to use the same backend for the whole protocol. This is different from model serialization validation: do not call `ModelCacheStoreValidator`, which rejects stores that are valid for permission arrays and checks unrelated Eloquent serialization requirements.

Validation remains lazy. A cache hit must not inspect the store or acquire a lock. This also avoids resolving the permission cache at application boot, which would freeze stale configuration in a long-lived worker.

Tests must cover direct and memoized stack/failover stores, valid lock providers, invalid lock return types, and a hot hit that performs no topology or lock work.

## 2. Coordinate permission cache fills and transaction settlement

### Permission storage connection

Treat the configured Permission model's connection as the owner of all five permission tables. The shipped schema creates them together and `role_has_permissions` has foreign keys to both Role and Permission, so Role and Permission cannot live on different physical databases under the supported schema. Require their models to resolve to the same connection name as well: relation reads compile on the Role connection while pivot writes and transaction settlement use the Permission connection, so separate aliases break read-your-own-writes and cache settlement even when they point at one database.

Expose that connection once as `PermissionRegistrar::getPermissionConnection()`. Do not add a configuration key, a Role/Permission equality validator, or call-site connection inference.

Add one protected `getPivotConnection()` seam at the shared database relation boundary. Default relations return their related query connection; `EnforcesPermissionPartition` overrides it with the registrar's permission connection. Route `newPivotStatement()`, stock and custom pivot models, and every transactional pivot wrapper through that seam. Route every other package-owned raw permission-table query and transaction wrapper through the same registrar accessor. This is smaller and harder to drift than overriding each pivot primitive in the package relation.

Subject models may use another connection name. This keeps forward assignment reads and permission-owned mutation paths coherent when subjects live on another database. It does not claim that reverse relation joins or subject query scopes work across physical databases; those compile one SQL statement on the subject builder and cannot be repaired by pivot ownership. Do not add cross-database relation machinery.

Fail before Role or Permission model persistence or deletion when the actual model connection name differs from the configured Permission connection. Put the comparison on `PermissionRegistrar`, throw a named package exception, and call it first from `RefreshesPermissionCache` `saving` and `deleting` listeners before any settlement registration. This covers every eventful model mutation without adding read-path validation. Quiet methods and query-builder writes remain normal Eloquent event bypasses.

### Shared fills and invalidation

Replace `remember()`-based publication for the permission catalog and raw model-role/model-permission assignments with `ModelCacheCoordinator::fill()`:

```php
return $coordinator->fill(
    cache: $cache,
    key: $key,
    ttl: $this->cacheExpirationTime,
    read: fn () => $this->loadFromSource(...),
);
```

Do not add a caller-side cache pre-check. `fill()` owns envelope detection and the hot-hit path; a null check would both duplicate work and misread a legitimately cached null. Cold fill holds one per-key lock across source read, lease refresh, and publication. A committed invalidation acquires the same lock, so it waits for an in-flight fill and removes any value that fill published before releasing the lock. A fill that read before the commit can still publish, and a concurrent hit can observe that value before invalidation removes it. Closing that interval would require version stamps, tombstones, or locking cache hits; none justify losing lock-free hits for this narrow window.

Keep passing the permission package's memoized repository to the coordinator. `MemoizedStore::put()` clears its local memo before writing the inner store, so the first lookup after a cold fill performs one remote read and later execution-local lookups hit the memo. Do not add package-owned memo state or claim that cold fill removes that one repository read.

### Dirty transaction state

Store transaction-local cache state in `CoroutineContext`. Use opaque, package-owned keys and plain arrays; do not add transaction objects, a registry, or cleanup listeners.

Maintain two distinct maps:

```php
// Exact shared cache key => normalized mutation connection => retained settlement.
$dirtyTokens[$cacheKey][$mutationConnectionName] = $settlement;

// Exact shared cache key => normalized source identity => raw database value.
$dirtyValues[$cacheKey][$sourceIdentity] = $rawValue;
```

Normalize a connection name as `$connection->getName() ?? ''`. Resolve this source identity lazily only after a dirty-key check; clean permission reads must not instantiate the configured Permission model merely to compute an unused map key. Do not cache connection names across operations: Eloquent's default connection and custom model connection resolution may change within one coroutine. One `PermissionCacheSettlement` retains the name resolved for its own lifetime so commit and rollback use the identity that registered them.

Assignment token and source identities both use the permission connection because all assignment reads and writes use permission storage. Catalog token and source identity use the same connection: Role and Permission share permission storage under the supported schema. Do not retain a serialized connection pair or delimiter-collision machinery.

### Mutation algorithm

Compute every affected exact cache key before registering settlement so callbacks capture the assignment token used by the mutation. For every affected exact key:

1. Clear all dirty raw source values for that key.
2. Clear the related hydrated runtime memo for that key.
3. Register assignment settlement through `afterCommitOrNow()` on the permission connection. Role and Permission lifecycle mutations use their model connection after the lifecycle guard proves that it has the same normalized name as the configured Permission connection.
4. Use a callback flag to detect whether settlement ran immediately. Do not infer deferral from raw transaction depth because `RefreshDatabase` deliberately wraps the test connection.
5. If the callback ran immediately, coordinated-invalidate the key and do not retain a dirty token.
6. If deferred and no settlement exists for the exact key and normalized mutation connection, retain the typed `PermissionCacheSettlement`. It carries the connection name and any provisional assignment namespace; its existing commit callback is the sole shared-invalidation callback for that key/connection. Compare the retained object directly with `===`; do not generate a ULID or reduce it to a reusable numeric id.
7. Register an owner rollback callback on the same transaction record. It clears the key's dirty raw and hydrated values and removes only that exact retained settlement.
8. For every later deferred mutation while the owner token exists, register an idempotent rollback cleanup callback on the current transaction record. It clears the key's dirty raw and hydrated values but never removes the owner token or invalidates shared cache.

Commit settlement:

- remove only the committing connection's token;
- clear all dirty raw values for the key;
- clear related hydrated runtime values;
- coordinated-invalidate the exact shared key immediately, even when another connection token remains.

Rollback settlement:

- remove only the rolling-back connection's token;
- clear all dirty raw values for the key;
- clear related hydrated runtime values;
- do not invalidate the shared cache.

The cache key remains dirty while any connection token exists.

One owner token and commit callback per key and connection is sufficient for shared settlement because `DatabaseTransactionRecord` owns callback movement and rollback at each transaction level. A rollback cleanup is still required on every nested record that mutates the key: otherwise a savepoint rollback can leave that record's locally memoized view visible under the surviving outer token. Repeated mutations on one record may register repeated idempotent cleanup callbacks. Add one short WHY comment that this bounded rollback-only work deliberately avoids a transaction-record identity map.

The commit callback must be registered before immediate versus deferred execution is known. Detect this with one small typed settlement-state object rather than raw transaction depth: `RefreshDatabase` deliberately wraps testing connections, so `transactionLevel()` does not identify whether package settlement should run. The same retained object is the owner identity, so this adds no allocation beyond the identity already required and avoids an untyped dynamic-property bag. A later mutation with an existing owner still registers on its current record so a nested rollback can clear that record's view while the original owner retains commit responsibility.

If a transaction is abandoned without settlement, the stale token is bounded by the coroutine lifetime and safely forces uncached reads for the rest of that coroutine. Add one concise WHY comment at that branch; do not add recovery machinery.

Route the explicit public full reset through the same assignment-token rotation and catalog-invalidation settlement paths as model mutations. Normalize an explicit nullable partition once before clearing runtime state or building either shared key, so both halves of the reset target the same resolved partition. Outside a transaction it remains immediate and returns the catalog backend's invalidation result. Inside a transaction it returns `true` once registered or deduplicated against an existing owner, publishes only after commit, and is discarded on rollback. Remove the public immediate token-bump methods: they have no upstream counterpart or production consumer and would remain an unsafe way to publish an uncommitted namespace. Package mutation paths derive the permission connection from the registrar rather than the subject or relation direction. Keep the catalog-only settlement seam domain-named; do not introduce a general transaction coordinator.

### Dirty read algorithm

When an exact cache key is dirty:

1. Never read or publish through shared cache.
2. Return a previously stored raw value for the exact key and source identity when present.
3. Otherwise read the source once, store only that raw value in `CoroutineContext`, and return it.
4. Allow existing hydrated runtime memoization to reuse the model collection derived from that raw value.

Every mutation and commit/rollback settlement clears all raw source identities for the affected key, so a later read observes the current transaction view. A read from a connection with no open transaction still bypasses shared publication while another connection has a dirty token; database truth may be committed for that source, but publication before the other connection settles would make cache correctness depend on commit order.

The catalog has one source connection. A deliberately different Role connection contradicts the shipped foreign-key schema and does not justify another cache identity or coordination protocol.

### Transaction-local assignment-generation rotation

Bulk `syncModels()` cannot enumerate every affected subject, and hard deletion can temporarily hide a Role or Permission from assignment reads before its transaction settles. Both operations must leave the committed assignment namespace before exposing their uncommitted view:

1. Register bulk rotation as the first statement inside the same permission-storage transaction as the delete/attach work. Register hard-delete rotation from `deleting`, before the row disappears; gate only this rotation on the hard-delete predicate.
2. Store one provisional assignment token directly on the retained `PermissionCacheSettlement` owner for the token key and permission connection. The marker and provisional namespace therefore share one lifetime and cannot be cleared independently.
3. Make `modelAssignmentCacheToken()` look up only the settlement for the current permission connection before its normal raw `rememberForever()` path. A connection with no open rotation continues using the committed token; a data-key dirty read remains connection-agnostic.
4. Every later rotation on the same connection replaces the owner's provisional token.
5. An inner savepoint rollback installs a fresh provisional token while the exact owner marker remains. This makes cache entries written under the rolled-back namespace unreachable without retaining a per-record token stack.
6. Commit removes the owner and publishes a newly generated token that was never visible inside the transaction. A provisional namespace may contain transaction-warmed entries, so publishing it would make cache completion order capable of restoring state from an earlier database commit. Root rollback removes the owner without publishing.

Do not route the clean token through `ModelCacheCoordinator::fill()`: the token is a raw permanent namespace value, not a TTL-bound model-cache envelope. Do not add an ordered publisher or token lock; every committed token names an empty namespace, so completion order cannot expose stale warmed entries.

### Subject lifecycle ordering

Add one small registrar helper used by queued assignment flush and assignment cleanup:

- when the subject and permission connection names match, run the permission-storage mutation immediately so it remains inside the subject transaction;
- when they differ, register the mutation through `afterCommitOrNow()` on the subject connection. With no open subject transaction it runs immediately; with an open transaction it runs only after commit.

Queued assignments clear their queued state only after the permission-connection transaction succeeds. Do not register rollback cleanup: rollback drops the deferred callback, leaving the queue available when `transaction($callback, $attempts)` retries. Multiple saves may register multiple callbacks; the first successful callback flushes and clears the queue and later callbacks safely see it empty.

Keep assignment cleanup in `deleted` for ordinary subjects, Roles, and Permissions. This prevents a failed or vetoed delete from stripping a surviving model. Ordinary subjects may defer cleanup from their connection to permission storage; Role and Permission cleanup always takes the immediate same-connection branch. Keep explicit Role/Permission pivot deletion even though the shipped foreign keys cascade, because SQLite foreign-key enforcement can be disabled.

Before deletion, validate that a hard-deleted model has a key and that a Role or Permission record belongs to the current persisted partition. This listener performs no cleanup and stores no state, so a later listener may still veto deletion without changing assignments.

Register Role and Permission catalog invalidation in both `deleting` and `deleted`. The pre-delete registration is deliberately not restricted to hard deletes: it marks transactional soft or hard deletion dirty before any earlier `deleted` listener can publish the uncommitted catalog. In that same `deleting` listener, register assignment-token rotation only for hard deletes so no read after the row disappears can publish a false revocation under the committed namespace. The post-delete catalog registration remains required for non-transactional soft deletes and for different subject/permission connection names, where the pre-delete registration runs immediately before the statement. Transactional same-connection deletion deduplicates both catalog registrations to one committed invalidation. Do not add a post-delete rotation; a hard delete is transaction-wrapped, and the pre-delete registration already owns its provisional namespace.

Override `delete(): int|bool|null` once in `HasPermissions` to make each hard deletion atomic with its `deleted` cleanup. Non-existing models and ordinary soft deletes call `parent::delete()` directly. Every hard delete wraps `parent::delete()` in the subject connection transaction, including when another transaction is already open. The nested savepoint lets a caller catch a cleanup exception and continue its outer transaction without committing the row deletion or leaving PostgreSQL's transaction aborted. This restores the pre-existing deletion contract after moving cleanup to `deleted`; it is not extra defensive machinery. A veto returns `false` before cleanup and commits an empty savepoint harmlessly. Do not override `deleteOrFail()` merely to avoid its one nested savepoint. Extract the hard-delete predicate used by this override, pre-delete validation, and both cleanup listeners rather than duplicating the `isForceDeleting()` check.

A model-defined `delete()` overrides the trait method, and another consumed trait that defines `delete()` requires normal PHP trait-conflict resolution. Document that a custom delete implementation must preserve the transaction boundary. `deleteQuietly()` deliberately suppresses validation and cleanup through Laravel's event bypass and may leave morph pivots behind; do not add interception machinery for it.

At event time, decide whether a soft-deleting model requires cleanup and capture durable scalar inputs: model key, morph class, force-delete decision, partition, and team context where needed. Do not capture the mutable Model for later identity or force-delete reads. Read distinct partition/team scopes from the pivot table inside deferred cleanup because those rows remain available: assignment pivots have no subject foreign key.

Mass query-builder deletes do not fire model events and remain the normal Laravel escape hatch. Do not add interception machinery or document cleanup as guaranteed through that bypass.

### Database relation correction

Fix the underlying relation inconsistency in `InteractsWithPivotTable` and its `MorphToMany::newPivot()` override:

- add the protected `getPivotConnection()` owner described above;
- after constructing a stock or custom pivot in either `newPivot()` implementation, set its connection from that owner;
- build `newPivotStatement()` from that owner;
- make `attachOrFail`, `detachOrFail`, `toggleOrFail`, `syncOrFail`, `syncWithoutDetachingOrFail`, `syncWithPivotValuesOrFail`, and `updateExistingPivotOrFail` transact that owner.

This preserves every public signature and named argument. It makes custom pivots match default pivot reads/writes and makes the transactional wrappers atomic for pivot work. It does not promise distributed atomicity for `touchIfTouching()` when parent and related models live on other physical connections.

### Transaction test mechanics

The always-available regression uses two named SQLite connections to one file under `ParallelTesting::tempDir()`:

- create the schema once through alias A;
- resolve both aliases inside the test coroutine, after `RefreshDatabase` has rebound the testing transaction manager;
- assign a fresh production `Hypervel\Database\DatabaseTransactionsManager` to both resolved connection objects;
- mutate only through alias A while alias B reads the committed pre-transaction view;
- keep the test free of reconnects, because reconnecting asks the container for `db.transactions` and would reinstall the testing manager;
- do not add the aliases to `connectionsToTransact`, because wrapper savepoints would prevent alias A's commit from becoming visible to alias B.

The production and testing manager instances share transaction records through `CoroutineContext`; they are not isolated. The setup is safe because callback registration, commit, and rollback filter records by normalized connection name. The production manager is required for a different reason: the testing manager treats level one as settled for `RefreshDatabase`-wrapped connections, while an unwrapped alias must settle only at level zero. Using the testing manager would fire callbacks when a nested transaction releases to level one, before its outer transaction commits.

Add a PostgreSQL service-backed counterpart under `tests/Integration/Permission/Database/Postgres/` using two aliases to the same logical database. One server database and SQLite are enough: the behavior being tested is permission cache settlement and transaction-manager ordering, not driver-specific SQL syntax.

Configure the shared cache store before coroutine setup and rely on the permission test case's configured-store reset. Do not rebuild a driver after the current coroutine has memoized it: a memo wrapper is coroutine-local while the manager's resolved store map is shared.

Test real commit visibility, rollback, same-transaction rereads, stale concurrent fills, repeated same-connection mutations, nested savepoint commit and rollback, outer mutation after inner rollback, and independent connections where one commits and the other rolls back. Pin the raw-memo regression explicitly: mutate/read at L1, mutate/read at L2, roll L2 back, then prove the next read returns the surviving L1 view while the L1 token remains dirty until outer settlement.

Pin generation-namespace rollback for bulk `syncModels()` and hard deletion: read an affected subject inside each transaction, roll back, and prove a sibling coroutine sees committed assignments rather than the transaction-local cache entry. Register an earlier `deleted` listener that reads assignments to prove hard-delete rotation is active before the row disappears. Also cover repeated rotations with a read between them, an outer provisional token followed by an inner rotation and savepoint rollback, alias-specific token ownership, and two open alias rotations settling independently. A committed token must differ from every transaction provisional and must not expose entries written under any provisional namespace.

Also prove that forward and reverse permission mutation APIs, raw cleanup, transaction wrappers, cache tokens, and custom pivots all use the permission alias; a subject-alias rollback undoes or defers permission work as designed; queued work survives a retry; plain `delete()` and `deleteOrFail()` both preserve a live model and its assignments when row deletion fails; and committed subject deletion cleans its assignments. Register a later vetoing `deleting` listener both inside and outside an existing transaction and prove the Role plus both assignment tables remain unchanged. Explicitly catch a cleanup failure inside an application transaction, continue to its commit, and prove the Role plus both assignment tables remain unchanged; cover this on SQLite and PostgreSQL so the PostgreSQL aborted-transaction recovery is pinned. Also prove that rolling back the delete savepoint discards its staged cache invalidations rather than publishing them at the outer commit. Update the custom Role and Permission cleanup tests to observe zero pivots from a later `deleted` listener: those tests own cleanup and query-count behavior, not the superseded pre-delete phase. Do not use separate physical databases or imply complete cross-database relation-query support.

## 3. Narrow invalidation and assignment-generation rotation

Separate catalog invalidation from the public full reset behavior.

### Catalog-only invalidation

The following operations invalidate only the catalog cache and related coroutine runtime state:

- role or permission creation;
- role or permission updates, including renames;
- role-permission edge attachment, detachment, and synchronization;
- soft deletion, because the existing assignment cleanup hooks intentionally leave pivots intact.

These operations must not rotate the assignment generation. Existing subject assignment payloads remain addressable and valid; only the catalog's model representation changed.

### Full generation rotation

Rotate the partition's assignment generation when an uncommitted assignment read must not publish under the committed namespace, or when affected subject keys cannot be enumerated exactly:

- bulk replacement through `HasAssignedModels::syncModels()`, because concurrent inserts prevent exact enumeration of every removed subject across all supported databases;
- hard or force deletion of a Role or Permission, because a read after the row or its pivots disappear can publish a false revocation that survives rollback under the committed namespace;
- the explicit public `forgetCachedPermissions()` / cache-reset surface, settled on the permission connection.

Catalog filtering makes stale extra IDs harmless after a committed hard delete, but it cannot restore an assignment ID omitted by an uncommitted read before rollback. The provisional namespace prevents that false revocation from reaching shared cache. Accept the rare partition-wide cold fill rather than adding a second settlement mode solely to restore the old namespace after commit.

Generation-token writes use `afterCommitOrNow()` on the actual mutation connection and share the same per-token-key/per-connection deduplication rule as exact invalidations. This includes explicit resets: commit publishes the reset, while rollback leaves the prior token and shared catalog in place. When the token changes, do not enumerate or forget subject keys that are now unreachable by design.

### Exact subject invalidation

Normal subject mutations invalidate only their exact model-role and model-permission keys. They never rotate the partition-wide token.

`assignToModels()` and `removeFromModels()` know their affected subjects and keep exact invalidation. `syncModels()` performs one bulk replacement and registers partition-generation rotation inside that transaction for settlement after commit. Do not query old subject identities: a concurrent insert can occur after any portable select and still be removed by the bulk delete. Do not constrain deletion to the discovered identities; that would let concurrent assignments survive a replacement and would create an unbounded `IN` predicate. Portable `DELETE ... RETURNING` is unavailable on MySQL and MariaDB, while locking every assignment path would add hot-path coordination solely to preserve a write-side cache optimization.

Tests must prove that creates, renames, role-permission changes, and soft deletes preserve the generation token; hard/force deletes, `syncModels()`, and explicit reset rotate it; rollback never publishes a provisional token; exact subject mutations leave unrelated warm entries available; and generation rotation remains isolated to the current partition. Prove that an explicit reset settles after a root commit, is discarded by a root rollback, and is also discarded when its inner savepoint rolls back before an otherwise unchanged outer commit. The savepoint regression must inspect the shared repository payload and token directly because reset registration intentionally clears coroutine-local runtime state. Also prove a transactional soft delete cannot publish a rolled-back catalog and a same-connection hard delete performs one committed catalog invalidation despite pre/post registration. Do not add timing-based interleaving tests or production test seams for the phantom window.

## 4. Memoize hydrated direct permissions per coroutine

Add direct-permission hydration memoization parallel to the existing via-role permission memo:

- use domain-named public methods on `PermissionRegistrar`, not a public string-kind parameter or registry;
- share a small private helper only if both direct and via-role paths genuinely use it;
- memoize only the non-loaded relation path;
- key by the existing exact subject assignment identity, partition, and team runtime context;
- store the hydrated collection as-is, matching the existing via-role object-sharing behavior;
- clear it from exact assignment invalidation, catalog invalidation, and full runtime resets. Ambient team and partition changes naturally select another slot through the existing runtime key; do not clear unrelated slots merely because context changed.

Do not add a roles hydration memo without evidence. The direct permission path reconstructs permission models plus morph pivots and is the measured repeated work; the roles path is cheaper and does not justify more state.

Tests must prove one hydration for repeated `hasPermissionTo()` work in the same coroutine, refresh after mutation/invalidation, separation by team and partition, and isolation between sibling coroutines.

## 5. Make class compatibility, primary keys, and team filtering consistent

### Compatible configured classes

At the entry to both `getRoles()` and `getPermissions()`:

1. Determine whether the configured class is the requested class or is a subclass of it.
2. For compatible requests, use the configured catalog rather than querying the requested base class.
3. Normalize a primary-key filter written with the requested class's key name to the configured class's key name before indexed lookup and fallback filtering.
4. Preserve all other filters and public argument shapes.

This keeps custom configured subclasses on the shared catalog and fixes models whose requested and configured primary-key names differ. Genuinely incompatible valid classes retain their own key and query behavior.

Apply this symmetrically to roles and permissions. Permissions need the same fix and have a larger avoidable cost because their catalog eager-loads roles.

Do not manually mark eager-loaded permission-role relations. `PartitionedMorphToMany::initRelation()` and `match()` already set the relation and provenance; an extra hydration marker would duplicate existing behavior.

### Incompatible class catalogs

For genuinely incompatible model classes, query once per coroutine, class, and partition, then filter the resulting collection. Clear these class-specific catalogs with catalog and runtime invalidation. This bounds repeated query work without adding worker-lifetime state.

### Team filtering

Add one `teamScopedRoles(Collection $roles): Collection` helper and apply it to:

- the configured catalog's non-indexed fallback;
- the incompatible role-class catalog fallback.

Apply the team filter before `$onlyOne`. Keep the indexed path's existing O(1) `roleMatchesCurrentTeam()` lookup unchanged. With a null current team, only global roles match.

Tests must cover:

- compatible base-class requests against custom role and permission subclasses;
- custom primary-key names on indexed and fallback paths;
- genuinely incompatible classes querying once per coroutine/class/partition;
- cache clearing after catalog mutation;
- identical role names in global, current-team, and other-team rows;
- null-team filtering;
- complex parameter filters and `$onlyOne` ordering.

## 6. Fail fast when a team-scoped write has no selected team

Add `TeamNotSelected extends RuntimeException` under `src/permission/src/Exceptions/`. Give it a named constructor with a direct message explaining that a current team must be selected before changing team-scoped roles or permissions. This matches the package's runtime-context failure style; `BadMethodCallException` remains reserved for calling team APIs while teams are disabled.

Add one registrar guard that throws only when teams are enabled and the current team is null.

Call the guard at the top of each package mutation entry point, before role or permission lookup and before empty-input early returns:

- assign, remove, and synchronize roles;
- give, deny, revoke, and synchronize direct permissions and permission effects;
- queued permission changes and their flush path;
- reverse assign, remove, and synchronize model operations.

Place one concise comment where needed to preserve the ordering rule: missing team context must not be misreported as `RoleDoesNotExist` or `PermissionDoesNotExist`.

Retain non-persisted queued behavior, but require the selected team to be captured before queueing.

Enforce the same invariant for direct relation writes in `EnforcesPermissionPartition`:

- reject actual writes from `formatAttachRecord()` and `updateExistingPivot()` when the relation is team-scoped and no team is selected;
- override `detach()` to apply the same guard;
- rely on inherited `attach`, `sync`, and `toggle` flows through those existing primitives rather than adding another relation class.

The high-level guard must also cover raw bulk updates such as `is_denied` changes and the raw deletion inside `syncModels()`.

Do not guard reads or relation construction. A null-team read remains empty, and assigning a global `Role` whose team column is null remains valid where the operation is not a team-scoped subject write.

Tests must cover every high-level mutation family, empty sync calls, queued changes, reverse model APIs, raw permission-effect updates, and direct `attach`, `updateExistingPivot`, `detach`, `sync`, and `toggle`. Also prove reads remain fail-closed, global roles remain usable, and the same writes succeed once a team is selected.

## 7. Documentation and plan cleanup

Update only the canonical permission documentation in `src/docs/permission.md`:

- explain that team-enabled writes require a selected current team;
- show the normal selection flow before mutation;
- list `TeamNotSelected` with the permission exceptions in Laravel-style prose;
- update both the Caching and Performance statements that currently say ordinary catalog mutations advance the assignment token. Catalog mutations invalidate only that partition's catalog; hard/force Role or Permission deletion, bulk `syncModels()`, and explicit cache reset advance the assignment token.
- explain that targeted reverse assignment mutations invalidate exact subjects while bulk `syncModels()` advances only the active partition's assignment token for portable concurrency correctness;
- explain that assignment cleanup follows a successful row deletion; same-connection hard deletes wrap the row plus cleanup in one transaction, while different connection names defer cleanup until the subject transaction commits. A consuming model's own `delete()` implementation must preserve the subject transaction boundary, while `deleteQuietly()` intentionally skips validation and cleanup.
- explain that Role and Permission models must use the configured Permission model's connection name because relation reads, pivot writes, and cache settlement must share one transaction identity; subject models may use another connection, but physically separate subject/permission databases cannot support reverse joins or subject query scopes compiled as one SQL statement;
- explain that coordinated permission caching requires one lock-capable backend for values and refreshable locks, so stack and failover stores are rejected on the first cold fill.
- explain that an explicit reset inside a transaction is published after commit, discarded on rollback, and reports success once registered.

Keep the existing partition-isolation statement that changing a Role in one workspace does not clear another workspace's catalog or token; that statement remains accurate.

Do not add this correctness fix to the package README's `Differences From Laravel` section or to `src/docs/porting-from-laravel.md`. The permission package tracks Spatie rather than a Laravel framework API, and a clearer failure for invalid teamless writes does not change a normal porter decision.

Keep the README's existing undefined-cache-store difference. Upstream deliberately falls back to the `array` store for an unknown configured cache store; Hypervel deliberately fails fast, so this remains a lasting configuration contract difference.

Do not remove the Passport-related permission TODOs.

## 8. Test ownership and verification

Prefer existing test owners and avoid scattering one behavior across many new files.

| Area | Primary test location |
|---|---|
| Coordinator topology and lock-free hit | `tests/Cache/ModelCacheCoordinatorTest.php` and, only if useful for its public accessor contract, `tests/Cache/CacheMemoizedStoreTest.php` |
| Permission cache payloads, invalidation, generation tokens, runtime hydration | `tests/Permission/CacheTest.php` and existing partition cache tests |
| Coherent transaction and concurrency cases | A focused `tests/Permission/PermissionCacheTransactionTest.php` |
| PostgreSQL cross-connection settlement | `tests/Integration/Permission/Database/Postgres/PermissionCacheTransactionTest.php` |
| Compatible/incompatible model catalogs and query counts | Existing permission registrar and custom-model test files |
| Team filtering and high-level guards | Existing team role, permission, and assigned-model trait tests |
| Direct pivot write guards and relation hydration | Existing custom pivot and partition relation tests |
| Generic pivot connection and `*OrFail` transactions | Existing database belongs-to-many and custom pivot tests |
| Documentation contract | Existing documentation checks, if any; otherwise source behavior tests are authoritative |

When the coordinator introduces a presence envelope, update any test that intentionally inspects the raw catalog cache entry to assert the honest envelope shape. Do not add a production test-only accessor.

Run each changed test file immediately after editing it. Run the focused permission and cache coordinator suites after each coherent slice. The existing database runner already discovers `tests/Integration/Permission/Database/Postgres/`; no workflow change is needed.

At completion, run `composer fix` once. After it is green:

1. Re-read every changed source file and trace callers and invalidation paths.
2. Verify cache hits perform no lock or store-topology work.
3. Verify all shared publications and invalidations use one coordinator boundary.
4. Verify every dirty map is coroutine-scoped and bounded by exact keys used in the current operation.
5. Verify rollback cannot publish, forget, or rotate shared state.
6. Verify no catalog-only mutation rotates the assignment generation.
7. Verify all teamless write paths fail before lookup or database work.
8. Check Laravel/Spatie-facing signatures, named arguments, and protected extension points remain intact.
9. Remove stale helpers, superseded invalidation paths, duplicate comments, and dead tests.

## Performance checks

The implementation should improve steady-state work while adding coordination only where correctness requires it:

- hot cache reads remain one memo/shared lookup with no lock;
- cold fills add one lock but prevent dogpiles and stale publication;
- committed mutations add one exact lock and forget, while transaction-local repeated reads avoid repeated queries through the raw memo;
- direct permission checks avoid repeated model and pivot hydration;
- compatible custom classes reuse the indexed catalog;
- incompatible classes query once per coroutine and partition;
- indexed team matching remains O(1);
- targeted reverse mutations retain exact invalidation, while bulk `syncModels()` removes its old-identity query and rotates one partition token after commit for portable concurrency correctness.

Add deterministic query, lock, and cache-operation assertions where the tests already expose those boundaries. Record a small local before/after measurement for the repeated direct-permission hydration and compatible custom-class paths. Do not add timing assertions, a new benchmark harness, production counters, retry loops, or size thresholds.

Pin that repeated clean role checks do not instantiate the configured Permission model merely to derive a source identity. The identity is needed only for transaction-local dirty reads and must remain lazy.

## Expected source changes

- `src/cache/src/MemoizedStore.php`
- `src/cache/src/CacheManager.php`
- `src/cache/src/ModelCacheCoordinator.php`
- `src/permission/src/Exceptions/PermissionConnectionMismatch.php`
- `src/permission/src/Exceptions/TeamNotSelected.php`
- `src/permission/src/PermissionRegistrar.php`
- `src/permission/src/PermissionServiceProvider.php`
- `src/permission/src/Support/PermissionCacheSettlement.php`
- `src/permission/src/Traits/EnforcesPermissionPartition.php`
- `src/permission/src/Traits/RefreshesPermissionCache.php`
- `src/permission/src/Traits/HasRoles.php`
- `src/permission/src/Traits/HasPermissions.php`
- `src/permission/src/Traits/HasAssignedModels.php`
- `src/database/src/Eloquent/Relations/Concerns/InteractsWithPivotTable.php`
- `src/database/src/Eloquent/Relations/MorphToMany.php`
- `src/docs/permission.md`
- focused existing and new tests listed above

The exact file set may grow when an existing mutation owner or test owner is discovered during implementation. Any such change must preserve these invariants and be folded into this plan before implementation continues.
