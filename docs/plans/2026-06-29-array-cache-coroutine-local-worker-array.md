# Array Cache Request-Local Store and Worker-Array Store

## Goal

Make Hypervel's `array` cache store behave like Laravel developers expect in a long-lived Swoole worker: data written to the `array` store must be isolated to the current request, job, scheduled task, or other unit of work.

Preserve the current worker-lifetime in-memory behavior as an explicit `worker-array` store for the cases where that behavior is intended. The final code should read as if Hypervel had designed these two stores from the start:

- `array`: request-local scratch cache, implemented with coroutine context in Hypervel.
- `worker-array`: worker-lifetime in-memory cache, shared by all coroutines in the same worker process.

The public behavior should be clean, documented, and tested across values, locks, tags, manager resolution, configuration, and coroutine isolation.

## Research

### Current Hypervel code

Files checked:

- `src/cache/src/ArrayStore.php`
- `src/cache/src/ArrayLock.php`
- `src/cache/src/CacheManager.php`
- `src/cache/src/TaggableStore.php`
- `src/cache/src/TagSet.php`
- `src/cache/src/RetrievesMultipleKeys.php`
- `src/contracts/src/Cache/Store.php`
- `src/contracts/src/Cache/LockProvider.php`
- `src/contracts/src/Cache/CanFlushLocks.php`
- `src/foundation/config/cache.php`
- `src/testbench/hypervel/config/cache.php`
- `src/boost/docs/cache.md`
- `src/cache/README.md`
- `tests/Cache/CacheArrayStoreTest.php`
- `tests/Cache/CacheTaggedCacheTest.php`
- `tests/Cache/CacheManagerTest.php`
- `tests/Inertia/CoroutineIsolationTest.php`
- `tests/Container/CoroutineSafetyTest.php`
- `src/coroutine/src/functions.php`
- `src/coroutine/src/Parallel.php`
- `src/coroutine/src/Coroutine.php`
- `src/context/src/CoroutineContext.php`
- `contrib/hypervel/hypervel/config/cache.php`

Current `ArrayStore` keeps values and locks on object properties:

```php
/**
 * @var array<string, array{value: mixed, expiresAt: float}>
 */
protected array $storage = [];

/**
 * @var array<string, array{owner: ?string, expiresAt: ?Carbon}>
 */
public array $locks = [];
```

`CacheManager` is a singleton. It memoizes resolved repositories in `$stores`, so a configured `array` store is held for the worker lifetime:

```php
public function store(?string $name = null): CacheRepository
{
    $name = $name ?: $this->getDefaultDriver();

    return $this->stores[$name] ??= $this->resolve($name);
}
```

That means current `array` cache data persists across requests in the same worker. This is not the behavior Laravel developers expect from the `array` cache store.

### Laravel reference

Files checked:

- `examples/laravel/framework/src/Illuminate/Cache/ArrayStore.php`
- `examples/laravel/framework/src/Illuminate/Cache/ArrayLock.php`
- `examples/laravel/framework/src/Illuminate/Cache/CacheManager.php`
- `examples/laravel/framework/config/cache.php`

Laravel's `ArrayStore` also stores values and locks on object properties. In normal Laravel, PHP process teardown resets those properties after each request. Hypervel does not have that reset boundary, so copying Laravel's storage shape directly is incorrect under Swoole.

Laravel's default failover config is still:

```php
'failover' => [
    'driver' => 'failover',
    'stores' => [
        'database',
        'array',
    ],
],
```

Hypervel should keep that failover shape. If the fallback is reached, `array` should behave like a request-local fallback, not a worker-lifetime cache.

### Coroutine context behavior

`CoroutineContext` is the correct Hypervel primitive for request/job/task-local mutable state. Values are isolated by coroutine. Child coroutines start with a fresh context unless the caller opts into copying context with APIs such as `parallel(..., copyContext: true)`, `co(..., copyContext: true)`, `go(..., copyContext: true)`, or `Coroutine::fork()`.

`CoroutineContext::copyFrom()` copies arrays by value. Object values are shared by reference unless they implement `ReplicableContext`. Therefore the array cache buckets should be plain arrays, not `ArrayObject` or a mutable DTO. Plain arrays preserve isolation when copied into child coroutines.

This preserves the existing `ArrayStore` object-value semantics. When `serialize` is `false`, cached objects are stored as PHP object references; if copied context contains an object value, that object reference is still the same PHP object unless the cache value is overwritten. When `serialize` is `true`, values round-trip through serialization and this reference sharing does not occur.

Outside a coroutine, `CoroutineContext` uses its normal non-coroutine fallback. Hypervel request, queue, and scheduler work runs inside coroutines; one-off CLI processes exit after running. Do not add an Octane-style end-of-request flusher around the array store.

## Decisions

### `array` becomes request-local through coroutine context

`ArrayStore` remains the public class for the `array` driver, but its mutable values and locks move from object properties to `CoroutineContext`.

Each `ArrayStore` instance needs its own context key suffix. A class-wide context key would make two manually-created stores share data inside one coroutine, which would be a behavior regression from both Hypervel's current implementation and Laravel's `new ArrayStore` behavior.

Use a private monotonic counter for the suffix. Do not use `spl_object_id($this)`: PHP can reuse an object ID immediately after the object is destroyed, while the old context bucket can still exist until coroutine teardown. Do not use a single shared context key.

The manager-cached `array` store remains the same object for the worker lifetime, but that object reads and writes the current coroutine's bucket. This gives same-store behavior within a request while preventing cross-request bleed.

### `worker-array` preserves current worker-lifetime behavior

Add `WorkerArrayStore` for deliberate worker-lifetime in-memory storage. It uses object properties for values and locks, matching the current `ArrayStore` behavior.

This store should not use static properties. Worker persistence already comes from `CacheManager::$stores`, which holds the resolved repository/store in the worker-lifetime container. Static state would collapse isolation between multiple configured `worker-array` stores and would require unnecessary test cleanup.

### Shared behavior lives in an abstract base class

Add an `AbstractArrayStore` base class to keep the public cache behavior in one place and separate it from the storage backend.

`ArrayStore` and `WorkerArrayStore` will differ only in how they read and write value and lock records. This avoids duplicating TTL, serialization, lock, tag, and increment/decrement logic.

### Locks use store methods, not public lock arrays

Remove `public array $locks` from the public store surface. `ArrayLock` should call store methods instead of reaching into a public property:

```php
$record = $this->store->getLockRecord($this->name);
$this->store->putLockRecord($this->name, [
    'owner' => $this->owner,
    'expiresAt' => $this->seconds === 0 ? null : Carbon::now()->addSeconds($this->seconds),
]);
```

`ArrayLock` remains the lock implementation for both `array` and `worker-array`, because the lock algorithm is the same. The backing state is store-specific.

### Tags are normal cache entries

Do not add a separate tag bucket. `TagSet` stores tag markers through the normal store methods:

```php
$this->store->forever($this->tagKey($name), $id = str_replace('.', '', uniqid('', true)));
```

For `array`, tag markers belong in the request-local value bucket. For `worker-array`, tag markers belong in the worker-lifetime value bucket.

### `flush()` and `flushLocks()` stay separate

Both array-family stores implement `CanFlushLocks` and return `true` from `hasSeparateLockStore()`.

`flush()` clears values and tag markers only.

`flushLocks()` clears locks only.

### Multi-word driver names should resolve cleanly

Change `CacheManager::build()` from `ucfirst($config['driver'])` to `Str::studly($config['driver'])`. This maps `worker-array` to `createWorkerArrayDriver()` and also makes the manager match Hypervel's existing `Support\Manager` behavior.

Custom creators still take precedence, so applications can still override built-in driver names through `CacheManager::extend()`.

### Config defaults

Update the foundation config and app skeleton config:

- Add `worker-array` to the supported driver comment.
- Add a `worker-array` store entry immediately after `array`.
- Keep the default cache store as `database`.
- Keep failover stores as `['database', 'array']`.

Do not add `worker-array` to `src/testbench/hypervel/config/cache.php`. Testbench merges framework defaults and that file only contains Testbench-specific overrides. Its default cache store should remain `array` so tests use the request-local store unless a test explicitly asks for `worker-array`.

### Current `array` usage audit

The existing codebase has been audited for configured and direct `array` cache usage.

#### Production source

Change this source use to `worker-array`:

```php
// src/reverb/src/Protocols/Pusher/Server.php
$this->rateLimiter ??= new RateLimiter(app('cache')->store('array'));
```

Reverb's Pusher server caches the `RateLimiter` instance on the server object and uses it to limit websocket messages from a connection. That counter must persist across multiple messages handled by the same worker. After `array` becomes request-local, this line must become:

```php
$this->rateLimiter ??= new RateLimiter(app('cache')->store('worker-array'));
```

No other production source path directly asks for `Cache::store('array')`, `app('cache')->store('array')`, or `new ArrayStore` except `CacheManager::createArrayDriver()`, which is the driver factory itself.

These non-cache `array` references are not part of this change:

- `src/testbench/hypervel/config/session.php` uses the array session driver, not the cache array driver.
- Package docs and Testbench docs that show `cache.default = array` remain valid examples of request-local test cache.

#### Tests

Do not bulk-convert existing tests from `array` to `worker-array`.

The current test references fall into these resolved categories:

- Cache package tests that instantiate `new ArrayStore` or configure `driver => array` are testing the `array` store and should stay on `array`: `tests/Cache/CacheArrayStoreTest.php`, `CacheTaggedCacheTest.php`, `CacheMemoizedStoreTest.php`, `CacheRepositoryTest.php`, `CacheStackStoreTest.php`, `CacheManagerTest.php`, `ConcurrencyLimiterTest.php`, `CacheEventsTest.php`, `CacheRateLimiterTest.php`, `RateLimiterTest.php`, and `CacheRepositoryEnumTest.php`.
- Scheduling mutex tests pass mocked repositories or per-test repositories and should stay on `array`: `tests/Console/Scheduling/CacheSchedulingMutexTest.php`, `tests/Console/Scheduling/CacheEventMutexTest.php`, `tests/Integration/Console/CallbackSchedulingTest.php`, `tests/Integration/Console/CommandSchedulingTest.php`, and `tests/Integration/Console/Scheduling/SubMinuteSchedulingTest.php`.
- Failover tests should keep `database`, `array` semantics because failover remains Laravel-compatible and request-local on fallback: `tests/Integration/Cache/FailoverStoreTest.php`.
- Rate limiter, queue, Sanctum, Permission, Telescope, Foundation, and Testing tests use `array` as a cheap in-test cache inside one test/application context. They are not intentionally testing worker-lifetime persistence and should stay on `array`: `tests/Integration/Queue/RateLimitedTest.php`, `tests/Integration/Queue/DebouncedJobTest.php`, `tests/Sanctum/PersonalAccessTokenCacheTest.php`, `tests/Permission/TestCase.php`, `tests/Telescope/FeatureTestCase.php`, `tests/Telescope/Watchers/DisabledWatcherTest.php`, `tests/Queue/QueuePauseResumeTest.php`, `tests/Foundation/FoundationExceptionsHandlerTest.php`, `tests/Foundation/Testing/Concerns/InteractsWithSessionTest.php`, `tests/Foundation/FoundationApplicationTest.php`, and `tests/Testing/Concerns/TestCachesTest.php`.
- `tests/Foundation/Testing/Concerns/InteractsWithSessionTest.php` clones the array repository for a cache-backed session handler and uses it inside one test context. It should stay on `array`; this test also covers the Testbench session-over-array-cache interaction.
- `tests/Integration/Queue/DebouncedJobTest.php` explicitly verifies a user-selected debounce store through `->store('array')` and a same-test cache assertion. It should stay on `array`.
- New tests added by this work will explicitly use `WorkerArrayStore` or `driver => worker-array` when proving worker-lifetime behavior.

These decisions are final for the existing references. Do not blanket-convert existing test caches to `worker-array`.

The resolved audit says the tests categorized above stay on `array`. If a red test contradicts that audit, stop and report the root cause. Determine whether the failure reveals a genuine worker-shared or cross-coroutine dependency, in which case that specific usage should move to `worker-array`, or whether it exposes a different bug. Do not weaken the test or blindly switch stores without understanding why it failed.

### Documentation

Update `src/boost/docs/cache.md` and `src/cache/README.md`.

Docs should focus on behavior first:

- `array` is per-request / per-unit-of-work in Hypervel.
- In HTTP requests, queue jobs, and scheduled tasks, values written to `array` disappear when that work finishes.
- Hypervel implements this with coroutine context, so advanced coroutine behavior follows normal context-copying rules.
- `worker-array` is a worker-lifetime in-memory store.
- `worker-array` is not shared across worker processes, servers, or restarts.
- Use `worker-array` only when worker-local persistence is intended.

Do not frame this as an Octane-style flush. Hypervel's correct reset boundary is coroutine teardown, not flushing shared worker state after a request.

### Request-local bucket tradeoff

The request-local `array` store should keep one plain-array bucket for values and one plain-array bucket for locks. Mutating a value reads the bucket, changes one entry, and writes the bucket back into `CoroutineContext`.

This makes `putMany()` a loop of bucket rewrites through `RetrievesMultipleKeys`, but `array` is a request-local scratch cache and this keeps `all()` and `flush()` simple and correct. Do not split values into one context key per cache key.

## Implementation Plan

### 1. Add `AbstractArrayStore`

Create `src/cache/src/AbstractArrayStore.php`.

The base class owns all shared cache behavior and delegates mutable state to abstract storage methods:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Support\Carbon;
use Hypervel\Support\InteractsWithTime;
use RuntimeException;

abstract class AbstractArrayStore extends TaggableStore implements CanFlushLocks, LockProvider
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

    protected bool $serializesValues;

    protected array|bool|null $serializableClasses;

    public function __construct(bool $serializesValues = false, array|bool|null $serializableClasses = null)
    {
        $this->serializesValues = $serializesValues;
        $this->serializableClasses = $serializableClasses;
    }

    /**
     * Get all of the cached values and their expiration times.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    public function all(bool $unserialize = true): array
    {
        $storage = $this->getCacheItems();

        if ($unserialize === false || $this->serializesValues === false) {
            return $storage;
        }

        foreach ($storage as $key => $data) {
            $storage[$key] = [
                'value' => $this->unserialize($data['value']),
                'expiresAt' => $data['expiresAt'],
            ];
        }

        return $storage;
    }

    public function get(string $key): mixed
    {
        $item = $this->getCacheItem($key);

        if ($item === null) {
            return null;
        }

        $expiresAt = $item['expiresAt'];

        if ($expiresAt !== 0.0 && (now()->getPreciseTimestamp(3) / 1000) >= $expiresAt) {
            $this->forget($key);

            return null;
        }

        return $this->serializesValues ? $this->unserialize($item['value']) : $item['value'];
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        $this->putCacheItem($key, [
            'value' => $this->serializesValues ? serialize($value) : $value,
            'expiresAt' => $this->calculateExpiration($seconds),
        ]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        // WorkerArrayStore shares this read/modify/write path across coroutines; keep it non-yielding.
        if (! is_null($existing = $this->get($key))) {
            $incremented = ((int) $existing) + $value;

            /** @var array{value: mixed, expiresAt: float} $item */
            $item = $this->getCacheItem($key);
            $item['value'] = $this->serializesValues ? serialize($incremented) : $incremented;
            $this->putCacheItem($key, $item);

            return $incremented;
        }

        $this->forever($key, $value);

        return $value;
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, $value * -1);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function touch(string $key, int $seconds): bool
    {
        $key = $this->getPrefix() . $key;
        $item = $this->getCacheItem($key);

        if ($item === null) {
            return false;
        }

        $item['expiresAt'] = $this->calculateExpiration($seconds);
        $this->putCacheItem($key, $item);

        return true;
    }

    public function forget(string $key): bool
    {
        return $this->forgetCacheItem($key);
    }

    public function flush(): bool
    {
        $this->clearCacheItems();

        return true;
    }

    public function flushLocks(): bool
    {
        if (! $this->hasSeparateLockStore()) {
            throw new RuntimeException('Flushing locks is only supported when the lock store is separate from the cache store.');
        }

        $this->clearLockRecords();

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): ArrayLock
    {
        return new ArrayLock($this, $name, $seconds, $owner);
    }

    public function restoreLock(string $name, string $owner): ArrayLock
    {
        return $this->lock($name, 0, $owner);
    }

    public function hasSeparateLockStore(): bool
    {
        return true;
    }

    /**
     * Get the lock record for the given name.
     *
     * @return array{owner: ?string, expiresAt: ?Carbon}|null
     */
    abstract public function getLockRecord(string $name): ?array;

    /**
     * Store the lock record for the given name.
     *
     * @param array{owner: ?string, expiresAt: ?Carbon} $record
     */
    abstract public function putLockRecord(string $name, array $record): void;

    /**
     * Remove the lock record for the given name.
     */
    abstract public function forgetLockRecord(string $name): void;

    /**
     * Remove all lock records.
     */
    abstract public function clearLockRecords(): void;

    /**
     * Get the cached item for the given key.
     *
     * @return array{value: mixed, expiresAt: float}|null
     */
    abstract protected function getCacheItem(string $key): ?array;

    /**
     * Store the cached item for the given key.
     *
     * @param array{value: mixed, expiresAt: float} $item
     */
    abstract protected function putCacheItem(string $key, array $item): void;

    /**
     * Remove the cached item for the given key.
     */
    abstract protected function forgetCacheItem(string $key): bool;

    /**
     * Remove all cached items.
     */
    abstract protected function clearCacheItems(): void;

    /**
     * Get all cached items.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    abstract protected function getCacheItems(): array;

    protected function calculateExpiration(int $seconds): float
    {
        return $this->toTimestamp($seconds);
    }

    protected function toTimestamp(int $seconds): float
    {
        return $seconds > 0 ? (now()->getPreciseTimestamp(3) / 1000) + $seconds : 0;
    }

    protected function unserialize(string $value): mixed
    {
        if ($this->serializableClasses !== null) {
            return unserialize($value, ['allowed_classes' => $this->serializableClasses]);
        }

        return unserialize($value);
    }
}
```

The final code must use Laravel-style title docblocks on all methods. The snippet above shows the shape; add the short method docblocks where needed.

### 2. Rebuild `ArrayStore` as a coroutine-context store

Replace `ArrayStore` with a small concrete class:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Context\CoroutineContext;
use Hypervel\Support\Carbon;

class ArrayStore extends AbstractArrayStore
{
    protected const STORAGE_CONTEXT_KEY_PREFIX = '__cache.array.storage.';

    protected const LOCKS_CONTEXT_KEY_PREFIX = '__cache.array.locks.';

    private static int $contextKeySequence = 0;

    protected readonly string $storageContextKey;

    protected readonly string $locksContextKey;

    public function __construct(bool $serializesValues = false, array|bool|null $serializableClasses = null)
    {
        parent::__construct($serializesValues, $serializableClasses);

        $suffix = (string) ++self::$contextKeySequence;

        $this->storageContextKey = self::STORAGE_CONTEXT_KEY_PREFIX . $suffix;
        $this->locksContextKey = self::LOCKS_CONTEXT_KEY_PREFIX . $suffix;
    }

    protected function getCacheItem(string $key): ?array
    {
        return $this->getCacheItems()[$key] ?? null;
    }

    protected function putCacheItem(string $key, array $item): void
    {
        $items = $this->getCacheItems();
        $items[$key] = $item;

        CoroutineContext::set($this->storageContextKey, $items);
    }

    protected function forgetCacheItem(string $key): bool
    {
        $items = $this->getCacheItems();

        if (! array_key_exists($key, $items)) {
            return false;
        }

        unset($items[$key]);
        CoroutineContext::set($this->storageContextKey, $items);

        return true;
    }

    protected function clearCacheItems(): void
    {
        CoroutineContext::set($this->storageContextKey, []);
    }

    protected function getCacheItems(): array
    {
        /** @var array<string, array{value: mixed, expiresAt: float}> $items */
        $items = CoroutineContext::get($this->storageContextKey, []);

        return $items;
    }

    public function getLockRecord(string $name): ?array
    {
        return $this->getLockRecords()[$name] ?? null;
    }

    public function putLockRecord(string $name, array $record): void
    {
        $records = $this->getLockRecords();
        $records[$name] = $record;

        CoroutineContext::set($this->locksContextKey, $records);
    }

    public function forgetLockRecord(string $name): void
    {
        $records = $this->getLockRecords();

        unset($records[$name]);

        CoroutineContext::set($this->locksContextKey, $records);
    }

    public function clearLockRecords(): void
    {
        CoroutineContext::set($this->locksContextKey, []);
    }

    protected function getLockRecords(): array
    {
        /** @var array<string, array{owner: ?string, expiresAt: ?Carbon}> $records */
        $records = CoroutineContext::get($this->locksContextKey, []);

        return $records;
    }
}
```

Use plain arrays in context. Do not store `ArrayObject` or a custom bucket object, because copied coroutine context would share object references unless the object implemented `ReplicableContext`.

Do not add `flushState()`. The store has no static cache data. The private static counter only creates unique context-key suffixes; resetting it could reintroduce collisions with live context buckets and provides no cleanup benefit.

### 3. Add `WorkerArrayStore`

Create `src/cache/src/WorkerArrayStore.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Support\Carbon;

class WorkerArrayStore extends AbstractArrayStore
{
    /**
     * @var array<string, array{value: mixed, expiresAt: float}>
     */
    protected array $storage = [];

    /**
     * @var array<string, array{owner: ?string, expiresAt: ?Carbon}>
     */
    protected array $locks = [];

    protected function getCacheItem(string $key): ?array
    {
        return $this->storage[$key] ?? null;
    }

    protected function putCacheItem(string $key, array $item): void
    {
        $this->storage[$key] = $item;
    }

    protected function forgetCacheItem(string $key): bool
    {
        if (! array_key_exists($key, $this->storage)) {
            return false;
        }

        unset($this->storage[$key]);

        return true;
    }

    protected function clearCacheItems(): void
    {
        $this->storage = [];
    }

    protected function getCacheItems(): array
    {
        return $this->storage;
    }

    public function getLockRecord(string $name): ?array
    {
        return $this->locks[$name] ?? null;
    }

    public function putLockRecord(string $name, array $record): void
    {
        $this->locks[$name] = $record;
    }

    public function forgetLockRecord(string $name): void
    {
        unset($this->locks[$name]);
    }

    public function clearLockRecords(): void
    {
        $this->locks = [];
    }
}
```

The final code should not expose `$locks` publicly. Tests should assert behavior, not internal lock arrays.

### 4. Update `ArrayLock`

Change the store type to `AbstractArrayStore` and replace direct property access with the new store methods:

```php
protected AbstractArrayStore $store;

public function __construct(AbstractArrayStore $store, string $name, int $seconds, ?string $owner = null)
{
    parent::__construct($name, $seconds, $owner);

    $this->store = $store;
}
```

Core changes:

```php
public function acquire(): bool
{
    $record = $this->store->getLockRecord($this->name);
    $expiration = $record['expiresAt'] ?? Carbon::now()->addSecond();

    if ($record !== null && $expiration->isFuture()) {
        return false;
    }

    // WorkerArrayStore shares this check/write path across coroutines; keep it non-yielding.
    $this->store->putLockRecord($this->name, [
        'owner' => $this->owner,
        'expiresAt' => $this->seconds === 0 ? null : Carbon::now()->addSeconds($this->seconds),
    ]);

    return true;
}

protected function exists(): bool
{
    return $this->store->getLockRecord($this->name) !== null;
}

public function forceRelease(): void
{
    $this->store->forgetLockRecord($this->name);
}

protected function getCurrentOwner(): ?string
{
    return $this->store->getLockRecord($this->name)['owner'] ?? null;
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

    $record = $this->store->getLockRecord($this->name);

    if ($record === null) {
        return false;
    }

    if (! $this->isOwnedByCurrentProcess()) {
        return false;
    }

    $record['expiresAt'] = Carbon::now()->addSeconds($seconds);
    $this->store->putLockRecord($this->name, $record);

    return true;
}

public function getRemainingLifetime(): ?float
{
    $record = $this->store->getLockRecord($this->name);

    if ($record === null) {
        return null;
    }

    $expiresAt = $record['expiresAt'];

    if ($expiresAt === null) {
        return null;
    }

    if ($expiresAt->isPast()) {
        return null;
    }

    return (float) Carbon::now()->diffInSeconds($expiresAt);
}
```

Keep Hypervel's current refresh behavior:

- Permanent lock with no explicit TTL returns `true`.
- Explicit non-positive TTL throws `InvalidArgumentException`.
- Missing lock returns `false`.
- Lock owned by another owner returns `false`.
- Refresh updates only an existing lock owned by the current process.

### 5. Update `CacheManager`

Import `Hypervel\Support\Str` and change driver method resolution:

```php
$driverMethod = 'create' . Str::studly($config['driver']) . 'Driver';
```

Add `worker-array` factory immediately after `createArrayDriver()`:

```php
/**
 * Create an instance of the worker-lifetime array cache driver.
 */
protected function createWorkerArrayDriver(array $config): Repository
{
    return $this->repository(new WorkerArrayStore(
        $config['serialize'] ?? false,
        $this->getSerializableClasses($config),
    ), $config);
}
```

Custom creators remain checked before built-in driver methods.

### 6. Update config files

Update `src/foundation/config/cache.php` and `contrib/hypervel/hypervel/config/cache.php`.

Supported drivers comment:

```php
| Supported drivers: "array", "worker-array", "database", "file",
|                    "redis", "swoole", "stack", "session",
|                    "failover", "null"
```

Stores:

```php
'array' => [
    'driver' => 'array',
    'serialize' => false,
],

'worker-array' => [
    'driver' => 'worker-array',
    'serialize' => false,
],
```

Do not change:

```php
'failover' => [
    'driver' => 'failover',
    'stores' => [
        'database',
        'array',
    ],
],
```

Do not add a matching entry to `src/testbench/hypervel/config/cache.php`. Framework config is merged into Testbench config and there is no Testbench-specific override for `worker-array`.

### 7. Update Reverb's intentional worker-lifetime cache usage

Update `src/reverb/src/Protocols/Pusher/Server.php`:

```php
$this->rateLimiter ??= new RateLimiter(app('cache')->store('worker-array'));
```

Add or update Reverb test coverage so this choice is protected. The preferred regression test is in `tests/Reverb/Protocols/Pusher/ServerTest.php` near the existing rate-limit tests:

```php
public function testMessageRateLimiterUsesWorkerLifetimeCacheStore(): void
{
    $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
        'enabled' => true,
        'max_attempts' => 1,
        'decay_seconds' => 60,
        'terminate_on_limit' => false,
    ]);

    $this->server->open($connection = new FakeConnection);

    $this->server->message(
        $connection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'test-channel'],
        ])
    );

    $this->assertTrue(
        $this->app->make('cache')->store('worker-array')->has('reverb:message:' . $connection->id())
    );
    $this->assertFalse(
        $this->app->make('cache')->store('array')->has('reverb:message:' . $connection->id())
    );

    $this->server->message(
        $connection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'test-channel-overflow'],
        ])
    );

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4301,
            'message' => 'Rate limit exceeded',
        ]),
    ]);

    $this->assertFalse($connection->wasTerminated);
}
```

This test checks behavior through the public cache repository surface and proves the rate-limit counter accumulates across messages. It does not mock internal cache manager calls and does not depend on private `Server` state.

### 8. Update docs

#### `src/boost/docs/cache.md`

Add an "Array Cache Stores" subsection under Configuration, before Driver Prerequisites, and add it to the table of contents.

Proposed text:

````md
<a name="array-cache-stores"></a>
### Array Cache Stores

Hypervel provides two in-memory array cache stores.

The `array` store is per-request. Values written to this store are visible only during the current HTTP request, queued job, scheduled task, or other unit of work. When that work finishes, the values are discarded.

Hypervel implements this with coroutine context. Child coroutines start with a fresh context by default. If you intentionally copy parent context with APIs such as `parallel(..., copyContext: true)` or `Coroutine::fork()`, the child receives the parent array cache's current values as a starting point. Later cache writes remain isolated to that child coroutine. If serialization is disabled and a cached value is an object, normal PHP object-reference behavior still applies to that object.

The `worker-array` store keeps values for the lifetime of the current worker process. Values are shared by all requests, jobs, tasks, and coroutines handled by that worker. They are not shared across worker processes, servers, or restarts.

Use `array` for request-local test and scratch data. Use `worker-array` only when worker-local persistence is the intended behavior:

```php
'stores' => [
    'array' => [
        'driver' => 'array',
        'serialize' => false,
    ],

    'worker-array' => [
        'driver' => 'worker-array',
        'serialize' => false,
    ],
],
```
````

Update the configuration introduction to list both stores accurately.

#### `src/cache/README.md`

Extend "Differences From Laravel":

```md
The `array` cache store is request-local in Hypervel. Laravel can keep array-store values on the store object because the PHP process normally ends after each request; Hypervel workers are long-lived, so mutable array-store data lives in `CoroutineContext` and resets when the current unit of work finishes.

Hypervel also provides a `worker-array` cache store for deliberate worker-lifetime in-memory cache data. It is shared by coroutines in the same worker process and is cleared when that worker exits.
```

Keep the existing `Cache::memo()` difference.

## Test Plan

Run tests immediately after updating each test file, then run `composer fix` after the implementation is complete.

### `tests/Cache/CacheArrayStoreTest.php`

Update tests that reference internals:

- Replace `assertEmpty($store->locks)` with behavior assertions:
  - acquire two locks,
  - call `flushLocks()`,
  - assert both lock names can be acquired again,
  - assert cached values are unaffected.

Add tests:

```php
public function testSeparateArrayStoreInstancesDoNotShareContextData(): void
{
    $first = new ArrayStore;
    $second = new ArrayStore;

    $first->put('key', 'first', 60);
    $second->put('key', 'second', 60);

    $this->assertSame('first', $first->get('key'));
    $this->assertSame('second', $second->get('key'));
}
```

```php
public function testAllOnlyReturnsCurrentStoreContextData(): void
{
    Carbon::setTestNow(Carbon::now());

    $first = new ArrayStore;
    $second = new ArrayStore;

    $first->put('key', 'first', 60);
    $second->put('key', 'second', 60);

    $this->assertSame(['key' => 'first'], array_map(
        fn (array $item) => $item['value'],
        $first->all()
    ));
    $this->assertSame(['key' => 'second'], array_map(
        fn (array $item) => $item['value'],
        $second->all()
    ));
}
```

Keep all existing value, TTL, serialization, increment/decrement, `touch()`, `all()`, lock ownership, refresh, and remaining-lifetime coverage.

### `tests/Cache/CacheWorkerArrayStoreTest.php`

Add a new unit test file for `WorkerArrayStore`.

Cover the behavior preserved from the current `ArrayStore`:

- stores and retrieves values,
- honors TTL expiry,
- supports serialization and `all(false)`,
- supports increment/decrement,
- supports `touch()`,
- supports locks, restore lock, refresh, and remaining lifetime,
- `flush()` clears values but not locks,
- `flushLocks()` clears locks but not values,
- tags work through normal `TagSet` markers.

Add a coroutine sharing test using `parallel()` and `Hypervel\Engine\Channel`:

```php
public function testWorkerArrayValuesAreSharedAcrossCoroutines(): void
{
    $store = new WorkerArrayStore;
    $written = new Channel(1);

    $results = parallel([
        'writer' => function () use ($store, $written) {
            $store->put('key', 'worker', 60);
            $written->push(true);

            return $store->get('key');
        },
        'reader' => function () use ($store, $written) {
            $written->pop();

            return $store->get('key');
        },
    ]);

    $this->assertSame('worker', $results['writer']);
    $this->assertSame('worker', $results['reader']);
}
```

Add a lock-sharing test:

```php
public function testWorkerArrayLocksAreSharedAcrossCoroutines(): void
{
    $store = new WorkerArrayStore;
    $acquired = new Channel(1);

    $results = parallel([
        'owner' => function () use ($store, $acquired) {
            $result = $store->lock('shared', 60)->acquire();
            $acquired->push(true);

            return $result;
        },
        'contender' => function () use ($store, $acquired) {
            $acquired->pop();

            return $store->lock('shared', 60)->acquire();
        },
    ]);

    $this->assertTrue($results['owner']);
    $this->assertFalse($results['contender']);
}
```

### `tests/Cache/CacheArrayStoreCoroutineIsolationTest.php`

Add a dedicated coroutine isolation test file.

Use `parallel()` and `usleep()` to force interleaving, matching existing coroutine safety tests.

Values:

```php
public function testArrayValuesAreIsolatedBetweenCoroutines(): void
{
    $store = new ArrayStore;

    $results = parallel([
        'first' => function () use ($store) {
            $store->put('key', 'first', 60);
            usleep(1000);

            return $store->get('key');
        },
        'second' => function () use ($store) {
            $store->put('key', 'second', 60);
            usleep(1000);

            return $store->get('key');
        },
    ]);

    $this->assertSame('first', $results['first']);
    $this->assertSame('second', $results['second']);
    $this->assertNull($store->get('key'));
}
```

Locks:

```php
public function testArrayLocksAreIsolatedBetweenCoroutines(): void
{
    $store = new ArrayStore;

    $results = parallel([
        'first' => function () use ($store) {
            $lock = $store->lock('shared', 60);

            return [$lock->acquire(), $lock->isOwnedByCurrentProcess()];
        },
        'second' => function () use ($store) {
            $lock = $store->lock('shared', 60);

            return [$lock->acquire(), $lock->isOwnedByCurrentProcess()];
        },
    ]);

    $this->assertSame([true, true], $results['first']);
    $this->assertSame([true, true], $results['second']);
}
```

Tags:

```php
public function testArrayTagsAreIsolatedBetweenCoroutines(): void
{
    $store = new ArrayStore;

    $results = parallel([
        'first' => function () use ($store) {
            $store->tags('tenant')->put('key', 'first', 60);
            usleep(1000);

            return $store->tags('tenant')->get('key');
        },
        'second' => function () use ($store) {
            $store->tags('tenant')->put('key', 'second', 60);
            usleep(1000);

            return $store->tags('tenant')->get('key');
        },
    ]);

    $this->assertSame('first', $results['first']);
    $this->assertSame('second', $results['second']);
    $this->assertNull($store->tags('tenant')->get('key'));
}
```

Copied context:

```php
public function testCopiedCoroutineContextCopiesArrayCacheValuesWithoutSharingFutureWrites(): void
{
    $store = new ArrayStore;
    $store->put('key', 'parent', 60);

    $results = parallel([
        'child' => function () use ($store) {
            $before = $store->get('key');
            $store->put('key', 'child', 60);

            return [$before, $store->get('key')];
        },
    ], copyContext: true);

    $this->assertSame(['parent', 'child'], $results['child']);
    $this->assertSame('parent', $store->get('key'));
}
```

### `tests/Cache/CacheManagerTest.php`

Add manager tests:

```php
public function testItCanBuildWorkerArrayRepositories(): void
{
    $app = $this->getApp([]);
    $cacheManager = new CacheManager($app);

    $repository = $cacheManager->build(['driver' => 'worker-array']);

    $this->assertInstanceOf(WorkerArrayStore::class, $repository->getStore());
}
```

```php
public function testItResolvesMultiWordInternalDriversUsingStudlyNames(): void
{
    $userConfig = [
        'cache' => [
            'stores' => [
                'worker' => [
                    'driver' => 'worker-array',
                ],
            ],
        ],
    ];

    $cacheManager = new CacheManager($this->getApp($userConfig));

    $this->assertInstanceOf(WorkerArrayStore::class, $cacheManager->store('worker')->getStore());
}
```

```php
public function testCustomCreatorsStillOverrideMultiWordInternalDrivers(): void
{
    $userConfig = [
        'cache' => [
            'stores' => [
                'worker' => [
                    'driver' => 'worker-array',
                ],
            ],
        ],
    ];

    $cacheManager = new CacheManager($this->getApp($userConfig));
    $repository = m::mock(CacheRepository::class);

    $cacheManager->extend('worker-array', fn () => $repository);

    $this->assertSame($repository, $cacheManager->store('worker'));
}
```

### Existing array-backed cache tests

Run the existing array-backed cache tests because the backing store changes under these repositories:

- `tests/Cache/CacheTaggedCacheTest.php`
- `tests/Cache/CacheRepositoryTest.php`
- `tests/Cache/CacheMemoizedStoreTest.php`
- `tests/Cache/CacheStackStoreTest.php`
- `tests/Cache/CacheEventsTest.php`
- `tests/Cache/CacheRateLimiterTest.php`
- `tests/Cache/RateLimiterTest.php`
- `tests/Cache/ConcurrencyLimiterTest.php`
- `tests/Cache/CacheRepositoryEnumTest.php`

`tests/Cache/CacheMemoizedStoreTest.php` verifies that the context-backed `array` store still composes with `MemoizedStore`. No separate memo-specific source change is needed: the memo layer remains per-coroutine and wraps the now per-coroutine `array` repository.

### Cross-package array-backed tests

Run these tests because they use `array` as an in-test cache backend:

- `tests/Console/Scheduling/CacheSchedulingMutexTest.php`
- `tests/Console/Scheduling/CacheEventMutexTest.php`
- `tests/Integration/Console/CallbackSchedulingTest.php`
- `tests/Integration/Console/CommandSchedulingTest.php`
- `tests/Integration/Console/Scheduling/SubMinuteSchedulingTest.php`
- `tests/Integration/Cache/FailoverStoreTest.php`
- `tests/Integration/Queue/RateLimitedTest.php`
- `tests/Integration/Queue/DebouncedJobTest.php`
- `tests/Sanctum/PersonalAccessTokenCacheTest.php`
- `tests/Telescope/Watchers/DisabledWatcherTest.php`
- `tests/Queue/QueuePauseResumeTest.php`
- `tests/Foundation/Testing/Concerns/InteractsWithSessionTest.php`

`tests/Foundation/Testing/Concerns/InteractsWithSessionTest.php` covers the Testbench session-over-array-cache interaction. The session config itself remains unchanged.

### Config and docs checks

After config and docs edits:

- grep `src/foundation/config/cache.php`, `contrib/hypervel/hypervel/config/cache.php`, `src/boost/docs/cache.md`, and `src/cache/README.md` for `worker-array`.
- grep `src/testbench/hypervel/config/cache.php` to confirm no unnecessary duplicate `worker-array` config was added.
- grep for `public array $locks` and `$store->locks` to confirm no direct lock-array surface remains.

### Commands

Run these after each touched test file or logical test group:

```shell
./vendor/bin/phpunit --no-progress tests/Cache/CacheArrayStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheWorkerArrayStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheArrayStoreCoroutineIsolationTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheManagerTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheTaggedCacheTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheRepositoryTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheMemoizedStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheStackStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheEventsTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheRateLimiterTest.php
./vendor/bin/phpunit --no-progress tests/Cache/RateLimiterTest.php
./vendor/bin/phpunit --no-progress tests/Cache/ConcurrencyLimiterTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheRepositoryEnumTest.php
./vendor/bin/phpunit --no-progress tests/Console/Scheduling/CacheSchedulingMutexTest.php
./vendor/bin/phpunit --no-progress tests/Console/Scheduling/CacheEventMutexTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Console/CallbackSchedulingTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Console/CommandSchedulingTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Console/Scheduling/SubMinuteSchedulingTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Cache/FailoverStoreTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Queue/RateLimitedTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Queue/DebouncedJobTest.php
./vendor/bin/phpunit --no-progress tests/Sanctum/PersonalAccessTokenCacheTest.php
./vendor/bin/phpunit --no-progress tests/Telescope/Watchers/DisabledWatcherTest.php
./vendor/bin/phpunit --no-progress tests/Queue/QueuePauseResumeTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/Testing/Concerns/InteractsWithSessionTest.php
./vendor/bin/phpunit --no-progress tests/Reverb/Protocols/Pusher/ServerTest.php
```

Then run:

```shell
composer fix
```

## Self-Review Checklist

After implementation, re-check:

- `array` data is stored in `CoroutineContext`, not on object/static properties.
- `array` uses per-instance context keys so separate manually-created stores do not share data.
- `worker-array` uses instance properties only, with no static state and no `flushState()`.
- `ArrayLock` has no direct property access to lock arrays.
- `ArrayLock` behavior remains correct for acquire, release, force release, restore, refresh, permanent locks, expired locks, and remaining lifetime.
- `flush()` and `flushLocks()` clear separate buckets.
- Tag markers use the same backing store as normal cache values.
- `CacheManager` uses `Str::studly()` for internal driver method names and custom creators still win.
- Foundation and skeleton configs match.
- Testbench config contains only Testbench-specific overrides.
- Reverb uses `worker-array` for its cached websocket message rate limiter.
- Existing test `array` uses stay on `array` because none intentionally require worker-lifetime persistence.
- Docs describe request-local and worker-lifetime behavior without using Octane as the model.
- No stale code comments or docs still describe `array` as worker-lifetime.
- No new public worker-lifetime mutator was added without the required docblock warning.
- No changed path adds per-request I/O or shared mutable state.
