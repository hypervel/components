Cache for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/cache)

## Differences From Laravel

The `array` cache store is request-local in Hypervel. Laravel can keep array-store values on the store object because the PHP process normally ends after each request; Hypervel workers are long-lived, so mutable array-store data lives in `CoroutineContext` and resets when the current unit of work finishes.

Hypervel also provides a `worker-array` cache store for deliberate worker-lifetime in-memory cache data. It is shared by coroutines in the same worker process and is cleared when that worker exits.

`Cache::memo()` stores its per-request memoized repository directly in coroutine context instead of Laravel's scoped container binding. Coroutine teardown provides the request reset boundary in Hypervel without dynamic container bindings.
