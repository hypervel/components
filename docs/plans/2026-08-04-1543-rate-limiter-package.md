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
- Redis, Swoole, database, and worker-array are first-party stores. File, request-local array, and generic cache stores are intentionally not supported.
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

- `AdmissionPolicy` is the clearly named admission-policy base; Laravel's familiar concrete `Limit` remains the fixed-window policy.
- `LeakyBucket` is the smoothed admission policy, implemented with GCRA.
- `Unlimited` bypasses storage.
- `Backoff::exponential(...)` returns a concrete exponential failure policy.

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
- The portable fixed-window script can use `INCRBY` for an accepted existing window. It has existed since Redis 1.0 and changes the string value in place, so Redis/Valkey preserve the existing TTL without Redis 6's `SET ... KEEPTTL`. The complete `EVAL`/`TIME`/`PTTL`/`SET PX`/`INCRBY` path was also executed against Redis 5.0.14 with its TTL intact. Do not advertise a broader server support matrix without CI, but do not introduce an unnecessarily new command floor either.

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

Implementation references: [Laravel rate limiting](https://laravel.com/docs/13.x/rate-limiting), [Symfony RateLimiter](https://symfony.com/doc/current/rate_limiter.html), [Redis scripting](https://redis.io/docs/latest/develop/programmability/eval-intro/), [Redis Functions](https://redis.io/docs/latest/develop/programmability/functions-intro/), [Redis TIME](https://redis.io/docs/latest/commands/time/), [Redis INCRBY](https://redis.io/docs/latest/commands/incrby/), [Redis expiry preservation](https://redis.io/docs/latest/commands/expire/), [Redis INCREX](https://redis.io/docs/latest/commands/increx/), [Redis Cluster](https://redis.io/docs/latest/operate/oss_and_stack/reference/cluster-spec/), numeric command-argument bridges in [Redis 5](https://github.com/redis/redis/blob/5.0/src/scripting.c#L426-L432), [Redis 8](https://github.com/redis/redis/blob/8.0/src/script_lua.c#L799-L814), and [Valkey 9](https://github.com/valkey-io/valkey/blob/9.0.0/src/lua/script_lua.c#L829-L844), [Google retry guidance](https://docs.cloud.google.com/storage/docs/retry-strategy), [OWASP authentication throttling](https://cheatsheets.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), and [Cloudflare rate-limiting algorithms](https://blog.cloudflare.com/counting-things-a-lot-of-different-things/).

## Public API

### Manager and store selection

`Hypervel\RateLimiter\RateLimiter` extends `MultipleInstanceManager`. It owns named limiter callbacks and resolves per-store `Limiter` instances. The facade delegates unknown methods to the default store through the manager, matching Laravel manager conventions. The package name is `hypervel/rate-limiter`, not `hypervel/rate-limit`: the former names the component being provided, while `AdmissionPolicy` clearly names the admission abstraction and Laravel's `Limit` remains the fixed-window policy applications use.

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

`store()` accepts `UnitEnum|string|null` and normalizes enums through `enum_value()`. Built-in `create*Driver()` methods and custom `extend()` callbacks return a `Contracts\Store`; the manager's protected `resolve()` wraps that store in one `Limiter`. This keeps key resolution and unlimited handling out of drivers while giving third-party drivers a small native-operation contract. A custom creator therefore has the familiar shape `fn (Application $app, array $config): Store` rather than having to construct a framework wrapper. The manager supplies `config['name']` as the requested store key after merging application configuration, overwriting any configured value so built-in and third-party drivers may treat it as authoritative.

The optional third argument to `for()` selects the store for that named limiter, so an application can keep login lockouts in the database while routing API traffic through Redis. `limiterStore()` exposes that normalized registration to framework consumers; `null` means use the current default store. Queue middleware's explicit `store()` modifier overrides the registered store. Keep the callback and store in synchronized manager-owned maps rather than adding a named-limiter descriptor class.

Resolved stores capture immutable configuration. `setDefaultInstance()`, `for()`, `resolveKeyScopeUsing()`, `extend()`, `forgetInstance()`, and `purge()` are explicitly boot/test-only under the repository's coroutine rules. The `Limiter` receives a resolver closure owned by the manager so a named-policy key can include the limiter name without mutating the policy object.

`MultipleInstanceManager::setApplication()` is tests-only and must refresh both the application and its cached configuration repository without rebuilding already resolved instances. Apply the same correction to `MailManager`, which keeps the same cached configuration reference, and cover both setters with focused same-test application-swap regressions. Normal test teardown already discards container-owned managers, so do not add subscriber cleanup or manager `flushState()` methods.

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

- `by(Stringable|UnitEnum|string|int|null $key): static` (normalized once to a string, with `null` intentionally matching Laravel's empty/shared key);
- `cost(int $cost): static` (positive; the final capacity relationship is validated at operation time so fluent order is irrelevant);
- `globally(bool $global = true): static` (bypasses Hypervel's named key-scope resolver);
- `after(callable $callback): static`;
- `response(callable $callback): static`.

Retain Laravel's convenient readable-property shape, but make the properties `public readonly`: `key`, `cost`, `global`, `afterCallback`, and `responseCallback` on `AdmissionPolicy`; `maxAttempts` and `decaySeconds` on `Limit`; and `rate`, `periodMicroseconds`, and `burst` on `LeakyBucket`. `AdmissionPolicy` declares a protected copy hook receiving every shared field; each concrete policy implements it by invoking its own constructor with those fields plus its typed algorithm fields. Shared modifiers call that hook. Concrete modifiers use the same constructor path. Do not use reflection, post-clone readonly writes, or a generic options array.

Modifiers validate their own scalar/range input immediately. Cross-field constraints are validated on `Limiter::consume()`/`inspect()` before key resolution or storage access, so fluent order is irrelevant: both `LeakyBucket::perSecond(100)->cost(150)->burst(200)` and the reverse order are valid, while a final `cost > burst` fails before mutation. Internal stores may read the typed readonly properties directly without getter-call overhead.

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

Factories mirror `Limit`'s names and argument order while using period terminology appropriate to a continuously replenished bucket: `perSecond(int $rate, int $periodSeconds = 1)`, `perMinute(int $rate, int $periodMinutes = 1)`, `perMinutes(int $periodMinutes, int $rate)`, `perHour(int $rate, int $periodHours = 1)`, and `perDay(int $rate, int $periodDays = 1)`. Each factory converts its public period unit directly to the stored microseconds so validation errors name the caller's unit without a second conversion. The rate is the sustained number of tokens emitted over the period. `burst(int $capacity)` is the total immediately available capacity, not “extra” capacity. It defaults to the factory's rate argument, matching the least-surprising reading of `perSecond(100)` while still replenishing continuously; strict smoothing is the explicit `->burst(1)` case. `cost()` cannot exceed `burst()`.

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
class Limiter
{
    public function getStore(): Contracts\Store;

    public function consume(AdmissionPolicy $policy, UnitEnum|string|null $limiterName = null): LimitResult;

    /** @return ($policy is Backoff ? BackoffResult : LimitResult) */
    public function inspect(AdmissionPolicy|Backoff $policy, UnitEnum|string|null $limiterName = null): LimitResult|BackoffResult;

    public function attempt(AdmissionPolicy $policy, Closure $callback, UnitEnum|string|null $limiterName = null): mixed;

    public function recordFailure(Backoff $backoff, UnitEnum|string|null $limiterName = null): BackoffResult;

    public function clear(AdmissionPolicy|Backoff $policy, UnitEnum|string|null $limiterName = null): bool;
}
```

`AdmissionPolicy` is the abstract base implemented by `Limit`, `LeakyBucket`, and `Unlimited`; the distinct name avoids conflating the `RateLimiter` manager, per-store `Limiter`, and Laravel-compatible `Limit`. `Backoff` is a separate concrete failure policy. `consume()` is the normal one-call atomic operation. `inspect()` never mutates state. The conditional PHPDoc return is part of both public and store contracts so PHPStan narrows admission inspection to `LimitResult` and backoff inspection to `BackoffResult` without caller assertions. `attempt()` atomically consumes before invoking the callback and returns `false` on denial; if a callback returns `null`, it returns `true`, preserving Laravel's convenient semantics. If the callback throws, the accepted token remains consumed. Code that should charge only on failure or on a response predicate must use `inspect()` followed by the appropriate explicit operation.

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

`Backoff::exponential(...)` is the sole constructor and returns a `Backoff` configured for exponential delay. Keep this as one concrete value class until a second backoff algorithm is justified; an abstract base plus a one-member subclass would add hierarchy without current polymorphism. The fifth failure in the example creates the initial one-second block. Each subsequent failure after the block is eligible doubles the delay, capped at `maxDelay`. `resetAfter` resets failure history after inactivity and must be at least `maxDelay`. A success calls `clear()`.

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

Each segment is domain-tagged and length-prefixed before the entire identity is hashed with seeded `xxh128`. Derive the stable integer seed once when resolving the store as `hexdec(substr(hash('xxh128', 'rate-limiter|' . $prefix), 0, 15))`, using only the validated limiter prefix. Do not use `app.key` or generate a process-local seed: rotating an unrelated encryption key must not clear active limiter state, and every worker/application node with the same limiter prefix must derive identical physical keys. Domain tags distinguish, for example, a limiter name from a caller key; length prefixes keep arbitrary normalized strings injective. Keys are normalized to strings first, so equivalent `1`, `'1'`, a stringable `'1'`, and an enum value `'1'` intentionally identify the same bucket; `null` intentionally normalizes to the same empty/shared key as `''`. The configured prefix is a pre-hash application namespace, so every driver still receives the same fixed 32-character lowercase hexadecimal key. Redis's connection-level `OPT_PREFIX` and a database connection's table prefix remain outside this digest and are applied exactly once by those components.

Policy callbacks and request cost are excluded from the policy fingerprint; the global-scope flag is included because it changes policy identity. Including stable policy parameters means two limits with the same `by()` value but different windows/algorithms naturally have different state, and changing policy configuration starts clean state while the old TTL expires. The Laravel fallback-key mutation is unnecessary. Add golden-vector tests for the canonical encoding so an apparently harmless refactor cannot orphan all active state.

Because parameters are part of identity, `clear()` removes only state addressed by an identically parameterized policy. Changing a limit/window intentionally starts new state; callers that must clear the previous state need the previous policy value until its TTL expires. Reverb and Fortify must centralize policy construction so consume/inspect/clear rebuild the same value. Document this difference and test both matching and changed-parameter clears; do not add backend scans or secondary key indexes to clear every historical variant.

Always hash physical identities. Remove `ThrottleRequests::shouldHashKeys()` and its process-global switch. The Swoole key itself remains a fixed 32-character digest, safely below Swoole Table's key limit.

## Internal package architecture

Target layout (names may move only if implementation reveals a concrete repository convention conflict):

```text
src/rate-limiter/
├── README.md
├── composer.json
└── src/
    ├── AdmissionPolicy.php
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
    ├── KeyResolver.php
    ├── LeakyBucket.php
    ├── Limit.php
    ├── Limiter.php
    ├── LimitResult.php
    ├── Listeners/
    │   ├── InitializeSwooleTables.php
    │   └── RegisterPruneTimer.php
    ├── RateLimiter.php
    ├── RateLimiterServiceProvider.php
    ├── RedisStore.php
    ├── Swoole/
    │   ├── TableManager.php
    │   └── TableState.php
    ├── SwooleStore.php
    ├── Unlimited.php
    └── WorkerArrayStore.php
```

Avoid an `Algorithms` service hierarchy. Policies hold validated immutable configuration. Worker-array, Swoole, and database stores share typed integer transition math through `CalculatesRateLimits`; Redis implements the same semantics in Lua. Both paths use a small exhaustive `instanceof` dispatch, never descriptor arrays or strategy enums. An unsupported policy throws `InvalidRateLimitException`.

`RateLimiter::resolve()` calls the parent driver resolver, asserts that the built-in/custom creator returned `Contracts\Store`, and constructs the `Limiter` with a physical-key resolver. Built-in `createWorkerArrayDriver()`, `createDatabaseDriver()`, `createRedisDriver()`, and `createSwooleDriver()` therefore return stores, not wrappers. `KeyResolver` accepts only the prefix when no named scope is needed; its optional scope callback is invoked only for a scoped named limiter. Resolve/freeze static key configuration such as the validated prefix when the lazy store wrapper is created, but have the manager supply a live closure that reads its current optional scope callback on every named operation. That single property read keeps `resolveKeyScopeUsing()` effective even if a store was resolved first. Keep the manager's instance cache as the sole wrapper/store cache; do not add a second registry, factory, or on-demand manager construction API.

### Store contract

The contract receives an already-resolved fixed-length physical key and validated policy:

```php
interface Store
{
    public function consume(string $key, AdmissionPolicy $policy): LimitResult;

    /** @return ($policy is Backoff ? BackoffResult : LimitResult) */
    public function inspect(string $key, AdmissionPolicy|Backoff $policy): LimitResult|BackoffResult;

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

As an always-installed framework component, the canonical defaults live in `src/foundation/config/rate-limiter.php`, alongside Cache, Queue, and Concurrency configuration. Mirror the database-default file into the application skeleton. Testbench carries the same stores but uses `worker-array` in both normal and package-test modes, because throttle middleware can run without Testbench's opt-in standard migrations; its standard database migration remains available for tests that explicitly select the database store. Add `'rate-limiter' => ['stores']` to `LoadConfiguration::mergeableOptions()` so application stores merge by name; this declares merge policy only and does not duplicate configuration values. `RateLimiterServiceProvider` must not merge or publish a second package-owned config file.

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
            'memory_limit_buffer' => 0.05,
            'prune_interval' => 60, // seconds
        ],

        'worker-array' => [
            'driver' => 'worker-array',
        ],
    ],

    'prefix' => env('RATE_LIMITER_PREFIX', app_id() . '_rate_limiter'),
];
```

Use typed config getters and validate every store at resolution. Swoole's `conflict_proportion` must be a float in the inclusive range `[0.2, 1.0]`, which is the range `Swoole\Table` honors without silently clamping. The service provider registers the database migration generator and prune commands.

The configured `worker-array` store is for automated tests only. Its state is isolated to one worker and expired untouched entries remain in memory until the worker exits, so production framework consumers that have a proven worker-local ownership model must compose `Limiter`, `WorkerArrayStore`, and `KeyResolver` directly rather than depend on a user-configurable store name.

`prefix` is an application namespace included in the canonical identity before its final hash; it is not concatenated onto the 32-character physical key. This preserves cross-application isolation without variable-length Swoole keys. It is separate from Redis `OPT_PREFIX` and database table prefixes.

Do not add algorithm defaults to global config. Rates belong to typed application policy definitions, not storage configuration.

Policy construction performs store-independent scalar/range validation against the strictest shared numeric representation, including Redis Lua's largest exactly representable integer (`9_007_199_254_740_991`) and signed 64-bit Swoole/database columns. Reject a leaky-bucket rate greater than its period's microsecond count because its sub-microsecond emission interval cannot be represented without changing the promised rate. `Limiter` then validates cross-field constraints and time-dependent additions (for example `cost <= burst` and `now + emission * burst`) before key resolution or mutation. Reject an unrepresentable policy with `InvalidRateLimitException`; do not add arbitrary-precision math, saturate silently, or let the same policy work on one first-party store and corrupt on another.

Keep the exact Redis ceiling above; do not confuse Lua's lower-precision `tostring()` display with the `redis.call()` command bridge. Redis 5 deliberately converts numeric command arguments with `%.17g` instead of `lua_tolstring()` to avoid precision loss, while current Redis and Valkey convert exact integer-valued doubles through `double2ll()`/`ll2string()`. Live Redis 5.0.14 and Valkey 9 checks accepted `9_007_199_254_740_991` as an `INCRBY` argument and preserved the full decimal value. Reuse the original canonical `ARGV` strings for fixed-window `SET`/`INCRBY` arguments because they are already available, but do not add `string.format()` wrappers or an artificial `10^14` policy ceiling; computed integer command arguments remain exact under the validated `2^53 - 1` bound.

## Algorithm specifications

### Fixed window

Semantics match Laravel's first-hit-anchored interval rather than a calendar-aligned window:

1. An absent/expired key starts a window with the requested cost and the configured duration.
2. An existing key accepts only if `current + cost <= maxAttempts`.
3. A denied request does not increment the counter or extend the window.
4. Remaining capacity is `maxAttempts - current` after an accepted operation and the current remaining capacity after denial.
5. Retry/reset is the existing TTL rounded up.

`inspect()` on an absent or expired key must not start a window. It returns allowed, `remaining = maxAttempts`, `retryAfter = 0`, and `resetAfter = 0`. By contrast, the first accepted `consume()` creates the window and returns its full duration as `resetAfter`. This distinction preserves Fortify's public `availableIn()` behavior for untouched keys and must be identical across stores.

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

Use Redis `TIME`, epoch-microsecond application time for worker-array/Swoole/local SQLite, and database server time for the other databases so distributed Redis/database decisions do not depend on the application node's clock. Clamp negative elapsed time to zero defensively. Validate microsecond resolution, integer overflow, Redis Lua's exact-integer range, positive rates/periods, and burst/cost limits before accessing storage.

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
- Reject a physically present backoff hash whose failure count is zero. Its positive TTL is the Redis equivalent of the non-empty expiry state rejected by the shared PHP calculator. Do not add policy-relative corruption checks or reconstruct an absolute expiry from `PTTL`: the other stores do not validate state against the current policy, and Redis's `TIME` and backend-owned millisecond expiry clocks cannot provide the exact timestamp comparison available to numeric stores.
- Prefix every package-authored general-purpose `redis.error_reply()` message with `ERR`, following Redis's error-code convention. The existing `evalWithShaCache()` path wraps those script/data failures in `LuaScriptException`, while native `RedisException` values for server state, cluster routing, authentication, and transport failures propagate unchanged. Do not catch or reclassify native Redis exceptions.
- Keep script bodies as private constants or dedicated internal operation classes only if file length warrants it. Do not build a generic script framework.

Fixed-window Lua shape:

```lua
local cost = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])
local durationMilliseconds = tonumber(ARGV[3])
local durationMicroseconds = durationMilliseconds * 1000

local function start_window()
    redis.call('SET', KEYS[1], ARGV[1], 'PX', ARGV[3])
    return {1, limit, limit - cost, 0, durationMicroseconds}
end

local raw = redis.call('GET', KEYS[1])

if not raw then
    return start_window()
end

if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
    return redis.error_reply('ERR corrupt rate limiter counter')
end

local current = tonumber(raw)
if not current or current < 0 or current > limit or current % 1 ~= 0 then
    return redis.error_reply('ERR corrupt rate limiter counter')
end

local ttl = redis.call('PTTL', KEYS[1])
if ttl == -1 then
    return redis.error_reply('ERR corrupt rate limiter counter has no expiry')
end
if ttl <= 0 then
    return start_window()
end

local nextValue = current + cost

if nextValue > limit then
    return {0, limit, limit - current, ttl * 1000, ttl * 1000}
end

local incremented = redis.call('INCRBY', KEYS[1], ARGV[1])
return {1, limit, limit - incremented, 0, ttl * 1000}
```

The production script must keep every result numeric, provide an inspect mode without creating a missing key (returning `{1, limit, limit, 0, 0}`), and include the required `@TODO` immediately beside it. A present fixed-window key with a noncanonical integer string (including a leading-zero value other than `0`), negative/out-of-range count, or no expiry (`PTTL == -1`) is corrupt: raise a Lua error and propagate it rather than deleting the key and potentially failing open. A zero/expired TTL is a real boundary condition and starts a fresh window atomically only for consume. Validate impossible costs in PHP so the script never creates an over-capacity first value. On an accepted existing window, use the integer returned by `INCRBY` for the decision and verify through integration coverage that the original TTL is retained; do not replace the value with `SET ... KEEPTTL`.

Do not alter `RedisConnection::callEvalsha()` or `evalWithShaCache()` behavior for this package; the existing path correctly distinguishes script/data replies returned by phpredis from native Redis server/cluster/authentication/transport exceptions. Correct the method documentation to describe both exception paths, and cover one `ERR` reply and one natively thrown `OOM` reply in real Redis integration tests.

### Swoole store

- Own a dedicated `Swoole\Table`; do not reuse `SwooleStore` or `SwooleTableManager` from cache.
- Columns are `value`, `available_at`, and `expires_at`, all `Table::TYPE_INT` with an explicit 8-byte width.
- Use a fixed 32-character hashed key.
- Resolve the package-local `Swoole\TableManager` as an unbound concrete, using Hypervel's auto-singleton behavior rather than an explicit container binding. `Listeners\InitializeSwooleTables` asks it to resolve every configured Swoole limiter store during `BeforeServerStart`; later `createSwooleDriver()` retrieves that same named `TableState`. This is the necessary registry between pre-fork allocation and lazy store resolution, not a second rate-limiter/store cache.
- Create every configured Swoole limiter table and its striped `Swoole\Atomic` locks before server fork, so all workers share them. After creating the configured tables, `InitializeSwooleTables` seals the manager. Before sealing, console/tests may explicitly initialize named tables; after sealing, `get()` returns only a pre-created state and an unknown name throws instead of allocating worker-private state. The sealed flag is set before fork and inherited by workers. Structural options (`rows`, columns, conflict proportion, store names) are restart-only.
- Extract the proven 64-stripe Atomic lock coordinator from cache's `SwooleTableState` into a small `Hypervel\Core\Swoole\StripedLock` primitive used by Cache, RateLimiter, and Reverb. It owns key-to-stripe selection, short spin/backoff acquisition, selected-key acquisition required by Reverb, all-lock acquisition required by Cache, and release; it does not own a table, cache columns, or limiter state. `withLocks(list<string> $keys, callable $callback)` maps logical keys to stripes internally, deduplicates shared stripes, and acquires them in ascending stripe-index order. Every multi-stripe path follows that same global order and releases in reverse so selected-key and all-lock callers cannot deadlock. Keep each package's table manager and state format independent.
- Use the existing `Hypervel\Coordinator\Timer` from `Listeners\RegisterPruneTimer`, with its default `WORKER_EXIT` coordinator. It already provides injectable repeating timers, exception reporting, cancellation, and automatic worker-exit cleanup; do not add another timer wrapper, timer-ID registry, or `OnWorkerExit` listener. Register only on worker 0 and never in task workers. In separate passes, validate every configured prune interval, resolve every target store, and only then register timers; retain rollback for genuine registration failures. `prune_interval` is seconds and is passed directly to `Timer::tick()`.
- Perform read/check/write within one row lock. No serialization, closures, cache repository, or generic eviction policy appears in the hot path.
- Use one `CalculatesRateLimits` epoch-microsecond helper for worker-array, Swoole, and local SQLite: `(int) (microtime(true) * 1_000_000)` normally and `(int) CarbonImmutable::now()->getPreciseTimestamp(6)` under `CarbonImmutable::hasTestNow()`. Both branches must use the same origin and unit so switching test time after creating state cannot manufacture an expiry. This also aligns local state with Redis `TIME` and database wall clocks; accepting wall-clock adjustment behavior is preferable to a separate monotonic-offset test abstraction that the distributed stores could not share.
- Expired rows are reclaimed on access. Worker 0 owns the coordinator-backed periodic expiry scan. Timer/full-table pruning must capture one cutoff, collect candidate keys without mutating or yielding inside the `Swoole\Table` iteration, then lock and re-read each candidate before deletion so a concurrent renewal cannot be removed from under another worker. Deleting while iterating is incorrect because collision-row promotion shifts Swoole's positional iterator and skips rows; yielding inside the scan also lets another coroutine in the worker reset the shared iterator. A rare cross-process chain mutation can still make one scan under-collect and fail closed early. Do not add a prune mutex or hold every stripe across the scan: neither excludes ordinary writers without broad hot-path coordination, and the residual never allows excess traffic.
- After each periodic prune, use `Swoole\Table::stats()` to calculate the same O(1) conflict/fill pressure signal as Cache: warn through injected `Psr\Log\LoggerInterface` when either ratio exceeds `1 - memory_limit_buffer`. This signals exhausted headroom off the request path while avoiding warnings for pressure relieved by expired-row pruning.
- If insertion fails, perform one synchronous expired-row prune and retry once. If Swoole still cannot allocate the new entry, throw `SwooleTableFullException` with allocation-accurate wording; normal exception reporting supplies the hard-failure signal. Never evict a live limiter entry, because eviction would fail open. A full scan is permitted only on this exceptional capacity path or the background timer, never on ordinary admission.
- Document that Swoole is host-local and is not a distributed rate limiter across servers.
- Document sizing as `rows >= peak concurrently live physical keys × headroom`, where a key remains live for its window/refill/inactivity TTL. Explain that Swoole rounds `rows` up to a power of two with a minimum of 64, allocates a separate `rows × conflict_proportion` collision pool, and can reject a colliding key while unrelated base slots remain, so `count()` need not reach `getSize()` before an allocation failure. Include examples for per-IP cardinality and explain that periodic pressure warnings indicate exhausted headroom before allocation begins failing closed.

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
    $row = $this->stateForUpdate($connection, $key);

    $now = $this->currentTimeInMicroseconds($connection);

    // Compute and persist one validated state transition, then return its result.
}, attempts: 3);
```

For MySQL, MariaDB, and PostgreSQL, `stateForUpdate()` first reads the primary-key row with `lockForUpdate()`. Established keys therefore enter a direct exclusive record-lock queue without issuing an insert. If the row is absent, insert it with `insertOrIgnore()`, lock/read it again, and fail closed if it is still absent. MySQL/MariaDB may deadlock while concurrent first-use transactions convert compatible absent-key gap locks into insert-intention locks; the transaction's three attempts are load-bearing because one winner creates the row and retries then converge on the established-row path. Do not describe this path as deadlock-free or assert engine deadlock counters. A no-op upsert would avoid the first-use deadlock, but it would perform update-style work on every established-key operation and create dead tuples on PostgreSQL, so do not use it.

SQLite deliberately keeps the opposite order: call `insertOrIgnore()` before the first read so the write acquires SQLite's database writer lock, because SQLite's grammar omits `FOR UPDATE`. Do not generalize this into a database capability layer. Fetch time only after the final row lock/write lock is held so waiting cannot make the decision timestamp stale.

Before `consume()`, `recordFailure()`, `clear()`, or `pruneExpired()` mutates state, require `transactionLevel() === 0` on the selected connection and throw `LogicException` otherwise. A nested limiter update would remain inside the caller's physical transaction: rollback could undo an accepted charge/failure, clear could silently disappear, pruning would accumulate every batch's delete locks until the outer commit, and the store's own retry is disabled for nested concurrency errors. Applications that need limiter decisions while another connection is transactional must configure a separately named limiter connection.

A PostgreSQL connection selected for this store must use `READ COMMITTED`, PostgreSQL's default isolation. Contended hot-row writes at `REPEATABLE READ`/`SERIALIZABLE` can exhaust any fixed immediate retry count with `40001`; do not add larger attempt counts, retry sleeps, runtime isolation probing, or `SET TRANSACTION` overrides. This restriction does not apply to MySQL or MariaDB, whose default `REPEATABLE READ` lock behavior is supported.

`inspect()` is intentionally different: select without inserting or locking, read the clock, and return a best-effort snapshot. Both its row query (`useWritePdo()`) and server-time scalar must use the primary/write PDO so a configured read replica cannot return stale limiter state or a clock from a different server, but it must not create, refresh, delete, or otherwise mutate a row. Inside a MySQL/MariaDB outer transaction at the default `REPEATABLE READ`, the snapshot may be as old as that transaction. `clear()` is a direct keyed delete. Only `consume()` and `recordFailure()` use the insert/lock transaction.

Use database-server microsecond time for MySQL/MariaDB and PostgreSQL: `FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) * 1000000)` for MySQL/MariaDB and `FLOOR(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint` for PostgreSQL. These exact expressions were executed successfully during plan review against MySQL 9.5, MariaDB 10.11, and PostgreSQL 17. PostgreSQL must use `clock_timestamp()`, not the transaction-start timestamp returned by `CURRENT_TIMESTAMP`. SQLite is local/non-distributed and may use application wall-clock microseconds. Return the scalar as a decimal/integer and range-check it before casting. Keep driver-specific clock SQL private and covered by integration tests; reject unsupported database drivers instead of silently choosing an application clock, and do not create a general database capability framework in this package.

Add `rate-limiter:prune {store?} {--chunk=1000}` for stores implementing `PrunableStore`. Database pruning uses a fixed current-time cutoff and bounded, portable batches: select at most the configured number of expired keys from the write connection, then delete only those keys that still satisfy `expires_at <= cutoff`. This avoids one unbounded delete/lock while ensuring a concurrently renewed row is skipped. Validate a positive bounded chunk size, stop when a selection is short/empty, and report the total deleted. Recommend scheduling it hourly. Do not add random bulk-delete queries to admission's hot path.

The prune command resolves `$manager->store($name)->getStore()` and rejects stores that do not implement `PrunableStore`; it must not reach through the container to a concrete database implementation.

The database driver is correctness-first and will require several SQL statements in a transaction; documentation must not present it as equivalent to Redis throughput.

### Worker-array store

- Use an in-process numeric state array and the same epoch-microsecond clock/test seam as Swoole.
- Use the `worker-array` name established by Hypervel Cache for worker-lifetime state. It is not coroutine-local and not shared across workers; do not call it `array`, which Hypervel documentation reserves for request-local scratch state.
- The configured store is suitable only for automated tests. General application limiting would split state between workers and expired untouched keys would continue consuming worker memory.
- The store class remains a reusable package primitive for first-party code with a proven worker-local ownership boundary. Reverb composes it directly for per-connection message limits because each connection remains owned by one worker and its key is cleared on close; this internal use must not depend on the public store configuration.
- Operations contain no suspension point, so a transition is atomic within one cooperative worker; it does not coordinate processes or hosts.
- Mutating operations replace expired state, while `inspect()` treats it as empty without changing storage. An expired entry whose key is never touched again remains for the worker lifetime; rely on explicit `clear()`, another mutating operation on that key, and worker recycling for this deliberately local/test store. Do not add an abandoned-key scheduler, expiry index, or unbounded whole-array sweep in a request hot path.

## Framework consumer refactor

### Routing

Rewrite `ThrottleRequests` around `LimitResult`:

This refactor must close, and then remove, both existing Redis entries in `docs/todo.md`; they are acceptance requirements for this package replacement, not follow-up work:

- keep remaining/reset/decision data in request-local variables so the singleton middleware has no worker-lifetime per-key state and same-key concurrent requests cannot overwrite one another's headers;
- implement `after()` for Redis-backed policies with non-mutating inspection followed by a conditional atomic consume.

- Inline `throttle:60,1` creates a fixed `Limit` and consumes it once.
- Preserve the public `ThrottleRequests::using()` and `ThrottleRequests::with()` helpers, middleware pipe syntax, named-limiter lookup and `MissingRateLimiterException` behavior. Keep `resolveMaxAttempts()` semantics intact: pipe values such as `60|120` select guest/authenticated limits, and a nonnumeric value may resolve from the authenticated user's named attribute before the existing missing-limiter exception is chosen. These are ergonomic Laravel routing APIs, not cache-counter compatibility methods.
- Named callbacks retain `Response`, `Unlimited`, one policy, or an ordered array of policies.
- Resolve the named limiter's registered store once for the request, or use the default store when none was registered. For a normal named policy, call that store's `consume($policy, $limiterName)` once and retain the local result for exception/header generation. Inline policies use the default store and omit the name.
- For a named `after()` policy, call the selected store's `inspect($policy, $limiterName)` before the downstream handler; after the response, call `consume($policy, $limiterName)` only when the predicate returns true. A concurrent post-response consume may be denied after the response has already been admitted; return headers from that result but do not retroactively throw. Document/test this inherent response-dependent semantic.
- Use `retryAfter()` and `remaining()` from the local result. Remove second reads and all request state from singleton middleware properties.
- Preserve Laravel-compatible headers: successful responses use `X-RateLimit-Limit`/`X-RateLimit-Remaining`; denied responses additionally use `Retry-After` and an absolute `X-RateLimit-Reset` derived from `retryAfter()`. Do not substitute leaky-bucket full-refill `resetAfter()` for the earliest retry time. For leaky policies, document that the limit/remaining header pair describes burst capacity while the policy definition describes the sustained rate.
- With multiple policies, retain the header pair for the most restrictive (lowest remaining) local result and do not overwrite an application-provided lower `X-RateLimit-Remaining` value.
- Ordered multiple policies consume sequentially. If a later policy denies, earlier accepted policies remain consumed. Do not add an all-or-nothing multi-key API: it cannot be implemented consistently across stores and Redis Cluster, and preflight checks would reintroduce races.
- Remove `ThrottleRequestsWithRedis`.
- Remove its `RoutingServiceProvider` binding and binding-lifetime tests. The unified `ThrottleRequests` keeps decisions in method-local state and remains safely auto-singletoned without an explicit binding.
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
- `inspect()` before running the job; `consume()` only when a qualifying exception occurs; `clear()` after success. If the post-failure consume is denied because a concurrent failure filled the window, release using its circuit-open retry delay rather than the ordinary pre-limit `backoff()` delay.
- Add the same `store()` selector and remove `ThrottlesExceptionsWithRedis`; this middleware constructs its policy directly, so it uses the default store unless explicitly overridden.
- Persist only the selected store name with the middleware/job; resolve the manager/wrapper inside `handle()` and never serialize a resolved backend store or Redis proxy.
- Keep its existing `backoff()` method for the ordinary queue retry delay; do not conflate that delay with the package's server-enforced exponential `Backoff` policy.
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

Change `Handler::throttle()` from `Lottery|Limit|null` to `Lottery|AdmissionPolicy|null`. `Limit::none()` returns the sibling `Unlimited`, so retaining the old concrete return type would throw on the default path; the broader admission type also permits leaky policies. An application override returning `Lottery|Limit|null` remains a valid covariant narrowing.

### Reverb

Inject `Limiter` directly into the Pusher protocol server and register a contextual binding that constructs it from `WorkerArrayStore` and `KeyResolver('reverb-message-rate-limiter')`. Do not resolve the `RateLimiter` manager or a configured store name. Hypervel auto-singletons the unbound Pusher server, so this object graph is built once per worker and retained on the server rather than constructed or resolved per message.

Keep a concise comment at the binding explaining why worker-local state is correct: `WebSocketHandler` retains connections in a per-worker static registry keyed by file descriptor, and a connection remains owned by that worker for its lifetime. A shared backend would add I/O to every client message without extending the state to any worker that can use it. The contextual binding preserves normal constructor injection and deliberate test/application rebinding while keeping internal correctness independent of `rate-limiter.default`, the presence of `stores.worker-array`, and custom creators registered for the public `worker-array` driver name.

Build the same fixed policy for consume and close-time clear. Promote the injected limiter as the server's protected constructor property instead of retaining a separate assignment.

Treat an enabled Reverb application's `max_attempts` and `decay_seconds` values as required configuration; do not silently substitute a different rate-limit period for custom application providers. Cast both values to integers when building the policy because environment-backed and custom application configuration may contain numeric strings. When `terminate_on_limit` is enabled, attempt the Pusher 4301 error frame before disconnecting the socket. Keep termination in a `finally` block so a throwing `MessageSent` listener or logger cannot leave a connection open after it exceeded the limit. The connection test fake must ignore sends after termination like the real transport, and focused tests must distinguish the original terminate-before-send ordering from a naive send-then-terminate reorder without `finally`.

Also replace Reverb's duplicated 64-stripe Atomic implementation with an explicitly injected Core `StripedLock`, created in the existing eager pre-fork provider block. Single-key operations use `withLock()` and presence operations use `withLocks([$channelKey, $userKey], ...)`; do not substitute `withAllLocks()`. Remove Reverb's local lock constants, arrays, acquisition helpers, and primitive-only tests while retaining call-site coverage for post-release reporting, shared-stripe deduplication, and opposite input ordering.

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
- exact direct requirements for `ext-swoole`, `hypervel/collections` (including `enum_value()`), console, container, contracts, coordinator, core events/primitives, database, Redis, support, `psr/log`, and `symfony/console`, pruning anything implementation does not actually import; PHP's mandatory Hash extension needs no Composer requirement;
- `hypervel/rate-limiter` dependencies in routing, queue, Fortify, foundation, and Reverb package manifests;
- remove `hypervel/cache` from packages where the limiter was its only cache use; retain unrelated cache/Redis dependencies after checking all imports;
- facade API documentation metadata;
- `Hypervel\RateLimiter` package entry in any package inventories/documentation lists.

Move only the generic striped-lock behavior described above into Core and update Cache and Reverb imports/tests in the same change. Reverb already directly requires Core, so this adds no package dependency. Do not change Cache's internal lock construction merely for constructor symmetry; only Reverb needs explicit injection for its retained call-site test doubles. Also make Coordinator's existing `Timer` the one Swoole maintenance mechanism across both touched packages:

- add `hypervel/coordinator` as Cache's direct dependency;
- replace Cache's `CreateSwooleTimers` with the accurately named `RegisterSwooleMaintenanceTimers`, inject `Coordinator\Timer`, validate every configured store's two intervals and resolve every target `SwooleStore` before registering any timer, then capture those stores in the eviction/interval-refresh callbacks registered with the default `WORKER_EXIT` coordinator. This deliberately makes the configured stores eager on worker 0, but they only wrap pre-fork shared tables and open no external connections. More importantly, a resolution failure fails worker startup before any timer exists instead of being swallowed and logged again on every tick by `Coordinator\Timer`; under Swoole, a throwing `AfterWorkerStart` listener appears as a worker respawn loop until the configuration is corrected;
- retain Cache's established millisecond config values and documentation, read each named store's complete interval values through typed config getters without duplicating inline defaults, require both integers to be positive, and divide by `1000` once during worker-start registration before calling the seconds-based `Timer::tick()`; this preserves subsecond configuration and avoids silently reinterpreting existing/skeleton values, while RateLimiter's independently documented `prune_interval` remains seconds;
- remove the old `=== false` registration guards and their `RuntimeException` messages because `Coordinator\Timer::tick(): int` either returns an ID or throws; retain thrown-registration rollback with only a method-local list of returned IDs, then discard it;
- delete the listener's persistent ID registry and `stop()` method because coordinator shutdown owns cleanup;
- delete `Hypervel\Cache\SwooleTimer` and the Cache provider's inline `OnWorkerExit` closure, including its nested exception-handler/stderr reporting fallback, then update listener/provider/recycle tests to drive the worker-exit coordinator.

Do not change Coordinator `Timer` itself or move Cache's table manager, string-value validation, eviction state, or exceptions that the numeric limiter table does not use. This is deletion and convergence on an established primitive, not a new timer abstraction.

Do not add a reverse `hypervel/rate-limiter` dependency to `hypervel/support`. Support is the lower-level package used by the new manager and service provider; its facade and default-provider references follow the repository's existing optional facade/provider bridge convention. The always-installed framework metapackage provides both packages, while an independently installed rate-limiter package already requires Support in the correct direction.

The existing split script automatically discovers `src/*`; no hard-coded split list should be added unless the current script changes.

After consumer imports are rewritten, remove `hypervel/cache` from routing, Fortify, and Reverb if the repository-wide import audit still confirms that rate limiting was their only actual cache-package use. Queue and foundation have unrelated cache responsibilities and retain that dependency. Add the new package as a direct dependency wherever its symbols are imported even if another package would provide it transitively.

Coordinate the two adjacent official repositories in the same release:

- add `hypervel/rate-limiter` to `contrib/hypervel/framework/composer.json`, sorted with the other split components;
- add `config/rate-limiter.php` to the `contrib/hypervel/hypervel` application skeleton;
- because the skeleton selects the database limiter store by default, add `database/migrations/0001_01_01_000008_create_rate_limits_table.php` after its current `000007` failed-jobs migration so a fresh application works immediately, while retaining the generator for existing applications;
- set `RATE_LIMITER_STORE=worker-array` in the skeleton `phpunit.xml`, matching its other test-local stores so `RefreshDatabase` feature tests do not nest limiter mutations inside the test transaction;
- reconcile the skeleton's stale `app.php` entries with Foundation: replace `stdout_log_level` with the `stdout_log.level`/`stdout_log.format` structure read by `StdoutLogger`, expose `force_https`, normalize `APP_PREVIOUS_KEYS` to a string, and retain the complete maintenance driver/refresh configuration required by `FoundationServiceProvider`;
- add `RATE_LIMITER_STORE=database` and commented connection/prefix overrides to the skeleton environment example, update lock/config documentation, and run each repository's own metadata/config/migration tests. Do not modify the private `packages/hypervel` repositories unless a concrete import audit finds an actual consumer.

Keep provider auto-discovery metadata in the split package so it works when independently required, matching other core components, but also assert `RateLimiterServiceProvider`'s presence in `DefaultProviders`. Discovery is not the framework's availability mechanism; its alphabetical placement is a code-style requirement, not runtime behavior that needs a brittle order test.

Within components, add `src/testbench/hypervel/config/rate-limiter.php` with `worker-array` as its test default and `src/testbench/hypervel/migrations/0001_01_01_000008_testbench_create_rate_limits_table.php` after the current `000007` failed-jobs migration. Update `CommanderTest`'s expected migration inventory, every `WithMigration`/default-database assertion that enumerates framework tables, and rollback/refresh coverage. The migration lets database-store tests opt in without pushing unrelated container-resolved limiter tests through SQLite; limiter tests must not create the standard table ad hoc.

Keep config-publishing tests isolated from that worker-shared Testbench skeleton. The ordinary `--all --force` test must preserve every config name discovered from Foundation before publishing into the clone. The `dontMergeFrameworkConfiguration()` case must use Testbench's `#[UsesFrameworkConfiguration]` bootstrap path, switch the command destination to a throwaway config directory after application creation, and delete that directory after the test. Before publishing, assert that the disposable path is active and every destination is absent so the test cannot pass by comparing the Foundation source directory to itself. Hypervel intentionally ships no config stubs today; retain the dormant Laravel-compatible source-selection branch without adding a stub or production change.

## Removal and cleanup inventory

Delete after consumers compile against the new package:

- `src/cache/src/RateLimiter.php`;
- `src/cache/src/RateLimiting/Limit.php`;
- `src/cache/src/RateLimiting/GlobalLimit.php`;
- `src/cache/src/RateLimiting/Unlimited.php`;
- cache provider limiter binding;
- `src/cache/src/SwooleTimer.php`, Cache's explicit timer-ID/`stop()` lifecycle, and the provider's inline `OnWorkerExit` closure/reporting fallback after `RegisterSwooleMaintenanceTimers` uses Coordinator cleanup;
- obsolete support-facade annotations for primitive limiter methods, `cleanRateLimiterKey()`, and the Hypervel-specific `resolveNamedLimiterKey()` helper;
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

As a bounded adjacent Redis optimization, change `DurationLimiter` and `ConcurrencyLimiter` from sending their full Lua body through `eval()` on every operation to the existing NOSCRIPT-aware `evalWithShaCache()` path, preserving their public APIs and cluster key handling. Correct `DurationLimiter`'s fresh/expired Lua result order so PHP receives the expiry timestamp and remaining capacity in their documented positions, and clamp returned remaining capacity to zero. Add focused unit and real-Redis coverage; do not otherwise fold those blocking concurrency/throttle primitives into the new package.

At the end, repository-wide searches (excluding archived code, third-party examples, vendor, and historical `docs/plans`/`.tmp/plans` artifacts) must return no old namespace, no removed middleware class, no `throttleWithRedis`, and no `cache.limiter` in executable code, tests, configuration, stubs, or maintained user/agent documentation. Historical plans are records, not supported documentation, and must not be rewritten as part of this change.

Group Redis-backed tests under `tests/Integration/{Package}/Redis` when the package also has service-independent integration tests. Move the existing Auth, Cache, HTTP, Queue, and RateLimiter Redis tests into those directories so the Redis workflow neither skips them nor reruns unrelated package suites. Run `tests/Integration/Auth/Redis`, `tests/Integration/Cache/Redis`, `tests/Integration/Horizon`, `tests/Integration/Http/Redis`, `tests/Integration/Queue/Redis`, `tests/Integration/RateLimiter/Redis`, and `tests/Integration/Redis` explicitly in both Redis 8 and Valkey 9 jobs with ParaTest capped at 15 workers. The separate Reverb workflow must continue running `tests/Integration/Reverb`, which also uses the shared Redis test trait and requires its own test servers. The database workflow already discovers `tests/Integration/*/Database/{Driver}` and needs no rate-limiter-specific path edit. Keep the root `AGENTS.md` workflow table and service-directory guidance aligned with these paths.

Correct the shared `InteractsWithRedis` test isolation before relying on those parallel groups. In both sequential and parallel runs, normalize the `database` key of every configured Redis connection array to the current test database, skipping the reserved `client`, `options`, and `clusters` groups and unrelated non-array values. Reject non-empty connection URLs with a clear message directing test environments to the trait's documented `REDIS_HOST`/`REDIS_PORT` setup, because URL database paths would otherwise override the normalized key. The first-party integration workflows expose one Redis/Valkey service, so one `FLUSHDB` through the default connection then clears every configured connection without opening every pool during each test setup and teardown.

Adapt the ported Queue integration base to Hypervel's auto-invoked trait lifecycle. Alias the trait's setup and teardown methods, gate setup on the live `queue.default` value, remember successful Redis setup for matching teardown even if a test later changes the driver, and remove the stale driver snapshot plus subclass synchronization assignments. Add one end-to-end Redis queue-worker fixture under `tests/Integration/Queue/Redis`; the existing Redis workflow discovers it without a new matrix. This keeps sync/database Queue tests independent of Redis, exercises the Redis branch in CI, and prevents the test base from calling Laravel-only `setUpRedis()`/`tearDownRedis()` methods.

## Documentation work

Update every applicable Boost document, not just the main rate-limiting page:

- `routing.md`: named policies, leaky bucket, weighted cost, response-based semantics, stores, headers, and removal of `throttleWithRedis`;
- update the existing `src/boost/docs/rate-limiting.md` in place as the single canonical rate-limiting document: cover the package architecture, direct consume/inspect/attempt/clear APIs, typed policies and results, fixed-window/leaky-bucket/backoff behavior, driver selection and guarantees, configuration, database migration/pruning, custom drivers, distribution boundaries, performance guidance, and failure behavior. Do not add a competing `rate-limiter.md` page;
- `queues.md`: store selection and removal of Redis-specific middleware classes;
- `fortify.md`, `errors.md`, `starter-kits.md`: imports and new typed calls;
- `facades.md`: canonical accessor/class;
- `middleware.md`: one throttle middleware class;
- `reverb.md`: explain that message limits are per connection, define `max_attempts` and `decay_seconds`, and state that `terminate_on_limit` closes the connection after the rate-limit error;
- database docs: `make:rate-limiter-table`, schema purpose, pruning schedule;
- package README: only the package heading, the canonical Boost documentation link, and concise public `Differences From Laravel`; omit an upstream link because this independently maintained package does not track a source package.
- Cache README: add a concise `Differences From Laravel` entry directing developers to the dedicated `hypervel/rate-limiter` package and canonical documentation.

Add a concise explicit divergence to root `AGENTS.md`: Laravel locates its cache-bound limiter under `Illuminate\Cache`; Hypervel's canonical implementation is `hypervel/rate-limiter` / `Hypervel\RateLimiter`, uses typed policies and dedicated stores, and has no Cache namespace alias. This is the instruction LLMs should see when porting.

For intentionally omitted or deliberately changed Laravel behavior, follow the repository's three-place rule: concise package README differences, concise comments at the natural source insertion points, and `REMOVED:` markers at matching upstream test locations. Cover:

- the `Hypervel\Cache` location and primitive counter/key APIs, including `fallbackKey()` and `cleanRateLimiterKey()`;
- Redis-specific middleware classes/switches and the removed `redis:` argument on `Middleware::throttleApi()`;
- `GlobalLimit`, `ThrottleRequests::shouldHashKeys()`, and Foundation Handler's protected `$hashThrottleKeys` extension point, because canonical hashing is mandatory;
- atomic `attempt()` consuming before the callback and retaining the charge on callback failure;
- sequential stacked-policy consumption retaining earlier charges when a later policy denies;
- truthful non-zero remaining capacity on a weighted denial; and
- parameter-sensitive identity causing configuration changes to start new state and requiring the same policy parameters for `clear()`.

Explain the replacement behavior and cover these semantics in Boost docs/tests. `resolveNamedLimiterKey()` is a Hypervel 0.4 implementation helper rather than a Laravel API; remove it and its facade annotation in the cleanup sweep, but do not mislabel it as a Difference From Laravel. Do not add entries to `docs/ai/differences-vs-laravel.md`, which is queued for deletion.

While updating root `AGENTS.md` with the rate-limiter divergence, remove its stale instructions to maintain `docs/ai/differences-vs-laravel.md`; that document's own header already marks it for deletion. Do not leave contradictory agent guidance in the touched file.

Do not copy internal research criticism into user documentation. Public docs should state the supported design clearly.

## Testing plan

Create `tests/RateLimiter` and use the repository-required base test/coroutine conventions. Run each changed/new test file individually before the package suite.

### Policy/value tests

- Every fixed-window and leaky-bucket factory converts periods correctly; leaky factories default burst to the sustained token count and `burst(1)` opts into strict smoothing.
- Invalid zero/negative capacity, rate, duration, burst, cost, and backoff settings throw named exceptions.
- Numeric boundary tests cover the shared Lua-exact/signed-64 limits and every overflow-prone multiplication/addition before a store mutation.
- Fluent methods return new copies and do not mutate the original policy.
- Concrete copy hooks preserve readonly shared/algorithm fields without reflection or post-clone writes, and cross-field validation makes `cost()`/`burst()` fluent order irrelevant.
- `globally`, scope, callbacks, cost, and response callbacks are retained correctly.
- Policy fingerprints are stable for a limiter prefix, change when the prefix or policy parameters change, distinguish policy types, and exclude cost/callbacks.
- A `KeyResolver` without a scope callback produces the same physical key as one whose callback returns `null`; keep the manager's separate live-callback test for late `resolveKeyScopeUsing()` changes.
- Arbitrary key segments cannot create ambiguous preimages before hashing.
- Unlimited performs no store operation.
- `LimitResult` and `BackoffResult` round timing up correctly and never expose negative remaining/retry values.
- Manager default/named/`UnitEnum` store resolution, named-limiter registered stores, explicit queue overrides, one-instance caching, purge/forget behavior, typed configuration failures, and a custom `extend()` callback returning `Contracts\Store` all produce the expected wrapped `Limiter` without a second cache. Registering the scope resolver after a store was resolved still affects subsequent named operations.
- Named limiter identity differs by limiter name, scope, global flag, normalized key value, policy type, and stable parameters exactly as specified; equivalent scalar/stringable/enum key values normalize identically, and direct policies do not accidentally invoke the named scope resolver.
- The shared typed PHP calculator produces the same transitions used by worker-array, Swoole, and database stores without descriptor arrays or floating-point state.

### Shared store contract suite

Run one behavioral contract against worker-array, Swoole, SQLite, MySQL, MariaDB, PostgreSQL, and the existing CI services for Redis 8 and Valkey 9. Do not add redundant Redis point-release jobs for a portable Lua path with no version branch, and do not claim a supported first-party store/server combination from mocks alone.

- first consume, exact-capacity consume, weighted consume, over-capacity denial;
- denied consume does not mutate count or extend TTL;
- inspection does not create/mutate state, and absent fixed-window inspection returns full remaining capacity with zero retry/reset;
- clear with matching parameters, changed-parameter isolation, and expiration reset;
- fixed-window boundary immediately before/after reset;
- leaky-bucket initial burst, smooth recovery, weighted retry, full refill, denial immutability;
- exponential threshold, doubling, cap, inactivity reset, success clear;
- same physical semantics for every store to the precision promised by public seconds;
- corrupted/wrong-type backend state fails explicitly rather than allowing work.

Time control is store-appropriate rather than abstracted into a production clock service. Worker-array and Swoole use epoch microseconds in production and honor `CarbonImmutable::hasTestNow()` on the same scale for exact semantic tests. Tests must create state before setting/travelling test time as well as while test time is already active, proving the seam cannot change the clock origin. Redis and database continue using authoritative backend time; their integration boundary/expiry cases use the shortest valid intervals with bounded polling and a hard deadline, while the shared calculator suite covers exact before/after arithmetic without sleeping.

### Concurrency tests

Tagged Swoole releases currently stall the two coroutine-hooked SQLite concurrency cases because the AIO scheduler can leave the lock holder's continuation queued behind lock waiters. Temporarily override only those two inherited SQLite tests as skipped, with a focused `@TODO` linking [Swoole PR #6140](https://github.com/swoole/swoole-src/pull/6140). Remove the overrides and TODO as soon as Hypervel's minimum tagged Swoole release contains that fix. Keep the test bodies unchanged in the shared database contract, keep every non-concurrency SQLite test active, and add no pool cap, timeout change, serialization, version branch, or package workaround.

`Parallel::wait()` is a coroutine-only API. Validate that precondition before resetting state or executing callbacks and throw the existing `RunningInNonCoroutineException` when no coroutine is active. This is fail-fast API hardening, not a non-coroutine execution path; standalone callers must enter a coroutine container through `run()`. Add focused coverage proving misuse executes no callback. Do not use `parallel()` to claim same-process contention coverage for a store operation with no suspension point, because those callbacks cannot interleave.

- Swoole: forked workers sharing the pre-fork table and Atomic stripes admit exactly capacity and do not lose updates; propagate framed child-process failures to the parent and reap each child with its own bounded deadline.
- Database: run the shared contract through the production pooled resolver by enabling `pool.testing_enabled` on the configured default connection. Concurrent transactions against an absent key and an existing key admit exactly capacity; include SQLite writer serialization plus MySQL/PostgreSQL row locks in integration CI. SQLite uses a pre-created plain file under `ParallelTesting::tempDir()` with a multi-connection pool rather than Testbench's in-memory/static-connection resolver, so the unchanged tests exercise independent PDOs and real writer locking. The file path intentionally overrides Testbench's earlier parallel-database suffix because the temp directory is already worker-scoped.
- Redis: many concurrent pooled clients admit exactly capacity for fixed and leaky bucket; test weighted costs.
- Structural Redis tests assert every limiter script is invoked with exactly one key; configured-prefix integration coverage verifies the key path. Do not add a Redis Cluster service merely to test an impossible CROSSSLOT case for one-key scripts.
- No driver allows stored state above capacity.

### Redis-specific tests

- Steady path calls `evalSha` and a node's first NOSCRIPT response falls back to `eval` through existing `evalWithShaCache()`.
- Script `false`/nil handling is not mistaken for NOSCRIPT, a package-style `ERR` reply becomes `LuaScriptException`, and a natively thrown `OOM` reply remains `RedisException`.
- Serializer/compression configuration does not affect limiter state.
- Redis connection `OPT_PREFIX` is applied once.
- `TIME`-based leaky/backoff calculations ignore application-clock skew.
- TTL is applied atomically, is unchanged on denial, and an accepted existing fixed-window increment uses portable `INCRBY` without changing the original TTL.
- A stored leading-zero counter such as `010` is rejected by the package's explicit corruption branch before `INCRBY`, while zero and canonical non-zero decimal counters remain valid.
- A physically present backoff hash with zero failures is rejected as corrupt; do not add timing-dependent timestamp-versus-`PTTL` assertions.
- The validated `9_007_199_254_740_991` ceiling survives fixed-window `SET`/`INCRBY` command arguments and computed Redis state without scientific-notation truncation; do not assert against Lua `tostring()`, which is not the command bridge.
- Redis 8 and Valkey 9 run the exact same Lua implementation.
- Do not add a source-shape test for the `@TODO`. The code comment and matching `docs/todo.md` entry are the maintenance record; tests cover the portable Lua behavior rather than comment text.

### Swoole-specific tests

- Core `StripedLock` preserves Cache's row/all-lock behavior and timeout coverage after extraction; selected-key coverage proves shared-stripe deduplication, ascending stripe ordering, and partial-acquisition rollback. RateLimiter and Reverb create the same lock primitive before fork. Do not add a timing-based selected-key-versus-all-lock deadlock test; the documented common ascending-index invariant is the proof.
- `RegisterPruneTimer` validates every interval before resolving any store, resolves every target before registering any timer, registers the prune callbacks only for worker 0/non-task workers, and stops them through the existing `WORKER_EXIT` coordinator without package timer IDs or exit listeners.
- Cache's renamed maintenance listener reads complete positive millisecond intervals through typed config getters, resolves every target Swoole store, converts the intervals to seconds at registration, removes unreachable native-false guards, rolls back earlier registrations if a later registration throws, and relies on the same worker-exit coordinator in its recycle test. The standalone recycle fixture binds the real cache manager and creates its configured table through `CreateSwooleTable` before server start, so replacement workers inherit the shared table instead of allocating worker-local tables. Missing, wrong-type, zero, and negative intervals and target-store resolution failures occur before timer registration. The callbacks capture the resolved stores, so timer execution performs no container lookup. Cache has no duplicated listener defaults, native Swoole timer wrapper, persistent timer-ID registry, `stop()` path, or provider-owned `OnWorkerExit` closure afterward.
- Table columns are 8-byte integers and table creation occurs before fork.
- `TableManager` allows explicit creation before sealing, is sealed by `InitializeSwooleTables` before fork, and rejects unknown tables afterward. It reads structural values through typed config getters, accepts conflict proportions `0.2` and `1.0`, and rejects `0.1`/`1.5` rather than letting Swoole silently clamp them.
- Same-key locks isolate transitions; different stripes can proceed independently.
- Expired rows are pruned by timer and on access. A collision-chain regression proves one collect-then-delete pass removes every expired candidate without mutating the table iterator.
- Periodic pruning logs pressure only when post-prune conflict/fill ratios cross the configured buffer; allocation tests discover an exact failing key from `getSize()`/`stats()`, prove one synchronous prune/retry can reclaim a conflict slice, and then prove the allocation-accurate exception without assuming that table count reached configured rows.
- Reverb retains call-site tests for reporting after stripe release, one acquisition when two logical keys share a stripe, and deadlock-free opposite input ordering, while generic spin/backoff/timeout tests live only in Core.
- Store state never serializes a PHP value.

### Database-specific tests

- Generated migration SQL/schema is valid for all four supported database families.
- Non-SQLite drivers lock established rows before inserting missing state; simultaneous first use converges through the three transaction attempts, while SQLite inserts first to acquire its writer lock.
- `consume`, `recordFailure`, `clear`, and pruning reject an active transaction on the selected connection before issuing limiter SQL; inspection remains available as a best-effort transactional snapshot.
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
- Foundation/application defaults select database without requiring Redis; Testbench defaults to worker-array while its standard migration supports explicit database-store tests.
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
- Foundation's default `Limit::none()` throttle path satisfies the widened `Lottery|AdmissionPolicy|null` return type.
- Reverb per-connection isolation and close cleanup use the contextually injected limiter. A per-test `DefineEnvironment` callback removes the configured `worker-array` store and selects a missing default before provider boot, so the current manager-backed constructor fails during test setup rather than being masked by cached manager state. The cleanup test resets captured output after close and proves a fresh direct message is accepted with cleared state.
- Reverb sends the 4301 rate-limit error before terminating a connection, still terminates if error delivery or reporting throws, requires the complete enabled rate-limit configuration without a hidden one-second fallback, and enforces limits configured with environment-shaped numeric strings.
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

The standalone harness must call Testbench's `Bootstrapper::bootstrap()` and create its application without an explicit base path. This keeps package manifests, compiled files, logs, databases, and every other runtime write inside Testbench's disposable skeleton copy rather than the committed `src/testbench/hypervel` source.

Measure at minimum:

- fixed-window and leaky-bucket consume through Redis, Swoole, and one explicitly labeled configured database backend;
- representative single-client and contended concurrency on allowed-heavy and denied-heavy paths, with the exact workload recorded in the output rather than a mandatory combinatorial matrix;
- a one-time old cache-backed fixed-limiter baseline versus the new drivers before old code is removed; retain the recorded comparison, not a compatibility adapter or old implementation in the final harness;
- p50/p95/p99 latency and operations/second. Measure pool wait, backend CPU, memory, or extra server versions ad hoc only when the core results expose a concrete question.

Acceptance invariants:

- Redis steady-state admission is one network round trip, one pool checkout, and one script invocation.
- No Redis cache serialization/compression path is entered.
- Swoole's ordinary admission path performs no serialization and no I/O; periodic maintenance may log capacity pressure.
- Middleware performs no post-consume state lookup for ordinary limits.
- Material throughput/latency regressions against the old baseline or between supported Redis and Valkey services are explained before merge; optimize the portable script rather than adding a premature version branch.

The future `INCREX` TODO must be revisited with the same end-to-end result contract and benchmarks, not a raw-command microbenchmark alone.

## Implementation sequence

This order keeps the tree buildable while still delivering one final cut with no compatibility residue:

1. Add package metadata/provider skeleton, Foundation/application/Testbench config, root autoload/replace entry, and default provider registration.
2. Add immutable policies, fingerprints/key resolver, decisions, contracts, manager, shared typed PHP calculator, and per-store `Limiter` wrapper with unit tests.
3. Implement worker-array store with the shared calculator and run the full store contract against it.
4. Implement Redis Lua transitions using `evalWithShaCache()`, including the required focused `@TODO`; run the existing Redis 8/Valkey 9 integration jobs and concurrency tests after adding their explicit RateLimiter path.
5. Extract the generic striped lock into Core and update Cache and Reverb; converge Cache's Swoole maintenance timers on Coordinator `Timer`; then implement the independent numeric Swoole table/state plus Coordinator-backed pruning listener and multi-worker tests.
6. Implement database store with the shared calculator, migration/prune commands, server clocks, default migrations, and database integration/concurrency tests.
7. Rewrite routing and Foundation middleware configuration; delete the Redis-specific request middleware/switch once tests pass.
8. Rewrite queue middleware and remove the two Redis-specific queue classes.
9. Rewrite Fortify, foundation exception throttling, Reverb, and facade access. Reverb receives a contextually bound direct `Limiter` so its internal per-connection limiter does not depend on application rate-limiter configuration.
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
- [ ] Redis/Swoole/database/worker-array pass the shared semantic suite, and Redis/Swoole/database pass their applicable real-contention concurrency suites; failures never fail open and no driver routes through generic cache serialization.
- [ ] Redis admission is one cached Lua call on the existing Redis 8 and Valkey 9 services; Swoole uses shared numeric state without live eviction and documents/logs capacity pressure; database uses only `rate_limits`.
- [ ] Foundation, the application skeleton, and Testbench carry the same stores/migration, with database as the application default and worker-array as the deliberate Testbench default; named stores merge without duplicate package config.
- [ ] Routing retains its Laravel-facing helpers, syntax, callbacks, exceptions, headers, and registered-store selection with one middleware; queue has no Redis/cache limiter branches, and Reverb's direct worker-local limiter is independent of application rate-limiter configuration.
- [ ] The framework metapackage, package dependencies, facade metadata, Boost's single `rate-limiting.md`, minimal README, AGENTS guidance, and required source/test difference markers agree.
- [ ] Old namespaces, classes, config, tests, docs, switches, stale state, and obsolete TODOs are absent; the INCREX and capability TODOs remain accurate.
- [ ] Existing Redis Duration/Concurrency limiters use the tested SHA-cache path without API changes.
- [ ] Static analysis, all affected suites, end-to-end benchmarks, stale-symbol searches, and `git diff --check` pass.
