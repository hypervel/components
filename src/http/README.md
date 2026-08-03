Http for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/http)

Documentation: https://hypervel.org/docs/requests

## Differences From Laravel

Laravel's deprecated `Request::get()` method is intentionally not ported because it mixes route attributes, query parameters, and request body input behind one ambiguous API. Use `input()`, `query()`, or `route()` to select the intended source explicitly.

Hypervel creates requests from Swoole's server request instead of `Request::capture()`. Responses are also emitted by Hypervel's server bridge rather than by calling `Response::sendContent()` directly.

Laravel's `Http::pool()` and `Http::batch()` APIs are intentionally not ported. They are built around Guzzle promise concurrency, while Hypervel uses Swoole coroutine-native concurrency through `parallel`, `Hypervel\Coroutine\Parallel`, and `defer`. See the Concurrent Requests section in the Boost HTTP client docs.

`TrustHosts` fails closed when no trusted host patterns resolve. If the middleware is enabled and no resolver, `at()` list, or valid `app.url` host provides a trusted pattern, Hypervel rejects all hosts using a never-matching sentinel. Laravel and Symfony leave the trusted host list empty in this case, which accepts every host. Configure a valid `app.url`, `TrustHosts::at()`, or `TrustHosts::resolveHostsUsing()` when enabling the middleware.

Configure trusted proxies through `$middleware->trustProxies(...)` in `bootstrap/app.php`. Hypervel does not read the legacy `trustedproxy.proxies` configuration key or include Laravel's Cloud, Forge, and Vapor host-specific proxy behavior.

Ported from: https://github.com/laravel/framework
