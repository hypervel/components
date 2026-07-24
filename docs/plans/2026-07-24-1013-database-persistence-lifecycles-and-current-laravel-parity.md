# Complete Database Persistence Lifecycles and Current Laravel Parity

## Status

Plan drafted after the Database audit and pre-implementation second-opinion consensus. Owner approval has been received for every additive capability and performance-sensitive change in that consensus. A fresh source, test, documentation, metadata, lifecycle, performance, and overengineering review added `redis-06` through `redis-08`, and independent plan review signed off the complete design. After sign-off, Hyperf issue #7733 and pull request #7734 exposed one missed `redis-03` defer-retention path; focused source review and second-opinion consensus settled the bounded amendment below. Refreshed independent plan review signed off the amendment. During the full validation gate, Laravel 13's model boot guard exposed stale pre-publication model construction in the current Scout and NestedSet consumers; focused upstream review and second-opinion consensus added the bounded compatibility corrections in Section 10. Fresh post-implementation review added two concise migration-guide notes for the new schema-dump option and migration-event name while deliberately excluding low-level exception metadata and lazy-value overload inventory, and closed one missed transaction failure-precedence path already required by Section 8. Implementation, the full validation gate, fresh self-review, audit-record updates, and independent post-implementation code review are complete.

## Scope

Complete the `database` package audit as one coherent persistence work unit. The work includes the lowest owning Core, Server, Redis, Pool-consumer, documentation, and metadata boundaries required to fix the verified Database defects completely.

The final implementation must:

- release exact Database and Redis leases at the end of non-coroutine Swoole tasks;
- prevent parent-created Database and Redis resources from crossing Swoole process forks;
- bound Redis same-connection terminal cleanup to one defer per pool per coroutine while preserving immediate callback-form release;
- make Redis command-event cleanup failure-safe and preserve exact connection ownership for optimistic transactions;
- make Redis release safe for abandoned native transaction, pipeline, and watch state;
- give one physical in-memory SQLite PDO exactly one concurrent owner;
- classify all supported SQLite memory and URI forms consistently;
- keep logical transactions, physical PDO transactions, transaction-manager records, callbacks, events, and pooled reuse truthful on every success and failure path;
- serialize first Eloquent model boot only while publication is incomplete, without rejecting legitimate sibling coroutines;
- port the complete supported current Laravel Database surface discovered through the relevant originating changes;
- apply the two approved current Laravel Database performance corrections;
- make split-package metadata, provenance, intentional omissions, and user-facing documentation complete;
- preserve Laravel-facing call shapes and extension points unless a verified Swoole/coroutine requirement demands an adaptation;
- remove the reflective task-worker cleanup and every stale helper, comment, test assumption, or dependency it leaves behind.

This is not a redesign of Database around a new transaction engine, cleanup registry, lease proxy, shared SQLite cache, or generic lifecycle framework. Each accepted mechanism has a demonstrated owner and a concrete production failure.

## Desired final architecture

| Surface | Final owner and lifetime |
|---|---|
| Swoole task completion | `TaskCallback` owns a final `TaskTerminated` phase after action dispatch and result finishing |
| Non-coroutine Database task leases | `ConnectionResolver` retains the exact borrowed `PooledConnection` wrappers until `TaskTerminated` |
| Non-coroutine Redis task leases | Each `RedisProxy` owns its context identity; `RedisManager` exhausts already-created proxies at `TaskTerminated` |
| Coroutine Redis same-connection leases | Each `RedisProxy` records the coroutine ID owning at most one terminal deferred release per pool; callback-form operations still release newly pinned wrappers immediately, and copied child context cannot inherit false ownership |
| Final parent pre-fork boundary | `Server::start()` dispatches `BeforeServerFork` immediately before native `start()` |
| Database process cleanup | One Database lifecycle listener discards exact resolver leases and independently flushes already-resolved pools |
| Redis process cleanup | One Redis lifecycle listener discards exact proxy leases and independently flushes already-resolved pools |
| Redis pooled release | `RedisConnection` verifies native queue mode before any database restore or requeue |
| SQLite classification | Internal `SQLiteDatabase` is the single URI and in-memory classifier |
| In-memory SQLite ownership | Existing pool channel serializes one wrapper around one shared PDO |
| Transaction state | `Connection` owns physical/logical state; `DatabaseTransactionsManager` owns detached callback records |
| Eloquent first boot | Per-model owner ID plus the existing class-keyed `Mutex` until boot publication and post-publication hooks complete |
| Current Laravel parity | Current local Laravel `13.x` source/tests/docs, adapted only for supported drivers and Hypervel runtime rules |
| Package provenance and omissions | Database README, split manifests, source `REMOVED:` markers, metadata test, and Boost guides |

## Finding summary

| ID | Category | Severity | Verified failure | Final boundary |
|---|---|---:|---|---|
| `database-05` | Defect | Major | Default non-coroutine task workers never release pooled Database wrappers; raw context, transaction, and session state persist into later tasks | Add `TaskTerminated`; retain exact non-coroutine wrappers in `ConnectionResolver`; release them terminally |
| `database-06` | Defect | Major | Database pools, PDOs, timers, channels, and pinned fallback context can be created before Swoole forks and inherited by every process | Add the final `BeforeServerFork` boundary; discard resolved leases and flush resolved pools before fork and at child start |
| `database-07` | Defect | Major | The in-memory SQLite pool advertises multiple independent wrappers for one physical PDO | Normalize valid in-memory pool capacity to one and use the existing pool channel as the only serialization primitive |
| `database-08` | Defect and parity defect | Major | Four inconsistent SQLite checks miss valid URI memory forms, reject valid file URIs, and can truncate a literal URI filename instead of the attached database | Add `SQLiteDatabase`, accept current SQLite URI forms, derive the canonical attached path, and check truncation |
| `database-09` | Defect, parity defect, and upstream defect | Major | Transaction listeners, physical failures, manager callbacks, disconnects, and nested rollbacks can publish false state, strand callback records, or requeue corrupt pooled sessions | Separate phases, detach manager records before callbacks, clean up before retry, exhaust rollback cleanup, and preserve the earliest throwable |
| `database-10` | Defect, parity defect, and upstream defect | Major | Eloquent publishes `$booted` before boot finishes; recursion sees incomplete state, exceptions permanently poison boot state, and sibling coroutines race | Track the exact boot owner and lock only first publication per model class |
| `database-11` | Supported current Laravel parity | Major/Minor | Current Query, Eloquent, Schema, migration, connector, provider, exception, and metadata behavior is absent or stale | Port the complete current supported upstream surface discovered through originating changes |
| `database-12` | Performance improvement | Improvement | Ordinary attribute reads merge every cached cast; raw SQL substitution removes bindings quadratically | Port current Laravel's per-key cast merge and indexed binding substitution |
| `database-13` | Metadata and documentation defect | Major | Split dependencies, provenance, deliberate deprecated omissions, and accepted public APIs are incomplete or undiscoverable | Correct manifests, add metadata coverage, record omissions, and update task-first guides |
| `redis-03` | Cross-package defect | Major | Non-coroutine same-connection commands call coroutine defer outside a coroutine and pin the lease across tasks; repeated callback-form operations in one long-lived coroutine immediately release their wrappers but retain one redundant defer closure per call until coroutine exit | Gate non-coroutine defer registration, register at most one terminal defer per pool per coroutine, preserve immediate callback release, and release exact proxy-owned task context at `TaskTerminated` |
| `redis-04` | Cross-package defect | Major | Abandoned native MULTI/PIPELINE clients are requeued and silently queue commands for the next borrower | Detect native mode at release and discard terminally |
| `redis-05` | Cross-package defect | Major | Redis sockets, heartbeat timers, pools, and pinned fallback context share Database's pre-fork inheritance defect | Consume `BeforeServerFork` and `BeforeWorkerStart` through exact discard plus resolved pool flush |
| `redis-06` | Cross-package defect | Major | A throwing `CommandExecuted` listener exits the proxy's `finally` block before release or context handoff, leaking the borrowed pool slot | Capture event failure, complete the same ownership handoff or cleanup, then preserve existing Laravel event-failure precedence |
| `redis-07` | Cross-package defect | Major | `WATCH` returns its wrapper to the pool immediately, so the later `MULTI`/`EXEC` can use another client and a watched client can leak into the next borrower; callback-form transaction completion bypasses wrapper state updates | Pin successful `WATCH`, track its native state on the owning wrapper, clear it on successful terminal commands including callback-form transactions, and discard an abandoned watch |
| `redis-08` | Cross-package parity and type defect | Major | `Redis::discard()` resolves the inherited pool-lifecycle `discard(): void` instead of phpredis `DISCARD`, destroying the wrapper and returning null while the transaction context still points at it | Give the wrapper a distinct internal native-discard method and route only the Laravel-facing proxy command through it |

## Backing research and fixed assumptions

### Current upstream workflow

The originating Laravel changes below are discovery history: they identify the complete source, test, fixture, facade, contract, and documentation surface introduced with each feature or correction. The implementation reference is the current local Laravel `13.x` checkout at `examples/laravel/framework`, currently `23e9e71f38`.

Do not copy historical diffs as the final source. Re-open each current upstream file when implementing it, preserve current relative member order, and merge current tests with Hypervel-specific coverage.

The documentation reference is the current local Laravel Docs `13.x` checkout at `examples/laravel/docs`, currently `ce4a1bf093`. It documents `foreignUuidFor()`, `whereNullSafeEquals()` / `orWhereNullSafeEquals()`, `withoutRelation()`, and `#[RouteKey]`. The RouteKey documentation has a dedicated current-history change (`559641a399`); the other three entered the local `13.x` history through its bulk section creation (`0d61029ee4`), so that checkout exposes no narrower documentation change to use for discovery. It does not currently document the other accepted public additions in this plan. Port current documented wording as the reference where it exists and add proportionate Hypervel documentation for the remaining accepted APIs.

#### Transaction corrections

| Commit | Discovery surface |
|---|---|
| `f344339df5` | Physical rollback before retrying commit deadlocks; `ManagesTransactions`, `DatabaseConnectionTest` |
| `b5b0d6b52d` | Transaction-manager cleanup on disconnect; `Connection`, `DatabaseTransactionsManager`, `DatabaseConnectionTest` |
| `1ef91c00b2` | Rollback callbacks for committed nested records; manager source and tests |

#### Query corrections and additions

| Commits | Current behavior |
|---|---|
| `7c9e306c3d` | MySQL straight joins |
| `8d8465a26e` | Null-safe equality and relationship use on supported grammars |
| `8365873987` | `inOrderOf()` |
| `039787fe2f` | MySQL query timeout hint |
| `95ef41ad58`, `fdbdddf6ed`, `842bbaa105`, `8ed1300f1e` | Current `insertOrIgnoreReturning()` signature, validation, modified-record behavior, and multi-column conflicts |
| `e8f4b59f10` | `DatePeriod` between bounds |
| `4075266d62` | `whereColumn()` question-mark escaping |
| `4d83904b41`, `fe2afc066c` | PostgreSQL date/time expressions |
| `df19aecd0d` | Delimited aggregate aliases |
| `f9ac81f5ab` | PostgreSQL precomputed `tsvector` full-text queries |
| `57eb459c65` | Eloquent builders and relations as update subqueries |
| `2a60ae7150` | Relative-date integration coverage; current Hypervel source already supports the behavior |

#### Eloquent corrections and additions

| Commits | Current behavior |
|---|---|
| `8c6960875d`, `56a36ae2e2`, `34da7b5d27` | Instance-scoped increment/decrement-each and quiet forwarding |
| `6f27470d15` | `saveOrIgnore()` |
| `a06041f10a` | `withoutRelation()`; source and existing docs revalidation |
| `9baf8d82ea` | `#[RouteKey]` |
| `c1e239dc34` | Related key use in `BelongsToMany::touch()` |
| `eb473d3c37` | `restored` only after a successful soft-delete restore save |
| `26f92f2e4a` | Private attributed scopes |
| `65d16321e4` | `MorphTo` eager matching with null owner key and non-primitive result keys |
| `6ce926d755`, `10539d466c` | Closure values across create-or-first, first-or-new, and update-or-create paths |
| `4ca4a16772` | Nested scope removal |
| `651ead2721` | Multiple-column touching |
| `4ce9f70950`, `c0dc1d5ff9` | Enum model IDs in `ModelNotFoundException` |
| `8843a5e3f9` | `casts()` Stringable PHPDoc |
| `6822896080` | `AsEncryptedArrayObject::ARRAY_AS_PROPS` |
| `23e9e71f38` | Trait-carried class attributes |
| `871351c009` | Full model path in resource naming |

#### Schema, migration, connector, and provider corrections

| Commits | Current behavior |
|---|---|
| `7e5320693d` | `foreignUuidFor()` |
| `979601b173` | `hasForeignKey()` and facade metadata |
| `277daca775` | PostgreSQL `tsvector` columns |
| `fbb3f5344f` | MariaDB vector indexes |
| `2d8e8f4015` | Unique-constraint index and column metadata |
| `fefc53a93f` | Migration event names |
| `c0f75327bc` | Schema dumps without migration data |
| `566f2c4d9c`, `39cb3f2fb4` | SQLite URI support and standalone `base_path()` guard |
| `cf6681c426` | PostgreSQL pre-9.1 collation handling |
| `278fcd781e` | `model:prune` option validation typo |
| `dcf70c4b19`, `c0567a68aa` | Current lost-connection messages |
| `6575242feb` | `migrate:fresh` missing-database behavior |
| `b2dcd15f34` | MariaDB client detection |
| `fbc03bac3e` | PostgreSQL custom-schema sequence starts |

#### Approved performance corrections

| Commit | Current behavior |
|---|---|
| `391d540182` | Merge only the requested cached cast on ordinary attribute access; retain full merges where mutators can inspect siblings |
| `e4223623a0` | Use a binding index rather than `array_shift()` per raw SQL placeholder |

### Hyperf Redis lifecycle correction

Hyperf issue #7733 and merged pull request #7734 are discovery evidence for the callback-form defer-retention defect. Hyperf's final correction uses an eager-release context marker to suppress defer registration while preserving immediate release from callback-form pipeline and transaction operations. Hypervel keeps that ownership contract but uses the lower registration boundary: one terminal deferred release per Redis pool per coroutine.

The immediate-release behavior is non-negotiable. Deferring callback-form release until coroutine exit can exhaust a small Redis pool in long-running or highly concurrent coroutines. The single terminal defer no-ops when no wrapper remains pinned, but it also owns any later raw same-connection pin in the same coroutine. This removes per-call closure growth without a callback-specific marker or another release path.

Swoole 6.2.2's `swoole_coroutine_defer` has no catchable failure after a valid callable is accepted inside a live coroutine. Register the terminal defer before publishing its owner ID. This establishes ownership by statement order without a rollback branch; a non-positive coroutine ID excludes the outside-coroutine defer failure.

### Verified Hypervel runtime facts

- `server.settings.task_enable_coroutine` defaults to `false`.
- Swoole executes default non-coroutine task-worker callbacks sequentially within one task worker.
- `ConnectionResolver` currently installs deferred release only in a coroutine. The bare `Connection` retains the wrapper incidentally through the bound reconnector closure, but nothing owns release in non-coroutine tasks.
- `RedisProxy` currently calls `Coroutine::defer()` after successful `multi`, `pipeline`, or `select` even outside a coroutine; native Swoole rejects that call.
- Each callback-form `pipeline()` or `transaction()` with no existing pin currently registers a fresh terminal defer in `RedisProxy::__call()`, then `MultiExec::executeMultiExec()` immediately releases and forgets the wrapper. Repeating the operation in a long-lived coroutine therefore retains one additional no-op closure per call until coroutine exit.
- `Coroutine::fork()`, `go(..., true)`, and `parallel(..., copyContext: true)` copy scalar context values into a child but do not copy the native defer stack. A boolean deduplication flag would therefore create a false owner in the child. Storing the owning coroutine ID makes the inherited parent value stale and forces the child to register its own defer.
- `RedisProxy::withPinnedConnection()` is defer-free and releases its own newly pinned wrapper in `finally`; it does not share the accumulation defect.
- `RedisProxy` dispatches `CommandExecuted` before its release/context-handoff branch inside one `finally`; a throwing listener therefore skips ownership cleanup. `CommandFailed` listener failure already replaces the command failure as current Laravel does, while the wrapper is still released.
- `RedisProxy::shouldUseSameConnection()` omits `watch`, although Redis optimistic transactions require `WATCH`, the intervening reads, `MULTI`, and `EXEC` to use one native client. Native phpredis `getMode()` remains `Redis::ATOMIC` while only watched, so queue-mode detection cannot discover this state.
- `RedisConnection` inherits the pool lifecycle method `discard(): void`. Consequently, `RedisProxy::__call('discard')` never reaches phpredis `DISCARD`; it removes the borrowed wrapper from pool ownership, returns `null`, and leaves the transaction context referring to a destroyed wrapper. Callback-form transactions call `discard()` on the native phpredis object and are unaffected.
- Callback-form Redis transaction/pipeline operations release a newly pinned connection immediately in `MultiExec::executeMultiExec()`. One pool-scoped terminal defer may later no-op or release a subsequent raw pin, but callback completion never waits for coroutine exit. A callback-form transaction that reuses a wrapper pinned by `WATCH` calls native `exec()` directly, so `MultiExec` must clear the wrapper's watch flag after that native call returns successfully.
- `RedisConnection::release()` can send `SELECT` while restoring the configured database. Fork cleanup must discard, not release, inherited sockets.
- `PhpRedisConnection` and `PhpRedisClusterConnection` expose the authoritative native mode through `getMode()`; this is local extension state, not a Redis network command.
- `Pool::discard()` validates exact borrowed ownership. `destroyConnection()` contains native close failure and always removes managed/borrowed bookkeeping and signals capacity.
- `BeforeMainServerStart` runs during first-port setup. `BeforeServerStart` runs per port after its configured callback. Neither is a final post-listener pre-fork boundary.
- `PoolFactory` is an auto-singletoned resolvable concrete for both Database and Redis and can own pools even when the canonical manager key is unresolved.
- SQLite `pragma_database_list` returns an empty main path for in-memory databases and a canonical filesystem path for file-backed URI connections.
- Native SQLite probes confirm lowercase `file:` handling, percent-decoded paths/query values, case-sensitive `mode`, and last-value-wins duplicate `mode` behavior.
- Current Laravel splits RefreshDatabase's aggregate and named in-memory checks, but its named check still recognizes only literal `:memory:`. Hypervel must route that live refresh-connection hook and every named transaction connection through the settled SQLite classifier so mixed file/memory connection sets cache only their physical memory PDOs.
- `Filesystem::put()` returns `int|false`; it does not guarantee an exception on write failure.
- In-memory `DbPool` deliberately shares one PDO among wrappers. Capacity greater than one therefore advertises owners that are not physically independent.
- `Mutex` is a class-keyed worker-static `Channel(1)` map. Test cleanup closes Mutex channels before resetting `Model`.
- Hypervel's class-attribute cache stores the attribute object and selects the requested property after lookup, so Laravel change `#60815` does not apply.

### Approved owner gates

The owner approved:

1. additive Core `TaskTerminated`;
2. additive Core/Server `BeforeServerFork`;
3. the per-model Eloquent boot-owner map, one extra static `isset()` per normal model construction, and one retained Mutex channel per model class first booted in a coroutine;
4. one local phpredis `getMode()` call at every successful pooled release;
5. current Laravel's per-key cached-cast merge;
6. current Laravel's indexed raw SQL binding substitution.

No other planned change adds successful request/query hot-path work.

The fresh self-review additionally found `redis-06` through `redis-08` in `RedisProxy::__call()`, which this work already modifies. The direct event-cleanup slots, successful-command state update, and release-time boolean read are source-proven hot-path work. They remove a pool-slot leak and restore Redis `WATCH` / `DISCARD` correctness without a registry, wrapper, network check, or transaction abstraction. The owner approved these bounded costs together with the other implementation gates.

The owner separately required callback-form Redis connections to keep returning to the pool immediately after their closure finishes. The `redis-03` amendment preserves that behavior and replaces the already-planned coroutine-presence read with one coroutine-ID read plus one context owner comparison when a successful same-connection command publishes a new pin.

## Implementation order

Implement in this order so every higher-level consumer lands only after its owner exists:

1. Core task terminal event and failure precedence.
2. Server final pre-fork event.
3. Database exact task ownership and lifecycle listener.
4. Redis proxy/manager task ownership, command-event cleanup, optimistic-transaction ownership, fork listener, purge identity, and terminal release.
5. Shared SQLite classification and connector/schema routing.
6. In-memory SQLite single-owner pool capacity.
7. Transaction connection state and retry cleanup.
8. Transaction-manager detachment and callback exhaustion.
9. Eloquent boot publication and trait class attributes.
10. Current Query parity.
11. Current Eloquent parity.
12. Current Schema/migration/provider parity.
13. Approved Database performance ports.
14. Split metadata, provenance, deprecated omission markers, and docs.
15. Focused tests, full `composer fix`, fresh self-review, and post-implementation code review.

### Failure-precedence rule

Use direct `try/catch` blocks for each fixed set of independent phases:

```php
$exception = null;

try {
    $firstPhase();
} catch (Throwable $throwable) {
    $exception = $throwable;
}

try {
    $secondPhase();
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

if ($exception !== null) {
    throw $exception;
}
```

Do not allocate closure arrays merely to reuse this shape. Do not extract a generic finalizer or callback executor.

### Touched-file typing and comments

- Preserve current Laravel member order in ported files.
- Use native PHP 8.4 types where the inherited public API allows them.
- Retain behavioral upstream comments and adapt only incorrect framework names or runtime assumptions.
- Add short WHY comments only for non-obvious ownership rules: detach-before-I/O, discard-at-fork, native queue-mode terminal discard, inode-preserving SQLite truncation, and the boot-owner publication window.
- Do not annotate ordinary Hypervel adaptations or add architecture prose to user-facing docs.

## 1. Publish a terminal task lifecycle event

### Files

- Add `src/core/src/Events/TaskTerminated.php`.
- Modify `src/core/src/Bootstrap/TaskCallback.php`.
- Modify `tests/Core/Bootstrap/TaskCallbackTest.php`.

### Event contract

`TaskTerminated` carries the exact native server and task objects. It is an observational terminal event, not a result carrier:

```php
class TaskTerminated
{
    /**
     * Create a new task terminated event instance.
     */
    public function __construct(
        public readonly Server $server,
        public readonly Task $task,
    ) {
    }
}
```

### Callback choreography

After constructing the `Task` exactly as today, `TaskCallback::onTask()` has two phases:

1. dispatch `OnTask`, then finish a non-null result only when that dispatch succeeded;
2. attempt guarded `TaskTerminated` dispatch regardless of action or finish failure.

The earliest throwable remains primary:

```php
$exception = null;

try {
    $event = new OnTask($server, $task);
    $this->dispatcher->dispatch($event);

    if ($event->result !== null) {
        $this->taskUsesObject
            ? $task->finish($event->result)
            : $server->finish($event->result);
    }
} catch (Throwable $throwable) {
    $exception = $throwable;
}

try {
    if ($this->dispatcher->hasListeners(TaskTerminated::class)) {
        $this->dispatcher->dispatch(new TaskTerminated($server, $task));
    }
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

if ($exception !== null) {
    throw $exception;
}
```

Do not attempt `finish()` after `OnTask` failed because no valid task result was published. Do attempt terminal cleanup after `finish()` failed because ownership still ends at this callback.

### Tests

Extend `TaskCallbackTest` to prove:

- legacy and object task signatures carry the same exact `Task` into `TaskTerminated`;
- no terminal event is constructed or dispatched when there are no listeners;
- a normal result is finished before terminal dispatch;
- `OnTask` failure skips finish but not terminal dispatch;
- finish failure does not skip terminal dispatch;
- terminal failure propagates after a successful action/finish;
- action or finish failure remains primary when terminal dispatch also fails.

Use dispatcher mocks and native task/server doubles; do not add a production testing hook.

### Cost

With no consumer registered, every Swoole task adds one cached-false `hasListeners()` lookup. HTTP requests, jobs that do not use Swoole task workers, queries, and model construction are unchanged.

## 2. Publish one final pre-fork boundary

### Files

- Add `src/core/src/Events/BeforeServerFork.php`.
- Modify `src/server/src/Server.php`.
- Modify `tests/Server/ServerTest.php`.

### Event contract

The event carries the exact native server and documents the hard boundary:

```php
class BeforeServerFork
{
    /**
     * Create a new before server fork event instance.
     *
     * Listeners must release parent-only runtime resources and must not open
     * sockets, timers, pools, or other resources that child processes could
     * inherit.
     */
    public function __construct(
        public readonly SwooleServer $server,
    ) {
    }
}
```

Dispatch it inside `Server::start()` immediately before the native call:

```php
public function start(): void
{
    $server = $this->getServer();
    $this->eventDispatcher->dispatch(new BeforeServerFork($server));

    if ($server->start() === false) {
        throw new ServerException('Failed to start the Swoole server.');
    }
}
```

This is deliberately one final event. Do not add per-port resets, listener priorities, `OnManagerStart`, `OnStart`, or a cleanup registry.

### Tests

Prove:

- `BeforeServerFork` dispatch precedes native `start()`;
- the event carries the exact native server;
- a listener failure prevents the fork/start attempt;
- native `false` still produces the existing `ServerException` after listeners succeed.

### Cost

One event dispatch occurs once per server start. It is outside request, query, Redis command, and worker hot paths.

## 3. Give non-coroutine Database task leases an exact owner

### Files

- Modify `src/database/src/ConnectionResolver.php`.
- Add `src/database/src/Listeners/DatabaseConnectionLifecycleListener.php`.
- Modify `src/database/src/DatabaseServiceProvider.php`.
- Delete `src/database/src/Listeners/UnsetContextInTaskWorkerListener.php`.
- Rename `tests/Database/ConnectionResolverSetDefaultConnectionTest.php` to `tests/Database/ConnectionResolverTest.php` and extend it with pooled lifecycle coverage.
- Add `tests/Database/DatabaseServiceProviderTest.php`.
- Add focused task/fork coverage under `tests/Database/`.

### Resolver-owned wrapper map

Add one instance property on the concrete pooled resolver:

```php
/**
 * Pooled wrappers retained by non-coroutine task execution.
 *
 * @var array<string, PooledConnection>
 */
protected array $nonCoroutineConnections = [];
```

The owner key is normally `ConnectionName::requested`, including `::read` and `::write`; never reconstruct wrappers later from configured base names. The one exception is aliases backed by the same shared in-memory SQLite PDO: base, read, and write requests must use the pool's canonical name because only one wrapper can own that PDO. Genuine read/write pools retain their exact requested-name owners.

`connection()` publishes the bare connection and exactly one terminal owner transactionally:

```php
$connectionOwnerName = $connectionName->requested;
$contextKey = $this->getContextKey($connectionOwnerName);
$pool = $this->factory->getPool($connectionName->requested);

if ($pool->getSharedInMemorySqlitePdo() !== null) {
    $connectionOwnerName = $pool->getName();
    $contextKey = $this->getContextKey($connectionOwnerName);

    // Return an existing canonical owner before borrowing the sole wrapper.
}

$pooledConnection = $pool->get();

try {
    $connection = $pooledConnection->getConnection();

    if ($connectionName->isWrite() && $connection instanceof Connection) {
        $connection->useWriteConnectionWhenReading();
    }

    CoroutineContext::set($contextKey, $connection);

    if (Coroutine::inCoroutine()) {
        Coroutine::defer(function () use ($pooledConnection, $contextKey): void {
            CoroutineContext::forget($contextKey);
            $pooledConnection->release();
        });
    } else {
        $this->nonCoroutineConnections[$connectionOwnerName] = $pooledConnection;
    }
} catch (Throwable $exception) {
    CoroutineContext::forget($contextKey);
    unset($this->nonCoroutineConnections[$connectionOwnerName]);

    try {
        $pooledConnection->discard();
    } catch (Throwable) {
        // Preserve the connection-creation or publication failure.
    }

    throw $exception;
}
```

The final implementation must retain these invariants:

- raw connection creation, role configuration, context publication, and defer/map ownership either all commit or the exact wrapper is discarded;
- coroutine cleanup uses `forget()`, not a persistent null slot;
- only non-coroutine borrows enter the instance map;
- no lock or second context map is added because non-coroutine tasks execute sequentially in one task worker.

### Terminal helpers

Add concrete `@internal` methods only to `ConnectionResolver`; do not change `ConnectionResolverInterface` or `FlushableConnectionResolver`.

```php
public function releaseConnections(): void
{
    $this->terminateConnections(
        static fn (PooledConnection $connection): void => $connection->release(),
    );
}

public function discardConnections(): void
{
    $this->terminateConnections(
        static fn (PooledConnection $connection): void => $connection->discard(),
    );
}
```

The shared protected helper is justified because both public lifecycle operations must perform the same non-trivial detach-before-I/O transaction. It must:

1. snapshot `$nonCoroutineConnections`;
2. clear the property;
3. forget every exact owner-name connection key;
4. forget `DEFAULT_CONNECTION_CONTEXT_KEY`;
5. only then release or discard every wrapper;
6. continue after each failure and throw the earliest one.

Do not use `Closure::call()`, reflection, configured connection lists, a public raw context key, or a generic cleanup registry.

### Database lifecycle listener

Use one stateless listener resolved from the container and inject `Hypervel\Contracts\Container\Container` as `ContainerContract`:

```php
class DatabaseConnectionLifecycleListener
{
    public function __construct(
        protected ContainerContract $container,
    ) {
    }

    public function releaseTaskConnections(): void
    {
        if (! $this->container->resolved('db.resolver')) {
            return;
        }

        $resolver = $this->container->make('db.resolver');

        if ($resolver instanceof ConnectionResolver) {
            $resolver->releaseConnections();
        }
    }

    public function discardProcessConnections(): void
    {
        // Resolve neither owner merely to clean it. Treat resolver discard and
        // pool-factory flush as independent phases and preserve the first failure.
    }
}
```

`discardProcessConnections()` independently checks:

- canonical `'db.resolver'`: if resolved and concrete pooled resolver, call `discardConnections()`;
- Database `PoolFactory::class`: if resolved, call `flushAll()`.

The resolver discard and factory flush both run when the first fails. A custom resolver is left alone because this internal lifecycle belongs to the pooled concrete. `SimpleConnectionResolver`, Capsule, and `DatabaseManager::$connections` remain unchanged.

### Provider registration

In `DatabaseServiceProvider::boot()`:

- keep installing Model's resolver and dispatcher;
- register the `TaskTerminated` listener only when `server.settings.task_enable_coroutine` is false;
- register `BeforeServerFork` and `BeforeWorkerStart` unconditionally;
- resolve `DatabaseConnectionLifecycleListener` from the container inside each event closure.

There is no runtime `Coroutine::inCoroutine()` check in the task listener. Registration already proves non-coroutine task mode, and the resolver map cannot contain coroutine-owned wrappers.

### Tests

Cover:

- one non-coroutine borrow retained until terminal release;
- multiple requested names and read/write role suffixes;
- context/default override detached before any release callback can re-enter;
- every wrapper released despite one failure, with earliest failure preserved;
- discard path uses exact wrappers and exhausts failures;
- setup failure at connection retrieval or write-role configuration discards exactly once and preserves the primary failure; successful context publication is terminally owned by either the coroutine defer or non-coroutine task map;
- coroutine borrows remain defer-owned and never enter terminal task cleanup;
- provider task registration only when task coroutines are disabled;
- unresolved resolver/factory stay unresolved at lifecycle boundaries;
- resolved custom resolver is not forced through pooled internals;
- process cleanup still flushes an independently resolved pool factory after resolver discard failure.

Delete every reflective-listener test and stale configured-base-key assertion rather than preserving the superseded model.

### Rejected adjacent concern

A custom non-coroutine daemon process can hold one resolver connection for its loop lifetime, but it has no task boundary and that lifetime matches ordinary daemon connection reuse. Do not invent a process-operation cleanup hook.

## 4. Give Redis task and process leases exact proxy-owned cleanup

### Files

- Modify `src/redis/src/RedisProxy.php`.
- Modify `src/redis/src/RedisManager.php`.
- Modify `src/redis/src/RedisConnection.php`.
- Modify `src/redis/src/Traits/MultiExec.php`.
- Add `src/redis/src/Listeners/RedisConnectionLifecycleListener.php`.
- Modify `src/redis/src/RedisServiceProvider.php`.
- Modify `tests/Redis/RedisProxyTest.php`.
- Modify `tests/Redis/RedisManagerTest.php`.
- Modify `tests/Redis/RedisConnectionTest.php`.
- Modify `tests/Redis/RedisServiceProviderTest.php`.
- Add or extend external Redis integration tests using `InteractsWithRedis`.

### Register one terminal defer per pool and coroutine

After a successful `multi`, `pipeline`, `select`, or `watch`, retain the connection in context exactly as today. Outside a coroutine, register no defer. Inside a coroutine, register at most one terminal deferred release for that pool:

```php
CoroutineContext::set($this->getContextKey(), $connection);

$coroutineId = Coroutine::id();

if ($coroutineId > 0) {
    $deferredReleaseOwnerContextKey = $this->getDeferredReleaseOwnerContextKey();

    if (CoroutineContext::get($deferredReleaseOwnerContextKey) !== $coroutineId) {
        Coroutine::defer(function (): void {
            $this->releaseContextConnection();
        });

        CoroutineContext::set($deferredReleaseOwnerContextKey, $coroutineId);
    }
}
```

Use one private key helper and the repository naming convention:

```php
private const DEFERRED_RELEASE_OWNER_CONTEXT_KEY_PREFIX = '__redis.deferred_release_owner.';

private function getDeferredReleaseOwnerContextKey(): string
{
    return self::DEFERRED_RELEASE_OWNER_CONTEXT_KEY_PREFIX . $this->poolName;
}
```

The order is load-bearing: register the terminal owner before publishing its coroutine ID. Do not wrap the native defer call in rollback handling; the guarded in-coroutine Swoole path has no catchable registration failure. Keep the owner ID for the context lifetime, including after callback-form immediate release, manager purge, and later re-pinning. The one existing defer releases whichever wrapper remains pinned at coroutine exit.

The ID is required by Hypervel's supported context-copy behavior. A copy-all child inherits the parent's scalar owner value but has a different coroutine ID and no inherited native defer, so it registers and publishes its own terminal owner. Sibling children and later generations behave the same. Do not use a boolean, a coroutine-ID key suffix that accumulates copied ancestor keys, a `ReplicableContext` sentinel object, or a proxy-owned `WeakMap`/registry.

This branch runs only after successful same-connection commands. Ordinary Redis commands gain no coroutine or context check. Do not add Hyperf's callback-specific eager-release marker, marker helper trio, or marker cleanup choreography.

Database has no deduplication analog: every Database coroutine borrow registers its own defer, so no copyable false-owner slot exists. Explicitly copying a context while a live Database or Redis connection object is pinned continues to share that object by the context API's documented reference semantics. This amendment neither creates that deliberate lifetime escape nor justifies a generic connection-cloning or context-filtering mechanism.

### Complete command events before ownership cleanup

Keep command events before release or context handoff so a listener cannot observe a wrapper that another coroutine has already borrowed. Contain a throwing listener long enough to complete ownership cleanup, then preserve the existing Laravel-facing failure contract:

- a throwing `CommandExecuted` listener remains the primary failure after a successful command;
- a throwing `CommandFailed` listener continues to replace the command failure, matching current Laravel and existing Hypervel behavior;
- without a listener failure, the command failure remains primary over later cleanup failure;
- cleanup failure propagates when command and event handling succeeded.

Use direct local throwable slots around the existing fixed phases. Do not extract an event executor or generic finalizer:

```php
$commandException = null;
$eventException = null;
$cleanupException = null;

try {
    /** @var RedisConnection $connection */
    $connection = $connection->getConnection();
    $result = $connection->{$name}(...$arguments);
} catch (Throwable $throwable) {
    $commandException = $throwable;

    try {
        // Dispatch CommandFailed when it has listeners.
    } catch (Throwable $throwable) {
        $eventException = $throwable;
    }
}

if ($commandException === null) {
    try {
        // Dispatch CommandExecuted when it has listeners.
    } catch (Throwable $throwable) {
        $eventException = $throwable;
    }
}

try {
    // Preserve the existing release or same-connection handoff decision.
} catch (Throwable $throwable) {
    $cleanupException = $throwable;
}

if ($eventException !== null) {
    throw $eventException;
}

if ($commandException !== null) {
    throw $commandException;
}

if ($cleanupException !== null) {
    throw $cleanupException;
}

return $result;
```

Successful commands still determine handoff from their command name even when the observational event fails; an event failure must not turn a successful `MULTI`, `PIPELINE`, `SELECT`, or `WATCH` into an ordinary release.

### Preserve optimistic-transaction ownership

Add `watch` to the same-connection command set. Track the native watch state on `RedisConnection`, which owns the exact client generation:

```php
protected bool $watching = false;
```

After either the first native attempt or a retry succeeds, update only the states phpredis does not expose directly in `RedisConnection::__call()`:

```php
if ($name === 'watch' && $result !== false) {
    $this->watching = true;
} elseif (
    in_array($name, ['exec', 'reset'], true)
    || ($name === 'unwatch' && $result !== false)
) {
    $this->watching = false;
}
```

Reset the flag when a new native generation is connected and when the connection is closed. Do not extract this one-caller branch into a helper. Do not infer state from failed `WATCH`/`UNWATCH` results, and do not attempt to model arbitrary `executeRaw()` or direct-native-client escape hatches.

This one boolean is required because phpredis exposes MULTI/PIPELINE through `getMode()` but exposes no watched-state getter. It is not a second queue-mode model and adds no Redis command. Do not add a transaction object, context payload wrapper, command registry, or always-issued `UNWATCH`.

### Settle callback-form transaction watch state at its owner

`MultiExec::executeMultiExec()` calls native phpredis `exec()` directly after the callback. That bypasses `RedisConnection::__call()`, so a wrapper pinned by `WATCH` would otherwise retain a stale `watching=true` flag after the server consumed the watch.

Add one concrete cross-owner method to `RedisConnection`:

```php
/**
 * Clear the tracked watch state after callback-form transaction completion.
 *
 * @internal
 */
public function clearWatchState(): void
{
    $this->watching = false;
}
```

Add `clearWatchState` to `RedisProxy::CONNECTION_BOUND_METHODS` so it cannot be invoked as a Laravel-facing Redis command. The trait calls it only on the concrete wrapper already retained in context.

After callback-form `transaction()` reaches a non-throwing native `exec()` return, including `false` from an optimistic-lock conflict, clear the existing pinned wrapper:

```php
$result = tap($instance, $callback)->exec();

if ($command === 'multi' && $hasExistingConnection) {
    $connection = CoroutineContext::get($this->getContextKey());

    if ($connection instanceof RedisConnection) {
        $connection->clearWatchState();
    }
}

return $result;
```

Limit this settlement to `multi`: callback-form pipeline execution does not consume a prior watch. Do not clear when native `exec()` throws; its terminal state is unknown, so release must conservatively discard the wrapper. A callback that issues native `discard()` is covered when the trait's final `exec()` returns without throwing. Do not route callback-form `exec()` through `RedisProxy::__call()`, because that would add command events where none are dispatched today.

### Route native DISCARD around the pool lifecycle collision

Keep Pool's public `RedisConnection::discard(): void` ownership method unchanged. Add a distinct concrete `@internal` wrapper method for the native Redis command:

```php
public function discardTransaction(): bool|Redis
{
    $result = $this->connection->discard();

    if ($result !== false) {
        $this->watching = false;
    }

    return $result;
}
```

Add `discardTransaction` to `RedisProxy::CONNECTION_BOUND_METHODS` so callers cannot accidentally proxy the internal name. In the existing command invocation branch, route only `$name === 'discard'` to `discardTransaction()`; every other Laravel command retains normal magic dispatch:

```php
$result = $name === 'discard'
    ? $connection->discardTransaction()
    : $connection->{$name}(...$arguments);
```

This restores the documented Laravel-facing `Redis::discard()` command without renaming Pool's lifecycle API or changing its contract. Do not route it through `executeRaw()`, which would add another dynamic transformation path while already inside native queueing mode. Do not add a compatibility alias or change callback-form transaction behavior; callback transactions already receive the native client and call its native `discard()` directly.

### Proxy-owned terminal methods

Promote the existing release method and add its symmetric discard method as public `@internal` framework APIs:

```php
public function releaseContextConnection(): void
{
    $contextKey = $this->getContextKey();
    $connection = CoroutineContext::get($contextKey);

    CoroutineContext::forget($contextKey);

    if ($connection instanceof RedisConnection) {
        $connection->release();
    }
}

public function discardContextConnection(): void
{
    $contextKey = $this->getContextKey();
    $connection = CoroutineContext::get($contextKey);

    CoroutineContext::forget($contextKey);

    if ($connection instanceof RedisConnection) {
        $connection->discard();
    }
}
```

The proxy is the only owner of its actual pool/context identity. Do not expose the key or pooled connection and do not add these internal methods to Laravel-facing Redis contracts.

Update `withPinnedConnection()` and any sibling terminal path touched by this work to forget, rather than store null, when ownership ends. `withPinnedConnection()` remains defer-free. Keep callback-form `MultiExec` immediate-release ownership unchanged; the one pool-scoped terminal defer later no-ops unless another raw pin remains, and `MultiExec` otherwise changes only to settle the watched-state flag at its direct native-`exec()` boundary.

### Manager aggregation

Add concrete `@internal` `releaseConnections()` and `discardConnections()` methods that iterate already-created proxies, continue after each failure, and throw the earliest failure. They do not create proxies, flush pools, or alter the Redis contracts.

Correct `purge($managerName)`:

```php
$proxy = $this->connections[$managerName] ?? null;
unset($this->connections[$managerName]);

$poolName = $proxy?->getName() ?? $managerName;
$exception = null;

if ($proxy !== null) {
    try {
        $proxy->discardContextConnection();
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }
}

try {
    $this->factory->flushPool($poolName);
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

if ($exception !== null) {
    throw $exception;
}
```

Discard and pool flush are independent cleanup phases with earliest failure. When a custom creator returns a proxy with a different pool name, both context cleanup and pool destruction use the proxy's real identity. With no cached proxy, the manager name remains the correct pool fallback because `extend()` and `forgetExtension()` can remove only the proxy cache.

### Redis lifecycle listener

One stateless listener mirrors Database:

- task termination: if canonical `'redis'` is resolved and is `RedisManager`, call `releaseConnections()`; do not flush pools;
- pre-fork and child start: independently discard resolved manager proxies and flush resolved Redis `PoolFactory`;
- never resolve either owner merely to clean it;
- preserve the earliest failure across manager and factory phases.

Register the task listener only when task coroutines are disabled. Register `BeforeServerFork` and `BeforeWorkerStart` unconditionally.

Add direct `hypervel/core` to the Redis split manifest because the provider now imports Core lifecycle events.

### Tests

Cover:

- successful non-coroutine `select`, `multi`, and `pipeline` return their native result rather than throwing from defer registration;
- successful non-coroutine `watch` pins the exact wrapper without attempting coroutine defer;
- `Redis::discard()` invokes native DISCARD, returns its native result, clears native transaction/watch state, and does not discard the pool wrapper;
- raw same-connection commands remain pinned through one non-coroutine task and release at termination;
- repeated callback-form transaction/pipeline calls in one child coroutine release each newly pinned wrapper in their own `finally` and produce exactly one context-absent terminal `releaseContextConnection()` call for the pool after the child exits;
- callback-form success and callback/native-exec failure both return a newly pinned wrapper immediately rather than retaining it until coroutine exit;
- the existing limited-pool concurrency integration still completes while each child remains alive after its callback, proving immediate release rather than coroutine-terminal release;
- a raw same-connection pin after one or more callback-form calls is released by the already-registered terminal defer;
- a pre-pinned raw connection and nested callback-form operations gain no duplicate terminal defer and retain their original owner;
- a parent that has completed a callback-form operation can copy all context into a child; the child's inherited parent owner ID is rejected, the child publishes its own ID, and its distinct raw same-connection wrapper releases exactly once at child exit;
- `WATCH` followed by callback-form `transaction()` reuses the pinned wrapper, clears tracked watch state after any non-throwing native `exec()` result, requeues the healthy wrapper, and emits no false WATCH-state critical log;
- both a successful result and `exec() === false` consume the tracked watch, while a thrown `exec()` leaves it set so terminal release discards the unknown generation;
- callback-form pipeline execution does not clear a prior watch;
- multiple proxies are exhausted and the earliest release/discard failure wins;
- a divergent manager name/proxy pool name proves task cleanup uses proxy context identity;
- the same divergent proxy proves `purge()` discards its context and flushes its real pool;
- purge discards rather than releases before pool destruction;
- unresolved canonical manager and independently unresolved PoolFactory remain unresolved;
- manager cleanup failure does not skip factory flush;
- coroutine cleanup remains defer-owned;
- selected database is restored before normal task reuse;
- parent pre-fork and child-start cleanup both discard pinned wrappers and flush already-resolved pools.
- a throwing success listener cannot leak an ordinary borrowed wrapper or skip successful same-connection handoff;
- a throwing failure listener cannot skip release and retains the existing Laravel failure precedence;
- cleanup failure remains observable without replacing the command failure when no event listener failed;
- `WATCH`, intervening reads, `MULTI`, and `EXEC` use the same native client;
- successful `UNWATCH`, `EXEC`, and `DISCARD` clear the tracked watch state, while a new generation begins unwatched.

Keep the defer-count and copied-context regressions in `tests/Redis/RedisProxyTest.php`. Use a test-only `RedisProxy` subclass at the already-planned public `@internal` `releaseContextConnection()` boundary to count context-absent terminal calls after joining an explicitly created child; do not add a production defer hook. Keep the real limited-pool pipeline/transaction cases in `tests/Integration/Redis/RedisProxyIntegrationTest.php`; they prove actual wrappers return to the pool while each caller coroutine remains alive.

External Redis behavior tests must use the existing per-ParaTest-worker Redis isolation trait.

## 5. Never requeue a native Redis client in queueing or watched state

### Files

- Modify `src/redis/src/RedisConnection.php`.
- Modify `tests/Redis/RedisConnectionTest.php`.
- Extend real phpredis integration coverage.

### Release order

Queue-mode detection and the locally tracked watch state are checked before database restoration. `SELECT` issued inside MULTI would itself be queued, while a watched client must not become visible to another borrower:

```php
public function release(): void
{
    $this->shouldTransform = false;

    try {
        $queueing = $this->isQueueingMode();
    } catch (Throwable $exception) {
        $this->markInvalid();

        try {
            $this->log('Release connection failed, caused by ' . $exception, LogLevel::CRITICAL);
        } catch (Throwable) {
            // Reporting must not prevent terminal ownership cleanup.
        }

        $this->database = null;
        $this->availableForReuse = true;
        parent::release();

        return;
    }

    if ($queueing || $this->watching) {
        try {
            $this->log(
                $queueing
                    ? 'Discarding Redis connection left in MULTI or PIPELINE mode.'
                    : 'Discarding Redis connection left in WATCH state.',
                LogLevel::CRITICAL
            );
        } catch (Throwable) {
            // Reporting must not prevent terminal ownership cleanup.
        }

        $this->database = null;
        $this->watching = false;
        $this->availableForReuse = false;
        $this->discard();

        return;
    }

    try {
        $defaultDatabase = (int) ($this->config['database'] ?? 0);

        if ($this->database !== null && $this->database !== $defaultDatabase) {
            $this->select($defaultDatabase);
        }
    } catch (Throwable $exception) {
        $this->markInvalid();

        try {
            $this->log('Release connection failed, caused by ' . $exception, LogLevel::CRITICAL);
        } catch (Throwable) {
            // Reporting must not prevent terminal ownership cleanup.
        }
    } finally {
        $this->database = null;
        $this->watching = false;
        $this->availableForReuse = true;
        parent::release();
    }
}
```

The final code must avoid calling `parent::release()` after queue-mode discard. Best-effort reporting must be contained so logger failure cannot prevent discard. Mode-detection or ordinary database-restore failure retains the existing invalidate-and-parent-release path, allowing Pool reuse to replace the invalid generation. Keep this flow inline: extracting a single-use reporting or finalization helper would add indirection without sharing any real policy.

Do not add a PHP-side queue-mode mirror. The native client remains authoritative for MULTI/PIPELINE state. The one watch boolean covers only the missing phpredis observation and is cleared by successful terminal commands, reconnect, close, and release.

Do not add a mark-invalid fallback around a failed `discard()`. An escaping discard failure is an ownership invariant violation; Pool already contains native close failure and repairs bookkeeping. A flag cannot repair a bad ownership assertion.

### Tests

Prove with real phpredis behavior:

- abandoned raw MULTI via coroutine defer discards the generation;
- abandoned raw MULTI via non-coroutine task termination discards the generation;
- abandoned pipeline follows the same path;
- `WATCH` remains on the same wrapper through intervening reads and `MULTI`/`EXEC`;
- successful `UNWATCH`, `EXEC`, and `DISCARD` permit ordinary reuse;
- an abandoned `WATCH` discards the generation without issuing cleanup commands on behalf of the next borrower;
- the next borrower is a fresh ATOMIC-mode client and commands execute normally;
- mode detection occurs before any selected-database restore;
- mode-check failure and database-restore failure retain invalidation behavior;
- reporting failure cannot prevent queue-mode discard;
- discard ownership failure propagates rather than being masked or double-released.

### Cost

Every successful phpredis pooled release gains one native local `getMode()` call and one boolean read. Neither performs Redis I/O. The base class remains a constant false queue-mode check for non-phpredis implementations. Watch-state writes occur only around optimistic transactions. These small unavoidable successful-hot-path costs are the minimum required to keep native client state private to one borrower.

## 6. Normalize SQLite URI and in-memory classification

### Files

- Add `src/database/src/SQLiteDatabase.php`.
- Modify `src/database/src/Pool/DbPool.php`.
- Modify `src/database/src/Connectors/SQLiteConnector.php`.
- Modify `src/database/src/Schema/SqliteSchemaState.php`.
- Modify `src/database/src/Schema/SQLiteBuilder.php`.
- Modify `src/foundation/src/Testing/RefreshDatabase.php`.
- Modify `src/foundation/src/Testing/Concerns/InteractsWithParallelDatabase.php`.
- Modify `src/testing/src/Concerns/TestDatabases.php`.
- Modify `src/testbench/src/Concerns/HandlesDatabases.php`.
- Modify `src/testbench/src/Concerns/Database/InteractsWithSqliteDatabaseFile.php`.
- Modify `src/boost/docs/testing.md`.
- Add `tests/Database/SQLiteDatabaseTest.php`.
- Modify SQLite connector, pool, builder, and schema-state tests.
- Modify `tests/Foundation/Testing/RefreshDatabaseTest.php`.
- Modify focused Foundation, Testing, and Testbench SQLite/parallel-database tests.

### Internal classifier

Use one small internal class rather than a trait or connector-owned helper:

```php
class SQLiteDatabase
{
    /**
     * Determine if the database name is a SQLite URI.
     */
    public static function isUri(string $database): bool
    {
        return str_starts_with($database, 'file:');
    }

    /**
     * Determine if the database name resolves to an in-memory database.
     */
    public static function isInMemory(string $database): bool
    {
        if ($database === ':memory:') {
            return true;
        }

        if (! static::isUri($database)) {
            return false;
        }

        [$path, $query] = array_pad(
            explode('?', substr($database, strlen('file:')), 2),
            2,
            null,
        );

        if (rawurldecode($path) === ':memory:') {
            return true;
        }

        parse_str($query ?? '', $parameters);

        return ($parameters['mode'] ?? null) === 'memory';
    }
}
```

Before implementation, rerun the classifier matrix against the exact PHP/SQLite versions in the development environment. If native behavior has changed, stop and resolve the discrepancy rather than adding guesses.

The required matrix includes:

- literal `:memory:`;
- `file::memory:` and `file::memory:?cache=shared`;
- `file:?mode=memory`;
- named `file:name?mode=memory`;
- percent-encoded `:memory:` paths and `mode=memory` values;
- ordinary `file:/abs`, `file:///abs`, and query-bearing file paths;
- uppercase URI prefixes, uppercase mode keys, mixed-case mode values, and duplicate mode parameters.

Lowercase exactness and last duplicate value must match the native implementation.

### Connector and schema state

`SQLiteConnector::parseDatabasePath()`:

1. returns every `SQLiteDatabase::isInMemory()` form unchanged;
2. returns every lowercase `file:` URI unchanged;
3. otherwise resolves a direct path;
4. only calls `base_path()` when the helper exists;
5. throws the existing named exception when no path resolves.

`SqliteSchemaState::load()` uses the classifier for the in-process PDO path and passes any file URI unchanged to the sqlite CLI environment.

### Canonical file refresh

`SQLiteBuilder::dropAllTables()` derives the active schema's canonical path from `getSchemas()` / `pragma_database_list`, not `Connection::getDatabaseName()`. An empty path is the in-memory signal:

```php
$databases = array_column($this->getSchemas(), 'path', 'name');
$database = $databases[$schema] ?? null;

if (is_string($database) && $database !== '') {
    $this->refreshDatabaseFile($database);
} else {
    // Existing writable_schema/rebuild path for memory.
}
```

Keep inode-preserving truncation because the live PDO remains attached to that inode:

```php
public function refreshDatabaseFile(?string $path = null): void
{
    $path ??= $this->connection->getDatabaseName();

    if (File::put($path, '') === false) {
        throw new RuntimeException("Unable to refresh SQLite database file [{$path}].");
    }
}
```

Do not atomically replace this file; that would leave the live PDO connected to the old unlinked inode.

### Parallel testing and filesystem management boundaries

Every Testbench and parallel-testing memory check delegates to `SQLiteDatabase::isInMemory()`. URI memory databases remain unchanged because each worker process already owns distinct memory. Automatic parallel database management requires a plain SQLite filesystem path: both the early Testbench configuration rewrite and its post-`defineEnvironment()` ensure phase, plus the application test runner's common database-management choke point, reject a non-memory `file:` URI with a message directing callers to configure a plain path or use `--without-databases`. Both parallel systems honor that option before classification.

`SQLiteBuilder::createDatabase()` and `dropDatabaseIfExists()` similarly reject memory and URI names before calling PHP filesystem APIs. They operate on plain paths only; treating a URI as a PHP path either creates a stray literal filename or reports successful deletion without touching the database.

Do not add URI suffixing, URI-to-path decoding, or another database-name abstraction. Supporting URI-backed automatic parallel isolation honestly would require coordinated rewriting and cleanup across both parallel systems, URL handling, schema creation/drop, Testbench file swapping, and query parameters. The plain-path boundary prevents silent worker sharing without that unused machinery.

### Mixed RefreshDatabase connection ownership

Match current Laravel's aggregate/named split while retaining Hypervel's live default-refresh hook:

```php
protected function usingInMemoryDatabases(): bool
{
    foreach ($this->connectionsToTransact() as $name) {
        if ($this->usingInMemoryDatabase($name)) {
            return true;
        }
    }

    return false;
}

protected function usingInMemoryDatabase(?string $name = null): bool
{
    $config = $this->app->make('config');
    $name ??= $this->getRefreshConnection();

    return SQLiteDatabase::isInMemory(
        $config->string("database.connections.{$name}.database")
    );
}
```

Use `usingInMemoryDatabases()` for the restore decision and pass the loop's `$name` when caching PDOs in `beginDatabaseTransactionWork()`. A file-backed default with a named memory connection must cache only the named memory PDO; otherwise the next test can skip migrations after receiving a fresh empty memory PDO. Keep `getRefreshConnection()` as the no-argument owner because package test suites override or consume that live refresh boundary.

Do not alter restore ordering, coroutine setup, migration publication, transaction cleanup, or the existing `$migrated` / `$inMemoryConnections` lockstep.

### Tests

Cover the full classifier matrix, connector standalone use without `base_path()`, valid file URI acceptance, nonexistent plain paths, CLI routing, canonical URI path refresh, memory rebuild, and a checked `Filesystem::put()` false result. Prove both parallel systems skip `file::memory:`, reject a non-memory URI, honor `--without-databases`, and keep suffixing plain paths. Prove SQLite schema create/drop reject memory and URI names before filesystem access. Prove RefreshDatabase's no-argument check uses the live default-refresh connection, its aggregate check finds memory on any named transaction connection, and mixed file/memory transaction setup caches only the memory PDO.

The shared SQLite integration harness must use `SQLiteDatabase` for configured in-memory detection and must not pass URI names to PHP file-management APIs. The WAL migration test similarly skips configurations that are not plain filesystem paths because its fixture deletes and recreates the database file directly.

Use `ParallelTesting::tempDir()` for every database file. Do not write fixtures into the committed test tree.

## 7. Give one in-memory SQLite PDO one pool owner

### Files

- Modify `src/database/src/Pool/DbPool.php`.
- Modify `tests/Integration/Database/Sqlite/InMemorySqliteSharedPdoTest.php`.
- Modify `tests/Integration/Database/Sqlite/PoolConnectionManagementTest.php`.
- Revalidate every SQLite pool test that borrows more than one wrapper.

### Capacity normalization

After extracting pool options and classifying through `SQLiteDatabase`, normalize only configurations that are already valid integer count pairs:

```php
$minimum = $poolOptions['min_connections'] ?? 1;
$maximum = $poolOptions['max_connections'] ?? 10;

if ($this->isInMemorySqlite()
    && is_int($minimum)
    && is_int($maximum)
    && $minimum >= 0
    && $maximum >= 1
    && $minimum <= $maximum
) {
    $poolOptions['min_connections'] = min($minimum, 1);
    $poolOptions['max_connections'] = 1;
}
```

Invalid types, negative minimums, zero maximums, and invalid minimum/maximum relationships pass through unchanged so `PoolOption` remains the authoritative validator and produces the existing failure.

Use `SQLiteDatabase::isInMemory()` in:

- pool capacity normalization;
- shared PDO construction;
- derived-read in-memory rejection.

Delete the duplicated substring checks.

### Ownership behavior

The existing pool channel supplies all required serialization:

```text
one shared PDO
    └── one managed PooledConnection
            └── one borrower at a time
```

Do not add another Mutex, shared-cache URI, wrapper coordinator, connection registry, or driver-specific wait path.

Resolvers must also treat unsplit base, `::read`, and `::write` aliases as one owner when their pool exposes the shared PDO. Production context and Testbench wrapper caches use the pool's canonical name only for this case. Testbench named flush resolves that canonical key only through an already-created pool, so cleanup never creates a pool merely to destroy it.

### Tests

Replace any test that treats multiple wrappers as independent owners with deterministic one-owner behavior:

- first borrow holds the only slot;
- a second coroutine waits through the existing pool wait boundary;
- release or discard transfers capacity;
- state is visible through the same shared PDO after reuse;
- unsplit base/read/write aliases reuse one bare connection and one wrapper without waiting on their own pool slot;
- Testbench alias flush discards the canonical wrapper and restores capacity;
- query-log assertions clear the existing log before opening each alias-specific observation window;
- pool close and persistence tests release their first wrapper before another borrow;
- every valid URI memory form receives maximum one;
- ordinary file-backed SQLite retains configured capacity;
- invalid configurations still fail through `PoolOption`.

Tests that intentionally exercise waiting must set a bounded pool `wait_timeout` and use a deterministic channel/barrier. Never leave a test waiting indefinitely.

### Cost

Normalization occurs once at pool construction. Borrow, release, and query execution gain no extra branch or synchronization; the existing channel simply has capacity one.

## 8. Make physical and logical transaction state truthful

### Files

- Modify `src/database/src/Concerns/ManagesTransactions.php`.
- Modify `src/database/src/Connection.php`.
- Modify `tests/Database/DatabaseConnectionTest.php`.
- Modify `tests/Database/DatabaseTransactionsTest.php`.
- Modify supported-driver integration transaction tests.

### Transaction phase model

Keep the current public APIs. The fixed phases are:

```text
begin:
    before callbacks
    physical begin/savepoint
    logical increment
    manager record
    began event

commit through transaction():
    user callback
    committing event
    physical commit
    logical decrement
    manager commit/callbacks
    committed event

rollback:
    physical rollback/savepoint rollback
    logical level change
    manager detach/callbacks
    rolled-back event

disconnect:
    physical rollback when still possible
    logical terminal reset
    manager detach/callbacks
    PDO references cleared
```

Do not introduce a transaction state enum, state machine, finalizer object, retry framework, ambiguity object, or callback registry.

### Begin is atomic after physical creation

`beforeStartingTransaction` callbacks remain before any physical transaction. Once `createTransaction()` succeeds:

```php
$previousLevel = $this->transactions;
$this->transactions++;

try {
    $this->transactionsManager?->begin($this->getName(), $this->transactions);
    $this->fireConnectionEvent('beganTransaction');
} catch (Throwable $exception) {
    try {
        $this->rollBack($previousLevel);
    } catch (Throwable) {
        // Preserve the publication failure.
    }

    throw $exception;
}
```

This prevents a manager/event failure from leaving an unowned physical transaction. Pre-publication cleanup failure cannot replace the original failure.

### Separate pre-commit and physical-commit failures

Inside `transaction()`:

- user callback and `committing` listener failures are pre-commit failures and use the normal rollback/retry path;
- only `performCommit()` failures enter `handleCommitTransactionException()`;
- decrement logical state only after physical commit succeeds.

The intended skeleton is:

```php
try {
    $callbackResult = $callback($this);

    if ($this->transactions === 1) {
        $this->fireConnectionEvent('committing');
    }
} catch (Throwable $exception) {
    $this->handleTransactionException($exception, $currentAttempt, $attempts);

    continue;
}

$levelBeingCommitted = $this->transactions;

if ($this->transactions === 1) {
    try {
        $this->performCommit();
    } catch (Throwable $exception) {
        $this->handleCommitTransactionException(
            $exception,
            $currentAttempt,
            $attempts,
        );

        continue;
    }
}

$this->transactions = max(0, $this->transactions - 1);
```

`handleCommitTransactionException()` runs while the logical level still describes the active transaction:

1. a lost connection terminally detaches logical, manager, and PDO state without attempting another physical rollback;
2. another commit failure attempts `rollBack(0)`;
3. cleanup failure is contained so the commit failure remains primary;
4. a concurrency retry is allowed only when cleanup completed successfully and attempts remain;
5. every other path rethrows the original commit failure.

This includes current Laravel's physical cleanup before deadlock retry and improves it by never retrying after failed cleanup.

The same cleanup gate applies when the transaction callback or `committing` listener fails. Attempt rollback, preserve that earlier failure if physical rollback, manager rollback callbacks, or the rolled-back event fails, and retry a concurrency failure only after completely successful cleanup:

```php
$cleanedUp = true;

try {
    $this->rollBack();
} catch (Throwable) {
    $cleanedUp = false;
}

if ($cleanedUp
    && $this->causedByConcurrencyError($e)
    && $currentAttempt < $maxAttempts) {
    return;
}

throw $e;
```

The nested concurrency branch similarly preserves its intended `DeadlockException` if transaction-manager rollback callbacks fail. It still invalidates the exact session memo and decrements the logical level before manager cleanup; no additional event or retry path is introduced.

### Explicit `commit()` remains caller-owned before physical success

Do not route explicit `commit()` through the retry handler. If its `committing` listener or physical commit throws:

- do not decrement the logical level;
- do not detach manager records;
- let the caller or pool terminal cleanup decide what to do.

After a successful root physical commit, decrement before manager callbacks. If manager after-commit callbacks throw, the transaction is already committed and detached; still attempt `TransactionCommitted`, preserve callback failure, and never pretend the physical commit can be rolled back.

Nested explicit commits retain existing event behavior and member order.

### Rollback publishes only after physical success

`rollBack()` must:

1. validate the requested target;
2. attempt physical rollback/savepoint rollback while invalidating the exact PDO's memoized session configuration at the physical boundary on every outcome;
3. on success, publish the new logical level;
4. independently run manager rollback callbacks and `TransactionRolledBack`;
5. preserve the earliest manager/event failure.

If physical rollback fails:

- non-lost failure keeps the old logical level and manager records, marks the session unknown after invalidating its memo, and rethrows;
- lost failure invalidates the dead PDO's memo without marking it unknown, sets level zero, detaches manager state, clears PDO/read PDO without retrying physical rollback, and rethrows the physical failure;
- do not fire the rolled-back event because no successful physical rollback was observed.

After physical success, manager and event are independent terminal phases:

```php
$this->transactions = $toLevel;
$exception = null;

try {
    $this->transactionsManager?->rollback($this->getName(), $toLevel);
} catch (Throwable $throwable) {
    $exception = $throwable;
}

try {
    $this->fireConnectionEvent('rollingBack');
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

if ($exception !== null) {
    throw $exception;
}
```

### Disconnect is terminal and exhaustive

`Connection::disconnect()` must independently:

- attempt physical rollback when a PDO still reports an active transaction;
- publish logical level zero;
- ask the transaction manager to detach all records even when the logical counter was already zero;
- clear write and read PDO references unconditionally;
- preserve the earliest non-lost physical or manager/callback failure.

The PDO references are cleared in a terminal `finally`-equivalent boundary. Physical rollback failure marks the old physical session unknown before dropping it. A lost-connection failure during disconnect means the physical session is already terminal and must not prevent manager cleanup or reconnection; a manager/callback failure remains observable after that classification. Preserve every non-lost physical failure, and do not retry physical rollback after a lost-connection failure.

Use a small protected helper only if the commit-lost and rollback-lost paths otherwise duplicate the same non-trivial manager/PDO terminal detach. It may not hide physical I/O or become a generic finalizer.

### Restore query logging in `finally`

`withFreshQueryLog()` restores the exact prior flag even when its callback throws:

```php
$loggingQueries = $this->loggingQueries;
$this->enableQueryLog();
$this->queryLog = [];

try {
    return $callback();
} finally {
    $this->loggingQueries = $loggingQueries;
}
```

### Tests

Build focused failure injection for:

- before-start callback failure before physical begin;
- manager begin and began-event failure after physical begin;
- rollback cleanup failure preserving begin failure;
- user callback failure, including rollback cleanup failure preserving the callback failure;
- retryable callback failure with rollback cleanup failure preventing retry;
- nested concurrency failure preserving its `DeadlockException` when manager rollback cleanup fails;
- throwing `TransactionCommitting` listener, including the real old pooled-corruption reproduction;
- physical commit concurrency failure with successful cleanup and retry;
- physical commit cleanup failure preventing retry while preserving the commit failure;
- lost commit terminal detachment and PDO clearing;
- explicit committing-listener and physical-commit failures retaining active caller-owned state;
- after-commit callback failure still attempting `TransactionCommitted`;
- root and savepoint physical rollback failure;
- lost rollback terminal detachment without a second physical rollback;
- manager rollback failure still attempting the event;
- event failure after successful manager cleanup;
- disconnect non-lost physical failure, already-terminal lost physical cleanup, callback failure, PDO clearing, and exact failure precedence;
- `withFreshQueryLog()` success and exception restoration;
- pooled release after every failure class, proving no false reusable state.

Use real SQLite transactions for physical-state assertions and mocks/subclasses only at existing protected seams for deterministic failure injection.

## 9. Detach transaction-manager records before rollback callbacks

### Files

- Modify `src/database/src/DatabaseTransactionsManager.php`.
- Modify `src/database/src/DatabaseTransactionRecord.php`.
- Modify `tests/Database/DatabaseTransactionsManagerTest.php`.
- Revalidate integration after-commit/rollback tests.

### Full rollback

For a connection rolling back to level zero:

1. collect every committed record for that connection;
2. collect the current parent chain;
3. clear current, pending, and committed manager state for that connection;
4. sort the detached records deepest-first;
5. execute all rollback callbacks, continuing after failure;
6. throw the earliest callback failure.

The essential order is:

```php
$transactions = $committedForConnection->concat($currentChain)
    ->uniqueStrict()
    ->sortByDesc(static fn (DatabaseTransactionRecord $record): int => $record->level)
    ->values();

// Detach all manager state before invoking user code.
$this->setCurrentTransactionForConnection($connection, null);
$this->setPendingTransactions($pendingForOtherConnections);
$this->setCommittedTransactions($committedForOtherConnections);

$this->executeRollbackCallbacks($transactions);
```

Normal commit choreography leaves the committed set and current pending ancestry disjoint. However, the public and directly tested `stageTransactions()` method can be invoked independently: it moves a record from pending to committed without advancing the current pointer. A later full rollback or disconnect then gathers that same record from both sets. Retain `uniqueStrict()` so its rollback callbacks execute once by object identity; this is a concrete upstream-reachable state, not defensive deduplication.

### Partial rollback

For a target above zero:

- detach pending/current records strictly above the target;
- recursively detach committed descendants of those records;
- do not detach outer committed siblings;
- publish the surviving current record before callbacks;
- sort all detached records deepest-first and exhaust their rollback callbacks.

A protected recursive helper that removes and returns committed descendants is justified because this is a real tree operation. It must not invoke callbacks during recursion.

### Callback behavior

`DatabaseTransactionRecord::executeCallbacksForRollback()` exhausts its fixed callback list:

```php
$exception = null;

foreach ($this->callbacksForRollback as $callback) {
    try {
        $callback();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
}

if ($exception !== null) {
    throw $exception;
}
```

The manager similarly continues across every detached record.

Keep after-commit callback stop-on-first behavior. Current Laravel explicitly tests that contract; rollback callbacks are cleanup and need exhaustive semantics. Do not unify both through a generic executor.

### Tests

Cover:

- full rollback current chain plus committed descendants;
- partial rollback above target;
- deeply nested committed descendants;
- outer committed siblings retained;
- manager collections and current pointer already detached when a callback re-enters;
- failure of the first callback does not skip later callbacks in the record or deeper/shallower records;
- deepest-first order;
- earliest callback failure;
- commit callbacks remain stop-on-first;
- multiple connection names remain isolated in coroutine context.

## 10. Serialize first Eloquent model boot until publication completes

### Files

- Modify `src/database/src/Eloquent/Model.php`.
- Modify `tests/Database/DatabaseEloquentModelTest.php`.
- Modify or add focused coroutine boot tests under `tests/Database/Eloquent/`.
- Modify `tests/Database/DatabaseEloquentModelAttributesTest.php`.
- Modify `src/scout/src/Searchable.php` and focused Scout feature coverage.
- Modify `src/nested-set/src/HasNode.php` and `tests/NestedSet/NestedSetTest.php`.

### Owner state

Add the current owner per model class:

```php
/**
 * The coroutine currently booting each model.
 *
 * @var array<class-string<self>, int>
 */
protected static array $booting = [];
```

The integer is `Coroutine::id()`, where `-1` is the existing non-coroutine sentinel.

### Fast path and recursive ownership

The normal already-booted path avoids a coroutine-ID lookup:

```php
$class = static::class;

if (isset(static::$booted[$class]) && ! isset(static::$booting[$class])) {
    return;
}

$coroutineId = Coroutine::id();

if ((static::$booting[$class] ?? null) === $coroutineId) {
    if (isset(static::$booted[$class])) {
        return;
    }

    throw new LogicException(
        'The [' . __METHOD__ . '] method may not be called on model [' . $class . '] while it is being booted.',
    );
}
```

Before publication, same-owner recursion throws current Laravel's named error. After `$booted` is published, same-owner construction from `booted()` or `whenBooted()` callbacks returns normally.

### First-boot lock and publication

Only coroutine first boot acquires the existing Mutex:

```php
$mutexKey = 'database.model.booting.' . $class;
$locked = false;

if ($coroutineId >= 0) {
    if (! Mutex::lock($mutexKey)) {
        throw new RuntimeException("Unable to acquire the model boot lock for [{$class}].");
    }

    $locked = true;
}

try {
    // Recheck booted and owner state after waiting.
    // Publish this coroutine as owner.
    // Run booting event, booting(), and boot().
    // Set $booted[$class] = true.
    // Retain ownership through booted(), whenBooted callbacks, and booted event.
} finally {
    if ((static::$booting[$class] ?? null) === $coroutineId) {
        unset(static::$booting[$class]);
    }

    if ($locked) {
        Mutex::unlock($mutexKey);
    }
}
```

The recheck after lock acquisition must return for a sibling whose predecessor completed.

Publication order:

1. booting event;
2. `booting()`;
3. `boot()`;
4. set `$booted[$class] = true`;
5. `booted()`;
6. registered `whenBooted()` callbacks;
7. booted event;
8. clear owner and unlock.

Pre-publication failure clears the owner and leaves the model unbooted so a later attempt retries. Post-publication failure leaves `$booted=true`, matching Laravel, while still clearing owner/unlocking. Do not attempt to reverse arbitrary user hook side effects.

### Reset behavior

`clearBootedModels()` and `flushState()` clear `$booting`. They do not close live Mutex channels. `AfterEachTestSubscriber` already flushes Mutex before Model.

Retain one Mutex channel for each model class whose first boot occurs in a coroutine. A model first booted outside a coroutine never creates a Mutex channel. Removing a created channel after boot would race waiters and require more synchronization.

### Trait-carried class attributes

Port current Laravel `#60566` by checking the traits of each reflected model/parent class before ascending:

```php
foreach ($reflection->getTraits() as $trait) {
    $attributes = $trait->getAttributes($attributeClass);

    if ($attributes !== []) {
        $instance = $attributes[0]->newInstance();
        break 2;
    }
}
```

Keep Hypervel's cache key as `class@attributeClass` and continue selecting `$property` after retrieving the cached attribute object. Do not port Laravel `#60815`; its property-key collision cannot occur in this cache shape.

### Keep model-boot consumers out of the pre-publication recursion guard

The full gate proved two current package consumers still construct their model while `bootIfNotBooted()` is deliberately incomplete:

- Scout's `bootSearchable()` creates `(new static)` to register collection macros. Current Scout PR `#965` moved that instance work and observer registration into `whenBooted()`. Port that boundary without its obsolete `method_exists()` compatibility branch, while retaining Hypervel's class-string observer and collection behavior.
- NestedSet's `usesSoftDelete()` calls `method_exists(new static, ...)` from `bootHasNode()`. Match current upstream's no-instantiation trait check through `class_uses_recursive(static::class)` and the existing typed cache. Keep listener registration in the boot phase: it is not recursive in Hypervel, and moving the whole block to `whenBooted()` would change established listener order without fixing another verified failure. Record that ordering reason in one short source comment where a future upstream sync will compare the block.

Do not relax the Model recursion guard or add another boot phase. The consumers must stop constructing an unpublished model at the exact operation that does so.

### Tests

Cover:

- same-owner recursion before publication throws;
- same-owner construction in `booted()` and `whenBooted()` succeeds;
- two sibling coroutines deterministically interleave and the second waits instead of observing incomplete state or throwing;
- pre-publication hook failure allows a later retry;
- post-publication hook/callback/event failure leaves the model published but clears owner/lock;
- boot state is isolated by model class;
- non-coroutine first boot uses no Mutex;
- `clearBootedModels()` and `flushState()` clear owner state;
- class attributes declared directly, on a trait, on a parent, and on a parent trait resolve with current precedence;
- attribute object cache/property selection remains collision-free.
- first construction of a Searchable model completes, then registers its observer and collection macros through the post-publication callback;
- plain and soft-deleting NestedSet models boot successfully and classify SoftDeletes without nested model construction.

Use explicit channels for boot interleaving; do not rely on timing sleeps.

### Cost

Every normal model construction gains one additional static owner-map `isset()`. `Coroutine::id()` and Mutex operations occur only while a class is not stably booted or while its first boot is still owned. One channel is retained only for a model class first booted in a coroutine. This owner-approved cost is the minimum needed to distinguish stable publication from an in-progress sibling boot.

## 11. Port the complete current supported Query surface

### Files

- Modify `src/database/src/Query/Builder.php`.
- Modify `src/database/src/Query/Grammars/Grammar.php`.
- Modify `src/database/src/Query/Grammars/MySqlGrammar.php`.
- Modify `src/database/src/Query/Grammars/PostgresGrammar.php`.
- Modify `src/database/src/Query/Grammars/SQLiteGrammar.php`.
- Modify `src/database/src/Eloquent/Concerns/QueriesRelationships.php`.
- Merge the originating tests into:
  - `tests/Database/DatabaseQueryBuilderTest.php`;
  - `tests/Database/DatabaseMySqlQueryGrammarTest.php`;
  - `tests/Database/DatabaseEloquentBuilderTest.php`;
  - the matching supported integration test files under `tests/Integration/Database`.

Use current Laravel `13.x` member order and behavior. Do not port SQL Server grammar code or tests.

### Straight joins

Add the three current builder entry points in the same relative position as Laravel:

```php
public function straightJoin(
    ExpressionContract|string $table,
    Closure|string $first,
    ?string $operator = null,
    ExpressionContract|string|null $second = null
): static {
    return $this->join($table, $first, $operator, $second, 'straight_join');
}

public function straightJoinWhere(
    ExpressionContract|string $table,
    Closure|ExpressionContract|string $first,
    string $operator,
    ExpressionContract|string $second
): static {
    return $this->joinWhere($table, $first, $operator, $second, 'straight_join');
}

public function straightJoinSub(
    Closure|self|EloquentBuilder|string $query,
    string $as,
    Closure|ExpressionContract|string $first,
    ?string $operator = null,
    ExpressionContract|string|null $second = null
): static {
    return $this->joinSub($query, $as, $first, $operator, $second, 'straight_join');
}
```

Use the actual existing Hypervel union and generic conventions when implementing; the snippet shows the call shape, not permission to widen a more precise current signature.

Compile the special join word only on a grammar that explicitly supports it:

```php
$joinWord = $join->type === 'straight_join' && $this->supportsStraightJoins()
    ? ''
    : ' join';

return trim("{$join->type}{$joinWord} {$tableAndNestedJoins} {$this->compileWheres($join)}");
```

The base grammar keeps current Laravel's unsupported-operation exception; MySQL returns `true`. Do not silently compile `straight_join join` or claim support on PostgreSQL or SQLite.

### Null-safe equality

Add the public builder methods and binding behavior:

```php
public function whereNullSafeEquals(
    ExpressionContract|string $column,
    mixed $value,
    string $boolean = 'and'
): static {
    $this->wheres[] = [
        'type' => 'NullSafeEquals',
        'column' => $column,
        'value' => $value,
        'boolean' => $boolean,
    ];

    if (! $value instanceof ExpressionContract) {
        $this->addBinding($this->flattenValue($value), 'where');
    }

    return $this;
}

public function orWhereNullSafeEquals(ExpressionContract|string $column, mixed $value): static
{
    return $this->whereNullSafeEquals($column, $value, 'or');
}
```

Compile the supported dialects exactly:

```php
// Base / PostgreSQL-compatible.
return $this->wrap($where['column'])
    . ' is not distinct from '
    . $this->parameter($where['value']);

// MySQL / MariaDB.
return $this->wrap($where['column'])
    . ' <=> '
    . $this->parameter($where['value']);

// SQLite.
return $this->wrap($where['column'])
    . ' is '
    . $this->parameter($where['value']);
```

Port the two current `whereNotMorphedTo()` uses in `QueriesRelationships` so nullable morph types use the null-safe builder primitive. Do not add the unsupported SQL Server override.

### Explicit value ordering

Port `inOrderOf()` with Arrayable normalization, empty-input no-op behavior, union binding selection, and the CASE expression compiler:

```php
public function inOrderOf(ExpressionContract|string $column, Arrayable|array $values): static
{
    if ($values instanceof Arrayable) {
        $values = $values->toArray();
    }

    $values = array_values($values);

    if ($values === []) {
        return $this;
    }

    $hasUnions = $this->unions !== null && $this->unions !== [];

    $this->{$hasUnions ? 'unionOrders' : 'orders'}[] = [
        'type' => 'InOrderOf',
        'column' => $column,
        'values' => $values,
    ];

    $this->addBinding(
        $this->cleanBindings($values),
        $hasUnions ? 'unionOrder' : 'order'
    );

    return $this;
}
```

```php
$column = $this->wrap($order['column']);
$cases = [];

foreach (array_values($order['values']) as $index => $value) {
    $cases[] = 'when ' . $column . ' = ' . $this->parameter($value) . ' then ' . $index;
}

return 'case ' . implode(' ', $cases) . ' else ' . count($order['values']) . ' end';
```

Do not invent driver-specific ordering functions; the current portable CASE implementation is the public contract.

### MySQL query timeout

Add the nullable timeout property and current fluent validation:

```php
public ?int $timeout = null;

public function timeout(?int $seconds): static
{
    if ($seconds !== null && $seconds <= 0) {
        throw new InvalidArgumentException('Timeout must be greater than zero.');
    }

    $this->timeout = $seconds;

    return $this;
}
```

MySQL prepends the optimizer hint after compiling the normal select:

```php
$sql = parent::compileSelect($query);

if ($query->timeout === null) {
    return $sql;
}

$milliseconds = $query->timeout * 1000;

return preg_replace(
    '/^select\b/i',
    'select /*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */',
    $sql,
    1
);
```

No other supported grammar claims timeout support.

### Insert while ignoring conflicts and return rows

Add current Laravel's final public signature and validation:

```php
public function insertOrIgnoreReturning(
    array $values,
    array $returning = ['*'],
    array|string|null $uniqueBy = null
): Collection {
    if ($values === []) {
        return new Collection;
    }

    if ($uniqueBy === [] || $uniqueBy === '') {
        throw new InvalidArgumentException('The unique columns must not be empty.');
    }

    if ($returning === []) {
        throw new InvalidArgumentException('The returning columns must not be empty.');
    }

    // Normalize one or many rows exactly as insertOrIgnore() does.
    // Apply before-query callbacks, compile, execute on the write connection,
    // and mark records modified only when at least one row was returned.
}
```

The final compilation contract is:

```php
public function compileInsertOrIgnoreReturning(
    Builder $query,
    array $values,
    array $returning,
    ?array $uniqueBy
): string
```

The base grammar throws `RuntimeException`. PostgreSQL and SQLite compile:

```php
$insert = $this->compileInsert($query, $values);

return match ($uniqueBy) {
    null => "{$insert} on conflict do nothing returning {$this->columnize($returning)}",
    default => "{$insert} on conflict ({$this->columnize($uniqueBy)}) do nothing returning {$this->columnize($returning)}",
};
```

Preserve current handling for a single row, multiple rows, no inserted rows, explicit returning columns, one or several unique columns, before-query callbacks, binding order, and modified-record state. Do not add MySQL emulation; the grammar does not provide equivalent row-return semantics.

### Current Query corrections

Port the remaining current changes at their existing lowest methods:

- Normalize `DatePeriod` in both `whereBetween()` and `havingBetween()` through one protected `resolveDatePeriodBounds()` helper. When no explicit end exists, clone the start and apply the interval for the declared recurrences.
- Escape question marks in `whereColumn()` operators before SQL compilation:

  ```php
  $operator = str_replace('?', '??', $where['operator']);
  ```

- In PostgreSQL `whereDate()` and `whereTime()`, call `wrap()` on the original column/Expression and parenthesize JSON selectors; never force an Expression through string-only selector logic.
- Wrap the aggregate alias through the grammar:

  ```php
  return 'select ' . $aggregate['function'] . '(' . $column . ') as ' . $this->wrap('aggregate');
  ```

- Honor the PostgreSQL full-text `vector` option by wrapping a precomputed `tsvector` column directly instead of wrapping it in `to_tsvector(...)`.
- Accept Query builders, Eloquent builders, and relations as update values. Compile each supported value as a parenthesized subquery and merge its bindings in the same order as the update columns.
- Port current relative-date tests because the existing source already implements the behavior; do not add duplicate source logic.

### Tests

Port and adapt every test changed by the originating commits. At minimum prove:

- all three straight-join entry points, nested joins, bindings, MySQL SQL, and unsupported-grammar failure;
- null-safe equality with normal values, null, expressions, OR form, bindings, MySQL, SQLite, PostgreSQL/base SQL, and morph relationship queries;
- `inOrderOf()` values, Arrayable values, union orders, bindings, duplicates, and empty no-op;
- timeout null/reset, positive values, invalid zero/negative values, hint placement, and unchanged non-MySQL SQL;
- `insertOrIgnoreReturning()` single/multiple rows, conflict/no-conflict, modified state, returning subsets, before-query callbacks, empty validation, multi-column uniqueness, and unsupported grammar;
- DatePeriod with explicit end and recurrence-derived end;
- literal question marks in column operators;
- PostgreSQL Expression date/time compilation;
- aggregate alias delimiting;
- Telescope's query watcher expects the same delimited aggregate alias as the grammar;
- PostgreSQL precomputed vector full-text SQL;
- Query/Eloquent/relation update subqueries and binding order;
- the current relative-date integration matrix on every configured supported driver.

Run each changed test file immediately. Use the existing external-database isolation traits for MySQL, MariaDB, and PostgreSQL integration coverage.

## 12. Port the complete current supported Eloquent surface

### Files

- Add `src/database/src/Eloquent/Attributes/RouteKey.php`.
- Modify:
  - `src/database/src/Eloquent/Builder.php`;
  - `src/database/src/Eloquent/Model.php`;
  - `src/database/src/Eloquent/ModelNotFoundException.php`;
  - `src/database/src/Eloquent/SoftDeletes.php`;
  - `src/database/src/Eloquent/Casts/AsEncryptedArrayObject.php`;
  - `src/database/src/Eloquent/Concerns/HasAttributes.php`;
  - `src/database/src/Eloquent/Concerns/HasRelationships.php`;
  - `src/database/src/Eloquent/Concerns/HasTimestamps.php`;
  - `src/database/src/Eloquent/Concerns/TransformsToResource.php`;
  - `src/database/src/Eloquent/Relations/BelongsToMany.php`;
  - `src/database/src/Eloquent/Relations/HasOneOrMany.php`;
  - `src/database/src/Eloquent/Relations/HasOneOrManyThrough.php`;
  - `src/database/src/Eloquent/Relations/MorphTo.php`.
- Merge the current originating tests into the matching `tests/Database/DatabaseEloquent*Test.php` and `tests/Integration/Database/Eloquent*Test.php` files, preserving Hypervel coroutine-specific cases.

### Model-scoped increment and decrement operations

Port the current builder and model behavior together. The public Query/Eloquent builder methods already express the SQL operation; the model methods must update one model instance, preserve events, class-deviable casts, dirty/original state, keys, timestamps, and quiet variants.

```php
protected function incrementEach(array $columns, array $extra = []): int|false
{
    return $this->incrementOrDecrementEach($columns, $extra, 'incrementEach');
}

protected function decrementEach(array $columns, array $extra = []): int|false
{
    return $this->incrementOrDecrementEach($columns, $extra, 'decrementEach');
}

protected function incrementEachQuietly(array $columns, array $extra = []): int|false
{
    return static::withoutEvents(
        fn () => $this->incrementOrDecrementEach($columns, $extra, 'incrementEach')
    );
}

protected function decrementEachQuietly(array $columns, array $extra = []): int|false
{
    return static::withoutEvents(
        fn () => $this->incrementOrDecrementEach($columns, $extra, 'decrementEach')
    );
}
```

`incrementOrDecrementEach()` must:

1. delegate directly to a relationship-free builder when the model does not exist;
2. update every in-memory attribute, including class-deviable casts;
3. force-fill `$extra`;
4. honor an `updating` veto;
5. translate deviable cast deltas to database values;
6. constrain the update through `setKeysForSaveQuery()`;
7. sync changes, fire `updated`, and sync originals for the changed columns.

Correct the existing sibling methods at the same owner:

```php
protected function increment(
    string $column,
    mixed $amount = 1,
    array $extra = []
): int|false {
    return $this->incrementOrDecrement($column, $amount, $extra, 'increment');
}

protected function decrement(
    string $column,
    mixed $amount = 1,
    array $extra = []
): int|false {
    return $this->incrementOrDecrement($column, $amount, $extra, 'decrement');
}
```

The shared implementation already returns `false` when an `updating` listener vetoes the operation. The current `int` declarations therefore turn a supported event veto into a `TypeError`; preserve the real Laravel behavior with the precise `int|false` contract instead of carrying the upstream PHPDoc error into native types.

Route all four dynamic method names through `Model::__call()`. Do not add duplicate public wrappers solely to expose protected dynamic methods.

### Conflict-safe model insert

Add `saveOrIgnore()` and its protected insert phase:

```php
public function saveOrIgnore(
    array $options = [],
    array|string|null $uniqueBy = null
): bool {
    if ($this->exists) {
        throw new LogicException('Cannot use saveOrIgnore on an existing model.');
    }

    $this->mergeAttributesFromCachedCasts();
    $query = $this->newModelQuery();

    if ($this->fireModelEvent('saving') === false) {
        return false;
    }

    $saved = $this->performInsertOrIgnore($query, $uniqueBy);

    if ($this->getConnectionName() === null) {
        $this->setConnection($query->getConnection()->getName());
    }

    if ($saved) {
        $this->finishSave($options);
    }

    return $saved;
}
```

`performInsertOrIgnore()` follows normal insert preparation:

- generate unique IDs;
- honor `creating`;
- update timestamps;
- obtain insertion attributes;
- return success for an empty insertion;
- call `insertOrIgnoreReturning($attributes, ['*'], $uniqueBy)`;
- return `false` without publishing model state when the result is empty;
- set an incrementing key from the returned row;
- publish `exists`, `wasRecentlyCreated`, and `created` only for an inserted row.

Do not emulate this API for MySQL, because the accepted Query primitive is supported only where the grammar can return the inserted row. Tests and docs must state the supported-driver boundary.

### Closure-valued defaults

Change the current first/create/update-or-create APIs to accept `Closure|array` wherever Laravel now does and resolve the closure only when the values are actually needed:

```php
return $this->newModelInstance(array_merge($attributes, value($values)));

return $this->withSavepointIfNeeded(
    fn () => $this->create(array_merge($attributes, value($values)))
);

return tap($this->firstOrCreate($attributes, $values), function ($instance) use ($values): void {
    if (! $instance->wasRecentlyCreated) {
        $instance->fill(value($values))->save();
    }
});
```

Apply the current signature and `value()` use consistently to Eloquent Builder, `BelongsToMany`, `HasOneOrMany`, and the applicable `HasOneOrManyThrough` methods. Do not invoke a closure on an already-found path.

### Route key class attribute

Add the current class-only attribute:

```php
#[Attribute(Attribute::TARGET_CLASS)]
class RouteKey
{
    public function __construct(
        public string $key
    ) {
    }
}
```

Resolve it from `Model::getRouteKeyName()`:

```php
return static::resolveClassAttribute(RouteKey::class, 'key')
    ?? $this->getKeyName();
```

This consumes the trait-aware class-attribute resolution implemented in Section 10. Preserve explicit method overrides and the ordinary primary-key fallback.

### Relationship and scope corrections

Port the current corrections at their existing owners:

- `BelongsToMany::touch()` updates through `getQualifiedRelatedKeyName()`, not an incorrectly assumed related primary key.
- `MorphTo::matchToMorphParents()` normalizes both the configured owner-key value and the model-key fallback through `getDictionaryKey()`:

  ```php
  $ownerKey = $this->getDictionaryKey(
      $this->ownerKey !== null
          ? $result->{$this->ownerKey}
          : $result->getKey()
  );
  ```

- Nested Eloquent closure where clauses propagate the nested builder's removed scopes before adding the nested base query:

  ```php
  $this->withoutGlobalScopes($query->removedScopes());
  ```

- A private method carrying `#[Scope]` is not a callable named scope:

  ```php
  if (method_exists(static::class, $method)) {
      $reflection = new ReflectionMethod(static::class, $method);

      return ! $reflection->isPrivate()
          && $reflection->getAttributes(Scope::class) !== [];
  }

  return false;
  ```

- Builder and model timestamp touching accept an array of columns, apply the same fresh timestamp to each, and retain the existing default updated-at behavior.
- `withoutRelation()` remains present and covered; do not duplicate its already-correct source or documentation merely because it appeared in the discovery history.

### Event, exception, cast, and resource corrections

- Fire `restored` only when the underlying soft-delete restore save succeeds:

  ```php
  $result = $this->save();

  if ($result) {
      $this->fireModelEvent('restored', false);
  }

  return $result;
  ```

- Normalize backed and unit enum IDs at the `ModelNotFoundException` string boundary:

  ```php
  $this->ids = array_map(enum_value(...), Arr::wrap($ids));
  ```

  This is an identifier/message boundary covered by `support-02`; do not normalize enum values earlier in query/model data paths.

- Add `ArrayObject::ARRAY_AS_PROPS` when constructing `AsEncryptedArrayObject`.
- Correct the `casts()` PHPDoc so custom cast methods may return `Stringable` objects where current Laravel allows them; do not widen unrelated runtime types.
- In `TransformsToResource::guessResourceName()`, use the full namespace segment before the final model class with `Str::beforeLast()`. Preserve Hypervel namespaces and the current resource guessing order.

### Tests

Port the exact originating source and integration tests, including:

- existing/non-existing model increment/decrement-each, extra values, events, event veto, quiet variants, timestamps, dirty/original state, class-deviable casts, and the dynamic `__call()` route;
- existing single-column increment/decrement event vetoes return `false` rather than violating their native return type;
- `saveOrIgnore()` inserted/conflicted rows, generated IDs, timestamps, events/vetoes, incrementing/non-incrementing keys, connection naming, invalid existing models, unique-column selection, and supported drivers;
- lazy closure invocation and non-invocation across builders and relations;
- direct and inherited `#[RouteKey]`, trait-carried attributes, explicit method override, and route binding;
- `BelongsToMany::touch()` with a non-default related key;
- failed soft-delete restore without a false `restored` event;
- private attributed scope rejection without recursion;
- MorphTo null owner-key/non-primitive matching;
- nested removed-scope propagation;
- multiple timestamp columns;
- backed/unit enum missing-model messages;
- encrypted ArrayObject property access;
- Stringable `casts()` metadata;
- nested resource namespace guessing.

Keep test-specific helper namespaces for generic model names and use the configured external-database traits for integration cases. Do not add placeholder tests for directly deprecated omissions.

## 13. Port current Schema, migration, connection, and provider parity

### Files

- Modify:
  - `src/database/src/Connection.php`;
  - `src/database/src/ConnectionInterface.php`;
  - `src/database/src/DatabaseManager.php`;
  - `src/database/src/MySqlConnection.php`;
  - `src/database/src/PostgresConnection.php`;
  - `src/database/src/SQLiteConnection.php`;
  - `src/database/src/UniqueConstraintViolationException.php`;
  - `src/database/src/DatabaseServiceProvider.php`;
  - `src/database/src/LostConnectionDetector.php`;
  - `src/database/src/Events/MigrationEvent.php`;
  - `src/database/src/Migrations/Migrator.php`;
  - `src/database/src/Console/DumpCommand.php`;
  - `src/database/src/Console/Migrations/FreshCommand.php`;
  - `src/database/src/Console/PruneCommand.php`;
  - `src/database/src/Schema/Blueprint.php`;
  - `src/database/src/Schema/Builder.php`;
  - `src/database/src/Schema/SchemaState.php`;
  - `src/database/src/Schema/MariaDbSchemaState.php`;
  - `src/database/src/Schema/Grammars/Grammar.php`;
  - `src/database/src/Schema/Grammars/MariaDbGrammar.php`;
  - `src/database/src/Schema/Grammars/PostgresGrammar.php`.
- Modify `src/database/src/Eloquent/Concerns/HasEvents.php`.
- Modify `src/support/src/Facades/Schema.php`.
- SQLite connector, builder, and schema-state changes are already specified in Section 6 and must be merged from the same current upstream files rather than implemented twice.
- Merge current tests into the matching Database unit and integration files, including new supported-driver coverage where Hypervel has no equivalent file yet.
Do not port SQL Server source or tests.

### Schema inspection and definition APIs

Add `Blueprint::foreignUuidFor()`:

```php
public function foreignUuidFor(Model|string $model, ?string $column = null): ForeignIdColumnDefinition
{
    if (is_string($model)) {
        $model = new $model;
    }

    $column = $column === null || $column === ''
        ? $model->getForeignKey()
        : $column;

    return $this->foreignUuid($column)
        ->table($model->getTable())
        ->referencesModelColumn($model->getKeyName());
}
```

Add `Builder::hasForeignKey()` using the same name-or-column matching contract as `hasIndex()`:

```php
public function hasForeignKey(string $table, array|string $foreignKey): bool
{
    foreach ($this->getForeignKeys($table) as $value) {
        if ($value['name'] === $foreignKey || $value['columns'] === $foreignKey) {
            return true;
        }
    }

    return false;
}
```

Add the matching annotation to Hypervel's hand-maintained Schema facade:

```php
@method static bool hasForeignKey(string $table, array|string $foreignKey)
```

Add the PostgreSQL column definition:

```php
public function tsvector(string $column): ColumnDefinition
{
    return $this->addColumn('tsvector', $column);
}

protected function typeTsvector(Fluent $column): string
{
    return 'tsvector';
}
```

Add MariaDB vector index compilation while retaining PostgreSQL's existing vector defaults:

```php
public function vectorIndex(string $column, ?string $name = null): Fluent
{
    [$algorithm, $operatorClass] = $this->grammar instanceof MariaDbGrammar
        ? [null, 'M=6 DISTANCE=cosine']
        : ['hnsw', 'vector_cosine_ops'];

    return $this->indexCommand(
        'vectorIndex',
        $column,
        $name,
        $algorithm,
        $operatorClass
    );
}
```

MariaDB's grammar compiles the native `vector index` clause, including the operator-class and optional lock text. Do not claim vector-index support on a grammar that does not compile it.

### Unique-constraint metadata

Give `UniqueConstraintViolationException` current public metadata:

```php
public ?string $index = null;

/** @var list<string> */
public array $columns = [];

public function setIndex(?string $index): self
{
    $this->index = $index;

    return $this;
}

/** @param list<string> $columns */
public function setColumns(array $columns): self
{
    $this->columns = $columns;

    return $this;
}
```

In the `Connection::runQueryCallback()` catch, preserve the existing error counter, parse the original driver exception, and publish its metadata on the wrapper:

```php
catch (Exception $driverException) {
    ++$this->errorCount;

    $exceptionType = ($isUniqueConstraintError = $this->isUniqueConstraintError($driverException))
        ? UniqueConstraintViolationException::class
        : QueryException::class;

    $queryException = new $exceptionType(
        $this->getName(),
        $query,
        $this->prepareBindings($bindings),
        $driverException,
        $this->getConnectionDetails(),
        $this->latestReadWriteTypeUsed(),
    );

    if ($isUniqueConstraintError) {
        ['index' => $index, 'columns' => $columns]
            = $this->parseUniqueConstraintViolation($driverException);

        $queryException->setIndex($index)->setColumns($columns);
    }

    throw $queryException;
}
```

Use the current driver parsers:

- MySQL/MariaDB extracts the offending index and returns no columns when the native message does not expose them reliably.
- PostgreSQL extracts the quoted constraint name and the `Key (...)` column list.
- SQLite extracts and de-qualifies the columns from `UNIQUE constraint failed`.

Keep a precise base return shape and add no speculative parser for unsupported or unrecognized message formats. An unparseable valid unique violation still throws the correct exception with `null`/empty metadata.

### Migration names and schema-dump data

Add nullable migration name state to `MigrationEvent` and thread the current migration name through both start and end events:

```php
public function __construct(
    public Migration $migration,
    public string $method,
    public ?string $name = null
) {
}
```

Preserve the existing public event property shape if Hypervel's current strict typing requires explicit declarations rather than promotion.

Add `--without-migration-data` to `schema:dump`. When selected, pass `null` as the migration table:

```php
$migrationTable = $this->option('without-migration-data')
    ? null
    : $migrationTable;

return $connection->getSchemaState()
    ->withMigrationTable($migrationTable)
    ->handleOutputUsing(/* existing output callback */);
```

`SchemaState::withMigrationTable()` must accept `?string`. Do not add another dump mode or configuration key.

### Remaining current connection and command corrections

Port these current corrections in place:

- PostgreSQL `compileColumns()` emits `null as collation` on servers before 9.1 rather than querying `pg_collation`, which does not exist there.
- PostgreSQL auto-increment starting values quote the fully wrapped table and exact column passed to `pg_get_serial_sequence()`, preserving custom schemas/connections.
- `model:prune` reports the actual singular `--model` option when combined with `--except`.
- `LostConnectionDetector` includes the two current additional native error strings from commits `dcf70c4b19` and `c0567a68aa`; preserve existing case-insensitive matching and do not generalize it into a regex engine.
- `migrate:fresh` treats a throwable `repositoryExists()` check as "repository absent", skips wipe, and lets the existing `migrate` path own database creation/prompting:

  ```php
  try {
      $repositoryExists = $this->migrator->repositoryExists();
  } catch (Throwable) {
      $repositoryExists = false;
  }
  ```

- `MariaDbSchemaState::detectClientVersion()` probes `mariadb --version`, falls back to the minimum MariaDB CLI version `10.5.2` when that process fails, and reports `isMariaDb => true`. Use the current upstream result, not the originating commit subject as implementation truth.

### Active PostgreSQL read/write prepare mode

Correct Hypervel's existing `isUsingEmulatedPrepares()` adaptation to inspect the PDO configuration selected for the query:

```php
protected function isUsingEmulatedPrepares(): bool
{
    $config = $this->latestReadWriteTypeUsed() === 'read'
        && $this->readPdoConfig !== []
            ? $this->readPdoConfig
            : $this->config;

    return (bool) ($config['options'][PDO::ATTR_EMULATE_PREPARES] ?? false);
}
```

Keep Hypervel's method name and boolean-binding adaptation. Do not port Laravel's unsupported direct/external connection branch. The bool cast accepts the native PDO option representations that configure the same behavior.

### Queue entity resolution

Bind the existing Database implementation to the existing Queue contract:

```php
protected function registerQueueableEntityResolver(): void
{
    $this->app->singleton(
        EntityResolver::class,
        QueueEntityResolver::class
    );
}
```

Call this from `DatabaseServiceProvider::register()` in current Laravel order. The contract represents behavior every queue entity resolver must provide, so this is the correct contract boundary; no new API is added.

### PHPDoc-only current parity

Apply current, evidence-based generic/callback PHPDoc to:

- `Connection::withoutPretending()`;
- `Connection::withoutTablePrefix()`;
- `DatabaseManager::usingConnection()`;
- `Migrator::usingConnection()`;
- `Eloquent\Concerns\HasEvents::withoutEvents()`;
- `Eloquent\Concerns\HasTimestamps::withoutTimestamps()` and `withoutTimestampsOn()`;
- `Eloquent\Model::withoutBroadcasting()`;
- `ConnectionInterface::transaction()`.

These changes must improve static return/callback inference without changing runtime code, widening contracts, adding casts, or introducing PHPStan-only branches.

### Tests

Port every supported current originating test:

- `foreignUuidFor()` infers model table, key, and default/custom column;
- `hasForeignKey()` matches names and column lists and returns false for absent definitions;
- PostgreSQL `tsvector` type and full-text vector use;
- MariaDB vector index SQL;
- MySQL, PostgreSQL, and SQLite unique index/column metadata with graceful unparseable messages;
- migration start/end event names for up/down and anonymous/named migrations;
- schema dump with and without migration data;
- SQLite URI coverage from Section 6;
- PostgreSQL pre-9.1 collation SQL and custom-schema sequence SQL;
- corrected prune validation message;
- new lost-connection strings;
- missing-database `migrate:fresh`;
- MariaDB CLI version success/fallback;
- active PostgreSQL read versus write emulated-prepare options;
- container resolution of `EntityResolver` to `QueueEntityResolver`;
- PHPDoc changes through PHPStan, not runtime-only placeholder tests.

External MySQL, MariaDB, PostgreSQL, and SQLite tests stay under `tests/Integration/Database` and use the repository's existing isolation/configuration conventions.

## 14. Apply the two approved Database performance corrections

### Files

- Modify `src/database/src/Eloquent/Concerns/HasAttributes.php`.
- Modify `src/database/src/Query/Grammars/Grammar.php`.
- Merge the originating cast tests into:
  - `tests/Integration/Database/DatabaseEloquentModelCustomCastingTest.php`;
  - `tests/Integration/Database/EloquentModelEncryptedCastingTest.php`.
- Extend `tests/Database/DatabaseQueryGrammarTest.php` for raw-SQL substitution.

### Merge only the requested cached cast on ordinary reads

Change `getAttributeFromArray()` from a full cached-cast merge to one key:

```php
protected function getAttributeFromArray(string $key): mixed
{
    $this->mergeAttributeFromCachedCasts($key);

    return $this->attributes[$key] ?? null;
}
```

Add the per-key composition:

```php
protected function mergeAttributeFromCachedCasts(string $key): void
{
    $this->mergeAttributeFromClassCasts($key);
    $this->mergeAttributeFromAttributeCasts($key);
}
```

Refactor the two existing full loops to delegate to their corresponding per-key methods:

```php
protected function mergeAttributesFromClassCasts(): void
{
    foreach ($this->classCastCache as $key => $value) {
        $this->mergeAttributeFromClassCasts($key);
    }
}

protected function mergeAttributeFromClassCasts(string $key): void
{
    if (! isset($this->classCastCache[$key])) {
        return;
    }

    $value = $this->classCastCache[$key];
    $caster = $this->resolveCasterClass($key);

    $this->attributes = array_merge(
        $this->attributes,
        $caster instanceof CastsInboundAttributes
            ? [$key => $value]
            : $this->normalizeCastClassResponse(
                $key,
                $caster->set($this, $key, $value, $this->attributes)
            )
    );
}
```

Apply the same shape to `$attributeCastCache`, preserving getter-only attributes and the existing fallback setter callback.

Retain a full `mergeAttributesFromCachedCasts()` immediately before:

- legacy get mutators;
- `Attribute` get mutators;
- legacy set mutators;
- `Attribute` set mutators.

Those mutators may inspect sibling attributes, so removing their full merge would be a behavior regression. Do not add dependency tracking between casts or mutators.

This change reduces work on ordinary attribute reads. It adds one constant-time cache check for the requested key while removing iteration and serialization of unrelated cached casts.

### Use indexed raw SQL binding substitution

Replace destructive front removal:

```php
$query = '';
$bindingIndex = 0;

// Existing SQL literal scan...
if ($char === '?' && ! $isStringLiteral) {
    $query .= $bindings[$bindingIndex++] ?? '?';
}
```

Keep the current literal/escaped-question-mark parser and ordinary binding escaping unchanged. Current Laravel's resource-binding correction is incomplete: it routes live and closed resources through driver binary escapers that still call `bin2hex()` and therefore throw. Normalize resource handles to their stable `Resource id #N` identity string at this observational raw-SQL boundary, then quote that identity as an ordinary string. Do not consume, rewind, or claim to reproduce stream contents that may already have been read or closed. This also removes repeated array reindexing and makes substitution linear in the number of bindings.

### Tests

Port the originating behavior/performance regressions:

- reading one cached cast does not serialize unrelated mutable custom casts;
- legacy and `Attribute` get/set mutators still see sibling cached-cast state;
- encrypted unrelated casts are not decrypted/serialized on another attribute read;
- mutable custom and date-like casts still synchronize back to raw attributes correctly;
- raw SQL substitution preserves quoted question marks, escaped question marks, missing bindings, and binding order, and renders live or closed resources as quoted `Resource id #N` identity placeholders without reading them;
- a long binding list produces identical SQL without asserting implementation timing.

No benchmark is required unless implementation uncovers uncertainty. Both changes remove source-proven work without adding machinery or changing results.

## 15. Complete package metadata, provenance, and intentional omissions

### Files

- Modify `src/database/composer.json`.
- Modify `src/redis/composer.json`.
- Modify `src/database/README.md`.
- Add `tests/Database/PackageMetadataTest.php`.
- Add `tests/Redis/PackageMetadataTest.php`.
- Add `REMOVED:` comments to the current natural upstream positions in:
  - `src/database/src/Eloquent/Factories/Factory.php`;
  - `src/database/src/Schema/Blueprint.php`;
  - `src/database/src/Grammar.php`;
  - `src/database/src/Query/Processors/MySqlProcessor.php`;
  - `src/database/src/Query/Grammars/PostgresGrammar.php`.

### Split dependencies

Add every direct runtime dependency proven by Database source:

```json
{
  "ext-pdo": "*",
  "ext-swoole": "^6.2",
  "hypervel/coordinator": "^0.4",
  "hypervel/engine": "^0.4",
  "hypervel/prompts": "^0.4",
  "psr/log": "^3.0",
  "symfony/finder": "^8.1"
}
```

These constraints match the root manifest and sibling split-package conventions. Keep `hypervel/collections`, `hypervel/conditionable`, and `hypervel/macroable`; their symbols are directly consumed through Hypervel Support traits/types. Remove `hypervel/config` only after deleting `UnsetContextInTaskWorkerListener`, because that listener is the package's only direct concrete Config use.

Add these direct Redis runtime requirements:

```json
{
  "ext-swoole": "^6.2",
  "hypervel/core": "^0.4",
  "psr/log": "^3.0"
}
```

The provider and lifecycle listener consume Core lifecycle events, while existing Redis source directly imports `Swoole\Coroutine\CanceledException` and `Psr\Log\LogLevel`. Keep phpredis in `suggest` because the package retains its existing optional connector contract.

Do not add transitive dependencies that no package source imports. Root `composer.json` already supplies the monorepo development graph; edit it only if the final direct dependency set is not already available there.

### Metadata test

Add the standard split-manifest regression:

```php
public function testDirectRuntimeDependenciesAreDeclared(): void
{
    $composer = json_decode(
        file_get_contents(__DIR__ . '/../../src/database/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    foreach ([
        'ext-pdo',
        'ext-swoole',
        'hypervel/coordinator',
        'hypervel/engine',
        'hypervel/prompts',
        'psr/log',
        'symfony/finder',
        // Retain the complete existing direct list.
    ] as $dependency) {
        $this->assertArrayHasKey($dependency, $composer['require']);
        $this->assertIsString($composer['require'][$dependency]);
        $this->assertNotSame('', trim($composer['require'][$dependency]));
    }

    $this->assertArrayNotHasKey('hypervel/config', $composer['require']);
}
```

The final test lists all direct runtime dependencies, not only the newly added ones.

Add the same standard regression for the Redis split manifest. Its final list includes every direct runtime dependency and specifically proves `ext-swoole`, `hypervel/core`, and `psr/log` are declared without moving optional `ext-redis` out of `suggest`.

### Provenance and deprecated omissions

Add the standard README provenance line:

```md
Ported from: https://github.com/laravel/framework
```

Keep all three existing `Differences From Laravel` bullets. Add one concise grouped bullet explaining that directly deprecated Laravel compatibility forwarding is intentionally omitted and callers should use the current non-deprecated owner APIs.

At each natural source position, add a concise marker naming the omitted upstream surface and replacement:

```php
// REMOVED: Laravel's deprecated singular model-name resolver compatibility
// is omitted; use the class-keyed modelNameResolvers map.
```

The complete directly deprecated omitted set is:

- Factory's singular `$modelNameResolver`;
- Blueprint's `commandsNamed()`, `getPrefix()`, and `getChangedColumns()`;
- Database Grammar's `getTablePrefix()` and `setTablePrefix()` forwarding;
- MySQL Processor's `processColumnListing()` forwarding;
- PostgreSQL Grammar's misspelled `cascadeOnTrucate()`.

Use the exact current non-deprecated replacement in each final marker. Do not implement shims, compatibility aliases, dead wrappers, or placeholder tests. These are current Laravel deprecations, not arbitrary API divergence.

## 16. Update task-first user documentation

### Files and placement

- Modify `src/boost/docs/migrations.md`.
- Modify `src/boost/docs/queries.md`.
- Modify `src/boost/docs/eloquent.md`.
- Modify `src/boost/docs/routing.md`.

Match the surrounding Laravel-style headings, anchors, examples, language, and table of contents. Keep the content concise and oriented around building applications and packages; do not document lifecycle internals.

### Migrations

At the existing model-aware foreign-ID section, add `foreignUuidFor()` with a UUID-key model example and state that it derives the column, table, and referenced model key.

At the existing schema inspection section, add `hasForeignKey()` and show both name and column-list checks.

At the available column types/indexes sections:

- document PostgreSQL `tsvector()` for stored full-text vectors;
- document MariaDB `vectorIndex()` and its supported-driver boundary;
- keep the existing PostgreSQL `vector`/pgvector documentation distinct.

At the existing schema-dump and migration-event sections:

- document `schema:dump --without-migration-data` as omitting migration-table rows while retaining the schema;
- state that `MigrationStarted` and `MigrationEnded` expose the migration filename through `$name`.

### Query builder

Add brief sections at the natural existing headings:

- **Joins:** `straightJoin()`, `straightJoinWhere()`, and `straightJoinSub()`, explicitly MySQL/MariaDB only.
- **Where clauses:** port current Laravel's `whereNullSafeEquals()` and `orWhereNullSafeEquals()` section, explaining that two null values compare equal.
- **Ordering:** `inOrderOf()` with a preferred value sequence.
- **Select/query execution:** `timeout()` as a MySQL/MariaDB per-query seconds limit.
- **Insert statements:** `insertOrIgnoreReturning()` with returned rows, optional conflict columns, supported PostgreSQL/SQLite boundary, and the distinction from `insertOrIgnore()`.

Examples must use the real final signatures and avoid internal grammar terminology.

### Eloquent

At the existing insert/update sections:

- document `saveOrIgnore()` for a new model and state that it returns `false` on a matching conflict;
- document model-instance `incrementEach()` and `decrementEach()`;
- mention the quiet variants beside their event behavior;
- retain the already-correct `withoutRelation()` section and Query-builder increment/decrement-each material without duplicating it.

State the same supported-driver boundary for `saveOrIgnore()` that the underlying returning API enforces.

### Routing

Beside the current `getRouteKeyName()` override example, add the class attribute alternative:

```php
use Hypervel\Database\Eloquent\Attributes\RouteKey;

#[RouteKey('slug')]
class Post extends Model
{
    // ...
}
```

Explain that the attribute applies consistently to implicit route model binding while an explicit method override remains available.

### Documentation verification

After editing:

- compare each addition with at least the neighboring section and the corresponding current Laravel docs where present;
- search every documented method against final source signatures;
- ensure every linked anchor/table-of-contents entry resolves;
- ensure no unsupported SQL Server/direct-connection/deprecated surface appears;
- ensure internal task, fork, pool, transaction, mutex, and URI-classifier mechanics stay out of user docs.

Do not add a new exception-metadata section solely to inventory `UniqueConstraintViolationException::$index` and `$columns`; the typed, tested class is the natural reference for that specialized surface. Do not inventory every `Closure|array` lazy-value overload on established Eloquent methods.

## 17. Remove every superseded path

The implementation is incomplete until the old ownership model is gone.

Delete:

- `src/database/src/Listeners/UnsetContextInTaskWorkerListener.php`;
- its reflective listener tests and configured-base-name cleanup assumptions;
- duplicate SQLite memory/URI substring checks replaced by `SQLiteDatabase`;
- RefreshDatabase's default-only literal `:memory:` check and per-loop default reuse;
- tests that expect multiple independently borrowed wrappers around one shared in-memory PDO;
- transaction-manager callback-before-detach paths;
- any rollback retry path that can run before physical/logical cleanup succeeds;
- stale README or Boost wording contradicted by the final public APIs;
- stale `hypervel/config` split dependency after its final direct use disappears.

Replace rather than retain:

- `CoroutineContext::set($key, null)` terminal cleanup with `forget($key)`;
- one deferred release registration per successful Redis same-connection pin with one registration per pool per coroutine;
- manager-name-derived Redis context cleanup with proxy-owned key derivation;
- release-before-pool-destruction purge/fork cleanup with exact discard;
- raw SQLite filename reuse with canonical attached-database lookup;
- full cached-cast merge on ordinary single-attribute reads with per-key merge;
- `array_shift()` raw SQL substitution with indexed access.

Do not leave:

- compatibility shims for omitted deprecated Laravel APIs;
- a generic task cleanup registry;
- a lifecycle listener priority mechanism;
- a generic lease proxy;
- a transaction state machine/finalizer/callback executor;
- a second SQLite lock, shared-cache URI rewrite, or wrapper coordinator;
- a PHP mirror of native Redis queue mode;
- a callback-specific eager-release marker or marker helper trio;
- a `WeakMap`, registry, per-coroutine key suffix, or `ReplicableContext` object for deferred-release ownership;
- a mark-invalid fallback that masks `Pool::discard()` ownership failure;
- comments documenting abandoned designs;
- placeholder or implementation-detail-only tests.

Before validation, run repository-wide searches for every removed class, helper, property, context-key reconstruction, stale dependency, and deprecated symbol. Every remaining hit must be an intentional README/source `REMOVED:` record or a current test assertion.

## 18. Regression and validation plan

### Focused lifecycle matrix

| Boundary | Success coverage | Failure coverage |
|---|---|---|
| `TaskCallback` | OnTask result finishing followed by terminal event | OnTask failure, finish failure, terminal failure, and all combinations preserve the earliest throwable while still attempting terminal cleanup |
| Database task cleanup | Exact base/read/write wrappers release once at task end; aliases of one shared in-memory SQLite PDO reuse one canonical owner | Partial acquisition or write-role configuration failure discards the exact wrapper; one release failure does not skip siblings |
| Database fork cleanup | Resolved wrapper discard plus resolved pool flush | Unresolved owners stay unresolved; resolver failure does not skip factory flush; earliest failure wins |
| Redis task/coroutine cleanup | Raw same-connection state remains pinned through one task then releases; one terminal defer per pool/coroutine owns raw pins while callback-form operations release immediately; copied child context publishes its own defer owner; callback-form transaction completion settles a consumed watch | Repeated callback calls produce one terminal no-op rather than unbounded deferred closures; callback-then-raw and copied-child raw pins remain terminally owned; divergent proxy name, release failure exhaustion, no double release, and no false WATCH discard/log after successful callback transaction |
| Redis fork/purge | Exact proxy discard and actual proxy pool flush | Manager/factory independently unresolved or failing; invariant failure propagates |
| Redis command events | Listener observation precedes ownership handoff | Success/failure listener exceptions cannot skip release or same-connection handoff; existing event precedence and cleanup failures remain truthful |
| Redis transaction state | ATOMIC, unwatched clients restore selected database and requeue; WATCH through EXEC stays on one native client | MULTI/PIPELINE/abandoned WATCH discards; log failure cannot prevent discard; mode/restore failure invalidates; discard ownership failure cannot fall through to requeue |
| SQLite classifier | Literal memory, encoded memory, named memory, file URIs, canonical attached path | Invalid/mixed-case modes follow native behavior; duplicate `mode` uses final value; write false throws descriptively |
| RefreshDatabase SQLite ownership | Any named memory connection triggers restore; mixed connection sets cache only their memory PDOs | A file-backed default cannot suppress named-memory restore/caching, and a memory default cannot make named file PDOs process-static |
| In-memory pool | One owner serializes through existing channel | Invalid option types/counts still fail in `PoolOption`; no normalization masks configuration errors |
| Transaction begin | Physical begin and logical/manager/event publication agree | Manager/event publication failure rolls back to prior level and preserves the primary error |
| Managed `transaction()` commit | Listener, physical commit, manager callbacks, and event publish in order | Pre-commit failure rolls back; deadlock retry only after cleanup; lost commit detaches terminally; cleanup failure prevents retry and never replaces primary |
| Explicit `commit()` | Physical commit before logical decrement | Committing listener or physical failure leaves active caller-owned state |
| Rollback | Logical level updates after physical success | Non-lost failure keeps truthful active state; lost failure detaches terminally; manager/event failures run independently after physical success |
| Disconnect | Physical rollback, manager detach, and PDO nulling all complete | Every phase is attempted and both PDOs become null; lost physical cleanup is already terminal, manager failure remains observable, and non-lost physical failure stays primary even from logical level zero |
| Transaction records | Full/partial rollback detach the exact record set before callbacks | Re-entry cannot see detached records; rollback callbacks exhaust deepest-first; commit callbacks retain upstream stop-on-first |
| Eloquent boot | First owner publishes once; sibling waits; post-publication recursion returns | Pre-publication recursion throws; pre-publication failure retries; post-publication failure remains booted while clearing owner/lock |

### Current upstream test porting

For every originating commit listed in this plan:

1. inspect its full changed-file list again;
2. reopen the corresponding current Laravel source and tests;
3. merge every supported current test into the matching Hypervel file;
4. retain Hypervel-specific coroutine, pool, immutable-date, strict-type, and external-service coverage;
5. remove only the explicitly approved SQL Server, dynamic/direct connection, and directly deprecated cases;
6. run each changed test file before moving to the next.

Historical tests are discovery aids. If a current file contains follow-up cases not present in the originating diff, port the current cases.

### Focused commands

Run the changed files individually while implementing:

```bash
./vendor/bin/phpunit --no-progress tests/Core/Bootstrap/TaskCallbackTest.php
./vendor/bin/phpunit --no-progress tests/Server/ServerTest.php
./vendor/bin/phpunit --no-progress tests/Database/ConnectionResolverTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseTransactionsManagerTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseEloquentModelTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseQueryBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseSchemaBlueprintTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisProxyTest.php
./vendor/bin/phpunit --no-progress tests/Redis/RedisManagerTest.php
./vendor/bin/phpunit --no-progress tests/Scout/Feature/SearchableModelTest.php
./vendor/bin/phpunit --no-progress tests/NestedSet
./vendor/bin/phpunit --no-progress tests/Telescope/Watchers/QueryWatcherTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/Sqlite/Console/MigrateFreshCommandWithJournalModeWalTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/Testing/Concerns/InteractsWithParallelDatabaseTest.php
./vendor/bin/phpunit --no-progress tests/Testing/Concerns/TestDatabasesTest.php
./vendor/bin/phpunit --no-progress tests/Testbench/DefaultConfigurationTest.php
```

Then run focused package groups:

```bash
./vendor/bin/phpunit --no-progress tests/Core tests/Server
./vendor/bin/phpunit --no-progress tests/Database
./vendor/bin/phpunit --no-progress tests/Redis
```

Run configured integration groups:

```bash
./vendor/bin/phpunit --no-progress tests/Integration/Database
./vendor/bin/phpunit --no-progress tests/Integration/Redis
```

The Redis and database integration tests must use the repository's worker-isolation traits and assigned `TEST_TOKEN`. Do not hardcode worker tokens, database numbers, ports, or shared temporary paths.

### Static analysis and full gate

After focused tests are green:

```bash
composer fix
```

`composer fix` is authoritative and already runs formatting, both PHPStan configurations, the full ParaTest suite, the Testbench contract suite, and Testbench dogfood. Do not substitute a package-only run for it.

Inspect all failures and skips normally. Fix source defects at their real boundary; do not weaken tests, add PHPStan-driven runtime branches, or suppress correct types. If implementation exposes an unexpected bug, edge case, lower-level contradiction, or same-family omission, stop that code path and use the required focused second-opinion loop before continuing.

## 19. Fresh post-implementation self-review

After `composer fix` passes, review the entire diff without trusting this plan:

1. Re-read every changed source file in full or consecutive chunks.
2. Trace every new event from producer through all listeners and every throwing phase.
3. Trace each Database and Redis wrapper from borrow through publication, use, release/discard, pool bookkeeping, and close failure.
4. Re-run the parent-before-fork and child-start source trace and confirm no listener resolves a service only to clean it.
5. Trace SQLite classification through pool construction, connector creation, schema CLI routing, canonical path discovery, and refresh failure.
6. Trace physical PDO state, logical transaction count, manager records, callbacks, events, retry decisions, pooled reset, and disconnect through every exception edge.
7. Trace Eloquent first boot through same-owner recursion, sibling waiting, publication, post-publication hooks, failure, reset, and test cleanup order.
8. Compare every ported method, signature, member position, test, and doc example with current Laravel `13.x`, not historical diffs.
9. Search the whole repository for every changed contract, event, method, context key, deprecated omission, and documentation claim.
10. Re-run both the literal `':memory:'` comparison sweep and the `mode=memory` family sweep; production classification outside `SQLiteDatabase` must be absent.
11. Re-check package dependency ownership from imports after all deletions/additions.
12. Check hot paths for new container resolution, locks, context calls, allocations, logging, yields, retries, and retained worker memory.
13. Check for dead helpers, duplicate cleanup, defensive guards that mask invariants, stale comments, and mechanisms with only hypothetical consumers.

Fix straightforward omissions immediately and rerun proportionate tests. Any newly discovered design issue goes through the required second-opinion workflow.

Then request a code review of the complete implementation, tests, docs, metadata, ledger updates, and validation. Continue until sign-off.

## 20. Performance and overengineering assessment

### Successful hot-path effects

| Change | Frequency | Effect |
|---|---|---|
| Database shared-memory alias ownership | First requested-name resolution in an execution context | One local shared-PDO property read; only unsplit in-memory SQLite aliases add a canonical context lookup, preventing self-exhaustion without query or network I/O |
| Eloquent boot owner `isset()` | Every normal model construction | One static array lookup; owner-approved correctness cost |
| Redis native `getMode()` | Every successful phpredis pooled release | One local extension-state read, no network I/O; owner-approved correctness cost |
| Redis deferred-release deduplication | Each successful same-connection publication with no current pin | One coroutine-ID read in place of the planned coroutine-presence read plus one context owner comparison; one integer write and one closure registration per pool per coroutine, with no ordinary-command or network work; owner-approved correctness cost |
| Redis event cleanup slots | Every Redis proxy command | Three local throwable slots and direct branches around work already performed; owner-approved correctness cost |
| Redis transaction-state routing | Every Redis wrapper/proxy command and release | Exact string comparisons for WATCH/DISCARD state and one boolean release read; callback-form multi-exec adds one string/boolean branch, while only an already-pinned successful transaction performs one context lookup and boolean clear; no lock or network I/O; owner-approved correctness cost |
| Per-key cached-cast merge | Ordinary Eloquent attribute reads | Removes unrelated cached-cast iteration/serialization; performance improvement |
| Indexed raw SQL substitution | Query SQL formatting/logging | Removes repeated array reindexing; performance improvement |

The Eloquent coroutine-ID lookup and Mutex acquisition occur only during incomplete first publication. Task and fork events are cold lifecycle boundaries. SQLite classification/capacity normalization happens at connection/pool/schema setup. Transaction additions are direct fixed-phase exception handling; successful query execution gains no registry, state machine, lock, retry, logging, container lookup, or yield.

### Retained worker memory

- One bounded `PooledConnection` entry per requested Database connection name only while a non-coroutine task owns it.
- Existing Redis proxies own their already-required pinned context; a coroutine that uses same-connection commands retains one integer owner-ID context slot and one terminal closure per pool until it exits, with no parallel registry or per-call growth.
- One boolean belongs to each already-existing Redis connection wrapper and describes only that native generation's unobservable watch state.
- One integer boot owner only while a model class is publishing.
- One existing Mutex channel per model class first booted in a coroutine remains for the worker lifetime; non-coroutine first boot creates none.
- No shared SQLite cache, transaction registry, cleanup registry, or PHP Redis mode mirror is added.

### Explicit anti-overengineering decisions

- Two additive lifecycle events each have multiple verified consumers and replace incomplete local workarounds.
- The SQLite classifier has four real consumers and deletes duplicated incompatible checks.
- The transaction implementation uses direct phase-specific control flow; no generic abstraction is introduced.
- The Redis proxy owns its real context identity; the manager only aggregates existing proxies.
- One coroutine-ID-owned terminal Redis defer per pool replaces duplicate per-pin registration while callback-form release stays immediate and copied child context self-registers; no eager-release marker, object sentinel, registry, or second cleanup path is added.
- Redis command-event cleanup uses direct local throwable slots, watch state is one wrapper boolean because phpredis exposes no equivalent getter, and native DISCARD gets one explicit route around the existing Pool method collision.
- Public contracts are unchanged except for supported current Laravel API parity. Concrete internal lifecycle methods remain off contracts.
- Directly deprecated Laravel compatibility surfaces remain omitted rather than reintroduced.
- Tests target supported behavior and reproduced failures, not impossible internal states.
- No benchmark, configuration option, event priority, generic extension point, or speculative retry policy is added.

If the final implementation needs more machinery than this section describes, treat that as a design warning and re-establish the evidence and lowest owner before proceeding.

## 21. Completion criteria

This work unit is complete only when:

- every accepted `database-05` through `database-13` and `redis-03` through `redis-08` finding is implemented at the specified owner;
- both lifecycle events have Database and Redis consumers, deterministic failure precedence, and focused tests;
- no pooled Database or Redis resource can cross the supported terminal task or process boundary unowned;
- no Redis client in native MULTI/PIPELINE or abandoned WATCH state can be requeued, repeated callback-form operations can accumulate only one terminal defer per pool/coroutine while still releasing immediately, callback-then-raw and copied-child raw pins remain terminally owned by the correct coroutine ID, callback-form optimistic transactions clear consumed watch state without false discard/logging, native `Redis::discard()` reaches phpredis rather than Pool lifecycle teardown, and command-listener failure cannot leak its borrowed wrapper;
- every supported SQLite URI/memory form is classified consistently and one in-memory PDO has one owner;
- physical transaction state, logical state, manager records, callbacks, events, retry decisions, and pooled reuse remain truthful on every covered edge;
- Eloquent first boot is recursive-safe, coroutine-safe, retryable before publication, and Laravel-compatible after publication;
- every supported current Laravel Query, Eloquent, Schema, migration, connector, provider, exception, and PHPDoc item listed here is present with current tests;
- both approved performance corrections are present with behavior regressions;
- manifests, provenance, omission markers, and user docs are complete and accurate;
- every superseded class, path, dependency, comment, and test assumption is removed;
- changed tests, focused package tests, configured integration tests, and `composer fix` pass;
- fresh self-review finds no unowned resource, stale state, unsupported divergence, hot-path regression beyond the approved noise-level costs, or unjustified mechanism;
- post-implementation code review is signed off;
- the companion ledger and main audit routing/checklist are updated only after the implementation is complete and validated;
- the owner receives the final self-contained summary and explicitly approves commits under the main audit workflow.
