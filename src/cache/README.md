Cache for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/cache)

## Differences From Laravel

Laravel provides rate limiting through its Cache component. Hypervel provides it through the dedicated `hypervel/rate-limiter` package and `Hypervel\RateLimiter` namespace instead. See the [rate limiting documentation](https://hypervel.org/docs/rate-limiting).

The `array` cache store is request-local in Hypervel. Laravel can keep array-store values on the store object because the PHP process normally ends after each request; Hypervel workers are long-lived, so mutable array-store data lives in `CoroutineContext` and resets when the current unit of work finishes.

Hypervel also provides a `worker-array` cache store for deliberate worker-lifetime in-memory cache data. It is shared by coroutines in the same worker process and is cleared when that worker exits.

`Cache::memo()` stores its per-request memoized repository directly in coroutine context instead of Laravel's scoped container binding. Coroutine teardown provides the request reset boundary in Hypervel without dynamic container bindings.

Hypervel exposes refreshable locks through the `Hypervel\Contracts\Cache\RefreshableLock` capability interface and adds `getRemainingLifetime()` for drivers that can inspect TTLs. Laravel exposes `refresh()` directly on supported lock implementations without a typed capability contract.

Calling `refresh()` with an explicit non-positive TTL throws `InvalidArgumentException` in Hypervel. Laravel's drivers disagree about what `refresh(0)` means, and Hypervel keeps lock TTLs as the crash-safety boundary instead of silently making a lock permanent.

Calling `refresh()` without arguments on a permanent native-expiry lock verifies ownership before returning. Database locks are not truly permanent because the database has no native TTL cleanup, so `refresh()` re-extends the driver's default safety timeout.

`Cache::funnel()->acquire()` returns a caller-held concurrency lease that can be released explicitly after work spanning multiple operations. Laravel only exposes the callback-scoped funnel API.

Cache funnel timeout failures throw `Hypervel\Contracts\Limiters\LimiterTimeoutException`, shared with Redis funnels and Redis throttles. Laravel uses separate cache and Redis limiter timeout exception classes.
