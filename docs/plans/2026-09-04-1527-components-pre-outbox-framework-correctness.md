# Pre-Outbox Framework Correctness Plan

Status: Approved for implementation

## Objective

Fix the queue, database, Horizon, worker, and signal defects found while preparing the transactional outbox work, without adding an outbox API or outbox-specific runtime behavior. The result must give each dispatch lock an exact owner, keep ownership until a queue has accepted the job, close every supported failure and deferral path, avoid process-long resource retention, and keep ordinary queue operations on their current fast paths.

Also reconcile the application skeleton with the current Foundation configuration in its own repository change.

## Confirmed baseline

- The Components worktree is based on current `0.4`. The MariaDB marker-loss bug and positive-delay SQS FIFO rejection are already fixed and must not be implemented again.
- `brick/math` 0.20 cannot be installed while the supported `ramsey/uuid` release caps Brick Math below it. The bounded numeric-parser follow-up is already recorded in `docs/todo.md`; this plan adds no parser workaround or Composer alias.
- `SyncQueue::pushRaw()` silently discards valid raw payloads. This is shared with Laravel but is still a defect.
- `JobQueued::$id` rejects Pheanstalk's `JobIdInterface`, although the queue contract returns `mixed`.
- `DatabaseQueue::enqueueBatch()` can exceed engine placeholder limits and leave a partial batch if oversized inserts are split without one transaction.
- `DatabaseQueue::getLockForPopping()` repeats engine/version detection on every pop. The queue object cannot safely cache it because pooled physical connections can be replaced during the worker lifetime.
- `UniqueJobPayloadContext` drops ownership during payload construction, before finalization, transaction callbacks, transport publication, and several other failure points have succeeded.
- `UniqueLock::release()` force-releases by key. An expired dispatch can therefore delete a newer dispatch's lock.
- Pending dispatch, scheduling, queued-listener dispatch, and unique broadcast dispatch are the four lock-acquisition sites. Queued listeners and broadcasts currently have no failure cleanup; `ShouldRescue` can additionally swallow the broadcast publication failure.
- `QueueFake` and `BusFake` record accepted jobs outside `Queue::enqueueNow()`. If only real queue drivers mark acceptance, pending and scheduled dispatch owners release the fake's unique/debounce locks; duplicate unique dispatches then pass and debounce maximum-wait state restarts.
- `RedisQueue::bulk()` finalizes payloads and reports queue acceptance before the enclosing Redis `EXEC`. On Cluster it also performs one synchronous round trip per member instead of one batch request.
- Redis single-job queue scripts retransmit their source on every dispatch and silently report success when phpredis returns a non-throwing script error as `false`.
- Deferred/background delayed jobs are accepted into an in-memory timer. Graceful worker shutdown drops them before execution and currently leaves their unique/debounce locks behind.
- `Worker::daemon()`, its timeout-monitor tick, and `SignalManager` watchers are long-lived coroutines. Framework database work and arbitrary listeners executed there can retain pooled resources until process exit.
- `WaitConcurrent::wait()` currently returns after child bodies but before concurrency-slot release and coroutine-deferred cleanup. Combining that premature completion signal with Worker shutdown's slot check creates a non-yielding loop, and accepting it would let shutdown listeners race pooled-resource release.
- The application skeleton and Foundation share every config file except app-only `services.php`; `cors.php` matches. All other shared files differ, and Foundation also has `broadcasting.php`, `concurrency.php`, and `signal.php` that the skeleton lacks.

## Upstream changes to account for completely

Historical pull requests identify the whole affected surface. Port final behavior, tests, fixtures, and documentation from current Laravel `13.x`, adapted for Hypervel's typed and coroutine-safe runtime.

| Pull request | Current behavior to port or adapt |
|---|---|
| [framework #60906](https://github.com/laravel/framework/pull/60906) | Owner-aware unique locks across `Queueable`, `UniqueLock`, pending dispatch, hidden metadata, queued handling, and queued-event/unique-job coverage. |
| [framework #61039](https://github.com/laravel/framework/pull/61039) | `UniqueJobSkipped` and its pending-dispatch coverage. |
| [framework #61234](https://github.com/laravel/framework/pull/61234) | A direct low-level queue push must not release a lock it did not acquire. |
| [framework #61169](https://github.com/laravel/framework/pull/61169) | Current queued-listener debounce propagation through `CallQueuedListener` and the event dispatcher, with queued-event and integration coverage. |
| [framework #61281](https://github.com/laravel/framework/pull/61281) | Release debounce maximum-wait state before handling, including job and listener tests. |
| [framework #59435](https://github.com/laravel/framework/pull/59435) | Avoid repeated `SKIP LOCKED` detection. Hypervel must put the cache on the physical database connection generation rather than the worker-lived queue. |
| [framework #61412](https://github.com/laravel/framework/pull/61412) | `JobInterrupted`, emitted for each running job actually notified of a signal. |
| [framework #61408](https://github.com/laravel/framework/pull/61408) | Add connection and queue context to `WorkerStopping`. |

Do not scan unrelated upstream changes. The pull requests above are complete once all of their current source behavior and meaningful coverage are either present or deliberately adapted here.

## Design invariants

- The code that acquires a unique or debounce lock owns it until a queue confirms acceptance or responsibility is explicitly delegated.
- Queue implementations mark acceptance; they do not release dispatch locks. This lets failover continue to another child after one child fails.
- Unique-lock release uses the exact owner token and cannot remove a newer owner on any supported store where cross-node interleaving is possible. Debounce cleanup refuses to remove state when it observes a newer owner, but remains best-effort across concurrent dispatch nodes because the cache contract has no atomic compare-and-delete or multi-key transaction.
- Payload creation may expose hidden lock metadata, but it does not transfer or consume ownership.
- Cancellation remains cancellation. Cleanup must run, but `CanceledException` identity and propagation stay intact and no failure event is invented.
- `JobQueued` is raised only after acceptance. A throwing `JobQueued` listener cannot turn accepted work into `JobQueueingFailed` or release its dispatch lock.
- Optional events are checked with `hasListeners()` before their objects or event-only child coroutines are allocated.
- No new public database capability contract, queue outbox hook, capability registry, retry loop, payload decoding at shutdown, or job-object retention is introduced.

## Implementation

### 1. Exact dispatch-lock ownership

#### Owner-aware locks

Apply the current behavior from #60906 while keeping Hypervel's public APIs:

- Add the upstream `Queueable::$uniqueLockOwner = ''` state in the upstream order, matching both current Laravel and the adjacent initialized `$debounceOwner` convention.
- Keep `UniqueLock::acquire(): bool` for ordinary callers and add an internal dispatch-specific `acquireForDispatch(object $job): bool`. Both methods share one protected acquisition path that resolves the TTL, selected cache, key, store, and nullable store name once before writing the lock. When the selected store supports lock ownership and acquisition succeeds, record the returned owner on a queueable job. `acquireForDispatch()` then registers those already-resolved values directly; it must not call `uniqueVia()`, `uniqueId()`, `displayName()`, or key generation again.
- Keep selected caches typed as `Hypervel\Contracts\Cache\Repository`. `getName()` is manager configuration on the concrete `Hypervel\Cache\Repository`, not behavior every conforming repository must provide, so record it only after narrowing to that concrete type; custom repositories remain valid and record `null`. Do not add `getName()`, `lock()`, or `restoreLock()` to the repository contract. Keep lock calls at repository level so concrete macro/`__call` dispatch and custom implementations continue to work; use line-scoped PHPStan ignores where the contract cannot express that established dynamic API rather than bypassing it through `getStore()`.
- `UniqueLock::release()` restores and releases that exact lock when an owner is present. Preserve the legacy force-release fallback only for supported jobs/stores that cannot carry owner state, matching current upstream behavior. Put the resolved-cache/key/owner branch in one public static `@internal releaseOwned()` primitive shared with dispatch-context cleanup.
- Include `laravel_unique_job_lock_owner` with the existing cache-store/key hidden metadata. `CallQueuedHandler` must read all three values when a missing model prevents unserialization, restore-and-release a non-empty owner only on a `LockProvider`, and retain force release only for null/empty legacy owner metadata. An unserializable stale job must not delete a newer owner's lock.
- Preserve `uniqueVia(): ?Repository` fallback to the configured default store.
- Construct the broadcast path's `UniqueLock` with the framework default cache; acquisition itself selects a wrapper override once. Widen `UniqueBroadcastEvent::uniqueVia()` to `?Repository` so a nullable user override reaches that default instead of raising a `TypeError`.

Add `UniqueJobSkipped` from #61039. Emit it from `PendingDispatch` when a supported unique queued dispatch fails to acquire its lock, and guard the event before allocation. Do not add it to scheduled dispatch or change the unsupported `ShouldBeUnique`-without-`ShouldQueue` path; neither is part of the upstream event contract.

#### Dispatch context

Rename `UniqueJobPayloadContext` to `DispatchLockContext`; update every source/test consumer and leave no alias. It remains an internal static `WeakMap` keyed by the exact job object.

Each record contains only:

- the hidden scalar unique-job metadata used during payload creation;
- exact unique and/or debounce release provenance already acquired by this dispatch;
- a `delegated` flag for an owner that has handed responsibility to a callback.

The value must not reference the job or capture it in a closure; that would make the weak key self-retaining. It may retain only the cache repository/key/owner data needed to release recorded ownership, which disappears with the weak key and is already worker-owned or bounded to the live dispatch.

Compute all values that can call application code before acquiring a lock. Lazily create the map only at the final assignment, keep that map after it becomes empty, and reset it only through `flushState()`. Registration after acquisition is only WeakMap creation plus one array assignment; do not add an unreachable post-registration unwind. Weak keys bound abandoned entries by live job objects without a runtime size policy.

PHPStan cannot assign an empty generic `WeakMap` to the nullable invariant property even when their printed types match. Keep the two creation sites explicit with a local `WeakMap<object, LockRecord>` annotation immediately before a line-scoped `assign.propertyType` ignore and the assignment. Mutate or unset existing records through a locally narrowed map without suppressions. Share the otherwise-identical delegate/claim mutation in `setDelegated(object $job, bool $delegated): bool`; do not add a map accessor that only relocates the same analyzer limitation.

Expose narrow internal operations to:

- register acquired provenance;
- peek hidden payload metadata without consuming it;
- mark a live record delegated and claim it in the callback;
- mark a job accepted by removing its record;
- release a still-owned record exactly;
- take an opaque read-only release snapshot and release that recorded provenance.

Snapshots exist only for a delayed local job with a live lock record. They contain small release provenance, never the user job graph, and are not used by normal execution.

#### Dispatch owners

Wrap the complete dispatch attempt at all four acquisition sites:

- `PendingDispatch::__destruct()`;
- scheduled unique dispatch;
- `Events\Dispatcher::queueHandler()` for unique and debounced listeners;
- `BroadcastManager::queue()` for unique broadcast wrappers, including the `ShouldRescue` branch.

Each owner follows the same lifecycle:

1. acquire and register unique/debounce ownership through the dispatch-specific acquisition methods;
2. dispatch through Bus/Queue;
3. in `finally`, release recorded ownership only when a context record still exists and is not delegated.

`PendingDispatch::__destruct()` enters that `try/finally` before `shouldDispatch()` so lock acquisition itself cannot move outside the cleanup boundary. Its early lock-miss and prepare-for-dispatch returns still run a no-op context release.

Only `PendingDispatch` raises guarded `UniqueJobSkipped` when unique acquisition fails. Scheduled dispatch, queued listeners, and broadcasts preserve their existing lock-miss behavior and return without that event; no failed acquisition registers ownership. `DebounceLock::acquireForDispatch()` is the sole writer of `Queueable::$debounceOwner` and retains the existing `{owner, maxWaitExceeded}` result for delay selection.

Make debounce acquisition exception-safe through one public static `@internal releaseOwned()` primitive shared by ordinary `release()`, failed acquisition cleanup, and dispatch-context cleanup. If maximum-wait work throws after the token write, call it with the already-resolved cache, key, and owner; never re-enter `debounceVia()` or key generation. The primitive begins removing the owner token and paired `:first_dispatched_at` timestamp only when its owner probe matches. This prevents sequential stale cleanup from deleting known newer state, but the probe and removals are separate cache operations: a concurrent dispatch can replace the owner between them, after which cleanup can remove the newer token and the handler's fail-open path can let both jobs run. `releaseMaxWait()` is also unconditional and runs after the middleware chain, so a concurrent dispatch during middleware can have its inherited timestamp removed and its effective maximum-wait window extended. Debounce is therefore best-effort across concurrent nodes. Do not add a driver-specific atomic path, change the Laravel-compatible cache value/key shape, or impose distributed mutex round trips and blocking on every debounce operation. Attempt both removals and preserve the primary acquisition throwable over cleanup failures; document the narrow cleanup catch so it cannot be mistaken for accidental swallowing. A failed owner probe propagates without attempting either removal.

Queued listeners must reject the unique-plus-debounce combination before either lock is acquired, then register each lock immediately after acquisition and before the next step that can throw. Do not reorder upstream route resolution around debounce acquisition. Their hidden metadata must remain available if the serialized listener command cannot be restored. A unique broadcast registers the wrapper's acquired lock before building the queue connection; put release inside the closure passed to `rescue()` so a swallowed publication failure cannot strand it.

For every immediate, after-response, after-commit, failover, and rollback cleanup, attempt both unique and debounce releases from the recorded provenance. Preserve the operation throwable over cleanup failures; when no operation failed, propagate the first cleanup failure only after attempting both releases. Keep the existing `createJobRollbackCallback()` isolation that attempts the debounce release even when unique release throws. The context delegates both operations to the lock classes' shared `releaseOwned()` primitives and contains no release branch or application callback resolution of its own. Only context-free worker/test cleanup in `CallQueuedHandler` and `QueueFake` continues to use `UniqueLock::release($job)`.

### 2. Payload and enqueue lifecycle

#### Payload construction and single-job enqueue

`Queue::createObjectPayload()` peeks at `DispatchLockContext` to scope Laravel-compatible hidden metadata around create-payload hooks. It never consumes ownership. Record the selected repository's actual nullable store name rather than assuming the configured default; an unnamed custom repository cannot be reconstructed safely from serialized metadata. Serialization, hook, JSON, route, or queue-resolution failures therefore remain owned by the immediate/deferred caller's `finally`.

Move payload finalization, `JobQueueing`, and the transport/storage callback into one guarded pre-acceptance lifecycle in `Queue::enqueueNow()`:

```php
try {
    $payload = $this->finalizePayloadForQueueing(/* ... */);
    $this->raiseJobQueueingEvent(/* ... */);
    $jobId = $callback(/* ... */);
} catch (CanceledException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    // Raise failure only for lifecycle state that actually started.
    throw $exception;
}

$this->acceptDispatchLocks($job);
$this->raiseJobQueuedEvent(/* ... */);
```

A valid encoded payload exists before finalization starts, so `JobQueueingFailed` closes finalizer, queueing-listener, and transport failures for every unaccepted operation. OpenTelemetry must tolerate both finalizer-listener orderings: no-op when no producer state was created, exact/UUID-correlated cleanup when instrumentation ran before another finalizer threw, and no retained state when instrumentation itself threw. Forget ownership immediately after confirmed storage/transport success and before `JobQueued`.

Preserve OpenTelemetry's exact-payload lookup followed by UUID fallback. Do not add retained payload references or change protected queue-event signatures.

#### After-commit and failover delegation

Extract the ownership/delegation block shared by persistent and local queues into `Queue::deferEnqueueAfterCommit()`. Keep `enqueueUsing()`'s Laravel-compatible signature and its callback's `Queue $owner` parameter so pooled drivers borrow a fresh concrete queue after commit. Every caller uses a static closure and invokes the operation on that supplied owner; local callers narrow it to their known concrete queue family for static analysis rather than capture `$this`. The helper installs rollback cleanup, wraps the commit callback with context claim/release, routes through the optional after-commit dispatcher, and delegates responsibility only after callback registration succeeds and only if the record still exists.

Persistent drivers continue to create their payload before `enqueueUsing()`. `SyncQueue` and the timer-backed local queues continue to create payloads inside the registered callback, matching their existing Laravel-shaped timing and keeping hidden dispatch metadata available through commit-time serialization. Do not widen `enqueueUsing()` to accept a payload closure.

`DatabaseTransactionsManager::addCallback()` may run the callback inline when no transaction is applicable. In that case the callback accepts or releases the context before `deferEnqueueAfterCommit()` attempts delegation, so the final delegation is an intentional no-op. Preserve `SyncQueue::push()`'s existing `null` return whenever its after-commit branch is selected; do not add an active-transaction guard merely to change that return value.

Commit callbacks claim the record, attempt publication, and release in `finally` unless acceptance removed it. Rollback callbacks release recorded ownership.

Build rollback release only from `DispatchLockContext` ownership, not merely from `ShouldBeUnique`. This applies to both callers of `createJobRollbackCallback()`:

- ordinary `Queue::addJobRollbackCallback()`;
- `FailoverQueue::deferUntilAllTransactionsCommit()`.

`FailoverQueue` can execute `addCallback()` inline. Set `delegated` only after registration returns and only when the record remains; an inline successful child may already have accepted and removed it. Partial registration failures remain with the immediate owner. Later duplicate rollback callbacks are safe because owner tokens make them no-ops.

Failed failover children leave ownership intact so the next child can accept the same dispatch. `QueuePoolProxy` continues to delegate to the borrowed concrete queue, whose acceptance path owns the mark.

#### After-response delegation

Put after-response ownership in `Bus\Dispatcher::dispatchAfterResponse()`, not `PendingDispatch`:

- the disabled-after-response branch calls `dispatchSync()` directly and leaves the immediate owner responsible;
- the deferring branch registers one callback, then marks an extant record delegated;
- inside the deferred closure, claim the record, call `dispatchSync()`, and release recorded ownership in `finally` if no queue accepted it.

This is the only layer that knows which branch ran and can observe exceptions inside `Coroutine::defer()`, which otherwise reports and swallows them. Do not register a second defer or depend on defer ordering.

#### Driver acceptance points

- Normal drivers: mark accepted after the storage/transport callback returns successfully and before `JobQueued`.
- `SyncQueue`: mark accepted only after `executePayload()` returns successfully.
- A throwing unique sync job can release through `CallQueuedHandler` before the outer dispatch sees failure. The outer exact unique-lock release is intentionally a duplicate no-op. A regression must prove a second owner acquired between those releases survives.
- `NullQueue`: never mark accepted. A pending dispatch releases in its outer `finally`; a direct low-level push has no context and remains a no-op.
- `QueueFake`: recording a job in the `shouldFakeJob()` branch is acceptance. Mark its context accepted after storing the original unique job for `releaseUniqueJobLocks()` and before after-push callbacks. A forwarded job remains owned until the real driver accepts it.
- `BusFake`: recording a command in any of its five fake dispatch branches is acceptance. Mark the original command accepted only after its stored representation succeeds; serialized representations have different object identity. `dispatch()` and `dispatchAfterResponse()` are the current framework paths with live ownership, while the other fake terminals stay behaviorally consistent. Forwarded commands remain owned until the real dispatcher accepts them. Do not add a release registry: accepted faked work intentionally retains its lock as real queued work does.
- Deferred/background immediate work: mark accepted after defer/coroutine registration succeeds.
- Deferred/background delayed work: snapshot exact provenance before timer registration, register the timer, then mark accepted. A registration failure leaves the original owner responsible.
- Database optimized bulk: accept every member only after the single insert or multi-chunk transaction commits.
- Redis optimized bulk: accept every member only after one Lua batch returns the exact prepared-member count. An unconfirmed call may have written an earlier member before failing, so retain the ordinary at-least-once duplicate risk rather than claiming rollback.
- Public bulk dispatch does not acquire unique or debounce locks. The terminal acceptance calls remain so internal callers that already own dispatch provenance receive the same post-storage handoff as single-job paths; accept every member before raising any `JobQueued` event because one throwing listener must not leave later committed members owned.
- SQS optimized bulk: retain current removal of each successful message ID from the unaccepted set before a later chunk can fail. Extend the lifecycle guard to finalization/queueing without reporting accepted entries as failed.

### 3. Deduplicate the timer-backed local queues

Create a small abstract `CoroutineQueue extends SyncQueue` shared by `DeferredQueue` and `BackgroundQueue`. It owns the timer, exception callback, constructor, delayed/after-commit scheduling, common payload-execution wrapper, and delayed shutdown cleanup.

Local queues do not route through `Queue::enqueueNow()`. Doing so would emit `JobQueueingFailed` after a sync job has already failed and place `JobQueued` after sync processing; on delayed queues it would add a new queue-event lifecycle that their immediate path does not have. `SyncQueue::executeJob()` accepts dispatch locks only after `executePayload()` succeeds. `CoroutineQueue` creates delayed payloads when the immediate or after-commit scheduling callback runs, snapshots live provenance immediately before timer registration, registers the timer, and then accepts the live context. Its protected `scheduleTimer()` gains a required nullable snapshot parameter because shutdown cleanup needs provenance captured before registration and the method has no job from which to derive it; this is an intentional protected-signature change.

Keep one concrete `executePayload()` in the base. It wraps `SyncQueue::executePayload()` with the existing cancellation and exception-callback behavior, then calls an abstract strategy hook:

```php
abstract protected function scheduleExecution(Closure $execution): void;
```

`DeferredQueue` supplies `Coroutine::defer($execution)`; `BackgroundQueue` supplies `Coroutine::create($execution)`. Each concrete class otherwise keeps only its default queue name. This preserves constructors, fluent return types, protected behavior, and `instanceof SyncQueue` while removing duplicated logic. Do not use a constructor-bearing trait or attempt the illegal redeclaration of inherited concrete `executePayload()` as abstract.

When the delayed timer callback receives `isClosing: true`, release its captured provenance because the in-memory job will never run. The normal callback leaves release to the serialized job. `Timer::clear()` cancels without invoking the callback; keep a concise source comment so a future caller does not treat clearing as graceful shutdown.

Update the queue guide: graceful shutdown releases locks for delayed local jobs it drops; abrupt termination or mid-execution cancellation can leave a unique lock until `uniqueFor` expires, or indefinitely when it is zero. Persistent queues remain the answer for durable work. Do not build recovery machinery for a deliberately non-durable transport.

### 4. Complete queued-listener debounce behavior

Port the complete current behavior and coverage from #61169 and #61281:

- propagate listener debounce ID and cache selection through `CallQueuedListener`;
- make event dispatch build debounced listeners with current attribute/method semantics;
- reject the already-invalid combination of listener uniqueness and debounce;
- store the acquired debounce owner and choose its delay/max-wait result;
- release the maximum-wait timestamp before real handling so a later dispatch starts a fresh window;
- keep Hypervel's immutable dates, typed attributes, cache `add()` semantics, and cancellation handling where they are stronger.

This is one complete Laravel feature port, not a dependency of the dispatch-context mechanism. Include current queued-event, debounced-job, and debounced-listener tests and proportionate queue documentation.

### 5. Widen `JobQueued` identifiers

Change `JobQueued::$id` and its constructor parameter to `mixed`, matching the Queue contract and current Laravel. Do not convert driver IDs or add a vendor-specific union.

Add a focused mocked Beanstalkd dispatch test in `tests/Queue/QueueBeanstalkdQueueTest.php` that listens for `JobQueued` and asserts the exact Pheanstalk `JobIdInterface` instance is delivered unchanged. Keep the existing scalar/null driver coverage; no external Beanstalkd service is needed.

### 6. Put database capabilities on the physical connection generation

Add two separate concrete methods to `PdoConnection`; do not expand `ConnectionInterface` or the abstract `Connection` base:

- `lockForPopping(): bool|string`;
- `maxBindings(): int`.

`PdoConnection` owns nullable per-generation cache fields, public cached accessors, conservative default resolver hooks, and invalidation in `setPdo()`. `MySqlConnection`, `PostgresConnection`, and `SQLiteConnection` override only the resolver hooks for their engines. The MySQL resolver covers both MySQL and MariaDB by checking `isMaria()` before applying the normalized-version threshold; `MariaDbConnection` inherits it without another override. Every replacement/reconnect path funnels through `setPdo()`; pooled check-in keeps the same PDO and must not invalidate.

Capability rules:

- base/unknown `PdoConnection`: plain `FOR UPDATE` (`true`) and 999 bindings;
- MySQL: `SKIP LOCKED` from 8.0.1;
- MariaDB: `SKIP LOCKED` from 10.6, using `isMaria()` before normalized version handling;
- configured Vitess/PlanetScale: current version threshold and lock clause behavior;
- PostgreSQL: `SKIP LOCKED` from 9.5;
- MySQL/MariaDB/PostgreSQL: 65,535 maximum bindings;
- SQLite before 3.32: 999; SQLite 3.32 and later: 32,766.

Configured version overrides remain authoritative. Do not add a generic registry or combine the two methods into a capability value object.

Keep `DatabaseQueue::getDatabase(): ConnectionInterface`. Consume the PDO capabilities with one contract-compatible fallback for non-PDO implementations:

```php
$connection instanceof PdoConnection ? $connection->lockForPopping() : true;
$connection instanceof PdoConnection ? $connection->maxBindings() : PdoConnection::DEFAULT_MAX_BINDINGS;
```

This avoids a public return-type break and keeps one fallback boundary. Do not duplicate capability methods on `Connection` or engine/version detection in `DatabaseQueue`.

### 7. Make database batch insertion binding-safe and atomic

Replace the unbounded insert in `DatabaseQueue::enqueueBatch()` with `Queue\Concerns\InsertsDatabaseRows`, a narrow queue-owned concern that:

1. derives row capacity as `intdiv(maxBindings, count($firstRow))`;
2. uses one ordinary insert and no transaction when the rows fit;
3. splits oversized input into the minimum number of chunks;
4. wraps every oversized chunk in one database transaction;
5. treats an exception or explicit `false` insert as failure so no partial batch commits;
6. returns only after the transaction has committed, then marks every job accepted and raises success events.

Do not add packet-size guesses or a general database bulk writer. The helper is owned by queue/database insertion and can be reused by the known outbox implementation without containing outbox behavior.

Test exact and one-over limits for every supported engine/version, minimum chunk count, one-statement/no-transaction behavior, multi-statement transaction behavior, false/exception rollback, and real oversized SQLite insertion. Tests must also prove dispatch ownership is retained through rollback and removed only after commit.

### 8. Make Redis queue publication coherent and scalable

#### Script execution primitives

Move `RedisQueue::pushRaw()` and `laterRaw()` from direct `eval()` calls to the existing `RedisConnection::evalWithShaCache()` operation. A cache hit remains one Redis round trip while avoiding repeated script transmission and parsing; only the first call after a Redis restart or script-cache eviction pays the `EVALSHA` miss and `EVAL` fallback. This also turns script errors that phpredis reports as `false` into exceptions before the queue can report acceptance. Do not replace this with `evalsha()`, whose source-based compatibility API performs `SCRIPT LOAD` on every atomic call.

Keep `prepareEvalsha()` and `callEvalsha()` unchanged. In MULTI/PIPELINE mode, the former intentionally sends `EVAL` with the script source because `SCRIPT LOAD` cannot synchronously produce a SHA while commands are queued. On Cluster, the latter already routes through `evalWithShaCache()` and its recursive nil normalization; adding another wrapper would be dead code.

#### One-command Redis bulk

Replace `RedisQueue::bulk()`'s nested PIPELINE/MULTI implementation with one queue-owned `LuaScripts::bulk()` call:

1. return immediately for an empty input;
2. partition immediate and after-commit members before borrowing or queueing Redis work;
3. prepare every immediate member in input order, including payload finalization and `JobQueueing`;
4. encode each member as two `ARGV` values: literal `i` plus payload for immediate work, or its numeric availability timestamp plus payload for delayed work;
5. execute one `evalWithShaCache()` against the hash-tagged ready, notify, and delayed keys;
6. require the returned integer to equal the prepared-member count;
7. only after that confirmation, accept every dispatch lock and raise `JobQueued` in original order;
8. register the after-commit group only after its rollback releases are installed, then publish it through the manager-level `RedisProxy`, never a borrowed connection.

The Lua loop must use one `redis.call` per storage operation. Each immediate member receives one ready-list write and exactly one notify token; each delayed member receives only its sorted-set write and obtains its notification when migration runs. Do not combine values with `unpack`: large batches can exceed Lua's C-stack limit, which is why the existing migration script chunks its own `unpack` calls.

Successful execution has one completion shape: the exact count confirms every prepared member. If `redis.call` raises, Lua aborts the remaining loop and returns no count, but Redis does not roll back earlier writes. A throw, `false`, or wrong count therefore confirms none and enters the existing failure/release lifecycle, with the same unavoidable at-least-once duplicate risk as a lost response from a single publish. Do not add `redis.pcall`, per-member result mapping, a vector-shape guard, or a claim of all-or-nothing publication.

Do not impose an invented batch limit. Database placeholder limits are fixed and knowable; Redis's per-argument and whole-query limits are deployment-configured. The current standalone bulk already sends the complete batch in one pipeline buffer, while both MULTI `EXEC` and Lua execute a batch without interleaving. Document the Redis protocol/query limits and busy-script visibility as operational boundaries, not as a new regression or a reason for arbitrary chunking.

Keep Horizon integrated through two narrow protected hooks around the batch storage call. The preparation hook returns the possibly reclassified and timestamped payload and raises guarded `JobPending`; the success hook raises guarded `JobPushed` only after the exact count returns. Base implementations are no-ops. Write the batch command directly rather than routing through Horizon's overridden `pushRaw()`, which would classify twice and emit success before batch completion.

This intentionally differs from Laravel's nested Redis transaction. Record the difference in the Queue README: Hypervel uses one same-slot Lua call so Cluster bulk dispatch remains one round trip and all topologies expose the same confirmation shape.

### 9. Execute raw sync-family payloads

Implement `SyncQueue::pushRaw()` as:

```php
return $this->executePayload($payload, $queue);
```

Dynamic dispatch preserves each concrete queue's timing through `CoroutineQueue::executePayload()`. Add direct raw tests for sync, deferred, and background queues, plus a public `queue:retry` flow proving successful execution deletes the failed record while thrown execution preserves it. Do not retain Laravel's silent no-op.

Treat this as a correctness fix, not a lasting compatibility mode: a public method whose contract is to push a raw payload must not silently discard it. Add no README or porting-guide entry for preserving Laravel's defective no-op.

### 10. Correct Horizon payload classification and publication timing

Keep public `JobPayload::prepare($job)` as the composition of two narrower operations:

- `prepareForQueueing($job)`: classify type, tags, and silenced state while the original object is available;
- `markAsPushed()`: set only `pushedAt` at actual Redis publication.

Rules:

- Immediate object dispatch uses the existing coroutine-local original-job handoff and performs one decode/encode at publication.
- Delayed object dispatch classifies inside the actual enqueue callback, so after-commit waiting does not predate classification/publication.
- With an original job object, classify and stamp.
- Without an object, an existing Horizon `type` means the payload is already classified: preserve its stored type/tags/silenced values and refresh only `pushedAt`.
- Without an object or stored classification, retain current raw defaults and stamp.
- `JobPending` sees the final timestamped payload; `JobPushed` remains after Redis accepts it.
- Always clear original-job context on success, failure, and cancellation.

Cover prepare composition, immediate/delayed after-commit timing, raw unclassified payloads, Horizon retry and `queue:retry`, stored classification/tags/silence preservation, refreshed timestamps, event ordering, confirmed and failed Redis batches, and context cleanup. This has no matching Horizon PR; after the Hypervel fix is verified, prepare a separate upstream Horizon report/PR only with owner approval.

### 11. Bound worker and signal callback resource lifetimes

Use existing owned-coroutine primitives; do not add another executor abstraction.

#### Worker loop

- Make `WaitConcurrent::wait()` represent full coroutine completion. At child entry, register `WaitGroup::done()` through `Coroutine::defer()` before running the body; Swoole's LIFO defer order then runs body-owned resource cleanup before the completion signal. Keep the active-coroutine marker scoped only around the body so `cancel()` still cannot interrupt deferred cleanup. Preserve the existing creation-failure balance path without adding a defensive registration flag: the wrapper always runs inside the native child, so defer registration cannot fail through supported use. Reordering PHP wrappers is insufficient because `ConnectionResolver` cleanup runs only after every wrapper frame through its own defer.
- Run each callback-bearing or resource-touching worker step in its own synchronous short-lived owned child and return only materialized values. Daemon flow, timeout monitoring, and graceful shutdown each own a local unbounded lifecycle `Waiter`; do not store one on the worker, reuse the protected independently overridable pop waiter, or add another protected waiter factory.
- Keep sleeps and capacity waits in the daemon after the preceding child has terminated. A paused iteration is the exception: invoke the existing protected `pauseWorker()` in a second child because its Laravel-shaped contract combines the sleep with a restart-cache read. This preserves the extension point while releasing maintenance and `Looping` listener resources before the sleep.
- An active iteration uses separate preflight, pop/idle, and post-pop stop-check children. Do not merge the stop check into the pop child or carry prior-pop state to a later iteration: the check must observe job admission and the current concurrent-job state while preserving existing event and stop order.
- Keep blocking queue pop in its existing separate child so preflight resources are returned before pop blocks. Newly apply `withCoroutineContext()` inside that child around the pop and idle handling, so `JobPopping`, `JobPopped`, and `WorkerIdle` listeners receive the configured worker context consistently with job execution.
- Move `WorkerIdle` into the pop child after a null result.
- Run `WorkerStarting` in a short-lived owned child. Keep `WorkerStopping` in the daemon coroutine because return/process exit follows immediately.
- Create the owned child first, then call `withCoroutineContext()` inside it without copying parent context. Applying context to the daemon/timer before creating a fresh child silently loses the configured values, while copying the parent would import accumulated daemon state. This intentionally gives `WorkerStarting`, `Looping`, pop, and idle listeners the configured worker context they do not currently receive.
- Preserve `Waiter` cancellation semantics: owner cancellation remains `CanceledException`, while cancellation of a child whose owner is still active remains `ChildCancellationException`.
- Replace Worker raw `usleep()` with fakeable `Sleep::usleep()`.

The timeout monitor needs the same boundary. `Timer::tick()` owns one loop coroutine, and its callback can write failed-job records and call arbitrary `JobTimedOut` listeners. Run each tick body synchronously in a fresh owned child, with `withCoroutineContext()` inside it. Thread `connectionName` and `queue` from `daemon()` into `monitorTimeoutJobs()` so timeout-driven stopping carries #61408 context.

#### Signals and shutdown

Worker PCNTL handlers perform only state mutation and append compact ordered signal records. They must not dispatch events or call `Interruptible` user code. A source comment must state the real precondition: `queue:work` is a console process where `SignalManager` is not registered; embedding it in a Swoole server worker/process would make PCNTL and `System::waitSignal()` compete for the same signals.

Drain signal records in order in fresh owned children during normal daemon flow. For each interrupting signal, notify the running jobs and emit guarded `JobInterrupted` once per job actually notified. Preserve the existing worker-level pausing, resuming, and interrupted events and their ordering.

Use exactly three drain points:

1. loop top for normal operation;
2. a shutdown loop that drains, calls `WaitConcurrent::wait()` with an owning 100 ms Worker constant, and repeats only while that wait times out;
3. once after jobs finish and before `WorkerStopping`.

Use exactly these three drain points; add no post-pop drain or redundant concurrency-channel check. Successful `WaitConcurrent::wait()` now proves the slot is released and deferred cleanup completed. The next loop top handles normal signals and every graceful terminal path uses the shutdown helper. The timeout monitor's hard-kill path intentionally skips draining and waiting because the jobs that failed to terminate would keep the shutdown loop alive. Let `CanceledException` pass through the timed wait unchanged.

`SignalManager` keeps native signal waiting and `SafeCaller`, but each received application-handler batch runs in a fresh owned child. Allocate that child only after a signal is received.

#### Worker events

Add current `JobInterrupted` source and test intent from #61412. Update `WorkerStopping` for #61408 with Laravel's positional layout:

1. existing six fields;
2. nullable `connectionName` at position 7;
3. nullable `queue` at position 8;
4. Hypervel's existing `terminatesImmediately` boolean moved to position 9.

Existing framework calls already name the boolean. Propagate connection/queue through every daemon stop and kill path. Guard optional events before object/child allocation.

### 12. Reconcile the application skeleton in its own repository change

Create a branch/worktree from `contrib/hypervel/hypervel` only when implementing this step. Compare every skeleton config with current `src/foundation/config` and reconcile the actual current values and shapes:

- add missing `broadcasting.php`, `concurrency.php`, and `signal.php`;
- update all differing shared files (`app`, `auth`, `cache`, `database`, `filesystems`, `hashing`, `logging`, `mail`, `queue`, `rate-limiter`, `server`, `session`, and `view`);
- omit Foundation's `App\Models\User` PHPStan suppression because the skeleton owns that class and the suppression is unmatched there;
- preserve app-owned `services.php` and the already-matching `cors.php`;
- remove obsolete Beanstalkd/SQS pool shapes that `PoolOptions::fromArray()` rejects;
- remove dead `QUEUE_CONCURRENCY_NUMBER` use and expose the canonical `queue.concurrency` configuration;
- add no outbox configuration or migration.

Add focused inventory and constructibility tests. Do not require byte-for-byte equality where a real application-specific setting belongs in the skeleton.

### 13. Documentation

- Put Queue, Bus, and Horizon READMEs into the required order: header/badge, meaningful docs link, valid `Differences From Laravel`, upstream link last. Record the Redis Lua bulk transport difference and preserve every other real difference; do not describe ordinary correctness fixes as differences.
- Update queue documentation for `UniqueJobSkipped`, listener debounce, `JobInterrupted`, Worker stopping context where useful, and the local delayed-queue shutdown limits described above. State in both queue and event documentation that multi-node debounce is best-effort rather than an exactly-once guarantee.
- Update the coroutine guide to state that successful `WaitConcurrent::wait()` includes child deferred cleanup, while `cancel()` continues to target active bodies only.
- Update `docs/todo.md` so the general backend identity/capability work remains tracked, but remove the sentence that names Database Queue `SKIP LOCKED` as an unresolved consumer after connection-owned detection lands.
- Add no porting-guide entry for bug fixes, internal performance work, or Laravel-parity additions.

## Testing plan

Extend existing test classes in their logical locations. Add a new class only when a distinct integration boundary has no suitable owner.

### Dispatch locks and lifecycle

- `tests/Bus/BusPendingDispatchTest.php`, `QueueableTest.php`, lock/context tests: initialized owner tokens, skipped event, WeakMap behavior, abandoned objects, coroutine isolation, single invocation of user-controlled lock resolvers, manager-resolved and unnamed repository metadata, non-empty recorded owners, stale-owner release after reacquisition, acquisition cleanup, failed debounce ownership probes, sequential owner-guarded debounce cleanup, primary-versus-cleanup exception isolation, after-response enabled/disabled, and exact unique-owner expiry/reacquisition. Do not encode the concurrent debounce race as desired behavior.
- `tests/Integration/Console/UniqueJobSchedulingTest.php`: scheduling success/failure, exact cleanup, and Bus-fake acceptance that retains duplicate suppression.
- `tests/Events/QueuedEventsTest.php` and `tests/Integration/Broadcasting/BroadcastManagerTest.php`: route/finalization/publication failures release recorded unique/debounce ownership; unserializable queued listeners retain hidden release metadata; `ShouldRescue` cannot strand a unique broadcast lock; successful publication leaves release to job handling.
- `tests/Queue/QueueSyncQueueTest.php`, `QueueDeferredQueueTest.php`, `QueueBackgroundQueueTest.php`, `QueueNullQueueTest.php`, `FailoverQueueTest.php`, and `QueuePoolProxyTest.php`: every acceptance/delegation path, commit-time payload creation, timer shutdown snapshot, registration failure, direct low-level push, inline no-transaction callback with no stranded/delegated record, preserved sync after-commit return value, partial registration, and the sync duplicate-release invariant.
- Queue fake coverage preserves one accepted unique push within a test, releases it through `releaseUniqueJobLocks()`, and leaves forwarded-job acceptance to the real connection.
- Bus fake coverage accepts registered ownership in all five fake terminals, uses the original command identity when storing a serialized copy, leaves ownership intact when serialization fails, preserves unique suppression for pending and scheduled dispatch, and retains debounce maximum-wait provenance. Keep `DispatchLockContext` in the testing subscriber's alphabetically ordered static-cleanup list.
- Queue driver/bulk tests: finalizer, queueing listener, transport, cancellation, partial SQS success, database commit/rollback, and throwing `JobQueued` listener.
- Redis queue unit and real standalone/Cluster tests: single push/later use of the SHA-cache helper, one script call per batch, exact separated key/argument arrays, mixed immediate/delayed ordering, one notify token per immediate member, exact-count acceptance, pre-storage lifecycle failure, script failure with earlier writes left visible and later writes absent, after-commit partitioning, and Horizon event ordering.
- Keep cache-hit and NOSCRIPT behavior owned by the existing `EvalWithShaCacheIntegrationTest`, which guarantees a miss with unique script source. Do not use server-global `SCRIPT FLUSH` or duplicate the helper's fallback tests through each queue script. Preserve the intentional queued `evalsha()`-to-`eval()` behavior.
- `tests/OpenTelemetry/Instrumentation/QueueInstrumentationTest.php`: terminal state for finalizer/queueing/transport failures, both finalizer-listener failure orderings, accepted success-listener failure, exact payload/UUID fallback, and no retained producer state.
- Current upstream queued-event, unique-job, unique-until-processing, debounced-job, and debounced-listener coverage adapted into existing Hypervel suites.

### Drivers, database, and Horizon

- `tests/Queue/QueueBeanstalkdQueueTest.php`: direct dispatch through a mocked Pheanstalk publisher, proving the exact `JobIdInterface` object reaches `JobQueued` without requiring an external service.
- Database queue and `PdoConnection` unit tests for capability thresholds, cached read counts, `setPdo()` invalidation, conservative non-PDO contract fallbacks, chunk boundaries, transaction choice, and failure handling.
- Real SQLite oversized batch integration; existing database workflow runs supported engine-specific behavior where relevant.
- Direct raw sync/deferred/background tests and public retry-command success/failure tests.
- Horizon unit and Redis integration tests for classification, delayed/after-commit timestamps, raw/retry preservation, event ordering, partial batch behavior, and context cleanup.

### Worker and signals

- `tests/Coroutine/WaitConcurrentTest.php`: timed wait remains false while completed-body deferred cleanup is blocked, `cancel()` does not interrupt that cleanup, and wait succeeds after cleanup finishes. Preserve the existing creation-failure balance test.
- `tests/Queue/QueueWorkerTest.php`: start/preflight/pop/idle/timeout/stopping resource release, configured context visible to pop/idle events, signal order, per-job interruption, stopping context, shutdown wait race, cancellation, and zero unnecessary child/event work when no listener exists.
- Tiny database-pool integration coverage proves each framework callback returns its borrowed resource before the daemon continues or waits, and that a stopping listener can borrow from a one-slot pool after an admitted job's deferred cleanup.
- `tests/Signal/SignalManagerTest.php` and related non-coroutine/rollback tests prove handler batches run in owned children while native wait, SafeCaller behavior, order, cancellation, and cleanup remain intact.

### Skeleton and metadata

- In the skeleton repository, verify required config inventory, canonical replaceable connection shapes, and construction of every shipped queue connection record.
- Package metadata/README tests remain green after documentation order changes.

## Verification workflow

1. Before each implementation item, re-read its source, tests, package README, and current upstream files named by the relevant pull request.
2. After updating or adding each test file, run that file immediately with `./vendor/bin/phpunit --no-progress ...`.
3. After each coherent package slice, run the affected package or focused suite.
4. Run `composer fix` once after all Components work is complete.
5. Review the full diff through every caller/callee, failure edge, cancellation path, worker-lifetime state, public signature, and hot path. Remove stale code/comments and unnecessary abstraction.
6. Request code review and resolve every finding to signoff.
7. Verify the separate skeleton branch with its repository commands and review it independently.

## Completion criteria

- Every acquired dispatch lock has one exact owner and no supported rejection, rollback, null-driver, graceful local-shutdown, or pre-acceptance failure path strands it.
- Unique-lock cleanup cannot remove a newer owner on any supported store where cross-node interleaving is possible. Debounce cleanup protects observed newer owners but is explicitly best-effort under concurrent dispatch and cleanup.
- All eight named upstream PRs are completely accounted for against current upstream source/tests/docs.
- Queue events and OpenTelemetry state have one coherent terminal lifecycle; accepted jobs are never reported failed.
- Ordinary database batches remain one insert without a transaction; oversized batches use the minimum atomic chunk count.
- Database capability probes occur once per physical connection generation and recompute after replacement.
- Raw sync-family payloads execute with their intended timing.
- Redis single and bulk queue scripts use the source-aware SHA cache; Cluster bulk publication is one same-slot request, and dispatch acceptance follows only an exact completion count without promising rollback of earlier Redis writes.
- Horizon classification is preserved while `pushedAt` reflects actual Redis publication.
- Worker and signal callbacks cannot pin pooled resources in process-long coroutines; `WaitConcurrent` does not report completion before deferred cleanup; and shutdown cannot spin or deadlock waiting for work or an interruption callback it has not delivered.
- Public Laravel-shaped APIs remain compatible; the only widening is `JobQueued::$id`, and `WorkerStopping` gains Laravel-positioned nullable context while retaining Hypervel's named boolean.
- The application skeleton uses constructible current Foundation config without future outbox settings.
- Documentation contains no stale or duplicate guidance.
