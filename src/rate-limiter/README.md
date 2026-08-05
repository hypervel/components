Rate Limiter for Hypervel
===

Documentation: https://hypervel.org/docs/rate-limiting

## Differences From Laravel

Hypervel provides rate limiting through the dedicated `hypervel/rate-limiter` package and the `Hypervel\RateLimiter` namespace instead of Laravel's cache-bound limiter. Policies are consumed atomically through dedicated Redis, Swoole, database, or worker-array stores; the primitive counter methods under `Illuminate\Cache\RateLimiter` are not available.

Hypervel uses immutable typed policies. `Limit` defines a fixed window, `LeakyBucket` defines a GCRA-backed leaky bucket, `Unlimited` bypasses storage, and `Backoff` defines failure-driven exponential lockout. Use `globally()` instead of Laravel's `GlobalLimit` class.

Every physical limiter key is hashed and includes its policy parameters. Changing a policy starts new state, and `clear()` must receive the same policy parameters that created the state. Sequential stacked policies retain earlier successful charges when a later policy denies, weighted denials report the actual unused capacity, and `attempt()` consumes before the callback and retains the charge if the callback throws.

Redis is selected as a regular rate-limiter store. Hypervel does not provide Redis-specific routing or queue middleware classes, a `throttleWithRedis()` switch, or an opt-out from canonical key hashing.
