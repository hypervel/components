Http for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/http)

## Differences From Laravel

Laravel's `Http::pool()` and `Http::batch()` APIs are intentionally not ported. They are built around Guzzle promise concurrency, while Hypervel uses Swoole coroutine-native concurrency through `parallel`, `Hypervel\Coroutine\Parallel`, and `defer`. See the Concurrent Requests section in the Boost HTTP client docs.

`TrustHosts` fails closed when no trusted host patterns resolve. If the middleware is enabled and no resolver, `at()` list, or valid `app.url` host provides a trusted pattern, Hypervel rejects all hosts using a never-matching sentinel. Laravel and Symfony leave the trusted host list empty in this case, which accepts every host. Configure a valid `app.url`, `TrustHosts::at()`, or `TrustHosts::resolveHostsUsing()` when enabling the middleware.
