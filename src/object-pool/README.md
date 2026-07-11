Object Pool for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/object-pool)

Hypervel's object pool is designed for long-lived, coroutine-based workers. Pools are registered from immutable `PoolDefinition` values containing a namespace-safe identity, resource type, construction fingerprint, and normalized `PoolOptions`. `PoolManager::getOrCreate()` converges equivalent definitions and rejects reuse when the resource type, fingerprint, or options differ.

`ObjectPool` tracks every managed and borrowed object explicitly. Foreign releases, double releases, duplicate factory results, and use after closure fail immediately instead of corrupting capacity. `close()` is terminal: idle objects are destroyed immediately, checked-out objects are destroyed when returned, parked borrowers wake, and later checkouts fail. `discard()` removes a failed borrowed object without returning it to circulation.

`Lease` provides exactly-once release or discard semantics for work that outlives a synchronous method call, such as streams and queue jobs. Its destructor safely finalizes abandoned borrows. Consumer proxies enumerate their supported methods and use `PoolProxy::invoke()` or a lease explicitly; generic magic forwarding is intentionally unavailable because it cannot determine whether a returned stream, iterator, promise, or other lazy value still depends on the borrowed object.

Automatic fingerprints support nulls, booleans, integers, floats, strings, enums, lists, and maps. Construction config containing objects, closures, or resources must declare equivalence explicitly through a consumer's `pool.fingerprint` setting. Automatic pools use a finite idle TTL by default, while `PoolRecycler` sweeps expired objects, trims per-object idle entries, and removes whole idle pools.
