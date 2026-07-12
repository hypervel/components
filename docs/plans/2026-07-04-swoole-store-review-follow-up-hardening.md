# Swoole Store Review Follow-Up Hardening

## Goal

Harden the Swoole cache store changes from PR #414 after reviewer feedback, keeping the same overall design and preserving the store's intended performance profile.

The final code should read as if it was designed this way from the start:

- Swoole cache timers run in a worker process that has coroutine/runtime access, not in the manager process.
- Periodic interval refresh and periodic stale cleanup / eviction are registered together on a single elected non-task worker.
- Periodic stale cleanup keeps running even when the table is below the memory threshold, so expired cache rows and expired lock rows do not accumulate indefinitely.
- Policy eviction honors `eviction_proportion` and stores compact eviction candidates instead of copying serialized values into PHP heap.
- Eviction deletes a row only when the row is still the same logical candidate selected during the scan.
- A stale interval refresher cannot overwrite a newer refresher's public value after its claim has been reclaimed.
- Row-lock contention has a tiny backoff after a short spin burst, avoiding sustained CPU burn under hot-key contention without adding cost to uncontended locks.
- Cache-hit metadata remains lock-free on the read hot path, with the known Swoole Table phantom-row behavior documented in the code.
- Multi-key writes report per-key write failures correctly, including empty batches as successful no-ops.
- Dead helpers introduced during the earlier design are removed.

## Research

Files checked:

- `src/cache/src/SwooleStore.php`
- `src/cache/src/SwooleTableState.php`
- `src/cache/src/LimitedMaxHeap.php`
- `src/cache/src/Listeners/CreateSwooleTimers.php`
- `src/cache/src/CacheServiceProvider.php`
- `src/cache/src/RetrievesMultipleKeys.php`
- `src/cache/src/DatabaseStore.php`
- `src/cache/src/MemoizedStore.php`
- `src/cache/src/FailoverStore.php`
- `src/cache/src/StackStore.php`
- `src/cache/src/SwooleTimer.php`
- `src/boost/docs/cache.md`
- `src/core/src/Bootstrap/WorkerStartCallback.php`
- `src/core/src/Events/AfterWorkerStart.php`
- `src/core/src/Events/OnManagerStart.php`
- `src/server/src/Listeners/AfterWorkerStartListener.php`
- `src/server/src/Listeners/InitProcessTitleListener.php`
- `tests/Cache/CacheSwooleStoreTest.php`
- `tests/Cache/CacheSwooleStoreIntervalTest.php`
- `tests/Cache/CreateSwooleTimersTest.php`
- `Symfony\Component\Process\Process` test usage in `tests/Filesystem/FilesystemNonCoroutineTest.php`
- `docs/plans/2026-07-03-swoole-store-row-concurrency-and-locks.md`
- `docs/plans/2026-07-03-swoole-store-interval-cache-refresh.md`
- `docs/reviews/swoole-store-concurrency-intervals-review.md`

Runtime probes confirmed:

- `OnManagerStart` timer callbacks run in the Swoole manager process with `Coroutine::getCid() === -1`.
- Coroutine primitives used by pooled clients fail from the manager process.
- `AfterWorkerStart` callbacks run in worker processes where coroutine/runtime APIs are available.
- `WorkerStartCallback` dispatches `AfterWorkerStart` for both normal workers and task workers, so the cache timer listener must explicitly skip task workers.
- `Swoole\Server` should be mocked in timer unit tests. Constructing real `Swoole\Server` instances is one-per-process and breaks repeated test cases, while `m::mock(Swoole\Server::class)` satisfies `AfterWorkerStart`'s `readonly Server $server` property and allows direct `$server->taskworker` setup.
- `Swoole\Table` exposes `set`, `get`, `del` / `delete`, `exists` / `exist`, `incr`, `decr`, table sizing/stat methods, and iteration. It does not expose full-row CAS, set-if-absent, delete-if-current, or update-existing-only primitives.
- Swoole Table partial `set()` and `incr()` on a missing row create an empty row with `value = ''` and `expiration = 0.0`. In SwooleStore that row is logically expired, reads as `null`, and is pruned by stale cleanup or a later miss.
- `src/boost/docs/cache.md` describes interval refreshes as manager-process work. That sentence must change when the timers move to the elected worker.

Relevant current code:

```php
// src/cache/src/Listeners/CreateSwooleTimers.php
public function handle(OnManagerStart $event): void
{
    $this->swooleStores()->each(function (array $config, string $name) {
        $this->timer->tick(
            $config['eviction_interval'] ?? 10000,
            fn () => $this->store($name)->evictRecords(),
        );

        $this->timer->tick(
            $config['interval_refresh_interval'] ?? 1000,
            fn () => $this->store($name)->refreshIntervalCaches(),
        );
    });
}
```

```php
// src/cache/src/LimitedMaxHeap.php
public function insert(mixed $value): true
{
    if ($this->count() < $this->limit) {
        parent::insert($value);
        return true;
    }

    if ($this->compare($value, $this->top()) < 0) {
        $this->extract();
    }

    parent::insert($value);

    return true;
}
```

```php
// src/cache/src/SwooleStore.php
protected function handleRecordsEviction(string $column): int
{
    $quantity = (int) round($this->table->getSize() * $this->evictionProportion);

    // ...

    foreach ($this->table as $key => $record) {
        if ($this->isControlKey($key)) {
            continue;
        }

        $value = $record[$column];

        $heap->insert(compact('key', 'record', 'value'));
    }

    // ...

    if ($this->forgetEvictionCandidate($candidate['key'], $candidate['record'])) {
        ++$deleted;
    }
}
```

```php
// src/cache/src/SwooleStore.php
protected function refreshIntervalCache(string $metadataKey, bool $force = false, bool $rethrow = false): mixed
{
    $claimedAt = null;

    try {
        // Claim metadata row under row lock.

        [$metadata, $claimedAt] = $claim;

        /** @var SerializableClosure $resolver */
        $resolver = unserialize($metadata['resolver']);
        $value = $resolver();

        if (! $this->forever($metadata['key'], $value)) {
            throw new RuntimeException("Unable to refresh Swoole interval cache [{$metadata['key']}].");
        }

        $this->completeIntervalRefresh($metadataKey, $claimedAt);

        return $value;
    } catch (Throwable $e) {
        // ...
    }
}
```

```php
// src/cache/src/SwooleTableState.php
protected function acquire(Atomic $lock): void
{
    while (! $lock->cmpset(0, 1)) {
        // Critical sections must stay short, non-yielding, and fatal-free so finally can release the stripe.
        // A hard process death while holding a stripe leaves it locked until the Swoole table state is recreated.
    }
}
```

```php
// src/cache/src/SwooleStore.php
public function putMany(array $values, int $seconds): bool
{
    foreach ($values as $key => $value) {
        $this->put($key, $value, $seconds);
    }

    return true;
}
```

```php
// src/cache/src/RetrievesMultipleKeys.php
public function putMany(array $values, int $seconds): bool
{
    $manyResult = null;

    foreach ($values as $key => $value) {
        $result = $this->put((string) $key, $value, $seconds);

        $manyResult = is_null($manyResult) ? $result : $result && $manyResult;
    }

    return $manyResult ?: false;
}
```

## Confirmed Problems

### Timers run in the wrong Swoole process

The timer listener currently listens for `OnManagerStart`. The manager process is not a normal worker and does not have a request/coroutine execution context. Interval resolvers are user callbacks and commonly use pooled DB, Redis, or HTTP clients. Those pooled clients depend on coroutine primitives, so proactive interval refreshes can fail in the manager process.

The fix is to register the cache timers on `AfterWorkerStart`, restricted to non-task worker `0`. That worker already has access to the normal runtime. Registering on exactly one worker preserves single-timer behavior.

Both timers should move together:

- The interval refresh timer must move because it runs user resolvers.
- The stale cleanup / eviction timer should move because it touches the same cache store and should not keep doing table work in the manager process.

The eviction timer callback must continue to call `evictRecords()` directly. It must not be guarded by `memoryLimitIsReached()` in the listener, because `evictRecords()` begins with unconditional `flushStaleRecords()`. Gating the timer at the listener would stop periodic stale-row and expired-lock cleanup whenever the table is below the memory threshold.

### `LimitedMaxHeap` does not enforce its limit

When the heap is full and the incoming value is not better than the current top, the current method still inserts the value. The heap grows beyond its intended size. `SwooleStore::handleRecordsEviction()` expects the heap to retain only the lowest-ranked `round(table_size * eviction_proportion)` rows; instead it can retain and evict far more rows.

This also causes avoidable PHP heap pressure because each candidate currently includes the full Swoole row, including the serialized cache value.

### Eviction candidate recheck copies too much and compares too much

The existing PR rechecks eviction candidates under the row lock by strict-comparing the current raw row to the row captured during the scan. That is correct in spirit, but it stores full rows in the heap. Under memory pressure this copies serialized values into PHP memory precisely when the table is already near capacity.

The final design should keep the recheck but store a compact fingerprint:

- `hash('xxh128', $record['value'])`
- `expiration`
- `last_used_at`
- `used_count`

The value hash is necessary. A metadata-only fingerprint would miss value changes from `put()` and `increment()` when expiration and hit metadata happen to stay the same.

### A reclaimed stale interval refresher can overwrite a newer value

The interval claim protects metadata updates, but the public cache value write currently happens before rechecking ownership of the claim. If refresher A runs longer than the stale-claim timeout, refresher B can reclaim the interval and write a newer value. When A eventually returns, A can still call `forever()` and overwrite B's value, even though A's later metadata completion is correctly ignored.

The public value write must be gated by a fresh ownership check after the resolver returns.

### Row-lock contention can busy-spin too aggressively

The striped `Swoole\Atomic` lock is still the right primitive for Swoole Table multi-step operations because Swoole Table has no native full-row CAS, set-if-absent, delete-if-current, or update-existing-only operation. The current acquire loop is a pure busy spin. That is optimal for the uncontended case and acceptable for very short waits, but it can burn CPU under hot-key contention.

The final design should keep the same shared stripe locks and add a tiny backoff after a short burst of failed spins. This does not add any operation on the uncontended path.

Stale-owner stealing should not be added. With `Swoole\Atomic` there is no reliable owner-death detection. Stealing based only on elapsed time can break correctness if the owner is alive but paused by CPU scheduling, GC, extension work, or debugging. The existing class-level `@TODO` should remain because native Swoole Table CAS / set-if-absent / delete-if-current primitives would be a better long-term primitive.

### `recordHit()` is intentionally lock-free but needs a WHY comment

`recordHit()` updates LRU/LFU metadata after an unlocked `get()`. Locking the read-hit path would add overhead to the hottest operation in the store. Swoole Table does not provide update-existing-only, so a concurrent delete can cause partial `set()` or `incr()` to recreate an expired empty row. That phantom row is logically expired, reads as `null`, and is cleaned by stale cleanup or a later miss.

This is a deliberate performance trade-off and should be recorded near the method.

### `putMany()` masks failed writes

`SwooleStore::putMany()` currently ignores the result from each `put()` call and always returns `true`. `put()` can fail if the table cannot allocate a row. A multi-key write should return `false` if any item fails, and an empty input should return `true`.

The shared fallback trait has the same empty-input problem in a different form: it aggregates results but returns `false` for an empty array. That should be corrected so stores using the fallback have consistent no-op semantics.

The trait is used by `AbstractArrayStore`, `FileStore`, `NullStore`, and `SessionStore`, so trait-level tests should cover the contract once. Other native `putMany()` implementations should be audited at the same time:

- `DatabaseStore::putMany()` should return `true` for an empty batch and should not treat a successful no-op upsert as a write failure.
- `DatabaseStore::put()` delegates to `putMany()`, so the same fix also makes identical-value single-key writes report success when the database reports zero affected rows.
- `StackStore::putMany()` already returns `true` for an empty batch but should aggregate failures while still attempting every key, matching the shared fallback.
- `MemoizedStore::putMany()` delegates to its repository after invalidating memoized keys, so it inherits the underlying store's result once the underlying stores are fixed.
- `FailoverStore::putMany()` delegates to the first available configured store, so its empty-batch semantics follow the selected store.

### `isUserKey()` is dead code

`isUserKey()` is not called by the store or tests. It should be removed.

## Decisions

### Keep PR #414's row-locking design

The earlier row-locking design is the right foundation. It fixed the important SwooleStore correctness gaps:

- atomic `add()`
- logical expiration in `add()`
- lost updates in `increment()`, `decrement()`, and `touch()`
- stale delete races in cleanup and eviction
- lock API support
- raw Swoole table key truncation / collision risk

The follow-up work should harden that design rather than replace it.

### Register timers from `AfterWorkerStart` on non-task worker `0`

Use the same event style as other Hypervel listeners:

```php
// src/cache/src/CacheServiceProvider.php
use Hypervel\Core\Events\AfterWorkerStart;

// ...

$events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
    $this->app->make(CreateSwooleTimers::class)->handle($event);
});
```

The listener should type `AfterWorkerStart` and guard all timer registration:

```php
// src/cache/src/Listeners/CreateSwooleTimers.php
use Hypervel\Core\Events\AfterWorkerStart;

class CreateSwooleTimers extends BaseListener
{
    // ...

    /**
     * Create timers for all configured Swoole cache stores.
     */
    public function handle(AfterWorkerStart $event): void
    {
        if (! $this->shouldRegisterTimers($event)) {
            return;
        }

        $this->swooleStores()->each(function (array $config, string $name) {
            $this->timer->tick(
                $config['eviction_interval'] ?? 10000,
                fn () => $this->store($name)->evictRecords(),
            );

            $this->timer->tick(
                $config['interval_refresh_interval'] ?? 1000,
                fn () => $this->store($name)->refreshIntervalCaches(),
            );
        });
    }

    /**
     * Determine if this worker should own Swoole cache timers.
     */
    protected function shouldRegisterTimers(AfterWorkerStart $event): bool
    {
        return $event->workerId === 0 && ! $event->server->taskworker;
    }
}
```

Do not extract a separate task-worker seam. Tests can construct a real `AfterWorkerStart` event around a Mockery `Swoole\Server` mock and set `$server->taskworker` to `true` or `false` directly.

Rejected alternatives:

- Keep timers on `OnManagerStart`: interval resolvers can use coroutine-only resources, so the manager process is the wrong place.
- Wrap manager timer callbacks in `Coroutine::run()` or `go()`: probes showed manager-process coroutine/runtime behavior is not a reliable fit for these pooled operations, and the framework already has a worker event.
- Register on every worker: that would multiply timer executions and make refresh/cleanup frequency depend on worker count.
- Move only interval refresh: stale cleanup and eviction also operate on worker-owned cache services, and a single worker owner is cleaner.
- Guard the eviction timer with `memoryLimitIsReached()`: this would stop the unconditional stale cleanup inside `evictRecords()`.
- Extract `isTaskWorker()` only for tests: the guard is a one-line production rule, and Mockery can model the server property without a test-only production seam.

### Fix `LimitedMaxHeap` by discarding non-better full-heap values

The heap should insert directly while below the limit. Once full, it should only replace the current top when the incoming value is better.

```php
public function insert(mixed $value): true
{
    if ($this->count() < $this->limit) {
        parent::insert($value);

        return true;
    }

    if ($this->compare($value, $this->top()) < 0) {
        $this->extract();
        parent::insert($value);
    }

    return true;
}
```

Validate the heap limit in the constructor so the helper cannot be used in an invalid state:

```php
use InvalidArgumentException;

class LimitedMaxHeap extends SplMaxHeap
{
    public function __construct(protected int $limit)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Heap limit must be at least 1.');
        }
    }

    public function insert(mixed $value): true
    {
        if ($this->count() < $this->limit) {
            parent::insert($value);

            return true;
        }

        if ($this->compare($value, $this->top()) < 0) {
            $this->extract();
            parent::insert($value);
        }

        return true;
    }
}
```

`SwooleStore::handleRecordsEviction()` should keep returning before constructing the heap when quantity is `<= 0`; constructor validation is a guardrail for the helper itself.

### Use compact eviction fingerprints

Add a helper that captures exactly the row fields relevant to whether a scanned candidate is still the same row:

```php
/**
 * Get the compact eviction fingerprint for a raw table record.
 *
 * @return array{value_hash: string, expiration: float, last_used_at: float, used_count: int}
 */
protected function evictionFingerprint(array $record): array
{
    return [
        'value_hash' => hash('xxh128', $record['value']),
        'expiration' => $record['expiration'],
        'last_used_at' => $record['last_used_at'],
        'used_count' => $record['used_count'],
    ];
}
```

Use it while building candidates:

```php
foreach ($this->table as $key => $record) {
    if ($this->isControlKey($key)) {
        continue;
    }

    $heap->insert([
        'key' => $key,
        'fingerprint' => $this->evictionFingerprint($record),
        'value' => $record[$column],
    ]);
}
```

Recheck under the row lock before deleting:

```php
/**
 * Forget an eviction candidate by table key.
 *
 * @param array{value_hash: string, expiration: float, last_used_at: float, used_count: int} $fingerprint
 */
protected function forgetEvictionCandidate(string $key, array $fingerprint): bool
{
    return $this->state->withRowLock($key, function () use ($key, $fingerprint): bool {
        $record = $this->rawGet($key);

        if ($record === false
            || $this->isControlKey($key)
            || $this->evictionFingerprint($record) !== $fingerprint) {
            return false;
        }

        return $this->rawForget($key);
    });
}
```

Use non-cryptographic `xxh128` because this is an in-process change detector, not a trust boundary.

Rejected alternatives:

- Keep storing full rows: correct but wasteful under memory pressure.
- Use metadata-only fingerprints: misses value changes from `put()` and `increment()`.
- Re-read and compare only the ranked column: misses almost every meaningful mutation.

### Guard interval public value commits with the current claim

After the resolver returns, re-acquire the metadata row lock and confirm this refresher still owns the claim. If it still owns the claim, restamp `refreshingAt` to a fresh timestamp and release the metadata lock. Then write the public value outside the metadata lock and complete the refresh using the new claim timestamp.

The restamp matters because if the value write fails after the commit check, the catch path should clear only the claim owned by this refresher. It must not clear a newer claim created after this refresher released the metadata lock.

`lastRefreshedAt` should intentionally use the commit timestamp after this change, not the original claim timestamp. That makes the interval cadence "N seconds after the last successful refresh completed" instead of "N seconds after the refresh started." Completion-time cadence is the cleaner invariant for slow resolvers: a resolver that takes most of its interval does not immediately become due again right after it finishes.

Add a helper shaped like:

```php
/**
 * Prepare a claimed interval refresh for public value commit.
 *
 * @return null|array{0: array, 1: float}
 */
protected function prepareIntervalRefreshCommit(string $metadataKey, float $claimedAt): ?array
{
    return $this->state->withRowLock($metadataKey, function () use ($metadataKey, $claimedAt): ?array {
        $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

        if ($metadata === null || $metadata['refreshingAt'] !== $claimedAt) {
            return null;
        }

        $commitClaimedAt = $this->getCurrentTimestamp();
        $metadata['refreshingAt'] = $commitClaimedAt;

        if (! $this->putIntervalMetadataByInternalKey($metadataKey, $metadata)) {
            throw new RuntimeException("Unable to prepare Swoole interval cache refresh [{$metadata['key']}].");
        }

        return [$metadata, $commitClaimedAt];
    });
}
```

Update `refreshIntervalCache()`:

```php
[$metadata, $claimedAt] = $claim;

/** @var SerializableClosure $resolver */
$resolver = unserialize($metadata['resolver']);
$value = $resolver();

$commit = $this->prepareIntervalRefreshCommit($metadataKey, $claimedAt);

if ($commit === null) {
    return null;
}

[$metadata, $claimedAt] = $commit;

if (! $this->forever($metadata['key'], $value)) {
    throw new RuntimeException("Unable to refresh Swoole interval cache [{$metadata['key']}].");
}

$this->completeIntervalRefresh($metadataKey, $claimedAt);

return $value;
```

Keep the public `forever()` call outside the metadata lock. `forever()` locks the user row, serializes arbitrary user values, and may trigger eviction. Holding the metadata lock while doing that would create a metadata-to-user nested lock path and increase deadlock risk against all-stripe operations. The current pattern keeps user code and user value serialization outside metadata-row critical sections.

The catch block should keep using the current `$claimedAt` value. After `prepareIntervalRefreshCommit()` succeeds, `$claimedAt` is the restamped claim and failed public writes clear only that restamped claim. If `prepareIntervalRefreshCommit()` returns `null`, no catch path runs and no claim is cleared, because this refresher no longer owns the metadata row.

Tests should assert the completion-time behavior anywhere the clock advances between claim and commit. Existing frozen-time tests can continue asserting exact equality because claim and commit timestamps are the same under a frozen `Carbon::setTestNow()`.

Rejected alternatives:

- Write the public value under the metadata lock: correct for the stale-overwrite race but creates a wider and nested critical section.
- Compare only after writing: still allows stale values to be visible.
- Skip the restamp: a failed public value write could clear a newer claim that appears between the ownership check and catch cleanup.
- Preserve start-time cadence: this allows slow resolvers to become due again immediately after completion, which is less useful than spacing refreshes from the last successful commit.

### Add a failed-CAS backoff to row-lock acquisition

Keep the same lock primitive and add a small backoff only after repeated failed CAS attempts:

```php
protected const SPINS_BEFORE_BACKOFF = 64;

/**
 * Acquire a striped lock.
 */
protected function acquire(Atomic $lock): void
{
    $spins = 0;

    while (! $lock->cmpset(0, 1)) {
        if (++$spins >= self::SPINS_BEFORE_BACKOFF) {
            $spins = 0;
            usleep(1);
        }
    }
}
```

Use raw `usleep(1)` deliberately:

- It is skipped entirely on uncontended locks.
- It avoids a dependency on framework sleep fakes in low-level cache infrastructure.
- In Swoole workers with runtime hooks, it can yield cooperatively.
- In non-coroutine contexts, it remains valid PHP.

Do not use `Hypervel\Support\Sleep` here. Lock acquisition is low-level infrastructure and should not be fakeable by tests outside cache row-lock behavior.

Do not add owner-token stale reclaim. `Swoole\Atomic` does not prove owner death, and time-based stealing can let two live workers enter the same critical section.

The existing class docblock should keep the `@TODO` requested by the owner:

```php
/**
 * Coordinates multi-step Swoole table row mutations across workers.
 *
 * Swoole Table does not currently provide full-row compare-and-swap,
 * set-if-absent, or delete-if-current primitives, so cache operations that need
 * atomic read-check-write behavior use striped shared Atomics around tiny
 * critical sections.
 *
 * @TODO Revisit this if Swoole Table adds full-row CAS / set-if-absent /
 * delete-if-current primitives so these operations can use native table atomics
 * instead of external stripe locks.
 */
```

### Keep `recordHit()` lock-free and document the phantom-row behavior

Add a concise WHY comment near the method:

```php
/**
 * Record a cache hit.
 */
protected function recordHit(string $key): void
{
    // Hit metadata stays lock-free for the read hot path. If a concurrent delete wins,
    // Swoole can create an expired shell row that stale cleanup later prunes.
    if ($this->evictionPolicy === static::EVICTION_POLICY_LRU) {
        $this->table->set($key, ['last_used_at' => $this->getCurrentTimestamp()]);

        return;
    }

    if ($this->evictionPolicy === static::EVICTION_POLICY_LFU) {
        $this->table->incr($key, 'used_count', 1);
    }
}
```

Do not lock `recordHit()`. That would add row-lock overhead to every LRU/LFU cache hit and would compromise the store's fastest path to prevent a harmless, self-pruning shell row.

### Aggregate `putMany()` results and treat empty input as success

Fix `SwooleStore`:

```php
public function putMany(array $values, int $seconds): bool
{
    $result = true;

    foreach ($values as $key => $value) {
        $result = $this->put((string) $key, $value, $seconds) && $result;
    }

    return $result;
}
```

Fix the shared fallback trait with the same semantics:

```php
public function putMany(array $values, int $seconds): bool
{
    $result = true;

    foreach ($values as $key => $value) {
        $result = $this->put((string) $key, $value, $seconds) && $result;
    }

    return $result;
}
```

The `&& $result` order preserves execution of every write while still returning `false` if any write fails.

### Remove `isUserKey()`

Delete the method and do not replace it. The store only needs `isControlKey()` and `isLockKey()`.

## Implementation Plan

### 1. Update timer event wiring

Edit `src/cache/src/CacheServiceProvider.php`:

- Replace the `OnManagerStart` import with `AfterWorkerStart`.
- Register `CreateSwooleTimers` on `AfterWorkerStart`.
- Keep `CreateSwooleTable` on `BeforeServerStart`.

Edit `src/cache/src/Listeners/CreateSwooleTimers.php`:

- Replace `OnManagerStart` with `AfterWorkerStart`.
- Add the `shouldRegisterTimers()` guard.
- Inline the task-worker check as `$event->server->taskworker`.
- Leave the callbacks as `evictRecords()` and `refreshIntervalCaches()`.

Do not add `memoryLimitIsReached()` to the listener.

### 2. Fix `LimitedMaxHeap`

Edit `src/cache/src/LimitedMaxHeap.php`:

- Reject limits below `1` in the constructor.
- Move `parent::insert($value)` inside the full-heap replacement branch.
- Return `true` after discarding non-better values.

### 3. Compact eviction candidates

Edit `src/cache/src/SwooleStore.php`:

- Add `evictionFingerprint()` beside the eviction helpers, near `handleRecordsEviction()` and `forgetEvictionCandidate()`.
- Change `handleRecordsEviction()` to insert `key`, `value`, and `fingerprint`.
- Change the drain loop to pass the fingerprint to `forgetEvictionCandidate()`.
- Change `forgetEvictionCandidate()` to re-read under the row lock, skip missing/control/mutated rows, and delete only unchanged rows.

### 4. Guard interval refresh commits

Edit `src/cache/src/SwooleStore.php`:

- Add `prepareIntervalRefreshCommit()` beside the interval refresh helpers, between `refreshIntervalCache()` and `completeIntervalRefresh()`.
- Update `refreshIntervalCache()` to:
  - claim as it does today;
  - run the resolver outside the lock;
  - call `prepareIntervalRefreshCommit()`;
  - return `null` if the claim has been lost;
  - update `$metadata` and `$claimedAt` from the commit helper;
  - write the public value outside the metadata lock;
  - call `completeIntervalRefresh()` using the restamped claim timestamp.
- Keep `completeIntervalRefresh()` and `clearIntervalClaim()` guarded by exact `refreshingAt === $claimedAt`.
- Ensure `completeIntervalRefresh()` keeps stamping `lastRefreshedAt` from the current `$claimedAt`, which is the commit timestamp after `prepareIntervalRefreshCommit()` succeeds.

### 5. Add lock-acquire backoff

Edit `src/cache/src/SwooleTableState.php`:

- Add `SPINS_BEFORE_BACKOFF`.
- Add the failed-spin counter and raw `usleep(1)`.
- Keep the class-level `@TODO`.
- Keep release as `cmpset(1, 0)`.

### 6. Clarify `recordHit()` and remove dead helper

Edit `src/cache/src/SwooleStore.php`:

- Add the concise WHY comment to `recordHit()`.
- Remove `isUserKey()`.

### 7. Fix multi-key write aggregation

Edit `src/cache/src/SwooleStore.php`:

- Aggregate per-key `put()` results.
- Cast array keys to string before passing to `put()`.
- Return `true` for empty arrays.

Edit `src/cache/src/RetrievesMultipleKeys.php`:

- Use the same aggregate shape.
- Return `true` for empty arrays.
- This changes the fallback semantics for `AbstractArrayStore`, `FileStore`, `NullStore`, and `SessionStore`.

Edit `src/cache/src/DatabaseStore.php`:

- Return `true` immediately for empty input.
- Treat a successful non-empty `upsert()` call as success even when the affected-row count is `0`, because an upsert that writes identical values can be a no-op at the database row-count level without being a cache write failure.
- Implement that as `upsert(); return true;` after the empty-input guard. Do not use `>= 0`; the database exception path is the failure signal, and a comparison that is always true obscures the intent.
- Cover `put()` too, because it delegates directly to `putMany([$key => $value], $seconds)`.

Edit `src/cache/src/StackStore.php`:

- Aggregate per-key `put()` results while still attempting every key.
- Keep returning `true` for empty input.

Audit `src/cache/src/MemoizedStore.php` and `src/cache/src/FailoverStore.php`:

- Confirm their `putMany()` semantics follow their selected/delegated store after the direct stores above are fixed.
- Add tests where needed; do not add source changes unless the audit exposes incorrect behavior.

### 8. Update Swoole cache documentation

Edit `src/boost/docs/cache.md`:

- Change the `interval_refresh_interval` prose from manager-process refreshes to refreshes by the elected worker.
- Keep the config comments unchanged; they describe intervals, not process ownership.

### 9. Rename stale interval test language

Edit `tests/Cache/CacheSwooleStoreIntervalTest.php`:

- Rename methods and variables that call the refresher instance `managerStore`.
- Use names such as `refresherStore`, `readerStore`, or `timerStore` to match the final worker-timer design.
- Keep the assertions unchanged unless the implementation change requires a more precise assertion.

## Testing Plan

Run each focused test file after editing it, then run `composer fix`.

### Timer tests

Update `tests/Cache/CreateSwooleTimersTest.php`:

- Replace `OnManagerStart` with `AfterWorkerStart`.
- Add a helper to create real `AfterWorkerStart` events with a Mockery `Swoole\Server` mock.
- Add a test proving timers are registered on non-task worker `0`.
- Add a test proving timers are not registered on worker `1`.
- Add a test proving timers are not registered on task workers by setting `$server->taskworker = true` on the mock server.
- Keep the callback test proving one tick calls `evictRecords()` and the other calls `refreshIntervalCaches()`.
- Keep the interval values test for configured and default timer intervals.

Useful test shape:

```php
use Hypervel\Core\Events\AfterWorkerStart;
use Swoole\Server as SwooleServer;

private function workerEvent(int $workerId, bool $taskworker = false): AfterWorkerStart
{
    $server = m::mock(SwooleServer::class);
    $server->taskworker = $taskworker;

    return new AfterWorkerStart($server, $workerId);
}
```

Focused command:

```sh
./vendor/bin/phpunit --no-progress tests/Cache/CreateSwooleTimersTest.php
```

### Heap tests

Add `tests/Cache/LimitedMaxHeapTest.php`:

- Invalid limits below `1` throw `InvalidArgumentException`.
- Full heap plus smaller value replaces the current max and retains the expected smallest values.
- Full heap plus larger value is discarded.
- Ascending input retains exactly `k` smallest values.
- Descending input retains exactly `k` smallest values.
- Shuffled input retains exactly `k` smallest values.

Focused command:

```sh
./vendor/bin/phpunit --no-progress tests/Cache/LimitedMaxHeapTest.php
```

### Eviction tests

Extend `tests/Cache/CacheSwooleStoreTest.php`:

- Add single-pass policy eviction tests for LRU, LFU, and TTL with `eviction_proportion < 1.0`. Invoke `removeRecordsByEvictionPolicy()` through a probe subclass instead of calling `evictRecords()`, because `evictRecords()` loops until memory pressure clears and can legitimately remove several batches in one call.
- Assert the single pass removes exactly the expected lowest-ranked records and preserves higher-ranked records.
- Add mutated-candidate recheck tests using a test subclass that exposes the protected fingerprint and candidate-forget helpers:
  - capture a candidate fingerprint;
  - perform one real mutation;
  - call the candidate-forget helper with the stale fingerprint;
  - assert the helper returns `false` and the row survives.
- Cover mutations from:
  - `put()`, where the value hash changes;
  - `increment()`, where the value hash changes while expiration and hit metadata may remain unchanged;
  - an LRU/LFU hit, where hit metadata changes.
- Add `recordHit()` phantom-row behavior tests for both LRU and LFU:
  - simulate a row read by calling the protected method through a test subclass or reflection after deleting the row;
  - assert Swoole may create a row with empty value and `expiration = 0.0`;
  - assert `get()` returns `null` and prunes the row.
- Add `putMany()` tests:
  - successful multi-write still stores all values;
  - empty input returns `true`;
  - partial failure returns `false` while still attempting every item. Use a SwooleStore test subclass whose `put()` fails for one selected key and records all attempted keys.

Useful candidate-recheck test shape:

```php
class EvictionCandidateProbeSwooleStore extends SwooleStore
{
    public function removeOnePolicyBatch(): int
    {
        return $this->removeRecordsByEvictionPolicy();
    }

    public function userTableKey(string $key): string
    {
        return $this->userKey($key);
    }

    public function fingerprintFor(array $record): array
    {
        return $this->evictionFingerprint($record);
    }

    public function forgetCandidate(string $tableKey, array $fingerprint): bool
    {
        return $this->forgetEvictionCandidate($tableKey, $fingerprint);
    }
}
```

Then each mutation test should capture `fingerprintFor($row)`, mutate once through the public behavior being covered, and assert `forgetCandidate($tableKey, $staleFingerprint)` returns `false`.

Focused command:

```sh
./vendor/bin/phpunit --no-progress tests/Cache/CacheSwooleStoreTest.php
```

### Interval tests

Extend `tests/Cache/CacheSwooleStoreIntervalTest.php`:

- Rename existing `managerStore` test methods and variables to avoid describing the refresher as manager-owned after timers move to worker `0`.
- Add stale-overwriter regression:
  - refresher A claims and resolver returns slowly;
  - after the stale timeout, refresher B reclaims and writes value `B`;
  - A returns value `A`;
  - final public value remains `B`;
  - metadata remains completed for B, not A;
  - `lastRefreshedAt` records B's commit timestamp.
- Add a test where the first claim is lost before commit and the resolver result is not written.
- Add a test proving slow successful refreshes use commit-time cadence: advance `Carbon::setTestNow()` between claim and commit, then assert `lastRefreshedAt` equals the commit timestamp.
- Add a test for the `intervalClaimIsStale()` branch where `refreshInterval * 2` is greater than the 300-second floor.
- Add a stderr fallback test for `reportIntervalException()` when no `ExceptionHandler` binding exists. Use a temporary PHP script launched with `Symfony\Component\Process\Process`, following the subprocess style in `tests/Filesystem/FilesystemNonCoroutineTest.php`, so stderr can be asserted through `$process->getErrorOutput()` without trying to intercept `php://stderr` inside the PHPUnit process.
- Keep existing tests for first-tick refresh, shared index discovery, same-instance fallback, exception reporting, claim clearing, and metadata/index preservation.

Useful stale-overwriter test shape:

```php
Carbon::setTestNow('2000-01-01 00:00:00');

$state = $this->createState();
$workerStore = $this->createStore($state);
$refresherStore = $this->createStore($state);

IntervalReentryProbe::$attempts = 0;
IntervalReentryProbe::$refresherStore = $refresherStore;

$workerStore->interval('foo', function () {
    ++IntervalReentryProbe::$attempts;

    if (IntervalReentryProbe::$attempts === 1) {
        Carbon::setTestNow('2000-01-01 00:05:01');
        IntervalReentryProbe::$refresherStore->refreshIntervalCaches();

        return 'A';
    }

    return 'B';
}, 5);

$workerStore->refreshIntervalCaches();

$this->assertSame('B', $workerStore->get('foo'));
$this->assertSame(Carbon::parse('2000-01-01 00:05:01')->getPreciseTimestamp(6) / 1000000, $this->metadata($state, $this->metadataKey($workerStore, 'foo'))['lastRefreshedAt']);
```

The static probe avoids serializing the refresher store inside the interval closure.

```php
class IntervalReentryProbe
{
    public static int $attempts = 0;

    public static ?SwooleStore $refresherStore = null;

    public static function reset(): void
    {
        self::$attempts = 0;
        self::$refresherStore = null;
    }
}
```

Call `IntervalReentryProbe::reset()` before and after the stale-overwriter test so the static store reference does not persist between tests.

Useful stderr fallback test shape:

```php
$scriptPath = $tempDir . '/interval-stderr.php';

file_put_contents($scriptPath, <<<'PHP'
<?php
require $argv[1];

use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Container\Container;
use RuntimeException;
use Throwable;

class ReportIntervalExceptionProbeStore extends SwooleStore
{
    public function report(Throwable $e): void
    {
        $this->reportIntervalException($e);
    }
}

Container::setInstance(new Container);

$state = (new SwooleTableManager(new Container))->createState(8, 1024, 0.2, 12345);
$store = new ReportIntervalExceptionProbeStore($state, 0.05, SwooleStore::EVICTION_POLICY_TTL, 0.05);
$store->report(new RuntimeException('refresh failed'));
PHP);

$process = new Process([PHP_BINARY, $scriptPath, dirname(__DIR__, 2) . '/vendor/autoload.php']);
$process->mustRun();

$this->assertStringContainsString('refresh failed', $process->getErrorOutput());
```

Focused command:

```sh
./vendor/bin/phpunit --no-progress tests/Cache/CacheSwooleStoreIntervalTest.php
```

### Shared fallback `putMany()` tests

Add or extend the cache tests covering `RetrievesMultipleKeys`:

- Empty array returns `true`.
- One failed write returns `false`.
- Later writes are still attempted after an earlier failure.
- The tested behavior applies to `AbstractArrayStore`, `FileStore`, `NullStore`, and `SessionStore` because they use the trait.

Use a tiny inline test store that uses `RetrievesMultipleKeys` and records `put()` calls.

Useful fallback store shape:

```php
class RetrievesMultipleKeysPutManyProbe
{
    use RetrievesMultipleKeys;

    public array $calls = [];

    public function __construct(private array $failures = [])
    {
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        $this->calls[] = $key;

        return ! in_array($key, $this->failures, true);
    }
}
```

Focused command depends on placement:

```sh
./vendor/bin/phpunit --no-progress tests/Cache/CacheRetrievesMultipleKeysTest.php
```

### Other native `putMany()` tests

Extend the existing store-specific tests where the store owns a native `putMany()` implementation:

- `tests/Cache/CacheDatabaseStoreTest.php`
  - empty `putMany([])` returns `true` and does not call `upsert()`;
  - non-empty `putMany()` returns `true` when `upsert()` returns `0`, proving successful no-op upserts are not reported as failures.
  - `put()` returns `true` when its delegated `upsert()` returns `0`, proving identical single-key writes are not reported as failures.
- `tests/Cache/CacheStackStoreTest.php`
  - empty `putMany([])` returns `true`;
  - a failed key returns `false` but later keys are still attempted.
- `MemoizedStore`
  - extend `tests/Cache/CacheMemoizedStoreTest.php` with an empty-input assertion that proves it returns the delegated repository result and keeps non-empty invalidation behavior intact.
- `FailoverStore`
  - extend `tests/Integration/Cache/FailoverStoreTest.php` with an empty-input assertion that proves it returns the selected store's result.

Focused commands depend on placement:

```sh
./vendor/bin/phpunit --no-progress tests/Cache/CacheDatabaseStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheStackStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheMemoizedStoreTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Cache/FailoverStoreTest.php
```

### Static analysis and full verification

After focused tests pass:

```sh
composer fix
```

This runs CS Fixer, PHPStan, and `composer test:parallel`.

## Self-Review Checklist For Implementation

- Confirm `CreateSwooleTimers` cannot register timers from manager or task workers.
- Confirm worker `0` registers exactly two timers per configured Swoole store and no timers for non-Swoole stores.
- Confirm the eviction timer still calls `evictRecords()` directly.
- Confirm `evictRecords()` still flushes stale records before checking memory pressure and policy.
- Confirm `LimitedMaxHeap` never grows beyond its limit.
- Confirm `LimitedMaxHeap` rejects invalid limits.
- Confirm single-pass eviction tests call `removeRecordsByEvictionPolicy()` through a probe, not `evictRecords()`.
- Confirm eviction candidates no longer store full serialized values in the heap.
- Confirm candidate fingerprints include `value_hash`; tests must fail if the value hash is removed.
- Confirm `evictionFingerprint()` is placed near the eviction helpers.
- Confirm no `withRowLock()` callback calls a public method that can acquire another row lock unless the row order is explicitly safe.
- Confirm interval resolver execution and public value serialization remain outside metadata row locks.
- Confirm `prepareIntervalRefreshCommit()` is placed near the interval refresh helpers.
- Confirm successful interval refreshes use completion-time cadence after restamp.
- Confirm stale interval commit loss returns without clearing another worker's claim.
- Confirm failed public interval writes clear only the restamped claim owned by the failing refresher.
- Confirm lock acquire has no extra work after a successful first CAS.
- Confirm no owner-token or stale-owner stealing logic is added.
- Confirm `recordHit()` still does not acquire row locks.
- Confirm the `recordHit()` comment explains the Swoole phantom-row trade-off without overstating risk.
- Confirm `putMany()` methods attempt every key and return `false` if any write fails.
- Confirm empty `putMany([])` returns `true` for SwooleStore and the shared fallback.
- Confirm `DatabaseStore`, `StackStore`, `MemoizedStore`, and `FailoverStore` have correct empty-batch semantics after the audit.
- Confirm `isUserKey()` is gone and no references remain.
- Confirm interval tests no longer use manager-process naming for worker-refresh behavior.
- Confirm `src/boost/docs/cache.md` no longer says interval refreshes run in the manager process.
- Confirm new comments explain WHY rather than narrating obvious code.

## Documentation / PR Notes

The PR description should be updated after implementation:

- Mention that timers now run from the elected worker, not the manager process.
- Mention that policy eviction now honors `eviction_proportion`.
- Mention the compact eviction fingerprint as both a correctness recheck and a memory-pressure improvement.
- Mention the interval stale-overwriter fix.
- Mention that row-lock contention now backs off after failed spin bursts without changing the uncontended path.
- Mention `putMany()` result semantics if it is relevant to the summary or changelog.
- Mention the timer process wording change in `src/boost/docs/cache.md` if the PR body has a docs section.
- Re-run SwooleStore microbenchmarks after implementation and update the PR table if the measured numbers change.

## Final Verification

Before asking for code review after implementation:

1. Run all focused tests listed above.
2. Run `composer fix`.
3. Re-read `SwooleStore`, `SwooleTableState`, `LimitedMaxHeap`, `CreateSwooleTimers`, `CacheServiceProvider`, and `RetrievesMultipleKeys` in full.
4. Trace the interval refresh path through claim, resolver, commit prep, public write, completion, and catch cleanup.
5. Trace eviction through scan, heap retention, fingerprint, lock recheck, and delete.
6. Trace timer registration from service provider boot to `AfterWorkerStart`.
7. Trace every edited `putMany()` path and confirm empty batches are successful no-ops.
8. Confirm there is no dead helper, stale comment, or documentation that describes the old manager-timer behavior.
