# Limiter Leases, Lock Refresh Hardening, and Redis Cluster Safety

Expose the concurrency limiter's acquire/hold/release primitive as a public lease API on both `Cache::funnel()` and `Redis::funnel()`, harden the lock refresh system by merging the best of Laravel's June 2026 lock refresh work with Hypervel's existing `RefreshableLock` implementation, unify the limiter timeout exceptions, and port Laravel's Redis Cluster hash-tag safety to every multi-key Lua path (both funnels and the Redis queue).

Hypervel 0.4 is unreleased. Backward compatibility and churn do not drive this plan. The final codebase must read as if it was designed this way from the start: no stale code, no dead methods, no misleading comments or docs.

This plan was agreed with codex-components over a multi-round consensus review. Every decision below records its reason.

## Background

### What exists today (verified in source)

**Limiters.** Both funnel limiters expose only callback sugar:

- `src/redis/src/Limiters/ConcurrencyLimiter.php` — protected `acquire(string $id)` (single Lua call claiming one of N precomputed slot keys via `LuaScripts::acquireConcurrencySlot()`), protected `release(string $key, string $id)`, public `block(int $timeout, ?callable $callback = null, int $sleep = 250)`. `block()` with no callback returns `true` and orphans the slot until the `releaseAfter` TTL reclaims it — the slot key and owner id never leave the method.
- `src/cache/src/Limiters/ConcurrencyLimiter.php` — same shape over `LockProvider::lock()`; its protected `acquire()` returns a real `Lock` object. `src/cache/src/Limiters/RedisConcurrencyLimiter.php` is the Redis fast path: one Lua call instead of N lock probes, bridging back into a `RedisLock` via `RedisStore::restoreLock()` (the Lua script returns the unprefixed slot name; the owner id is pre-packed because phpredis does not auto-serialize `eval()` ARGV).
- `src/redis/src/Limiters/DurationLimiter.php` — the `Redis::throttle()` rate limiter. Public `acquire(): bool` but that is a sliding-window counter, not a releasable slot. Single-key Lua, so no cluster concern.
- All three `block()` loops use whole-second `time()` deadline arithmetic; `Lock::block()` (`src/cache/src/Lock.php:89`) already uses a millisecond-precise pattern that never starts a sleep that would overshoot the deadline.

**Exceptions.** Two identical classes: `Hypervel\Contracts\Redis\LimiterTimeoutException` (thrown by both redis limiters) and `Hypervel\Cache\Limiters\LimiterTimeoutException` (cache funnel; referenced without import since it sits in the same namespace). Laravel has the same split — the redis one in contracts, the cache one added ad hoc with `Cache::funnel()` in Feb 2026. Consumers in this repo: the six limiter classes, `tests/Redis/{ConcurrencyLimiter,ConcurrencyLimiterBuilder,DurationLimiter,DurationLimiterBuilder}Test.php`, `tests/Cache/ConcurrencyLimiterTest.php`, `tests/Integration/Redis/{ConcurrencyLimiter,DurationLimiter}IntegrationTest.php`, `tests/Integration/Cache/{CacheFunnelTestCase,PhpRedisCacheFunnelTest}.php`, and `src/boost/docs/cache.md`. `src/queue/src/Middleware/{RateLimitedWithRedis,ThrottlesExceptionsWithRedis}.php` construct `DurationLimiter` directly but do not reference the exception.

**Locks.** `Hypervel\Contracts\Cache\RefreshableLock` (`refresh(?int $seconds = null): bool`, `getRemainingLifetime(): ?float`) is implemented by `RedisLock`, `DatabaseLock`, `ArrayLock`, `NoLock`. `FileLock extends CacheLock` and is not refreshable. `CacheLock` (used via `HasCacheLock` by StackStore, FailoverStore, MemoizedStore, NullStore, etc.) is intentionally not refreshable (cannot do it atomically). The shared refresh semantics today: `refresh(null)` on a permanent lock (`seconds <= 0`) is a blind no-op returning `true`; explicit `refresh($n)` with `$n <= 0` throws `InvalidArgumentException`. `LuaScripts::refreshLock()` does the owner-checked `EXPIRE`.

**Upstream Laravel commits to merge (all postdate the Hypervel port):**

- `0a1f8c376c` "port refresh locks to laravel 13" (June 15 2026) — `refresh($seconds = null)` on the abstract `Lock` throwing `RuntimeException`, overridden by RedisLock/PhpRedisLock/DatabaseLock/ArrayLock/FileLock/MemcachedLock/DynamoDbLock/NoLock; `FileStore::refreshIfOwned()`; expiry guards in DatabaseLock and ArrayLock refresh; tests.
- PR #59791 — `isLocked(): bool` on the abstract `Lock` (`getCurrentOwner() !== null`), `NoLock::isLocked(): false`.
- `d6be8af77c` "redis cluster full support" (April 5 2026) — static `Connection::hasHashTag()`, cluster hash-tag prefixing in the redis `ConcurrencyLimiter` (`getPrefix()` wraps the limiter name in `{...}` when `isCluster()` and the name has no hash tag), `RedisQueue::getQueueRedisKey()` with a cached `isClusterConnection()` flag, connector backoff options (already in Hypervel via the May 23 parity commit), tests. Without hash tags, multi-key Lua (`mget` across slot keys, the queue push/pop scripts) fails with CROSSSLOT on Redis Cluster. Hypervel has `isCluster()` (`RedisProxy`, `PhpRedisConnection`, `PhpRedisClusterConnection`) but no `hasHashTag()` and no tagging anywhere.

### Bugs in current Hypervel code this plan fixes

1. `DatabaseLock::getCurrentOwner()` (`src/cache/src/DatabaseLock.php:167`) does not filter expired rows, so an expired lock reports as held — `isOwnedByCurrentProcess()` lies today, and the ported `isLocked()` would too. Laravel filters `expiration > now`.
2. `DatabaseLock::refresh()` has no `expiration > now` guard in its UPDATE, so an expired-but-unclaimed row can be resurrected. Laravel guards.
3. `ArrayLock::getCurrentOwner()` returns the owner of an expired record. Laravel compensates only inside `refresh()`; treating expired-as-absent at the source is stronger and makes every derived check consistent (matches Redis, where the key is simply gone).
4. `DatabaseLock::refresh(null)` on a lock acquired with `seconds = 0` is a no-op returning `true`. But a database "permanent" lock is not permanent — `expiresAt()` maps non-positive seconds to `defaultTimeoutInSeconds` (86400) because a truly permanent row in a TTL-less store would be unreclaimable after a crash. The no-op lets a heartbeating holder's lock silently lapse after 24h.
5. The permanent-lock `refresh(null)` branch in RedisLock/ArrayLock/NoLock returns `true` blindly, without checking ownership. `refresh()` returning `true` must mean "I still hold this" — that is the invariant heartbeat code relies on.
6. `DatabaseLock::getConnectionName(): string` returns the nullable `$connectionName` property — a type error when the default connection (null) is used.
7. `RedisLock::acquire()` uses loose `== true` comparisons (`src/cache/src/RedisLock.php:36,39`). `RedisConnection::callSet()` returns `bool`, while the Laravel-style `RedisConnection::callSetnx()` wrapper returns `int` (`1` when acquired, `0` when not). Strict comparisons must match those real return types: `set(...) === true` and `setnx(...) === 1`.
8. The shared `InvalidArgumentException` message "For a permanent lock, acquire it with seconds=0." is wrong for DatabaseLock, where seconds=0 means the 24h default, not permanent.
9. Bare `now()` calls across `src/cache/src` (Lock, AbstractArrayStore, the Redis AllTag operations) resolve to the **global** `now()` helper, which only the foundation package defines (`src/foundation/src/helpers.php`, global namespace). The support package's `now()` (`src/support/src/functions.php`) is namespaced `Hypervel\Support` and is never reached by an unqualified call — PHP function resolution falls back from the current namespace straight to global. The cache package requires support but not foundation, so every bare call is a latent standalone-install break. Both helper bodies are identical (`Date::now(enum_value($tz))`), so adding `use function Hypervel\Support\now;` to each affected file fixes resolution with zero behavior change. The same latent pattern exists in the sanctum package, so those imports are added directly too.
10. `FileStore` imported `Hypervel\Contracts\Cache\LockTimeoutException`, but `LockableFile::getExclusiveLock()` throws `Hypervel\Contracts\Filesystem\LockTimeoutException` — the catch in `add()` never matched, so flock contention crashed instead of returning `false`. Fixed by importing the Filesystem exception (discovered during implementation, not in the original plan).

### Consensus decisions and their reasons

| Decision | Reason |
|---|---|
| Public lease API via `acquire()` on both funnel builders; `then()`/`block()` become sugar over one acquisition path | Acquire/hold/release is the fundamental semaphore primitive (Java `Semaphore`, Go `semaphore`, K8s/DHCP/Azure leases). Locks already follow primitive-first design (`get()`/`block()` over public `acquire()`/`release()`); the limiter is the odd one out. |
| Capability interfaces `Lease` + `RefreshableLease extends Lease`; no `isRefreshable()` method | Matches the `RefreshableLock` idiom, PHPStan can narrow via `instanceof`, and no contract advertises a method that may not work. Leases are new API with no Laravel-compat constraint, so no throwing fallback is needed (unlike locks). |
| One `Hypervel\Contracts\Limiters\LimiterTimeoutException` for cache funnel, redis funnel, and redis throttle | One concept, one exception: generic limiter code catches a single class regardless of backend. `Contracts\Concurrency` is not used because that namespace belongs to the task-execution Concurrency package. `LockTimeoutException` stays separate — a mutex and a slot pool are different features. |
| Keep the `refresh()` name (not `renew()`) | Laravel chose `refresh()` for the identical feature in June 2026; parity costs nothing here. |
| Keep explicit non-positive `refresh()` throwing `InvalidArgumentException` (reject Laravel's `refresh(0)` → PERSIST) | Laravel's `refresh(0)` is inconsistent across its own drivers: permanent on redis (`PERSIST`)/array (`null`)/file (`9999999999`), but a silent 24h reset on database (`defaultTimeoutInSeconds`). There is no coherent semantic to port. The throw is uniform, deterministic, and protects the crash-safety TTL from computed-zero bugs — a silently-permanent slot plus a crashed worker permanently reduces limiter capacity. Duration class is a creation-time choice (`lock($name, 0)`, `releaseAfter(0)`); refresh extends within the class. |
| `refresh(null)` re-applies the original duration as the driver interpreted it at acquisition | Uniform rule with driver-appropriate interpretation defined in exactly one place (acquire): Redis/Array/File permanent locks have nothing to re-assert (ownership-checked no-op), DatabaseLock re-extends its `defaultTimeoutInSeconds` backstop — which is what a heartbeating holder needs. |
| Permanent-lock `refresh(null)` verifies ownership instead of blindly returning `true` | `refresh()` returning `true` must mean continuous ownership; a force-released-and-re-acquired permanent lock must not report success. One extra read, only on the permanent path. |
| Internal slot methods renamed `claimSlot()` / `releaseSlot()` | Frees the `acquire` name for the public lease API and makes clear these are backend operations. They were never public API. |
| Millisecond-precise deadline loops in all three limiter `block()`/`acquire()` paths | The `time()` loops can miss the deadline by up to a second either way; `Lock::block()` already has the correct pattern. Never hold a pooled connection across a sleep. |
| Leases are caller-held plain objects, never CoroutineContext | A lease legitimately spans requests within a worker (pooled connections). All correctness is enforced server-side by owner-checked atomic operations, so concurrent coroutines/workers/processes can hold and manipulate leases safely. No new static state anywhere. |
| No shared abstract lease base across packages; no `name()`/`key()` accessor | Contracts provide the shared shape; a few duplicated delegate lines beat cross-package inheritance. Exposing slot keys leaks implementation details. |
| `hasHashTag()` lives as a public static on `Hypervel\Redis\RedisConnection` | Matches Laravel's base-connection placement; it is key/connection behavior, not proxy behavior. |
| Skip Memcached/DynamoDB lock work | Unsupported drivers (exhaustive list in AGENTS.md). |
| Do not copy Laravel's `PhpRedisLock::refresh()` packing of `[$owner, $seconds]` | Packing the int breaks `tonumber(ARGV[2])` under phpredis serializers. Hypervel packs only the owner and passes seconds raw — correct. Record as a source comment where future ports will look. |

## Design

### 1. New contracts — `src/contracts/src/Limiters/`

`Hypervel\Contracts\` maps to `src/contracts/src/`, so this is a new `Limiters/` directory. Three files.

`src/contracts/src/Limiters/Lease.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Limiters;

/**
 * A held concurrency-limiter slot.
 *
 * A lease is acquired from a funnel limiter and held by the caller — across
 * operations, coroutines, or requests — until it is explicitly released or
 * its releaseAfter TTL reclaims it after a crash. Leases that support TTL
 * extension implement RefreshableLease.
 */
interface Lease
{
    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool;

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string;
}
```

`src/contracts/src/Limiters/RefreshableLease.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Limiters;

use InvalidArgumentException;

/**
 * A lease that supports refreshing its TTL and inspecting remaining lifetime.
 *
 * Semantics mirror RefreshableLock: refresh() is atomic and owner-checked,
 * refresh(null) re-applies the duration the slot was acquired with as the
 * backend interpreted it, and an explicit non-positive TTL throws.
 */
interface RefreshableLease extends Lease
{
    /**
     * Refresh the lease's TTL if still owned by this lease.
     *
     * @param null|int $seconds Seconds to set the TTL to (null = re-apply the acquisition TTL)
     * @return bool True if the lease was refreshed (or is permanent and still owned), false if not owned or expired
     *
     * @throws InvalidArgumentException If $seconds is explicitly provided and is not positive
     */
    public function refresh(?int $seconds = null): bool;

    /**
     * Get the number of seconds until the lease expires.
     *
     * @return null|float Seconds remaining, or null if the slot doesn't exist or has no expiry
     */
    public function getRemainingLifetime(): ?float;
}
```

`src/contracts/src/Limiters/LimiterTimeoutException.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Limiters;

use Exception;

class LimiterTimeoutException extends Exception
{
}
```

Delete `src/contracts/src/Redis/LimiterTimeoutException.php` and `src/cache/src/Limiters/LimiterTimeoutException.php`. Update every consumer listed in Background (six src files, nine test files, `src/boost/docs/cache.md`). The cache limiter files currently reference the exception without an import (same namespace) — they gain a `use Hypervel\Contracts\Limiters\LimiterTimeoutException;` statement.

### 2. Redis funnel lease — `src/redis/src/Limiters/ConcurrencyLease.php`

```php
<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use Hypervel\Contracts\Limiters\RefreshableLease;
use Hypervel\Redis\LuaScripts;
use Hypervel\Redis\RedisProxy;
use InvalidArgumentException;

/**
 * A held slot in a Redis funnel limiter.
 *
 * The owner id is stored and compared raw, never packed: the slot value is
 * written by the acquire Lua script (which bypasses phpredis serialization)
 * and only ever read back by the release/refresh Lua scripts. This differs
 * from RedisLock, whose value is written by set() (serialized) and therefore
 * must pack the owner before Lua comparisons.
 */
class ConcurrencyLease implements RefreshableLease
{
    /**
     * Create a new lease instance.
     *
     * @param RedisProxy $redis the Redis connection instance
     * @param string $key the slot key held by this lease
     * @param string $owner the owner identifier
     * @param int $releaseAfter the acquisition TTL in seconds (0 = permanent)
     */
    public function __construct(
        protected RedisProxy $redis,
        protected string $key,
        protected string $owner,
        protected int $releaseAfter,
    ) {
    }

    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool
    {
        return (bool) $this->redis->eval(LuaScripts::releaseLock(), 1, $this->key, $this->owner);
    }

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Refresh the lease's TTL if still owned by this lease.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds === null && $this->releaseAfter <= 0) {
            return $this->redis->get($this->key) === $this->owner;
        }

        $seconds ??= $this->releaseAfter;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }

        return (bool) $this->redis->eval(LuaScripts::refreshLock(), 1, $this->key, $this->owner, $seconds);
    }

    /**
     * Get the number of seconds until the lease expires.
     */
    public function getRemainingLifetime(): ?float
    {
        $ttl = $this->redis->ttl($this->key);

        // -2 = key doesn't exist, -1 = key has no expiry
        if ($ttl < 0) {
            return null;
        }

        return (float) $ttl;
    }
}
```

Note on the permanent branch's `get()` comparison: the slot value was written raw by Lua, and phpredis only unserializes values it recognizes as serialized, so a raw random id round-trips unchanged. This is the same reason the owner is passed raw to the Lua scripts.

### 3. Cache funnel leases — `src/cache/src/Limiters/`

Two flat classes. Codex proposed `RefreshableConcurrencyLease extends ConcurrencyLease`, but PHP typed properties are invariant — the child cannot redeclare `protected Lock $lock` as `RefreshableLock` — so inheritance forces either duplicate references or `@var` gymnastics. The contracts already provide the hierarchy (`RefreshableLease extends Lease`); the concrete classes stay flat and trivial.

`src/cache/src/Limiters/ConcurrencyLease.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Limiters\Lease;

/**
 * A held slot in a cache funnel limiter, backed by a non-refreshable lock.
 */
class ConcurrencyLease implements Lease
{
    /**
     * Create a new lease instance.
     */
    public function __construct(
        protected Lock $lock,
    ) {
    }

    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool
    {
        return $this->lock->release();
    }

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string
    {
        return $this->lock->owner();
    }
}
```

`src/cache/src/Limiters/RefreshableConcurrencyLease.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Limiters\RefreshableLease;
use InvalidArgumentException;

/**
 * A held slot in a cache funnel limiter, backed by a refreshable lock.
 */
class RefreshableConcurrencyLease implements RefreshableLease
{
    /**
     * Create a new lease instance.
     */
    public function __construct(
        protected RefreshableLock $lock,
    ) {
    }

    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool
    {
        return $this->lock->release();
    }

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string
    {
        return $this->lock->owner();
    }

    /**
     * Refresh the lease's TTL if still owned by this lease.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        return $this->lock->refresh($seconds);
    }

    /**
     * Get the number of seconds until the lease expires.
     */
    public function getRemainingLifetime(): ?float
    {
        return $this->lock->getRemainingLifetime();
    }
}
```

### 4. Limiter reshape — one acquisition path

#### Redis `ConcurrencyLimiter` (`src/redis/src/Limiters/ConcurrencyLimiter.php`)

Full new shape (constructor also gains cluster tagging, see §6):

```php
class ConcurrencyLimiter
{
    /**
     * Precomputed slot names. Built once in the constructor.
     *
     * @var list<string>
     */
    protected array $slots;

    /**
     * The slot key prefix: the limiter name, hash-tagged on cluster connections.
     */
    protected string $keyPrefix;

    /**
     * Create a new concurrency limiter instance.
     *
     * @param RedisProxy $redis the Redis connection instance
     * @param string $name the name of the limiter
     * @param int $maxLocks the allowed number of concurrent tasks
     * @param int $releaseAfter the number of seconds a slot should be maintained
     */
    public function __construct(
        protected RedisProxy $redis,
        protected string $name,
        protected int $maxLocks,
        protected int $releaseAfter
    ) {
        // All slot keys must hash to one cluster node: the acquire script
        // runs a multi-key MGET, which fails with CROSSSLOT otherwise.
        $this->keyPrefix = $redis->isCluster() && ! RedisConnection::hasHashTag($name)
            ? '{' . $name . '}'
            : $name;

        $this->slots = $maxLocks < 1
            ? []
            : array_map(fn (int $i): string => $this->keyPrefix . $i, range(1, $maxLocks));
    }

    /**
     * Acquire a lease on one of the limiter's slots, waiting up to the given timeout.
     *
     * @throws LimiterTimeoutException
     */
    public function acquire(int $timeout, int $sleep = 250): ConcurrencyLease
    {
        $id = Str::random(20);

        $starting = ((int) now()->format('Uu')) / 1000;

        $milliseconds = $timeout * 1000;

        while (! $slot = $this->claimSlot($id)) {
            $now = ((int) now()->format('Uu')) / 1000;

            if (($now + $sleep - $milliseconds) >= $starting) {
                throw new LimiterTimeoutException;
            }

            Sleep::usleep($sleep * 1000);
        }

        return new ConcurrencyLease($this->redis, $slot, $id, $this->releaseAfter);
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * When no callback is given, the slot is reserved fire-and-forget: it is
     * held until the releaseAfter TTL reclaims it. Use acquire() to obtain a
     * releasable lease instead.
     *
     * @throws LimiterTimeoutException
     * @throws Throwable
     */
    public function block(int $timeout, ?callable $callback = null, int $sleep = 250): mixed
    {
        $lease = $this->acquire($timeout, $sleep);

        if (is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $lease->release();
            }
        }

        return true;
    }

    /**
     * Attempt to claim a free slot.
     *
     * @param string $id a unique identifier for this lease
     * @return false|string the claimed slot key, or false when every slot is taken
     */
    protected function claimSlot(string $id): false|string
    {
        // Without slots there's nothing to claim. Calling eval with zero KEYS
        // would error inside Lua via unpack({}) → redis.call('mget') with no args.
        if ($this->slots === []) {
            return false;
        }

        $result = $this->redis->eval(...array_merge(
            [LuaScripts::acquireConcurrencySlot(), count($this->slots)],
            $this->slots,
            [$this->keyPrefix, $this->releaseAfter, $id],
        ));

        return is_string($result) ? $result : false;
    }
}
```

Changes from today: protected `acquire()`/`release()` become `claimSlot()` + the lease (the private release helper disappears entirely — the lease owns release); `block()` is sugar; ms-precise deadline; ARGV[1] passes `$this->keyPrefix` (not `$this->name`) so the Lua-returned slot name is the real key on cluster connections; `claimSlot()` narrows the eval result to `false|string`. Each claim attempt is one proxy call that returns its pooled connection before the sleep — no connection is held across `Sleep::usleep()`.

The deadline arithmetic keeps `Lock::block()`'s `now()` helper style, but every file using it imports `use function Hypervel\Support\now;` so the call resolves inside the package (bug 9; support is a dependency of both redis and cache, and the support and foundation helper bodies are identical). All four deadline loops share one fakeable time source through the `Date` facade.

The existing behavior of `block($timeout)` with no callback (reserve until TTL, return `true`) is kept deliberately — it is documented Laravel behavior — but the docblock now points to `acquire()`.

#### Redis `ConcurrencyLimiterBuilder`

```php
    /**
     * Acquire a lease on one of the limiter's slots, waiting up to the configured timeout.
     *
     * @throws LimiterTimeoutException
     */
    public function acquire(): ConcurrencyLease
    {
        return $this->createLimiter()->acquire($this->timeout, $this->sleep);
    }

    /**
     * Execute the given callback if a lock is obtained, otherwise call the failure callback.
     *
     * @throws LimiterTimeoutException
     */
    public function then(callable $callback, ?callable $failure = null): mixed
    {
        try {
            $lease = $this->acquire();
        } catch (LimiterTimeoutException $e) {
            if ($failure) {
                return $failure($e);
            }

            throw $e;
        }

        try {
            return $callback();
        } finally {
            $lease->release();
        }
    }

    /**
     * Create the concurrency limiter instance.
     */
    protected function createLimiter(): ConcurrencyLimiter
    {
        return new ConcurrencyLimiter($this->connection, $this->name, $this->maxLocks, $this->releaseAfter);
    }
```

Deliberate behavioral improvement to document: today a `LimiterTimeoutException` thrown *by the callback itself* is caught by the builder and misrouted to the `$failure` handler. The new shape scopes the catch to acquisition only. The `createLimiter()` extraction mirrors the cache builder for symmetry.

#### Cache `ConcurrencyLimiter` + `RedisConcurrencyLimiter` + builder

Same reshape. The base cache limiter's `claimSlot()` keeps the lock-probing loop with its return type narrowed from `bool|Lock` to `false|Lock` (the `true` case is unreachable — it returns a claimed `Lock` or `false`).

`RedisConcurrencyLimiter::claimSlot()` keeps the Lua fast path but its lock construction changes: today it bridges through `RedisStore::restoreLock($result, $id)`, and `restoreLock()` builds the lock with `seconds = 0`. A zero-seconds `RedisLock` hits the permanent branch on `refresh(null)` — ownership check only, no TTL extension — which would silently break the fast path's lease heartbeat (the most important cache lease path). Instead, construct the lock with the acquisition TTL:

```php
            return is_string($result) ? $store->lock($result, $this->releaseAfter, $id) : false;
```

`RedisStore::lock()` prepends the store prefix exactly once (same as `restoreLock()`, which just wraps it), the raw `$id` becomes the lock owner exactly as before, and `refresh(null)` now re-extends by `releaseAfter` as the shared semantics require. The claim-slot invariant comment block (unprefixed Lua return, pre-packed owner) stays, reworded to reference `RedisStore::lock()` instead of `restoreLock()` and updated for the method rename. The limiter's `acquire()` wraps the lock:

```php
    /**
     * Acquire a lease on one of the limiter's slots, waiting up to the given timeout.
     *
     * @throws LimiterTimeoutException
     */
    public function acquire(int $timeout, int $sleep = 250): Lease
    {
        $id = Str::random(20);

        $starting = ((int) now()->format('Uu')) / 1000;

        $milliseconds = $timeout * 1000;

        while (! $lock = $this->claimSlot($id)) {
            $now = ((int) now()->format('Uu')) / 1000;

            if (($now + $sleep - $milliseconds) >= $starting) {
                throw new LimiterTimeoutException;
            }

            Sleep::usleep($sleep * 1000);
        }

        return $lock instanceof RefreshableLock
            ? new RefreshableConcurrencyLease($lock)
            : new ConcurrencyLease($lock);
    }
```

`block()` and the builder change exactly as on the redis side (`ConcurrencyLimiterBuilder::acquire(): Lease`). The old protected `release(Lock $lock)` helper is deleted. The cache `RedisConcurrencyLimiter` always produces a `RedisLock`, so its leases are `RefreshableConcurrencyLease` — the fast path stays fully refreshable.

Builder return types are deliberately asymmetric: the redis builder declares the concrete `acquire(): ConcurrencyLease` (there is only one possible type, and no builder contract exists — callers get the precise type), while the cache builder declares the `Lease` contract (two possible concretes). Docs teach `instanceof RefreshableLease` for store-agnostic code.

The cache Redis fast path also gains cluster tagging (§6): the *unprefixed* slot base name is hash-tagged before the store prefix is applied, so `restoreLock()`'s prepend-prefix-once contract is untouched: keys are `cachePrefix . '{name}' . i`, Lua ARGV[1] is `'{name}'`, and the returned unprefixed name round-trips through `restoreLock()` exactly as today.

#### `DurationLimiter::block()`

Same ms-precise loop (sleep default stays 750). No other changes — `acquire(): bool` is already the correct public primitive for a rate limiter. Exception import switches to the unified class.

### 5. Lock hardening

#### Abstract `Lock` (`src/cache/src/Lock.php`)

Insert after `block()`, before `owner()` (Laravel's order):

```php
    /**
     * Attempt to refresh the lock for the given number of seconds.
     *
     * @throws RuntimeException
     */
    public function refresh(?int $seconds = null): bool
    {
        throw new RuntimeException('This lock driver does not support refreshing locks.');
    }

    /**
     * Get the number of seconds until the lock expires.
     *
     * @throws RuntimeException
     */
    public function getRemainingLifetime(): ?float
    {
        throw new RuntimeException('This lock driver does not support lifetime inspection.');
    }
```

Insert before `isOwnedByCurrentProcess()`:

```php
    /**
     * Determine if the lock is currently held by any process.
     */
    public function isLocked(): bool
    {
        return $this->getCurrentOwner() !== null;
    }
```

The `refresh()` fallback is a direct Laravel port. The `getRemainingLifetime()` fallback goes beyond Laravel (which has no lifetime inspection at all): without it, calling `refresh()` on a non-refreshable lock throws a catchable `RuntimeException` while calling `getRemainingLifetime()` is a fatal undefined-method `Error` — an inconsistent failure mode for two methods that travel together on `RefreshableLock`.

#### Shared refresh semantics (all `RefreshableLock` implementations)

The permanent-lock branch changes from a blind `return true;` to an ownership check, and the exception message becomes driver-neutral:

```php
        if ($seconds === null && $this->seconds <= 0) {
            return $this->isOwnedByCurrentProcess();
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }
```

Applied to `RedisLock`, `ArrayLock`, `NoLock` (whose `getCurrentOwner()` returns the local owner, so its check is constant-true — correct for a no-op driver), and the redis `ConcurrencyLease` (§2). `DatabaseLock` drops the branch entirely (below). `FileLock` gains it (below).

#### `RedisLock`

Besides the shared semantics change: fix the loose comparisons in `acquire()` — the `set()` branch becomes `=== true`, and the `setnx()` branch becomes `=== 1` because Hypervel's Laravel-style Redis wrapper normalizes `setnx` to `int`. Verify with the Redis lock integration tests.

#### `DatabaseLock`

```php
    /**
     * Return the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): ?string
    {
        return $this->connection()->table($this->table)
            ->where('key', $this->name)
            ->where('expiration', '>', $this->currentTime())
            ->first()
            ?->owner;
    }

    /**
     * Get the UNIX timestamp indicating when the lock should expire.
     */
    protected function expiresAt(?int $seconds = null): int
    {
        $seconds ??= $this->seconds;

        $lockTimeout = $seconds > 0 ? $seconds : $this->defaultTimeoutInSeconds;

        return $this->currentTime() + $lockTimeout;
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * A database lock is never truly permanent: non-positive acquisition
     * durations map to the default timeout because an unexpiring row in a
     * TTL-less store could not be reclaimed after a crash. refresh(null)
     * therefore re-extends by the same rule acquire() used.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds !== null && $seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }

        $updated = $this->connection()->table($this->table)
            ->where('key', $this->name)
            ->where('owner', $this->owner)
            ->where('expiration', '>', $this->currentTime())
            ->update([
                'expiration' => $this->expiresAt($seconds),
            ]);

        return $updated >= 1;
    }

    /**
     * Get the name of the database connection being used to manage the lock.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection()->getName();
    }
```

The atomicity story: the expiry guard lives inside the single UPDATE's WHERE clause — check and write are one statement, no extra query. `getConnectionName()` resolves the actual connection and matches `Hypervel\Database\ConnectionInterface::getName(): ?string` — the honest fix for the nullable-property type error is a nullable return, since the underlying contract is nullable.

#### `ArrayLock`

```php
    /**
     * Release the lock.
     */
    public function release(): bool
    {
        if (! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        $this->forceRelease();

        return true;
    }

    /**
     * Return the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): ?string
    {
        $record = $this->store->getLockRecord($this->name);

        if ($record === null) {
            return null;
        }

        $expiresAt = $record['expiresAt'];

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        return $record['owner'];
    }
```

With expired-as-absent enforced in `getCurrentOwner()`, the explicit record-null/expiry checks inside `refresh()` reduce to the shared semantics plus the record write-back, and `exists()` becomes dead code — delete it (`release()` was its only caller, and its check is subsumed by the ownership check). The `// WorkerArrayStore shares this check/write path across coroutines; keep it non-yielding.` comment in `acquire()` stays — the same constraint applies to the refresh read-modify-write, which remains non-yielding.

```php
    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds === null && $this->seconds <= 0) {
            return $this->isOwnedByCurrentProcess();
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }

        $record = $this->store->getLockRecord($this->name);

        if ($record === null || ! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        $record['expiresAt'] = Carbon::now()->addSeconds($seconds);

        $this->store->putLockRecord($this->name, $record);

        return true;
    }
```

The explicit `$record === null` guard keeps `$record` provably non-null for the write-back (PHPStan sees the local variable, not the invariant that `isOwnedByCurrentProcess()` just proved the record exists), while `isOwnedByCurrentProcess()` stays the single source of owner + expiry logic. The extra in-memory read is free.

#### `NoLock`

Shared semantics (permanent branch becomes `return $this->isOwnedByCurrentProcess();` — constant-true here, kept for uniformity), plus:

```php
    /**
     * Determine if the lock is currently held by any process.
     */
    public function isLocked(): bool
    {
        return false;
    }
```

#### `FileStore` + `FileLock`

`FileStore` gains a permanent-expiration constant (the `9999999999` sentinel currently appears as a bare literal in `expiration()`), an atomic owner-checked refresh, and a lifetime reader:

```php
    /**
     * The expiration timestamp stored for items cached forever.
     */
    protected const PERMANENT_TIMESTAMP = 9999999999;
```

`expiration()` is rewritten to use it:

```php
    protected function expiration(int $seconds): int
    {
        $time = $this->availableAt($seconds);

        return $seconds === 0 || $time > self::PERMANENT_TIMESTAMP ? self::PERMANENT_TIMESTAMP : $time;
    }
```

New methods, placed after `add()` (the operation they pair with):

```php
    /**
     * Atomically refresh the expiration of a cache key if it matches the expected owner.
     */
    public function refreshIfOwned(string $key, string $expectedOwner, int $seconds): bool
    {
        $this->ensureCacheDirectoryExists($path = $this->path($key));

        $file = new LockableFile($path, 'c+');

        try {
            $file->getExclusiveLock();
        } catch (LockTimeoutException) { // @phpstan-ignore catch.neverThrown (thrown inside closure)
            $file->close();

            return false;
        }

        $contents = $file->read();

        if (strlen($contents) < 10) {
            $file->close();

            return false;
        }

        $expire = (int) substr($contents, 0, 10);
        $currentOwner = $this->unserialize(substr($contents, 10));

        if ($currentOwner !== $expectedOwner || $this->currentTime() >= $expire) {
            $file->close();

            return false;
        }

        $file->truncate()
            ->write($this->expiration($seconds) . serialize($expectedOwner))
            ->close();

        $this->ensurePermissionsAreCorrect($path);

        return true;
    }

    /**
     * Get the number of seconds until the given key expires.
     *
     * The read takes a shared lock: Filesystem::get() with $lock = true
     * delegates to sharedGet(), so a concurrent refreshIfOwned() rewrite
     * (exclusive lock) can never be observed half-written.
     *
     * @return null|float Seconds remaining, or null if the key is missing, expired, or permanent
     */
    public function remainingSeconds(string $key): ?float
    {
        try {
            $expire = (int) substr($this->files->get($this->path($key), true), 0, 10);
        } catch (Exception) {
            return null;
        }

        if ($expire >= self::PERMANENT_TIMESTAMP) {
            return null;
        }

        $remaining = $expire - $this->currentTime();

        return $remaining > 0 ? (float) $remaining : null;
    }
```

`FileLock` becomes a real `RefreshableLock`. Its `$store` property is typed `Store` (inherited from `CacheLock`; PHP typed properties are invariant, so it cannot be redeclared), and it is only ever constructed by `FileStore::lock()` with a `FileStore`, so a narrowing accessor is proven correct. Routing `acquire()` through the accessor also removes the existing `@phpstan-ignore-next-line` on the `add()` call — the ignore existed only because `add()` was called through the `Store`-typed property:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\RefreshableLock;
use InvalidArgumentException;

class FileLock extends CacheLock implements RefreshableLock
{
    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        return $this->fileStore()->add($this->name, $this->owner, $this->seconds);
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds === null && $this->seconds <= 0) {
            return $this->isOwnedByCurrentProcess();
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }

        return $this->fileStore()->refreshIfOwned($this->name, $this->owner, $seconds);
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        return $this->fileStore()->remainingSeconds($this->name);
    }

    /**
     * Get the file store backing this lock.
     */
    protected function fileStore(): FileStore
    {
        /** @var FileStore */
        return $this->store;
    }
}
```

#### `RefreshableLock` contract docblock

Update `refresh()`'s docblock body to the agreed semantics (drop the "no-op that returns true" sentence):

```
     * When called without arguments, the TTL is re-applied exactly as the
     * driver interpreted the acquisition duration: drivers with native
     * expiry treat a lock acquired with a TTL of 0 as permanent and only
     * verify ownership, while drivers without native expiry (database)
     * re-extend their default safety timeout.
```

`CacheLock` itself is untouched — it inherits the throwing base methods, which is the intended failure mode for non-atomic stores.

### 6. Redis Cluster safety

#### `RedisConnection::hasHashTag()` (`src/redis/src/RedisConnection.php`)

Public static, placed with the connection's key-behavior helpers:

```php
    /**
     * Determine if the given key contains a Redis Cluster hash tag.
     *
     * A hash tag is a substring enclosed in braces with at least one character
     * between them (e.g., "{user}:sessions"). Empty braces ("{}") are not
     * considered a valid hash tag.
     */
    public static function hasHashTag(string $key): bool
    {
        $open = strpos($key, '{');

        if ($open === false) {
            return false;
        }

        $close = strpos($key, '}', $open + 1);

        return $close !== false && $close - $open > 1;
    }
```

#### Redis funnel — constructor tagging

Shown in §4. The tag decision is made once per limiter instance; `RedisProxy::isCluster()` is a pool-config array lookup, cheap enough per construction, and pool config is process-stable so there is no coroutine-safety concern.

#### Cache Redis fast path — `RedisConcurrencyLimiter`

The constructor tags the unprefixed base name before both the plain and store-prefixed slot arrays are built:

```php
        $connection = $store->lockConnection();

        $tagged = $connection->isCluster() && ! RedisConnection::hasHashTag($name)
            ? '{' . $name . '}'
            : $name;

        parent::__construct($store, $tagged, $maxLocks, $releaseAfter);

        $prefix = $store->getPrefix();
        $this->prefixedSlots = array_map(
            fn (string $slot): string => $prefix . $slot,
            $this->slots,
        );
```

The base cache limiter builds `$this->slots` from the (tagged) name, Lua ARGV[1] passes the tagged name, the returned unprefixed slot name round-trips through `RedisStore::lock()` (see §4) which prepends the store prefix exactly once — the existing invariant documented on `claimSlot()` is preserved. Hash slot computation uses the first `{...}` in the final key (`cachePrefix{name}N`), so all slots co-locate. The parent (non-Redis) cache limiter path needs no tagging — lock-probing stores are not Redis Cluster.

#### `RedisQueue` (`src/queue/src/RedisQueue.php`)

Port Laravel's current shape: a cached cluster flag, a cluster-safe key resolver, and every Redis key construction switched to it. `getQueue()` stays public and un-tagged (it is the logical queue name used in payloads and by external callers).

```php
    /**
     * Indicates if the connection is a Redis Cluster connection.
     */
    protected ?bool $isCluster = null;

    /**
     * Get the cluster-safe Redis key for the given queue.
     *
     * When connected to a Redis Cluster, queue names are wrapped in hash tags
     * to ensure all related keys (queue, delayed, reserved, notify) hash to the
     * same slot, which is required for multi-key Lua scripts.
     */
    protected function getQueueRedisKey(?string $queue = null): string
    {
        $queue = $queue ?: $this->default;

        return $this->isClusterConnection() && ! RedisConnection::hasHashTag($queue)
            ? $this->getQueue('{' . $queue . '}')
            : $this->getQueue($queue);
    }

    /**
     * Determine if the queue connection is a Redis Cluster connection.
     */
    protected function isClusterConnection(): bool
    {
        return $this->isCluster ??= $this->getConnection()->isCluster();
    }
```

Call-site sweep (mirror Laravel's current file exactly; Hypervel method names verified): `size()`, `pendingSize()`, `delayedSize()`, `reservedSize()`, `creationTimeOfOldestPendingJob()` (the `lindex` peek), `pushRaw()` + `:notify`, `laterRaw()` + `:delayed`, `pop()`/`migrate()`/`migrateExpiredJobs()`, `retrieveNextJob()` (the pop eval + `blpop` on `:notify`), `deleteReserved()`, `deleteAndRelease()`, and `clear()`. Every place that currently feeds `$this->getQueue($queue)` (or a string derived from it) into a Redis command switches to `getQueueRedisKey($queue)`; suffixes (`:delayed` etc.) concatenate onto the tagged key so they share its hash slot. `RedisConnection` gains an import in the queue file. The cached `?bool $isCluster` is instance state derived from process-stable pool config — coroutine-safe.

`createPayload()` call sites keep `$this->getQueue($queue)` — the payload records the logical queue name, not the storage key (matches Laravel).

### 7. What is deliberately different from Laravel (record per AGENTS.md)

Each entry goes in the relevant package README under `Differences From Laravel`, with a concise source comment at the natural location:

**cache README:**
- `refresh()` with an explicit non-positive TTL throws `InvalidArgumentException` instead of Laravel's PERSIST-to-permanent (Laravel's own drivers disagree about what `refresh(0)` means; the TTL is the crash-safety net and is never silently removed).
- `RefreshableLock` capability interface and `getRemainingLifetime()` — Hypervel additions; Laravel puts `refresh()` on implementations with no typed capability path.
- Permanent-lock `refresh(null)` verifies ownership rather than returning `true` unconditionally.
- `Cache::funnel()` returns leases via `acquire()` — Hypervel addition.
- The funnel timeout exception is `Hypervel\Contracts\Limiters\LimiterTimeoutException` (Laravel: `Illuminate\Cache\Limiters\LimiterTimeoutException`).

**redis README:**
- `Redis::funnel()->acquire()` lease API — Hypervel addition.
- The limiter timeout exception is `Hypervel\Contracts\Limiters\LimiterTimeoutException` (Laravel: `Illuminate\Contracts\Redis\LimiterTimeoutException`).

**Source comment (RedisLock::refresh or LuaScripts::refreshLock, wherever a future port will look):** owner is packed, seconds passed raw — Laravel's `PhpRedisLock::refresh()` packs both, which breaks `tonumber(ARGV[2])` when a phpredis serializer is configured; do not port that shape.

## Implementation steps

Work top-down; each step compiles and is testable on its own. Run the named test files immediately after each step (`./vendor/bin/phpunit --no-progress <file>` from the repo root), then `composer test:parallel` at the end. Run `./vendor/bin/phpstan` and `./vendor/bin/php-cs-fixer fix` (no flags) before the final suite.

1. **Contracts.** Create `src/contracts/src/Limiters/{Lease,RefreshableLease,LimiterTimeoutException}.php`. Delete the two old exception classes. Sweep imports/references in the six limiter src files, the nine test files, and `src/boost/docs/cache.md` (`grep -rn "LimiterTimeoutException" src/ tests/` must show only the contracts class afterward). Run the four `tests/Redis/*Limiter*Test.php` files and `tests/Cache/ConcurrencyLimiterTest.php`.
2. **`hasHashTag()`.** Add the static to `RedisConnection`. Unit-test alongside the limiter cluster tests (steps 6–7).
3. **Lock base + shared semantics.** `Lock`: add `refresh()`/`getRemainingLifetime()` fallbacks and `isLocked()`. Fix bug 9: add `use function Hypervel\Support\now;` to every `src/cache/src` file with a bare `now()` call — find them with `grep -rn "[^:>a-zA-Z_\$]now()" src/cache/src` (excludes `Carbon::now()`/`->now()`; ArrayLock only uses `Carbon::now()` and needs no import) — and add the same import to the sanctum files with bare helper calls. `NoLock`: `isLocked()` override + shared refresh semantics. `RedisLock`: shared semantics + strict `set(...) === true` / `setnx(...) === 1` fixes. `ArrayLock`: expired-as-absent `getCurrentOwner()`, simplified `release()`, delete `exists()`, refresh rewrite. `DatabaseLock`: `getCurrentOwner()` filter, `expiresAt(?int)`, refresh rewrite, `getConnectionName()` fix. Update the `RefreshableLock` contract docblock. Run `tests/Cache/{CacheRedisLockTest,RedisLockTest,CacheDatabaseLockTest,CacheNoLockTest,CacheArrayStoreTest,CacheWorkerArrayStoreTest}.php` after each file, porting/adapting the Laravel `0a1f8c376c` + #59791 test additions as each driver lands (expired-lock cases, cannot-refresh-other-owner, default seconds, explicit-zero asserts the throw, `isLocked()`).
4. **FileStore + FileLock.** `PERMANENT_TIMESTAMP` constant, `refreshIfOwned()`, `remainingSeconds()`, `FileLock implements RefreshableLock`. Lock coverage goes in the existing `tests/Integration/Cache/FileCacheLockTest.php` — the upstream-aligned home; Laravel's `0a1f8c376c` additions target this exact file. Keep upstream's method names, adapting `testLockRefreshWithDefaultSeconds` to the throw semantics, and alongside them cover `getRemainingLifetime()` incl. the permanent sentinel → null, the permanent-lock ownership check, the explicit-zero throw, and `isLocked()`. No new unit test file: the existing `CacheFileStoreTest.php` stays mocked-Filesystem store coverage, and everything lock-shaped is expressible through `Cache::lock()` plus time travel.
5. **Database integration test ports.** `tests/Integration/Database/Todo/` holds two skipped placeholders whose skip reasons are stale — the methods they wait for (`isOwnedBy`/`isOwnedByCurrentProcess`/`getConnectionName` on the locks, `forgetIfExpired`/`getConnection` on `DatabaseStore`) all exist now, and the owner confirmed the files were forgotten, not deferred. Port both for real: `mv` `Todo/DatabaseLockTest.php` → `tests/Integration/Database/DatabaseLockTest.php` and `Todo/DatabaseCacheStoreTest.php` → `tests/Integration/Database/DatabaseCacheStoreTest.php`, fix the namespaces, drop the skips, and diff each against the current upstream `tests/Integration/Database/` version before porting (they were copied a while ago — port the current upstream state, which for `DatabaseLockTest` includes the `0a1f8c376c` refresh additions). Run each file immediately, then delete the emptied `Todo/` directory.
6. **Redis funnel lease + limiter reshape.** New `ConcurrencyLease`; reshape `ConcurrencyLimiter` (constructor tagging, `acquire()`, `block()` sugar, `claimSlot()`); builder `acquire()`/`then()`/`createLimiter()`; both limiter files gain the `use function Hypervel\Support\now;` import. Update `tests/Redis/ConcurrencyLimiterTest.php` + `ConcurrencyLimiterBuilderTest.php` (rename expectations, lease lifecycle, ms deadline, misrouted-callback-exception fix) and port the cluster test additions from Laravel's `d6be8af77c` `tests/Redis/ConcurrencyLimiterTest.php`.
7. **Cache funnel leases + limiter reshape.** New `ConcurrencyLease`/`RefreshableConcurrencyLease`; reshape base limiter + `RedisConcurrencyLimiter` (tagging + the `$store->lock($result, $this->releaseAfter, $id)` construction) + builder. Update `tests/Cache/ConcurrencyLimiterTest.php`; extend `tests/Integration/Cache/CacheFunnelTestCase.php` + `PhpRedisCacheFunnelTest.php` with lease lifecycle per driver, the fast-path TTL regression test (acquire via the redis store with a short `releaseAfter`, call `refresh()`, assert `getRemainingLifetime()` is re-extended — this fails against a `seconds = 0` restored lock), serializer/compression coverage for lease release/refresh (follow `PhpRedisCacheLockTest`'s serializer matrix), and prefix × hash-tag × slot-key round-trip tests.
8. **`DurationLimiter::block()` ms loop.** Update `tests/Redis/DurationLimiterTest.php` if timing expectations exist.
9. **`RedisQueue` cluster keys.** Cached flag, `getQueueRedisKey()`, call-site sweep against Laravel's current file. Port the `QueueRedisQueueTest` cluster additions into `tests/Queue/QueueRedisQueueTest.php` (file verified to exist). `tests/Integration/Queue/RedisQueueTest.php` exercises the same call sites against real Redis (non-cluster) — run it after the sweep to prove the non-cluster path is byte-for-byte unchanged; it needs no new cluster coverage of its own.
10. **New cross-cutting tests.** In `tests/Integration/Redis/ConcurrencyLimiterIntegrationTest.php` and `tests/Integration/Cache/CacheFunnelTestCase.php`: leaked-lease-TTL-reclaim (acquire, don't release, assert slot reusable after `releaseAfter`), two-lease non-interference (two leases on one limiter; releasing/refreshing one never affects the other; a lease constructed with a wrong owner cannot release/refresh a held slot), lease `refresh()` extends the TTL observably (`getRemainingLifetime()` grows), timeout path throws the unified exception.
11. **Docs.** See below. Then `./vendor/bin/phpstan`, `./vendor/bin/php-cs-fixer fix`, `composer test:parallel`.

## Testing plan (summary of coverage)

- **Locks per driver (redis/database/array/file/no):** refresh happy path, refresh by non-owner fails, refresh after expiry fails, refresh with explicit TTL, explicit zero/negative throws, permanent + `refresh(null)` ownership check, `getRemainingLifetime()` (live/missing/expired/permanent), `isLocked()` (held/expired/released), database `refresh(null)` re-extends the default timeout, expired database/array locks report unowned/unlocked.
- **Redis funnel:** lease acquire/release/refresh/lifetime/timeout, `then()` still releases on success and exception, `block()` no-callback fire-and-forget, callback-thrown `LimiterTimeoutException` no longer misrouted to `$failure`, `maxLocks < 1` still yields timeout, cluster tagging (mocked `isCluster()`: slots become `{name}1..N`, ARGV[1] tagged, non-cluster unchanged, pre-tagged names untouched).
- **Cache funnel:** lease per store type (`RefreshableConcurrencyLease` for redis/database/array/file-backed, `ConcurrencyLease` for `HasCacheLock` stores), fast-path lease is refreshable **and `refresh(null)` re-extends the acquisition TTL** (regression for the `seconds = 0` restored-lock trap), serializer/compression matrix, prefix × cluster-tag × slot-key round-trip.
- **Queue:** cluster key wrapping for queue/delayed/reserved/notify, non-cluster keys unchanged, pre-tagged queue names untouched.
- **Database integration ports:** the formerly-skipped `Todo/` placeholders (`DatabaseLockTest`, `DatabaseCacheStoreTest`) run for real, diffed against current upstream (step 5).
- **Regression:** every bug in the "Bugs this plan fixes" list gets a dedicated test.

No new `flushState()`/`AfterEachTestSubscriber` entries: no new static state is introduced. No new CI services: cluster behavior is unit-tested with mocked `isCluster()`.

## Documentation plan

- **`src/boost/docs/cache.md`:** funnel section gains the lease API (`acquire()`, holding across operations, `refresh()` heartbeats, `release()` in `finally`, `instanceof RefreshableLease`, leaked-lease TTL reclaim); Refreshing Locks section updated (FileLock now refreshable — remove the file-locks note; permanent-refresh ownership semantics; database default-timeout re-extension; new exception namespace); Managing Locks gains `isLocked()`.
- **`src/boost/docs/redis.md`:** new section covering `Redis::funnel()` (builder, `then()`, `acquire()` leases) and `Redis::throttle()` — currently undocumented anywhere; clusters section notes automatic hash-tagging of funnel slots and queue keys.
- **`src/boost/docs/queues.md`:** `Redis::throttle()` examples keep working as-is; add the cluster hash-tag note where the Redis queue driver is described.
- **READMEs:** `src/cache/README.md` and `src/redis/README.md` Differences From Laravel entries per §7.

Docs are targeted at advanced humans + LLMs: be comprehensive, document beyond what Laravel documents (leases, cluster behavior, permanent semantics per driver).
