# Inertia.js Adapter for Hypervel

The Inertia.js server-side adapter for Hypervel, providing middleware, response factories, SSR support, Blade directives, and testing utilities.

## Differences From Laravel

Hypervel sends SSR requests using a dedicated reusable HTTP client. Therefore, `Http::fake()` and `Http::preventStrayRequests()` do not intercept SSR requests. Tests may replace the SSR client using `Hypervel\Inertia\Ssr\HttpGateway::useTestingClient()`.

Ported from: https://github.com/inertiajs/inertia-laravel
