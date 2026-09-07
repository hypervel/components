# Runtime Correctness Remediation Plan

Status: Complete

## Objective

Fix verified correctness failures across Database Queue, cache wrappers, unique-job payload metadata, numeric validation, and SQS FIFO dispatch. Complete optional framework-event gating across cache, prepared statements, queues, database and pool lifecycle, framework commands and integrations, and server lifecycle without changing listener-present behavior. Compile registered event wildcards once, and remove an inaccurate `RateLimited` comment while documenting its existing zero-delay difference from Laravel.

The work combines Hypervel-specific fixes with current Laravel validation fixes. Preserve Laravel APIs unless their current behavior is broken: SQS FIFO positive delays deliberately fail instead of being silently discarded. Do not add cache-coordination affinity, queue-object capability memoization, configurable rate-limit padding, local numeric parsers, or broad public capability APIs.

## Confirmed baseline

- The branch was created from `0.4` after the dependency-ownership rule was added to `AGENTS.md`.
- Current Laravel reference source and tests are in `examples/laravel/framework` on its checked-out `13.x` branch.
- Laravel validation commits [`49dd2f5793`](https://github.com/laravel/framework/commit/49dd2f5793) (#55963) and [`a92fbd56cc`](https://github.com/laravel/framework/commit/a92fbd56cc) (#61332) are not ported. The current upstream branch contains their final source and test shape.
- Laravel's memo driver has the same `flexible()` refresh-marker tautology: its opening `many()` call memoizes the marker, so the acquired-lock `get()` recheck reads the captured marker from the same memo. Report that memo-only defect upstream with a focused test and fix after the Hypervel implementation is verified and the project owner approves the external submission. Do not send Hypervel's stack, failover, raw-sentinel, or wrapper-composition machinery upstream; Laravel does not have those drivers or contracts.
- Laravel commit [`1da2839a08`](https://github.com/laravel/framework/commit/1da2839a08) memoizes `DatabaseQueue::getLockForPopping()` on the queue object. That design is unsafe in Hypervel: queue objects live for the worker lifetime while pooled physical connections, backend versions, and configuration can change. This plan fixes detection without copying that memo.
- Installed `ramsey/uuid` 4.9.3 restricts `brick/math` to `<=0.18`. Brick Math 0.20's bounded parsing API and parser hardening therefore cannot be adopted truthfully in the current dependency graph. `docs/todo.md` records the complete follow-up; no Composer alias or local parser will hide the constraint.
- Hypervel commit `6c2f5debd` replaced Laravel-shaped `event(object)` cache dispatch with `event(string, Closure)` to defer event-object construction. It successfully removed eager event objects, including batch event argument maps, but every call site still allocates a factory closure and tagged caches allocate a second wrapper closure before the listener check.
- Passive observers deliberately receive only events dispatched for another reason. They do not count toward `hasListeners()`; this contract is documented under [Passive Observers](../../src/docs/events.md#passive-observers) and pinned by OpenTelemetry's event-instrumentation tests. Guarding currently eager lifecycle events makes their behavior match that existing contract.
- The events documentation already names queue lifecycle among the framework event families protected by listener guards. Queue producers are guarded, but several queue consumer/worker events are not; completing the queue pass makes the documented behavior accurate.

## Implementation

### 1. Detect MariaDB correctly when Database Queue chooses its pop lock

#### Problem

`DatabaseQueue::getLockForPopping()` reads `ConnectionInterface::getDriverName()` and `getServerVersion()`. A `MySqlConnection` connected to MariaDB reports driver `mysql`, while `MySqlConnection::getServerVersion()` strips the `MariaDB` marker. MariaDB 10.3–10.5 is then compared with MySQL's `8.0.1` threshold and incorrectly receives `FOR UPDATE SKIP LOCKED`, although MariaDB did not support it until 10.6.

The existing test returns `5.5.5-10.6.1-MariaDB` from `getServerVersion()`, a shape the real connection method normalizes away, so it masks the defect. A numeric configured version has the same problem: configuration supplies a version, not engine identity.

#### Changes

Update `src/queue/src/DatabaseQueue.php` in this order:

1. Resolve the connection once and read its configured/live version as today.
2. If that version contains `MariaDB`, set the engine to `mariadb` and retain the current marker normalization.
3. Otherwise, when the connection is a `MySqlConnection` and `isMaria()` is true, set the engine to `mariadb` while keeping the configured or normalized version number.
4. Otherwise retain the current Vitess/PlanetScale detection and version normalization.
5. Keep the existing MySQL, MariaDB, PostgreSQL, and Vitess thresholds.

`isMaria()` is the stronger engine signal because it checks `PDO::ATTR_SERVER_VERSION` for a marker a non-MariaDB server does not emit. A configured numeric version still owns the version number. Guard the call with `instanceof MySqlConnection`; do not add `isMaria()` to the general connection contract.

Do not cache the result on `DatabaseQueue`. `pop()` reaches this method inside the transaction after the current pooled connection has been resolved. The extra `getPdo()` may repeat connection/session-state lookups but performs no steady-state SQL; the planned connection-generation capability cache in `docs/todo.md` is the correct future owner.

#### Tests

Update `tests/Queue/QueueDatabaseQueueUnitTest.php` to cover:

- MySQL immediately below and at the `8.0.1` boundary;
- a `MySqlConnection` whose real normalized version is MariaDB 10.5 and 10.6;
- the explicit `mariadb` driver path;
- numeric configured versions on both MySQL and MariaDB-backed `MySqlConnection` instances;
- configured/raw MariaDB marker normalization;
- existing PostgreSQL, Vitess, and PlanetScale behavior;
- one connection resolution per decision.

Use typed connection mocks that exercise `MySqlConnection::isMaria()` rather than making the general connection contract pretend to expose it.

### 2. Recheck `Cache::flexible()` against the lock-owning layer

#### Problem

`Repository::flexible()` reads the value and refresh marker through `manyRaw()`, then compares the captured marker with `getRaw()` after acquiring the refresh lock.

- `MemoizedStore::manyRaw()` caches the marker, so the in-lock recheck returns the request-local captured marker and becomes a tautology. Concurrent requests can each recompute after waiting for the same distributed lock.
- `StackStore::lock()` belongs to the bottom layer, but ordinary `get()` can return a stale node-local upper-layer marker. Cross-node single-flight then degrades to one refresh per node.
- A failover store may contain a stack, and a stack may itself end in another stack or failover store. A concrete wrapper list or a non-recursive bottom read leaves supported compositions broken.
- `FailoverStore::getRaw()` and `manyRaw()` unconditionally call methods absent from `Hypervel\Contracts\Cache\Repository`. Built-in repositories happen to implement them, but a contract-compliant custom repository returned by `CacheManager::extend()` fails instead of falling back to its public read API.

The opening value/marker read remains intentionally cache-layer-aware. An authoritative read after that opening decision cannot prevent the current request from serving a bounded stale upper-layer value; changing the opening path would alter the documented microcache tradeoff without restoring another invariant.

#### Internal authoritative-read seam

Add `src/contracts/src/Cache/AuthoritativeRawReadable.php` beside `RawReadable`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cache;

use UnitEnum;

/**
 * Capability interface for cache wrappers that can bypass non-authoritative read layers.
 *
 * @internal Used by cache coordination. Application code should read through
 *   the cache repository's public API.
 */
interface AuthoritativeRawReadable
{
    /**
     * Retrieve an item without serving it from a non-authoritative read layer.
     */
    public function getAuthoritativeRaw(UnitEnum|string $key): mixed;
}
```

Keep it separate from `RawReadable`. `StackStore` correctly does not implement ordinary raw reads through a repository, yet it needs a custom authoritative read that bypasses upper layers. The new interface is an internal cache-wrapper capability, not an application-facing general store API. Keep it deliberately limited to this single read method; do not grow it into a general cache-capability surface.

Implement the interface on `Repository`, `MemoizedStore`, `FailoverStore`, and `StackStore`:

- `Repository::getAuthoritativeRaw()` is public and marked `@internal` because wrappers call it on another repository. It applies `itemKey()`, preserves `getRaw()`'s retrieval/hit/miss/failure events, passes cancellation through, delegates incomplete-class handling to whichever repository owns the plain store read exactly as `getRaw()` does, and preserves `NullSentinel::VALUE`. When its store lacks the authoritative interface, it performs the normal raw read.
- Keep `getRaw()`'s normal path direct rather than routing both methods through a boolean mode helper. The authoritative path is rare, while measurement shows the shared helper would add about 16 nanoseconds to every ordinary raw read; duplicating this small control flow avoids that hot-path regression.
- `MemoizedStore` delegates directly to its inner repository's authoritative method. It bypasses only the coroutine-local memo and retains the inner repository's normal events and error handling.
- `FailoverStore` tries resolved repositories in the configured order. It calls the authoritative interface when available, then `RawReadable`, then contract `get()`. It does not pin the backend selected for the lock to the later read; failover has no operation-affinity guarantee, and adding one would require unjustified cross-operation machinery.
- `StackStore` resolves the bottom proxy's wrapped store. It recursively calls the authoritative interface when available, otherwise `RawReadable::getRaw()` or ordinary `get()`. After that recursion it casts the returned outer stack record and reads `['value'] ?? null`. Missing, scalar, and malformed records are misses; a valid null sentinel survives.
- The Stack path must not call `getOrRestoreRecord()` or write/backfill an upper layer. The later successful refresh already writes every layer, and an authoritative recheck is read-only.

Replace only the marker read inside the acquired `flexible()` lock:

```php
if ($created !== $this->getAuthoritativeRaw($markerKey)) {
    return;
}
```

The initial `manyRaw()` remains a single batched read. Plain stores still perform one normal read. Wrapper compositions still perform one backing read; recursion adds no backend round trip.

Override `getAuthoritativeRaw()` in `AnyModeTaggedCache` with the same unconditional `BadMethodCallException` used by its other read methods. Any-mode tags are write/invalidation indexes and deliberately expose no read surface; inheriting the new method from `Repository` would otherwise bypass that invariant. Update `flexibleNullable()`'s support-matrix docblock to name `getAuthoritativeRaw()` as the acquired-lock read that any mode also rejects, and remove `flexible` from `getRaw()`'s internal-caller list because only the opening `manyRaw()` read remains on that path.

#### Failover raw-read fallback

Widen only `FailoverStore::attemptOnAllStores()` to accept `Closure|string`. For closures, pass the resolved repository; for method names, retain the current dynamic call. Keep the existing first-success short circuit, cancellation behavior, failure history, and `CacheFailedOver` reporting. Do not combine it with the every-store invalidation loop, whose accumulation semantics are intentionally different.

Use closure dispatch for:

- `getRaw()`: `RawReadable::getRaw()` or contract `get()`;
- `manyRaw()`: `RawReadable::manyRaw()` or contract `get($keys)`;
- `getAuthoritativeRaw()`: authoritative, raw, then contract read.

Extend `RawReadable`'s class docblock to describe repository-level fallbacks. A contract-only repository cannot expose the raw null sentinel, so its fallback is deliberately lossy: cached null and miss are indistinguishable, and nullable remember/flexible callbacks may run again. This is the only result possible through the public repository contract and is better than the current fatal call. The flexible refresh marker is an integer, so this does not weaken its comparison.

Remove `StackStore::getStores()`. It is marked internal, has no callers, and the new recursive seam does not need broad layer exposure.

#### Documentation

Update `src/docs/cache.md` in the sections that own each behavior:

- in **Building Cache Stacks**, state that upper layers may continue serving values until their configured/item TTL expires, while flexible refresh coordination rechecks the bottom authority after taking its lock so a stale upper marker does not cause repeated refreshes;
- in **Cache Failover**, state that each operation selects the first available backend independently, so a lock and a later read are not guaranteed to use the same backend if availability changes.

Do not describe this as strong consistency for cache values or add a configuration surface.

#### Tests

Extend the existing cache tests rather than creating a new test hierarchy:

- `tests/Cache/CacheRepositoryTest.php`: authoritative reads preserve item-key namespacing, sentinels, retrieval events, failure events, cancellation, and incomplete-class handling; ordinary stores fall back to their raw `get()` behavior. Exercise public `flexible()` with an opening marker that becomes stale before the acquired-lock recheck, and prove the callback is skipped through the authoritative seam.
- `tests/Cache/CacheMemoizedStoreTest.php`: an authoritative read bypasses a stale memoized marker and delegates to its inner repository without writing the memo.
- `tests/Cache/CacheStackStoreTest.php`: direct stack, single-layer stack, bottom-stack, and bottom-failover recursion; valid/missing/malformed records; null sentinel preservation; and a direct assertion that authoritative reads never write an upper layer.
- `tests/Cache/CacheFailoverStoreTest.php`: direct authoritative recursion, failover containing a stack, memoized failover containing a stack, first-store failure, and contract-only custom repositories. Prove single and batched raw reads use the contract fallback and explicitly pin its sentinel-lossy null behavior.
- `tests/Cache/Redis/AnyTaggedCacheTest.php`: the new authoritative read throws through the existing any-mode read boundary.
- `tests/Cache/CacheStackStoreTagsTest.php`: add the new authoritative method to the existing enumeration of read operations rejected by stack any-mode tags.
- `tests/Integration/Cache/Redis/MemoizedStoreTest.php`: exercise public `flexible()` through the real Redis-backed memo driver. Prime a stale memoized marker, update the backing marker as another caller would, invoke the deferred refresh, and prove the callback is skipped. Wrapper-composition details remain in focused unit tests rather than being duplicated here.

### 3. Complete optional cache and prepared-statement event gating, then compile event wildcards once

#### Cache event construction

All 83 cache event sites currently create a factory closure before `Repository::event()` checks `hasListeners()`. `TaggedCache::event()` wraps that factory in another closure before delegating. This leaves avoidable allocation on every ordinary cache operation even when no listener exists.

Restore the protected Laravel-shaped dispatch seam in `Repository` and `TaggedCache`:

```php
protected function event(object $event): void
{
    $this->events?->dispatch($event);
}
```

`TaggedCache::event()` applies `setTags()` to the object before delegating, matching current Laravel and Hypervel before `6c2f5debd`. Guard every framework-owned event construction directly with the exact event class:

```php
if ($this->events?->hasListeners(WritingKey::class)) {
    $this->event(new WritingKey(/* ... */));
}
```

This performs one listener lookup, constructs no closure or event when absent, retains tagged decoration through normal polymorphic dispatch, and restores Laravel protected API compatibility. Do not add a factory helper, variadic event arguments, checked-listener token, listener-result memo, or a second listener check inside `event()`.

Keep success/failure dispatch branches in the structural form `if ($result) { if (hasListeners(...)) { ... } } elseif (hasListeners(...)) { ... }`. This makes success own the success-listener decision and prevents a later edit from dispatching failure events for a successful operation merely because no success listener exists.

For event loops, guard immediately before construction and skip the complete loop when no class it can emit has listeners:

- Batch terminal/failure loops emit one loop-invariant class, so resolve that class before the loop and use one outer guard. If a listener is removed or added during an earlier dispatch, each later `dispatch()` resolves the current listener set; constructing the remaining objects after removal is the only possible extra work and is bounded to that batch.
- `manyRaw()` can alternate `CacheHit` and `CacheMissed`. Use one outer OR guard to skip the loop when neither class is listened to, then keep an exact per-class guard inside. The outer decision is safe because no coroutine yield occurs between the all-false check and returning the normalized result. The inner checks allow a listener for one outcome to register a listener for the other outcome during an earlier dispatch.
- Do not hoist a listener decision across cache/Redis I/O or a preceding event dispatch.

Add concise source commentary only at the mixed `manyRaw()` loop, where the outer/inner distinction is not obvious. The direct guards and restored protected methods otherwise explain themselves.

#### Prepared-statement event construction

Apply the same lowest-boundary correction to the one remaining event-factory seam in the framework. `PdoConnection::prepared()` currently allocates a closure for every prepared statement before `Connection::event()` checks for `StatementPrepared` listeners, and its protected helper differs from Laravel's object-shaped API.

- restore `Connection::event(object $event): void` as a direct nullable dispatch;
- guard `new StatementPrepared(...)` in `PdoConnection::prepared()` with `hasListeners(StatementPrepared::class)`;
- do not change query or transaction event handling, which already guards object construction directly.

This removes all remaining closure-form event dispatch in framework source and avoids allocation on every prepared query when the optional event has no listener.

#### Registered wildcard compilation

`Dispatcher::hasWildcardListeners()`, `getWildcardListeners()`, and `getWildcardObservers()` currently call `Str::is()` for every registered pattern and runtime event. Each call repeats `preg_quote()`, wildcard translation, and regex-string construction. Measurements with five wildcard listeners put that repeated work at roughly 2.4 microseconds per scan; reusing registered regexes reduces it by about 72 percent.

Add one `Dispatcher` map from registered wildcard pattern to compiled case-sensitive regex. Populate it eagerly from both protected wildcard setup methods and remove it through the existing joint wildcard `forget()` path. The key set is exactly the union of the boot-owned listener and observer wildcard registries, so arbitrary runtime event names never grow worker state. Do not add queried-event result caches, lazy fallback entries, an LRU, an invalidation version, configuration, or a public Support API; `EventsDispatcherTest::testRuntimeEventNamesDoNotGrowDispatcherState()` must remain green.

The compiler is the case-sensitive wildcard translation from `Str::is()` verbatim: `preg_quote()` with `#`, replace escaped `\*` with `.*`, and anchor with `#^...\z#su`. All three replaced calls currently use `Str::is()`'s default case-sensitive mode. Preserve a direct `$pattern === '*'` fast path so catch-all observers avoid regex work and keep exact matching semantics for arbitrary byte strings.

#### Tests

Keep the existing listener-present payload/order coverage for all 19 cache event classes. Add focused behavioral tests for the base repository, namespaced tags, stack/any-mode tags, Redis any tags, and Redis all tags using small test subclasses that count protected `event()` entry:

- without listeners, representative read, write, batch, and administrative operations never enter `event()`;
- with a matching listener, each implementation family enters and dispatches normally.

Do not mirror all 83 mechanical sites or inspect source text. Existing detailed event tests catch a guard/event-class mismatch; the new tests prove the absent-listener fast path and protect the restored dispatch seam.

Extend `tests/Events/EventsDispatcherTest.php` behaviorally for literal dots, a `#` delimiter, multiline names, start and end anchoring, case sensitivity, and bare `*`. Do not reflect the compiled regex or assert timing thresholds. Its existing runtime-name test automatically includes the new array property and proves arbitrary queried names do not grow it.

Extend `tests/Database/DatabasePdoConnectionTest.php` so a prepared statement with no `StatementPrepared` listener does not enter the protected `event()` seam, while its existing listener-present test still verifies the event payload and dispatch.

### 4. Guard the remaining optional framework lifecycle events

#### Problem

Several framework-owned event objects are still constructed and dispatched when no active listener exists. An empty dispatch pays object construction, event parsing, a coroutine deferral-state lookup, and both listener and observer resolution. An exact `hasListeners()` guard performs one listener lookup and stops, so it is cheaper even for small event DTOs and materially cheaper on per-job, per-query-adjacent, pool, and server callback paths.

The inconsistency also contradicts Hypervel's documented passive-observer contract: observers see events dispatched because an active listener or targeted wildcard exists, but must not make optional events exist. The canonical contract remains in [Passive Observers](../../src/docs/events.md#passive-observers); do not duplicate it in other documentation.

#### Guarding rules

At every owned construction site, check `hasListeners(ExactEvent::class)` immediately before constructing and dispatching the event. Keep the guard at the lowest boundary that owns construction, preserve payloads/order/error propagation, and do not add a lazy-dispatch helper or closure factory.

- Exact listeners, targeted wildcards, and interface listeners continue to cause dispatch. Passive `observe()` registrations and bare `listen('*')` do not.
- Do not guard an event implementing `ShouldBroadcast`; broadcasting is itself a dispatch side effect and is not counted by `hasListeners()`. Audit every converted class for that interface before editing.
- Do not guard job/command-bus dispatch, broadcast delivery, webhook delivery, or a lifecycle event whose targeted listener is the subsystem's required control flow.
- Preserve existing configuration checks. For pool-release events, both the configured-event predicate and the listener guard must be true before constructing the event.
- Do not hoist listener decisions across I/O, coroutine suspension, or preceding dispatches.

Apply direct guards to these optional event families:

- Queue: `SyncQueue`, `Worker`, `QueueManager`, queue monitor/retry commands, and `Jobs\Job::fail()`. Existing producer, idle, loop, pop, debounce, and failover guards remain intact; perform a final exact-class pass across `src/queue` because several guards sit more than one line above dispatch.
- Database and pool: connection establishment/reconnect, configured pool release, schema dump/prune, database monitoring, migration load/refresh/run events, model pruning, and mass pruning. Keep `Migrator::fireMigrationEvent(MigrationEventContract)` object-shaped; guard only its owned construction sites. `PruneCommand` reports the aggregate total without temporary listener registration.
- Foundation, Console, and Testbench: health diagnosis, maintenance mode, vendor/stub publishing, email verification, Artisan startup, schedule pause/resume, and Testbench serve lifecycle events.
- Optional integrations: Telescope's `MessageLogged`, Horizon's user-facing deploy/out-of-memory events, and Inertia SSR failure. Horizon events consumed by its required `EventMap` workflow remain direct. Inertia constructs `SsrRenderFailed` when listened for or when `throw_on_error` needs the object for `SsrException`, but dispatches it only when listened for.
- Server lifecycle: server pre-fork/start, core bootstrap callbacks, and server-process before/after/pipe callbacks.

Two construction sites need local handling rather than a mechanically wrapped statement:

- `StubPublishCommand` uses the mutable `PublishingStubs` event as the source of stubs after listener mutation. When no listener exists, iterate the original stubs directly.
- `TaskCallback` uses mutable `OnTask::$result`. Construct it only for an active listener and call `finish()` only for the non-null result that listener supplies. Passive observers cannot supply task results; that is consistent with their documented non-influencing role. A no-listener path must still emit guarded `TaskTerminated` when that separate event has a listener.

`PruneCommand` needs a structural correction before its guards are complete. It currently registers a temporary `ModelsPruned` listener on the worker-shared dispatcher and then calls `forget(ModelsPruned::class)`. Every successful command therefore removes all application listeners and observers for that event; an exception before `forget()` also leaks the command closure, and concurrent programmatic calls can receive each other's progress. Remove both registry mutations. Use `pruneAll()`'s existing cumulative total to print one final count per model, while retaining the zero-result message. Guard `ModelPruningStarting` and `ModelPruningFinished` in the command and guard `ModelsPruned` in `Prunable` and `MassPrunable` at their different existing construction sites. Active application listeners retain the same cumulative event payloads; only intermediate console counter updates disappear, while the final line carries the same last value.

Do not add an optional progress callback to `pruneAll()`. It would change both Laravel trait signatures and can break narrower overrides in subclasses of prunable models for a cosmetic progress update. Individual-listener removal still permits cross-coroutine delivery, a scoped-listener facility would add a public contract and coroutine-local registry semantics for one command, and moving chunking into the command would duplicate the traits' deletion, soft-delete, reporting, and event behavior. No Laravel-difference or porting-guide entry is warranted: command inputs, deletion behavior, status, event payloads, and `pruneAll()` remain unchanged.

#### Explicit direct-dispatch cases

Leave these unchanged:

- bus jobs, queued listeners, Horizon controller jobs, Scout/Telescope jobs, broadcasts, and webhooks;
- broadcastable framework events;
- Horizon `RedisQueue`, `MasterSupervisorLooped`, `SupervisorLooped`, and `LongWaitDetected`, whose targeted `EventMap` listeners are required internal behavior;
- string-named application bootstrap, view, and Eloquent events, where no event object is constructed;
- public APIs receiving an event object already built by the caller.

#### Tests and audit

Update existing listener-present tests before adding no-listener cases. Every affected dispatcher mock must explicitly return true from `hasListeners()` for the class under test, and every expected dispatch must use `once()` or `times()` so a missing guard expectation cannot make the test pass without dispatching.

Add focused no-listener and listener-present coverage by implementation family rather than mirroring every site:

- queue worker success/failure/timeout/release/signal/stop, `SyncQueue`, queue manager/commands, and job failure;
- database manager/pool, commands, migrator, and pruning;
- Foundation, Console, Testbench, Telescope, Horizon, and the Inertia listener/throw matrix;
- server bootstrap/core/process callbacks, including no-listener `OnTask` making no `finish()` call.

Grep affected tests for bare `shouldReceive('dispatch')` expectations and make listener-present cardinality explicit. Do not add a source-text architecture test: it would need a fragile operational-dispatch exception list, miss assigned or static constructions, and couple the suite to formatting. Finish with exact-class source audits for the converted packages and prove every converted event class is non-broadcastable.

### 5. Apply stack layer TTLs to direct `putMany()` calls

#### Problem and change

`StackStoreProxy::put()`, `forever()`, and `touch()` cap TTLs, but `putMany()` forwards its caller's TTL unchanged. `StackStore::putMany()` currently loops through `put()`, so the defect is not reached through today's stack write path; however, the proxy implements the complete `Store` contract and silently violates its own layer-TTL invariant when called directly.

Update `src/cache/src/StackStoreProxy.php` so `putMany()` uses the same effective TTL rule as `put()`:

```php
if (is_null($this->ttl) || $seconds < $this->ttl) {
    return $this->store->putMany($values, $seconds);
}

return $this->store->putMany($values, $this->ttl);
```

Remove the proxy's generic `call()` helper and delegate its contract methods directly. Every accepted method is guaranteed by `Store`, so the dynamic guard is unreachable and replaces native errors with a worse message. Do not change increment/decrement: those store methods have no TTL argument. Do not rewrite `StackStore::putMany()` into a batching path; that would change rollback and layer-record behavior for no proven benefit.

Add focused coverage in `tests/Cache/CacheStackStoreTest.php` for no cap, shorter/equal/longer requested TTLs, return propagation, and the existing stack-level path.

### 6. Preserve nullable unique cache-store selection

#### Problem

`UniqueJobPayloadContext::getCacheStore()` calls `$job->uniqueVia()->getName()` whenever the method exists. The unique-lock path already defines `uniqueVia(): ?Repository`: null means use the default cache repository. A unique job returning null therefore acquires its lock correctly and then fails while its payload metadata is registered.

An actual repository may also have a null name. That is valid metadata and must not be relabeled as the default store, or missing-model lock release can target the wrong cache.

#### Change

Update `src/bus/src/UniqueJobPayloadContext.php` to call `uniqueVia()` once and distinguish three cases:

- no `uniqueVia()` method: return `config()->string('cache.default')`;
- `uniqueVia()` returns null: return the default store name;
- it returns a repository: return `getName()` unchanged, including null.

Do not use `?->getName() ?? config(...)`, because that collapses a real unnamed repository into the default.

Extend `tests/Bus/UniqueJobPayloadContextTest.php` through the actual registration/payload path for jobs with no method, a null return, a named repository, and an unnamed repository. Assert the emitted `laravel_unique_job_cache_store` value and that each resolver is called once.

### 7. Port numeric validation exception handling

#### Problem

Hypervel predates Laravel's fixes that make malformed or policy-rejected numeric sizes fail validation instead of escaping from `passes()` as math exceptions. Current failures include non-finite numeric values, allowed-by-`is_numeric()` strings containing form-feed whitespace, and scientific notation outside Hypervel's configured exponent range.

Hypervel has two execution paths: delegated `validate*()` methods and compiled inline size checks. Porting only upstream's trait changes leaves compiled validation inconsistent.

#### Delegated changes

Port the final current-upstream source shape into `src/validation/src/Concerns/ValidatesAttributes.php`, preserving Hypervel's native types and strict comparisons:

- catch `Hypervel\Support\Exceptions\MathException|Brick\Math\Exception\MathException` and return false in `validateBetween`, `validateMax`, `validateMin`, and `validateSize`;
- add the same catch around the BigNumber branches of `validateGt`, `validateLt`, `validateGte`, and `validateLte`;
- catch Hypervel's exponent-policy `MathException` around the final non-numeric size comparisons in those four field-comparison methods, because `getSize()` itself can reject an exponent;
- retain `multiple_of`'s existing exception translation. It has a different public failure contract and is not part of the upstream change.

Catch only the two math exception types. Do not swallow arbitrary application, file, callback, or type errors.

#### Compiled changes

Update `src/validation/src/PlanExecutor.php` so `compareSize()` and `compareSizeBetween()` wrap the full `sizeOf()` plus native/BigNumber comparison in the same two-type catch and return false. The try boundary must begin before `sizeOf()`: Hypervel's exponent policy can throw before the BigNumber conversion, including when the native integer fast path would otherwise return.

Keep the native fast path, threshold compilation, and one exponent-policy callback invocation. Do not route compiled rules back through delegated methods or add a parser abstraction.

Leave `canPreflightInline()`'s exclusion for non-finite and exponent-bearing numeric size values unchanged. That preflight gate, not the new exception catch, prevents a size check from running twice and preserves the one-invocation exponent-policy invariant.

Audit all 16 affected references in `ValidatesAttributes.php`, not only the calls whose arguments contain `getSize()`:

- 12 `BigNumber::of()` sites: current lines 460, 1225, 1233, 1257, 1265, 1289, 1297, 1321, 1329, 1547, 1646, and 2327;
- four final `getSize() <operator> getSize()` comparisons: current lines 1240, 1272, 1304, and 1336.

The `BigDecimal` calls in `multiple_of` remain unchanged.

#### Tests

Port the current upstream cases into `tests/Validation/ValidationValidatorTest.php` from the checked-out default branch:

- size rules reject `"\x0C5"` and `"5\x0C"` without throwing;
- `gt`, `lt`, `gte`, and `lte` reject those values on either side of a field comparison;
- out-of-range scientific exponents return false;
- a custom exponent-range callback still receives the exact scale, attribute, and value, and a false decision becomes validation failure.

Update `tests/Validation/ValidationCompiledExecutionTest.php` to run non-finite values, malformed numeric strings, range rules, field-comparison rules, and exponent-policy rejection through both `Validator` and `DelegatedValidationValidator`. Replace the old exception expectation with false validation and retain the warning guard. Keep `testNumericSizeChecksEnforceExponentPolicyExactlyOnce()` as the regression for callback count.

### 8. Reject positive per-message delays on SQS FIFO queues

#### Problem

AWS does not support per-message `DelaySeconds` on FIFO queues. `SqsQueue::getQueueableOptions()` omits the option when the queue name ends in `.fifo`, so `later()` and delayed bulk/batch dispatch silently send immediately. Laravel currently has the same bug. Worker retries are unaffected because `SqsJob::release()` changes message visibility instead of calling `later()`.

SQS bulk dispatch also extracts delay with `$job->delay ?? null` instead of the inherited attribute-aware `getJobDelay()`. A job using the documented `#[Delay]` attribute therefore loses its delay on a standard SQS queue, and the same blind extraction would let a FIFO job evade validation. Hypervel's Database, Redis, and base queue bulk paths already use `getJobDelay()`; `DatabaseQueue::prepareBatchJobs()` is the direct structural analogue with the same job/delay/payload shape. Unlike the shared FIFO-delay defect, this attribute-loss defect is Hypervel-only: current Laravel SQS resolves the attribute correctly by inlining `getAttributeValue()`. Hypervel's helper has those same semantics, so the attribute repair needs no upstream report.

#### Change

In `src/queue/src/SqsQueue.php`:

- add one protected resolver for the effective queue name (`null` and `''` use the configured default) and use it anywhere this class currently repeats that rule;
- add one protected validator used by `later()` and `getQueueableOptions()`;
- add a lightweight batch preflight at the start of `bulk()`, immediately after the empty-jobs guard and before transaction lookup, partitioning, payload creation, or SQS I/O;
- have the batch preflight resolve the effective queue once and return immediately for standard queues; for FIFO queues, walk the original jobs, resolve each delay through inherited `getJobDelay()`, and invoke the same validator;
- replace `$job->delay ?? null` in `prepareBatchMessages()` with `getJobDelay()` so payload metadata and standard-queue transport options honor `#[Delay]`;
- resolve the effective queue name once at each eager call boundary and pass that string to the validator before testing the `.fifo` suffix;
- reject only when the effective queue is FIFO and `secondsUntil()` produces a positive remaining delay; permit non-FIFO queues and null, zero, negative, or elapsed delays;
- state only the transport constraint in the exception: SQS FIFO queues do not support per-message delays. Do not mention an outbox API that this repository does not provide.

Call the validator at the start of `later()` before payload creation or `enqueueUsing()`. Keep the call in public `getQueueableOptions()` because that method owns the SQS `DelaySeconds` decision; the transport rule must remain colocated with the option it governs even though eager callers also validate for failure timing. Preserve `bulk()`'s existing partition, per-group payload preparation, send, and transaction-callback order after the new preflight.

This eager placement makes every positive delayed FIFO job in a mixed batch fail before any payload is built, SDK call is made, or transaction callback is registered. It avoids preparing and retaining deferred payloads during the immediate send, whose entry construction already duplicates message bodies and can also retain overflow payload copies. Hoisting both groups would therefore increase peak payload memory and waste deferred serialization when the immediate SQS send fails.

The validation cost is deliberately bounded:

- standard queues resolve the effective name and suffix once, then skip the per-job preflight with no added `secondsUntil()` call; each job still changes from a bare delay-property read to the cached metadata-aware `getJobDelay()` lookup during existing payload preparation so `#[Delay]` works;
- null-delay FIFO jobs do not call `secondsUntil()`;
- positive FIFO delays call it once in the preflight and then fail;
- explicitly non-positive or elapsed FIFO delays add one computation in the preflight and one at the transport boundary, in addition to the existing payload-metadata computation;
- a permitted FIFO batch resolves each job's delay once during preflight and again during payload preparation. `ClassMetadataCache` retains reflection, default-property, and instantiated attribute metadata by class for the worker lifetime, so after the first job of a class each resolution uses cached metadata plus property and attribute-value access.

Do not add descriptor arrays, validation flags, normalized-delay plumbing, or payload rebuilding to remove those two local computations from the unusual non-positive FIFO-delay path. Do not re-read delay after payload hooks. `createPayloadUsing` receives the live job through the payload array and can deliberately mutate it, but the scheduling delay and payload `delay` field have already been captured. Honoring that escape-hatch mutation would make the serialized command disagree with the scheduling metadata unless the operation were rebuilt, which is unsupported machinery rather than a transport fix.

The effective-name resolver is the single place to incorporate queue-name forwarding if Laravel's later `Queue::forward()` API is ported. Do not port forwarding as part of this fix.

#### Tests

`tests/Queue/QueueSqsQueueTest.php` currently has no bulk-delay coverage. Add the first standard and FIFO bulk-delay cases while updating the existing direct-delay tests:

- replace the three tests that currently assert a positive FIFO delay is silently omitted with exact `LogicException` expectations and no SQS call;
- cover string jobs, object jobs, pending dispatch, and delayed bulk/batch jobs;
- prove a standard SQS bulk job's `#[Delay]` attribute reaches both payload metadata and `DelaySeconds`, while the same attribute is rejected on FIFO before payload creation;
- cover default/empty queue resolution so all eager-validation and transport-option sites classify the same effective FIFO queue;
- prove zero, negative, and past delays remain immediate on FIFO queues;
- prove positive delay options remain unchanged on standard queues and that their batch preflight adds no second `secondsUntil()` computation;
- prove an after-commit bulk job throws before rollback/post-commit callback registration;
- prove a mixed immediate/after-commit batch with a positive delayed FIFO job performs no SQS call and registers no transaction callbacks;
- call `getQueueableOptions()` directly to pin enforcement at the public transport-option boundary;
- do not add a payload-hook mutation test or a differently configured fresh-owner test; the former is an intentional scheduling snapshot, while a queue pool constructs every owner from the same frozen configuration.

Update user-facing documentation:

- `src/docs/queues.md`: explain in the SQS FIFO section that positive per-message delay is unsupported and rejected rather than sent immediately;
- `src/queue/README.md`: add a concise `Differences From Laravel` bullet because Laravel silently ignores the delay;
- `src/docs/porting-from-laravel.md`: tell porters to remove positive per-message delays from FIFO dispatch or choose a queue transport that supports them.

### 9. Keep `RateLimited` behavior and remove false commentary

The default `$result->retryAfter() + 3` delay matches current Laravel and is not a defect. Do not add a padding option. Hypervel already uses `??`, so `releaseAfter(0)` intentionally requests immediate retry; Laravel's `?:` ignores zero.

In `src/queue/src/Middleware/RateLimited.php`, remove or correct the comment claiming Laravel preflights every policy. Laravel also checks and records each limit in sequence, and Hypervel's atomic limiter consumes policies in that same order. The existing tests already prove earlier accepted policies remain consumed when a later one denies.

Add one concise bullet to `src/queue/README.md` documenting that Hypervel honors `releaseAfter(0)`. Do not add it to the porting guide: this narrow edge does not require general porting action.

### 10. Record the bounded Brick Math follow-up

Keep the new `docs/todo.md` Validation entry as the single tracked requirement:

- adopt Brick Math 0.20 bounded parsing when the supported Ramsey UUID dependency permits it;
- preserve Hypervel's configurable exponent policy;
- cover delegated and compiled rules, oversized mantissas/exponents, malformed input, and `multiple_of`;
- do not use a Composer alias or local parser workaround.

Keep the Framework-wide backend-capability entry's Database Queue example. It explains why this plan does not add unsafe queue-object memoization and identifies the connection/pool generation as the future cache owner.

## File plan

### Source and contracts

- `src/contracts/src/Cache/AuthoritativeRawReadable.php` — new internal recursive capability.
- `src/contracts/src/Cache/RawReadable.php` — document store and repository fallback semantics.
- `src/contracts/src/Cache/Repository.php` — document the existing array-key/default form used by contract-only failover repositories.
- `src/cache/src/Repository.php` — authoritative raw read and flexible marker recheck.
- `src/cache/src/TaggedCache.php` — restore object-shaped tagged event decoration.
- `src/cache/src/AnyModeTaggedCache.php` — preserve the closed any-mode read surface.
- `src/cache/src/StackTaggedCache.php` — guard stack-owned event construction.
- `src/cache/src/Redis/AnyTaggedCache.php` — guard Redis any-mode event construction.
- `src/cache/src/Redis/AllTaggedCache.php` — guard Redis all-mode event construction.
- `src/cache/src/MemoizedStore.php` — bypass memo for authoritative reads.
- `src/cache/src/FailoverStore.php` — recursive/fallback reads through the existing failover loop.
- `src/cache/src/StackStore.php` — recursive bottom-only, no-backfill read; remove dead getter.
- `src/cache/src/StackStoreProxy.php` — apply the layer TTL cap to `putMany()`.
- `src/queue/src/DatabaseQueue.php` — MariaDB engine detection without worker memoization.
- `src/queue/src/SqsQueue.php` — effective queue resolution and positive FIFO delay rejection.
- `src/queue/src/Middleware/RateLimited.php` — remove inaccurate commentary only.
- `src/bus/src/UniqueJobPayloadContext.php` — nullable unique-store handling.
- `src/validation/src/Concerns/ValidatesAttributes.php` — port current upstream exception handling.
- `src/validation/src/PlanExecutor.php` — match compiled execution to delegated validation.
- `src/events/src/Dispatcher.php` — compile bounded registered wildcard patterns once.
- `src/database/src/Connection.php` — restore object-shaped protected event dispatch.
- `src/database/src/PdoConnection.php` — guard prepared-statement event construction at its call site.
- `src/queue/src/{SyncQueue.php,Worker.php,QueueManager.php,Console/MonitorCommand.php,Console/RetryCommand.php,Jobs/Job.php}` — guard optional queue lifecycle events.
- `src/database/src/{DatabaseManager.php,Pool/PooledConnection.php,Console/DumpCommand.php,Console/PruneCommand.php,Console/MonitorCommand.php,Console/Migrations/FreshCommand.php,Console/Migrations/MigrateCommand.php,Console/Migrations/RefreshCommand.php,Migrations/Migrator.php,Eloquent/Prunable.php,Eloquent/MassPrunable.php}` and `src/pool/src/Connection.php` — guard database, migration, pruning, and pool lifecycle events.
- `src/foundation/src/{Http/HealthCheckController.php,Console/DownCommand.php,Console/UpCommand.php,Console/VendorPublishCommand.php,Console/StubPublishCommand.php,Auth/EmailVerificationRequest.php}`, `src/console/src/{Application.php,Commands/SchedulePauseCommand.php,Commands/ScheduleResumeCommand.php}`, and `src/testbench/src/Foundation/Console/ServeCommand.php` — guard optional framework and command events.
- `src/telescope/src/Telescope.php`, `src/horizon/src/{ProvisioningPlan.php,Listeners/MonitorMasterSupervisorMemory.php,Listeners/MonitorSupervisorMemory.php}`, and `src/inertia/src/Ssr/HttpGateway.php` — guard optional integration events while retaining required local behavior.
- `src/server/src/Server.php`, `src/core/src/Bootstrap/{PipeMessageCallback.php,WorkerExitCallback.php,ConnectCallback.php,PacketCallback.php,WorkerStartCallback.php,StartCallback.php,WorkerErrorCallback.php,CloseCallback.php,ManagerStopCallback.php,ReceiveCallback.php,FinishCallback.php,ManagerStartCallback.php,WorkerStopCallback.php,ShutdownCallback.php,TaskCallback.php}`, and `src/server-process/src/AbstractProcess.php` — guard server lifecycle events at their owning callbacks.

### Tests

- `tests/Queue/QueueDatabaseQueueUnitTest.php`
- `tests/Queue/QueueSqsQueueTest.php`
- `tests/Cache/CacheRepositoryTest.php`
- `tests/Cache/CacheEventsTest.php`
- `tests/Cache/CacheMemoizedStoreTest.php`
- `tests/Cache/CacheFailoverStoreTest.php`
- `tests/Cache/CacheStackStoreTest.php`
- `tests/Cache/CacheStackStoreTagsTest.php`
- `tests/Cache/Redis/AnyTaggedCacheTest.php`
- `tests/Cache/Redis/AllTaggedCacheTest.php`
- `tests/Integration/Cache/FailoverStoreTest.php`
- `tests/Integration/Cache/Redis/MemoizedStoreTest.php`
- `tests/Events/EventsDispatcherTest.php`
- `tests/Database/DatabasePdoConnectionTest.php`
- `tests/Bus/UniqueJobPayloadContextTest.php`
- `tests/Validation/ValidationValidatorTest.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`
- existing queue event tests: `tests/Queue/{QueueSyncQueueTest.php,QueueWorkerTest.php,QueuePauseResumeTest.php,MonitorCommandTest.php,RetryCommandTest.php,QueueJobTest.php,PooledJobWorkerTest.php,QueueBackgroundQueueTest.php,QueueBeanstalkdJobTest.php,QueueDeferredQueueTest.php}`
- existing database/pool event tests: `tests/Database/{DatabaseManagerTest.php,DatabaseMigrationFreshCommandTest.php,DatabaseMigrationMigrateCommandTest.php,DatabaseMigrationRefreshCommandTest.php,DatabaseMonitorCommandTest.php,DatabaseMigratorIntegrationTest.php,PruneCommandTest.php}`, `tests/Integration/Database/{EloquentPrunableTest.php,EloquentMassPrunableTest.php,MigratorEventsTest.php,PooledConnectionTest.php}`, and `tests/Pool/ConnectionTest.php`
- existing framework/integration event tests: `tests/Foundation/{Http/HealthCheckControllerTest.php,Auth/EmailVerificationRequestTest.php,Console/VendorPublishCommandTest.php,Console/RouteListCommandTest.php}`, `tests/Integration/Foundation/{MaintenanceModeTest.php,Console/StubPublishCommandTest.php}`, `tests/Console/{ConsoleApplicationDeferredCallbacksTest.php,ConsoleApplicationResolveTest.php,Scheduling/SchedulePauseCommandTest.php,Scheduling/ScheduleResumeCommandTest.php}`, `tests/Testbench/Foundation/Console/ServeCommandTest.php`, `tests/Telescope/Telescope/TelescopeTest.php`, `tests/Integration/Horizon/Feature/{ProvisioningPlanTest.php,MonitorMasterSupervisorMemoryTest.php,MonitorSupervisorMemoryTest.php}`, and `tests/Inertia/HttpGatewayTest.php`
- server event tests: `tests/Server/{ServerTest.php,ServerNativeTest.php}`, `tests/Core/Bootstrap/{LifecycleCallbackTest.php,TaskCallbackTest.php,WorkerExitCallbackTest.php,WorkerStartCallbackTest.php}`, and `tests/ServerProcess/{AbstractProcessTest.php,ListenMethodTest.php}`

### Documentation

- `src/docs/cache.md`
- `src/docs/events.md`
- `src/docs/queues.md`
- `src/queue/README.md`
- `src/docs/porting-from-laravel.md`
- `docs/todo.md`
- this plan

## Implementation order and verification

Work one file at a time. Before each item, re-read the relevant source, its callers/callees, package README, current upstream source/tests where applicable, and the matching plan section.

1. Database Queue source and its unit test; run `./vendor/bin/phpunit --no-progress tests/Queue/QueueDatabaseQueueUnitTest.php` immediately after the test edit.
2. Add the authoritative cache contract, then update `Repository`, `MemoizedStore`, `FailoverStore`, `StackStore`, and `StackStoreProxy` in dependency order. Update one test file at a time and run it immediately:
   - `tests/Cache/CacheRepositoryTest.php`
   - `tests/Cache/CacheMemoizedStoreTest.php`
   - `tests/Cache/CacheFailoverStoreTest.php`
   - `tests/Cache/CacheStackStoreTest.php`
   - `tests/Cache/CacheStackStoreTagsTest.php`
   - `tests/Cache/Redis/AnyTaggedCacheTest.php`
   - `tests/Integration/Cache/Redis/MemoizedStoreTest.php`
3. Restore object-shaped cache event dispatch and guard one source file at a time. Update and immediately run each owning event test file before proceeding to the next family: `tests/Cache/CacheEventsTest.php`, `tests/Cache/CacheStackStoreTagsTest.php`, `tests/Cache/Redis/AnyTaggedCacheTest.php`, and `tests/Cache/Redis/AllTaggedCacheTest.php`.
4. Guard prepared-statement events, update `tests/Database/DatabasePdoConnectionTest.php`, and run that file immediately. Compile registered event wildcard patterns, update `tests/Events/EventsDispatcherTest.php`, and run that file immediately.
5. Complete event guards one family at a time: queue; database/pool; Foundation/Console/Testbench/integrations; server/core/server-process. Update each family's existing listener-present tests with explicit `hasListeners()` behavior and dispatch cardinality, add focused no-listener coverage, and run every changed test file immediately. Finish with exact-class audits for queue completeness and `ShouldBroadcast` exclusions.
6. Update cache documentation.
7. Fix unique-job metadata and run `tests/Bus/UniqueJobPayloadContextTest.php`.
8. Port delegated validation, run `tests/Validation/ValidationValidatorTest.php`, then update compiled execution and run `tests/Validation/ValidationCompiledExecutionTest.php`. Run both together after parity is complete.
9. Fix SQS and run `tests/Queue/QueueSqsQueueTest.php`.
10. Correct `RateLimited` commentary and queue documentation; run `tests/Queue/RateLimitedTest.php` and `tests/Integration/Queue/RateLimitedTest.php` only if source behavior changes while editing. No behavior change is planned.
11. Review the TODO and documentation changes for one source of truth and no stale claims.
12. Run focused PHPStan only if a new type needs confirmation during implementation.
13. Run `composer fix` once at the completed-slice checkpoint. It runs formatting, both static-analysis configurations, the parallel suite, Testbench, and dogfood tests.
14. Perform a full self-review after green checks: trace all changed callers/callees, wrapper recursion, sentinel handling, event and cancellation paths, failover behavior, lock ownership, queue after-commit timing, validation parity, public API effects, and adjacent code touched. Remove stale code/comments and check for added backend calls, hot-path work, worker-lifetime state, overengineering, or weak tests.
15. Request code review and address findings with focused tests. Repeat `composer fix` only if the review changes warrant another full check.

## Completion criteria

- MariaDB before 10.6 never receives `SKIP LOCKED`, including through the `mysql` driver and numeric version overrides.
- Flexible refreshes bypass memoized and stack-local markers only inside the acquired lock, recursively through supported wrapper compositions, without upper-layer writes or extra backing reads.
- Every framework-owned cache and prepared-statement event is constructed only when its exact class has a listener, tagged cache events retain their tags, and the protected event seams match Laravel.
- Remaining optional queue, database/pool, framework/integration, and server lifecycle event objects are constructed only for exact, targeted-wildcard, or interface listeners. Passive observers do not cause dispatch, broadcast and operational dispatches remain direct, listener-present behavior is unchanged, `model:prune` preserves application listeners without shared temporary registration, and no-listener `OnTask` never calls `finish()`.
- Registered listener/observer wildcard patterns compile once, arbitrary runtime names add no dispatcher state, and matching behavior remains unchanged.
- Contract-only custom repositories work in failover raw reads with their unavoidable sentinel-lossy behavior documented and tested.
- Every direct TTL-bearing `StackStoreProxy` write applies its layer cap.
- Null `uniqueVia()` uses the default store while an unnamed real repository remains unnamed.
- Malformed, non-finite, and exponent-policy-rejected numeric sizes fail consistently in delegated and compiled validation without swallowing other errors.
- Every positive FIFO per-message delay is rejected by preflight before payload creation, transport I/O, or transaction callback registration; zero/past FIFO and standard-queue delays remain unchanged.
- SQS bulk honors `#[Delay]` in standard-queue payload metadata and `DelaySeconds`, while rejecting the same attribute on FIFO before payload creation.
- Rate-limit behavior is unchanged and its documentation is accurate.
- Brick Math bounded parsing remains clearly tracked without an incompatible dependency change or workaround.
- Focused tests, `composer fix`, self-review, and final code review are green.
