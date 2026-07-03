# Swoole Store Row Concurrency and Locks

## Goal

Make the Swoole cache store correct under cross-worker access while preserving its intended role as the very fast in-process shared-memory cache driver.

The final code should read as if the store was designed around Swoole Table's actual guarantees from the beginning:

- Ordinary cache reads stay lock-free on the hot path.
- Single-key read-modify-write operations are protected by shared, pre-fork striped atomics.
- `add()` follows the cache contract: exactly one live writer can win for a key, and expired rows are logically absent.
- `increment()`, `decrement()`, `touch()`, `forget()`, stale cleanup, eviction deletes, and cache locks do not clobber concurrent writes.
- Swoole cache stores support the framework lock API through a real `LockProvider`.
- Every Swoole Table row key is a short deterministic table key, so Swoole's key-size limit cannot truncate or collide long user keys.
- Physical table keys use seeded `xxh128`, with the seed created in pre-fork shared state, to keep hashing fast while preventing offline collision crafting against untrusted logical keys.
- Control rows for locks and interval metadata are clearly separated from user cache entries.
- Production timestamp reads avoid Carbon object construction while preserving `Carbon::setTestNow()` behavior for tests.
- `SwooleTable::set()` keeps its string-size guard without per-write Collection or closure allocation.
- No method calls a public locking method while it already holds a row lock.

## Research

Files checked:

- `src/cache/src/SwooleStore.php`
- `src/cache/src/SwooleTableManager.php`
- `src/cache/src/SwooleTable.php`
- `src/cache/src/CacheManager.php`
- `src/cache/src/CacheServiceProvider.php`
- `src/cache/src/Listeners/CreateSwooleTable.php`
- `src/cache/src/Listeners/CreateTimer.php`
- `src/cache/src/Lock.php`
- `src/cache/src/CacheLock.php`
- `src/cache/src/ArrayLock.php`
- `src/cache/src/RedisLock.php`
- `src/cache/src/FileStore.php`
- `src/cache/src/DatabaseStore.php`
- `src/cache/src/Repository.php`
- `src/contracts/src/Cache/LockProvider.php`
- `src/contracts/src/Cache/CanFlushLocks.php`
- `src/reverb/src/Servers/Hypervel/Scaling/SwooleTableSharedState.php`
- `src/foundation/config/cache.php`
- `src/boost/docs/cache.md`
- `tests/Cache/CacheSwooleStoreTest.php`
- `tests/Cache/CacheArrayStoreCoroutineIsolationTest.php`
- `tests/Cache/CacheWorkerArrayStoreTest.php`
- `tests/ServerProcess/AbstractProcessTest.php`

Relevant current behavior:

```php
public function add(string $key, mixed $value, int $seconds): bool
{
    if ($this->table->exists($key)) {
        return false;
    }

    return $this->put($key, $value, $seconds);
}
```

That is a physical-row check followed by a write. It is not atomic, and it treats expired rows as still present until some later lazy cleanup removes them.

Current `getRecord()` also reads a row, mutates `last_used_at` and `used_count` in PHP, and writes the whole row back:

```php
$record = $this->table->get($key);

if (! $record) {
    return false;
}

$record['last_used_at'] = $this->getCurrentTimestamp();
$record['used_count'] = ($record['used_count'] ?? 0) + 1;

$this->table->set($key, $record);
```

That full-row write can clobber a concurrent `put()`, `touch()`, or `increment()` because the stale `value` and `expiration` read by `getRecord()` are written back along with the metadata.

Reverb already has the right primitive for Swoole Table row lifecycle races: shared `Swoole\Atomic` striped locks created before fork and acquired with `cmpset(0, 1)`. Its critical sections only contain Swoole Table operations, so the spin lock stays cheap.

Swoole 6.2.0 does not expose a PHP constant for the table-key length limit in this environment. An empirical check shows keys at and beyond 64 bytes emit warnings but `set()` still returns `true`; stored row keys are truncated to 63 bytes, so distinct long keys can collide. The final store must never pass raw user keys to Swoole Table.

PHP's `hash('xxh128', $key, false, ['seed' => $seed])` works in this environment, so the store can seed xxh128 without adding a cryptographic hash to the hot path.

## Defects

### `add()` is not atomic

Two workers can both observe that a key does not exist and then both write the key. Both callers can return `true`, which violates the cache `add()` contract used by dedupe, replay protection, and lock-like workflows.

### `add()` ignores logical expiration

`Swoole\Table::exists($key)` checks physical row presence. SwooleStore expires rows lazily, so a key can be logically expired while the physical row is still present. In that state, `get($key)` returns `null`, but `add($key, ...)` returns `false`.

### `getRecord()` can clobber concurrent writes

The store currently writes a whole stale row back after every hit. That makes `get()` a hidden writer and creates avoidable data loss windows.

### `increment()`, `decrement()`, and `touch()` are read-modify-write races

Each method reads the row, calculates a new value or expiration in PHP, then writes the result. Two workers can lose updates or overwrite each other's changes.

### `forget()`, stale cleanup, eviction, and `flush()` can race with writers

Deletes are separately atomic at the Swoole Table operation level, but not coordinated with the store's multi-step logical operations. A stale cleanup or eviction delete can remove a key that another worker just recreated unless the delete rechecks under the key's stripe lock.

### SwooleStore does not support framework locks

`RedisStore`, `DatabaseStore`, `FileStore`, and `AbstractArrayStore` implement `LockProvider`. SwooleStore does not. This leaves applications without `Cache::store('swoole')->lock(...)` even though the store is shared across workers.

### Raw table keys can be truncated by Swoole

Current SwooleStore passes user cache keys directly into `Swoole\Table`. Distinct long keys can be truncated to the same physical row key. This is a correctness bug independent of the locking work, and this plan rewrites table access enough that the final design should fix it now.

## Decisions

### Use a shared state object, not a raw table constructor

Introduce a cache-specific shared state object owned by `SwooleTableManager`. It contains the `SwooleTable` and pre-fork `Swoole\Atomic` stripes.

Why:

- The table and atomics must be created before the Swoole server forks workers.
- Passing a raw `Table` to `SwooleStore` hides the fact that row lifecycle operations require shared locks.
- Reverb already proves the pattern inside this repository.

Shape:

```php
namespace Hypervel\Cache;

use Swoole\Atomic;

class SwooleTableState
{
    protected const STRIPE_COUNT = 64;

    /** @var list<Atomic> */
    protected array $rowLocks;

    public function __construct(
        protected SwooleTable $table,
        protected int $hashSeed = 0,
    ) {
        $this->hashSeed = $hashSeed ?: random_int(1, PHP_INT_MAX);

        $this->rowLocks = array_map(
            fn () => new Atomic(0),
            range(0, self::STRIPE_COUNT - 1),
        );
    }

    public function table(): SwooleTable
    {
        return $this->table;
    }

    public function hashSeed(): int
    {
        return $this->hashSeed;
    }

    /**
     * Run the callback while holding the row lock for the given table key.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withRowLock(string $key, callable $callback): mixed
    {
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            return $callback();
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Run the callback while holding every row-lock stripe.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withAllRowLocks(callable $callback): mixed
    {
        $acquired = [];

        foreach ($this->rowLocks as $lock) {
            $this->acquire($lock);
            $acquired[] = $lock;
        }

        try {
            return $callback();
        } finally {
            while ($lock = array_pop($acquired)) {
                $this->release($lock);
            }
        }
    }

    protected function lockFor(string $key): Atomic
    {
        return $this->rowLocks[crc32($key) % self::STRIPE_COUNT];
    }

    protected function acquire(Atomic $lock): void
    {
        while (! $lock->cmpset(0, 1)) {
            // Intentionally empty: critical sections must stay short and non-yielding.
        }
    }

    protected function release(Atomic $lock): void
    {
        $lock->cmpset(1, 0);
    }
}
```

`STRIPE_COUNT = 64` matches the existing Reverb precedent. It keeps unrelated key contention low without adding configuration or meaningful memory cost. If implementation benchmarks show unrelated write contention under unusually high worker counts, the constant can be increased before merge; it should not be a public config option unless there is a demonstrated need.

### SwooleTableManager manages states

`SwooleTableManager` should cache named `SwooleTableState` instances instead of raw tables.

```php
class SwooleTableManager
{
    /** @var array<string, SwooleTableState> */
    protected array $states = [];

    public function get(string $name): SwooleTableState
    {
        return $this->states[$name] ??= $this->resolve($name);
    }

    public function createState(int $rows, int $bytes, float $conflictProportion, int $hashSeed = 0): SwooleTableState
    {
        return new SwooleTableState(
            $this->createTable($rows, $bytes, $conflictProportion),
            $hashSeed,
        );
    }

    public function createTable(int $rows, int $bytes, float $conflictProportion): SwooleTable
    {
        // Existing column-definition body stays here.
    }

    protected function resolve(string $name): SwooleTableState
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Swoole table [{$name}] is not defined.");
        }

        return $this->createState(
            $config['rows'] ?? 1024,
            $config['bytes'] ?? 10240,
            $config['conflict_proportion'] ?? 0.2,
        );
    }
}
```

The optional `createState(..., $hashSeed)` parameter exists for deterministic unit tests only. `resolve()` should not read a seed from application config; production should use the generated pre-fork seed.

`CreateSwooleTable` keeps calling the manager before fork. `CacheManager::createSwooleDriver()` retrieves the state and passes it to `SwooleStore`.

```php
$state = $this->app->make(SwooleTableManager::class)->get($config['table']);

$store = new SwooleStore(
    $state,
    $config['memory_limit_buffer'] ?? 0.05,
    $config['eviction_policy'] ?? SwooleStore::EVICTION_POLICY_LRU,
    $config['eviction_proportion'] ?? 0.05,
);
```

### Map every logical key to a bounded seeded table key

Replace raw Swoole Table keys with short deterministic table keys. User cache entries and control rows all use separate physical prefixes plus a 128-bit hash or a small shard number.

```php
protected const USER_PREFIX = 'u:';
protected const INTERVAL_PREFIX = 'i:';
protected const INTERVAL_INDEX_PREFIX = 'x:';
protected const LOCK_PREFIX = 'l:';

protected function userKey(string $key): string
{
    return $this->hashedTableKey(self::USER_PREFIX, $key);
}

protected function intervalKey(string $key): string
{
    return $this->hashedTableKey(self::INTERVAL_PREFIX, $key);
}

protected function lockKey(string $name): string
{
    return $this->hashedTableKey(self::LOCK_PREFIX, $name);
}

protected function hashedTableKey(string $prefix, string $key): string
{
    return $prefix . hash('xxh128', $key, false, [
        'seed' => $this->state->hashSeed(),
    ]);
}

protected function isUserKey(string $tableKey): bool
{
    return str_starts_with($tableKey, self::USER_PREFIX);
}

protected function isControlKey(string $tableKey): bool
{
    return ! $this->isUserKey($tableKey);
}

protected function isLockKey(string $key): bool
{
    return str_starts_with($key, self::LOCK_PREFIX);
}
```

Why:

- Swoole Table truncates long physical keys, so raw user keys are not safe.
- `flush()` currently skips every user key beginning with `interval-`; that is accidental control-key leakage.
- Lock rows must survive normal `flush()` just like other stores with separate lock stores.
- Hashed user and control keys keep every physical key comfortably below Swoole's observed 63-byte storage width.
- Seeded xxh128 keeps per-operation hashing fast while preventing an attacker who controls logical cache keys from precomputing chosen collisions offline. The seed lives on `SwooleTableState`, is generated before fork, and is inherited consistently by workers that share the table state.

Hash alternatives considered:

- Unseeded `xxh128`: fastest, but non-cryptographic and deterministic across applications. Reject it because Swoole cache keys can be security-relevant.
- Truncated `sha256`: collision-resistant, but slower on every cache operation. Keep it as the fallback if seeded xxh128 becomes unavailable in supported PHP versions.
- Seeded `xxh128`: chosen design. It avoids Swoole truncation, keeps the hot path fast, and blocks practical offline collision crafting because the seed is not exposed to clients.

Decision: use seeded `xxh128` as the physical-key hash. It gives the smallest performance hit while addressing adversarial key-collision concerns much better than unseeded `xxh128`.

This plan must include the current interval metadata key migration even though the shared interval index and refresh redesign are in the interval-cache plan. Otherwise plan 1 alone would leave `interval()` writing `interval-foo` while `flush()` only preserves new control keys. Plan 1 therefore changes the existing local-interval methods to use `intervalKey($key)` and the local interval set; plan 2 builds the shared index and manager refresh flow on top.

### Keep `get()` lock-free for live hits

Do not lock normal live reads. Reads are the dominant Swoole cache path and should remain as close to raw table speed as possible.

The read path should:

1. Read the row.
2. If the row is live, return the unserialized value.
3. Record hit metadata only when the configured eviction policy needs it.
4. If the row is expired, acquire the row lock, recheck, and delete only if it is still expired.

```php
public function get(string $key): mixed
{
    $tableKey = $this->userKey($key);
    $record = $this->rawGet($tableKey);

    if (! $this->recordIsFalseOrExpired($record)) {
        $this->recordHit($tableKey);

        return unserialize($record['value']);
    }

    if ($this->hasLocalInterval($key) && ! is_null($interval = $this->getInterval($key))) {
        return $interval['resolver']();
    }

    if ($record !== false) {
        $this->forgetExpiredRecord($tableKey);
    }

    return null;
}

protected function recordHit(string $tableKey): void
{
    if ($this->evictionPolicy === static::EVICTION_POLICY_LRU) {
        $this->table->set($tableKey, ['last_used_at' => $this->getCurrentTimestamp()]);

        return;
    }

    if ($this->evictionPolicy === static::EVICTION_POLICY_LFU) {
        $this->table->incr($tableKey, 'used_count', 1);
    }
}
```

Why:

- `ttl` and `noeviction` do not use hit metadata, so those reads become cheaper.
- `lru` only needs `last_used_at`; a partial set must not rewrite `value` or `expiration`.
- `lfu` should use Swoole Table's numeric `incr()` for the counter.
- LRU/LFU metadata is intentionally approximate under races; cache eviction metadata does not need to be as strong as cache value correctness.
- If a metadata write races with a delete, Swoole may leave a shell row with default `value` / `expiration` columns. That shell row is expired and self-cleaning on the next read or stale cleanup. It is acceptable because the alternative is locking every read, which is the performance cost this design avoids.
- Local interval fallback is checked before deleting an expired public value, preserving today's same-instance interval behavior. The interval-cache plan will replace direct fallback resolution with the shared claim/write path.

### Use `microtime()` for production timestamps

`getCurrentTimestamp()` is on the hot path for reads, writes, expiration checks, locks, and interval claims. Building a Carbon object for every production timestamp adds more work than the store needs.

Keep Carbon only when tests have frozen time:

```php
protected function getCurrentTimestamp(): float
{
    return Carbon::hasTestNow()
        ? Carbon::now()->getPreciseTimestamp(6) / 1000000
        : microtime(true);
}
```

Why:

- `microtime(true)` returns the same float seconds shape the store already stores in Swoole Table rows.
- Production cache operations avoid constructing a Carbon object on every timestamp read.
- `Carbon::setTestNow()` continues to control expiration, LRU metadata, lock lifetime, and interval timing tests.

### Keep the Swoole table write guard allocation-free

`SwooleTable::set()` must keep checking string column sizes before writing, but it should not allocate a Collection and closure for every table write.

Use a direct loop:

```php
public function set(string $key, array $values): bool
{
    foreach ($values as $column => $value) {
        if (! isset($this->columns[$column])) {
            continue;
        }

        [$type, $size] = $this->columns[$column];

        if ($type !== Table::TYPE_STRING) {
            continue;
        }

        $length = strlen($value);

        if ($length > $size) {
            throw new ValueTooLargeForColumnException(sprintf(
                'Value [%s...] is too large for [%s] column. Should be less than %d characters but got %d characters.',
                substr($value, 0, 20),
                $column,
                $size,
                $length
            ));
        }
    }

    return parent::set($key, $values);
}
```

Why:

- Every cache write goes through this guard.
- The guard behavior and exception stay the same.
- Removing the Collection and closure cuts avoidable allocation from `put()`, `add()`, `increment()`, `touch()`, lock writes, interval writes, and LRU metadata writes.

### Public mutating methods lock; raw helpers do not

Public methods that change a single key acquire that key's row lock. Internal helpers assume the caller has already chosen the right lock boundary.

Core helper shape:

```php
protected function rawGet(string $key): array|false
{
    return $this->table->get($key);
}

protected function rawPutSerialized(string $key, string $serialized, float $expiration): bool
{
    return $this->table->set($key, [
        'value' => $serialized,
        'expiration' => $expiration,
    ]);
}

protected function rawForget(string $key): bool
{
    return $this->table->del($key);
}

protected function expiration(int $seconds): float
{
    return $this->getCurrentTimestamp() + $seconds;
}
```

`put()`:

```php
public function put(string $key, mixed $value, int $seconds): bool
{
    $tableKey = $this->userKey($key);
    $serialized = serialize($value);
    $expiration = $this->expiration($seconds);

    $result = $this->state->withRowLock(
        $tableKey,
        fn () => $this->rawPutSerialized($tableKey, $serialized, $expiration),
    );

    $this->evictRecordsIfNeeded();

    return $result;
}
```

`add()`:

```php
public function add(string $key, mixed $value, int $seconds): bool
{
    $tableKey = $this->userKey($key);
    $serialized = serialize($value);
    $expiration = $this->expiration($seconds);

    return $this->state->withRowLock($tableKey, function () use ($tableKey, $serialized, $expiration) {
        $record = $this->rawGet($tableKey);

        if (! $this->recordIsFalseOrExpired($record)) {
            return false;
        }

        return $this->rawPutSerialized($tableKey, $serialized, $expiration);
    });
}
```

`increment()` and `decrement()`:

```php
public function increment(string $key, int $value = 1): int
{
    $tableKey = $this->userKey($key);

    return $this->state->withRowLock($tableKey, function () use ($tableKey, $value) {
        $record = $this->rawGet($tableKey);

        if ($this->recordIsFalseOrExpired($record)) {
            $this->rawPutSerialized($tableKey, serialize($value), $this->expiration(static::ONE_YEAR));

            return $value;
        }

        $incremented = (int) (unserialize($record['value']) + $value);

        $this->rawPutSerialized($tableKey, serialize($incremented), $record['expiration']);

        return $incremented;
    });
}

public function decrement(string $key, int $value = 1): int
{
    return $this->increment($key, $value * -1);
}
```

`touch()`:

```php
public function touch(string $key, int $seconds): bool
{
    $tableKey = $this->userKey($key);

    return $this->state->withRowLock($tableKey, function () use ($tableKey, $seconds) {
        $record = $this->rawGet($tableKey);

        if ($this->recordIsFalseOrExpired($record)) {
            if ($record !== false) {
                $this->rawForget($tableKey);
            }

            return false;
        }

        return $this->table->set($tableKey, [
            'expiration' => $this->expiration($seconds),
        ]);
    });
}
```

`forget()`:

```php
public function forget(string $key): bool
{
    $tableKey = $this->userKey($key);

    return $this->state->withRowLock(
        $tableKey,
        fn () => $this->rawForget($tableKey),
    );
}
```

`forever()` stays a thin delegate to public `put()`:

```php
public function forever(string $key, mixed $value): bool
{
    return $this->put($key, $value, static::ONE_YEAR);
}
```

Do not add a separate lock in `forever()`. `put()` already locks the user table key.

### Migrate the existing local interval rows in this plan

The shared interval index and manager refresh redesign stay in the interval-cache plan. The basic interval metadata key migration cannot wait, because `flush()` will switch to preserving control rows by physical key prefix in this plan.

Plan 1 should update today's local interval implementation to use `intervalKey($key)` and a local set:

```php
/** @var array<string, true> */
protected array $intervals = [];

protected function hasLocalInterval(string $key): bool
{
    return isset($this->intervals[$key]);
}

protected function getInterval(string $key): ?array
{
    $record = $this->rawGet($this->intervalKey($key));

    return $this->recordIsFalseOrExpired($record)
        ? null
        : unserialize($record['value']);
}

public function interval(string $key, Closure $resolver, int $seconds): void
{
    $intervalKey = $this->intervalKey($key);

    $this->state->withRowLock($intervalKey, function () use ($intervalKey, $resolver, $seconds) {
        if (! $this->recordIsFalseOrExpired($this->rawGet($intervalKey))) {
            return;
        }

        $this->rawPutSerialized($intervalKey, serialize([
            'resolver' => new SerializableClosure($resolver),
            'lastRefreshedAt' => null,
            'refreshInterval' => $seconds,
        ]), $this->expiration(static::ONE_YEAR));
    });

    $this->intervals[$key] = true;
}

public function refreshIntervalCaches(): void
{
    foreach (array_keys($this->intervals) as $key) {
        $interval = $this->getInterval($key);

        if ($interval === null || ! $this->intervalShouldBeRefreshed($interval)) {
            continue;
        }

        $intervalKey = $this->intervalKey($key);

        $this->state->withRowLock($intervalKey, function () use ($intervalKey, $interval) {
            $this->rawPutSerialized($intervalKey, serialize(array_merge(
                $interval,
                ['lastRefreshedAt' => Carbon::now()->getTimestamp()],
            )), $this->expiration(static::ONE_YEAR));
        });

        $this->forever($key, $interval['resolver']());
    }
}
```

This keeps existing same-instance interval behavior and `testIntervalsAreNotFlushed` / `testIntervalsCanBeRefreshed` passing while avoiding the old `interval-` user-key collision. Plan 2 will replace this metadata shape and local-only refresh loop with the richer shared-index metadata shape.

### Do not run eviction while holding a row lock or on every write

`put()` should release the row lock before considering eviction. Eviction scans arbitrary rows and deletes candidates. It must not be nested inside a per-key lock.

Writes should not run a full-table stale scan unconditionally. The current store calls `evictRecords()` after every `put()`, which means every write scans the whole table through `flushStaleRecords()`. After candidate deletes become locked, inheriting that behavior would make write cost scale with table size and expired-row count.

Use a cheap memory-pressure gate on the write path:

```php
protected function evictRecordsIfNeeded(): void
{
    if (! $this->memoryLimitIsReached()) {
        return;
    }

    $this->evictRecords();
}
```

Keep `evictRecords()` as the full maintenance operation used by the timer and by the pressure gate:

```php
public function evictRecords(): void
{
    $this->flushStaleRecords();

    while ($this->memoryLimitIsReached()) {
        $this->removeRecordsByEvictionPolicy();
    }
}
```

Why:

- The row spinlock is non-reentrant.
- Eviction may need to acquire the lock for the same stripe or a different stripe.
- Holding a row lock while attempting to acquire all locks or another row lock creates deadlock risk.
- The common write path should stay O(1) unless the table is under memory pressure.
- Expired rows that are not on a read/write path can be removed by the existing maintenance timer.

### Delete stale and evicted records under candidate row locks

`flushStaleRecords()` and eviction candidate removal should scan without locks, collect keys, then lock and recheck each candidate before deleting.

```php
protected function flushStaleRecords(): void
{
    $now = $this->getCurrentTimestamp();
    $tableKeys = [];
    $lockKeys = [];

    foreach ($this->table as $tableKey => $row) {
        if ($this->isLockKey($tableKey)) {
            if ($this->rawLockPayloadIsExpired($row)) {
                $lockKeys[] = $tableKey;
            }

            continue;
        }

        if ($this->isControlKey($tableKey)) {
            continue;
        }

        if ($row['expiration'] < $now) {
            $tableKeys[] = $tableKey;
        }
    }

    foreach ($tableKeys as $tableKey) {
        $this->forgetExpiredRecord($tableKey);
    }

    foreach ($lockKeys as $tableKey) {
        $this->forgetExpiredLockRecord($tableKey);
    }
}

protected function forgetExpiredRecord(string $tableKey): void
{
    $this->state->withRowLock($tableKey, function () use ($tableKey) {
        $record = $this->rawGet($tableKey);

        if ($this->recordIsFalseOrExpired($record)) {
            $this->rawForget($tableKey);
        }
    });
}
```

Eviction-by-policy should also call a raw candidate delete that rechecks the selected row under its row lock:

```php
protected function forgetEvictionCandidate(string $tableKey): void
{
    $this->state->withRowLock($tableKey, function () use ($tableKey) {
        if (! $this->isControlKey($tableKey)) {
            $this->rawForget($tableKey);
        }
    });
}
```

Why:

- Candidate selection is advisory because eviction metadata is approximate.
- The delete itself must not race a concurrent recreate on the same key.
- Internal rows for locks and interval metadata must not be evicted by normal cache pressure.
- Stale cleanup may prune expired lock rows, but only after decoding the lock payload and rechecking under that lock key's row lock. Live locks must never be removed by stale cleanup.
- Stale cleanup no longer runs on every successful write. It runs through `evictRecords()` from the timer and from the write path only when the memory-pressure gate is tripped.

### `flush()` is an all-stripes operation

Normal cache flush should delete user rows only. It should preserve internal lock and interval rows.

```php
public function flush(): bool
{
    return $this->state->withAllRowLocks(function () {
        foreach ($this->table as $tableKey => $record) {
            if ($this->isControlKey($tableKey)) {
                continue;
            }

            $this->rawForget($tableKey);
        }

        return true;
    });
}
```

`flush()` does not lock readers. A concurrent lock-free `get()` can read a row just before it is flushed. That is acceptable and matches cache-store expectations: `flush()` is a writer barrier, not a global read memory barrier.

### Implement `LockProvider` with a Swoole-specific lock

`SwooleStore` should implement `LockProvider` and `CanFlushLocks`.

```php
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;

class SwooleStore implements CanFlushLocks, LockProvider, Store
{
    public function lock(string $name, int $seconds = 0, ?string $owner = null): SwooleLock
    {
        return new SwooleLock($this, $name, $seconds, $owner);
    }

    public function restoreLock(string $name, string $owner): SwooleLock
    {
        return $this->lock($name, 0, $owner);
    }

    public function hasSeparateLockStore(): bool
    {
        return true;
    }
}
```

Do not use `CacheLock` for Swoole. `CacheLock::release()` does a separate owner read and delete; Swoole needs release to be protected by the same row lock as acquire.

Store-facing lock helpers:

```php
public function acquireLock(string $name, string $owner, int $seconds): bool
{
    $key = $this->lockKey($name);
    $expiresAt = $seconds > 0 ? $this->expiration($seconds) : null;

    return $this->state->withRowLock($key, function () use ($key, $owner, $expiresAt) {
        $lock = $this->rawLockRecord($key);

        if ($lock !== null && ! $this->lockIsExpired($lock)) {
            return false;
        }

        return $this->rawPutSerialized($key, serialize([
            'owner' => $owner,
            'expiresAt' => $expiresAt,
        ]), $this->expiration(static::ONE_YEAR));
    });
}

public function releaseLock(string $name, string $owner): bool
{
    $key = $this->lockKey($name);

    return $this->state->withRowLock($key, function () use ($key, $owner) {
        $lock = $this->rawLockRecord($key);

        if ($lock === null || $this->lockIsExpired($lock)) {
            if ($lock !== null) {
                $this->rawForget($key);
            }

            return false;
        }

        if ($lock['owner'] !== $owner) {
            return false;
        }

        return $this->rawForget($key);
    });
}

public function getLockOwner(string $name): ?string
{
    $lock = $this->rawLockRecord($this->lockKey($name));

    return $lock !== null && ! $this->lockIsExpired($lock)
        ? $lock['owner']
        : null;
}

public function refreshLock(string $name, string $owner, int $seconds): bool
{
    $key = $this->lockKey($name);

    return $this->state->withRowLock($key, function () use ($key, $owner, $seconds) {
        $lock = $this->rawLockRecord($key);

        if ($lock === null || $this->lockIsExpired($lock) || $lock['owner'] !== $owner) {
            return false;
        }

        $lock['expiresAt'] = $this->expiration($seconds);

        return $this->rawPutSerialized($key, serialize($lock), $this->expiration(static::ONE_YEAR));
    });
}

public function getLockRemainingLifetime(string $name): ?float
{
    $lock = $this->rawLockRecord($this->lockKey($name));

    if ($lock === null || $lock['expiresAt'] === null || $this->lockIsExpired($lock)) {
        return null;
    }

    return max(0.0, $lock['expiresAt'] - $this->getCurrentTimestamp());
}

public function forceReleaseLock(string $name): void
{
    $key = $this->lockKey($name);

    $this->state->withRowLock($key, fn () => $this->rawForget($key));
}

protected function rawLockRecord(string $key): ?array
{
    $record = $this->rawGet($key);

    return $record === false ? null : unserialize($record['value']);
}

protected function lockIsExpired(array $lock): bool
{
    return $lock['expiresAt'] !== null && $lock['expiresAt'] <= $this->getCurrentTimestamp();
}

protected function rawLockPayloadIsExpired(array $row): bool
{
    $lock = unserialize($row['value']);

    return $this->lockIsExpired($lock);
}

protected function forgetExpiredLockRecord(string $key): void
{
    $this->state->withRowLock($key, function () use ($key) {
        $lock = $this->rawLockRecord($key);

        if ($lock !== null && $this->lockIsExpired($lock)) {
            $this->rawForget($key);
        }
    });
}
```

`SwooleLock`:

```php
namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\RefreshableLock;
use InvalidArgumentException;

class SwooleLock extends Lock implements RefreshableLock
{
    public function __construct(
        protected SwooleStore $store,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        return $this->store->acquireLock($this->name, $this->owner, $this->seconds);
    }

    public function release(): bool
    {
        return $this->store->releaseLock($this->name, $this->owner);
    }

    public function forceRelease(): void
    {
        $this->store->forceReleaseLock($this->name);
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->store->getLockOwner($this->name);
    }

    public function refresh(?int $seconds = null): bool
    {
        if ($seconds === null && $this->seconds <= 0) {
            return true;
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Refresh requires a positive TTL. For a permanent lock, acquire it with seconds=0.'
            );
        }

        return $this->store->refreshLock($this->name, $this->owner, $seconds);
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->store->getLockRemainingLifetime($this->name);
    }
}
```

Also add:

```php
public function flushLocks(): bool
{
    if (! $this->hasSeparateLockStore()) {
        throw new RuntimeException('Flushing locks is only supported when the lock store is separate from the cache store.');
    }

    return $this->state->withAllRowLocks(function () {
        foreach ($this->table as $key => $record) {
            if ($this->isLockKey($key)) {
                $this->rawForget($key);
            }
        }

        return true;
    });
}
```

Why:

- Locks should survive normal cache flush.
- `cache:clear --locks` should work for Swoole just like other lock-capable stores.
- Expired lock takeover is handled by the same logical-expiration check used by cache rows.

### Critical-section invariants

These rules must hold in the final implementation:

- Public mutating methods may acquire row locks.
- Raw helper methods never acquire row locks.
- Methods called from inside `withRowLock()` must not call public mutating methods.
- A row lock must never be held while calling `evictRecords()`, `flush()`, `flushLocks()`, or user code.
- `withAllRowLocks()` must only be entered when no row lock is already held.
- Eviction and stale cleanup may lock one candidate key at a time.

These invariants are more important than minimizing line churn. If a helper's name does not make its lock expectation obvious, rename it.

## Implementation Steps

1. Add `src/cache/src/SwooleTableState.php`.

   Why: centralizes the shared table and shared atomics.

   How: copy the Reverb spin-lock shape, expose `table()`, `withRowLock()`, and `withAllRowLocks()`. Include `declare(strict_types=1)`, normal Laravel-style method docblocks, and generic PHPDoc on callback helpers.

2. Refactor `SwooleTableManager` to return `SwooleTableState`.

   Why: every resolved Swoole cache table needs its matching shared locks.

   How: replace `$tables` with `$states`, add `createState()`, narrow `createTable()`'s return type to `SwooleTable`, keep table column creation inside the manager, and update the missing-config exception unchanged.

3. Update `CacheManager::createSwooleDriver()`.

   Why: `SwooleStore` needs the state object, not just the table.

   How: retrieve `$state = $manager->get($config['table'])` and pass it to the new constructor.

4. Update `SwooleStore` constructor and properties.

   Why: the store should not own synchronization; it should use the shared state.

   How: accept `SwooleTableState $state`, set `$this->table = $state->table()`, and keep current config properties.

5. Replace `getRecord()` with raw and hit-metadata helpers.

   Why: remove the full-row clobber path.

   How: add `rawGet()`, `rawPutSerialized()`, `rawForget()`, `recordHit()`, `forgetExpiredRecord()`. Delete the old comment and method once all call sites are gone.

6. Rewrite `get()` around lock-free live reads.

   Why: fixes clobbering while keeping read performance.

   How: raw read, return live value, best-effort metadata update for LRU/LFU only, locked recheck-delete for expired rows, then local interval fallback.

7. Change `getCurrentTimestamp()` to use `microtime(true)` unless Carbon has frozen test time.

   Why: timestamp reads are on the store hot path, and production code does not need Carbon object construction.

   How: branch on `Carbon::hasTestNow()`, keep the existing precise Carbon path for tests, and use `microtime(true)` otherwise.

8. Change `SwooleTable::set()` to check string column sizes with a plain loop.

   Why: every write goes through this wrapper, so the old Collection and closure allocation added avoidable write-path cost.

   How: remove `collect($values)->each($this->ensureColumnsSize())`, inline the guard as a `foreach`, preserve the `ValueTooLargeForColumnException`, and delete the now-unused helper/import.

9. Rewrite single-key mutators around row locks.

   Why: fixes check-then-write and read-modify-write races.

   How: update `put()`, `add()`, `increment()`, `decrement()`, `touch()`, and `forget()` so public mutators lock their physical user table key and internal work uses raw helpers. Keep `forever()` as a thin delegate to public `put()`.

10. Rewrite cleanup and eviction deletes.

   Why: scans can be lock-free, deletes cannot.

   How: scans collect candidate user keys, collect expired lock keys separately, skip non-lock internal keys, then recheck/delete under each candidate row lock.

11. Migrate current local interval methods enough for plan 1 to stand alone.

   Why: `flush()` and all-key hashing change the physical key namespace in this plan, so today's interval rows cannot keep using `interval-{$key}` until plan 2.

   How: convert `$intervals` to an associative set, update `getInterval()` / `interval()` / `refreshIntervalCaches()` to use `intervalKey($key)`, iterate local intervals with `array_keys($this->intervals)`, and use logical expiration checks for interval metadata dedupe.

12. Gate write-path eviction.

   Why: adding locks to candidate deletes makes the inherited full-table scan after every write too expensive at scale.

   How: replace `put()`'s unconditional `evictRecords()` call with `evictRecordsIfNeeded()`, which checks `memoryLimitIsReached()` first. Keep `evictRecords()` as the full stale-cleanup plus eviction operation for the timer and pressure path.

13. Rewrite `flush()` as an all-stripes user-row delete.

   Why: `flush()` must not interleave with writers and must not delete internal lock/interval rows.

   How: call `withAllRowLocks()`, iterate table, delete only `isUserKey()` rows with raw deletes.

14. Add `SwooleLock` and `LockProvider` support.

    Why: Swoole is a shared cache store and should support the framework's shared lock API.

    How: add lock helpers on `SwooleStore`, implement `SwooleLock`, implement `CanFlushLocks`, add `flushLocks()`.

15. Update docs where Swoole store capabilities are listed.

    Why: docs should not lag the final codebase.

    How: update Swoole cache docs to mention `lock()` support and `cache:clear --locks` if lock support is documented for other stores.

14. Remove stale comments and old method names.

    Why: the final code should read as designed this way from the start.

    How: delete comments about "write used info" and avoid leaving `getRecord()` as a compatibility alias.

## Testing Plan

Run each touched test file immediately after updating it, then run the relevant package suite.

Commands:

```bash
vendor/bin/phpunit tests/Cache/CacheSwooleStoreTest.php
vendor/bin/phpunit tests/Cache/CacheSwooleStoreConcurrencyTest.php
vendor/bin/phpunit tests/Cache
composer analyse
```

### Unit and sequential regression tests

Add or update `tests/Cache/CacheSwooleStoreTest.php`.

Because the store no longer uses raw logical keys as Swoole Table keys, migrate every direct table seed/assertion in the existing test file. Add helpers instead of scattering reflection:

```php
use ReflectionMethod;

private function logicalTableKey(SwooleStore $store, string $key): string
{
    $method = new ReflectionMethod($store, 'userKey');
    $method->setAccessible(true);

    return $method->invoke($store, $key);
}

private function setLogicalRow(SwooleTableState $state, SwooleStore $store, string $key, mixed $value, float $expiration): void
{
    $state->table()->set($this->logicalTableKey($store, $key), [
        'value' => serialize($value),
        'expiration' => $expiration,
    ]);
}

private function getLogicalRow(SwooleTableState $state, SwooleStore $store, string $key): array|false
{
    return $state->table()->get($this->logicalTableKey($store, $key));
}
```

The important rule is that tests seed and inspect the same physical table key the store uses.

Existing tests that must be migrated away from raw `$table->set('foo', ...)` / `$table->get('foo')` include:

- `testCanRetrieveItemsFromStore`
- `testExpiredItemsReturnNull`
- `testManyMethodCanReturnManyValues`
- `testCanRemoveExpiredRecordFromTable`
- `testFlushStaleRecords`
- Any new LRU/LFU metadata assertions that inspect raw table rows

Rewrite the old `testCanRetrieveItemsFromStore` metadata assertions. The existing helper creates a TTL-policy store, and TTL reads should no longer update `last_used_at` or `used_count`. Replace the old "every get bumps metadata" assertion with explicit policy-aware tests listed below.

Required coverage:

- `add()` returns `true` and overwrites an expired physical row.
- `add()` returns `false` and preserves the existing value for a live row.
- `add()` does not update LRU/LFU hit metadata.
- `get()` under `ttl` does not update `last_used_at` or `used_count`.
- `get()` under `noeviction` does not update `last_used_at` or `used_count`.
- `get()` under `lru` updates only `last_used_at` and preserves `value`, `expiration`, and `used_count`.
- `get()` under `lfu` increments only `used_count` and preserves `value`, `expiration`, and `last_used_at`.
- Expiration behavior remains controllable with `Carbon::setTestNow()` after the production timestamp path switches to `microtime(true)`.
- `SwooleTable::set()` still throws `ValueTooLargeForColumnException` when a string column value exceeds its configured size.
- Expired `get()` deletes the row after a locked recheck.
- `touch()` preserves the cached value while changing only expiration.
- `touch()` deletes an expired physical row and returns `false`.
- `increment()` preserves the original expiration.
- `decrement()` uses the same locked path as `increment()`.
- `flush()` deletes user keys but preserves internal interval rows and lock rows.
- `flushLocks()` deletes lock rows but preserves user keys and interval rows.
- `evictRecords()` never deletes control rows.
- `put()` does not scan the full table when the memory limit is not reached.
- `put()` runs `evictRecords()` when the memory limit is reached.
- `evictRecords()` still flushes stale records when called directly by the maintenance timer path.
- `forever()`, `putMany()`, `many()`, and `forget()` still behave normally.
- Distinct long logical cache keys that share the same first 63 bytes do not collide, because both are hashed through `userKey()` before reaching Swoole Table.
- Internal lock and interval table keys are shorter than Swoole's observed 63-byte storage width.
- Seeded xxh128 changes the physical table key when the state seed changes, and two stores sharing one state compute the same physical table key.
- `SwooleStore` implements `LockProvider` and `CanFlushLocks`.
- `tests/Cache/FunnelUnsupportedStoresTest.php` no longer asserts Swoole is unsupported; keep `StackStore` and `SessionStore` as unsupported.
- Add positive Swoole funnel coverage proving `Repository::funnel()` works with Swoole now that it is a `LockProvider`.
- `lock()->acquire()` succeeds once and fails for a second owner while live.
- Expired locks can be acquired by a new owner.
- `release()` only releases for the owner.
- `forceRelease()` releases regardless of owner.
- `restoreLock()` uses the supplied owner.
- `refresh()` extends a live owned lock, returns `true` as a no-op for permanent locks when no explicit TTL is provided, and rejects non-positive explicit TTLs.
- `getRemainingLifetime()` returns seconds for expiring locks and `null` for missing, expired, or permanent locks.

Use a helper that creates a full state, not a bare table:

```php
private function createState(): SwooleTableState
{
    return (new SwooleTableManager(m::mock(Container::class)))
        ->createState(128, 10240, 0.2);
}

private function createStore(?SwooleTableState $state = null, string $policy = SwooleStore::EVICTION_POLICY_TTL): SwooleStore
{
    return new SwooleStore($state ?? $this->createState(), 0.05, $policy, 0.05);
}
```

### Process-level concurrency regression tests

Add `tests/Cache/CacheSwooleStoreConcurrencyTest.php`.

Set:

```php
protected bool $runTestsInCoroutine = false;
```

Why: existing process tests opt out because Swoole process creation is not reliable inside the coroutine test wrapper under parallel test workers.

Use `Swoole\Process` children sharing a pre-created `SwooleTableState`. Each child should start from a synchronization barrier so operations overlap.

Required coverage:

- Many processes call `add('same-key', owner, 60)` at once; exactly one process reports success and the final value is that winner.
- Many processes call `add()` for the same expired physical row; exactly one process reports success.
- Many processes call `increment('counter')` repeatedly; the final value equals the total increments.
- Many processes try `lock('same-lock', 60)->acquire()` at once; exactly one process reports success.

Sketch:

```php
public function testConcurrentAddHasExactlyOneWinner(): void
{
    $state = $this->createState();
    $ready = new Atomic(0);
    $start = new Atomic(0);

    $processes = [];

    for ($i = 0; $i < 16; ++$i) {
        $processes[] = new Process(function (Process $process) use ($state, $ready, $start, $i) {
            $store = $this->createStore($state);

            $ready->add(1);

            while ($start->get() === 0) {
                usleep(100);
            }

            $process->write(serialize([
                'id' => $i,
                'won' => $store->add('key', $i, 60),
            ]));
        }, false, SOCK_STREAM);
    }

    foreach ($processes as $process) {
        $process->start();
    }

    while ($ready->get() < count($processes)) {
        usleep(100);
    }

    $start->set(1);

    $results = [];

    foreach ($processes as $process) {
        $results[] = unserialize($process->read());
    }

    while (Process::wait(false)) {
        // Reap all children after all pipes have been read.
    }

    $this->assertCount(1, array_filter($results, fn ($result) => $result['won']));
}
```

The final implementation should factor the process orchestration into helpers so the test file stays readable. Do not call `Process::wait()` inside the same loop that reads a specific process pipe; `Process::wait()` can reap any child and make result-to-child association flaky.

## Performance Expectations

This design keeps the fastest path fast:

- Live `get()` for `ttl` and `noeviction` becomes one table `get()` plus unserialize, with no metadata write.
- Live `get()` for `lru` performs one table `get()` plus one partial metadata `set()`.
- Live `get()` for `lfu` performs one table `get()` plus one numeric `incr()`.
- `add()`, `put()`, `increment()`, `decrement()`, `touch()`, `forget()`, lock acquire/release, and candidate deletes pay one uncontended shared-memory CAS acquire/release around a tiny critical section.
- Hot-key writes serialize by design. That is the correctness cost of a single shared key without a native Swoole Table compare-and-swap or set-if-absent primitive.
- Best-effort LRU/LFU metadata writes can recreate an expired shell row if they race with a delete. Under read-only load, that shell may remain until a later timer-driven `evictRecords()` pass. That is an accepted property of keeping reads lock-free.

The design intentionally avoids:

- Locking normal reads.
- Holding a lock while running user code.
- Holding a lock while scanning the table.
- A global lock for ordinary single-key writes.
- Using `CacheLock`, because it cannot release atomically on Swoole.

## Self-Review Checklist

Before implementation starts, verify these points against the codebase one more time:

- `SwooleTableManager` is resolved before fork through `CreateSwooleTable`.
- The manager instance is shared enough for the pre-fork state to be inherited by workers.
- `CacheManager::createSwooleDriver()` has no other raw-table assumptions.
- Every old `getRecord()` call is either removed or intentionally replaced.
- No internal method calls public `put()`, `forget()`, `forever()`, `touch()`, or `increment()` while a row lock is held.
- `flush()` and `flushLocks()` do not call public `forget()`.
- Eviction skips control rows, and stale cleanup only removes lock control rows when their lock payload is expired.
- Interval-plan control keys use the same short physical key helpers.
- Tests can inspect the raw table through `SwooleTableState::table()`; raw row assertions should use table iteration, reflection on key helpers, or store behavior rather than raw logical user keys.
- All comments describe current invariants; no comments remain from the old full-row metadata update design.
