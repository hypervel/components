# Driver-Neutral Database Architecture, Context Ownership, and Migration Reset Correctness

## Status

Signed-off implementation plan reviewed against the current Hypervel `0.4` worktree and the local Laravel framework, Telescope, and `laravel-clickhouse` references. Re-audit every inventory and caller against implementation-time HEAD.

The work is intentionally independent of ClickHouse. ClickHouse exposed several weak boundaries, but every accepted change is justified by the framework's own correctness, extensibility, diagnostics, or public API. If the ClickHouse package disappeared, Hypervel would still want the final architecture described here.

## Objective

Make Hypervel's database layer a first-class home for both PDO-backed SQL drivers and non-PDO drivers while preserving the complete PDO experience for MySQL, MariaDB, PostgreSQL, SQLite, and future PDO drivers.

The finished codebase must also:

- prevent live pooled Database and Redis resources in their framework-owned context slots from crossing coroutine-context copy boundaries;
- make every context-copy direction apply the same atomic replication and omission rules;
- make Telescope quote bindings through the connection instead of reaching into PDO;
- make database-pool health, refresh, disconnect, and reuse decisions driver-owned without changing the generic Pool package;
- make `migrate:fresh` reset every statically declared migration connection it is about to rebuild;
- preserve Laravel-shaped query, grammar, schema, event, and Eloquent APIs where those APIs are transport-neutral;
- keep PDO-only APIs strongly typed on a PDO-specific class instead of pretending every connection has PDO;
- remove every superseded helper, stale PDO-specific description, temporary adapter, and obsolete test assumption in the same implementation.

This is pre-release architecture work. Compatibility with earlier Hypervel 0.4 development snapshots and the amount of source churn do not constrain the design. Correct supported Laravel call shapes and named arguments remain important because they are the basis for future ports.

## Source-proven findings

### 1. Copied coroutine context can violate pool-slot exclusivity

`CoroutineContext::captureFrom()` copies every selected context value by reference unless it implements `ReplicableContext`. `copyFromNonCoroutine()` has a separate replication loop, while `copyToNonCoroutine()` currently does not honor `ReplicableContext` at all.

The live pool resources stored in context are objects:

- Database stores a borrowed `Connection` under `__database.connection.{name}`.
- Redis stores a borrowed `RedisConnection` under `__redis.connection.{pool}`.

With `parallel(..., copyContext: true)`, sibling coroutines therefore receive the same live object and can use one physical slot concurrently. A detached child can also retain the object after the parent's defer has released its wrapper to the pool. The child did not borrow the slot, so it did not register an owning defer.

This is a driver-independent correctness defect. PDO connections must not be shared concurrently, and a returned pool slot must not remain usable through a copied reference. Native coroutine clients make the defect more visible because concurrent socket use may terminate the worker, but they are not the reason for the fix.

### 2. Telescope's query watcher is not connection-neutral

`Telescope\Watchers\QueryWatcher::quoteStringBinding()` calls `getPdo()->quote()`, catches only `PDOException`, and falls back to MySQL-style escaping. A non-PDO connection can throw a different exception, and PostgreSQL must not be formatted with MySQL escape conventions.

`Database\Connection::escape()` is already the public connection-owned quoting API. The watcher should not select a transport or dialect itself.

The watcher's placeholder matcher also treats PostgreSQL syntax as bindings. A binding named `jsonb`, for example, can replace the cast token in `:payload::jsonb`, while the positional matcher consumes the first question mark in PostgreSQL's doubled `??` operator escape. The latter affects ordinary query-builder output such as `whereJsonContainsKey()`, not only raw SQL.

### 3. The generic Pool package is already driver-neutral

`Hypervel\Contracts\Pool\ConnectionInterface` owns only generic pool lifecycle operations. The PDO coupling is confined to `Database\Pool\PooledConnection` and `Database\Pool\DbPool`:

- heartbeat enumerates raw PDO handles and executes `SELECT 1` itself;
- refresh creates a fresh `Connection`, extracts its PDO handles, and transplants them;
- release reaches into PDO session-state knowledge;
- `DbPool::configureConnectTimeout()` switches on MySQL, MariaDB, and PostgreSQL.

The existing timeout and cancellation harness is sound: heartbeat runs its probe in a child coroutine, waits through a `Channel`, and cancels the child when the deadline expires. That harness stays in `PooledConnection`; only the actual probe becomes driver-owned. The child boundary must continue converting an ordinary probe throwable to `false` while allowing `CanceledException` to terminate quietly.

### 4. The base `Connection` is only partially abstracted from PDO

PDO appears in the constructor, resource properties, fetch mode, select and statement execution, value binding, string escaping, reconnect/disconnect, server version lookup, session synchronization, transactions, statement events, the base insert-ID processor, test database restoration, and pool refresh.

Important indirect dependencies include:

- `ConnectionInterface::getPdo(): PDO`;
- `Concerns\ManagesTransactions::performRollBack(..., PDO $pdo)`;
- `Query\Processors\Processor::processInsertGetId()`;
- `Foundation\Testing\RefreshDatabase`;
- `Events\StatementPrepared`;
- `SessionConfigurator` and `PhysicalSessionState`;
- `DatabaseManager::refreshPdoConnections()`;
- `ConnectionFactory::createSingleConnection()`, whose Laravel-compatible `Connection::resolverFor()` callback receives a PDO closure and therefore cannot be the non-PDO extension seam.

The current insert-ID paths also have real type defects. Generic `Processor::processInsertGetId()` lets `PDO::lastInsertId()` return `false`, which then violates the processor's declared `int|string` return type. `MySqlConnection::insert()` assigns that same `false` to a `string|int|null` property under `strict_types=1`; the assignment throws a `TypeError` inside the `run()` callback before its specialized processor can run. `runQueryCallback()` catches `Exception`, not `Throwable`, so this `TypeError` currently escapes directly rather than being wrapped as a `QueryException`.

### 5. `migrate:fresh` can leave secondary schemas intact

`FreshCommand` wipes only its selected/default connection. Migration objects can independently declare another connection through `Migration::getConnection()`, and `Migrator` correctly routes each migration to that connection, including `migrations_connection` aliases. The subsequent migrate can therefore recreate multiple schemas after only one was wiped.

The current repository-presence gate is also too broad. A reachable database without a migrations table may contain application tables that still need to be wiped. Conversely, a genuinely missing physical database must retain the current behavior where `migrate` creates it. Today a migration declaring a missing secondary connection can fail after earlier migrations have already run, so target preparation belongs to the shared migration-command path rather than Fresh alone. Repository absence and database absence are different states and must not be conflated.

## Desired final architecture

| Concern | Final owner |
|---|---|
| Context values that must never cross copy boundaries | Methodless `Hypervel\Context\NonCopyableContext` marker |
| Context snapshot transformation | One private `CoroutineContext` helper used by every copy direction |
| Query binding quoting | `Connection::escape()` and the concrete driver's `escapeString()` |
| Query/grammar/schema/logging/event orchestration | Driver-neutral abstract `Database\Connection` |
| PDO resources, prepared statements, fetch modes, session state, and PDO transaction hooks | `Database\PdoConnection` |
| MySQL/MariaDB/PostgreSQL/SQLite dialects | Existing dialect classes extending `PdoConnection` |
| Non-PDO driver construction | Config-first `ConnectionFactory::extend()` / `DatabaseManager::extend()` resolver |
| Laravel-compatible PDO connection-class resolver | Existing `Connection::resolverFor()`, explicitly limited to the PDO construction path |
| Pool deadlines and cancellation | Existing `Database\Pool\PooledConnection` harness |
| Health probe, resource replacement, disconnect, and reuse truth | Concrete `Connection` implementation |
| Pool connect deadline exposure | Generic normalized config; each connector/extension maps it to its native client |
| Declared migration reset targets | `Migrator::getMigrationConnections()` |
| Migration path semantics | Existing `BaseCommand::getMigrationPaths()` shared by `migrate` and `migrate:fresh` |
| Missing database classification and creation | Shared migration-command helpers used by both `migrate` and `migrate:fresh` for every declared target |

No execution-strategy object is introduced. The current hierarchy already uses concrete connection subclasses as the extension point, and no demonstrated consumer needs the same SQL dialect over multiple transport families. Protected driver hooks provide the required lifecycle boundary without creating a second extension axis.

## Implementation order

Treat context safety, Telescope escaping, the Connection/PDO plus pool split, and migration/Fresh behavior as four coherent work units. They may ship in one branch, but each unit must have its own focused green tests and leave no temporary compatibility seam. The migration unit is deliberately last and does not gate the driver-neutral class hierarchy.

### 1. Make context copies resource-safe and atomic

#### Production changes

Add `src/context/src/NonCopyableContext.php` as a methodless marker. The name describes the operation being prohibited; ordinary object references are still non-replicated values, so `NonReplicableContext` would be ambiguous.

```php
namespace Hypervel\Context;

interface NonCopyableContext
{
}
```

Refactor `CoroutineContext` so `captureFrom()`, `copyFromNonCoroutine()`, and `copyToNonCoroutine()` all build a complete source map and pass it through one private transformation helper before changing the destination:

```php
private static function prepareForCopy(array $values): array
{
    foreach ($values as $key => $value) {
        if ($value instanceof NonCopyableContext) {
            unset($values[$key]);

            continue;
        }

        if ($value instanceof ReplicableContext) {
            $values[$key] = $value->replicate();
        }
    }

    return $values;
}
```

The ordering is part of the contract: if an object implements both markers, omission wins and `replicate()` is never called.

All three destination writes must be atomic with respect to replication failure. Transform the whole map first, then merge it. Do not mutate a destination one value at a time while replication is still in progress. Omitted keys do not erase a destination's existing value; they behave as if the source did not contain that key.

Apply the transformation only to values stored directly in the context map. Do not recursively walk arrays or object graphs: framework-owned Database and Redis resources are direct context values, while nested application values keep normal PHP copy/reference behavior. Recursive traversal would need cycle/reference handling without solving another verified framework ownership path.

`copyFrom()` continues to delegate to `captureFrom()` and needs no second filter. Preserve all current all-key/selected-key, merge, destroyed-coroutine, integer-key normalization, and non-coroutine storage behavior. Make selected-key copies use key-existence semantics, not `isset()`, so an explicitly stored `null` is treated consistently with an all-key copy.

Implement `NonCopyableContext` directly on:

- `Hypervel\Database\Connection`;
- `Hypervel\Redis\RedisConnection`.

Do not put the marker on `Hypervel\Pool\Connection`. The generic pool package has no context dependency, and some future pool consumer may have a safely replicable logical wrapper. Database and Redis own the proven unsafe objects and already depend on Context.

Continue copying scalar ownership metadata. In particular:

- `ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY` remains copyable so a child borrows its own slot for the same configured database;
- Redis's deferred-release owner coroutine ID remains copyable, but the different child coroutine ID forces the child to register its own defer when it creates its own pin.

#### Documentation changes

Update every current public description discovered by the `copyContext` / `ReplicableContext` documentation sweep, especially:

- `src/docs/coroutine-context.md`;
- `src/docs/coroutines.md`;
- `src/docs/concurrency.md`;
- `src/docs/context.md`;
- the docblocks in `src/coroutine/src/Parallel.php`, `Waiter.php`, and `functions.php`.

State the three exact behaviors for objects stored directly as context values: ordinary objects remain shared references, `ReplicableContext` objects are independently replicated, and `NonCopyableContext` objects are omitted. Explain that the rule is not recursive and that a copied default connection name does not copy a borrowed connection. Keep `src/docs/cache.md`'s statement about object references nested inside copied array-cache values because that behavior does not change.

#### Tests

Extend `tests/Context/ContextCoroutineTest.php` and `ContextTest.php` with:

- all-key and selected-key omission;
- each direction: coroutine to coroutine, non-coroutine to coroutine, coroutine to non-coroutine;
- atomic destination behavior when a later `ReplicableContext::replicate()` throws;
- an object implementing both markers, proving omission precedence;
- preservation of unrelated destination keys and of a destination value whose same-named source value was omitted;
- identical handling of explicitly stored `null` in all-key and selected-key copies;
- no invocation of `replicate()` on a doubly marked object.

Add realistic ownership regressions:

- Database parent/child and concurrent sibling tests with `copyContext: true`, asserting different `Connection` instances and independent releases;
- a detached Database child that continues after the parent exits, proving the parent's released slot is not retained by the child;
- Redis siblings using stateful/pinned operations, asserting distinct `RedisConnection` instances and one correct child-owned deferred release;
- scalar default database and Redis defer-owner behavior.

Use channels to force the failure interleavings. A test that happens to execute sequentially is insufficient.

### 2. Make Telescope use the connection's escaping contract

Replace the entire PDO branch and MySQL-style fallback in `QueryWatcher::quoteStringBinding()` with delegation to the observed connection. Because Telescope is an observer invoked synchronously from `Connection::logQuery()`, an expected runtime failure to represent a binding must not fail the user's already-executed query. Catch only that failure for the individual string binding and substitute one stable redaction marker:

```php
try {
    return $event->connection->escape($binding);
} catch (RuntimeException) {
    return '[REDACTED: UNESCAPABLE BINDING]';
}
```

Remove the now-unused `PDO` and `PDOException` imports and add the `RuntimeException` import. Catch only the documented representability/driver runtime failure from `escape()` for the individual string binding; do not catch `Throwable`, because a driver `TypeError`, assertion, or other programming failure must remain visible. Do not catch around the whole event, invent another quoting algorithm, or expose the original bytes in the marker. Other failures in record construction remain visible; only a value that cannot be represented safely in SQL text is redacted.

Correct four locally verified bugs inherited from `laravel/telescope` in the same method:

- pass replacements through `preg_replace_callback()` so `$1`, backslashes, and other replacement-language bytes in an already escaped binding remain literal;
- `preg_quote()` named keys and require a parameter-name boundary so `:id` cannot replace the prefix of `:id2`;
- require `(?<!:)` before named placeholders so PostgreSQL casts such as `:payload::jsonb` keep both cast colons;
- require `(?<!\?)` and `(?!\?)` around positional placeholders so PostgreSQL's doubled `??`, `??|`, and `??&` operator escapes are not treated as bindings.

Preserve the existing positional replacement limit and repeated named-parameter behavior. These are upstream defects, not intentional Hypervel differences. Report the defects and an upstream-ready patch summary to the owner in the implementation handoff; submitting an external Telescope PR is a separate user-authorized action and is not an implementation acceptance criterion.

Update `tests/Telescope/Watchers/QueryWatcherTest.php` so the nonstandard connection overrides `escape()` instead of manufacturing `PDOException` code `IM001`. Add coverage proving:

- a non-PDO connection never receives `getPdo()`;
- the connection's exact dialect-specific quoted string is used;
- quotes, backslashes, Unicode, dollar/backreference-like bytes, and named bindings still substitute literally;
- a named `:id` binding does not corrupt `:id2`, while repeated exact `:id` placeholders are all replaced;
- a PostgreSQL `::` cast whose type name is also a binding key keeps its cast token;
- a real `whereJsonContainsKey()` SQL shape preserves its doubled `??` operator while a later positional placeholder is replaced;
- null-byte and invalid-UTF-8 bindings record an entry with the exact redaction marker without throwing from the query listener or being reformatted as MySQL SQL.
- a `TypeError` or other non-`RuntimeException` from a broken driver remains visible instead of being redacted.

This change lands before the class split so Telescope is already on the permanent neutral seam.

### 3. Split neutral connection orchestration from PDO mechanics

#### `Connection` and `PdoConnection`

Implement the split copy-first. Copy the intact `Connection.php` to `PdoConnection.php` and reduce the child to PDO-owned members before removing those members from `Connection`. Copy the complete transaction and pool-owned blocks, including comments and docblocks, into `PdoConnection` before changing their sources. Compare each moved block with its source and make every intentional difference explicit.

Make `Hypervel\Database\Connection` an abstract driver-neutral class. Its constructor becomes:

```php
public function __construct(
    string $database = '',
    string $tablePrefix = '',
    array $config = [],
)
```

It retains the behavior that is independent of a physical client:

- grammar, schema builder, processor, query-builder creation;
- `selectOne`, `scalar`, `selectFromWriteConnection`, and insert/update/delete delegation;
- binding preparation for dates and booleans;
- `run()`, exception wrapping, lost-connection policy extension points, query logging, duration handlers, and events;
- pretend mode and before-execution callbacks;
- modified-record and read/write routing state;
- transaction-level orchestration, transaction manager, callbacks, and transaction events;
- database/name/config/table-prefix metadata;
- reconnector registration and invocation;
- macros, driver class resolvers, and static cleanup.

Move all PDO state and mechanics to a new concrete `Hypervel\Database\PdoConnection`:

- PDO/write/read resolver properties and the Laravel-shaped `PDO|Closure` constructor;
- fetch mode and prepared-statement configuration;
- `select`, `selectResultSets`, `cursor`, `statement`, `affectingStatement`, and `unprepared` implementations;
- `bindValues()` and `getPdoForSelect()`;
- `getPdo`, `getRawPdo`, `getReadPdo`, `getRawReadPdo`, resolver, and setter methods;
- read-PDO resolver/setter mechanics and synchronized physical handle selection;
- `SessionConfigurator`, `PhysicalSessionState`, unknown-state replacement, and the static PDO session map;
- PDO string quoting and server-version lookup;
- physical begin/savepoint/commit/rollback and physical transaction inspection;
- PDO resource disconnect, health probe, replacement, and reuse checks.

`MySqlConnection`, `PostgresConnection`, and `SQLiteConnection` extend `PdoConnection`; `MariaDbConnection` remains a `MySqlConnection` subclass. Their grammars, processors, schema builders, unique-constraint parsing, binary/bool escaping, SQLite transaction mode, and MariaDB version handling remain where they are.

Keep `QueryException extends PDOException`, `DeadlockException extends PDOException`, and `UniqueConstraintViolationException extends QueryException`. `QueryException` already accepts any `Throwable` and copies `errorInfo` only from an actual `PDOException`, so a non-PDO driver needs no fake PDO exception. This isolated Laravel-compatible exception taxonomy does not put PDO mechanics back into the neutral connection. Keep the DB facade's PDO getter annotations for built-in connections, while documenting that code holding an explicitly neutral `Connection` must narrow to `PdoConnection` before direct PDO access.

Keep logical read/write routing and diagnostics neutral. Rename `$latestPdoTypeRetrieved` to `$latestReadWriteTypeRetrieved` and `$readPdoConfig` to `$readConnectionConfig` on `Connection`, and remove stale PDO terminology from error details and comments. Keep both properties protected: `PdoConnection` updates them when resolving write/read handles, its Laravel-compatible `setReadPdoConfig()` writes the neutral config property, and a native or HTTP subclass can publish the same state directly. Do not add one-line publisher helpers with no separate invariant.

Make PDO string escaping use the effective physical session that executed the most recent query. `PdoConnection::escapeString()` selects `getPdo()` when `latestReadWriteTypeUsed()` is `write` and `getReadPdo()` otherwise. The explicit branch is clearer than reusing the select-named helper and needs no role parameter or Telescope-specific hook. This is a correctness invariant, not only an optimization: quoting can depend on physical session configuration, while synchronizing the other endpoint during synchronous query diagnostics can apply configurators, reconnect unknown state, or turn that other endpoint's runtime failure into Telescope redaction. It also prevents a split base or explicit `::write` wrapper from opening and configuring its lazy read endpoint solely to format a binding. Keep a short comment above the branch explaining the session-configuration dependency and the unwanted endpoint resolution. A fresh connection with no prior role keeps Laravel's read default. Pretend mode also keeps that default because it deliberately executes no query and therefore has no truthful physical role to record; do not add pretend-only role machinery.

Make explicitly requested roles unambiguous on `DatabaseManager`'s direct/non-pooled cache path. Before factory construction, stamp `READ_WRITE_TYPE_CONFIG_KEY` with the parsed `::read` or `::write` role; keep the existing `configForRead()` endpoint selection when a separate read configuration exists, and otherwise preserve today's base/fallback and write-forced resource behavior. The manager's default reconnector can then reconstruct the exact requested configuration from the invoking wrapper's base name plus neutral role metadata without adding requested-name state or scanning the cache by object identity. Do not change `PoolFactory`'s intentional ownership rule: pooled `::write` continues sharing the base pool, and `::read` gets a separate pool only when a separate read configuration exists.

`PdoConnection` remains directly constructible as the generic PDO-backed connection used by framework tests and custom PDO drivers. Replace every direct `new Connection($pdo, ...)` in the repository with `new PdoConnection($pdo, ...)`, except tests that deliberately use a minimal non-PDO test connection.

Split the current monolithic connection tests by the same ownership rule as production code. Keep transport-neutral orchestration in `tests/Database/DatabaseConnectionTest.php`; its transaction orchestration cases may continue using a working `PdoConnection` fixture when PDO is only the mechanism and the assertions concern logical depth, retry, manager publication, event order, or exception precedence. Use a small concrete neutral test connection for neutral driver contracts and hook routing. Move PDO resource, statement, fetch, binding, session, and physical transaction cases to `DatabasePdoConnectionTest.php`. Repoint PDO session fixtures such as `TestSessionConnection` to `PdoConnection`. Preserve each moved Laravel-derived test's relative upstream order and do not duplicate coverage between the files.

#### Explicit neutral driver operations

The base class must make transport requirements visible instead of inheriting hidden PDO behavior. Require concrete implementations for:

- `select`, `cursor`, `statement`, `affectingStatement`, and `unprepared`;
- `escapeString`;
- `getServerVersion`;
- `ping(): bool`;
- `inTransaction(): bool`, reporting physical transaction truth for testing and lifecycle checks;
- driver-resource presence, disconnect, and replacement hooks.

Keep precise unsupported defaults on the neutral base for PDO-shaped optional capabilities: `selectResultSets()` throws because a generic driver cannot promise multiple wire result sets, `getLastInsertId()` throws because many stores have no generated-ID concept, and `getSchemaState()` retains its existing unsupported-driver exception. `PdoConnection` overrides the first two; the built-in dialect connections override `getSchemaState()` where a schema dumper exists. This avoids making every HTTP/native driver repeat identical throwing methods while keeping failures explicit.

Place the neutral `getLastInsertId()` immediately after `insert()`, matching `ConnectionInterface` while keeping Laravel's escape-method block contiguous. Keep the PDO and MySQL overrides with their respective PDO-resource and captured-ID invariants rather than forcing every subclass into interface order.

Add `escape(mixed $value, bool $binary = false): string`, `getLastInsertId(?string $sequence = null): int|string`, and `inTransaction(): bool` to `ConnectionInterface`. Each passes the contract rule that every implementation must supply the behavior: query/event/testing consumers need connection-owned escaping, `Query\Builder` exposes only `ConnectionInterface`, the generic `Processor::processInsertGetId()` requires generated-ID access, and framework lifecycle/testing code requires physical transaction truth. The neutral base throws for unsupported generated IDs; `inTransaction()` remains abstract, and a deliberately non-transactional implementation returns `false` while its transaction entry points throw. This also removes `MySqlProcessor`'s current `method.notFound` suppression and lets `InteractsWithDatabase::castAsJson()` use the contract without narrowing or suppression.

The neutral base's precise unsupported exception is itself the required `getLastInsertId()` behavior every implementation supplies; support is not optional or silently absent merely because the default is a failure.

Keep Laravel's public `$useReadPdo` parameter names on select/cursor APIs. They are supported named-argument shapes even though a non-PDO driver interprets the boolean as its read/write routing choice.

Use one narrow resource replacement entry point, not a strategy object:

```php
/**
 * Refresh the driver resources from a fresh connection.
 *
 * @internal
 */
final public function refreshFrom(Connection $fresh): void
{
    if ($fresh::class !== static::class || $fresh->getName() !== $this->getName()) {
        throw new LogicException(sprintf(
            'Cannot refresh connection [%s] of type [%s] from connection [%s] of type [%s].',
            $this->getName() ?? '',
            static::class,
            $fresh->getName() ?? '',
            $fresh::class,
        ));
    }

    $this->replaceDriverResources($fresh);
}

abstract protected function replaceDriverResources(Connection $fresh): void;
```

The public method owns the common connection-class and configured-name identity invariant, which also makes the protected hook an earned implementation seam rather than a one-line extraction. Replacement has one eager semantic: each implementation validates and captures a complete replacement set that the discarded fresh wrapper cannot close before disturbing the current resources. The hook's docblock must define that set as both driver resources and resource-associated metadata, including the configured database and table-prefix baselines described below. Every operation that can reject the replacement must occur before old-resource teardown. Once teardown begins, the old generation is gone, so the prepared generation is adopted in a `finally`; the wrapper always ends with a complete generation at logical depth zero and the original teardown throwable propagates unchanged. This is safe because `disconnectDriverResources()` must forget the current resources through `forgetDriverResources()` in its own `finally`. `ConnectionEstablished` is not dispatched when teardown throws because both reconnect owners dispatch only after `refreshFrom()` returns. For PDO, preparation calls both `getPdo()` and `getReadPdo()` to validate the effective write/read paths, then transfers the fresh wrapper's post-resolution raw properties so a connection with no explicit read handle preserves its normal `null`-means-write fallback rather than acquiring a synthetic second role. Do not add a lazy/eager flag: `DB::reconnect()` succeeding should mean the replacement is usable, and this path is not a query hot path.

Treat resource-associated metadata as part of that generation. The neutral constructor stores protected `configuredDatabase` and `configuredTablePrefix` baselines from its normalized constructor arguments as well as the mutable current values. PDO replacement explicitly captures and adopts the fresh wrapper's two baselines, current database, current table prefix, selected write config, read connection config, and configured single-role read/write type alongside its handles, then resets the last selected role. Capture every value before teardown and assign it only inside the adoption `finally`: validation failure retains the complete old generation, while teardown failure still installs the complete prepared generation. This keeps failover diagnostics and future grammar/config reads aligned with the actual endpoints. Retain wrapper-owned event/transaction managers, reconnector, query log, duration handlers, callbacks, macros, and logical routing/modification state; those belong to the long-lived wrapper, not the disposable factory result. Native drivers apply the same ownership rule to their endpoint metadata.

`resetForPool()` restores the mutable database and table prefix from those configured baselines and clears the last selected read/write role together with its existing per-borrow routing reset. This prevents public `setDatabaseName()` or `setTablePrefix()` calls, and the previous borrow's physical role, from leaking to the next coroutine. The configured single-role `readWriteType` remains unchanged. Keep the reset in the neutral base so a config-first native driver that derives its logical database or prefix from a DSN needs no special override. `MySqlConnection::resetForPool()` continues calling the parent before clearing its captured insert ID. Clone handling needs no special branch because scalar baselines copy with the wrapper.

Tighten `Connection::getConfig()`'s docblock to `@return ($option is null ? array<string, mixed> : mixed)` so no-argument consumers such as the migration admin creator receive the truthful array shape without local narrowing.

PDO references need no artificial detach step because destroying the temporary wrapper does not close PDO objects retained by the long-lived wrapper. A native driver whose temporary wrapper or destructor closes owned resources must detach or otherwise transfer that ownership before adoption. A failed factory or validation call still leaves the complete old generation intact because teardown has not begun.

The neutral base owns reconnect orchestration through the existing callback. `reconnectIfMissingConnection()` consults a protected `hasDriverResources(): bool` hook. `disconnect()` invokes a protected `disconnectDriverResources(): void` hook and logical transaction-manager cleanup while preserving the earliest throwable. Each driver implements graceful cleanup in that hook and must call its `forgetDriverResources(): void` hook in a `finally`, so cleanup failure cannot leave stale resources attached. The forget hook drops a known-dead resource generation without attempting physical I/O; it is also used after a lost transaction operation. These hooks are protected because callers should use `reconnectIfMissingConnection()`, `disconnect()`, and `refreshFrom()` rather than manipulating transport state.

For PDO, `hasDriverResources()` is true when the write property contains either a PDO or its lazy closure; the optional read property may validly fall back to write and is not a second presence requirement. This preserves lazy first use. PDO disconnect preserves the current physical rollback behavior: a lost-connection throwable is suppressed, while the earliest other physical or logical-cleanup throwable wins. Its `finally` calls `forgetDriverResources()`, whose PDO implementation clears both properties through `setPdo(null)->setReadPdo(null)`, never through direct assignment. `setPdo()` resets the logical level before the neutral base publishes the level-zero rollback to the transaction manager, which is behaviorally equivalent to the current teardown order and also lets MySQL clear its captured insert ID through one setter override.

Add a generic `isReusable(): bool` hook. The neutral default returns `true`; `PdoConnection` returns `false` for unknown physical session state. A non-PDO transport can report an ambiguous or poisoned client without teaching the pool about that transport. Mark `ping()`, `isReusable()`, and `refreshFrom()` as framework-internal lifecycle methods even though they must be public for the separate pool/manager collaborators. Remove the superseded public `hasUnknownSessionState()` helper and update pool, testing-resolver, and tests to consume `isReusable()`; PDO may keep only a private/protected predicate needed to implement the generic result.

Do not add `supportsTransactions()`. The neutral transaction algorithm calls protected physical hooks. Default unsupported hooks throw a precise `LogicException`; PDO implements them. A deliberately non-transactional driver may override the public transaction entry points to provide one consistent domain message, as the ClickHouse bridge does.

#### Insert-ID correction

Add `getLastInsertId(?string $sequence = null): int|string` to the neutral connection contract used by the processor. Neutral `Connection::getLastInsertId()` throws a `LogicException` because unsupported generated IDs are a driver capability fact. `PdoConnection` calls PDO and throws a `RuntimeException` when PDO returns `false`; it never normalizes failure into a sentinel. `Processor::processInsertGetId()` calls the connection method instead of PDO and retains its existing numeric-string-to-int conversion. Keep its existing separate insert and ID handouts; do not add MySQL-style capture state to SQLite or another generic PDO path without a demonstrated need.

Widen `MySqlConnection::getLastInsertId()` with the compatible optional sequence parameter and continue returning its captured post-insert ID. `PdoConnection` centralizes `PDO::lastInsertId() === false` handling in a protected `getLastInsertIdFrom(PDO $pdo, ?string $sequence = null): int|string` helper. Its public getter calls that helper with `getPdo()`. `MySqlConnection::insert()` retains its raw `$pdo->prepare($query)` path and passes the same already-synchronized PDO that executed the statement to the helper; do not route inserts through `prepared()` or dispatch `StatementPrepared`. Add a short comment at the capture call explaining that the ID must come from the session that executed the insert. This preserves physical-handle identity and avoids a second `SessionConfigurator::state()` pass when configurators are registered. The `false` path inside the query callback becomes a `QueryException` whose previous exception is the `RuntimeException`.

`MySqlConnection::getLastInsertId()` throws a `RuntimeException` if no ID has been captured rather than falling back to a fresh/stale PDO value or returning its current `null`; its sequence argument is intentionally ignored because MySQL captured the ID during insert execution. Do not include the sequence in either runtime message. Do not add new `#[Override]` attributes to the rewritten MySQL methods; the dialect classes currently reserve that attribute for their established `getSchemaState()` pattern.

Add one concise inline comment in the MySQL getter saying that the sequence is intentionally ignored because the ID was captured from the insert session. The existing insert comment owns the fuller same-session explanation.

Clear the captured property whenever MySQL's write generation changes. Override `MySqlConnection::setPdo()` so every public write-resource mutation clears it, and override `resetForPool()` to call `parent::resetForPool()` before clearing it. The PDO disconnect and replacement paths must use `setPdo()` rather than assigning the property directly; do not add duplicate ad hoc clearing branches or a base-class last-ID hook for one dialect-owned property. Add regressions for `false`, direct getter before insert, pool release/reborrow, direct `setPdo()`, disconnect, and refresh.

Keep `MySqlProcessor`'s `arguments.count` PHPStan suppression because only `MySqlConnection::insert()` accepts the sequence; adding that dialect-only parameter to `ConnectionInterface::insert()` would make every driver API worse. Remove only the `method.notFound` suppression once the neutral contract owns `getLastInsertId()`. Do not return `0`, an empty string, or `false` as a compatibility sentinel.

#### Transactions and testing

Refactor `Concerns\ManagesTransactions` so it contains no `PDO` type or call. Keep logical levels, attempts, manager publication, event ordering, exception precedence, and retry orchestration unchanged. Delegate these physical operations to protected driver hooks:

- begin the outer transaction;
- create a savepoint;
- commit the physical transaction;
- roll back to zero or a savepoint.

The nested concurrency branch also needs one protected `invalidateCurrentSessionState()` hook. The neutral default is a no-op because it owns no framework-managed session memo; `PdoConnection` performs the exact existing `invalidateSessionState($this->resolvePdo())` call. Do not substitute `markCurrentSessionStateUnknown()`: nested driver-owned rollback must clear remembered configurator state without issuing another rollback or poisoning the retained session.

Have the four default unsupported physical transaction hooks call one private `throwUnsupportedTransactionException(): never` helper. The shared helper owns their identical exception and message; concrete drivers continue overriding only the hooks they support.

Move the current PDO implementations, including their complete comments, docblocks, and session invalidation rules, to `PdoConnection` before changing the trait. PDO rollback absorbs the current PDO resolution, non-lost unknown marking, and final state invalidation before rethrowing to the neutral handler. This moves final invalidation before the failure handler, but the end state is unchanged: invalidation only clears applied state, while marking unknown also clears applied state, so the operations are order-independent. Preserve the deliberate asymmetry where commit does not mark lost or concurrency failures unknown, while rollback omits only lost failures.

Replace the mixed `terminateTransactionState()` helper with two precise neutral pieces. `resetTransactionState()` owns the shared depth-zero assignment and transaction-manager rollback publication used by both normal `disconnect()` and lost cleanup. `forgetLostConnection()` uses `try { $this->resetTransactionState(); } finally { $this->forgetDriverResources(); }`, so a known-dead transport is dropped without calling it again even when manager cleanup fails. Both lost commit/rollback handlers keep their outer cleanup swallow so the original physical failure remains primary. Normal `disconnect()` still calls `disconnectDriverResources()` and therefore retains graceful physical rollback and unknown-state marking for a failed handle another wrapper may still reference. `PdoConnection::disconnectDriverResources()` calls `forgetDriverResources()` in `finally`; native drivers own the same graceful-disconnect versus hard-forget distinction.

`PdoConnection::inTransaction()` inspects only an already-resolved raw write PDO and returns `false` for a lazy resolver or missing handle; lifecycle inspection must not open a connection. A non-transactional driver also returns `false` while its transaction entry points remain loud. Add a neutral counting-connection regression proving nested concurrency handling calls `invalidateCurrentSessionState()` exactly once and does not call the rollback hook. Add explicit PDO call counters proving lost commit cleanup performs no subsequent `inTransaction()` or rollback call, while lost rollback cleanup performs only its initial one of each and never re-enters the dead handle.

Replace `Foundation\Testing\RefreshDatabase`'s PDO assumptions with honest connection APIs:

- cache/restore in-memory resources only for an in-memory SQLite `PdoConnection`;
- replace direct `getPdo()->inTransaction()` with `inTransaction()`;
- keep explicit non-transactional entries in `connectionsToTransact()` loud at `beginTransaction()`;
- retain the existing coroutine set-up/tear-down ordering and the lockstep relationship between the migrated flag and cached in-memory resources.

Do not add a flag that silently removes a connection from the test transaction list.

Keep `Schema\SchemaState` itself typed to neutral `Connection`: its process/configuration behavior does not require PDO. Narrow only the operation that actually accesses PDO. In particular, `SqliteSchemaState::load()` must require or validate `PdoConnection` before the in-memory `exec()` path; MySQL/PostgreSQL process-based dumpers do not gain a false PDO dependency.

#### Internal physical-session maintenance

Schema builders already have internal statements that intentionally bypass `statement()` so physical-session maintenance does not run query callbacks, logging, or user-facing query events. Preserve that hardening without exposing PDO:

```php
/**
 * Mark the current physical session state as unknown.
 *
 * @internal
 */
public function markCurrentSessionStateUnknown(): void
{
    // Neutral drivers have no framework-managed session memo by default.
}

/**
 * Execute an internal physical-session statement.
 *
 * @internal
 */
public function executeSessionStatement(string $sql): void
{
    throw new LogicException(sprintf(
        'Database driver [%s] does not support physical session statements.',
        $this->getDriverName(),
    ));
}
```

`PdoConnection` overrides both methods: it executes through the current write PDO without query callbacks/logging and marks that physical session unknown on any failure. `Schema\Builder::executeSessionStatement()` and every SQLite physical-session call delegate to this connection method rather than calling `getPdo()` or duplicating invalidation. Keep these narrowly named methods out of `ConnectionInterface`; they are framework-internal schema/session mechanics, not behavior every consumer-facing connection contract must expose.

#### PDO-only events and configuration

`StatementPrepared` remains PDO-specific. Move its dispatch to `PdoConnection::prepared()` and type its connection property as `PdoConnection`. Do not invent a mixed generic statement event; there is no common statement object or demonstrated listener contract.

Move the static session configurator registry and `configureSessionUsing()` to `PdoConnection`, because its public contract explicitly receives PDO. Type both `SessionConfigurator::state()` and `apply()` with `PdoConnection`, not neutral `Connection`. Update `src/docs/database.md`, tests, facade annotations, and static test cleanup accordingly. `Connection::flushState()` retains neutral resolver/macro cleanup. `PdoConnection::flushState()` calls it first, then clears both its session-configurator list and physical-session `WeakMap`; `AfterEachTestSubscriber` calls `PdoConnection::flushState()` as the single authoritative database cleanup. Add a subscriber regression that registers a configurator, creates tracked PDO session state, runs cleanup, and proves both PDO-owned static collections plus the neutral state are empty so this cannot silently become parent-only cleanup.

#### Factory seams

Keep `ConnectionFactory::make()` config-first:

```php
if (isset($this->extensions[$name])) {
    return ($this->extensions[$name])($config, $name);
}

if (isset($this->extensions[$driver])) {
    return ($this->extensions[$driver])($config, $name);
}

return $this->createPdoConnectionFromConfig($config);
```

The existing name/driver `extend()` callbacks are the documented non-PDO seam and already run first in `ConnectionFactory::make()`; preserve that ordering and validate only that they return neutral `Connection` instances. Do not claim that `Connection::resolverFor()` can run before a PDO resolver exists: its Laravel-compatible callback signature receives the lazy PDO closure as its first argument.

Make the canonical `DB::extend()` example construct the neutral base shape with `database: $config['database'] ?? ''`, `tablePrefix: $config['prefix']`, and `config: $config`. `parseConfig()` guarantees the prefix and embeds the configured name, but it does not guarantee a database key.

Retain `Connection::resolverFor()` for the Laravel-compatible custom PDO connection-class use case. Rename/refactor the built-in path to `createPdoConnectionFromConfig()`, build the lazy closure there, consult the resolver there, and validate that it returns a `PdoConnection`. Building that closure establishes no connection and costs no network operation. Do not present `resolverFor()` as the non-PDO registration API. Update the factory tests and canonical database documentation to make the two seams unambiguous; `DatabaseServiceProvider` needs no artificial adapter or registration change because it already exposes the singleton factory through `DatabaseManager::extend()`.

This keeps future Laravel PDO driver ports straightforward: port the connector, `PdoConnection` dialect subclass, query grammar, schema grammar/builder, and processor using the same structure as MySQL/PostgreSQL/SQLite. HTTP/native drivers extend neutral `Connection` and register through `extend()` without a fake PDO closure.

#### Laravel's direct endpoint

Preserve Hypervel's existing, documented replacement for Laravel's current nested `direct` endpoint and `::direct` suffix. A direct endpoint remains a normal named connection referenced by `migrations_connection`, with its own complete pool and connection configuration. This is cleaner in Hypervel's pooled runtime than adding a third PDO role inside one wrapper, and it works for PDO and non-PDO transports alike. Keep the rejection in `ConnectionName`, the source/test omission markers required by the porting policy, and the README difference. Do not add Laravel's `directPdo` properties or methods to `Connection` or `PdoConnection` during the split.

### 4. Make Database pool lifecycle driver-neutral

Implement this in the same architectural work unit as the class split so no temporary PDO-shaped hook lands and is immediately replaced.

Refactor `Database\Pool\PooledConnection` to depend only on neutral `Connection` operations:

- heartbeat's child coroutine calls `$connection->ping()`;
- `PooledConnection` retains the current channel deadline, cancellation, error containment, and last-use timestamp behavior; the child catches ordinary `Throwable` and reports `false`, while `CanceledException` exits without publishing a result;
- refresh asks the factory for a complete fresh connection and calls `$connection->refreshFrom($fresh)`;
- close calls driver-neutral `disconnect()`;
- release resets logical wrapper state, rolls back declared logical transactions, evaluates `isReusable()`, and returns/discards the wrapper as today;
- remove PDO imports, `getOpenPdos()`, `pingPdos()`, raw PDO access, and PDO transplantation.

The existing shared in-memory SQLite branch still asks `DbPool` for its retained PDO and asks `ConnectionFactory::makeSqliteFromSharedPdo()` for a complete fresh `PdoConnection`. It then uses the same `refreshFrom()` path as every other driver; `PooledConnection` must not extract or set raw handles itself. Tighten the factory method's return type and validation to `PdoConnection`, while continuing to allow custom SQLite subclasses of `PdoConnection`.

A matching config-first name or `extend('sqlite', ...)` callback cannot participate in pooled in-memory SQLite: it receives config rather than the retained shared PDO, so the factory cannot replay it without changing the extension API or letting it open a separate empty database. The incompatibility must be rejected before creating the pool's initially retained handle, not only during a later refresh. Add `makeSharedInMemorySqliteConnection(array $config, ?string $name = null): PdoConnection` for that initial construction; it parses/selects the write config, validates the extension constraint, and constructs through the ordinary PDO path. `DbPool::createSharedInMemorySqlitePdo()` obtains its retained handle from that validated connection. `makeSqliteFromSharedPdo()` applies the same validation before constructing around the retained handle. Both public methods call one private `ensureNoSharedInMemorySqliteExtension()` guard so the matching rule and precise exception pointing to `Connection::resolverFor('sqlite', ...)` cannot drift. Both then route through the same `createConnection()` / `Connection::resolverFor('sqlite', ...)` seam, so a Laravel-compatible custom PDO resolver returns the identical concrete subclass on initial creation and refresh; that is the load-bearing invariant required by `refreshFrom()`'s exact-class check.

Keep the initial method specific to pooled in-memory SQLite rather than exposing a general extension-bypassing factory API. The factory already owns its extension map, so do not expose a new `hasExtension()` API, add a second callback signature/config sentinel, or create a shared-resource strategy for this one built-in case.

`PdoConnection::ping()` preserves current behavior exactly: inspect only already-resolved distinct write/read PDOs, execute raw `SELECT 1`, close cursors, fire no query events/logs, and return `true` without opening a lazy PDO when no handle has been used. A non-PDO driver decides whether an unused client is healthy and how to probe an opened client.

Replace `DbPool::configureConnectTimeout()`'s driver switch with generic normalization. Expose the validated pool deadline to the connection config, without rounding away fractional precision:

```php
$this->config['connect_timeout'] ??= $this->option->getConnectTimeout();
```

Then:

- MySQL/MariaDB connectors map the top-level value to `PDO::ATTR_TIMEOUT` with the native integer/ceiling rule unless the user supplied that PDO option directly;
- PostgreSQL applies `(int) ceil()` to whatever top-level `connect_timeout` value is present—pool-derived or explicitly configured—before placing it in the DSN, because libpq rejects fractional seconds;
- SQLite ignores it;
- custom extensions receive the normalized value and map it to their native client.

While editing `MySqlConnector`, correct the adjacent raw identifier interpolation in its `USE` statement: double embedded backticks in the configured database name before execution. Keep this direct and local; a one-use quoting service is not justified. Add a regression with a database name containing a backtick so legitimate configured identifiers cannot produce malformed SQL.

Keep SQLite's Laravel-style application-relative path fallback without assuming that a loaded `base_path()` helper has a usable application root. After direct `realpath()` fails, call `base_path($database)` only when the helper exists and either `BASE_PATH` is defined or the global container has the Foundation application contract. A standalone Capsule has no application root, so missing absolute and relative paths must reach the connector's existing `SQLiteDatabaseDoesNotExistException` instead of the Foundation helper's `RuntimeException`. Keep this decision local to `SQLiteConnector`; changing the helper, catching its internal exception, or adding a path-resolution strategy would weaken another boundary or add needless machinery.

Keep the current in-memory SQLite resource retention and single-owner capacity in `DbPool`. It is a proven built-in driver requirement, not a reason to create a generic shared-resource strategy. Rename only descriptions that incorrectly imply every pool resource is PDO. `src/pool` receives no source change.

Replace `DatabaseManager::refreshPdoConnections()` with one driver-neutral helper used by the manager's default reconnector. Given the invoking wrapper, derive its requested name from its base `getName()` plus the normalized `READ_WRITE_TYPE_CONFIG_KEY`, build and configure a fresh wrapper for that exact requested configuration, then call `refreshFrom()` on the invoking wrapper. The manager must not look up a different cached wrapper or copy a resource from the return value of `reconnect()`.

`DatabaseManager::reconnect($name)` removes its unconditional leading `disconnect($name)`. When the requested pooled/context wrapper exists, it delegates to that wrapper's reconnector, whose `PooledConnection::refresh()` owns replacement. When the requested non-pooled cache entry exists, it likewise calls that wrapper's `reconnect()`, whose manager-installed callback invokes the helper above. Thus query-triggered and explicit reconnect share one non-pooled refresh implementation; the public manager method does not independently build or replace resources. With `::read`/`::write` role normalization, the callback refreshes the invoking derived wrapper in place without creating or touching a base-name cache entry. `getName()` intentionally remains the base configured name on both old and fresh wrappers, so `refreshFrom()`'s exact-name invariant passes and must not be loosened. Do not pre-resolve resources in the manager; the concrete replacement hook owns eager validation.

Dispatch `ConnectionEstablished` exactly once and only after complete successful replacement. The component that performs replacement owns the event: the manager's non-pooled refresh helper dispatches after `refreshFrom()`, while `PooledConnection::refresh()` dispatches for its callback path. `DatabaseManager::reconnect()` must not dispatch again after invoking either reconnector. Update pooled and non-pooled reconnect documentation and tests, including event count, eager failure timing, derived-role identity, and read/write validation.

#### Current-HEAD consumer audit

Update behavior, types, comments, and tests at every current caller rather than stopping after the class split:

- the DatabaseManager reconnector uses neutral reconnect/refresh and never copies a raw PDO back into the wrapper;
- `Queue\DatabaseQueue::getLockForPopping()` resolves its connection once, uses `getDriverName()` and `getServerVersion()` instead of PDO attributes, preserves the `getConfig('version') ?? getServerVersion()` short circuit, and removes the unsupported SQL Server lock branch plus the unused PDO import. Today it resolves the connection twice when a configured version exists and three times otherwise, so the single local removes one or two complete resolver round trips from every queue pop. Keep reachable MariaDB, Vitess, and PlanetScale behavior unchanged;
- `Foundation\Testing\DatabaseTruncation` and `RefreshDatabase` narrow only retained in-memory SQLite resources to `PdoConnection` and otherwise use neutral lifecycle/transaction APIs;
- `Foundation\Testing\DatabaseConnectionResolver` resets and evaluates cached connections through neutral lifecycle methods while keeping any SQLite PDO retention explicitly narrowed;
- `Foundation\Testing\Concerns\InteractsWithDatabase::castAsJson()` uses the connection's `escape()` contract rather than `getPdo()->quote()`;
- schema builders use the internal session-statement seam above;
- `QueryException` and `Events\QueryExecuted` retain Laravel's public `readWriteType` name while changing PDO-specific descriptions to connection-role wording;
- `StatementPrepared`, `SessionConfigurator`, `PhysicalSessionState`, and PDO session tests narrow to `PdoConnection`;
- all PDO-requiring direct constructions/imports in source and tests use `PdoConnection`; minimal neutral test doubles extend `Connection` and implement only the required transport hooks.

`DatabaseManager::availableDrivers()` may retain its Laravel-compatible `PDO::getAvailableDrivers()` check because it reports availability of Hypervel's built-in PDO drivers; custom `extend()` drivers have never been enumerated by that API. Do not contort this diagnostic API or imply that its result lists registered extension drivers.

### 5. Make `migrate:fresh` reset all declared migration connections

First make `resolveMigrationConnectionName()` idempotent without adding alias-chain machinery. Resolve one `migrations_connection` hop, then inspect the target's own value. A missing target key or explicit self-reference is terminal; any different second target is invalid configuration and throws a precise exception naming the attempted route. `Repository::string()` rejects non-strings but accepts `''`, so reject an empty effective source after resolving a `null` input to the configured/context default, and reject an empty first-hop target before inspecting it; do not trim or add broader normalization. Preserve the current no-config-container passthrough used by isolated unit tests, where there is no repository from which to resolve or validate an alias.

Document the terminal rule and `InvalidArgumentException` on the resolver itself. Give its docblock the conditional return type `($name is null ? null|string : string)`. `getMigrationConnections()` must retain its first null/empty guard because the no-config passthrough can genuinely return `null` for a null argument; after that validated default is substituted for empty migration declarations, the second post-resolution guard is unreachable and must not remain.

The supported shape is therefore `pooled -> direct`, not `a -> b -> c`. Valid targets remain unchanged when `setConnection()`, `resolveConnection()`, `migrate:install`, and `db:wipe` resolve them again, while chains and cycles fail before mutation. A traversal and visited set would support an unverified configuration need and is intentionally not added.

Re-audit `Migrator::usingConnection()` after that change. Keep its direct `finally` restoration, but replace the now-stale alias-workaround explanation: the method captures the migrator's stored connection and the coroutine's effective default independently, and those values can differ. Calling `setConnection($previousStored)` would overwrite both with one value and fail to restore the prior coroutine context. Add a regression that starts with distinct stored/context values, enters an aliased target, and proves both original values and the repository source are restored after success and after an exception. Idempotent alias resolution removes double-resolution hazards; it does not collapse these two state owners.

Add this purpose-specific API to `Migrator`:

```php
/**
 * Get the distinct connections declared by the migrations at the given paths.
 *
 * @return list<string>
 */
public function getMigrationConnections(
    array|string $paths,
    ?string $defaultConnection = null,
): array;
```

Implementation rules:

1. Use `getMigrationFiles()` so path ordering and duplicate migration-name behavior match `run()`.
2. Resolve each file through protected `resolvePath()`, not public `resolve()`, so anonymous returned migrations, named migrations, real paths, and the required-path cache all behave exactly as execution does.
3. Read `Migration::getConnection()` without calling `shouldRun()`. Use the same valid-migration contract already required by `runMigration()`; do not invent a fallback connection for an invalid resolved object.
4. Resolve a non-empty effective default first, then run the file-resolution loop inside `usingConnection($effectiveDefault, ...)` so constructors and `getConnection()` observe the same coroutine default as execution and all migrator/repository/context state is restored afterward.
5. Treat `null` and `''` declarations as that effective default and resolve every result through the same one-hop terminal `migrations_connection` validation as migration execution.
6. Include the effective default even when there are no migration files, because schema dumps and the traditional fresh behavior still target it.
7. Deduplicate final targets while preserving deterministic first-seen order.
8. Do not execute `up()`, `down()`, `shouldRun()`, or other application methods, and do not inspect arbitrary PHP source; construction plus the framework's `getConnection()` contract is the only application code discovery invokes.

Ignoring `shouldRun()` is required: a migration may have created tables during an earlier run even when its current runtime predicate is false. Fresh is resetting the schema the migration set can own, not predicting only the current up calls.

#### Share target inspection and creation

Move the existing MySQL/MariaDB, PostgreSQL, and SQLite missing-database classification/creation routines from `MigrateCommand` to protected helpers on `BaseCommand`, using resolved target names for inspection and the resolved connection for server-database creation. Keep authorization in each owning command rather than hiding prompts inside the creator. Both commands use the same two-phase flow:

1. inspect every target read-only by entering `Migrator::usingConnection($target, ...)` and calling `repositoryExists()`;
2. a normal `true` or `false` result means the physical database pre-existed; repository absence is not database absence;
3. when inspection throws, walk the throwable chain and classify only the existing supported signals: SQLite's missing-file exception, MySQL/MariaDB error 1049, and PostgreSQL SQLSTATE `08006` whose message names the configured database;
4. retain each classified cause in a simple `array<string, Throwable>` keyed by target so creation can occur later; do not add a DTO, registry, or policy object;
5. immediately propagate invalid connection names, non-PDO driver errors, authentication/network/permission failures, and every unclassified throwable;
6. after the owning command has authorized its complete set, create only the classified targets, then verify all creations before any later migration or wipe.

SQLite creation uses the classified path. MySQL/MariaDB/PostgreSQL creation retains Hypervel's copied-config one-off admin connection rather than mutating process-global configuration, but its copy must come from the resolved connection's already parsed and write-merged `getConfig()` rather than re-reading the raw named config. The raw path is incorrect for split endpoints: a different `write.database` both trips the current mismatch guard and would overwrite a top-level admin database during the factory's second merge. Remove that guard and the redundant `ConfigurationUrlParser` pass. This deliberately creates the database that the resolved connection actually tried to open, including when public `setDatabaseName()` changed it.

Set the copied admin database to `''` for MySQL/MariaDB and `'postgres'` for PostgreSQL; the current MySQL `null` value violates the strict factory's `string $database` path. In the PostgreSQL arm, also remove `connect_via_database` because `PostgresConnector` gives it priority over `database`; retaining it can make the admin wrapper reopen the missing application database. A different pooler alias remains unclassified because its error does not name the configured application database, so the framework must not invent a database creation for it. Preserve `connect_via_port`, inert single `write` and `read_write_type` values, and the factory's normal host selection.

Quote the complete target returned by the resolved connection's `getDatabaseName()` through that connection's query grammar `wrapIdentifier()` method; do not use `wrap()`, which splits dots as qualified-name separators, and never interpolate a configured name between raw backticks or quotes. Build the small dialect statement around that quoted identifier, preserve MySQL/MariaDB's idempotent `IF NOT EXISTS` behavior, and always disconnect the temporary admin connection in `finally`.

`migrate --pretend` never creates a database; a missing target remains loud because pretending cannot inspect a nonexistent migration repository. Remove the old retry callback and selected-connection-only creation path after the shared preflight owns the behavior—there must be one classifier and one creator.

#### Standalone `migrate`

After its existing production confirmation, `MigrateCommand` computes paths and declared targets and performs the shared read-only inspection. `BaseCommand` is the single owner of the migrator property used by its shared helpers, so its subclasses must not redeclare that property. If databases are missing, authorize the complete set before creation: `--force` proceeds without another prompt, `--no-interaction` without force fails, and an interactive run prints every missing target and asks one Laravel-style confirmation. Declining creates nothing. Then create and verify every missing target before preparing the central migration repository or executing any migration. A secondary target does not receive its own migration repository; migration records remain on the command-selected repository exactly as today.

This prevents a first migration from mutating the default database before a later migration discovers that its declared secondary database is absent. It also makes the existing missing-default convenience consistent across every statically declared target.

#### `migrate:fresh`

Change `FreshCommand` to extend the existing migration `BaseCommand`. Remove its duplicate migrator property and use `getMigrationPaths()` so discovery exactly matches `migrate` for registered paths, application paths, relative `--path`, and `--realpath`.

`FreshCommand::handle()` runs in this exact order:

1. return immediately if the command is prohibited;
2. compute paths and distinct resolved targets;
3. perform the shared read-only inspection and classify verified-missing targets, propagating every other failure before any mutation;
4. print the complete target list and clearly identify which targets would be created and which pre-existing targets would be wiped;
5. call `confirmToProceed()`; declining creates and wipes nothing;
6. create and verify every classified missing target using the already-confirmed forced behavior;
7. call `db:wipe` once for every target that pre-existed, regardless of whether its migration repository exists, with the existing views/types/force options; Wipe must disconnect that resolved connection's physical driver resources without purging its wrapper, resolver ownership, or pool membership, because a lazy-refresh callback resumes on the wrapper that triggered it;
8. never wipe a database created by this command because it is already empty;
9. run the existing single nested `migrate --force` only after every creation and wipe succeeds;
10. after a successful migrate, preserve Laravel's event-before-seed order: dispatch `DatabaseRefreshed` with the command-selected connection and seeding flag, then run the requested seeder.

"Pre-existing" is decided from the initial read-only inspection, not re-checked after creation. No wipe occurs unless all required creations have succeeded. The nested `migrate` deliberately repeats the now-idempotent preflight; do not add a skip flag. Treat non-zero creation, wipe, migrate, or seeder results as command failure. A seed failure occurs after `DatabaseRefreshed` by Laravel's established ordering; the event reports that the database was refreshed and its `seeding` flag announces the following seed phase, so do not reorder it as a generic command-success event.

Use one task per target so output identifies failures. The confirmation must precede database creation as well as wiping; creating a database before an operator declines Fresh would be an unauthorized mutation.

Document the declarative discovery boundary: a migration's `getConnection()` result defines its fresh target and should be stable for that migration. A migration that manually calls `Schema::connection('other')` inside `up()` cannot be discovered without executing arbitrary code. Such cross-connection work should be split into migrations with explicit connection declarations. Do not add a repeatable CLI target option, parser, reset policy registry, or source-code scanner.

## File-level checklist

### Context and coroutine

- `src/context/src/NonCopyableContext.php` — add marker.
- `src/context/src/CoroutineContext.php` — one atomic copy transformation for all directions.
- `src/database/src/Connection.php` — implement marker while performing the neutral split.
- `src/redis/src/RedisConnection.php` — implement marker.
- `src/coroutine/src/Parallel.php`, `Waiter.php`, `functions.php` — correct copy semantics in API docs.
- `src/docs/coroutine-context.md`, `coroutines.md`, `concurrency.md`, and `context.md` — document top-level omission wherever direct context values are described.
- `tests/Context/*`, `tests/Coroutine/*`, `tests/Integration/Database/ConnectionCoroutineSafetyTest.php`, `tests/Redis/RedisProxyTest.php`, and `tests/Integration/Redis/RedisProxyIntegrationTest.php` — add copy and ownership regressions.

### Telescope

- `src/telescope/src/Watchers/QueryWatcher.php` — delegate escaping.
- `tests/Telescope/Watchers/QueryWatcherTest.php` — replace PDO fallback fixture and add neutral/dialect, literal replacement, named-boundary, PostgreSQL cast/operator, and redaction cases.
- implementation handoff — report the four locally verified upstream Telescope defects and an upstream-ready patch summary without performing an unauthorized external submission.

### Database architecture and pool

- `src/database/src/Connection.php` — neutral abstract orchestration and resource/transaction hooks.
- `src/database/src/PdoConnection.php` — all PDO mechanics.
- `src/database/src/ConnectionInterface.php` — remove `getPdo`, add `escape()`, `getLastInsertId()`, and `inTransaction()`, and remove PDO wording.
- `src/database/src/Concerns/ManagesTransactions.php` — remove PDO types/calls.
- `src/database/src/MySqlConnection.php`, `PostgresConnection.php`, `SQLiteConnection.php`, `MariaDbConnection.php` — repoint inheritance and retain dialect behavior.
- `src/database/src/Connectors/ConnectionFactory.php` — separate config-first extensions from PDO construction and give initial plus replacement shared in-memory SQLite construction explicit validated `PdoConnection` paths with one private extension guard.
- `src/database/src/Connectors/MySqlConnector.php`, `MariaDbConnector.php`, `PostgresConnector.php` — native timeout mapping and the MySQL `USE` identifier correction.
- `src/database/src/Pool/PooledConnection.php`, `DbPool.php` — neutral lifecycle delegation and generic timeout normalization.
- `src/database/src/DatabaseManager.php` — one invoking-wrapper refresh path, direct-cache role metadata, and exactly-once reconnect events.
- `src/queue/src/DatabaseQueue.php` — use connection driver/version APIs instead of PDO attributes.
- `src/database/src/Query/Processors/Processor.php` — neutral insert-ID call and false normalization at PDO owner.
- `src/database/src/Schema/Builder.php`, `SQLiteBuilder.php` — delegate internal physical-session statements and invalidation through neutral connection methods.
- `src/database/src/Schema/SchemaState.php`, `MySqlSchemaState.php`, `PostgresSchemaState.php`, `SqliteSchemaState.php` — keep the base neutral and narrow only the in-memory SQLite PDO load operation; neutral `Connection::getSchemaState()` keeps its precise unsupported default while built-in dialect connections return concrete states.
- `src/database/src/Events/StatementPrepared.php` — PDO-specific connection type.
- `src/database/src/Events/QueryExecuted.php`, `QueryException.php`, and `Query/Builder.php` — preserve public read/write API names while removing incorrect PDO-only descriptions from neutral routing state.
- `src/database/src/SessionConfigurator.php`, `PhysicalSessionState.php` — retain PDO contract under `PdoConnection` ownership.
- `src/foundation/src/Testing/RefreshDatabase.php` — honest resource and physical-transaction APIs.
- `src/foundation/src/Testing/DatabaseTruncation.php`, `DatabaseConnectionResolver.php`, and `Concerns/InteractsWithDatabase.php` — narrow retained SQLite PDOs and otherwise use neutral lifecycle/escaping APIs.
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php` — call `PdoConnection::flushState()` once so neutral state plus the PDO configurator list/session map are cleared.
- `src/support/src/Facades/DB.php` — correct annotations.
- `src/docs/database.md`, `src/docs/pools.md`, `src/docs/porting-from-laravel.md`, and the minimal `src/database/README.md` — PDO/non-PDO extension paths, pool probes, session configuration, concise lasting public differences, and future Laravel database-change routing.
- Every source/test import and direct construction found by a final broad content sweep — use `PdoConnection` when the test or consumer needs PDO.
- `tests/Database/DatabaseConnectionTest.php` and `DatabasePdoConnectionTest.php` — separate neutral orchestration from PDO mechanics without duplicate cases.

### Migrations

- `src/database/src/Migrations/Migrator.php` — one-hop terminal alias validation, independent `usingConnection()` state restoration, and declared target discovery.
- `src/database/src/Console/Migrations/BaseCommand.php` — shared paths plus all-target read-only inspection, missing-database classification, and creation.
- `src/database/src/Console/Migrations/MigrateCommand.php` — preflight/create every declared target before repository preparation or migration execution.
- `src/database/src/Console/Migrations/FreshCommand.php` — multi-target wipe and explicit failure handling.
- `src/database/src/Console/WipeCommand.php` — disconnect physical resources without invalidating the logical wrapper or its pool ownership.
- `tests/Database/DatabaseWipeCommandTest.php` — resolved-target disconnect behavior without manager purge.
- `tests/Database/DatabaseMigrationFreshCommandTest.php` — command behavior.
- `tests/Database/DatabaseMigrationMigrateCommandTest.php` — all-target missing-database preflight and creation.
- `tests/Database/DatabaseMigratorConnectionRoutingTest.php` and new migration fixtures — named/anonymous discovery and alias resolution.
- `tests/Integration/Database/MigrationsConnectionRoutingTest.php` plus SQLite fresh coverage — end-to-end mixed connections and missing database behavior.
- `src/docs/migrations.md`, `src/docs/porting-from-laravel.md`, the minimal database README difference, and console help text — declared target semantics, central migration-history ownership, and the deliberate Fresh divergence.

### Documentation and future Laravel syncs

Keep one user-documentation source in `src/docs/`. Explain the PDO/non-PDO extension choice in `database.md`, pooling lifecycle in `pools.md`, context-copy omission wherever context copying is described, and multi-target migration behavior in `migrations.md`. The pooling documentation must state that connections open on demand in the borrowing coroutine and that lifetime recycling occurs only while a connection is idle or before reuse. Add only concise, action-oriented entries to `porting-from-laravel.md` for differences a Laravel application/package porter must account for: direct PDO access requires `PdoConnection`; Laravel's nested `direct` endpoint and `::direct` suffix map to a normal named Hypervel connection plus `migrations_connection`; and Fresh discovers/resets declared migration connections.

Keep the database package README minimal, but make its existing `Connection` / `PdoConnection` difference the durable routing note for future Laravel database updates: driver-neutral connection behavior stays on `Connection`, PDO mechanics move to `PdoConnection`, and driver-specific behavior stays on the matching connection, grammar, schema builder, or processor. Do not add a per-package file under `docs/upstream-sync/` or place this maintainer guidance in `sync.yaml`; that directory owns sync workflow and state, while package READMEs own lasting Laravel differences. Preserve Laravel relative constant/property/method order within each destination class without adding source comments to ordinary adapted methods.

## Testing plan

After editing or creating any test file, run that file immediately. The commands and matrices below are the minimum focused coverage, not permission to defer another changed test file until the full gate.

### Context safety

Run each changed test class immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Context/ContextCoroutineTest.php
./vendor/bin/phpunit --no-progress tests/Context/ContextTest.php
./vendor/bin/phpunit --no-progress tests/Coroutine/ParallelTest.php
./vendor/bin/phpunit --no-progress tests/Coroutine/WaiterTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/ConnectionCoroutineSafetyTest.php
```

Run the Redis proxy unit and integration classes that receive the new pinned-resource cases:

```bash
./vendor/bin/phpunit --no-progress tests/Redis/RedisProxyTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Redis/RedisProxyIntegrationTest.php
```

The unit test uses pool doubles to assert exact object identity and release ownership. The integration case forces siblings to overlap on a stateful operation and proves post-parent child use without sharing a physical client.

### Telescope

```bash
./vendor/bin/phpunit --no-progress tests/Telescope/Watchers/QueryWatcherTest.php
```

### Connection/PDO split and pool lifecycle

Run the affected unit classes one at a time, including:

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabasePdoConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseSessionConfiguratorTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectionFactoryTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseManagerTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseProcessorTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectorTest.php
./vendor/bin/phpunit --no-progress tests/Database/PoolFactoryTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseSchemaBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseSQLiteBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseQueryExceptionTest.php
./vendor/bin/phpunit --no-progress tests/Database/QueryDurationThresholdTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/Testing/DatabaseConnectionResolverTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/Testing/DatabaseTruncationTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/Testing/Concerns/InteractsWithDatabaseTest.php
./vendor/bin/phpunit --no-progress tests/Queue/QueueDatabaseQueueUnitTest.php
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php
```

Add a focused neutral connection test that proves:

- the base public contract contains no PDO getter/setter;
- a config-first custom non-PDO driver is created without constructing or invoking a PDO connector;
- query logging, events, grammars, pretend mode, modified-record state, reconnect, pool reset, and insert-ID errors remain coherent;
- `ConnectionInterface::escape()`, `getLastInsertId()`, and `inTransaction()` support generic event/testing, processor, and lifecycle callers without narrowing or PHPStan suppressions;
- `selectResultSets()`, `getLastInsertId()`, and `getSchemaState()` use the neutral base's precise unsupported defaults while PDO/dialect connections override only the capabilities they provide;
- a PDO `lastInsertId() === false` becomes the specified direct or query-wrapped runtime failure and never a `TypeError` or sentinel;
- `DatabaseProcessorTest` mocks `getLastInsertId('id')` directly and contains no PDO import, PDO setup, or dead PDO stub;
- `DatabasePdoConnectionTest` owns the MySQL single-handout regression and keeps exact `stateCalls === 1` and `applyCalls === 1` assertions;
- MySQL's `false` result is a `QueryException` whose previous exception is the precise `RuntimeException`, while the generic processor path returns its raw `RuntimeException` after `insert()` completes;
- MySQL captured insert IDs never survive pool reset, disconnect, or refresh and an uncaptured getter never falls back to PDO state;
- neutral nested concurrency handling invokes `invalidateCurrentSessionState()` once without invoking the rollback hook;
- PDO escaping after write, read, write-forced read, and sticky read execution quotes through that exact effective session without resolving the opposite endpoint;
- an explicit `::write` wrapper over split configuration leaves its lazy read resolver unopened when escaping, while a fresh wrapper with no prior execution role preserves the read default;
- pool reset restores constructor-derived database and table-prefix baselines even when raw config omits them, clears the last selected role, and preserves the configured single-role `readWriteType`;
- a pooled `::read` wrapper restores its selected read endpoint's configured database and prefix, while MySQL's override still clears its captured insert ID after the neutral reset;
- lost commit cleanup performs no subsequent physical transaction inspection or rollback, while lost rollback cleanup performs only its initial inspection and rollback attempt;
- `SchemaState` remains neutral while the in-memory SQLite load path rejects a non-PDO connection precisely;
- `AfterEachTestSubscriber` reaches `PdoConnection::flushState()` and empties neutral state, the PDO configurator list, and the PDO session `WeakMap`;
- an unsupported transaction fails explicitly;
- `StatementPrepared` is emitted only by PDO execution.

Run pool and session integration classes:

```bash
./vendor/bin/phpunit --no-progress tests/Integration/Database/PooledConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/DbPoolTeardownLifecycleTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/SessionConfiguratorTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/Sqlite/DbPoolHeartbeatTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/Sqlite/InMemorySqliteSharedPdoTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/Postgres/PooledConnectionStateTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/Postgres/SessionConfiguratorTest.php
```

The heartbeat timeout regression must feed the normal pool harness through a name-specific config-first factory extension that returns a connection subclass with a slow `ping()`. Preserve its explicit static coroutine-ID reset, raw interruptible `usleep()`, child-termination assertion, and no-late-requeue assertion. Remove the old test-only `PooledConnection::getOpenPdos()` fixture with the production PDO helpers; overriding `PooledConnection::ping()` would bypass the behavior under test.

Run the MySQL, MariaDB, PostgreSQL, and SQLite integration matrix through the existing database workflow/environment. Verify read/write lazy resources, sticky routing, session configurators, transaction cleanup, heartbeat timeouts/cancellation, idle/lifetime recycling, shared in-memory SQLite, and native connect-timeout mapping.

Add focused reconnect tests proving a cached non-pooled connection retains its complete old resource/metadata generation when fresh eager validation fails, eagerly validates both read/write resources before replacement, adopts newly selected write/read endpoint metadata on success while preserving wrapper-owned state, and dispatches `ConnectionEstablished` exactly once only after success on both pooled and non-pooled paths. Cover both teardown failure sources: a transaction-manager rollback callback failure after successful physical cleanup, and a non-lost physical rollback failure that poisons only the old PDO. In both cases the exact original throwable propagates, logical depth reaches zero, and the complete clean prepared generation plus metadata is adopted without an establishment event. The prepare-failure regression must prove the old configured database/prefix baselines survive; the teardown-failure regressions must reset after adoption and prove the fresh baselines survive. Cover cached `::read` and `::write` wrappers explicitly: each refreshes itself from its own selected-role configuration, retains the base `getName()`, and neither creates nor mutates a second base-name cache entry. Add connector tests proving MySQL/MariaDB option precedence and PostgreSQL ceiling conversion for both pool-derived and explicit fractional `connect_timeout` values.

Run missing absolute and relative SQLite path cases in a fresh PHP subprocess that proves neither `BASE_PATH` nor a Foundation application binding exists, then assert the exact `SQLiteDatabaseDoesNotExistException` and original path. An in-process negative case is insufficient because Testbench permanently defines `BASE_PATH` for its PHPUnit worker. Add an in-process positive case with a real application root proving that an existing relative database path still resolves through `base_path()`.

For shared in-memory SQLite, add a custom `Connection::resolverFor('sqlite', ...)` callback returning a `PdoConnection` subclass and prove initial construction plus shared-PDO refresh produce that same subclass and refresh successfully. Add negative cases for a non-PDO class resolver and config-first `extend('sqlite', ...)`: each fails with the precise configuration/identity exception before any current connection releases a resource.

### Multi-connection fresh

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseMigrationFreshCommandTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseMigrationMigrateCommandTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseMigratorConnectionRoutingTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseWipeCommandTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/MigrationsConnectionRoutingTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/Sqlite/Console/MigrateFreshCommandWithJournalModeWalTest.php
./vendor/bin/phpunit --no-progress tests/Testbench/Databases/MigrateWithHypervelMigrationsTest.php
./vendor/bin/phpunit --no-progress tests/Testbench/Databases/MigrateWithHypervelMigrationsWithoutTestingPoolTest.php
./vendor/bin/phpunit --no-progress tests/Testbench/Databases/LazilyRefreshDatabaseFileConnectionTest.php
```

Required cases:

- default plus one and several explicitly declared connections;
- named and anonymous migrations;
- registered paths, application path, relative `--path`, and `--realpath`;
- duplicate declarations and two aliases resolving to one target;
- one-hop aliases resolving idempotently, with empty/non-string targets, nested chains, and cycles failing before confirmation/mutation;
- absent migrations repository with unrelated tables still present;
- genuinely absent default and secondary SQLite/MySQL/PostgreSQL databases being discovered read-only and created by both commands before migration/destruction;
- MySQL/MariaDB/PostgreSQL creation from the resolved write configuration, including split `write.database`, explicit-role metadata, URL-derived credentials, and PostgreSQL `connect_via_database`; type-safe server-level admin database values; quoting a complete target identifier containing dots and dialect quote characters; retaining MySQL/MariaDB `IF NOT EXISTS`; and disconnecting the one-off admin connection after success or failure;
- `migrate --pretend` refusing to create a missing target;
- standalone `migrate` authorizing the full missing-target set before mutation, with `--force`, interactive decline, and `--no-interaction` behavior;
- declining Fresh after its target report creating and wiping nothing;
- Fresh never wiping a target it just created, and never wiping any target when a required creation fails;
- standalone migrate performing all-target preflight before the first migration so a missing later target cannot produce a partial run;
- invalid connection declaration, authentication failure, and network failure remaining loud;
- a `shouldRun() === false` migration still contributing its declared target;
- schema-path/default target with no migration files;
- in-memory SQLite;
- wipe or migrate failure aborting before seed/event publication, and seed failure producing command failure;
- `DatabaseRefreshed` dispatching after migrate but before the requested seeder, including the documented seed-failure result;
- lazy refresh preserving the triggering wrapper on both testing resolver paths, and rolling back the triggering write on a persistent SQLite database before the next test;
- deterministic task output and one final migrate invocation.

### Full gates

After focused tests are green:

```bash
composer fix
```

`composer fix` already runs formatting, PHPStan, the parallel suite, and Testbench. Do not redundantly run those full checks separately at the same checkpoint. If it fails, follow the repository workflow: fix with targeted checks, then run the failed entry and every remaining `fix` entry in order.

Run the repository's configured documentation validation if one exists. Use broad `grep` sweeps across `src/`, `tests/`, and living documentation (excluding historical `docs/plans/` records and local upstream references) to prove that stale PDO descriptions, direct base `Connection` PDO construction, old `refreshPdoConnections` naming, old session registration examples, `NonReplicableContext`, alias-chain claims, and claims that every copied object survives are gone outside explicit compatibility/difference explanations.

## Performance and worker-lifetime review

- The context marker adds one `instanceof` only while explicitly copying context, not on ordinary request execution.
- The connection split adds no strategy dispatch to query hot paths. Existing virtual method calls remain virtual method calls.
- PDO string escaping adds one branch only when SQL text is explicitly rendered. It reuses the already selected query session, avoiding wrong-endpoint connection, session-configuration, and reconnect work; ordinary query execution is unchanged. Pretend mode has no executed-session role and retains the read-default behavior.
- Telescope's two placeholder guards add fixed-width adjacency checks only while recorded SQL is formatted; they add no database, network, context, or ordinary query-execution work.
- `PooledConnection` keeps one child coroutine and channel only when heartbeat is enabled, exactly as today.
- `isReusable()` adds one driver method call on pool release, where PDO-specific state is already checked today.
- Configured database/prefix baselines add two refcounted string properties per wrapper and three scalar assignments on pool release; they add no query-path, context, database, or network operation.
- Connect-timeout mapping occurs during connection construction only.
- Non-pooled reconnect now eagerly opens and validates its write/read replacement set instead of transplanting lazy closures. This may open two sockets on a reconnect with split endpoints, but reconnect is an exceptional/explicit lifecycle path and the cost buys atomic replacement and truthful success; pooled refresh already pays this cost.
- Direct cached `::read`/`::write` wrappers retain current endpoint ownership while carrying explicit role metadata for in-place refresh; pooled `::write` still reuses the base pool rather than creating another resource collection.
- Migration target discovery/preflight occurs only in migration console commands and loads the same files execution loads. Fresh's nested migrate repeats read-only probes intentionally; no request/query hot path changes.
- `PdoConnection`'s static session maps retain `WeakMap` ownership. Move cleanup registration with the state; do not duplicate it.
- No new worker-global registry, mutex, per-query strategy object, or long-lived resource collection is introduced.

## Rejected designs and why

- **Key-prefix exclusions in Context:** hard-code Database/Redis ownership into a generic package and miss future unsafe types. A marker expresses the value's semantics at its owner.
- **Putting the marker on generic Pool connections:** adds a Context dependency and assumes every pool consumer has the same copy rule.
- **Deep-copying live connections:** cannot produce an independently owned physical slot or transfer the native defer stack.
- **Recursively scanning copied arrays/object graphs for markers:** framework resources are direct context values; recursive traversal adds cycle/reference machinery while changing documented nested application-value semantics.
- **PDO-or-mixed getters on neutral `Connection`:** preserve a dishonest API and move failures to runtime consumers such as Telescope.
- **Changing `QueryException` to `RuntimeException`:** gains conceptual purity but breaks useful Laravel exception taxonomy and requires broader deadlock/detector changes even though non-PDO drivers already wrap arbitrary `Throwable` without fake PDO state.
- **An execution-driver strategy object:** creates an unused transport/dialect matrix and weaker types. The class hierarchy is the earned extension seam.
- **A lazy/eager flag on `refreshFrom()`:** leaks lifecycle policy into the transport seam and preserves a non-pooled implementation detail. One eager, validated replacement contract is simpler and makes reconnect success truthful.
- **A generic statement-prepared event:** there is no cross-driver statement type or demonstrated listener behavior.
- **A `supportsTransactions()` flag:** invites silent branching. Unsupported transaction entry must fail; supported drivers implement physical hooks.
- **Changing the generic Pool package:** its contract is already neutral; the defect is in the Database adapter.
- **Per-driver heartbeat timeout implementations:** duplicate the existing correct cancellation harness.
- **A generic shared-resource strategy for in-memory SQLite:** only SQLite needs it today, and the existing explicit single-owner rule is correct.
- **Traversing migration aliases:** supports unverified chains and needs cycle machinery. One-hop resolution plus terminal-target validation is idempotent and matches the pooled-to-direct use case.
- **Fresh-only database creation or dropping secondary creation entirely:** the former makes `migrate` inconsistent; the latter regresses missing-default behavior and permits partial migration/destruction. Shared all-target preflight is the smaller complete rule.
- **Repeatable `migrate:fresh` target options, a target-resolver service, or PHP source scanning:** the migration declarations already provide a deterministic target set; arbitrary calls inside `up()` cannot be discovered safely without execution.
- **Silently excluding non-transactional connections in tests:** hides a user configuration error and removes isolation without consent.

## Completion criteria

Implementation is complete only when:

1. every context-copy direction has identical atomic replication/omission semantics;
2. copied Database and Redis contexts can no longer share a borrowed resource;
3. Telescope has no PDO import or fallback quoting algorithm, substitutes literal/named bindings without replacement-language or prefix corruption, and preserves PostgreSQL casts and escaped question-mark operators;
4. neutral `Connection` exposes no PDO resource API and all built-in drivers retain full typed PDO access through `PdoConnection`;
5. config-first custom drivers can be pooled, probed, refreshed, disconnected, and discarded without fake PDO state;
6. current MySQL, MariaDB, PostgreSQL, and SQLite tests prove unchanged query/grammar/schema/transaction/session behavior;
7. insert-ID failure can never return `false` through an `int|string` API;
8. migration aliases resolve one hop to a validated terminal target, both migration commands prepare every declared database before mutation, and `migrate:fresh` creates verified-missing targets only after confirmation, wipes every pre-existing target without replacing its logical connection wrapper or pool ownership, and fails on every other error;
9. public documentation accurately separates PDO drivers, native/HTTP drivers, pool lifecycles, context copy rules, and migration target declarations;
10. the database package README accurately routes future Laravel connection changes without duplicating user documentation or sync-state guidance;
11. focused tests, `composer fix`, documentation validation, source/documentation sweeps, and a final fresh review all pass with no stale or dead artifacts.
