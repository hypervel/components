# Database Read / Write Routing and Pool Config Plan

## Goal

Bring Hypervel's database read / write public API closer to current Laravel while keeping the internals native to Hypervel's long-lived Swoole workers, coroutine context, and database connection pools.

This plan does four things:

1. Keeps the current automatic read / write routing design because it is the right default for Hypervel.
2. Adds safe `::read` / `::write` connection suffix support without copying Laravel's unsafe shared-connection mutation.
3. Fixes pooled connection URL parsing and the `db --read` / `db --write` config merge path.
4. Documents the operational sizing behavior of read / write pooled connections.

Churn and backwards compatibility do not matter. The final codebase should read as if Hypervel was designed this way from the start.

## References Checked

Hypervel source:

- `src/database/src/ConnectionResolver.php`
- `src/database/src/DatabaseManager.php`
- `src/database/src/Connectors/ConnectionFactory.php`
- `src/database/src/Connection.php`
- `src/database/src/Pool/PoolFactory.php`
- `src/database/src/Pool/DbPool.php`
- `src/database/src/Pool/PooledConnection.php`
- `src/database/src/DatabaseTransactionsManager.php`
- `src/database/src/Migrations/Migrator.php`
- `src/database/src/Console/DbCommand.php`
- `src/boost/docs/database.md`
- `tests/Database/DatabaseConnectionTest.php`
- `tests/Database/DatabaseConnectionFactoryTest.php`
- `tests/Database/DatabaseManagerTest.php`
- `tests/Database/PoolFactoryTest.php`
- `tests/Integration/Database/DatabaseConnectionsTest.php`
- `tests/Integration/Database/ConnectionCoroutineSafetyTest.php`
- `tests/Integration/Database/PooledConnectionTest.php`

Laravel source:

- `/home/binaryfire/workspace/monorepo/examples/laravel/framework/src/Illuminate/Database/DatabaseManager.php`
- `/home/binaryfire/workspace/monorepo/examples/laravel/framework/src/Illuminate/Database/Connection.php`
- `/home/binaryfire/workspace/monorepo/examples/laravel/framework/src/Illuminate/Database/Connectors/ConnectionFactory.php`
- `/home/binaryfire/workspace/monorepo/examples/laravel/framework/src/Illuminate/Database/Console/DbCommand.php`
- `/home/binaryfire/workspace/monorepo/examples/laravel/framework/tests/Integration/Database/DatabaseConnectionsTest.php`
- `/home/binaryfire/workspace/monorepo/examples/laravel/framework/tests/Database/DatabaseManagerTest.php`
- `/home/binaryfire/workspace/monorepo/examples/laravel/framework/tests/Database/DatabaseConnectionFactoryTest.php`

Hypervel history:

- PR #370 added first-class Postgres external pooler support through a separately configured pooled connection plus `migrations_connection`.
- Commit `7f71c7f0f` removed Laravel's `::read` / `::write` implementation because Laravel mutates the shared `Connection` object's PDO routing, which does not fit Hypervel's pooled Swoole runtime.

## Current Hypervel Behavior

`ConnectionResolver::connection()` resolves a connection name, borrows a `PooledConnection` from `PoolFactory`, stores the concrete `ConnectionInterface` in `CoroutineContext`, and schedules release with `Coroutine::defer()`. The context key is currently:

```php
sprintf('__database.connection.%s', $name)
```

`DbPool` creates one `DbPool` per configured connection name. Each pool slot contains a `PooledConnection`, and each `PooledConnection` wraps one `Connection`.

`ConnectionFactory::make()` creates either:

- a single connection when no `read` config exists; or
- one write connection with a lazy read PDO closure when `read` config exists.

`Connection::getReadPdo()` already routes correctly:

```php
if ($this->transactions > 0) {
    return $this->getPdo();
}

if ($this->readOnWriteConnection
    || ($this->recordsModified && $this->getConfig('sticky'))) {
    return $this->getPdo();
}

$this->latestPdoTypeRetrieved = 'read';

if ($this->readPdo instanceof Closure) {
    return $this->readPdo = call_user_func($this->readPdo);
}

return $this->readPdo ?: $this->getPdo();
```

`Connection::resetForPool()` clears request/coroutine state, including:

- query callbacks
- query log state
- duration handlers
- `readOnWriteConnection`
- pretend mode
- `recordsModified`

This means sticky read routing and forced write-read routing do not leak to another coroutine after pool release.

## Current Laravel Behavior

Laravel parses `::read`, `::write`, and `::direct` in `DatabaseManager::parseConnectionName()`:

```php
protected function parseConnectionName($name)
{
    return Str::endsWith($name, ['::read', '::write', '::direct'])
        ? explode('::', $name, 2)
        : [$name, null];
}
```

Laravel then mutates the connection in `setPdoForType()`:

```php
if ($type === 'read') {
    $connection->setPdo($connection->getReadPdo());
} elseif ($type === 'write') {
    $connection->setReadPdo($connection->getPdo());
} elseif ($type === 'direct') {
    $connection->setPdo($connection->getDirectPdo())
        ->setReadPdo($connection->getDirectPdo());
}
```

This mutation is safe enough in Laravel's lifecycle, but it is not safe to copy into Hypervel because Hypervel reuses pooled connection objects across coroutines.

Laravel's recent Postgres external pooler PR also added `::direct` and `--pooled`, but Hypervel already has a cleaner native model: configure a separate pooled connection and use `migrations_connection` for migration/schema paths.

## Design Decisions

### Keep Automatic Routing As-Is

Do not replace Hypervel's automatic read / write routing with a deep role-aware pool wrapper.

Reason:

- The current design is lazy: actual PDO sockets open only when first used.
- The current design is coroutine-safe: request routing state is reset on pool release.
- The current design preserves one coherent `Connection` object with query log, events, query duration handlers, grammars, processors, transaction state, and transaction manager hooks.
- A deep automatic role-aware wrapper would need to keep all of that state coherent across separate physical pools. That adds complexity and risk for limited gain.

The main operational tradeoff is that a single pool slot can lazily hold both one write PDO and one read PDO. This must be documented.

### Add Safe `::read` / `::write`

Add `DB::connection('name::read')` and `DB::connection('name::write')` because they are Laravel public API and useful for explicit diagnostics or code that accepts only a connection name.

Do not port Laravel's mutation implementation.

Hypervel behavior:

- `name::read` uses a derived read config and a separate read pool when the base connection has a non-null `read` config, using the same `isset($config['read'])` gate as `ConnectionFactory::make()`. An empty `read` array still counts and merges back to the base config; `read => null` does not.
- `name::write` uses the base pool under a suffixed coroutine context key and calls `useWriteConnectionWhenReading(true)` on that borrowed connection.
- A connection with no read / write split treats `name::read` and `name::write` as physical aliases of the base connection and never errors.
- `::direct` remains unsupported because Hypervel uses `migrations_connection` plus separate configured connections instead.

Unsplit suffixes are compatibility aliases only. They should not synthesize a fake read role because there is no configured read side, and doing so would require a mutable per-borrow read marker that Hypervel does not otherwise need.

### Fix Pooled URL Parsing

`DatabaseManager::configuration()` parses `url`, but `DbPool::__construct()` currently reads the raw config and `ConnectionFactory::parseConfig()` does not parse URLs. In pooled Swoole runtime, `DB_URL` / `DB_POOLED_URL` can be ignored.

Fix URL parsing in both:

- `DbPool::__construct()`, so pool-level decisions such as `pool` options and in-memory SQLite detection see parsed config.
- `ConnectionFactory::parseConfig()`, so every connection creation path has one authoritative parse point.

The parser is idempotent when `url` is absent, so parsing in both places is safe.

### Fix `db --read` / `db --write`

`DbCommand::getConnection()` currently assumes nested config is shaped as `['host' => ...]` and indexes `$connection['read']['host']` directly.

Current Laravel's `mergeConnectionConfiguration()` handles:

- empty nested config
- list-of-configs
- host arrays
- stripping nested `read` / `write` config after merge

Hypervel should adapt that helper without Laravel's `direct` / `pooled` CLI behavior.

### Do Not Add `::direct`

`::direct` should stay omitted. Hypervel's external-pooler model is better as normal named connections:

```php
'pgsql' => [
    // direct database connection
],

'pgsql-pooled' => [
    // PgBouncer / PgDog connection
    'migrations_connection' => 'pgsql',
],
```

Future ports need to see this is intentional, so implementation must record the omission in:

1. `src/database/README.md` under `Differences From Laravel`.
2. A concise source comment near suffix parsing.
3. A `REMOVED:` comment near the corresponding upstream `::direct` tests that are intentionally not ported.

### Do Not Restore `getNameWithReadWriteType()`

Do not restore Laravel's `Connection::getNameWithReadWriteType()`.

Reason:

- Hypervel currently has no consumers.
- `::write` will use the efficient `useWriteConnectionWhenReading(true)` path and will not carry a forced config marker.
- Restoring the method would produce an asymmetric identity surface: `name::read` could report a suffix from config, while `name::write` would not unless a new mutable marker was added.
- Query event and exception behavior is already carried through `latestReadWriteTypeUsed()`.

Keep `Connection::getName()` as the base connection name. This keeps transaction manager keying aligned with Laravel's base-name behavior and avoids splitting transaction callback state by suffix.

## Implementation Plan

### 1. Add a Parsed Connection Name Helper

Add a small final readonly value object in the database package, for example `Hypervel\Database\ConnectionName`.

Purpose:

- parse one string format in one place;
- reject `::direct` with a clear exception and source comment;
- expose the requested name, base name, and nullable role.

Sketch:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Database;

use InvalidArgumentException;

final readonly class ConnectionName
{
    public const READ = 'read';

    public const WRITE = 'write';

    public function __construct(
        public string $requested,
        public string $base,
        public ?string $role = null,
    ) {
    }

    public static function parse(string $name): self
    {
        foreach ([self::READ, self::WRITE] as $role) {
            $suffix = '::' . $role;

            if (str_ends_with($name, $suffix)) {
                return new self($name, substr($name, 0, -strlen($suffix)), $role);
            }
        }

        // Laravel's ::direct suffix is intentionally omitted. Hypervel uses
        // normal named connections plus migrations_connection for pooler paths.
        if (str_ends_with($name, '::direct')) {
            throw new InvalidArgumentException(
                'Database connection suffix [::direct] is not supported. Configure a direct connection and use migrations_connection instead.'
            );
        }

        return new self($name, $name);
    }

    public function isRead(): bool
    {
        return $this->role === self::READ;
    }

    public function isWrite(): bool
    {
        return $this->role === self::WRITE;
    }
}
```

`::direct` rejection is intentionally explicit. A silent "connection not configured" error would make the known Laravel difference harder to diagnose.

### 2. Add Role Config Derivation to `ConnectionFactory`

Keep read/write config merging inside `ConnectionFactory`, because it already owns `getReadConfig()`, `getWriteConfig()`, `getReadWriteConfig()`, and `mergeReadWriteConfig()`.

Make `parseConfig()` public so pooled and non-pooled setup can share the same authoritative parsing path without adding a redundant passthrough method. Keep the Laravel-aligned method name, and update callers inside `ConnectionFactory` normally.

Add read helper methods that do not expose mutable connection state:

```php
public function hasReadConfig(array $config): bool
{
    return isset($config[ConnectionName::READ]);
}

public function configForRead(array $config): array
{
    $config = $this->parseConfig($config, $config['name'] ?? null);

    return Arr::add(
        $this->getReadConfig($config),
        Connection::READ_WRITE_TYPE_CONFIG_KEY,
        ConnectionName::READ,
    );
}
```

`parseConfig()` should become public and parse URL config before adding defaults:

```php
public function parseConfig(array $config, ?string $name): array
{
    $config = (new ConfigurationUrlParser)->parseConfiguration($config);

    return Arr::add(Arr::add($config, 'prefix', ''), 'name', $name);
}
```

Use `Hypervel\Database\ConfigurationUrlParser`, not `Hypervel\Support\ConfigurationUrlParser`, inside the database package.

`configForRead()` must only be called after `hasReadConfig()` returns true. Use `isset($config['read'])` to match `ConnectionFactory::make()`: an empty array still counts as a read split, while `read => null` is treated as no split and avoids a typed `getReadWriteConfig()` failure.

### 3. Cache Forced Read/Write Type on `Connection`

Add a nullable property initialized from the parsed config once in the constructor. Do not read config on every query.

Place the constant and property beside the existing read/write routing state (`recordsModified`, `readOnWriteConnection`, and `latestPdoTypeRetrieved`) so related state stays grouped.

Sketch:

```php
public const READ_WRITE_TYPE_CONFIG_KEY = 'read_write_type';

/**
 * The configured read / write type for derived single-role connections.
 */
protected ?string $readWriteType = null;

public function __construct(PDO|Closure $pdo, string $database = '', string $tablePrefix = '', array $config = [])
{
    $this->pdo = $pdo;
    $this->database = $database;
    $this->tablePrefix = $tablePrefix;
    $this->config = $config;
    $this->readWriteType = $config[self::READ_WRITE_TYPE_CONFIG_KEY] ?? null;

    $this->useDefaultQueryGrammar();
    $this->useDefaultPostProcessor();
}

protected function latestReadWriteTypeUsed(): ?string
{
    return $this->readWriteType ?? $this->latestPdoTypeRetrieved;
}
```

This is worker-safe because it is immutable per `Connection` instance. Derived `::read` pools are separate pools. Normal connections have `null`, preserving current behavior.

Do not add a public setter.

### 4. Fix `getConnectionDetails()`

`getConnectionDetails()` currently assumes any latest `read` type means read/write split mode:

```php
$config = $this->latestReadWriteTypeUsed() === 'read'
    ? $this->readPdoConfig
    : $this->config;
```

For a derived `name::read` single connection, the real target config lives in `$this->config`, and `readPdoConfig` is empty.

Change it to only use `readPdoConfig` when a read/write split config exists:

```php
$config = $this->latestReadWriteTypeUsed() === 'read' && $this->readPdoConfig !== []
    ? $this->readPdoConfig
    : $this->config;
```

This keeps existing read/write split exception details correct and fixes derived read exception details.

### 5. Make `DbPool` Suffix and URL Aware

`DbPool` must parse URLs before it inspects config.

Sketch:

```php
public function __construct(Container $container, string $name)
{
    $connectionName = ConnectionName::parse($name);
    $configService = $container->make('config');
    $key = sprintf('database.connections.%s', $connectionName->base);

    if (! $configService->has($key)) {
        throw new InvalidArgumentException(sprintf('Database connection [%s] not configured.', $connectionName->base));
    }

    $factory = $container->make('db.factory');
    $config = $factory->parseConfig($configService->get($key), $connectionName->base);

    if ($connectionName->isRead() && $factory->hasReadConfig($config)) {
        $config = $factory->configForRead($config);
        $this->ensureNotDerivedInMemorySqlitePool($connectionName, $config);
    }

    $this->config = $config;
    $poolOptions = Arr::get($this->config, 'pool', []);

    // existing constructor flow...
}
```

Do not create a `name::write` pool. `::write` uses the base pool.

This means pooled URL parsing intentionally runs more than once when a pooled connection is first created: twice for a base connection, and three times for a derived `::read` connection because `configForRead()` also parses before deriving the read config. This is idempotent and happens at connection creation time, not per query.

Add an in-memory SQLite guard for derived read pools:

```php
protected function ensureNotDerivedInMemorySqlitePool(ConnectionName $name, array $config): void
{
    if (($config['driver'] ?? null) !== 'sqlite') {
        return;
    }

    $database = $config['database'] ?? '';

    if ($database === ':memory:' || str_contains($database, '?mode=memory') || str_contains($database, '&mode=memory')) {
        throw new InvalidArgumentException(
            "Database connection [{$name->requested}] cannot use a derived read pool for in-memory SQLite."
        );
    }
}
```

Reason: a separate read pool for in-memory SQLite would create a second empty in-memory database. File-backed SQLite read/write tests remain supported.

### 6. Make `PoolFactory` Resolve Pool Keys

Add role-aware pool keying:

- base `name` => pool key `name`
- `name::read` with non-null `read` config, including an empty array => pool key `name::read`
- `name::read` without `read` config, or with `read => null` => pool key `name`
- `name::write` => pool key `name`

Sketch:

```php
public function getPool(string $name): DbPool
{
    $poolName = $this->getPoolName($name);

    if (isset($this->pools[$poolName])) {
        return $this->pools[$poolName];
    }

    $pool = $this->container->make(DbPool::class, ['name' => $poolName]);

    return $this->pools[$poolName] = $pool;
}

public function flushPoolsForConnection(string $name): void
{
    $base = ConnectionName::parse($name)->base;

    foreach (array_keys($this->pools) as $poolName) {
        if ($poolName === $base || str_starts_with($poolName, $base . '::')) {
            $this->flushPool($poolName);
        }
    }
}
```

`getPoolName()` should read config only to determine whether `::read` passes the same `isset($config['read'])` gate as `ConnectionFactory::make()`. It should not build a connection.

This adds a config read to `PoolFactory`, but only at pool selection / creation time. The hot query path continues to use an already borrowed connection.

### 7. Make `ConnectionResolver` Role Aware

Keep the coroutine context key as the requested name so `name`, `name::read`, and `name::write` are isolated within the same coroutine.

Sketch:

```php
public function connection(UnitEnum|string|null $name = null): ConnectionInterface
{
    $connectionName = ConnectionName::parse(enum_value($name) ?: $this->getDefaultConnection());
    $contextKey = $this->getContextKey($connectionName->requested);

    if (CoroutineContext::has($contextKey)) {
        $connection = CoroutineContext::get($contextKey);

        if ($connection instanceof ConnectionInterface) {
            return $connection;
        }
    }

    $pool = $this->factory->getPool($connectionName->requested);
    $pooledConnection = $pool->get();

    try {
        $connection = $pooledConnection->getConnection();

        if ($connectionName->isWrite() && $connection instanceof Connection) {
            $connection->useWriteConnectionWhenReading();
        }

        CoroutineContext::set($contextKey, $connection);
    } finally {
        if (Coroutine::inCoroutine()) {
            Coroutine::defer(function () use ($pooledConnection, $contextKey) {
                CoroutineContext::set($contextKey, null);
                $pooledConnection->release();
            });
        }
    }

    return $connection;
}
```

This is safe because:

- `name::write` gets its own context entry.
- It may borrow a separate base-pool slot if `name` is also used in the same coroutine.
- `resetForPool()` clears `readOnWriteConnection` on release.
- No separate write pool is created.

### 8. Make `DatabaseManager` Role Aware

Direct/non-pooled resolution must match pooled resolution so Capsule/tests do not behave differently.

Change `resolveConnectionDirectly()` to cache by requested name but build using the base name/config.

Sketch:

```php
public function resolveConnectionDirectly(string $name): ConnectionInterface
{
    $connectionName = ConnectionName::parse($name);

    if (! isset($this->connections[$connectionName->requested])) {
        $connection = $this->configure(
            $this->makeConnection($connectionName)
        );

        if ($connectionName->isWrite()) {
            $connection->useWriteConnectionWhenReading();
        }

        $this->connections[$connectionName->requested] = $connection;
        $this->dispatchConnectionEstablishedEvent($connection);
    }

    return $this->connections[$connectionName->requested];
}
```

Change `makeConnection()` and `configuration()` to accept `ConnectionName|string`:

```php
protected function makeConnection(ConnectionName|string $name): Connection
{
    $connectionName = is_string($name) ? ConnectionName::parse($name) : $name;
    $config = $this->configuration($connectionName);

    return $this->factory->make($config, $connectionName->base);
}
```

`configuration()` should:

- look up `database.connections.<base>`;
- parse URL config through the database parser;
- derive read config only for `::read` when the base config passes the same `isset($config['read'])` gate as `ConnectionFactory::make()`;
- leave `::write` as base config because write forcing is handled by `useWriteConnectionWhenReading()`;
- leave unsplit `::read` / `::write` as base config.

`configuration()` should call `$this->factory->hasReadConfig()` and `$this->factory->configForRead()` for read derivation, the same helper path `DbPool` uses. This keeps pooled and non-pooled resolution on one derivation source.

While touching this class, replace existing `$this->app['events']` reads with `$this->app->make('events')` to match Hypervel's container convention.

### 9. Update Manager Cleanup Paths

`purge()`, `disconnect()`, and `reconnect()` must account for base and role variants.

Add helpers:

```php
protected function connectionNameVariants(UnitEnum|string|null $name): array
{
    $base = ConnectionName::parse(enum_value($name) ?: $this->getDefaultConnection())->base;

    return [$base, $base . '::read', $base . '::write'];
}
```

Use variants for:

- `CoroutineContext` keys
- non-pooled `$connections` cache
- resolver flush calls when the configured resolver implements `FlushableConnectionResolver`
- pool flushing

`purge()` should forget all variants and call `PoolFactory::flushPoolsForConnection($base)`.

`disconnect()` should disconnect current-coroutine connections for all variants and non-pooled cached connections for all variants, but it should not clear context, matching current behavior.

`reconnect()` should keep the current shape but use the requested context key after `disconnect()`. For `name::write`, reconnecting the suffixed context connection should reconnect that borrowed base-pool slot and preserve the forced-write flag for the current borrow.

### 10. Keep Migration Routing on `migrations_connection`

Do not add `::direct`.

Do not route `db:show` or `db:table` through `migrations_connection`. They inspect the selected app connection. Schema and migration commands already route through `migrations_connection` where needed.

### 11. Fix `DbCommand` Merge Logic

Switch the import to the database parser:

```php
use Hypervel\Database\ConfigurationUrlParser;
use Hypervel\Support\Arr;
```

Add an adapted merge helper:

```php
protected function mergeConnectionConfiguration(array $connection, string $type): array
{
    if (empty($connection[$type])) {
        return $connection;
    }

    $merge = $connection[$type];

    if (isset($merge[0]) && is_array($merge[0])) {
        $merge = $merge[0];
    }

    if (is_array($merge['host'] ?? null)) {
        $merge['host'] = $merge['host'][0];
    }

    $connection = array_merge($connection, $merge);

    if (is_array($connection['host'] ?? null)) {
        $connection['host'] = $connection['host'][0];
    }

    return Arr::except($connection, ['read', 'write']);
}
```

Use it for `--read` and `--write`.

While touching this command, read config through the config repository resolved with `$this->hypervel->make('config')` instead of container array access.

### 12. Update Docs

Update `src/boost/docs/database.md`.

Add `::read` / `::write` to the read/write section:

```md
You may also resolve a specific side of a configured read / write connection by appending `::read` or `::write` to the connection name:

```php
$users = DB::connection('mysql::read')->select('select * from users');
$count = DB::connection('mysql::write')->table('users')->count();
```

Use these suffixes when you need to explicitly inspect a replica or force reads through the write connection. Normal application queries do not need them; Hypervel routes reads, writes, transactions, and sticky reads automatically.
```

Add pool sizing guidance to the connection pooling section:

```md
For a connection with separate read and write hosts, each base pool slot may lazily open one write PDO and one read PDO. It does not open one PDO per configured host. If `max_connections` is `10`, a worker may therefore hold up to roughly 20 server-side database connections for that configured connection once both sides have been used. Size your database server, PgBouncer, PgDog, or other pooler capacity with that in mind. Increase `max_connections` for more concurrent database work per worker, not simply because you configured more read hosts.

Explicit `::read` connections use a separate read-only pool with the same top-level `pool` settings as the base connection. Explicit `::write` connections do not create a separate pool, but a coroutine that uses both `mysql` and `mysql::write` at the same time may borrow two slots from the base pool. Most applications do not need these suffixes in normal query paths because Hypervel already routes reads, writes, transactions, and sticky reads automatically.
```

Update the external pooler note to make the Hypervel equivalent of Laravel `::direct` clear without making `::direct` sound supported:

```md
When you need a direct connection for migrations or schema operations, configure it as a normal connection and reference it with `migrations_connection`. Hypervel does not use Laravel's `::direct` connection suffix.
```

Update the database CLI section to state that `--read` and `--write` understand list-style nested read/write configs and host arrays.

Update `src/database/README.md` with a concise `Differences From Laravel` section:

```md
## Differences From Laravel

- Laravel's external database pooler support uses a `::direct` connection suffix. Hypervel instead uses normal named connections for each endpoint and `migrations_connection` for schema and migration paths. This keeps direct and pooled endpoints as normal configured connections with their own pool settings, so Hypervel does not support Laravel's `::direct` suffix.
```

### 13. Remove Stale Comments and Add Only Useful Comments

Remove or update any old comments that imply `::read` / `::write` are unsupported.

Add one concise source comment near `::direct` rejection. Do not annotate ordinary adapted code.

Do not add `Boot-only.` or similar warnings to `flushState()` docblocks.

## Testing Plan

Run targeted tests immediately after updating each touched test file.

### Unit Tests

Update or add tests in `tests/Database/DatabaseConnectionTest.php`:

- Keep/refine the already added sticky tests:
  - `testStickyReadConnectionsUseWritePdoAfterRecordsModified()`
  - `testNonStickyReadConnectionsKeepUsingReadPdoAfterRecordsModified()`
  - `testResetForPoolClearsStickyReadRoutingState()`
- Add a test proving a derived `::read` single connection's `QueryException` uses `$this->config`, not empty `readPdoConfig`.
- Keep existing read/write split exception detail tests green.

Update or add tests in `tests/Database/DatabaseConnectionFactoryTest.php`:

- pooled/non-pooled URL parsing uses `Hypervel\Database\ConfigurationUrlParser` consistently.
- read role config derivation:
  - strips nested `read` / `write`;
  - merges base config;
  - preserves base `name`;
  - adds forced read role marker only for derived read config.
  - treats `read => null` as no read split.
- the new public factory read helpers do not expose or derive write-role config because `::write` uses the base pool and `useWriteConnectionWhenReading()`.

Add tests for `ConnectionName`:

- `ConnectionName::parse('default')` returns the base name with no role.
- `ConnectionName::parse('default::read')` and `ConnectionName::parse('default::write')` split requested/base/role correctly.
- `ConnectionName::parse('default::direct')` throws `InvalidArgumentException` with the documented message.

Update or add tests in `tests/Database/PoolFactoryTest.php`:

- `getPool('default')` returns base pool.
- `getPool('default::write')` returns the base pool.
- `getPool('default::read')` returns a separate pool when the base config has non-null `read` config.
- `getPool('default::read')` returns the base pool when the base config does not have non-null `read` config.
- `flushPoolsForConnection('default')` flushes base and derived read pools.

Update or add tests in `tests/Database/DatabaseManagerTest.php`:

- non-pooled `connection('default::read')` works.
- non-pooled `connection('default::write')` works and uses write routing for reads.
- non-pooled `connection('default::direct')` throws the documented `InvalidArgumentException`.
- unsplit `connection('default::read')` and `connection('default::write')` do not throw and do not create role-specific pools.
- `purge('default')`, `disconnect('default')`, and `reconnect('default')` handle base and role variants.
- touched `DatabaseManager` event dispatch code uses `$this->app->make('events')` instead of container array access.

Update or add tests for `DbCommand`:

- `--read` handles list-of-configs.
- `--write` handles list-of-configs.
- host arrays are reduced to the first host for CLI commands.
- empty nested read/write config returns base config.
- merged CLI config strips `read` / `write`.
- command config reads use the container's config repository instead of container array access.

### Integration Tests

Update `tests/Integration/Database/DatabaseConnectionsTest.php`:

- port/adapt Laravel's `readWriteExpectations` coverage for:
  - base split connection: write/read/write/read
  - `::read`: read/read/read/read
  - `::write`: write/write/write/write
- query logs include the same expected `readWriteType` values.
- query exceptions carry expected `readWriteType` for `::read` and `::write`.
- `::read` and `::write` on unsplit connection do not throw and behave as base-connection compatibility aliases.
- unsplit/base connections never carry the forced-read config marker; their read/write type continues to come only from the latest PDO used.
- `DB::connection('default::direct')` throws the documented `InvalidArgumentException`.

Add coroutine isolation tests in `tests/Integration/Database/ConnectionCoroutineSafetyTest.php`:

- one coroutine using `default::write` does not force another coroutine's `default` reads onto the write connection.
- within the same coroutine, `default` and `default::write` use separate context entries, so forcing write reads on the suffixed connection does not affect the plain connection.
- `default::read` and `default` are separately cached in coroutine context when a read split exists.

Add pool/integration tests in `tests/Integration/Database/PooledConnectionTest.php` or a focused new integration file if clearer:

- pooled config using only `url` creates the correct driver/database config.
- pooled `pgsql-pooled`-style URL config is parsed before pool setup.
- derived read pool for in-memory SQLite throws the explicit exception.
- file-backed SQLite read/write split still works with `::read`.

Add transaction manager coverage in an integration test:

- `getName()` remains the base name for suffixed connections.
- after-commit callbacks registered while using `default` still execute correctly if the coroutine also touches `default::read`.
- rollback callbacks do not leak or split by suffixed name.

### Documentation Review Tests

No docs-specific automated test is needed, but after editing docs:

- read the full edited `src/boost/docs/database.md` section around read/write and pooling;
- verify wording focuses on functionality (`per connection`, `read`, `write`, `pool slots`) rather than internal implementation details;
- verify it does not claim each host gets its own PDO;
- verify it explains `max_connections` sizing without telling users to increase pool size just because more hosts exist.

### Command Run Order

After implementation:

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConnectionFactoryTest.php
./vendor/bin/phpunit --no-progress tests/Database/PoolFactoryTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseManagerTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/DatabaseConnectionsTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/ConnectionCoroutineSafetyTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/PooledConnectionTest.php
composer fix
```

`composer fix` runs cs-fixer, phpstan, and the full parallel test suite.

## Self-Review Checklist

Before requesting code review after implementation:

- Trace `DB::connection('name')` through `DatabaseManager`, `ConnectionResolver`, `PoolFactory`, `DbPool`, `PooledConnection`, and `ConnectionFactory`.
- Trace `DB::connection('name::read')` through the same path and confirm it uses a separate read pool only when the base config passes the same `isset($config['read'])` gate as `ConnectionFactory::make()`.
- Trace `DB::connection('name::write')` and confirm it uses the base pool, suffixed context key, and `useWriteConnectionWhenReading(true)`.
- Confirm `resetForPool()` clears every mutable per-borrow state added or touched by this work.
- Confirm normal unsuffixed query execution has no per-query overhead beyond the existing `latestReadWriteTypeUsed()` property read.
- Confirm URL parsing is not skipped on pooled runtime connections.
- Confirm `DbCommand` still works for sqlite and connections without host.
- Confirm `migrations_connection` behavior is unchanged.
- Confirm `db:show` and `db:table` behavior is unchanged.
- Search for `::read`, `::write`, `::direct`, `getNameWithReadWriteType`, and stale comments to make sure no old unsupported-state text remains.
- Re-read `src/boost/docs/database.md` and `src/database/README.md` after edits.
