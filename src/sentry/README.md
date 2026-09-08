# Sentry for Hypervel

Documentation: https://hypervel.org/docs/sentry

## Differences From Laravel

- Events are sent asynchronously through a bounded, reusable transport pool. Telemetry is dropped when pool acquisition times out, and worker-exit delivery is best effort.

Ported from: https://github.com/getsentry/sentry-laravel
