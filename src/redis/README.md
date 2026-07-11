Redis for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/redis)

## Differences From Laravel

`Redis::funnel()->acquire()` returns a caller-held concurrency lease that can be refreshed and released explicitly after work spanning multiple operations. Laravel only exposes the callback-scoped funnel API.

Redis funnel and throttle timeout failures throw `Hypervel\Contracts\Limiters\LimiterTimeoutException`, shared with cache funnels. Laravel uses `Illuminate\Contracts\Redis\LimiterTimeoutException` for Redis limiters.
