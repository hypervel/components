# First-Party `hypervel/rate-limiter` Package Plan

## Plan status

This is the implementation plan for replacing Hypervel's cache-bound rate limiter with a dedicated first-party `hypervel/rate-limiter` component. It is a final-codebase plan, not a compatibility or phased-migration plan. The implementation must remove the old rate-limiter implementation and every obsolete alternate path in the same change. There must be one canonical API and no aliases, shims, deprecated wrappers, stale tests, stale documentation, or TODO entries describing code that no longer exists.

The plan deliberately does not preserve source compatibility. Hypervel 0.4 is a work in progress, and the desired end state is the code that would have been written if this package had existed from the start.

## Desired outcome

Create `src/rate-limiter` as a split first-party package with these properties:

- Canonical namespace: `Hypervel\RateLimiter`.
- Canonical facade: the existing `Hypervel\Support\Facades\RateLimiter`, resolving the new `Hypervel\RateLimiter\RateLimiter` manager.
- No `Hypervel\Cache\RateLimiter` class, no `Hypervel\Cache\RateLimiting` namespace, and no aliases back to either namespace.
- The cache repository is not part of the driver contract. Each driver owns its atomic state transition and native storage representation.
- Fixed-window admission, GCRA-backed leaky-bucket admission, and capped exponential failure backoff are distinct typed policies. There is no generic `strategy` string plus nullable bag of unrelated parameters.
- Redis, Swoole, database, and array are first-party stores. File and generic cache stores are intentionally not supported.
- Redis performs one pooled checkout and one `EVALSHA` in the steady-state admission path, with the existing `evalWithShaCache()` NOSCRIPT fallback on the first execution per Redis node.
- Swoole performs one short striped-lock critical section over native integer columns, with no PHP serialization.
- The database store uses a dedicated `rate_limits` table, not `cache` or `cache_locks`, and performs an atomic transaction with a row lock.
- The public decision result contains everything middleware needs. No caller performs a second read to calculate remaining capacity or retry timing after a consume.
- Store failures fail closed by propagating an exception. The package must never silently bypass a configured limit or fall back to a weaker/local store.
- Route, queue, Fortify, exception-reporting, and Reverb consumers are rewritten to use the new API directly.

## Explicit non-goals

- Do not retain Laravel's primitive `tooManyAttempts($key, $maxAttempts)` / `hit($key, $decay)` API merely for parity. That split API cannot express one atomic decision and is the central design problem being removed.
- Do not build a compatibility layer under `Hypervel\Cache`.
- Do not introduce a generic cache-backed driver.
- Do not add a file driver. A correct file implementation would require a dedicated locked state format, and it would add a low-throughput production surface without an unmet use case because the database driver is the portable shared fallback.
- Do not implement token bucket, sliding-log, sliding-window-counter, linear backoff, Fibonacci backoff, reservations, blocking waits, or distributed multi-limit transactions in this change. The type and store boundaries must permit future additions, but speculative algorithms must not produce unused code.
- Do not introduce a process-global service/version registry as part of this package. The related framework capability work is separately recorded in `docs/todo.md`.
- Do not use Redis Functions as an alternate deployment mode. Functions require server-side library lifecycle/permissions and create an operational branch without improving the one-round-trip steady-state contract over cached Lua scripts.

## Decisions

### 1. A dedicated package replaces the cache implementation

The package must be `hypervel/rate-limiter`, not an extension inside `hypervel/cache`.

The generic cache contract is a poor atomic-algorithm boundary:

- Laravel/Hypervel's current fixed-window path performs separate timer add, counter add, increment, and sometimes put operations.
- `RedisStore` still serializes/deserializes ordinary values and only has special-case bypasses for counters.
- `SwooleStore` serializes values, takes a row lock, unserializes, increments, and serializes again.
- `DatabaseStore` serializes payloads and implements generic cache semantics rather than an admission decision.
- `FileStore` adds filesystem I/O, payload serialization, and locking concerns.

A dedicated driver can instead expose exactly one atomic state transition and return the entire decision.

### 2. There is one canonical namespace and one facade

Applications import policy classes from `Hypervel\RateLimiter` and may continue using `Hypervel\Support\Facades\RateLimiter`. Constructor injection uses `Hypervel\RateLimiter\RateLimiter`.

Do not add a `Hypervel\Cache\RateLimiter` alias or wrapper. Two class locations would be more confusing for LLMs because the Laravel-looking location would expose a different API. Add the namespace/API difference to `AGENTS.md` instead.

### 3. Stores are drivers; policies are typed definitions

The manager resolves named stores from `rate-limiter.stores`. Each store has a `driver` string, following Laravel's manager/config conventions. The algorithm is selected by the policy object's concrete type:

- `Limit` is the familiar fixed-window policy.
- `LeakyBucket` is the smoothed admission policy, implemented with GCRA.
- `Unlimited` bypasses storage.
- `Backoff::exponential(...)` returns an `ExponentialBackoff` failure policy.

Adding an admission algorithm later means adding a typed policy and implementing its state transition in each supported store. That is intentional: atomic algorithms and their storage primitives are coupled. A single strategy DTO would hide that coupling and accumulate irrelevant fields.

### 4. Backoff is not an admission algorithm

Exponential backoff is based on failures and success/reset events; fixed window and leaky bucket decide whether a unit of work may be admitted. They therefore use different operations:

- admission: `consume`, `inspect`, `clear`;
- failure penalty: `inspect`, `recordFailure`, `clear`.

They may share a store and decision interface, but `recordFailure` must remain explicit. A request must never increase exponential backoff merely because it was attempted.

Fibonacci is not included. It is occasionally used as a retry-delay sequence, but it is not a common server-side rate-limiting algorithm. For request admission, token bucket, leaky bucket/GCRA, and sliding-window variants are the established alternatives. For distributed client retries, capped exponential backoff with jitter is the common pattern; jitter is not appropriate for the deterministic server-enforced lockout state in this package.

### 5. No strategy or driver enums

Do not add enums for driver names or algorithms:

- driver names are an open extension point via `extend()` and must remain config/env strings;
- `UnitEnum|string` is accepted by `store()`, `for()`, and relevant middleware APIs, preserving convenient application enums without closing extension;
- concrete policy classes already provide compile-time strategy identity;
- a `Strategy` enum would duplicate the class hierarchy and invite invalid parameter combinations.

An enum should be introduced later only if a genuinely closed domain appears. None is needed in the initial package.

### 6. Database is a first-class shared fallback with its own table

The database store is worthwhile for applications that need cross-node correctness but do not operate Redis. It must use a dedicated table because cache-table rows are serialized generic payloads with cache expiration semantics and cannot express an atomic limiter transition efficiently.

Database is the default store for a fresh application because it is shared across application nodes and does not assume Redis is running. Documentation must clearly recommend Redis for high-throughput distributed limiting and Swoole for deliberate single-node/process-cluster limiting.

### 7. Redis uses portable Lua now; native `INCREX` is a tracked future optimization

The initial Redis implementation uses portable Lua for all algorithms. Do not ship runtime command/version branching.

Verified constraints as of 2026-08-04, including direct `COMMAND INFO INCREX`/execution checks against the official `redis:8.6-alpine`, `redis:8.8-alpine`, and `valkey/valkey:9-alpine` images:

- Redis 8.8 provides `INCREX`; Redis 8.6 does not.
- Valkey 9 does not provide `INCREX`.
- Valkey PR [#3253](https://github.com/valkey-io/valkey/pull/3253) remains open and adds expiry/existence options to the INCR family; it is related but does not currently provide Redis `INCREX`'s bounded-operation/result semantics.
- phpredis 6.3.0 exposes neither `Redis::increx()` nor `RedisCluster::increx()`.
- `Redis::rawCommand()` bypasses `OPT_PREFIX`; this was verified against Redis 8.8 (`raw-key` remained unprefixed while an EVAL key became `prefix:eval-key`).
- `RedisCluster::rawCommand()` has the different signature `rawCommand($key_or_address, $command, ...$args)`, so a generic standalone raw-command call is not cluster-safe.
- Direct `INCREX` returns the new counter and applied increment but not the remaining TTL required for `Retry-After`/reset metadata. A second command, a pipeline/transaction, or a Lua wrapper would still be needed for the framework's full result.

A local indicative Redis 8.8 `redis-benchmark` run (300,000 requests, 50 clients, random keyspace) measured approximately 61.6k requests/s for direct `INCREX`, 48.4k for a portable full-result Lua script, and 50.5k for Lua wrapping `INCREX` plus `PTTL`. These figures are not a release benchmark, but they show that the native primitive may eventually be useful while also showing that full framework semantics reduce the direct-command advantage.

`docs/todo.md` now records the prerequisites and future benchmark. The implementation must also put this focused comment immediately beside the portable fixed-window Redis script:

```php
// @TODO Re-benchmark a native INCREX implementation when equivalent bounded
// increment-with-expiry semantics are supported by Redis and Valkey and exposed
// by phpredis with prefix-aware Redis Cluster routing. Keep the portable Lua
// path until then; docs/todo.md records the compatibility details.
```

Remove the comment and the documentation TODO together when the native implementation actually replaces Lua.

## Research findings that constrain implementation

- Current Laravel and Hypervel expose no strategy parameter. Preserve `RateLimiter::for()`, `Limit::perMinute()`, `by()`, `after()`, `response()`, and routing middleware helpers, but replace the split cache check/hit/read engine.
- Hypervel's normal middleware is non-atomic; its Redis variant keeps request-local result data on a worker singleton and ignores `after()`. Both recorded defects are acceptance items.
- Migrate routing, queue middleware, Fortify, Foundation exception throttling, Reverb, the facade/provider/config, tests, documentation, and package metadata. Keep Redis `DurationLimiter`/`ConcurrencyLimiter` as separate blocking primitives.
- Retain the archived package's one-call decision, weighted consumption, Redis Lua, and typed bucket ideas. Do not retain JSON/whole-second state, the timeout race, invalid fractional admission, or multi-key Cluster scripts.
- The requested Laravel packages add no safe driver boundary: Oltrematica is configuration-oriented, while milenmk's progressive lockout replays cache hits and is not concurrency-safe. Fibonacci remains deliberately unimplemented; exponential backoff is a separate failure policy.
- Symfony supports typed policies, weighted consumption, and rich results, but its generic storage/lock path is not the Redis hot-path design. `go-redis/redis_rate` and Cloudflare support the single-TAT GCRA representation.

Implementation references: [Laravel rate limiting](https://laravel.com/docs/13.x/rate-limiting), [Symfony RateLimiter](https://symfony.com/doc/current/rate_limiter.html), [Redis scripting](https://redis.io/docs/latest/develop/programmability/eval-intro/), [Redis Functions](https://redis.io/docs/latest/develop/programmability/functions-intro/), [Redis TIME](https://redis.io/docs/latest/commands/time/), [Redis INCREX](https://redis.io/docs/latest/commands/increx/), [Redis Cluster](https://redis.io/docs/latest/operate/oss_and_stack/reference/cluster-spec/), [Google retry guidance](https://docs.cloud.google.com/storage/docs/retry-strategy), [OWASP authentication throttling](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), and [Cloudflare rate-limiting algorithms](https://blog.cloudflare.com/counting-things-a-lot-of-different-things/).

## Public API

### Manager and store selection

`Hypervel\RateLimiter\RateLimiter` extends `MultipleInstanceManager`. It owns named limiter callbacks and resolves per-store `Limiter` instances. The facade delegates unknown methods to the default store through the manager, matching Laravel manager conventions. The package name is `hypervel/rate-limiter`, not `hypervel/rate-limit`: the former names the component being provided, while `RateLimit` is the policy abstraction consumed by that component.

```php
namespace Hypervel\RateLimiter;

/** @mixin \Hypervel\RateLimiter\Limiter */
class RateLimiter extends MultipleInstanceManager
{
    public function store(UnitEnum|string|null $name = null): Limiter;

    public function getDefaultInstance(): string;

    public function setDefaultInstance(string $name): void;

    public function getInstanceConfig(string $name): array;

    public function for(
        UnitEnum|string $name,
        Closure $callback,
        UnitEnum|string|null $store = null,
    ): static;

    public function limiter(UnitEnum|string $name): ?Closure;

    public function limiterStore(UnitEnum|string $name): ?string;

    public function resolveKeyScopeUsing(?Closure $resolver): void;

    // Inherited: extend(string $driver, Closure $callback): static.
}
```

Examples:

```php
RateLimiter::consume(
    Limit::perMinute(60)->by("user:{$user->id}"),
);

RateLimiter::store('redis')->consume(
    LeakyBucket::perSecond(100)->burst(200)->by("api:{$token}"),
);
```

`store()` accepts `UnitEnum|string|null` and normalizes enums through `enum_value()`. Built-in `create*Driver()` methods and custom `extend()` callbacks return a `Contracts\Store`; the manager's protected `resolve()` wraps that store in one `Limiter`. This keeps key resolution and unlimited handling out of drivers while giving third-party drivers a small native-operation contract. A custom creator therefore has the familiar shape `fn (Application $app, array $config): Store` rather than having to construct a framework wrapper.

The optional third argument to `for()` selects the store for that named limiter, so an application can keep login lockouts in the database while routing API traffic through Redis. `limiterStore()` exposes that normalized registration to framework consumers; `null` means use the current default store. Queue middleware's explicit `store()` modifier overrides the registered store. Keep the callback and store in synchronized manager-owned maps rather than adding a named-limiter descriptor class.

Resolved stores capture immutable configuration. `setDefaultInstance()`, `for()`, `resolveKeyScopeUsing()`, `extend()`, `forgetInstance()`, and `purge()` are explicitly boot/test-only under the repository's coroutine rules. The `Limiter` receives a resolver closure owned by the manager so a named-policy key can include the limiter name without mutating the policy object.

### Fixed-window policy

Keep Laravel's most recognizable policy name and factories while making the value immutable from the caller's perspective. Fluent modifiers return a new copy rather than mutating a cached definition.

```php
use Hypervel\RateLimiter\Limit;

$limit = Limit::perMinute(120)
    ->by("uploads:{$user->id}")
    ->cost(5)
    ->after(fn (Response $response): bool => $response->isSuccessful())
    ->response(fn (Request $request, array $headers): Response => response('Slow down', 429, $headers));
```

Factories:

- `perSecond(int $maxAttempts, int $decaySeconds = 1)`;
- `perMinute(int $maxAttempts, int $decayMinutes = 1)`;
- `perMinutes(int $decayMinutes, int $maxAttempts)`;
- `perHour(int $maxAttempts, int $decayHours = 1)`;
- `perDay(int $maxAttempts, int $decayDays = 1)`;
- `none(): Unlimited`.

Modifiers shared by admission policies:

- `by(Stringable|UnitEnum|string|int $key): static` (normalized once to a string with `enum_value()` for enums);
- `cost(int $cost): static` (positive, and no greater than the policy's capacity);
- `globally(bool $global = true): static` (bypasses Hypervel's named key-scope resolver);
- `after(callable $callback): static`;
- `response(callable $callback): static`.

Retain Laravel's convenient readable-property shape, but make the properties `public readonly`: `key`, `cost`, `global`, `afterCallback`, and `responseCallback` on `RateLimit`; `maxAttempts` and `decaySeconds` on `Limit`; and `rate`, `periodMicroseconds`, and `burst` on `LeakyBucket`. Each modifier constructs/copies a fully validated new value. Do not expose mutable public state, reflection-based cloning, or a generic `options` array. Internal stores may read these typed properties directly without getter-call overhead.

`globally()` replaces `GlobalLimit`; do not carry a second class solely to mark scope behavior.

### Leaky-bucket policy

```php
use Hypervel\RateLimiter\LeakyBucket;

RateLimiter::for('api', function (Request $request) {
    return LeakyBucket::perSecond(100)
        ->burst(200)
        ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
}, store: 'redis');
```

Factories mirror `Limit`'s names and argument order: `perSecond(int $rate, int $decaySeconds = 1)`, `perMinute(int $rate, int $decayMinutes = 1)`, `perMinutes(int $decayMinutes, int $rate)`, `perHour(int $rate, int $decayHours = 1)`, and `perDay(int $rate, int $decayDays = 1)`. The rate is the sustained number of tokens emitted over the period. `burst(int $capacity)` is the total immediately available capacity, not “extra” capacity. It defaults to the factory's rate argument, matching the least-surprising reading of `perSecond(100)` while still replenishing continuously; strict smoothing is the explicit `->burst(1)` case. `cost()` cannot exceed `burst()`.

Document that the backend implementation is GCRA, which provides leaky-bucket behavior with constant state rather than running a leak timer.

### Decisions

```php
final readonly class LimitResult implements Decision
{
    public function allowed(): bool;
    public function denied(): bool;
    public function limit(): int;
    public function remaining(): int;
    public function retryAfter(): int;
    public function resetAfter(): int;
}
```

All public durations are integer seconds rounded up from the driver's finer internal precision:

- `retryAfter()` is `0` when the requested cost was accepted and otherwise is the minimum wait until that cost can be accepted;
- `resetAfter()` is the remaining fixed-window duration or time until a leaky bucket is full;
- `limit()` is fixed-window capacity or leaky-bucket burst capacity;
- `remaining()` is immediately consumable whole-token capacity after the decision.

For `inspect()`, no consumption occurs, so `remaining()` is the capacity available in the observed state; `allowed()` answers whether this policy's configured cost could be consumed now. For an accepted `consume()`, remaining is measured after the cost is committed. For a denied consume, it is the unchanged current capacity. This distinction must be identical across stores and explicit in result tests.

The common `Decision` contract contains `allowed()`, `denied()`, and `retryAfter()` so middleware can handle admission and backoff results without a loose array.

### Limiter operations

```php
final class Limiter
{
    public function getStore(): Contracts\Store;

    public function consume(RateLimit $limit, UnitEnum|string|null $limiterName = null): LimitResult;

    public function inspect(RateLimit|Backoff $policy, UnitEnum|string|null $limiterName = null): LimitResult|BackoffResult;

    public function attempt(RateLimit $limit, Closure $callback, UnitEnum|string|null $limiterName = null): mixed;

    public function recordFailure(Backoff $backoff, UnitEnum|string|null $limiterName = null): BackoffResult;

    public function clear(RateLimit|Backoff $policy, UnitEnum|string|null $limiterName = null): bool;
}
```

`RateLimit` is the abstract admission-policy base implemented by `Limit`, `LeakyBucket`, and `Unlimited`; `Backoff` is a separate failure-policy base. `consume()` is the normal one-call atomic operation. `inspect()` never mutates state. `attempt()` atomically consumes before invoking the callback and returns `false` on denial; if a callback returns `null`, it returns `true`, preserving Laravel's convenient semantics. If the callback throws, the accepted token remains consumed. Code that should charge only on failure or on a response predicate must use `inspect()` followed by the appropriate explicit operation.

The optional `limiterName` is only identity context for a policy obtained from `RateLimiter::for()`. Routing and queue middleware must pass it; direct calls omit it. It is deliberately not a mutable hidden field on a policy and not the selected store name. This closes the collision between two named limiters that return otherwise identical policies while keeping direct policy use terse.

### Exponential backoff

```php
use Hypervel\RateLimiter\Backoff;

$backoff = Backoff::exponential(
    after: 5,
    initialDelay: 1,
    maxDelay: 300,
    resetAfter: 3600,
)->by("login:{$username}:{$ip}");

if (RateLimiter::inspect($backoff)->denied()) {
    // Return the result's retryAfter().
}

try {
    authenticate();
    RateLimiter::clear($backoff);
} catch (AuthenticationException $exception) {
    $result = RateLimiter::recordFailure($backoff);
    throw $exception;
}
```

`Backoff::exponential(...)` returns a typed `ExponentialBackoff`. The fifth failure in the example creates the initial one-second block. Each subsequent failure after the block is eligible doubles the delay, capped at `maxDelay`. `resetAfter` resets failure history after inactivity and must be at least `maxDelay`. A success calls `clear()`.

As with admission policies, expose validated `public readonly` fields (`key`, `after`, `initialDelay`, `maxDelay`, and `resetAfter`) and make `by()` return a new value. Keep integer seconds at the public boundary and convert once to the driver's internal microseconds.

`BackoffResult` exposes `allowed/denied`, `failures`, and `retryAfter`. Do not add jitter: server-enforced lockout state should be deterministic. Client retry code may apply jitter independently.

### Named limiter resolution

Keep `RateLimiter::for()` and the existing optional named-key scope resolver. Policy objects are immutable, and named resolution must not rewrite duplicate objects.

The physical identity includes:

1. a package-domain/version segment and the configured rate-limiter prefix;
2. named limiter name when present;
3. optional resolved Hypervel scope for a named limiter unless `globally()` was selected;
4. caller key from `by()` (an empty key intentionally means a shared/global policy);
5. policy type and canonical parameters.

Each segment is domain-tagged and length-prefixed before the entire identity is hashed with seeded `xxh128`. Derive the stable integer seed once when resolving the store as `hexdec(substr(hash('xxh128', 'rate-limiter|' . $prefix), 0, 15))`, using only the validated limiter prefix. Do not use `app.key` or generate a process-local seed: rotating an unrelated encryption key must not clear active limiter state, and every worker/application node with the same limiter prefix must derive identical physical keys. Domain tags distinguish, for example, a limiter name from a caller key; length prefixes keep arbitrary normalized strings injective. Keys are normalized to strings first, so equivalent `1`, `'1'`, a stringable `'1'`, and an enum value `'1'` intentionally identify the same bucket. The configured prefix is a pre-hash application namespace, so every driver still receives the same fixed 32-character lowercase hexadecimal key. Redis's connection-level `OPT_PREFIX` and a database connection's table prefix remain outside this digest and are applied exactly once by those components.

Policy callbacks and request cost are excluded from the policy fingerprint; the global-scope flag is included because it changes policy identity. Including stable policy parameters means two limits with the same `by()` value but different windows/algorithms naturally have different state, and changing policy configuration starts clean state while the old TTL expires. The Laravel fallback-key mutation is unnecessary. Add golden-vector tests for the canonical encoding so an apparently harmless refactor cannot orphan all active state.

Always hash physical identities. Remove `ThrottleRequests::shouldHashKeys()` and its process-global switch. The Swoole key itself remains a fixed 32-character digest, safely below Swoole Table's key limit.

## Internal package architecture

Target layout (names may move only if implementation reveals a concrete repository convention conflict):

```text
src/rate-limiter/
├── README.md
├── composer.json
└── src/
    ├── ArrayStore.php
    ├── Backoff.php
    ├── BackoffResult.php
    ├── Console/
    │   ├── PruneCommand.php
    │   ├── RateLimiterTableCommand.php
    │   └── stubs/rate-limits.stub
    ├── Concerns/
    │   └── CalculatesRateLimits.php
    ├── Contracts/
    │   ├── Decision.php
    │   ├── PrunableStore.php
    │   └── Store.php
    ├── DatabaseStore.php
    ├── Exceptions/
    │   ├── InvalidRateLimitException.php
    │   └── SwooleTableFullException.php
    ├── ExponentialBackoff.php
    ├── LeakyBucket.php
    ├── Limit.php
    ├── Limiter.php
    ├── LimitResult.php
    ├── RateLimit.php
    ├── RateLimiter.php
    ├── RateLimiterServiceProvider.php
    ├── RedisStore.php
    ├── Swoole/
    │   ├── CreateTables.php
    │   ├── PruneTables.php
    │   ├── TableManager.php
    │   └── TableState.php
    ├── SwooleStore.php
    └── Unlimited.php
```

Avoid an `Algorithms` service hierarchy. Policies hold validated immutable configuration. Array, Swoole, and database stores share typed integer transition math through `CalculatesRateLimits`; Redis implements the same semantics in Lua. Both paths use a small exhaustive `instanceof` dispatch, never descriptor arrays or strategy enums. An unsupported policy throws `InvalidRateLimitException`.

`RateLimiter::resolve()` calls the parent driver resolver, asserts that the built-in/custom creator returned `Contracts\Store`, and constructs the `Limiter` with a physical-key resolver using the then-current validated prefix plus the manager-owned optional scope callback. Built-in `createArrayDriver()`, `createDatabaseDriver()`, `createRedisDriver()`, and `createSwooleDriver()` therefore return stores, not wrappers. Resolve/freeze static key configuration when the lazy store wrapper is created; do not read the config repository on every consume. Keep the manager's instance cache as the sole wrapper/store cache; do not add a second registry.

### Store contract

The contract receives an already-resolved fixed-length physical key and validated policy:

```php
interface Store
{
    public function consume(string $key, RateLimit $limit): LimitResult;

    public function inspect(string $key, RateLimit|Backoff $policy): LimitResult|BackoffResult;

    public function recordFailure(string $key, Backoff $backoff): BackoffResult;

    public function clear(string $key): bool;
}
```

```php
interface PrunableStore
{
    public function pruneExpired(int $chunkSize = 1000): int;
}
```

`Limiter` intercepts `Unlimited` before dispatch, so a store never reads or writes for it. Keep this contract inside `hypervel/rate-limiter`; external drivers necessarily depend on the package, so moving it to the global contracts package would add indirection without decoupling.

### Configuration

As an always-installed framework component, the canonical defaults live in `src/foundation/config/rate-limiter.php`, alongside Cache, Queue, and Concurrency configuration. Mirror that file into the application and Testbench skeletons. Add `'rate-limiter' => ['stores']` to `LoadConfiguration::mergeableOptions()` so application stores merge by name; this declares merge policy only and does not duplicate configuration values. `RateLimiterServiceProvider` must not merge or publish a second package-owned config file.

```php
return [
    'default' => env('RATE_LIMITER_STORE', 'database'),

    'stores' => [
        'database' => [
            'driver' => 'database',
            'connection' => env('RATE_LIMITER_DB_CONNECTION'),
            'table' => env('RATE_LIMITER_DB_TABLE', 'rate_limits'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('RATE_LIMITER_REDIS_CONNECTION', 'default'),
        ],

        'swoole' => [
            'driver' => 'swoole',
            'rows' => (int) env('RATE_LIMITER_SWOOLE_ROWS', 65536),
            'conflict_proportion' => 0.2,
            'prune_interval' => 60000,
        ],

        'array' => [
            'driver' => 'array',
        ],
    ],

    'prefix' => env('RATE_LIMITER_PREFIX', app_id() . '_rate_limiter'),
];
```

Use typed config getters and validate every store at resolution. The service provider registers the database migration generator and prune commands.

`prefix` is an application namespace included in the canonical identity before its final hash; it is not concatenated onto the 32-character physical key. This preserves cross-application isolation without variable-length Swoole keys. It is separate from Redis `OPT_PREFIX` and database table prefixes.

Do not add algorithm defaults to global config. Rates belong to typed application policy definitions, not storage configuration.

Policy construction performs store-independent range validation against the strictest shared numeric representation, including Redis Lua's largest exactly representable integer (`9_007_199_254_740_991`) and signed 64-bit Swoole/database columns. Reject a leaky-bucket rate greater than its period's microsecond count because its sub-microsecond emission interval cannot be represented without changing the promised rate. Driver operations then validate time-dependent additions (for example `now + emission * burst`) before mutation. Reject an unrepresentable policy with `InvalidRateLimitException`; do not add arbitrary-precision math, saturate silently, or let the same policy work on one first-party store and corrupt on another.

## Algorithm specifications

### Fixed window

Semantics match Laravel's first-hit-anchored interval rather than a calendar-aligned window:

1. An absent/expired key starts a window with the requested cost and the configured duration.
2. An existing key accepts only if `current + cost <= maxAttempts`.
3. A denied request does not increment the counter or extend the window.
4. Remaining capacity is `maxAttempts - current` after an accepted operation and the current remaining capacity after denial.
5. Retry/reset is the existing TTL rounded up.

All drivers must implement the same boundary behavior, including weighted costs equal to capacity and rejected costs over capacity.

### Leaky bucket / GCRA

Use integer microseconds and one theoretical-arrival-time (TAT) state value:

```text
emission = ceil(period_microseconds / rate)
candidate_tat = max(stored_tat, now) + emission * cost
allowed_at = candidate_tat - emission * burst
allowed = now >= allowed_at
```

On acceptance, persist `candidate_tat` with a TTL long enough for the bucket to become completely full. On denial, leave stored state unchanged. Retry time is `allowed_at - now`, rounded up to seconds. Remaining immediate capacity is the number of whole emissions between the stored debt and `now + emission * burst`, clamped to `[0, burst]`.

Use the post-operation TAT for an accepted consume and the stored TAT for inspect/denial:

```text
remaining = clamp(floor((now + emission * burst - effective_tat) / emission), 0, burst)
reset = max(effective_tat - now, 0)
```

An absent/fully drained bucket uses `effective_tat = now`, so inspect reports the full burst. Persist the accepted state for `ceil(reset / 1000)` milliseconds on Redis (minimum one millisecond while state is non-empty) and exact microseconds on numeric local/database stores. A driver may encounter a physically present but logically drained record and must treat it as empty without extending stale state.

Use Redis `TIME`, Swoole `hrtime(true)`, and database server time (except local SQLite, which uses wall-clock microseconds) so distributed Redis/database decisions do not depend on the application node's clock. Clamp negative elapsed time to zero defensively. Validate microsecond resolution, integer overflow, Redis Lua's exact-integer range, positive rates/periods, and burst/cost limits before accessing storage.

### Exponential backoff

State is failure count, blocked-until time, and expiration/inactivity time. On `recordFailure`:

1. Reset stale state whose inactivity deadline has passed.
2. Increment failures.
3. If failures are below `after`, return allowed with no delay.
4. Otherwise calculate `min(initialDelay * 2 ** (failures - after), maxDelay)` without an overflowing exponent.
5. Set blocked-until to now plus the delay.
6. Set expiry to at least both blocked-until and now plus `resetAfter`.

`inspect` never increments failures. `clear` removes all state. Recording a failure while already blocked is allowed only when the application explicitly calls it; normal callers inspect first and will not execute blocked work.

## Driver details

### Redis store

- Hold the Redis factory and connection name, and resolve the lightweight cached proxy by name at operation start rather than retaining it inside the store. A `RedisManager::purge()` can then replace the proxy/pool generation without leaving the rate limiter pinned to a stale proxy; the steady path pays only the manager's array lookup before its one pool checkout.
- Use `RedisProxy::withConnection()` and `RedisConnection::evalWithShaCache()`.
- One `EVALSHA` is the steady-state operation. A NOSCRIPT response may cause one fallback `EVAL` for the first execution on a node.
- Pass exactly one key to each script. Redis Cluster can route it without cross-slot behavior; do not invent multi-key hash-tag grouping.
- Rely on the EVAL key path for configured phpredis prefixing. Do not use raw commands or manually duplicate the Redis connection prefix.
- Store raw integer/string/hash state directly. Never pass it through cache serialization/compression.
- Use one Redis string plus TTL for a fixed counter, one Redis string TAT plus TTL for GCRA, and one small hash (`failures`, `available_at`) plus inactivity TTL for backoff. The policy fingerprint fixes the type for a key, so no strategy tag or JSON envelope is needed.
- Set TTL atomically in the script. Every algorithm returns the same five-integer tuple—accepted flag, limit, remaining, retry microseconds, reset microseconds—even when the Redis command uses milliseconds. Convert `PTTL` milliseconds to microseconds inside the fixed-window script and validate the converted values; result decoding never guesses a unit from the policy type.
- Validate every returned tuple's arity, integer types, flags, and non-negative/range invariants before constructing a result; `false`, `nil`, truncation, or malformed data must throw rather than cast into an allowed decision.
- Keep script bodies as private constants or dedicated internal operation classes only if file length warrants it. Do not build a generic script framework.

Fixed-window Lua shape:

```lua
local cost = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])
local durationMilliseconds = tonumber(ARGV[3])
local durationMicroseconds = durationMilliseconds * 1000

local function start_window()
    redis.call('SET', KEYS[1], cost, 'PX', durationMilliseconds)
    return {1, limit, limit - cost, 0, durationMicroseconds}
end

local raw = redis.call('GET', KEYS[1])

if not raw then
    return start_window()
end

local current = tonumber(raw)
if not current or current < 0 or current > limit or current % 1 ~= 0 then
    return redis.error_reply('CORRUPT rate limiter counter')
end

local ttl = redis.call('PTTL', KEYS[1])
if ttl == -1 then
    return redis.error_reply('CORRUPT rate limiter counter has no expiry')
end
if ttl <= 0 then
    return start_window()
end

local next = current + cost

if next > limit then
    return {0, limit, limit - current, ttl * 1000, ttl * 1000}
end

redis.call('SET', KEYS[1], next, 'KEEPTTL')
return {1, limit, limit - next, 0, ttl * 1000}
```

The production script must keep every result numeric, provide an inspect mode without creating a missing key, and include the required `@TODO` immediately beside it. A present fixed-window key with a non-integer value, negative/out-of-range count, or no expiry (`PTTL == -1`) is corrupt: raise a Lua error and propagate it rather than deleting the key and potentially failing open. A zero/expired TTL is a real boundary condition and starts a fresh window atomically. Validate impossible costs in PHP so the script never creates an over-capacity first value.

Do not alter `RedisConnection::callEvalsha()` for this package; the correct `evalWithShaCache()` path already exists and has real Redis integration coverage.

### Swoole store

- Own a dedicated `Swoole\Table`; do not reuse `SwooleStore` or `SwooleTableManager` from cache.
- Columns are `value`, `available_at`, and `expires_at`, all `Table::TYPE_INT` with an explicit 8-byte width.
- Use a fixed 32-character hashed key.
- Resolve the package-local `Swoole\TableManager` as an unbound concrete, using Hypervel's auto-singleton behavior rather than an explicit container binding. `CreateTables` asks it to resolve every configured Swoole limiter store during `BeforeServerStart`; later `createSwooleDriver()` retrieves that same named `TableState`. This is the necessary registry between pre-fork allocation and lazy store resolution, not a second rate-limiter/store cache.
- Create every configured Swoole limiter table and its striped `Swoole\Atomic` locks before server fork, so all workers share them. Structural options (`rows`, columns, conflict proportion, store names) are restart-only. If a running worker requests a Swoole store whose table was not created before fork, throw a lifecycle/configuration exception rather than silently allocate a worker-private table. Console/tests may explicitly initialize a table before concurrent use.
- Extract the proven 64-stripe Atomic lock coordinator from cache's `SwooleTableState` into a small `Hypervel\Core\Swoole\StripedLock` primitive used by both Cache and RateLimiter. It owns key-to-stripe selection, short spin/backoff acquisition, all-lock acquisition required by Cache, and release; it does not own a table, cache columns, limiter state, or arbitrary multi-key transactions. Keep both packages' table managers and state formats independent.
- Move Cache's existing injectable `SwooleTimer` wrapper to `Hypervel\Core\Swoole\Timer` and use that same two-method `tick()`/`clear()` test seam from Cache and RateLimiter lifecycle listeners. Do not create a package-local duplicate or expand it into a scheduler abstraction.
- Perform read/check/write within one row lock. No serialization, closures, cache repository, or generic eviction policy appears in the hot path.
- Use `intdiv(hrtime(true), 1000)` for a host-monotonic microsecond clock shared by workers.
- Expired rows are reclaimed on access. Worker 0 owns a periodic expiry scan timer; stop it on worker exit. Timer/full-table pruning must lock and re-read each candidate before deletion so a concurrent renewal cannot be removed from under another worker.
- If insertion fails, log one warning for the synchronous full-table prune attempt, perform one expired-row prune, and retry once. Inject `Psr\Log\LoggerInterface`; no logging lookup belongs in the hot path. If the table remains full of live rows, throw `SwooleTableFullException`. Never evict a live limiter entry, because eviction would fail open. A full scan is permitted only on this exceptional capacity path or the background timer, never on ordinary admission.
- Document that Swoole is host-local and is not a distributed rate limiter across servers.
- Document sizing as `rows >= peak concurrently live physical keys × headroom`, where a key remains live for its window/refill/inactivity TTL. Include examples for per-IP cardinality and explain that the warning indicates exhausted headroom before a live-only table begins failing closed.

### Database store

Hold `ConnectionResolverInterface` and the configured connection name, not a concrete pooled `Connection`. Resolve once at operation start; Hypervel's coroutine resolver then retains that connection for the full transaction/operation and releases it normally, while pool purge/reconnect can still supply a new generation later.

Migration generated by `make:rate-limiter-table` (`rate-limiter:table` alias):

```php
Schema::create('rate_limits', function (Blueprint $table) {
    $table->char('key', 32)->primary();
    $table->unsignedBigInteger('value')->default(0);
    $table->unsignedBigInteger('available_at')->default(0);
    $table->unsignedBigInteger('expires_at')->index();
});
```

State mapping:

- fixed window: `value = consumed`, `available_at = reset_at`, `expires_at = reset_at`;
- leaky bucket: `value = TAT`, `available_at = 0`, `expires_at = full_refill_at`;
- exponential backoff: `value = failures`, `available_at = blocked_until`, `expires_at = inactivity expiry`.

The strategy and parameters are already in the hashed physical key, so a strategy column and JSON payload are unnecessary. This representation is compact, queryable, portable across Hypervel's MySQL, MariaDB, PostgreSQL, and SQLite connections, and avoids serialization.

Mutating operation:

```php
return $connection->transaction(function ($connection) use ($key, $policy) {
    $connection->table($table)->insertOrIgnore([
        'key' => $key,
        'value' => 0,
        'available_at' => 0,
        'expires_at' => 0,
    ]);

    $row = $connection->table($table)
        ->where('key', $key)
        ->lockForUpdate()
        ->first();

    $now = $this->currentTimeInMicroseconds($connection);

    // Compute and persist one validated state transition, then return its result.
}, attempts: 3);
```

The initial `insertOrIgnore` solves the first-row race and also obtains SQLite's writer lock before reading; `lockForUpdate` provides row serialization on MySQL/MariaDB/PostgreSQL. Fetch time after lock acquisition so lock wait does not make the decision's timestamp stale.

`inspect()` is intentionally different: select without inserting or locking, read the clock, and return a best-effort snapshot. Both its row query (`useWritePdo()`) and server-time scalar must use the primary/write PDO so a configured read replica cannot return stale limiter state or a clock from a different server, but it must not create, refresh, delete, or otherwise mutate a row. `clear()` is a direct keyed delete. Only `consume()` and `recordFailure()` use the insert/lock transaction.

Use database-server microsecond time for MySQL/MariaDB and PostgreSQL: `FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) * 1000000)` for MySQL/MariaDB and `FLOOR(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint` for PostgreSQL. These exact expressions were executed successfully during plan review against MySQL 9.5, MariaDB 10.11, and PostgreSQL 17. PostgreSQL must use `clock_timestamp()`, not the transaction-start timestamp returned by `CURRENT_TIMESTAMP`. SQLite is local/non-distributed and may use application wall-clock microseconds. Return the scalar as a decimal/integer and range-check it before casting. Keep driver-specific clock SQL private and covered by integration tests; reject unsupported database drivers instead of silently choosing an application clock, and do not create a general database capability framework in this package.

Add `rate-limiter:prune {store?} {--chunk=1000}` for stores implementing `PrunableStore`. Database pruning uses a fixed current-time cutoff and bounded, portable batches: select at most the configured number of expired keys from the write connection, then delete only those keys that still satisfy `expires_at <= cutoff`. This avoids one unbounded delete/lock while ensuring a concurrently renewed row is skipped. Validate a positive bounded chunk size, stop when a selection is short/empty, and report the total deleted. Recommend scheduling it hourly. Do not add random bulk-delete queries to admission's hot path.

The prune command resolves `$manager->store($name)->getStore()` and rejects stores that do not implement `PrunableStore`; it must not reach through the container to a concrete database implementation.

The database driver is correctness-first and will require several SQL statements in a transaction; documentation must not present it as equivalent to Redis throughput.

### Array store

- Use an in-process numeric state array and a monotonic clock.
- The name follows Laravel manager conventions, but its scope must be explicit in docs: it is shared for the lifetime of one Hypervel worker, not coroutine-local and not shared across workers.
- It is suitable for tests and deliberately local workloads such as Reverb per-connection message limits, because a connection remains owned by one worker and Reverb clears its key on close.
- Operations contain no suspension point, so a transition is atomic within one cooperative worker; it does not coordinate processes or hosts.
- Lazily discard an expired entry whenever its key is touched. Do not add an abandoned-key scheduler/expiry index in the initial store or perform an unbounded whole-array sweep in a request hot path; rely on explicit `clear()`, Reverb close cleanup, and worker recycling for this deliberately local/test store.
- Do not call this store `worker-array`; that name belongs to cache semantics.

## Framework consumer refactor

### Routing

Rewrite `ThrottleRequests` around `LimitResult`:

This refactor must close, and then remove, both existing Redis entries in `docs/todo.md`; they are acceptance requirements for this package replacement, not follow-up work:

- keep remaining/reset/decision data in request-local variables so the singleton middleware has no worker-lifetime per-key state and same-key concurrent requests cannot overwrite one another's headers;
- implement `after()` for Redis-backed policies with non-mutating inspection followed by a conditional atomic consume.

- Inline `throttle:60,1` creates a fixed `Limit` and consumes it once.
- Preserve the public `ThrottleRequests::using()` and `ThrottleRequests::with()` helpers, middleware pipe syntax, named-limiter lookup and `MissingRateLimiterException` behavior. These are ergonomic Laravel routing APIs, not cache-counter compatibility methods.
- Named callbacks retain `Response`, `Unlimited`, one policy, or an ordered array of policies.
- Resolve the named limiter's registered store once for the request, or use the default store when none was registered. For a normal named policy, call that store's `consume($policy, $limiterName)` once and retain the local result for exception/header generation. Inline policies use the default store and omit the name.
- For a named `after()` policy, call the selected store's `inspect($policy, $limiterName)` before the downstream handler; after the response, call `consume($policy, $limiterName)` only when the predicate returns true. A concurrent post-response consume may be denied after the response has already been admitted; return headers from that result but do not retroactively throw. Document/test this inherent response-dependent semantic.
- Use `retryAfter()` and `remaining()` from the local result. Remove second reads and all request state from singleton middleware properties.
- Preserve Laravel-compatible headers: successful responses use `X-RateLimit-Limit`/`X-RateLimit-Remaining`; denied responses additionally use `Retry-After` and an absolute `X-RateLimit-Reset` derived from `retryAfter()`. Do not substitute leaky-bucket full-refill `resetAfter()` for the earliest retry time. For leaky policies, document that the limit/remaining header pair describes burst capacity while the policy definition describes the sustained rate.
- With multiple policies, retain the header pair for the most restrictive (lowest remaining) local result and do not overwrite an application-provided lower `X-RateLimit-Remaining` value.
- Ordered multiple policies consume sequentially. If a later policy denies, earlier accepted policies remain consumed. Do not add an all-or-nothing multi-key API: it cannot be implemented consistently across stores and Redis Cluster, and preflight checks would reintroduce races.
- Remove `ThrottleRequestsWithRedis`.
- Remove `Middleware::$throttleWithRedis`, `throttleWithRedis()`, the `$redis` argument to `throttleApi()`, alias branching, kernel priority entries, docs, and tests. Store selection now belongs to `rate-limiter.default`.
- Remove `ThrottleRequests::shouldHashKeys()` because key hashing is an invariant of the new limiter.
- Return raw normalized user/route/IP signatures from `resolveRequestSignature()` and remove its private `formatIdentifier()` helper; the canonical limiter hashes the full identity once.

### Queue `RateLimited`

- Resolve the named policy through the manager and use its registered store, falling back to the configured default; pass the limiter name into each `consume()` so named identities remain isolated.
- Add a Laravel-style `store(UnitEnum|string $store): static` modifier that overrides the named limiter's registered store for this queued job. Serialize only limiter name, explicit store override, release delay, and release behavior; resolve a non-overridden registered/default store after wakeup.
- Consume each policy once and release denied jobs using `result->retryAfter() + 3` unless explicitly overridden.
- Preserve ordered partial-consumption semantics for multiple policies, matching routing; do not add a queue-only preflight or rollback protocol.
- Remove `RateLimitedWithRedis`; a named Redis-backed rate-limiter store replaces both the class and connection-specific implementation.

### Queue `ThrottlesExceptions`

- Represent its existing “N failures in decay window” behavior with a fixed `Limit` keyed to the job.
- `inspect()` before running the job; `consume()` only when a qualifying exception occurs; `clear()` after success.
- Add the same `store()` selector and remove `ThrottlesExceptionsWithRedis`; this middleware constructs its policy directly, so it uses the default store unless explicitly overridden.
- Persist only the selected store name with the middleware/job; resolve the manager/wrapper inside `handle()` and never serialize a resolved backend store or Redis proxy.
- Keep its existing `backoff()` method for the ordinary queue retry delay; do not conflate that delay with the package's server-enforced `ExponentialBackoff` policy.
- Preserve the Laravel-style optional second callback argument, but always pass the selected package `Limiter` wrapper to `when()` and `report()` callbacks. Redis and non-Redis paths must no longer expose different concrete/cache limiter objects.
- Stop pre-hashing the job class inside `getKey()` because the canonical limiter hashes the complete identity. Replace the misleading Laravel-interoperability prefix/comment—the new state format is intentionally not cache-compatible—with `hypervel:queue:throttles-exceptions:` while retaining `withPrefix()` for callers that choose another namespace.

### Fortify

Rewrite `LoginRateLimiter` around a private fixed-policy factory. `inspect()` supplies too-many/remaining/retry state, `consume()` records a failed login, and `clear()` records success. Preserve all five existing public methods: `attempts()` returns `result->limit() - result->remaining()` for this fixed policy, `tooManyAttempts()` uses `denied()`, `increment()` consumes, `availableIn()` returns `resetAfter()` (the current method reports the active window even before denial), and `clear()` removes the policy state. Preserve guard/username/IP scoping. Do not silently change Fortify's fixed five-per-minute policy to exponential backoff; applications may opt into `Backoff` separately.

Update the Fortify provider stub imports to `Hypervel\RateLimiter\Limit`.

### Foundation exception reporting

Use the policy returned by the throttle callback directly:

```php
$resolvedExceptionKey = $throttle->key ?: 'hypervel:foundation:exceptions:' . $e::class;

return ! $this->container->make(RateLimiter::class)->attempt(
    $throttle->by($resolvedExceptionKey),
    fn (): bool => true,
);
```

Keep Lottery and Unlimited handling. Remove primitive key/max/decay calls and the handler's redundant pre-hashing/property; the dedicated limiter hashes every canonical identity.

### Reverb

Inject/resolve the new manager and use `store('array')` for per-connection message limiting. Build the same fixed policy for consume and close-time clear. Remove direct construction of a cache `RateLimiter` and the dependency on `cache.worker-array` for this feature.

### Facade and provider

- `Hypervel\RateLimiter\RateLimiter` is a concrete manager and therefore uses Hypervel's normal unbound-concrete auto-singleton behavior; do not add a redundant container binding or alias. `RateLimiterServiceProvider` registers only its commands and lifecycle listeners. Foundation owns the default config.
- Add it unconditionally to `Hypervel\Support\DefaultProviders` between `QueueServiceProvider` and `RedisServiceProvider`, preserving the list's package ordering. Store creation remains lazy, so provider order does not force a backend connection. The framework must not rely on package discovery to obtain its limiter.
- Update the support facade accessor and generated method annotations to the new manager/policies/results.
- Remove only the limiter binding from `CacheServiceProvider`; its cache commands/listeners remain cache-owned. Register the new table/prune commands exclusively from `RateLimiterServiceProvider`.

## Package and repository metadata

Add/update all of the following:

- root `composer.json` PSR-4 mapping for `Hypervel\RateLimiter\`;
- root `replace` entry for `hypervel/rate-limiter`;
- `src/rate-limiter/composer.json`, auto-discovered provider, authors/support/branch alias, sorted requirements;
- exact direct requirements for `ext-swoole`, `hypervel/collections` (including `enum_value()`), config, console, container, contracts, core events/primitives, database, Redis, support, `psr/log`, and `symfony/console`, pruning anything implementation does not actually import; PHP's mandatory Hash extension needs no Composer requirement;
- `hypervel/rate-limiter` dependencies in routing, queue, Fortify, foundation, and Reverb package manifests;
- remove `hypervel/cache` from packages where the limiter was its only cache use; retain unrelated cache/Redis dependencies after checking all imports;
- facade API documentation metadata;
- `Hypervel\RateLimiter` package entry in any package inventories/documentation lists.

Move only the generic striped-lock and timer behavior described above into Core and update Cache imports/tests in the same change. Do not move Cache's table manager, string-value validation, eviction state, or exceptions that the numeric limiter table does not use.

Do not add a reverse `hypervel/rate-limiter` dependency to `hypervel/support`. Support is the lower-level package used by the new manager and service provider; its facade and default-provider references follow the repository's existing optional facade/provider bridge convention. The always-installed framework metapackage provides both packages, while an independently installed rate-limiter package already requires Support in the correct direction.

The existing split script automatically discovers `src/*`; no hard-coded split list should be added unless the current script changes.

After consumer imports are rewritten, remove `hypervel/cache` from routing, Fortify, and Reverb if the repository-wide import audit still confirms that rate limiting was their only actual cache-package use. Queue and foundation have unrelated cache responsibilities and retain that dependency. Add the new package as a direct dependency wherever its symbols are imported even if another package would provide it transitively.

Coordinate the two adjacent official repositories in the same release:

- add `hypervel/rate-limiter` to `contrib/hypervel/framework/composer.json`, sorted with the other split components;
- add `config/rate-limiter.php` to the `contrib/hypervel/hypervel` application skeleton;
- because the skeleton selects the database limiter store by default, add `database/migrations/0001_01_01_000008_create_rate_limits_table.php` after its current `000007` failed-jobs migration so a fresh application works immediately, while retaining the generator for existing applications;
- add `RATE_LIMITER_STORE=database` and commented connection/prefix overrides to the skeleton environment example, update lock/config documentation, and run each repository's own metadata/config/migration tests. Do not modify the private `packages/hypervel` repositories unless a concrete import audit finds an actual consumer.

Keep provider auto-discovery metadata in the split package so it works when independently required, matching other core components, but also assert `RateLimiterServiceProvider`'s presence in `DefaultProviders`. Discovery is not the framework's availability mechanism; its alphabetical placement is a code-style requirement, not runtime behavior that needs a brittle order test.

Within components, add `src/testbench/hypervel/config/rate-limiter.php` and `src/testbench/hypervel/migrations/0001_01_01_000008_testbench_create_rate_limits_table.php` after the current `000007` failed-jobs migration. Update `CommanderTest`'s expected migration inventory, every `WithMigration`/default-database assertion that enumerates framework tables, and rollback/refresh coverage. Testbench must model a fresh skeleton accurately; limiter tests must not create the default table ad hoc.

## Removal and cleanup inventory

Delete after consumers compile against the new package:

- `src/cache/src/RateLimiter.php`;
- `src/cache/src/RateLimiting/Limit.php`;
- `src/cache/src/RateLimiting/GlobalLimit.php`;
- `src/cache/src/RateLimiting/Unlimited.php`;
- cache provider limiter binding;
- `cache.limiter` config and its documentation block from `src/foundation/config/cache.php`;
- `src/routing/src/Middleware/ThrottleRequestsWithRedis.php`;
- `src/queue/src/Middleware/RateLimitedWithRedis.php`;
- `src/queue/src/Middleware/ThrottlesExceptionsWithRedis.php`;
- Foundation middleware Redis-throttle switch/state/API;
- the `ThrottleRequests::flushState()` call in `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`, because removing the hash opt-out leaves the middleware with no static state and no empty cleanup method should remain;
- all old cache-rate-limiter unit/integration tests once their behavior is covered under `tests/RateLimiter`;
- the rate-limiter case from `RedisCacheIntegrationTest` (retain its actual Redis cache tests) after equivalent native Redis coverage exists under `tests/RateLimiter`;
- Inertia's old namespace/config override, Reverb's worker-array state assertions, Testbench's `cache.limiter` default assertions, and every other cross-package test fixture discovered by the stale-symbol search; rewrite them against the new package rather than merely deleting behavioral coverage;
- all docs and examples importing `Hypervel\Cache\RateLimiter` or `Hypervel\Cache\RateLimiting\*`;
- the two existing Redis TODO bullets about `ThrottleRequestsWithRedis` state and `after()` after the unified middleware makes them obsolete.
- the overall `Rate Limiting` implementation TODO added for this package once every acceptance item is complete; retain only the future native-`INCREX` and framework capability TODOs because they describe intentionally deferred work that still exists.

Do not delete `Hypervel\Redis\Limiters\DurationLimiter` or its builder merely because request/queue middleware no longer imports it. `Redis::throttle()` and other Redis limiter APIs still use it. Audit its remaining references and leave it as a separate Redis concurrency/throttle primitive.

As a bounded adjacent Redis optimization, change `DurationLimiter` and `ConcurrencyLimiter` from sending their full Lua body through `eval()` on every operation to the existing NOSCRIPT-aware `evalWithShaCache()` path, preserving their public APIs and cluster key handling. Add focused unit and real-Redis coverage; do not otherwise fold those blocking concurrency/throttle primitives into the new package.

At the end, repository-wide searches (excluding archived code, third-party examples, vendor, and historical `docs/plans`/`.tmp/plans` artifacts) must return no old namespace, no removed middleware class, no `throttleWithRedis`, and no `cache.limiter` in executable code, tests, configuration, stubs, or maintained user/agent documentation. Historical plans are records, not supported documentation, and must not be rewritten as part of this change.

Add `tests/Integration/RateLimiter` explicitly to both the Redis 8 and Valkey 9 command lists in `.github/workflows/redis.yml`; that workflow enumerates integration directories and will not discover the new suite automatically. The database workflow already discovers driver directories and needs no equivalent path edit.

## Documentation work

Update every applicable Boost document, not just the main rate-limiting page:

- `routing.md`: named policies, leaky bucket, weighted cost, response-based semantics, stores, headers, and removal of `throttleWithRedis`;
- update the existing `src/boost/docs/rate-limiting.md` in place as the single canonical rate-limiting document: cover the package architecture, direct consume/inspect/attempt/clear APIs, typed policies and results, fixed-window/leaky-bucket/backoff behavior, driver selection and guarantees, configuration, database migration/pruning, custom drivers, distribution boundaries, performance guidance, and failure behavior. Do not add a competing `rate-limiter.md` page;
- `queues.md`: store selection and removal of Redis-specific middleware classes;
- `fortify.md`, `errors.md`, `starter-kits.md`: imports and new typed calls;
- `facades.md`: canonical accessor/class;
- `middleware.md`: one throttle middleware class;
- database docs: `make:rate-limiter-table`, schema purpose, pruning schedule;
- package README: only the package heading, the canonical Boost documentation link, and concise public `Differences From Laravel`; omit an upstream link because this independently maintained package does not track a source package.

`src/boost/docs-ported.md` already registers `rate-limiting.md`; retain that single inventory entry and do not add `rate-limiter.md` there or anywhere else.

Add a concise explicit divergence to root `AGENTS.md`: Laravel locates its cache-bound limiter under `Illuminate\Cache`; Hypervel's canonical implementation is `hypervel/rate-limiter` / `Hypervel\RateLimiter`, uses typed policies and dedicated stores, and has no Cache namespace alias. This is the instruction LLMs should see when porting.

For intentionally omitted or deliberately changed Laravel behavior, follow the repository's three-place rule: concise package README differences, concise comments at the natural source insertion points, and `REMOVED:` markers at matching upstream test locations. Cover the `Hypervel\Cache` location, primitive counter methods, Redis-specific middleware classes/switch, `GlobalLimit`, hash opt-out, atomic `attempt()` consuming before the callback and retaining the charge on callback failure, sequential stacked-policy consumption that retains earlier charges when a later policy denies, and truthful non-zero remaining capacity on a weighted denial. Explain the replacement behavior and cover these semantics in Boost docs/tests. Do not add entries to `docs/ai/differences-vs-laravel.md`, which is queued for deletion.

While updating root `AGENTS.md` with the rate-limiter divergence, remove its stale instructions to maintain `docs/ai/differences-vs-laravel.md`; that document's own header already marks it for deletion. Do not leave contradictory agent guidance in the touched file.

Do not copy internal research criticism into user documentation. Public docs should state the supported design clearly.

## Testing plan

Create `tests/RateLimiter` and use the repository-required base test/coroutine conventions. Run each changed/new test file individually before the package suite.

### Policy/value tests

- Every fixed-window and leaky-bucket factory converts periods correctly; leaky factories default burst to the sustained token count and `burst(1)` opts into strict smoothing.
- Invalid zero/negative capacity, rate, duration, burst, cost, and backoff settings throw named exceptions.
- Numeric boundary tests cover the shared Lua-exact/signed-64 limits and every overflow-prone multiplication/addition before a store mutation.
- Fluent methods return new copies and do not mutate the original policy.
- `globally`, scope, callbacks, cost, and response callbacks are retained correctly.
- Policy fingerprints are stable for a limiter prefix, change when the prefix or policy parameters change, distinguish policy types, and exclude cost/callbacks.
- Arbitrary key segments cannot create ambiguous preimages before hashing.
- Unlimited performs no store operation.
- `LimitResult` and `BackoffResult` round timing up correctly and never expose negative remaining/retry values.
- Manager default/named/`UnitEnum` store resolution, named-limiter registered stores, explicit queue overrides, one-instance caching, purge/forget behavior, typed configuration failures, and a custom `extend()` callback returning `Contracts\Store` all produce the expected wrapped `Limiter` without a second cache.
- Named limiter identity differs by limiter name, scope, global flag, normalized key value, policy type, and stable parameters exactly as specified; equivalent scalar/stringable/enum key values normalize identically, and direct policies do not accidentally invoke the named scope resolver.
- The shared typed PHP calculator produces the same transitions used by array, Swoole, and database stores without descriptor arrays or floating-point state.

### Shared store contract suite

Run one behavioral contract against array, Swoole, SQLite, MySQL, MariaDB, PostgreSQL, and the existing CI services for Redis 8 and Valkey 9. Do not add redundant Redis point-release jobs for a portable Lua path with no version branch, and do not claim a supported first-party store/server combination from mocks alone.

- first consume, exact-capacity consume, weighted consume, over-capacity denial;
- denied consume does not mutate count or extend TTL;
- inspection does not create/mutate state;
- clear and expiration reset;
- fixed-window boundary immediately before/after reset;
- leaky-bucket initial burst, smooth recovery, weighted retry, full refill, denial immutability;
- exponential threshold, doubling, cap, inactivity reset, success clear;
- same physical semantics for every store to the precision promised by public seconds;
- corrupted/wrong-type backend state fails explicitly rather than allowing work.

### Concurrency tests

- Array: multiple coroutines in one worker admit exactly capacity.
- Swoole: multiple coroutines and forked workers admit exactly capacity and do not lose updates.
- Database: concurrent transactions against an absent key and an existing key admit exactly capacity; include SQLite writer serialization plus MySQL/PostgreSQL row locks in integration CI.
- Redis: many concurrent pooled clients admit exactly capacity for fixed and leaky bucket; test weighted costs.
- Redis Cluster: one-key scripts route without CROSSSLOT and work with configured prefixes.
- No driver allows stored state above capacity.

### Redis-specific tests

- Steady path calls `evalSha` and a node's first NOSCRIPT response falls back to `eval` through existing `evalWithShaCache()`.
- Script false/nil/error handling is not mistaken for NOSCRIPT.
- Serializer/compression configuration does not affect limiter state.
- Redis connection `OPT_PREFIX` is applied once.
- `TIME`-based leaky/backoff calculations ignore application-clock skew.
- TTL is applied atomically and is unchanged on denial.
- Redis 8 and Valkey 9 run the exact same Lua implementation.
- Add a focused assertion/test fixture guarding the required `@TODO`/portable path only if repository conventions permit source-shape tests; otherwise the docs TODO and code comment are sufficient.

### Swoole-specific tests

- Core `StripedLock` preserves Cache's row/all-lock behavior and timeout coverage after extraction; RateLimiter creates the same lock primitive before fork.
- Core `Timer` preserves Cache's injectable timer lifecycle coverage and is reused by RateLimiter without a package-local wrapper.
- Table columns are 8-byte integers and table creation occurs before fork.
- Same-key locks isolate transitions; different stripes can proceed independently.
- Expired rows are pruned by timer and on access.
- Full table logs the pressure warning, retries after pruning once, and then throws without evicting live state.
- Timer is registered only by worker 0 and cleaned on exit/recycle.
- Repeated worker lifecycle hooks do not register duplicate prune timers or retain stale timer IDs.
- Store state never serializes a PHP value.

### Database-specific tests

- Generated migration SQL/schema is valid for all four supported database families.
- `insertOrIgnore` plus lock handles simultaneous first use.
- Server time is read after lock acquisition.
- PostgreSQL uses current wall time rather than transaction-start time.
- Prune command rejects non-prunable stores, targets a named database store, and deletes only expired rows.
- Pruning validates/chunks work, terminates across multiple full batches, reports the exact total, and rechecks expiry in the delete.
- Concurrent pruning never deletes a row renewed by an in-flight consume transaction.
- Configured connection/table/prefix are honored.
- Inspection uses the primary/write PDO even when the connection has read replicas configured.
- No cache/cache-lock table query occurs.
- Testbench's standard migration set creates, refreshes, and rolls back `rate_limits`, and its exact migration inventory/config assertions include the new package defaults.

### Framework integration tests

- Foundation loads the rate-limiter defaults, application stores merge by name while a same-named store replaces its whole definition, and the provider does not perform a second merge.
- Application/Testbench config and environment defaults select the database store without requiring Redis.
- Routing inline and named fixed limits.
- `ThrottleRequests::using()`, `ThrottleRequests::with()`, middleware pipe syntax, missing named limiters, and custom responses retain their Laravel-facing behavior.
- Named leaky-bucket routing, registered/default store selection, weighted costs, custom response, global/scope behavior, and multiple policy ordering.
- Response-based `after()` with matching/non-matching response and a concurrent post-response consume.
- Header values come from the local decision and remain isolated across same-key concurrent requests; weighted denials retain truthful non-zero remaining capacity when the rejected cost exceeds it.
- Atomic `attempt()` charges before invoking the callback and retains the charge on an exception; stacked policies retain earlier successful charges if a later policy denies.
- Queue release timing, `dontRelease`, explicit store, and job serialization/wakeup.
- `ThrottlesExceptions` consumes only qualifying failures and clears on success.
- Fortify fixed lockout and clearing.
- Foundation exception report throttling.
- Reverb per-connection isolation and close cleanup with array store.
- Facade resolves the canonical manager.
- An existing provider/application integration test asserts that `DefaultProviders` contains `RateLimiterServiceProvider`, independently of package discovery; do not create composer-manifest tests or assert alphabetical order as runtime behavior.
- Middleware configuration contains only `ThrottleRequests` and has no Redis switch.
- Existing Redis Duration/Concurrency limiter tests prove the SHA-cache migration preserves results, connection selection, prefixes, and cluster slot handling; real Redis verifies NOSCRIPT fallback.

### Static/quality checks

For every changed PHP file:

1. run the directly related test file;
2. run formatter/linter required by the repository;
3. run PHPStan for the touched package/scope;
4. run the package test group;
5. run integration groups for Redis, database, routing, queue, Fortify, foundation, and Reverb;
6. run `git diff --check`;
7. run stale-symbol searches described above.

Do not weaken PHPStan types, suppress errors, or widen return types to accommodate test mocks. Fix contract/test doubles correctly.

## Performance validation

Add a reproducible developer-only CLI harness under `tests/Benchmarks/RateLimiter/`, including documented backend inputs; do not register a production Artisan command or treat PHPUnit timing as a benchmark. The harness must exercise the framework manager, pool, driver, result decoding, and middleware-relevant operation—not only a raw backend command—so its numbers represent the code being shipped.

Measure at minimum:

- fixed-window and leaky-bucket consume through Redis, Swoole, and one explicitly labeled configured database backend;
- representative single-client and contended concurrency on allowed-heavy and denied-heavy paths, with the exact workload recorded in the output rather than a mandatory combinatorial matrix;
- a one-time old cache-backed fixed-limiter baseline versus the new drivers before old code is removed; retain the recorded comparison, not a compatibility adapter or old implementation in the final harness;
- p50/p95/p99 latency and operations/second. Measure pool wait, backend CPU, memory, or extra server versions ad hoc only when the core results expose a concrete question.

Acceptance invariants:

- Redis steady-state admission is one network round trip, one pool checkout, and one script invocation.
- No Redis cache serialization/compression path is entered.
- Swoole's ordinary admission path performs no serialization and no I/O; only the exceptional full-table path logs capacity pressure.
- Middleware performs no post-consume state lookup for ordinary limits.
- Material throughput/latency regressions against the old baseline or between supported Redis and Valkey services are explained before merge; optimize the portable script rather than adding a premature version branch.

The future `INCREX` TODO must be revisited with the same end-to-end result contract and benchmarks, not a raw-command microbenchmark alone.

## Implementation sequence

This order keeps the tree buildable while still delivering one final cut with no compatibility residue:

1. Add package metadata/provider skeleton, Foundation/application/Testbench config, root autoload/replace entry, and default provider registration.
2. Add immutable policies, fingerprints/key resolver, decisions, contracts, manager, shared typed PHP calculator, and per-store `Limiter` wrapper with unit tests.
3. Implement array store with the shared calculator and run the full store contract against it.
4. Implement Redis Lua transitions using `evalWithShaCache()`, including the required focused `@TODO`; run the existing Redis 8/Valkey 9 integration jobs and concurrency tests after adding their explicit RateLimiter path.
5. Extract the generic striped lock and existing cache timer seam into Core, update Cache, then implement the independent numeric Swoole table/state/timer/pruning and multi-worker tests.
6. Implement database store with the shared calculator, migration/prune commands, server clocks, default migrations, and database integration/concurrency tests.
7. Rewrite routing and Foundation middleware configuration; delete the Redis-specific request middleware/switch once tests pass.
8. Rewrite queue middleware and remove the two Redis-specific queue classes.
9. Rewrite Fortify, foundation exception throttling, Reverb, and facade access.
10. Move/replace rate-limiter tests into `tests/RateLimiter`; remove cache rate-limiter classes/config/binding/tests.
11. Update every composer dependency, Boost document, minimal README/divergence record, facade annotation, AGENTS divergence/stale references, package inventory, and explicit Redis workflow path.
12. Update the official framework metapackage and application skeleton dependency/config/base migration, verifying those repositories under their own instructions.
13. Move the surviving Redis Duration/Concurrency limiter scripts onto `evalWithShaCache()` with focused tests.
14. Remove the completed package TODO and obsolete Redis middleware-defect TODO bullets while retaining the native-increment and framework capability TODOs.
15. Run stale-code searches, per-package suites, cross-package integration suites, static analysis, benchmarks, and `git diff --check`.

No step should add a temporary alias or dual API. If intermediate local compilation requires ordering, make the consumer and provider changes in the same working change before handoff.

## Final verification checklist

- [ ] `Hypervel\RateLimiter` is the sole namespace; its facade and unconditional default provider resolve the new manager with no Cache shim or dual API.
- [ ] Fixed, GCRA/leaky-bucket, unlimited, and exponential-backoff policies are typed; no strategy/driver enum, descriptor bag, or speculative algorithm exists.
- [ ] Redis/Swoole/database/array pass the shared semantic and concurrency suites; failures never fail open and no driver routes through generic cache serialization.
- [ ] Redis admission is one cached Lua call on the existing Redis 8 and Valkey 9 services; Swoole uses shared numeric state without live eviction and documents/logs capacity pressure; database uses only `rate_limits`.
- [ ] Foundation, the application skeleton, and Testbench carry matching config/default migrations; named stores merge without a duplicate package config.
- [ ] Routing retains its Laravel-facing helpers, syntax, callbacks, exceptions, headers, and registered-store selection with one middleware; queue and Reverb have no Redis/cache limiter branches.
- [ ] The framework metapackage, package dependencies, facade metadata, Boost's single `rate-limiting.md`, minimal README, AGENTS guidance, and required source/test difference markers agree.
- [ ] Old namespaces, classes, config, tests, docs, switches, stale state, and obsolete TODOs are absent; the INCREX and capability TODOs remain accurate.
- [ ] Existing Redis Duration/Concurrency limiters use the tested SHA-cache path without API changes.
- [ ] Static analysis, all affected suites, end-to-end benchmarks, stale-symbol searches, and `git diff --check` pass.
