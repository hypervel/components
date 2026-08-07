Rate Limiter for Hypervel
===

Documentation: https://hypervel.org/docs/rate-limiting

## Differences From Laravel

- Hypervel provides rate limiting through the dedicated `hypervel/rate-limiter` package and `Hypervel\RateLimiter` namespace instead of Laravel's Cache component.
- Hypervel uses immutable, typed rate limits. `Limit` defines a fixed window, `SlidingWindow` defines a weighted sliding window, `LeakyBucket` defines a GCRA-backed leaky bucket, `Unlimited` bypasses storage, and `Backoff` defines failure-driven exponential delays. Use `globally()` instead of Laravel's `GlobalLimit` class.
- The `consume`, `inspect`, `attempt`, `recordFailure`, and `clear` methods replace Laravel's split primitive counter API. Dedicated Redis, Swoole, database, and worker-array stores perform their state changes atomically.
- Rate limit keys are always hashed and include the rate limit type, its stable algorithm settings, and its global scope. Cost and callbacks do not affect identity. Changing identity settings starts new state, and `clear()` must receive the same settings that created the state.
- When several rate limits are consumed in order, earlier successful charges remain if a later rate limit denies the operation. Weighted denials report the actual unused capacity. The `attempt()` method consumes before invoking its callback and retains the charge if the callback throws.
- Redis is selected through the normal rate limiter store API. Hypervel does not provide Redis-specific routing or queue middleware classes, a `throttleWithRedis()` switch, a `redis` argument on `Middleware::throttleApi()`, or a way to disable key hashing.
