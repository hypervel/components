# Sliding-Window Rate Limiter Plan

## Status and goal

Implement a first-class weighted sliding-window admission policy in `hypervel/rate-limiter`. It must work through the existing `consume`, `inspect`, `attempt`, and `clear` APIs and every first-party store. The public API stays Laravel-shaped, while each backend keeps its existing optimized atomic path.

Base commit: `41ec39db5bc1e62eabb64d686179722386a8ac30` on `feature/rate-limiter`.

The completed package will offer four distinct behaviors:

- `Limit`: first-hit fixed window;
- `SlidingWindow`: weighted two-window counter;
- `LeakyBucket`: continuously replenishing GCRA limit;
- `Backoff`: failure-driven capped exponential delay.

This plan is the complete implementation context for sliding windows. It supersedes the earlier package plan's sliding-window exclusion. The existing package architecture and API remain otherwise unchanged.

## Final public API

Add `Hypervel\RateLimiter\SlidingWindow` beside `Limit` and `LeakyBucket`:

```php
readonly class SlidingWindow extends AdmissionPolicy
{
    public function __construct(
        public int $maxAttempts = 60,
        public int $windowSeconds = 60,
        string $key = '',
        int $cost = 1,
        bool $global = false,
        ?Closure $afterCallback = null,
        ?Closure $responseCallback = null,
    );

    public static function perSecond(int $maxAttempts, int $windowSeconds = 1): static;
    public static function perMinute(int $maxAttempts, int $windowMinutes = 1): static;
    public static function perMinutes(int $windowMinutes, int $maxAttempts): static;
    public static function perHour(int $maxAttempts, int $windowHours = 1): static;
    public static function perDay(int $maxAttempts, int $windowDays = 1): static;
}
```

It inherits `by`, `cost`, `globally`, `after`, and `response`. Every modifier remains immutable. Typical use:

```php
$limit = SlidingWindow::perMinute(100)
    ->cost(5)
    ->by('uploads:'.$user->id);

$result = RateLimiter::store('redis')->consume($limit);
```

Do not add manager methods, store config, drivers, events, enums, registries, timers, or a generic algorithm hierarchy. `SlidingWindow` is a typed policy handled by the existing manager, `Limiter`, `KeyResolver`, `Store` contract, and result type.

## Why this algorithm

Sliding window is a standard general-purpose alternative to fixed windows. It smooths the fixed-window boundary while keeping constant state and bounded work. Hypervel will use the common weighted two-window estimate:

```text
estimated = current + floor(previous * remaining_in_current_window / window)
allowed = estimated + cost <= max_attempts
```

The window begins on the first accepted operation, matching `Limit` rather than introducing calendar alignment. State lives for two window periods because the current counter becomes the weighted previous counter in the next period.

Deliberate exclusions:

- An exact sliding log needs per-operation records, variable memory, and `O(log n)` or worse backend work.
- A segmented ring adds configurable segment counts, larger state, and `O(segments)` rotation for little benefit over this approximation.
- Calendar-aligned two-key Redis designs create a different boundary contract and use multiple keys.
- GCRA already provides continuous smoothing and burst control; sliding window fills the separate need for an intuitive rolling-count approximation.

## Research checked

The design was checked against these local sources:

| Source | Local path | Useful finding |
|---|---|---|
| [Symfony RateLimiter](https://github.com/symfony/rate-limiter) | `examples/symfony/rate-limiter` | Uses current and previous counters, first-hit windows, and a two-window lifetime. Its generic storage/lock layer is not suitable for Hypervel's Redis hot path. |
| [Python `limits`](https://github.com/alisaifee/limits) | `/tmp/hypervel-sliding-window-research.wFjjW4/python-limits` | Confirms weighted counters and TTL-driven rotation; its two Redis keys are unnecessary here. |
| [.NET runtime](https://github.com/dotnet/runtime) | `/tmp/hypervel-sliding-window-research.wFjjW4/dotnet-runtime` | Uses a segmented ring, queues, and timers; this is broader and heavier than Hypervel needs. |
| [Upstash Ratelimit](https://github.com/upstash/ratelimit-js) | `/tmp/hypervel-sliding-window-research.wFjjW4/upstash-ratelimit-js` | Shows a compact atomic Redis implementation, but uses calendar windows and two keys. |
| [Redis rate-limiting JS](https://github.com/redis-developer/redis-ratelimiting-js) | `/tmp/hypervel-sliding-window-research.wFjjW4/redis-ratelimiting-js` | Confirms weighted counters; its exact-log option has per-request sorted-set state and is rejected. |
| [Go sliding window](https://github.com/RussellLuo/slidingwindow) | `/tmp/hypervel-sliding-window-research.wFjjW4/go-slidingwindow` | Confirms the widely used current/previous weighted-counter model. |
| [Laravel sliding-window package](https://github.com/beyondcode/laravel-sliding-window-limiter) | `/tmp/hypervel-sliding-window-research.wFjjW4/laravel-sliding-window-limiter` | Uses variable hash segments and non-atomic read/check/write operations, so it is not a correctness or performance model. |

The selected Redis representation was also compared directly. Three local raw-script runs measured the two-field `PTTL` form at roughly 30.1k, 32.3k, and 30.6k operations per second, versus 28.6k, 29.4k, and 26.2k for a third timestamp plus `TIME`. This is supporting evidence only; the package benchmark remains the release-facing end-to-end measurement.

## Numeric contract

All stores must return identical millisecond-quantized decisions. Redis exposes TTL in milliseconds, so the PHP calculator floors its clock once at the start of sliding-window calculation:

```php
$now -= $now % 1000;
```

Existing fixed-window, GCRA, and backoff precision must not change.

Use:

```php
private const int WEIGHT_SCALE = 1_000_000;
```

With `remaining` in microseconds and `windowSeconds` in seconds:

```php
$weight = min(self::WEIGHT_SCALE, intdiv($remaining, $policy->windowSeconds));
$weightedPrevious = intdiv($previous * $weight, self::WEIGHT_SCALE);
$estimated = $current + $weightedPrevious;
```

The weight clamp is required when a backend clock moves backwards and the remaining lifetime exceeds one window. Keep the raw remaining lifetime for `retryAfter`, `resetAfter`, and persisted TTL; only the weight is clamped.

### Limits and validation

The scaled multiplication must remain within `AdmissionPolicy::MAX_INTEGER` (`2^53 - 1`) on every backend:

```text
maximum maxAttempts = floor(9_007_199_254_740_991 / 1_000_000)
                    = 9_007_199_254

maximum windowSeconds = floor(9_007_199_254_740_991 / 2_000_000)
                      = 4_503_599_627
```

`9_007_199_254 * 1_000_000` leaves 740,991 exact-integer units of headroom. Reject larger values during policy construction. Validate `cost <= maxAttempts` in `Limiter` before key resolution or storage. Validate `now + 2 * window` through the existing time-range check.

Factories must convert their own public unit directly so error messages name seconds, minutes, hours, or days correctly. Do not route one factory through another when that changes the validation message.

For `SlidingWindow`, validation covers the full two-window lifetime. The constructor and `perSecond` validate `windowSeconds * 2_000_000` under "window seconds". Minute, hour, and day factories validate these exact multipliers under their own unit names, then recover stored seconds by dividing the validated result by `2_000_000`:

| Factory | Two-window multiplier | Maximum accepted units |
|---|---:|---:|
| `perSecond` | `2_000_000` | `4_503_599_627` |
| `perMinute` / `perMinutes` | `120_000_000` | `75_059_993` |
| `perHour` | `7_200_000_000` | `1_250_999` |
| `perDay` | `172_800_000_000` | `52_124` |

```php
$windowMicroseconds = static::multiply($windowMinutes, 120_000_000, 'window minutes');

return new static($maxAttempts, intdiv($windowMicroseconds, 2_000_000));
```

`perMinutes` may delegate to `perMinute` because both accept minutes. Do not repeat the seconds check in `perSecond`; its constructor already performs the exact validation with the correct unit.

Apply the same rule to `Limit`. Its minute/hour/day factories currently validate the unit-to-seconds conversion, then let the constructor's seconds-to-microseconds check report an overflow in "decay seconds" even when the caller supplied another unit. Pre-validate each factory's complete unit-to-microseconds conversion under its public unit name, derive the stored seconds from that exact value, and retain constructor validation for direct construction. `perMinutes` may delegate to `perMinute` because both accept minutes. This is policy construction work, not a limiter hot-path change.

```php
$decayMicroseconds = static::multiply($decayMinutes, 60_000_000, 'decay minutes');

return new static($maxAttempts, intdiv($decayMicroseconds, 1_000_000));
```

## Shared state and schema

The common PHP stores already persist three integers. Rename the generic second field from the backoff-specific `available_at` / `$availableAt` to `secondary_value` / `$secondaryValue` everywhere owned by the rate-limiter package:

| Policy | `value` | `secondary_value` | `expires_at` |
|---|---|---|---|
| Fixed window | consumed capacity | `0` | window end |
| Leaky bucket | TAT | `0` | TAT |
| Backoff | failure count | blocked-until timestamp | inactivity expiry |
| Sliding window | current counter | previous counter | end of the following window |

This affects the shared calculator, database store, Swoole store/table, worker-array store, rate-limiter migration stub, Testbench migration, application skeleton migration, and their tests. Redis backoff keeps the semantic hash field `available_at`; it is not generic shared state.

This is more than a field rename for fixed windows. Their current shared state duplicates expiry in both `available_at` and `expires_at`. Stop writing that duplicate and require `secondary_value === 0` in `validateFixedWindowState()`. `validateLeakyBucketState()` already requires the second value to be zero and only needs the variable rename. Invert the fixed-window database assertion that currently expects the two old fields to match.

Hypervel 0.4 has no compatibility burden, so update the existing migrations rather than add a column-rename migration or dual-read path. The final schema is:

```php
$table->char('key', 32)->primary();
$table->unsignedBigInteger('value')->default(0);
$table->unsignedBigInteger('secondary_value')->default(0);
$table->unsignedBigInteger('expires_at')->index();
```

Valid sliding state is either all-zero empty state, or `current` in `1..maxAttempts`, `previous` in `0..maxAttempts`, and a positive expiry. A package-written live row never has `current = 0`: inspection and denial do not persist logical rotation, while an accepted rotation writes its positive cost.

## Shared PHP transition

Extend `CalculatesRateLimits` and both admission dispatches with `SlidingWindow`. The operation works on local copies; stores persist them only after an accepted consume, preserving inspection and denial immutability.

State interpretation after expired-state reset:

```php
$window = $policy->windowSeconds * 1_000_000;
$windowEnd = $expiresAt - $window;

if ($expiresAt === 0) {
    // Empty.
} elseif ($now < $windowEnd) {
    $remaining = $windowEnd - $now;
} else {
    // Logical rotation only; persist it only if this consume is accepted.
    $previous = $current;
    $current = 0;
    $remaining = $expiresAt - $now;
}
```

Transitions:

- Missing inspect: allowed, full remaining capacity, retry/reset `0`, no state.
- First accepted consume: `current = cost`, `previous = 0`, expiry `now + 2W`, reset `2W`.
- Same-window acceptance: increment current, retain previous and expiry.
- Rotated acceptance: write `current = cost`, `previous = old current`, extend expiry by `W`.
- Denial or inspection: never write the logical rotation or extend expiry.
- Expired state: treat as empty; mutation replaces it only on acceptance.

For an accepted consume, `remaining()` is `maxAttempts - estimated - cost`. For inspection or denial, it is `max(0, maxAttempts - estimated)`. `resetAfter()` is the raw time until all contributing state expires, so it may be as high as two window periods. On a rotated acceptance, calculate it from the post-write expiry after extending that expiry by `W`; the newly accepted current count remains relevant through the following window. `retryAfter()` is zero when allowed and the minimum millisecond-grid wait for the configured cost when denied.

### Exact retry calculation

When `current + cost <= limit` but the weighted previous counter causes denial, set:

```php
$available = $limit - $current - $cost;
$maximumWeight = intdiv((($available + 1) * self::WEIGHT_SCALE) - 1, $previous);
$maximumRemainingMilliseconds = intdiv(
    (($maximumWeight + 1) * $policy->windowSeconds) - 1,
    1000,
);
$retry = (intdiv($remaining, 1000) - $maximumRemainingMilliseconds) * 1000;
```

This inverts both integer floors without returning a retry that is one millisecond too early. Call it only when the current weighted value is denied.

When `current + cost > limit`, admission cannot occur before the current boundary. Add the remaining time to that boundary, rotate `current` into `previous`, then apply the same inverse calculation from a full next window with `available = limit - cost`. This result never exceeds the raw reset time.

The retry branches have these required preconditions:

- Weighted denial has `weightedPrevious > available >= 0`, so `weightedPrevious >= 1` and `previous >= 1`; the divisor is non-zero.
- Capacity denial has `current > limit - cost >= 0`, so `current >= 1`; after the boundary, the divisor `previous = current` is non-zero. One boundary hop suffices because `limit - cost >= 0`.
- Capacity denial cannot follow a logical rotation because rotation sets `current = 0`.
- The post-boundary capacity-denial state is still denied at full previous weight: entering the branch proves `current > limit - cost`. Its maximum weight is therefore at most `999_999`, and the second inverse always returns at least one millisecond; do not add an unreachable zero-delay guard.

The inverse was exhaustively checked over both one- and two-second windows, `previous` values 1 through 8, every smaller available value, and every millisecond position. All 42,060 denied states returned the first admissible millisecond. Keep an aggregate deterministic test for this domain rather than thousands of PHPUnit assertions.

## Redis transition

Add one dedicated Lua script and `executeSlidingWindow()`. It must use one physical key, one `evalWithShaCache()` call, one pooled connection checkout, and no cache serializer. Store one hash with only:

```text
current
previous
```

Derive position from `PTTL`; do not store a timestamp or call `TIME`:

```lua
local ttl = redis.call('PTTL', KEYS[1])

if ttl > windowMilliseconds then
    remainingMilliseconds = ttl - windowMilliseconds
else
    previous = current
    current = 0
    remainingMilliseconds = ttl
    rotated = true
end

local weight
if remainingMilliseconds >= windowMilliseconds then
    weight = WEIGHT_SCALE
else
    weight = math.floor(remainingMilliseconds * 1000 / windowSeconds)
end
```

The `remainingMilliseconds >= windowMilliseconds` branch clamps before multiplication, so a clock rollback cannot make a large raw TTL overflow. Do not reject a TTL above two periods; that is a valid conservative clock-rollback state. Return the raw TTL-derived reset and retry values.

State rules:

- Both hash fields absent means empty.
- A partial hash, noncanonical integer, live `current = 0`, field outside its policy range, or missing expiry is corrupt and returns an `ERR` reply.
- Check `PTTL == -1` first and report the missing expiry as corrupt; only then treat the remaining `PTTL <= 0` values as logically empty.
- Initial acceptance uses `HSET current cost previous 0` and `PEXPIRE 2W`.
- Same-window acceptance uses `HINCRBY current cost`; Redis retains the TTL.
- Rotated acceptance uses `HSET current cost previous oldCurrent` and `PEXPIRE ttl + W`.
- Inspection and denial perform no write.
- Return the standard five-integer tuple: allowed flag, limit, remaining, retry microseconds, reset microseconds.

Use only established Redis/Valkey commands: `HMGET`, `HSET`, `HINCRBY`, `PTTL`, `PEXPIRE`, `DEL`, and the existing `EVALSHA`/`EVAL` fallback. No Redis 8 command, server-version branch, raw command, second key, or Redis Function is needed.

## File changes

### Package source

- Add `src/rate-limiter/src/SlidingWindow.php`.
- Correct `Limit` factory overflow validation so minute/hour/day callers receive errors in the units they supplied.
- Extend `Limiter` validation and maximum state duration.
- Add the stable `sliding-window` fingerprint with max attempts, window seconds, and global scope in `KeyResolver`; cost and callbacks remain excluded.
- Add sliding calculation and the `secondaryValue` rename in `Concerns/CalculatesRateLimits.php`.
- Update `DatabaseStore`, `SwooleStore`, `WorkerArrayStore`, and `Swoole/TableManager` for the generic second field and sliding state.
- Add the one-key sliding Lua path to `RedisStore` without changing the existing scripts.
- Update `Console/stubs/rate-limits.stub`.
- Correct the active package plan at `docs/plans/2026-08-04-1543-rate-limiter-package.md`: remove sliding window from its non-goals; add it to the typed-policy list, target layout, state mapping, and final checklist; and change the common Swoole/database column to `secondary_value`. Leave the Redis backoff hash's semantic `available_at` unchanged.

The `Store` and `PrunableStore` contracts, `LimitResult`, manager, service provider, facade, config, and package dependencies do not change.

### Framework and skeleton state

- Update `src/testbench/hypervel/migrations/0001_01_01_000008_testbench_create_rate_limits_table.php`.
- Update the existing application skeleton migration at `contrib/hypervel/hypervel/database/migrations/0001_01_01_000008_create_rate_limits_table.php` under that repository's rules.
- Update generator, migration inventory, schema, database-row, Swoole-column, and state-shape assertions. Do not change queue tables whose unrelated `available_at` column is correct.

### Documentation and PR

- Update `src/boost/docs/rate-limiting.md` as the sole full guide:
  - list sliding windows in the introduction and table of contents;
  - add a short comparison table for choosing fixed, sliding, leaky, or backoff behavior;
  - document `SlidingWindow` factories, weighted approximation, first-hit anchoring, two-period reset, modifiers, and examples;
  - include sliding in result, store, Swoole lifetime, custom-store, and validation descriptions.
- Update the short supported-policy sentences in `routing.md` and `queues.md`.
- Update the package README's `Differences From Laravel` policy list without duplicating the guide.
- Draft an updated PR #480 body in a temporary file. If the prior `/tmp/hypervel-rate-limiter-pr.md` no longer exists, fetch the current body with `gh pr view 480 --json body` and recreate the draft. Add `SlidingWindow` to the policy summary, explain that it is a first-hit-anchored weighted two-window approximation with constant state, and include one concise factory/consume example. Present the draft to the user; publishing it with `gh pr edit --body-file` requires explicit approval.
- Use the simple, direct style of the existing Laravel-ported docs. Do not rewrite untouched Laravel prose.

### Benchmark

Extend `tests/Benchmarks/RateLimiter/benchmark.php` and its README:

- add sliding-window allowed-heavy and denied-heavy rows for Redis, Swoole, and the selected database store;
- keep the current one-client and contended-client shapes;
- do not add backend-specific state injection or a broad parameter matrix.

The benchmark must continue exercising the manager, pool, driver, Lua/result decoding, and cleanup. Rotation behavior belongs in deterministic store tests rather than a timed setup row. The acceptance target is unchanged: Redis steady state is one checkout and one script; PHP stores use constant integer state and bounded work.

## Tests

Run every changed test file immediately after editing it.

### Policy and dispatch

- Add `tests/RateLimiter/SlidingWindowTest.php` for every factory, public-unit conversion, immutable modifiers/callbacks, positive input validation, the exact max-attempt ceiling, max+1 rejection, two-window duration bounds, copied fields, and overflow messages naming seconds, minutes, hours, and days correctly.
- Extend `LimitTest` with minute/hour/day overflow messages that name the caller's unit.
- Extend `LimiterTest` for cost/capacity validation before key or store access and full `2W` time-range validation; retain its existing unsupported-policy coverage.
- Extend `KeyResolverTest` with a golden sliding identity and checks that parameters/type affect identity while cost/callbacks do not.

### Exact calculator

Add a focused `tests/RateLimiter/SlidingWindowCalculatorTest.php` using a tiny test fixture around `CalculatesRateLimits`. Use `ReflectionProperty` only to inspect `LimitResult`'s existing internal microsecond values; do not widen the production API for tests.

Cover:

- missing inspection and first consume;
- same-window weighting and logical rotation;
- immediately before, exactly at, and immediately after a boundary;
- multiple elapsed windows;
- accepted and denied weighted costs, including a rotated denial that leaves stored state unchanged;
- inspection and denial immutability;
- same-bucket TTL retention and accepted-rotation expiry extension;
- truthful remaining capacity and reset up to `2W`;
- both retry branches and first-admissible-millisecond behavior;
- backward clock movement with separate vectors proving the accepted decision changes without the weight clamp and that denied retries/resets retain the raw duration;
- empty, valid, and corrupt state shapes;
- the deterministic exhaustive domain described above;
- high-frequency boundary vectors with previous values 999, 1000, 1001, and 2000;
- exact ceiling/product/headroom vectors.

### Shared stores and integration

Extend `RateLimiterStoreContract` so worker-array, Swoole, SQLite, MySQL, MariaDB, PostgreSQL, Redis, and Valkey all prove:

- weighted sliding admission and denial;
- denial/inspection immutability;
- recovery across a boundary;
- expiry, matching clear, and changed-parameter isolation.

Add store-specific coverage:

- Worker array: generic state shape, rotation, expiry, and no suspension-dependent behavior.
- Swoole: `secondary_value` column, rotation, shared-table contention, pruning lifetime, and state validation.
- Database: schema/mapping on all four drivers, rotation within the existing transaction/row lock, server-time behavior, concurrent exact-capacity admission, and pruning.
- Redis unit/integration: one key and exact arguments; no `TIME`; two-field hash; initial/same/rotated TTL; missing inspect; denial immutability, including rotated denial; malformed, partial, noncanonical, no-expiry, and zero-current state; separate clock-rollback vectors proving a clamp-dependent accepted decision and raw denied retry/reset durations without TTL rewrites; prefix, serializer/compression, NOSCRIPT fallback, and concurrent weighted admission.

Reuse the existing contention harnesses where they already test store atomicity. Do not add duplicate process/coroutine frameworks merely for the new policy.

Add focused routing and queue integration cases proving named limiters accept `SlidingWindow` without a special middleware path and use its returned retry/remaining values. No framework consumer source change is expected because both already operate on `AdmissionPolicy`; investigate before editing those consumers if a test disproves that contract.

### Documentation and static checks

- Verify anchors and links in rate-limiting, routing, and queue docs.
- Search all `src/` and `tests/` references to ensure rate-limiter-owned `available_at` is gone except Redis backoff; unrelated queue columns remain.
- Search every policy dispatch/list to ensure `SlidingWindow` is included.
- Run `git diff --check` in both repositories.

## Implementation order and verification

1. Add and test `SlidingWindow` plus Limiter/KeyResolver support, and correct `Limit` factory unit validation.
2. Rename common state to `secondary_value`, update both migrations, and run affected schema/store tests.
3. Add and prove the shared calculator transition.
4. Wire worker-array, Swoole, and database through that transition and run each store's unit/integration tests.
5. Add the optimized Redis script and run unit, Redis, Valkey, and concurrency coverage.
6. Update the shared contract suite and benchmark.
7. Update Boost docs, README, the active package plan, a draft PR body, and policy lists. Do not publish the PR body without explicit approval.
8. Run focused package, routing, queue, database, Redis/Valkey, Swoole, generator, Testbench, and skeleton checks.
9. Run `composer fix` from the components worktree. If it fails, correct the cause and run the failed stage plus every remaining stage in the script.
10. Review every changed caller/callee, stale symbol, hot path, schema, test, and doc. Fix all findings before requesting code review.

## Completion checklist

- [ ] `SlidingWindow` has the approved Laravel-style factories and inherited modifiers.
- [ ] All stores implement the same millisecond-quantized weighted algorithm and result semantics.
- [ ] Redis uses one key, one cached Lua call, two hash fields, mature commands, and no `TIME`.
- [ ] Common PHP state and migrations use `secondary_value`; Redis backoff alone retains semantic `available_at`.
- [ ] Fixed-window state no longer duplicates expiry, and all period factories report validation errors in the caller's unit.
- [ ] Cost, time, exact-integer, corruption, rollback, retry, rotation, and concurrency edges are covered.
- [ ] No inspection or denial mutates or extends state.
- [ ] Documentation, both active plans, package README, benchmark, draft PR body, routing, and queue policy lists agree.
- [ ] No compatibility layer, strategy enum, generic algorithm framework, extra driver/config, exact log, segmented ring, event, or timer was added.
- [ ] Targeted tests, integration services, skeleton checks, `composer fix`, stale searches, and `git diff --check` pass.
