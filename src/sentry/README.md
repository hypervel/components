# Sentry for Hypervel

Documentation: https://hypervel.org/docs/sentry

## Differences From Laravel

- Events are sent asynchronously through a bounded, reusable transport pool. Telemetry is dropped when pool acquisition times out, and worker-exit delivery is best effort.
- HTTP transactions have no `app.bootstrap` or `app.php.autoload` spans, and the `SENTRY_AUTOLOAD` constant has no effect. Workers boot once before serving requests, so no request includes application bootstrap.
- There is no `HttpClientIntegration` feature. An aspect instruments every Guzzle client instead, including the HTTP client and SDKs built on Guzzle, and its spans use the `auto.http.guzzle` origin. Pass the `no_sentry_aspect` Guzzle option to a client or request to leave it out.
- `ConsoleSchedulingIntegration` has no `useCacheStore()` method. Background scheduled tasks run in a coroutine of the scheduler process, so their check-ins never pass through the cache to another process.

Ported from: https://github.com/getsentry/sentry-laravel
