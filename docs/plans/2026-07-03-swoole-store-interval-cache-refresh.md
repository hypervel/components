# Swoole Store Interval Cache Refresh

## Goal

Make Swoole interval caches refresh correctly across workers and from the manager timer, without adding work to normal cache misses or hot reads.

The final code should read as if interval caches were always designed as shared Swoole cache state:

- `interval()` registers interval metadata in the shared table.
- A shared interval index lets the manager process discover registered intervals.
- The manager process refreshes due intervals on a dedicated short timer.
- Normal `get()` does not scan or consult the shared interval index.
- The registering store instance can still resolve a local interval before the first timer tick.
- Resolver callbacks never run while a row lock is held.
- Resolver failures are reported and do not permanently suppress future refreshes.
- Hard failures during refresh do not freeze an interval forever; stale claims are reclaimable.

This plan depends on the row-concurrency plan's shared `SwooleTableState`, seeded physical table-key mapping, raw helper methods, local interval metadata migration, and row-lock invariants.

## Research

Files checked:

- `src/cache/src/SwooleStore.php`
- `src/cache/src/SwooleTableManager.php`
- `src/cache/src/CacheManager.php`
- `src/cache/src/CacheServiceProvider.php`
- `src/cache/src/Listeners/CreateSwooleTable.php`
- `src/cache/src/Listeners/CreateTimer.php`
- `src/cache/src/Listeners/BaseListener.php`
- `src/foundation/config/cache.php`
- `src/boost/docs/cache.md`
- `src/boost/docs/octane.md`
- `src/contracts/src/Debug/ExceptionHandler.php`
- `src/foundation/src/Bootstrap/HandleExceptions.php`
- `tests/Cache/CacheSwooleStoreTest.php`

Current interval behavior:

```php
protected array $intervals = [];

public function interval(string $key, Closure $resolver, int $seconds): void
{
    if (! is_null($this->getInterval($key))) {
        $this->intervals[] = $key;

        return;
    }

    $this->forever('interval-' . $key, serialize([
        'resolver' => new SerializableClosure($resolver),
        'lastRefreshedAt' => null,
        'refreshInterval' => $seconds,
    ]));

    $this->intervals[] = $key;
}

public function refreshIntervalCaches(): void
{
    foreach ($this->intervals as $key) {
        // refresh due local intervals
    }
}
```

Current timer behavior:

```php
Timer::tick($config['eviction_interval'] ?? 10000, function () use ($name) {
    $store = Cache::store($name)->getStore();

    $store->evictRecords();
});
```

The timer runs in the manager process on `OnManagerStart`, but it only calls `evictRecords()`. It never calls `refreshIntervalCaches()`.

Even if the timer did call `refreshIntervalCaches()`, the manager process's `SwooleStore` instance has an empty local `$intervals` list. Workers register intervals after resolving their own store instances; that local PHP array is not shared with the manager process.

## Defects

### Interval registrations are not discoverable by the manager process

The metadata row is shared, but the list of keys to refresh is local process memory. The manager process cannot know which intervals exist.

### No timer refreshes interval caches

`CreateTimer` currently creates only an eviction timer. Interval caches are documented as automatically refreshed, but no timer calls `refreshIntervalCaches()`.

### Current metadata keys leak into the user key namespace

Internal rows use `interval-{$key}`. `flush()` skips every key starting with `interval-`, so a user cache key with that prefix is accidentally preserved.

The row-concurrency plan fixes the immediate key leak by moving today's local interval metadata to the seeded `i:` control-key namespace. This plan builds on that by adding shared `x:` interval index rows and manager-driven refresh.

### Interval registration can duplicate local keys

Calling `interval('foo', ...)` more than once appends `foo` to `$intervals` each time.

### Resolver exceptions can break refresh behavior

The current refresh path updates `lastRefreshedAt` before running the resolver. If the resolver throws, the timestamp remains fresh even though the value was not refreshed.

## Decisions

### Use a shared internal interval index

Add an internal index of registered interval cache keys in the Swoole table. The manager timer reads this index to know which interval metadata rows to evaluate.

Use sharded index rows rather than one large row:

```php
protected const INTERVAL_INDEX_SHARDS = 64;
protected const INTERVAL_INDEX_PREFIX = 'x:';

protected function intervalIndexKey(string $metadataKey): string
{
    return self::INTERVAL_INDEX_PREFIX . (crc32($metadataKey) % self::INTERVAL_INDEX_SHARDS);
}
```

Each shard stores an associative array of bounded interval metadata table keys:

```php
[
    'i:27a2...' => true,
    'i:91bf...' => true,
]
```

Why sharded rows:

- The timer reads at most 64 small rows instead of scanning the whole cache table.
- It avoids a single index row growing too quickly in the table's fixed-size string column.
- It stores bounded `i:` metadata table keys rather than unbounded logical cache keys, so shard size does not depend on application key length.
- It adds no work to normal `get()` or `put()`.
- It does not require a second Swoole table whose memory size would need separate tuning.

The index is for refresh discovery only. Normal `get()` misses must not read it.

### Store interval metadata once, under seeded control keys

Use the row-concurrency plan's `intervalKey($key)` helper for one metadata row per interval:

```php
protected function intervalKey(string $key): string
{
    return $this->hashedTableKey(self::INTERVAL_PREFIX, $key);
}
```

Metadata shape:

```php
[
    'key' => $key,
    'metadataKey' => $this->intervalKey($key),
    'resolver' => new SerializableClosure($resolver),
    'lastRefreshedAt' => null,
    'refreshingAt' => null,
    'refreshInterval' => $seconds,
]
```

Store it with the raw serialized row helper:

```php
$this->rawPutSerialized(
    $this->intervalKey($key),
    serialize($metadata),
    $this->expiration(static::ONE_YEAR),
);
```

Do not double-serialize metadata by passing an already serialized string through public `forever()`.

Why:

- The metadata key is bounded, seeded, and does not collide with ordinary `interval-*` user keys.
- Storing the original key inside metadata lets refresh code write the public cache value without depending on index row keys.
- `lastRefreshedAt` records the last successful refresh. `refreshingAt` is a short-lived claim that prevents overlapping refresh work.
- Keeping the metadata row live for `ONE_YEAR` matches existing SwooleStore forever semantics.

### `interval()` registers only; it does not eagerly compute the value

`interval()` should write metadata and update the shared index. It should not run the resolver during service-provider boot.

```php
public function interval(string $key, Closure $resolver, int $seconds): void
{
    $metadata = [
        'key' => $key,
        'metadataKey' => $this->intervalKey($key),
        'resolver' => new SerializableClosure($resolver),
        'lastRefreshedAt' => null,
        'refreshingAt' => null,
        'refreshInterval' => $seconds,
    ];

    $metadataKey = $this->intervalKey($key);

    $metadataWritten = $this->state->withRowLock($metadataKey, function () use ($key, $metadata) {
        return $this->putIntervalMetadata($key, $metadata);
    });

    if (! $metadataWritten) {
        throw new RuntimeException("Unable to register Swoole interval cache [{$key}].");
    }

    try {
        $this->registerIntervalIndex($metadataKey);
    } catch (Throwable $e) {
        $this->state->withRowLock($metadataKey, fn () => $this->rawForget($metadataKey));

        throw $e;
    }

    $this->registerLocalInterval($key);
}
```

All registration writes must be checked. If writing metadata or the index shard fails, `interval()` should throw a `RuntimeException` instead of silently registering a cache that the timer cannot refresh. If the metadata write succeeds and the index write fails, delete the metadata row before throwing so there is no unreachable interval metadata left behind.

Index registration stores the bounded metadata table key:

```php
protected function registerIntervalIndex(string $metadataKey): void
{
    $indexKey = $this->intervalIndexKey($metadataKey);

    $result = $this->state->withRowLock($indexKey, function () use ($indexKey, $metadataKey) {
        $record = $this->rawGet($indexKey);
        $index = $this->recordIsFalseOrExpired($record) ? [] : unserialize($record['value']);
        $index[$metadataKey] = true;

        return $this->rawPutSerialized($indexKey, serialize($index), $this->expiration(static::ONE_YEAR));
    });

    if (! $result) {
        throw new RuntimeException("Unable to register Swoole interval index row [{$indexKey}].");
    }
}
```

Why:

- Boot-time resolver work can be expensive and surprising.
- The first manager timer tick seeds the shared value.
- The registering worker still has same-instance fallback through its local interval list before the first tick.

Tradeoff:

- A non-registering worker may return `null` for the interval key until the first interval refresh timer tick seeds the value. With the default 1000ms refresh tick this window is short and avoids expensive boot-time resolver execution in every worker.

### Keep the local interval list, but only for same-instance fallback

`$intervals` should remain a local set, not a list:

```php
/** @var array<string, true> */
protected array $intervals = [];

protected function registerLocalInterval(string $key): void
{
    $this->intervals[$key] = true;
}

protected function hasLocalInterval(string $key): bool
{
    return isset($this->intervals[$key]);
}
```

Why:

- Existing same-instance behavior is useful: immediately after registration, `get($key)` can compute the value without waiting for the timer.
- The local set should not drive manager refresh.
- A set prevents duplicate local refresh attempts.

### Add a dedicated interval refresh timer

Rename `CreateTimer` to `CreateSwooleTimers` or update it so the class name and comments accurately reflect both timers. Because churn is not a constraint, prefer the clearer class name.

```php
class CreateSwooleTimers extends BaseListener
{
    public function handle(OnManagerStart $event): void
    {
        $this->swooleStores()->each(function (array $config, string $name) {
            Timer::tick($config['eviction_interval'] ?? 10000, function () use ($name) {
                $this->store($name)->evictRecords();
            });

            Timer::tick($config['interval_refresh_interval'] ?? 1000, function () use ($name) {
                $this->store($name)->refreshIntervalCaches();
            });
        });
    }

    protected function store(string $name): SwooleStore
    {
        /** @var SwooleStore */
        return Cache::store($name)->getStore();
    }
}
```

Update `CacheServiceProvider` to listen with the renamed class.

Why a separate timer:

- `eviction_interval` defaults to 10 seconds.
- Docs show intervals as short as 5 seconds and the first seed should happen quickly.
- Reusing the eviction timer makes interval caches stale by design.

Add config:

```php
'swoole' => [
    'driver' => 'swoole',
    'table' => 'default',
    'memory_limit_buffer' => 0.05,
    'eviction_policy' => SwooleStore::EVICTION_POLICY_LRU,
    'eviction_proportion' => 0.05,
    'eviction_interval' => 10000, // milliseconds
    'interval_refresh_interval' => 1000, // milliseconds
],
```

### Refresh due intervals through the shared index

`refreshIntervalCaches()` should read the shared interval index, then attempt each interval independently.

```php
public function refreshIntervalCaches(): void
{
    foreach ($this->registeredIntervalMetadataKeys() as $metadataKey) {
        $this->refreshIntervalCache($metadataKey);
    }
}
```

Index reading:

```php
protected function registeredIntervalMetadataKeys(): array
{
    $metadataKeys = [];

    for ($i = 0; $i < self::INTERVAL_INDEX_SHARDS; ++$i) {
        $indexKey = self::INTERVAL_INDEX_PREFIX . $i;
        $record = $this->rawGet($indexKey);

        if ($this->recordIsFalseOrExpired($record)) {
            continue;
        }

        foreach (array_keys(unserialize($record['value'])) as $metadataKey) {
            $metadataKeys[$metadataKey] = true;
        }

        $this->touchInternalRow($indexKey);
    }

    return array_keys($metadataKeys);
}
```

`touchInternalRow()` extends internal row expiration without calling public `touch()`:

```php
protected function touchInternalRow(string $key): void
{
    $this->state->withRowLock($key, function () use ($key) {
        if ($this->rawGet($key) !== false) {
            $this->table->set($key, ['expiration' => $this->expiration(static::ONE_YEAR)]);
        }
    });
}
```

Why:

- The index should not silently expire one year after boot if intervals remain active.
- Touching 64 small index rows once per second is negligible compared to scanning the cache table.

### Claim refresh work before running the resolver

Refreshing one interval should:

1. Lock the metadata row.
2. Read metadata.
3. If missing or not due, return.
4. If claimed and the claim is fresh, return.
5. If claimed and stale, reclaim it.
6. Set `refreshingAt` to the current microsecond timestamp as the claim token.
7. Release the lock.
8. Run the resolver outside locks.
9. Write the public cache value.
10. Lock metadata again and set `lastRefreshedAt` to the claim timestamp and `refreshingAt` to `null`.
11. If resolver throws, report or rethrow according to the caller and clear the claim if it is still current.

Sketch:

```php
protected const INTERVAL_REFRESH_CLAIM_TIMEOUT = 300.0;

protected function refreshIntervalCache(string $metadataKey, bool $force = false, bool $rethrow = false): mixed
{
    $now = $this->getCurrentTimestamp();

    $claim = $this->state->withRowLock($metadataKey, function () use ($metadataKey, $now, $force) {
        $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

        if (! $metadata) {
            return null;
        }

        if (! $force && ! $this->intervalShouldBeRefreshed($metadata, $now)) {
            return null;
        }

        if ($metadata['refreshingAt'] !== null
            && ! $this->intervalClaimIsStale($metadata['refreshingAt'], $now, $metadata['refreshInterval'])) {
            return null;
        }

        $metadata['refreshingAt'] = $now;
        $this->putIntervalMetadataByInternalKey($metadataKey, $metadata);

        return [$metadata, $now];
    });

    if ($claim === null) {
        return null;
    }

    [$metadata, $claimedAt] = $claim;

    try {
        $value = $metadata['resolver']();

        if (! $this->forever($metadata['key'], $value)) {
            throw new RuntimeException("Unable to refresh Swoole interval cache [{$metadata['key']}].");
        }

        $this->completeIntervalRefresh($metadataKey, $claimedAt);

        return $value;
    } catch (Throwable $e) {
        $this->clearIntervalClaim($metadataKey, $claimedAt);

        if ($rethrow) {
            throw $e;
        }

        $this->reportIntervalException($e);

        return null;
    }
}
```

Completion and claim clearing:

```php
protected function completeIntervalRefresh(string $metadataKey, float $claimedAt): void
{
    $this->state->withRowLock($metadataKey, function () use ($metadataKey, $claimedAt) {
        $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

        if (! $metadata || $metadata['refreshingAt'] !== $claimedAt) {
            return;
        }

        $metadata['lastRefreshedAt'] = $claimedAt;
        $metadata['refreshingAt'] = null;
        $this->putIntervalMetadataByInternalKey($metadataKey, $metadata);
    });
}

protected function clearIntervalClaim(string $metadataKey, float $claimedAt): void
{
    $this->state->withRowLock($metadataKey, function () use ($metadataKey, $claimedAt) {
        $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

        if (! $metadata || $metadata['refreshingAt'] !== $claimedAt) {
            return;
        }

        $metadata['refreshingAt'] = null;
        $this->putIntervalMetadataByInternalKey($metadataKey, $metadata);
    });
}
```

`$claimedAt` is a microsecond-precision float from `getCurrentTimestamp()`. The snippets use it as a token; strict comparison should compare the exact stored float value read from and written to the table payload, not recompute it.

Stale claim helper:

```php
protected function intervalClaimIsStale(float $refreshingAt, float $now, int $refreshInterval): bool
{
    $timeout = max(static::INTERVAL_REFRESH_CLAIM_TIMEOUT, $refreshInterval * 2);

    return ($now - $refreshingAt) >= $timeout;
}
```

Exception reporting:

```php
protected function reportIntervalException(Throwable $e): void
{
    if (Container::getInstance()->bound(ExceptionHandler::class)) {
        Container::getInstance()->make(ExceptionHandler::class)->report($e);

        return;
    }

    file_put_contents('php://stderr', (string) $e . PHP_EOL);
}
```

Use the repository's normal container access pattern when implementing; do not introduce a constructor dependency that makes `SwooleStore` hard to instantiate in unit tests. This is an intentional narrow error-path dependency on `Container::getInstance()`, guarded by `bound()` and backed by stderr if the manager process somehow has no exception handler binding.

Why:

- Claiming prevents overlapping slow refreshes from multiple timer callbacks or processes.
- Stale-claim reclamation prevents a hard crash after claim acquisition from freezing an interval until server restart.
- Running the resolver outside the lock prevents user code from blocking cache writes.
- A resolver that runs longer than the stale-claim timeout could have its late public-value write race with a newer refresh. The generous timeout floor makes this unrealistic for intended interval resolvers and the value self-corrects on the next refresh. Keep a source comment near `INTERVAL_REFRESH_CLAIM_TIMEOUT` so the timeout is not lowered casually.
- A refresh is not completed unless writing the public cache value succeeds.
- Completing or clearing only if the claim still matches avoids undoing newer work.
- Exceptions should be visible to the application's exception handling pipeline.

### `get()` should preserve same-instance fallback only

Normal `get()` should not consult the shared interval index. It should only attempt local fallback when the current store instance registered that key locally.

```php
if ($this->hasLocalInterval($key)) {
    return $this->refreshIntervalCache($this->intervalKey($key), force: true, rethrow: true);
}
```

Why:

- A generic miss must stay cheap.
- Checking the shared index on every miss would add overhead to all applications for a feature used by few keys.
- Same-instance fallback preserves the useful immediate-read behavior after registration.
- Local fallback uses the same `refreshingAt` claim as the timer, so it does not overlap an in-progress manager refresh.
- If a timer refresh is already in progress, same-instance fallback returns `null` rather than blocking or running the resolver a second time. The next read sees the refreshed value after the in-flight refresh writes it.

### `flush()` preserves interval metadata and index rows

The row-concurrency plan changes `flush()` to delete only `u:` user rows and preserve control rows. That must include:

- interval metadata rows,
- interval index shard rows,
- lock rows.

`flush()` should delete the actual public cached value for an interval key. The next timer refresh or same-instance fallback can recreate it.

### Control rows should not be evicted as normal cache data

Stale cleanup and eviction should skip interval metadata and index rows.

Why:

- Interval metadata is control-plane state, not user cache data.
- Active interval rows are kept alive by registration and refresh touches.
- Expired lock rows may be cleaned by lock-aware paths, but general user-data eviction should not choose control rows as victims.

## Implementation Steps

1. Apply the row-concurrency plan first.

   Why: this plan relies on `SwooleTableState`, seeded physical key helpers, raw row helpers, and row-lock invariants.

   How: do not start interval implementation while `SwooleStore` still uses public methods recursively for internal writes.

2. Add interval index key helpers.

   Why: row plan already provides seeded `i:` metadata keys; this plan needs bounded `x:` index shard keys for shared timer discovery.

   How: add `INTERVAL_INDEX_PREFIX = 'x:'`, `INTERVAL_INDEX_SHARDS`, and `intervalIndexKey()`. Keep using the row plan's `intervalKey()` for metadata.

3. Convert `$intervals` to a local set.

   Why: avoid duplicate local fallback work.

   How: change the property docblock and replace `in_array()` / append usage with `isset()` / assignment.

4. Rewrite interval metadata accessors.

   Why: avoid public `get()` recursion, double serialization, and duplicated plan-1 helpers.

   How: replace plan 1's `getInterval()` with one metadata accessor vocabulary centered on `getIntervalMetadataByInternalKey()` and `putIntervalMetadataByInternalKey()`. Add logical-key wrappers only if they have real call sites; do not keep an unused `getIntervalMetadata()` helper.

5. Implement shared index registration.

   Why: manager process needs cross-worker discovery.

   How: lock the index shard row, decode or start an empty array, set `$index[$key] = true`, write it back with `ONE_YEAR` expiration, and throw if the write fails.

6. Rewrite `interval()`.

   Why: registration should be shared and cheap.

   How: write metadata under the metadata row lock, register the index shard, register the local set, do not run the resolver.

7. Rewrite `refreshIntervalCaches()`.

   Why: manager timer should refresh all shared registrations, not just local registrations.

   How: read registered metadata table keys from index shards with `registeredIntervalMetadataKeys()` and call `refreshIntervalCache($metadataKey)` for each.

8. Add claim / resolver / completion refresh flow.

   Why: prevent overlapping refreshes and avoid poisoning successful-refresh timestamps after exceptions.

   How: claim with `refreshingAt` under metadata row lock, run resolver outside locks, write public value through `forever()`, complete by setting `lastRefreshedAt` and clearing `refreshingAt`, and clear/report or clear/rethrow on failure.

9. Replace plan 1's local interval fallback in `get()`.

   Why: plan 1 directly calls the resolver after `getInterval()`. Plan 2 must use the same claim path as the timer so fallback and timer refresh do not overlap.

   How: replace the fallback with `refreshIntervalCache($this->intervalKey($key), force: true, rethrow: true)`. Document that a concurrent non-stale claim returns `null` rather than blocking or duplicating resolver work.

10. Update `intervalShouldBeRefreshed()` signature.

   Why: plan 1's method takes only `array $interval`; plan 2 due checks need the current timestamp passed in so claim decisions use one consistent time sample.

   How: change it to `intervalShouldBeRefreshed(array $metadata, float $now): bool`. Store `lastRefreshedAt` as the successful claim's microsecond float and compare `($now - $metadata['lastRefreshedAt']) >= $metadata['refreshInterval']`.

11. Update timer listener.

   Why: interval caches currently have no automatic refresh timer.

   How: rename `CreateTimer` to `CreateSwooleTimers` or otherwise make name/comments accurate; create both eviction and interval refresh timers from `OnManagerStart`.

12. Update config and docs.

    Why: users need the new interval timer setting and docs should match first-tick behavior.

    How: add `interval_refresh_interval` to framework config and docs; update Octane interval docs to state that the manager refresh timer seeds and refreshes interval values, so a non-registering worker may not see a value until the first tick.

13. Remove stale interval comments and docs.

    Why: final code should not preserve old "minutes" wording or imply eager refresh.

    How: change the `interval()` docblock to seconds, delete obsolete comments, and avoid mentioning the old `interval-` key scheme.

## Testing Plan

Run each touched test file immediately after updating it, then run the relevant package suite.

Commands:

```bash
vendor/bin/phpunit tests/Cache/CacheSwooleStoreTest.php
vendor/bin/phpunit tests/Cache/CacheSwooleStoreIntervalTest.php
vendor/bin/phpunit tests/Cache
```

### Store-level interval tests

Add a dedicated `tests/Cache/CacheSwooleStoreIntervalTest.php` or expand `CacheSwooleStoreTest.php` if the file remains readable. Prefer a dedicated file if row-concurrency tests make the existing file large.

Required coverage:

- `interval()` writes metadata under the seeded `i:` control key, not `interval-{$key}`.
- `interval()` writes the original public key into metadata.
- `interval()` registers the bounded `i:` metadata table key in exactly one `x:` shared index shard, not the raw logical key.
- Calling `interval('foo', ...)` twice does not duplicate local registration or index entries.
- `get('foo')` on the same store instance can resolve through local fallback before the first timer tick.
- `get('foo')` on a different store instance sharing the same state returns `null` before the first timer tick.
- `refreshIntervalCaches()` on a different store instance with an empty local interval set discovers `foo` through the shared index and writes the public cached value.
- After refresh, any store instance sharing the state can read the public value.
- Refresh does not run the resolver again before `refreshInterval` seconds have elapsed.
- Refresh runs the resolver again after the interval is due.
- Metadata keeps `lastRefreshedAt` as the last successful refresh and clears `refreshingAt` after success.
- A second refresh attempt returns without running the resolver while `refreshingAt` is set.
- A stale `refreshingAt` claim older than `max(INTERVAL_REFRESH_CLAIM_TIMEOUT, refreshInterval * 2)` can be reclaimed and refreshed.
- `refreshingAt` uses microsecond float precision, and completion/clearing does not clear a newer claim.
- Same-instance fallback during a non-stale in-flight timer claim returns `null` and does not run the resolver a second time.
- `flush()` deletes the public value but preserves metadata and index rows.
- After `flush()`, `refreshIntervalCaches()` can recreate the public value from preserved metadata.
- Generic `get('missing')` does not consult the shared interval index.
- Index shard rows are touched during refresh discovery so they do not expire while intervals remain active.
- Stale cleanup and eviction skip interval metadata and index rows.

Use two stores sharing one state:

```php
$state = $this->createState();
$workerStore = $this->createStore($state);
$managerStore = $this->createStore($state);

$workerStore->interval('foo', fn () => 'bar', 5);

$this->assertNull($managerStore->get('foo'));

$managerStore->refreshIntervalCaches();

$this->assertSame('bar', $workerStore->get('foo'));
$this->assertSame('bar', $managerStore->get('foo'));
```

### Resolver exception tests

Required coverage:

- A throwing resolver is reported through `ExceptionHandler`.
- A throwing resolver does not write a new public value.
- A failed public cache write is treated like a refresh failure: the claim is cleared, `lastRefreshedAt` is unchanged, and the failure is reported or rethrown according to caller.
- A throwing timer resolver clears `refreshingAt` and leaves `lastRefreshedAt` unchanged so the next refresh attempt can retry immediately.
- A throwing same-instance fallback resolver clears `refreshingAt` and rethrows to preserve current direct-resolver behavior.
- Claim clearing does not overwrite a newer claim if metadata changed after the failed claim.

Use a Mockery spy bound in the container instead of a hand-written anonymous class:

```php
$handler = m::spy(ExceptionHandler::class);
$this->app->instance(ExceptionHandler::class, $handler);

// trigger failed refresh

$handler->shouldHaveReceived('report')->with($exception);
```

Do not add a brittle stderr-capture test for the no-handler fallback. Cover it with a narrow unit test only if the suite already has a stable stderr-capture helper; otherwise leave that fallback covered by code review.

### Timer wiring tests

Add or update listener tests for the timer class.

Required coverage:

- For each configured Swoole store, one eviction timer is registered with `eviction_interval`.
- For each configured Swoole store, one interval refresh timer is registered with `interval_refresh_interval`.
- Defaults are `10000` ms for eviction and `1000` ms for interval refresh.
- The eviction callback calls `evictRecords()`.
- The interval callback calls `refreshIntervalCaches()`.

If `Swoole\Timer::tick()` is hard to fake directly, use the existing repository's pattern for facade/static timer tests. If no pattern exists, keep this as a narrow integration-style test around the listener with a test double introduced through the smallest clean abstraction, such as an injectable timer callback registrar. Do not leave production code with a test-only seam; the abstraction should make the timer listener cleaner.

### Documentation tests

No generated docs tests are expected, but update:

- `src/foundation/config/cache.php`
- `src/boost/docs/cache.md`
- `src/boost/docs/octane.md`

Review docs manually to ensure they no longer say interval registration refreshes values eagerly or that the `interval()` parameter is minutes.

## Performance Expectations

Normal cache operations:

- `get()` for ordinary misses does not read interval index rows.
- `get()` for ordinary hits is unchanged from the row-concurrency plan.
- `put()`, `add()`, `increment()`, `touch()`, and locks do not interact with interval index rows.

Interval operations:

- `interval()` does two tiny locked writes: one metadata row and one index shard row.
- `refreshIntervalCaches()` reads at most 64 index shard rows and then touches only registered intervals.
- Resolver callbacks run outside locks.
- Due checks are per registered interval, not per cache table row.
- The dedicated 1-second timer adds a small fixed read cost per Swoole store and no work to request hot paths.

CPU load at scale is bounded by the number of registered interval caches, not the number of cache entries or application requests. The design avoids a full Swoole Table scan every second.

## Self-Review Checklist

Before implementation starts, verify these points against the codebase one more time:

- `OnManagerStart` really runs in the manager process and is the right place for both timers.
- `CreateSwooleTable` still creates the state before fork.
- `refreshIntervalCaches()` no longer depends on local `$intervals`.
- Same-instance fallback still works before the first timer tick.
- Cross-instance refresh works with a fresh `SwooleStore` sharing the same state.
- No resolver can run while a row lock or all-row lock is held.
- Resolver exceptions are reported without suppressing future refresh attempts.
- Interval metadata and index control rows cannot be flushed or evicted as user data.
- Index shard row writes fit through the same value-size validation as other Swoole rows, and tests cover a realistic multi-key shard.
- The row-concurrency plan's `flush()` and eviction helpers preserve the `i:` and `x:` control rows used here.
- Timer docs and config comments mention milliseconds for timer options and seconds for `interval()`.
