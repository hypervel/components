# Inertia.js Adapter for Hypervel

The Inertia.js server-side adapter for Hypervel, providing middleware, response factories, SSR support, Blade directives, and testing utilities.

## Differences From Laravel

SSR requests default to a 2-second connect timeout and a 5-second total timeout, configured by the `inertia.ssr.connect_timeout` and `inertia.ssr.timeout` options, so pages fall back to client-side rendering quickly when the SSR server is slow. Laravel's adapter uses the HTTP client's global timeouts unless `inertia.ssr.timeout` is set. Set either option to `null` to use the global timeout instead.

Ported from: https://github.com/inertiajs/inertia-laravel
