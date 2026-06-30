Http for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/http)

## Differences From Laravel

Laravel's `Http::pool()` and `Http::batch()` APIs are intentionally not ported. They are built around Guzzle promise concurrency, while Hypervel uses Swoole coroutine-native concurrency through `parallel`, `Hypervel\Coroutine\Parallel`, and `defer`. See the Concurrent Requests section in the Boost HTTP client docs.
