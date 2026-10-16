# Sentry for Hypervel

Documentation: https://hypervel.org/docs/sentry

## Differences From Laravel

- Events are sent asynchronously through a bounded, reusable transport pool. Telemetry is dropped when pool acquisition times out, and worker-exit delivery is best effort.
- HTTP transactions have no `app.bootstrap` or `app.php.autoload` spans, and the `SENTRY_AUTOLOAD` constant has no effect. Workers boot once before serving requests, so no request includes application bootstrap.
- `HttpClientIntegration` instruments every Guzzle client through global middleware instead of HTTP client events. Each span belongs to its transfer and uses the `auto.http.guzzle` origin, so concurrent requests record independently. Trace headers still propagate when HTTP spans and breadcrumbs are disabled. Pass `sentry_enabled => false` to leave a client or request out. See [performance monitoring](https://hypervel.org/docs/sentry#performance-monitoring).
- `ConsoleSchedulingIntegration` has no `useCacheStore()` method. Background scheduled tasks run in a coroutine of the scheduler process, so their check-ins never pass through the cache to another process.

Ported from: https://github.com/getsentry/sentry-laravel
