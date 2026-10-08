# Sentry for Hypervel

Documentation: https://hypervel.org/docs/sentry

## Differences From Laravel

- Events are sent asynchronously through a bounded, reusable transport pool. Telemetry is dropped when pool acquisition times out, and worker-exit delivery is best effort.
- HTTP transactions have no `app.bootstrap` or `app.php.autoload` spans, and the `SENTRY_AUTOLOAD` constant has no effect. Workers boot once before serving requests, so no request includes application bootstrap.

Ported from: https://github.com/getsentry/sentry-laravel
