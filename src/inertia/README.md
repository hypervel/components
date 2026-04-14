# Inertia.js Adapter for Hypervel

The Inertia.js server-side adapter for Hypervel, providing middleware, response factories, SSR support, Blade directives, and testing utilities.

Ported from the official [inertiajs/inertia-laravel](https://github.com/inertiajs/inertia-laravel) adapter with Swoole-specific optimisations: coroutine-safe per-request state isolation, worker-lifetime caching for immutable metadata, SSR timeouts with circuit breaker protection.
