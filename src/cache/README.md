Cache for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/cache)

## Differences From Laravel

`Cache::memo()` stores its per-request memoized repository directly in coroutine context instead of Laravel's scoped container binding. Coroutine teardown provides the request reset boundary in Hypervel without dynamic container bindings.
