Redis for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/redis)

Ported from: https://github.com/hyperf/hyperf/tree/master/src/redis

## Differences From Laravel

- Hypervel uses phpredis-only pooled connections. Cluster and Sentinel settings belong to each named connection instead of Laravel's top-level cluster configuration.
- Hypervel never automatically replays a command after a Redis failure because the server may already have committed it. Native phpredis retry and backoff options remain supported.
- Redis connection macros are observed as one proxy operation; native commands run inside a macro do not emit separate command events.
- Laravel's connector-driver `extend()` and `setDriver()` APIs are intentionally omitted. Use Redis macros for custom commands or `withConnection()` for explicit access to a held connection.
- Native `subscribe()`, `psubscribe()`, and `ssubscribe()` calls are unavailable on pooled connections. Use the dedicated subscriber for ordinary channel and pattern subscriptions; sharded Pub/Sub is not supported.
- Native `reset()` is unavailable on pooled connections because it clears authentication and database state owned by the pool. Use `discard()`, `unwatch()`, or `exec()` to finish the corresponding stateful operation.
- `Redis::funnel()->acquire()` returns a caller-held concurrency lease that can be refreshed and released explicitly after work spanning multiple operations. Laravel only exposes the callback-scoped funnel API.
- Redis funnel and throttle timeout failures throw `Hypervel\Contracts\Limiters\LimiterTimeoutException`, shared with cache funnels. Laravel uses `Illuminate\Contracts\Redis\LimiterTimeoutException` for Redis limiters.
