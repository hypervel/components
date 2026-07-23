# Physical Database Session Configuration

## Status

Implementation plan for a Hypervel database enhancement. The source investigation, external-reference check, and pre-plan second-opinion loop are complete. This document records the settled final design.

This work adds a generic, driver-neutral way for packages and applications to keep context-sensitive database session settings synchronized with the exact physical PDO used by each operation in Hypervel's long-lived pooled workers. PostgreSQL row-level-security GUCs are the first known consumer, but neither the public API nor the database implementation is tenancy- or RLS-specific.

Backward compatibility, churn, and minimizing the diff are not design constraints. The implemented result must read as though Hypervel's pooled database layer was designed with physical-session configuration from the start. That does not permit speculative machinery: every mechanism below closes a source-verified correctness, isolation, lifecycle, diagnostics, or performance requirement.

## Scope

Implement all of the following as one coherent database-layer change:

- add a small typed public configurator contract and one Laravel-shaped boot-time registrar;
- synchronize the complete desired state at the public PDO hand-out boundary;
- memoize applied state by exact physical PDO, independently for read and write sessions;
- preserve known state across clean pool release and successful commit;
- invalidate or taint state on the transaction and failure paths that can make the memo false;
- compose with Hypervel's existing lost-connection and transaction retry behavior;
- prevent unknown or reentrantly configured physical sessions from reaching application SQL or returning to the pool as healthy;
- keep transaction-control operations usable while PostgreSQL is in an aborted transaction;
- document the public API, raw escape hatches, lifecycle, pooler constraints, and performance model;
- add deterministic unit, pool, coroutine, all-supported-driver integration, and PostgreSQL-specific coverage;
- remove or rewrite every touched comment, test, and documentation statement that describes the old lifecycle incompletely.

The implementation does **not** add RLS policies, tenant context, tenant identifiers, package-specific GUC names, or consumer configuration. Those belong to the consuming tenancy package.

## Desired final architecture

| Concern | Final owner and rule |
|---|---|
| Configurator registration | Worker-static ordered list on `Connection`; boot-only; cleared by the existing `Connection::flushState()` test hook |
| Desired logical state | The configurator reads current coroutine/application context lazily in `state()`; `Connection` never caches request context |
| Applied physical state | One worker-static `WeakMap<PDO, PhysicalSessionState>`, keyed by exact PDO identity |
| Ordinary PDO hand-out | `getPdo()` / `getReadPdo()` resolve, recover if necessary, synchronize, then return |
| Raw escape | `getRawPdo()` / `getRawReadPdo()` remain unresolved and unsynchronized; protected raw resolvers exist only for internal control statements |
| Transaction start | Synchronize before the outer `BEGIN`, so the established session state is outside the transaction being started |
| Transaction control | Savepoint creation, both outer commit sites, full rollback, and savepoint rollback use the raw resolved write PDO and never invoke configurators |
| Clean release | Reset wrapper/request state but preserve the truthful physical-session memo and server state |
| Successful commit | Preserve the memo because session-level state survives commit |
| Rollback or failed commit | Invalidate the memo; mark unknown only when the physical outcome is genuinely ambiguous |
| Failed apply / reentry | Mark the exact PDO unknown; never return it; replace it when possible |
| Unknown pooled state | Release marks the wrapper invalid; normal refresh prepares a complete replacement before disconnecting the current generation and fails invalid; shared in-memory SQLite remains fail-closed because reconnect retains the same PDO |
| Retry | Reuse existing query/BEGIN lost-connection retry and transaction concurrency retry; add no second retry subsystem |

## Finding summary

| Finding | Category | Severity | Evidence |
|---|---|---|---|
| A coroutine retains one borrowed `Connection` while its logical context can change | Isolation gap | Critical for context-sensitive sessions | `ConnectionResolver::connection()` caches the connection in `CoroutineContext` until deferred release |
| Pool release resets PHP wrapper state but intentionally performs no server-session reset | Missing extension point | Major | `PooledConnection::release()` calls `resetForPool()`; heartbeat is raw `SELECT 1`; no `RESET` / `DISCARD` occurs |
| Existing `beforeExecuting()` callbacks run before reconnect and are not replayed by the direct lost-query retry | Wrong seam for this job | Critical | `Connection::run()` invokes callbacks before `reconnectIfMissingConnection()`; `tryAgainIfCausedByLostConnection()` calls `runQueryCallback()` directly |
| Wrapper-local memoization is false when a PDO is replaced or shared | Isolation gap | Critical | read/write PDOs are distinct; reconnect swaps PDOs; shared in-memory SQLite exposes one PDO through multiple wrappers |
| Synchronizing inside rollback can block recovery from an aborted PostgreSQL transaction | Recovery defect | Critical | rollback currently obtains PDO through `getPdo()`; a configurator SQL statement cannot run in an aborted transaction |
| PostgreSQL rolls back session-level `SET` changes made inside an aborted transaction or after a savepoint | Memo invalidation requirement | Critical | PostgreSQL `SET` documentation and real integration behavior |
| A failed COMMIT can be the rollback boundary without calling `rollBack()` | Memo invalidation requirement | Critical | `testTransactionRetriesOnSerializationFailure()` expects repeated BEGIN/COMMIT attempts and no explicit rollback |
| A nested MySQL deadlock can be an automatic full rollback without calling `rollBack()` in that handler | Memo invalidation requirement | Major | `handleTransactionException()` explicitly takes this branch when a concurrency error occurs above transaction level one |
| `disconnect()` directly rolls back and a shared PDO can outlive the wrapper | Memo invalidation requirement | Major | `Connection::disconnect()` bypasses `ManagesTransactions::rollBack()`; `DbPool` retains the shared SQLite PDO |
| Pooled refresh disconnects the current wrapper before both fresh PDOs are ready | Failure-path lifecycle defect | Major | A failed fresh write hand-out leaves null PDOs; a failed fresh read hand-out leaves a partial write-only generation; `check()` does not inspect PDO presence |
| Public `getPdo()->query()` is documented and supported | API boundary | Major | `src/boost/docs/database.md` documents direct PDO access |
| Raw retained PDO handles cannot be intercepted after hand-out | Explicit escape hatch | Expected | PDO owns subsequent method calls; the framework can only synchronize at hand-out |

## References checked

### Hypervel source and tests

- `src/database/src/Connection.php`
- `src/database/src/ConnectionInterface.php`
- `src/database/src/Concerns/ManagesTransactions.php`
- `src/database/src/MySqlConnection.php`
- `src/database/src/SQLiteConnection.php`
- `src/database/src/DatabaseManager.php`
- `src/database/src/ConnectionResolver.php`
- `src/database/src/SimpleConnectionResolver.php`
- `src/database/src/Pool/DbPool.php`
- `src/database/src/Pool/PooledConnection.php`
- `src/database/src/Pool/PoolFactory.php`
- `src/database/src/Query/Processors/Processor.php`
- `src/database/src/QueryException.php`
- `src/database/src/DetectsLostConnections.php`
- `src/database/src/DetectsConcurrencyErrors.php`
- `src/queue/src/Queue.php`
- `src/session/src/Middleware/StartSession.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `src/foundation/src/Testing/DatabaseConnectionResolver.php`
- `src/boost/docs/database.md`
- `tests/Database/ConnectionTest.php`
- `tests/Database/DatabaseConnectionTest.php`
- `tests/Database/DatabaseTransactionsTest.php`
- `tests/Integration/Database/PooledConnectionTest.php`
- `tests/Integration/Database/ConnectionCoroutineSafetyTest.php`
- the MySQL, MariaDB, PostgreSQL, and SQLite integration base classes
- `tests/Integration/Database/Postgres/PooledConnectionStateTest.php`
- `.github/workflows/databases.yml`

Broad searches across all of `src/` and `tests/` found exactly seven database statement-execution closures that resolve a PDO: six in `Connection` and the MySQL insert override. Other direct PDO consumers are transaction control, schema import, escaping/server introspection, insert-ID retrieval, or explicit low-level access.

### Laravel/Hypervel API precedents

- `Queue::createPayloadUsing()` establishes the boot-only, ordered, appending `...Using()` registrar shape.
- `StartSession::configureSessionCookieUsing()` establishes the `configure...Using` verb.
- `Connection::getPdo()` is existing Laravel-compatible public API; this enhancement strengthens its Hypervel pooled-runtime semantics rather than inventing a parallel executor.
- There is no Laravel physical-session configurator to port. The API is intentionally Hypervel-owned because the need comes from long-lived pooled workers.

### External references

- PostgreSQL `SET`: <https://www.postgresql.org/docs/current/sql-set.html>
  - a session-level `SET` issued inside a transaction disappears if that transaction aborts;
  - it persists after commit;
  - rollback to an earlier savepoint cancels `SET` and `SET LOCAL` changes made after that savepoint.
- PostgreSQL runtime settings and `set_config`: <https://www.postgresql.org/docs/current/config-setting.html> and <https://www.postgresql.org/docs/current/functions-admin.html>.
- PostgreSQL rollback to savepoint: <https://www.postgresql.org/docs/current/sql-rollback-to.html>.
- PHP `WeakMap`: <https://www.php.net/weakmap>. A key does not keep its object alive and its entry disappears with the key.
- PHP weak-map RFC: <https://wiki.php.net/rfc/weak_maps>.
- PgBouncer feature matrix: <https://www.pgbouncer.org/features.html>. Session pooling supports `SET` / `RESET`; transaction pooling does not.
- PgBouncer reset behavior: <https://www.pgbouncer.org/config.html#server_reset_query>. Its reset query belongs to PgBouncer's external client-session release, not Hypervel's internal wrapper release.

The project runtime also verified the disputed ephemeron edge directly:

```bash
php -r 'class State { public function __construct(public object $key) {} } $map = new WeakMap; $key = new stdClass; $map[$key] = new State($key); unset($key); gc_collect_cycles(); var_export(count($map));'
# 0
```

No `WeakReference` is required. The planned state value holds no PDO reference anyway.

## Current execution and lifecycle trace

### Ordinary statements

`Connection::run()` currently:

1. invokes `beforeExecutingCallbacks`;
2. reconnects only when the write PDO property is null;
3. calls the operation closure through `runQueryCallback()`;
4. catches `QueryException`;
5. outside a transaction, reconnects and calls `runQueryCallback()` again directly when the previous exception is a lost connection.

The operation closure resolves the actual PDO only after the pretend guard:

| Public operation | PDO path |
|---|---|
| `select()`, `selectResultSets()`, `cursor()` | `getPdoForSelect()` → read or write getter |
| `statement()`, `affectingStatement()`, `unprepared()` | write getter |
| `MySqlConnection::insert()` | write getter |

Putting synchronization in the public getters keeps all seven closures covered on first execution and direct retry. It also covers the documented immediate `getPdo()->query()` idiom without adding seven callbacks or changing `run()`.

### Transaction control

The current control sites are:

- outer BEGIN in `Connection::executeBeginTransactionStatement()` and `SQLiteConnection::executeBeginTransactionStatement()`;
- savepoint creation in `ManagesTransactions::createSavepoint()`;
- inline outer commit in `transaction()`;
- public outer commit in `commit()`;
- the nested-concurrency branch where the driver has already rolled back the whole physical transaction;
- full rollback and savepoint rollback in `performRollBack()`;
- direct rollback during `Connection::disconnect()`;
- abandoned-transaction rollback during `PooledConnection::release()`.

Only outer BEGIN must synchronize. Every other control operation must use an already-resolved raw write PDO so recovery remains possible when the transaction is aborted.

### Pool lifecycle

`PooledConnection::release()`:

- captures `errorCount`;
- calls `Connection::resetForPool()`, which changes PHP wrapper/request state only;
- marks high-error connections invalid;
- rolls an abandoned transaction back;
- optionally dispatches `ReleaseConnection`;
- requeues the wrapper.

`ping()` runs only a raw `SELECT 1`. Normal refresh/reconnect replaces the PDO. It must resolve both synchronized replacement handles before disconnecting the current wrapper and mark the pool adapter invalid if any part of refresh fails; otherwise a configurator failure can leave null or partially swapped PDOs that `check()` still treats as healthy. Shared in-memory SQLite is the deliberate exception: the pool owns one PDO and binds new wrappers back to that exact object.

Therefore a clean release neither changes physical session state nor needs memo invalidation. Clearing only the PHP memo would not prevent inheritance; it would merely force a redundant configuration SQL round trip on the next same-state borrow.

## Fixed public API

### `SessionConfigurator`

Add `src/database/src/SessionConfigurator.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Database;

use PDO;

interface SessionConfigurator
{
    /**
     * Return the complete desired state identity for the connection.
     *
     * Return null only when this configurator does not apply to the connection.
     * The value must completely identify the state that apply() establishes.
     * This method runs on every synchronized PDO hand-out and must not execute
     * database work.
     */
    public function state(Connection $connection): ?string;

    /**
     * Apply the complete desired state to the physical database session.
     *
     * Use the given PDO directly. Calling Connection query APIs from this
     * method is reentrant and fails closed.
     */
    public function apply(PDO $pdo, string $state, Connection $connection): void;
}
```

Contract details:

- The state string is opaque to Hypervel and compared strictly.
- Empty string is a valid state. Only `null` is the not-applicable sentinel.
- `null` must never be used by a security-sensitive consumer to mean “no current context”; that consumer must return an explicit fail-closed state identity.
- Applicability must be stable for a connection: returning `null` means the configurator does not own state on that connection at all, not that state it established earlier should be left behind for one request.
- The state value must identify everything the configurator owns. `apply()` must establish exactly that complete state and return only after it is complete.
- `apply()` must establish physical-session state, not transaction-local state. PostgreSQL consumers use session-level `SET` / `set_config(..., false)`, not `SET LOCAL` / `set_config(..., true)`.
- Configurators must be stateless/worker-safe services. They may read coroutine-local context lazily, but must not capture request data in their constructor or worker-static properties.
- `state()` is a hot-path pure computation. Consumers should precompute canonical state strings on immutable context frames rather than encode or hash on every query.
- `apply()` uses the passed PDO directly and parameterized SQL where values are dynamic. It must not call `Connection::statement()`, `select()`, `getPdo()`, or `getReadPdo()`.
- Different configurators own independent session settings. Registration order is deterministic, but it is not a priority/override mechanism for two configurators writing the same setting.

### Registrar

Add one static registrar to `Connection`:

```php
/**
 * Register a database session configurator.
 *
 * Boot-only. The configurator persists in a static property for the worker
 * lifetime and runs on every subsequent synchronized PDO hand-out across all
 * coroutines.
 */
public static function configureSessionUsing(SessionConfigurator $configurator): void
{
    static::$sessionConfigurators[] = $configurator;
}
```

The registrar:

- appends to an ordered list;
- returns `void`;
- does not deduplicate;
- does not accept names, priorities, removal callbacks, or runtime mutation;
- is registered in a service provider during worker boot;
- is cleared for tests through the existing `Connection::flushState()`.

Do not add this method to `ConnectionInterface`: global configurator registration is a concrete Hypervel `Connection` facility, not behavior every alternate connection implementation must provide.

## Exact physical-session state

### Internal state object

Add `src/database/src/PhysicalSessionState.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Database;

/**
 * @internal
 */
final class PhysicalSessionState
{
    /**
     * @var array<int, string>
     */
    public array $appliedStates = [];

    public bool $configuring = false;

    public bool $unknown = false;
}
```

Each field closes one demonstrated requirement:

- `appliedStates`: skip configuration SQL while every desired state still matches;
- `configuring`: detect recursive configuration and concurrent configuration of one shared physical PDO;
- `unknown`: prevent a partially configured or otherwise ambiguous session from being trusted.

The object is final because it is a private invariant carrier, not an extension point. Its fields are public only to avoid accessor ceremony inside the owning `Connection`; the class is not documented as public API.

### Worker-static storage

Add to `Connection`:

```php
/**
 * The registered database session configurators.
 *
 * @var list<SessionConfigurator>
 */
protected static array $sessionConfigurators = [];

/**
 * The state known for each live physical database session.
 *
 * @var null|\WeakMap<PDO, PhysicalSessionState>
 */
protected static ?WeakMap $physicalSessionStates = null;
```

Use a lazy nullable `WeakMap`, not an eagerly constructed map:

- with no configurators, no map is allocated or touched;
- the PDO object is the exact physical-session identity;
- read and write PDOs naturally keep independent state;
- clones/wrappers sharing one PDO see one state;
- replacing or collecting a PDO naturally removes its state;
- no object-ID reuse table, destructor hook, manual sweep, or `WeakReference` is needed.

Extend the existing `flushState()`:

```php
public static function flushState(): void
{
    static::$sessionConfigurators = [];
    static::$physicalSessionStates = null;
    static::$resolvers = [];
    static::flushMacros();
}
```

`AfterEachTestSubscriber` already calls `Connection::flushState()`; do not add another cleanup owner.

## Connection implementation

### Split raw resolution from synchronized hand-out

Refactor lazy PDO resolution into protected helpers:

```php
/**
 * Resolve the current write PDO without synchronizing session state.
 */
protected function resolvePdo(): PDO
{
    if ($this->pdo instanceof Closure) {
        return $this->pdo = call_user_func($this->pdo);
    }

    return $this->pdo;
}

/**
 * Resolve the current read PDO without synchronizing session state.
 */
protected function resolveReadPdo(): PDO
{
    if ($this->readPdo instanceof Closure) {
        return $this->readPdo = call_user_func($this->readPdo);
    }

    if ($this->readPdo instanceof PDO) {
        return $this->readPdo;
    }

    $this->latestPdoTypeRetrieved = 'write';

    return $this->resolvePdo();
}
```

The public getters become the synchronized boundary:

```php
/**
 * Get the current synchronized PDO connection.
 */
public function getPdo(): PDO
{
    $this->latestPdoTypeRetrieved = 'write';

    return $this->synchronizeSession($this->resolvePdo(), read: false);
}

/**
 * Get the current synchronized PDO connection used for reading.
 */
public function getReadPdo(): PDO
{
    if ($this->transactions > 0) {
        return $this->getPdo();
    }

    if ($this->readOnWriteConnection
        || ($this->recordsModified && $this->getConfig('sticky'))) {
        return $this->getPdo();
    }

    $this->latestPdoTypeRetrieved = 'read';

    return $this->synchronizeSession($this->resolveReadPdo(), read: true);
}
```

This preserves existing read/write/sticky routing and its `latestPdoTypeRetrieved` diagnostics. When no read PDO exists, `resolveReadPdo()` falls back to the raw write PDO and records the write type just as today's `getReadPdo()` fallback reaches `getPdo()`.

Keep the public raw accessors unsynchronized and update their docblocks to be explicit:

```php
/**
 * Get the current PDO parameter without resolving, reconnecting, or
 * synchronizing session state.
 */
public function getRawPdo(): PDO|Closure|null;

/**
 * Get the current read PDO parameter without resolving, reconnecting, or
 * synchronizing session state.
 */
public function getRawReadPdo(): PDO|Closure|null;
```

Do not expose the protected resolver methods publicly. They exist solely so internal transaction control can resolve a lazy PDO without recursively invoking session configuration.

### Synchronization algorithm

Add these protected helpers to `Connection` next to the PDO accessors:

```php
/**
 * Synchronize the desired state for a physical database session.
 */
protected function synchronizeSession(PDO $pdo, bool $read): PDO
{
    if (static::$sessionConfigurators === []) {
        return $pdo;
    }

    $sessionState = static::physicalSessionState($pdo);

    if ($sessionState->configuring) {
        $this->markSessionStateUnknown($pdo);

        throw new RuntimeException('Reentrant database session configuration is not allowed.');
    }

    if ($sessionState->unknown) {
        $sessionState->configuring = true;

        try {
            $pdo = $this->replaceUnknownSession($read);
        } finally {
            $sessionState->configuring = false;
        }

        $sessionState = static::physicalSessionState($pdo);

        if ($sessionState->configuring) {
            $this->markSessionStateUnknown($pdo);

            throw new RuntimeException('Reentrant database session configuration is not allowed.');
        }
    }

    $sessionState->configuring = true;

    try {
        foreach (static::$sessionConfigurators as $index => $configurator) {
            $desiredState = $configurator->state($this);

            if ($desiredState === null
                || ($sessionState->appliedStates[$index] ?? null) === $desiredState) {
                continue;
            }

            try {
                $configurator->apply($pdo, $desiredState, $this);

                if ($sessionState->unknown) {
                    throw new RuntimeException(
                        'Database session state became unknown during configuration.'
                    );
                }
            } catch (Throwable $exception) {
                $sessionState->appliedStates = [];
                $sessionState->unknown = true;

                throw $exception;
            }

            $sessionState->appliedStates[$index] = $desiredState;
        }

        if ($sessionState->unknown) {
            throw new RuntimeException(
                'Database session state became unknown during configuration.'
            );
        }
    } finally {
        $sessionState->configuring = false;
    }

    return $pdo;
}

/**
 * Replace a physical session whose state can no longer be trusted.
 */
protected function replaceUnknownSession(bool $read): PDO
{
    if ($this->transactions > 0) {
        throw new RuntimeException(
            'Database session state is unknown within an active transaction.'
        );
    }

    $this->reconnect();

    $replacement = $read
        ? $this->resolveReadPdo()
        : $this->resolvePdo();

    if (static::sessionStateIsUnknown($replacement)) {
        throw new RuntimeException(
            'Database session state remains unknown after reconnecting.'
        );
    }

    return $replacement;
}

/**
 * Get the state holder for a physical database session.
 */
protected static function physicalSessionState(PDO $pdo): PhysicalSessionState
{
    $states = static::$physicalSessionStates ??= new WeakMap;

    return $states[$pdo] ??= new PhysicalSessionState;
}

/**
 * Determine whether a physical database session has unknown state.
 */
protected static function sessionStateIsUnknown(PDO $pdo): bool
{
    return static::$physicalSessionStates !== null
        && isset(static::$physicalSessionStates[$pdo])
        && static::$physicalSessionStates[$pdo]->unknown;
}

/**
 * Invalidate the states remembered for a physical database session.
 */
protected function invalidateSessionState(PDO $pdo): void
{
    if (static::$physicalSessionStates !== null
        && isset(static::$physicalSessionStates[$pdo])) {
        static::$physicalSessionStates[$pdo]->appliedStates = [];
    }
}

/**
 * Mark a physical database session's state as unknown.
 */
protected function markSessionStateUnknown(PDO $pdo): void
{
    if (static::$sessionConfigurators === []) {
        return;
    }

    $sessionState = static::physicalSessionState($pdo);
    $sessionState->appliedStates = [];
    $sessionState->unknown = true;
}
```

The implementation may tune line wrapping and method placement to match the class, but not change these semantics.

Important details:

- The empty-list return comes before every map access and callback.
- The map records a state only after `apply()` returns successfully.
- A failed apply clears every configurator memo for the PDO because an earlier configurator may have succeeded before a later one failed.
- A `state()` exception propagates without taint unless it caused a detected reentry. The contract makes `state()` pure, so a normal computation failure cannot have changed the physical session.
- The `configuring` flag covers both same-stack recursion and concurrent access through multiple wrappers around one physical PDO.
- If overlapping access marks a PDO unknown while another configurator call is in progress, the in-progress call checks the taint before recording success and also fails. No caller receives that PDO merely because its own `apply()` completed.
- Unknown recovery reconnects at most once per getter call. It never recurses.
- The old unknown PDO remains marked as configuring during its reconnect callback. A custom reconnector that re-enters a public getter before replacing the PDO therefore fails closed instead of recursively reconnecting.
- Re-resolving after reconnect happens in the getter's original read/write route.
- A manually constructed `Connection` without a reconnector naturally throws the existing `LostConnectionException`; no fallback connector is invented.
- A normal pooled or manager-backed nonpooled connection already has a reconnector and replaces its PDO.
- Shared in-memory SQLite deliberately resolves the same PDO after reconnect. The second unknown check throws, so it cannot loop or silently clear taint.
- Do not catch and wrap the runtime failures in a new exception hierarchy.

### Pool health query

Add a narrow concrete method to `Connection`, consumed by `PooledConnection` at release:

```php
/**
 * Determine whether an open PDO has unknown session state.
 *
 * @internal
 */
public function hasUnknownSessionState(): bool
{
    if (static::$physicalSessionStates === null) {
        return false;
    }

    $writePdo = $this->getRawPdo();

    if ($writePdo instanceof PDO
        && static::sessionStateIsUnknown($writePdo)) {
        return true;
    }

    $readPdo = $this->getRawReadPdo();

    return $readPdo instanceof PDO
        && static::sessionStateIsUnknown($readPdo);
}
```

This method:

- inspects only already-resolved raw PDO objects;
- never invokes a lazy closure;
- never reconnects or synchronizes;
- allocates no temporary collection on the release path;
- handles shared read/write PDO identity without needing a deduplication structure.

It remains off `ConnectionInterface` because only the concrete Hypervel pool adapter consumes it.

## Transaction lifecycle

### Transaction-control matrix

| Operation | PDO access | Memo result | Reason |
|---|---|---|---|
| Outer BEGIN | synchronized public write getter | current state established before BEGIN | configuration survives a later rollback |
| BEGIN lost retry | synchronized public write getter again | fresh PDO configured | existing BEGIN retry owns reconnect |
| Savepoint creation | raw resolved write PDO | preserve | control SQL must not configure and does not change session settings |
| Successful inline/public commit | raw resolved write PDO | preserve | session-level state survives commit |
| Failed commit: concurrency | raw resolved write PDO | invalidate, reusable | server abort is a known rollback; existing retry deliberately reuses PDO |
| Failed commit: lost connection | raw resolved write PDO | invalidate old key | reconnect replaces physical session |
| Failed commit: other | raw resolved write PDO | invalidate and mark unknown | physical outcome is ambiguous |
| Nested concurrency error after driver auto-rollback | raw resolved write PDO | invalidate | the physical transaction and any transactional session changes are already gone |
| Full rollback | raw resolved write PDO | invalidate in `finally` | transaction may have reverted session settings |
| Savepoint rollback | raw resolved write PDO | invalidate in `finally` | settings after savepoint may have reverted |
| Failed rollback: lost | raw resolved write PDO | invalidate | replacement/discard heals; do not taint merely dead old socket |
| Failed rollback: other | raw resolved write PDO | invalidate and mark unknown | physical state is ambiguous |
| Invalid rollback level | no PDO operation | preserve | no rollback was attempted |
| Disconnect rollback | already-resolved raw write PDO | invalidate; unknown on failure | exact PDO may survive through shared SQLite ownership |

Invalidating all configurator memos after rollback and concurrency-failed commit is deliberately conservative. A pre-BEGIN state may still be correct, but distinguishing settings applied before and after every nested boundary would require a transaction/savepoint state stack. The next synchronized hand-out safely reapplies only registered state; the error/retry path is not a hot successful path. Do not add that state stack.

### Invalidate driver-owned nested rollback

Keep the existing nested-concurrency behavior in `handleTransactionException()`, but invalidate the exact resolved PDO before adjusting the logical level and throwing `DeadlockException`:

```php
if ($this->causedByConcurrencyError($e)
    && $this->transactions > 1) {
    $this->invalidateSessionState($this->resolvePdo());

    --$this->transactions;

    $this->transactionsManager?->rollback(
        $this->getName(),
        $this->transactions
    );

    throw new DeadlockException(
        $e->getMessage(),
        is_int($e->getCode()) ? $e->getCode() : 0,
        $e
    );
}
```

This branch already asserts that the driver rolled back the complete physical transaction, so its memo cannot be retained. A transaction level above one guarantees that the outer BEGIN already resolved the write PDO; this helper does not open a connection on the failure path. Do not mark the PDO unknown or add another rollback: the physical outcome is known and the existing transaction exception behavior is unchanged.

### Keep outer BEGIN synchronized

`Connection::executeBeginTransactionStatement()` and `SQLiteConnection::executeBeginTransactionStatement()` continue to call public `getPdo()`. This establishes desired state before `beginTransaction()` or `BEGIN ... TRANSACTION`.

Do not move synchronization into `beforeStartingTransaction`: those callbacks are mutable per-connection request state, reset on pool release, and not the physical-session owner.

### Raw savepoints and rollback

Change savepoint creation to:

```php
protected function createSavepoint(): void
{
    $this->resolvePdo()->exec(
        $this->queryGrammar->compileSavepoint('trans' . ($this->transactions + 1))
    );
}
```

Pass the exact raw PDO into rollback:

```php
public function rollBack(?int $toLevel = null): void
{
    $toLevel = is_null($toLevel)
        ? $this->transactions - 1
        : $toLevel;

    if ($toLevel < 0 || $toLevel >= $this->transactions) {
        return;
    }

    $pdo = $this->resolvePdo();

    try {
        $this->performRollBack($toLevel, $pdo);
    } catch (Throwable $exception) {
        if (! $this->causedByLostConnection($exception)) {
            $this->markSessionStateUnknown($pdo);
        }

        $this->handleRollBackException($exception);
    } finally {
        $this->invalidateSessionState($pdo);
    }

    $this->transactions = $toLevel;

    $this->transactionsManager?->rollback(
        $this->getName(),
        $this->transactions
    );

    $this->fireConnectionEvent('rollingBack');
}

/**
 * Perform a rollback within the database.
 */
protected function performRollBack(int $toLevel, PDO $pdo): void
{
    if ($toLevel === 0) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } elseif ($this->queryGrammar->supportsSavepoints()) {
        $pdo->exec(
            $this->queryGrammar->compileSavepointRollBack('trans' . ($toLevel + 1))
        );
    }
}
```

The level guard remains before raw resolution and invalidation. A redundant `rollBack()` at level zero remains a no-op with no state cost.

The invalidation belongs in `finally`, so a failed physical rollback cannot leave a positive memo. Non-lost failure marks the exact PDO unknown before `handleRollBackException()` rethrows. The lost branch retains existing transaction-manager behavior and relies on physical replacement.

### Centralize outer commit behavior

Add one protected helper because both commit sites need the same nontrivial lifecycle:

```php
/**
 * Commit the active physical transaction.
 */
protected function performCommit(): void
{
    $pdo = $this->resolvePdo();

    try {
        $pdo->commit();
    } catch (Throwable $exception) {
        $this->invalidateSessionState($pdo);

        if (! $this->causedByLostConnection($exception)
            && ! $this->causedByConcurrencyError($exception)) {
            $this->markSessionStateUnknown($pdo);
        }

        throw $exception;
    }
}
```

Replace both:

```php
$this->getPdo()->commit();
```

with:

```php
$this->performCommit();
```

This applies to the inline outer commit inside `transaction()` and the outer branch of public `commit()`. Nested logical commits still perform no PDO commit.

Recognized concurrency/serialization commit failures are explicitly reusable because Hypervel's supported retry loop expects that behavior and the failed transaction is the rollback boundary. Other non-lost failures remain unknown because the framework cannot prove the physical outcome. Tainting recognized concurrency failures would reconnect a healthy PDO on every retry without improving correctness.

### Make disconnect terminal and physically truthful

Refactor `Connection::disconnect()` so a throwing rollback cannot retain a half-disconnected wrapper and so a shared PDO's memo is invalidated:

```php
public function disconnect(): void
{
    $pdo = $this->getRawPdo();

    try {
        if ($this->transactions > 0) {
            $this->transactions = 0;

            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } finally {
                    $this->invalidateSessionState($pdo);
                }
            }
        }
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO) {
            $this->markSessionStateUnknown($pdo);
        }

        throw $exception;
    } finally {
        $this->setPdo(null)->setReadPdo(null);
    }
}
```

`transactions > 0` currently implies BEGIN already resolved the write closure, but keep the `instanceof PDO` boundary so disconnect never invokes a lazy connector just to close it.

Dropping wrapper references in `finally` is an intentional failure-path improvement: the original exception still propagates, while the wrapper cannot retain a half-disconnected physical handle. Add a direct regression test for this behavior.

### Aborted-transaction recovery

The decisive PostgreSQL path is:

1. an application statement aborts the transaction;
2. logical context changes before cleanup;
3. `rollBack()` resolves the raw PDO and runs rollback without invoking `state()` or `apply()`;
4. rollback invalidates the memo;
5. the next normal statement runs after the transaction, computes current desired state, reapplies it, and succeeds.

Any implementation that synchronizes in `getPdo()` but forgets to route rollback/savepoint/commit through raw resolution is incorrect.

## Failure, retry, and diagnostic composition

### Ordinary lost-query retry

No new retry code is added. Because synchronization occurs inside the operation closure through the public getter:

1. `apply()` is the first SQL to touch an idle-killed PDO and throws;
2. `runQueryCallback()` clears/taints state through the synchronizer, increments `errorCount`, and wraps the error in a `QueryException`;
3. the wrapper names the application SQL and retains the configuration exception as `getPrevious()`;
4. `handleQueryException()` sees no active transaction;
5. `tryAgainIfCausedByLostConnection()` detects the previous exception, reconnects, and reruns the same operation closure;
6. the fresh PDO has no memo, is configured, and executes the original application statement.

Inside a transaction, `handleQueryException()` already refuses transparent reconnect/retry. Keep that behavior.

### BEGIN lost retry

`handleBeginTransactionException()` already reconnects and calls `executeBeginTransactionStatement()` again. Because outer BEGIN uses synchronized `getPdo()`, the fresh PDO is configured on the retry. Add no special BEGIN callback.

### Direct getter behavior

A direct `getPdo()` or `getReadPdo()` call is outside `run()`:

- its current configuration failure propagates directly;
- it gets no transparent retry for that call;
- failed apply or detected reentry marks the exact PDO unknown, while a pure `state()` exception does not;
- a later synchronized getter may reconnect once before use.

This matches today's direct PDO semantics. Do not hide direct getter errors behind an implicit retry loop.

### QueryException and error accounting

Pin the intentional diagnostic shape:

- a configuration `Exception` during a normal operation produces a `QueryException` whose SQL/bindings describe the requested application operation;
- the actual configuration exception is the previous exception;
- lost-connection detection continues to inspect that previous exception;
- `errorCount` increments for the failed execution attempt and contributes to the pool's existing stale-connection threshold;
- a direct getter failure outside `run()` does not increment query `errorCount`.

Do not add separate prepared-statement/query instrumentation for configurator SQL. It executes directly through PDO, while its time is naturally included in the application operation's elapsed duration on a transition. Separate logging/events would require recursive query machinery and misrepresent the hot path.

## Pool integration

### Preserve known state across clean release

`Connection::resetForPool()` continues to clear only request/wrapper state:

- before-execution and before-transaction callbacks;
- query logs and logging flag;
- cumulative duration and handlers;
- read-on-write routing;
- pretend mode;
- modified-record sticky routing;
- error count.

It must not clear physical session state or the worker-static memo. Update its docblock/comments to distinguish wrapper state from server-session state.

The same-state release/reborrow path must execute zero configurator SQL. A changed context on reborrow differs in `state()` and applies before the first operation.

### Reject unknown pooled sessions

At the end of `PooledConnection::release()`, after abandoned rollback and the optional release event, check health in `finally` before requeue:

```php
} finally {
    if ($this->connection?->hasUnknownSessionState()) {
        $this->logger->warning(
            'Database session state is unknown, marking connection as stale.'
        );

        $this->markInvalid();
    }

    $this->availableForReuse = true;
    $this->pool->release($this);
}
```

The final position is required:

- it observes a session that was already unknown when release began, even if the application caught the originating failure;
- it observes an apply failure a release listener caught rather than rethrew;
- the existing outer catch still marks the wrapper invalid when release itself throws.

A successful abandoned-transaction rollback invalidates the memo but does not make the session unknown. A failed release rollback is already marked unknown where appropriate and is also made stale by the existing outer catch.

On the next normal borrow, `check()` fails and the existing reconnect path replaces the physical PDO. No new pool state or discard queue is needed.

For shared in-memory SQLite, reconnect creates a new wrapper around the same PDO. The getter's bounded unknown check therefore throws and remains fail-closed until the owning `DbPool` is closed/recreated. Do not clear the map entry, rebuild the shared in-memory database, or invent a SQLite reset protocol.

### Make normal refresh atomic and fail invalid

The normal `PooledConnection::refresh()` branch must prepare the complete fresh generation before mutating the active wrapper:

```php
try {
    $fresh = $this->factory->make(
        $this->config,
        $this->config['name'] ?? null
    );
    $writePdo = $fresh->getPdo();
    $readPdo = $fresh->getReadPdo();

    $connection->disconnect();
    $connection->setPdo($writePdo);
    $connection->setReadPdo($readPdo);
} catch (Throwable $exception) {
    $this->markInvalid();

    throw $exception;
}
```

Factory creation, both synchronized fresh hand-outs, disconnect, and both setters belong to one failure boundary. Resolving both fresh handles first means a connector or configurator failure leaves the current generation intact and inspectable rather than producing null write state or a silent read-to-write fallback. If disconnect itself fails, its own terminal cleanup may clear the handles, but the adapter is already invalid and cannot be requeued as healthy.

Do not call `markValid()` after a later refresh in the same borrow or add recovery bookkeeping. A failed refresh makes that generation conservatively stale; the normal next-borrow `reconnect()` path already creates the replacement and marks it valid. The successful path performs the same factory creation, two hand-outs, disconnect, and setters in a safer order, with only two local assignments added.

### Preserve the maintenance invariant

Add one concise WHY comment at the raw heartbeat/release maintenance boundary:

```php
// Known session configuration is memoized by physical PDO across clean
// releases. Built-in pool maintenance must remain session-state-neutral
// unless it also invalidates that PDO's memo.
```

`SELECT 1` is session-neutral and remains raw. A future built-in `RESET`, `DISCARD`, `SET`, or role/search-path change must invalidate the corresponding exact PDO.

Application code that deliberately mutates a configurator-owned setting through raw SQL or a retained PDO handle violates the configurator ownership contract. Do not add SQL parsing or unconditional release invalidation to defend against that escape hatch.

## MySQL insert hot path

`MySqlConnection::insert()` currently calls `getPdo()` once for `prepare()` and again for `lastInsertId()`. With synchronized hand-out, that would perform two state computations/map checks in one closure. Resolve once:

```php
$pdo = $this->getPdo();
$statement = $pdo->prepare($query);

$this->bindValues($statement, $this->prepareBindings($bindings));

$this->recordsHaveBeenModified();

$result = $statement->execute();

$this->lastInsertId = $pdo->lastInsertId($sequence);
```

This is a local no-behavior-change optimization at a touched hot path, not a new cache. Do not redesign `Processor::processInsertGetId()` or store a global “last PDO”; those are separate calls and the additional known-state check is negligible.

## Public documentation deliverable

Update `src/boost/docs/database.md` as part of the same change. Add **Configuring Database Session State** to the table of contents and place the section immediately after **Connection Pooling**, because persistent physical sessions are the reason the API exists.

The framework documentation must remain generic. Do not make tenancy, RLS, a particular GUC, or a package class the normative example. Use a small application-name configurator to show the API shape:

```php
<?php

declare(strict_types=1);

namespace App\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\SessionConfigurator;
use PDO;

final class ApplicationNameConfigurator implements SessionConfigurator
{
    public function __construct(
        private readonly string $applicationName,
    ) {
    }

    public function state(Connection $connection): ?string
    {
        return $connection->getDriverName() === 'pgsql'
            ? $this->applicationName
            : null;
    }

    public function apply(PDO $pdo, string $state, Connection $connection): void
    {
        $statement = $pdo->prepare(
            "select set_config('application_name', ?, false)"
        );
        $statement->execute([$state]);
        $statement->closeCursor();
    }
}
```

Register the worker-safe service during application boot:

```php
use App\Database\ApplicationNameConfigurator;
use Hypervel\Database\Connection;

Connection::configureSessionUsing(
    $this->app->make(ApplicationNameConfigurator::class)
);
```

The prose around the example must document all of these rules:

- registration is boot-only, ordered, appending, and worker-static;
- the configurator must be safe to share for the worker lifetime and must read request/coroutine context lazily rather than capture it in a constructor;
- `state()` returns the complete opaque state identity, runs on every synchronized PDO hand-out, and must not execute database work;
- `null` means only “this configurator does not apply to this connection”; it must not be a security-sensitive no-context shortcut;
- that not-applicable decision is stable for the connection; it is not a way to stop managing previously applied state for one request;
- `apply()` receives the exact physical PDO, must use it directly, and must completely establish the state represented by the string;
- settings must be session-scoped rather than transaction-local; PostgreSQL `SET LOCAL` / `set_config(..., true)` does not satisfy this contract;
- public `getPdo()` and `getReadPdo()` synchronize before returning; raw getters, protected framework control paths, and an already-retained PDO handle are explicit unsynchronized escape hatches;
- Hypervel remembers state separately per physical read/write PDO, preserves it through successful commit and clean internal pool release, and rechecks it on every hand-out;
- rollback invalidates remembered state because PostgreSQL may revert session-level settings made within the rolled-back transaction;
- a partially configured or otherwise ambiguous PDO is not trusted or returned as healthy;
- configuration SQL runs only when the opaque state differs, but `state()` remains a hot-path method;
- configurator SQL runs directly through PDO and does not create its own prepared-statement event, query event, log entry, or duration callback; transition time is included in the requesting operation when configuration occurs;
- application code must not mutate a setting owned by a configurator through raw SQL or a retained PDO without accepting that it has left the synchronization contract.

Also revise the existing direct-PDO paragraph under **Using Multiple Database Connections**. `getPdo()` still returns the underlying PDO, but it is now a synchronized hand-out rather than a “raw” accessor. Point low-level framework authors to `getRawPdo()` only when they intentionally need the unresolved, unsynchronized parameter and understand that it may be a closure or `null`.

Add a compact external-pooler warning:

- direct server connections and session-pooling proxies preserve physical session settings for the required lifetime;
- transaction- or statement-pooling modes are incompatible with configurators whose correctness depends on state persisting across statements;
- Hypervel cannot reliably discover or repair the proxy's pooling mode, so it performs no automatic pooler detection.

Do not add a framework configuration file, environment switch, default configurator, or automatic registration. The extension point has no behavior until a consumer explicitly registers one.

## Implementation sequence and affected files

Implement in this order so each layer can be tested before pool integration:

1. Add `src/database/src/SessionConfigurator.php` with the public contract and complete behavioral docblocks.
2. Add `src/database/src/PhysicalSessionState.php` as the final internal state holder.
3. Update `src/database/src/Connection.php`:
   - static configurator list and lazy weak map;
   - boot-only registrar and `flushState()` cleanup;
   - raw PDO resolvers and synchronized public getters;
   - synchronization, bounded unknown replacement, invalidation, taint, and health helpers;
   - direct-disconnect lifecycle;
   - outer BEGIN remains synchronized;
   - update docblocks/comments that describe raw PDO or reset semantics.
4. Update `src/database/src/Concerns/ManagesTransactions.php`:
   - memo invalidation when a nested concurrency error means the driver already rolled back the complete transaction;
   - raw savepoint creation;
   - centralized raw outer commit lifecycle for both commit sites;
   - exact-PDO rollback with `finally` invalidation and non-lost taint.
5. Update `src/database/src/MySqlConnection.php` to reuse one synchronized PDO inside its insert closure.
6. Update `src/database/src/Pool/PooledConnection.php` to reject unknown sessions at the final release boundary, prepare normal refresh replacements atomically and fail invalid, and record the raw-maintenance invariant.
7. Add/update the focused unit and integration tests below.
8. Update `src/boost/docs/database.md` after the executable contract is green, then verify that every documented guarantee has a matching test.

Do not modify:

- `ConnectionInterface`; the registrar and pool-health inspection are concrete framework facilities;
- `DatabaseManager`, either connection resolver, or `PoolFactory`; existing construction and reconnect wiring are sufficient;
- `beforeExecuting()` semantics; it remains an application query callback rather than physical-session machinery;
- `QueryException`, lost-connection detection, or concurrency detection; composition with those existing mechanisms is part of this design;
- `AfterEachTestSubscriber`; its existing `Connection::flushState()` call owns worker-static test cleanup;
- `.github/workflows/databases.yml`; its MySQL 8/9, MariaDB 10/11, PostgreSQL 17/18, and SQLite jobs already discover the new common integration test, while both PostgreSQL jobs also discover the driver-specific class;
- the private tenancy package in this framework PR. It is a later consumer of this generic API.

## Testing plan

Tests must prove observable behavior and SQL/configurator call counts. Do not add wall-clock assertions.

### Core registration, memoization, and physical identity

Add `tests/Database/DatabaseSessionConfiguratorTest.php` using the repository's database test base classes and Mockery/PDO test conventions.

Cover:

1. **Zero-configurator fast path**
   - `getPdo()` and `getReadPdo()` return the existing routed PDOs;
   - no `PhysicalSessionState`/`WeakMap` is created;
   - no extra PDO method is called;
   - repeated hand-outs keep the same result.
2. **Boot registration semantics**
   - configurators run in registration order;
   - duplicate instances are not silently deduplicated;
   - `flushState()` removes both registrations and remembered physical state;
   - a subsequent test starts with no callbacks or stale memo.
3. **State semantics**
   - `null` skips that configurator only;
   - empty string is applied and memoized as a real state;
   - a matching state calls `state()` but not `apply()`;
   - a changed state calls `apply()` once and replaces the memo only after success;
   - multiple configurators keep independent indexed states.
4. **Physical identity**
   - separate read and write PDOs are configured and memoized independently;
   - read fallback to write shares one physical memo;
   - two connection wrappers around the same PDO share one memo;
   - a cloned wrapper around the same PDO does not reapply a matching state;
   - after all strong PDO references are released and garbage collection runs, the weak-map entry disappears.
5. **Public and raw access**
   - public getters synchronize before returning;
   - raw getters neither resolve closures nor synchronize;
   - internal raw resolution resolves without synchronization;
   - a previously retained PDO receives no later automatic check, pinning the documented escape-hatch boundary.

Expose internal state only through purpose-built anonymous test subclasses or reflection already accepted by the local test style. Do not make production internals public merely to simplify tests.

### Every normal statement path

Extend `tests/Database/DatabaseConnectionTest.php` and `tests/Database/ConnectionTest.php` so every execution closure is pinned:

- `select()`;
- `selectResultSets()`;
- `cursor()`;
- `statement()`;
- `affectingStatement()`;
- `unprepared()`;
- `MySqlConnection::insert()`.

For each distinct routing family, assert synchronization occurs before prepare/query/exec. Avoid seven copy-pasted test setups: use a data provider where the assertion shape is truly identical, and keep dedicated tests for cursor/result-set and MySQL-specific behavior.

Also verify:

- pretend mode does not resolve a PDO, compute state, or configure because its operation closure is not entered;
- `MySqlConnection::insert()` computes state once, prepares and retrieves the insert ID through the same PDO, and preserves existing return/ID behavior;
- `escape()` and server-version introspection synchronize when they use public PDO getters, consistent with the public hand-out contract;
- existing read/sticky/transaction routing and `latestPdoTypeRetrieved` diagnostics remain unchanged.

### Configuration failure and reentry

In `DatabaseSessionConfiguratorTest` cover:

- a thrown `state()` exception propagates without marking the PDO unknown, because no physical mutation is permitted there;
- an `apply()` failure prevents the application SQL from executing, clears every remembered configurator state for that PDO, and marks it unknown;
- an earlier configurator succeeding before a later configurator fails does not retain a partial positive memo;
- direct recursion through `getPdo()`, and same-PDO concurrent entry through another wrapper, throw the reentry error and mark the physical PDO unknown;
- when overlapping same-PDO access taints a session while the first `apply()` is suspended, both callers fail and the first caller does not record success or receive the PDO after resuming;
- unknown replacement happens once, configures the replacement, and returns only the replacement;
- a reconnector that re-enters a public getter before replacing the unknown PDO is rejected without a recursive reconnect loop;
- unknown recovery preserves the original route: write hand-out replaces/resolves write, read hand-out replaces/resolves read, and read fallback still uses the replacement write PDO;
- a replacement that is the same unknown PDO fails after one reconnect without recursion;
- an unknown PDO inside an active transaction fails closed without reconnect;
- a manually constructed connection without a reconnector preserves the existing reconnect failure rather than inventing fallback behavior.

### Query diagnostics and existing retry composition

Extend `tests/Database/ConnectionTest.php` around the existing lost-connection tests:

- a normal-operation configuration `Exception` is wrapped as one `QueryException` for the requested application SQL/bindings, with the configurator exception as its previous exception;
- `errorCount` increments once for each failed execution attempt, not once for an imaginary second configuration query;
- configuration SQL emits no separate `StatementPrepared` / query event, log entry, or duration callback;
- a direct getter failure remains the unwrapped configurator exception and does not increment query `errorCount`;
- when configuration is the first SQL to find a dead connection outside a transaction, existing lost-connection handling reconnects and reruns the operation closure; the replacement is configured and the original operation succeeds;
- the same failure inside a transaction is not transparently retried;
- when the first outer BEGIN finds a dead connection during configuration, existing BEGIN recovery reconnects, reconfigures, and begins successfully;
- direct public getter calls receive no current-call retry.

Use errors/messages already recognized by `DetectsLostConnections`; do not alter production detection rules solely to make a mock test pass.

`Connection::runQueryCallback()` intentionally catches `Exception`, matching its existing contract. A non-`Exception` `Throwable` from consumer code still taints the PDO in the synchronizer and then propagates directly; do not broaden the database query wrapper as unrelated API churn.

### Transaction lifecycle

Extend `tests/Database/DatabaseTransactionsTest.php` and the focused connection tests:

- outer BEGIN synchronizes before `beginTransaction()` / SQLite `BEGIN`;
- nested savepoint creation uses raw resolution and invokes no configurator;
- successful inline `transaction()` commit preserves the memo;
- successful public `commit()` preserves the memo;
- full rollback invalidates the memo in `finally` and the next normal hand-out reapplies;
- rollback to savepoint invalidates the memo and the next normal hand-out reapplies;
- invalid rollback levels perform no PDO resolution and preserve the memo;
- a lost-connection rollback failure invalidates without marking the dead PDO newly unknown;
- another rollback failure invalidates and marks the exact PDO unknown before existing exception handling runs;
- a concurrency/serialization commit failure invalidates but leaves the same PDO eligible for the existing transaction retry;
- a nested concurrency error on the driver-owned full-rollback branch invalidates before throwing `DeadlockException`, without tainting or issuing another rollback;
- another non-lost commit failure invalidates and marks the PDO unknown;
- a lost commit failure invalidates the old PDO's memo and composes with existing handling/replacement;
- each transaction retry recomputes current logical state before the new BEGIN;
- no configurator is invoked by COMMIT, ROLLBACK, or ROLLBACK TO SAVEPOINT themselves.

Keep the existing serialization-retry expectation that there is no explicit `rollBack()` between failed COMMIT and the next attempt. The new assertion is that the session memo is invalidated at the failed COMMIT boundary.

### Disconnect lifecycle

Extend `tests/Database/DatabaseConnectionTest.php`:

- disconnecting outside a transaction drops read/write references without resolving a lazy PDO;
- successful direct rollback during disconnect invalidates the exact PDO before references are dropped;
- a shared PDO retained elsewhere observes that invalidation;
- failed rollback marks the PDO unknown and still drops both wrapper references in `finally`;
- the original rollback exception is rethrown unchanged;
- a PDO/closure boundary that is not an already-resolved PDO is never invoked merely to disconnect.

### Pool lifecycle

Extend `tests/Integration/Database/PooledConnectionTest.php`:

- same state across clean release/reborrow performs zero additional `apply()` calls;
- changed logical state on reborrow applies before the first application operation;
- clean `resetForPool()` does not erase the physical memo;
- abandoned-transaction rollback invalidates the memo and the next borrow reapplies;
- an unknown session detected at final release marks the wrapper invalid before it is returned;
- an unknown already-open read PDO is detected even when the write PDO is healthy, without resolving unopened closures;
- an unknown state created by a release listener and caught by that listener is still detected;
- a thrown release error keeps the existing invalidation/release behavior;
- the next health check reconnects an invalid normal connection and the fresh PDO configures normally;
- when configuration of a fresh PDO fails during unknown-session refresh, the current generation remains wholly installed, the pool adapter is marked invalid, the original exception propagates unchanged, and the next borrow recreates and configures a distinct PDO;
- heartbeat `SELECT 1` neither computes/configures state nor invalidates a truthful memo;
- read/write physical sessions remain independent through release/reborrow.

Add a shared in-memory SQLite case through the real `DbPool`:

- make the shared PDO unknown;
- release/borrow creates or refreshes a wrapper but resolves the same physical PDO;
- synchronized hand-out makes one reconnect attempt, sees the same unknown PDO, and throws;
- no loop, silent taint clearing, database recreation, or application SQL occurs.

### Coroutine isolation

Extend `tests/Integration/Database/ConnectionCoroutineSafetyTest.php` with a worker-static configurator whose `state()` reads coroutine-local context lazily.

Interleave at least two coroutines through a one-connection pool and prove:

- one boot registration is shared safely;
- each coroutine's first hand-out establishes its own current state before application SQL;
- after release/reborrow, a changed context triggers one transition;
- a matching context performs no configuration SQL;
- no constructor/static property captures the first coroutine's context;
- no callback from `resetForPool()` is needed for correctness.

Use deterministic barriers/channels already present in the coroutine tests rather than sleeps.

### All supported database drivers

Add `tests/Integration/Database/SessionConfiguratorTest.php`, extending the common `DatabaseTestCase`, so the same behavioral contract runs in every existing database workflow job: MySQL 8/9, MariaDB 10/11, PostgreSQL 17/18, and SQLite.

Use a small driver-aware test configurator and read the resulting state back from the same PDO with a native session primitive:

| Driver | `apply()` primitive | Assertion primitive |
|---|---|---|
| PostgreSQL | `select set_config('hypervel_test.context', ?, false)` | `select current_setting('hypervel_test.context', true)` |
| MySQL / MariaDB | `set @hypervel_test_context := ?` | `select @hypervel_test_context` |
| SQLite | validated integer `PRAGMA cache_size = N` | `PRAGMA cache_size` |

The SQLite fixture must cast/validate its test-owned state to an integer before interpolation because SQLite PRAGMA assignment does not accept a bound placeholder. No application input reaches this test SQL.

Cover the cross-driver contract without duplicating four classes:

1. First synchronized application SQL establishes the native session value before the application statement runs.
2. A second hand-out with the same desired state reads the same native value and performs no second `apply()`.
3. Changing desired state applies exactly once and the very next application statement observes the new native value.
4. A clean release/reborrow of the same pool slot preserves a matching memo and native value with zero configuration SQL; changing state before the next borrow reconfigures before use.
5. Full rollback invalidates the memo on every driver and causes one conservative reapply before the next statement. PostgreSQL may have reverted the setting; MySQL, MariaDB, and SQLite may still hold it, but Hypervel deliberately does not add driver-specific rollback shortcuts.
6. Public direct PDO hand-out returns only after native state is current; raw access remains unsynchronized.
7. Configuration SQL creates no independent prepared-statement/query event or log entry, while an application query still records its normal instrumentation.

Keep call counters in the test configurator and assert exact `state()` / `apply()` counts. In `defineEnvironment()`, derive a dedicated named connection from the already-loaded default driver config and give it `pool.testing_enabled = true`, `max_connections = 1`, and heartbeats disabled before the application finishes booting. Use only that named connection for release/reborrow assertions; do not mutate connection configuration at runtime or infer physical reuse from coincidentally equal server values. The test fixture may branch only at the native SQL adapter boundary—the framework assertions remain identical.

The test owns that dedicated pool. Release every borrowed wrapper in `finally` and flush the named pool during teardown so native session variables/PRAGMAs and live PDOs cannot leak to another test, including after an assertion failure. Do not duplicate `Connection::flushState()` in teardown; the existing global test subscriber remains its sole owner.

Do not add separate MySQL and MariaDB copies. Running the same class in both existing workflow families verifies their native behavior and PDO drivers without maintaining parallel expectations.

### Real PostgreSQL integration

Add `tests/Integration/Database/Postgres/SessionConfiguratorTest.php` for PostgreSQL-only transaction and recovery semantics beyond the common cross-driver contract. Use a namespaced custom runtime parameter accepted by PostgreSQL (for example `hypervel_test.context`) and `select set_config(?, ?, false)` through the exact PDO. Clean it up explicitly where practical so failures do not confuse later assertions.

Cover the facts on which the generic lifecycle depends:

1. A session-level `set_config(..., false)` made outside a transaction persists after successful COMMIT.
2. A session-level change made within a transaction is reverted by full ROLLBACK; the framework's invalidated memo causes the next synchronized statement to reapply the desired state.
3. A change made after a savepoint is reverted by ROLLBACK TO SAVEPOINT; the framework invalidates and reapplies rather than trusting the stale memo.
4. After an application SQL error aborts a transaction, rollback executes without calling the configurator; the next statement after rollback reconfigures and succeeds.
5. Empty/fail-closed state values are applied as real values rather than treated as not-applicable sentinels.
6. Kill a pooled connection's backend from an independent PostgreSQL administrative connection while the pooled PDO is idle. Change desired state so `apply()` is the first SQL to hit the dead socket, then assert the original application statement transparently reconnects, configures the fresh PDO, and succeeds outside a transaction.

For the backend-kill case, use a dedicated one-slot, heartbeat-disabled pool, capture that target session's `pg_backend_pid()`, release it, and terminate only that PID through a separately created administrative PDO. This makes both physical reuse and “configuration is the first failing SQL” deterministic. Close the administrative PDO and release/flush all target-pool resources in `finally`.

The test must target the existing PostgreSQL integration configuration and run in the already-defined PostgreSQL 17/18 workflow matrix. Do not repeat common first-use/matching-state cases already covered by the cross-driver class, and do not introduce a third-party proxy service merely to test the documented PgBouncer compatibility boundary.

### Performance assertions

Pin performance through deterministic work counts:

| Path | Required work |
|---|---|
| No configurators | per public hand-out, one empty-list branch; per pooled release, one null-map health branch; no weak map, state object, callback, or SQL |
| Registered, matching state | exact-PDO weak-map lookup plus `state()` and strict string comparison per configurator; no configuration SQL |
| Changed state | same checks plus exactly one `apply()` for each changed configurator |
| Clean same-state release/reborrow | no additional `apply()` |
| MySQL insert closure | one synchronized PDO hand-out/state computation |
| Rollback/failed-commit recovery | conservative reapply on next normal hand-out; no transaction-state stack |

Add test-only counters for `state()` and `apply()` and assert these rows. Do not assert elapsed time in CI.

Before opening the implementation PR, run a local before/after microbenchmark for the zero-configurator and matching-state paths using the same PHP process, connection, operation count, warm-up, and driver. Record the observed result in the PR description, not in production code or a permanent benchmark command. The acceptance criterion is no added SQL in steady state and no surprising regression requiring investigation; do not create a brittle numeric CI threshold.

## Performance model

The feature is not literally instruction-neutral when enabled: a synchronized public hand-out must ask each registered configurator for current desired state and compare it with the physical memo. It is designed to be practically negligible in the steady state and exactly neutral at the database/network layer:

- applications that register nothing pay one predictable empty-array branch per synchronized hand-out plus one null-map health branch per pooled release, and allocate nothing;
- enabled steady-state traffic performs in-process weak-map/string work only;
- database SQL occurs only on first use of a physical PDO, a real state transition, or conservative recovery after a lifecycle invalidation;
- preserving the memo across clean release avoids one SQL round trip per borrow for tenant-sticky workloads;
- physical-PDO keys avoid wrapper churn and eliminate stale wrapper-local caches;
- `state()` is consumer-controlled hot-path code, so the public contract explicitly forbids I/O and recommends a precomputed canonical value;
- a transition is synchronized before application SQL by necessity; there is no background or deferred path that can preserve correctness;
- failure/retry uses existing connection machinery, adding no locks, timers, queues, or retry loops.

Do not describe the enabled path as “zero overhead” or “performance-neutral.” The accurate guarantee is: no additional SQL for a matching state, no allocation/map work when unused, and bounded in-process work when enabled.

## Deliberately rejected designs

Do not add any of the following while implementing this plan:

| Rejected design | Why it is unnecessary or incorrect |
|---|---|
| `rlsFingerprint`, `rememberRlsFingerprint`, tenant IDs, roles, or GUC names in database | Consumer-specific vocabulary and policy do not belong in the generic physical-session extension point |
| Public mutable session-state bag | Exposes framework invariants, invites false memos, and is unnecessary for one opaque state per configurator |
| Named registry, priorities, replacement/removal APIs | No demonstrated runtime mutation or ownership conflict; boot-only ordered append matches existing `...Using()` APIs |
| Closure-only registrar | A typed two-method contract states hot-path and exact-PDO responsibilities more clearly and is container-friendly |
| One configurator singleton only | Prevents independent packages from owning independent non-overlapping settings |
| Per-tenant or per-state connection pools | Multiplies connections and pool complexity; physical session synchronization already handles state transitions |
| `RESET ALL`, `DISCARD ALL`, or blanket release reset | Adds a server round trip per borrow/release, interferes with driver/server facilities, and still needs exact ownership semantics |
| Invalidate on every clean release | Does not reset server state and only creates redundant configuration SQL |
| Read back server state on every hand-out | Adds the round trip memoization is meant to avoid and does not replace explicit ownership |
| Parse or rewrite application SQL | Fragile, driver-specific, and unable to guard retained PDO handles reliably |
| Transaction/savepoint memo stack | Conservatively invalidating on rollback/failure is correct and occurs off the successful hot path |
| Automatic PgBouncer/proxy detection | Pooling topology is deployment configuration and cannot be inferred reliably from PDO |
| Change `beforeExecuting()` | Its timing/retry semantics and request-reset lifecycle are intentionally different |
| Expand `ConnectionInterface` | Alternate connection implementations do not need the concrete static registrar or pool-health helper |
| Public exception hierarchy | Existing runtime, PDO, query, lost-connection, and concurrency errors already communicate the required failures |
| `WeakReference`, object-ID maps, or destructor cleanup | `WeakMap<PDO, ...>` already models exact physical lifetime correctly |
| Per-PDO mutex/lock service | Remote physical sessions are exclusive to one borrowed wrapper; shared in-memory SQLite executes local PDO calls without remote-I/O suspension. A configuration-only lock could not make arbitrary concurrent application use of one PDO safe anyway; the state object's reentry flag fails closed if overlap or recursion is detected |
| PDO proxy object | Breaks established PDO type/API expectations and adds broad interception complexity for an explicit raw escape hatch |
| A second retry subsystem | Existing query and BEGIN retry paths already replay synchronized closures correctly |
| Feature flags or package config in the framework | No registrar means no behavior; an additional switch duplicates that fact |
| Rebuild shared SQLite automatically | Would destroy the in-memory database to heal configuration state; bounded fail-closed behavior is safer |
| Defend retained/raw handles after hand-out | Technically impossible without a PDO proxy; document the ownership boundary instead |

This is the minimum coherent design. Removing the weak-map identity, transaction invalidation, raw control split, or unknown-state handling would reopen a verified correctness hole; adding the rejected machinery would solve no current requirement.

## Stale and dead-code cleanup

After implementation, perform broad searches across `src/database`, `tests`, and `src/boost/docs/database.md` for:

- `resetForPool`, `server state`, `session state`, `getPdo`, `getReadPdo`, `getRawPdo`, `getRawReadPdo`;
- direct `->commit()`, `->rollBack()`, `beginTransaction()`, savepoint SQL, and PDO-resolving transaction control;
- every `->run(` execution closure and every direct PDO `query`, `prepare`, `exec`, or `lastInsertId` use;
- `flushState`, resolver/static reset hooks, and pool health/release comments;
- old test names or prose implying that clean release resets the database server session.

The finished codebase must contain:

- no transaction-control site that accidentally synchronizes except outer BEGIN;
- no ordinary statement closure that bypasses synchronized hand-out;
- no duplicate inline commit lifecycle after `performCommit()` exists;
- no wrapper-local session memo, obsolete helper, unused import, compatibility alias, or dead branch;
- no comment that says what the code trivially says, and no stale comment that claims wrapper reset is a server reset;
- no temporary debug helper, benchmark code, TODO, or tenancy-specific example left in the framework;
- no documentation promise without a matching executable behavior/test.

Prefer deleting superseded comments/tests over layering contradictory caveats on them. Preserve comments only where they explain a non-obvious invariant, particularly raw transaction recovery and session-neutral pool maintenance.

## Validation sequence

During implementation, run the narrowest relevant test after each file or behavior change. Before review, run in this order from the components repository root:

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseSessionConfiguratorTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/ConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseTransactionsTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/PooledConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/ConnectionCoroutineSafetyTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/SessionConfiguratorTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/Postgres/SessionConfiguratorTest.php
./vendor/bin/phpstan
./vendor/bin/php-cs-fixer fix
composer test:parallel
```

The PostgreSQL command requires the repository's integration-test environment. The existing database workflow provides PostgreSQL 17 and 18; verify both CI jobs rather than adding a new workflow matrix.

After formatting, rerun any focused test whose production or test file changed. Then inspect:

```bash
git diff --check
git status --short
git diff -- src/database tests/Database tests/Integration/Database src/boost/docs/database.md
```

The final diff inspection is mandatory because formatting and transaction refactoring can expose a missed direct PDO call or stale comment even when tests pass.

## Completion criteria

Implementation is complete only when all of the following are true:

- the public API has the exact generic naming and two-method shape in this plan;
- registration is boot-only ordered append and is fully reset by `Connection::flushState()`;
- unused applications allocate no session state and execute no additional SQL;
- every public read/write PDO hand-out is synchronized and every internal post-BEGIN transaction-control operation is raw;
- read/write/shared physical identities are memoized correctly;
- clean release and successful commit preserve truthful memos;
- rollback, driver-owned automatic rollback, failed commit, disconnect rollback, failed apply, and reentry follow the exact invalidation/unknown matrix;
- unknown sessions never reach application SQL or return to a pool as healthy;
- normal pooled refresh never leaves a null or partially swapped generation and any refresh failure marks the adapter invalid;
- shared in-memory SQLite unknown recovery is bounded and fail-closed;
- configuration failure composes with existing lost-connection and concurrency retry behavior without new retry machinery;
- deterministic unit, pool, coroutine, all-supported-driver, and PostgreSQL-specific tests cover every lifecycle row and statement family;
- the Boost database documentation fully states the public contract and deployment limits without tenancy-specific coupling;
- all focused checks, PHPStan, formatter, parallel suite, every existing database CI job, and final diff checks pass;
- broad searches find no stale lifecycle statement, bypass, dead helper, obsolete comment, or speculative machinery.
