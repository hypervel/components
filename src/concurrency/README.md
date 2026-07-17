Concurrency for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/concurrency)

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Concurrency

## Differences From Laravel

Hypervel uses its Swoole coroutine driver by default; the process and sync drivers remain available explicitly. Laravel's fork driver is omitted because coroutine concurrency is Hypervel's native lightweight execution model.
