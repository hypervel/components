Object Pool for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/object-pool)

Hypervel's object pool is designed for long-lived, coroutine-based workers. For most application pools, resolve the pool factory and call `pool()` with a name, a callback, and any pool options:

```php
use Hypervel\ObjectPool\Contracts\Factory;

$pool = app(Factory::class)->pool(
    'app:reports',
    fn () => new ReportsClient,
    ['max_objects' => 20],
);
```

Use the same name only when every callback creates the same kind of object with the same configuration. Pool names are shared across the application, so choose names such as `app:reports` to avoid collisions. Call `pool()` again before each borrow instead of keeping the returned pool in a long-lived property, because an idle pool may have been removed and closed.

If the callback depends on credentials or other values that may change, use an immutable `PoolDefinition` and build its fingerprint from those values. `PoolManager::getOrCreate()` returns an existing pool only when its resource type, fingerprint, and options match the requested definition.

`ObjectPool` tracks every managed and borrowed object explicitly. Foreign releases, double releases, duplicate factory results, and use after closure fail immediately instead of corrupting capacity. `close()` is terminal: idle objects are destroyed immediately, checked-out objects are destroyed when returned, parked borrowers wake, and later checkouts fail. `discard()` removes a failed borrowed object without returning it to circulation.

`Lease` provides exactly-once release or discard semantics for work that outlives a synchronous method call, such as streams and queue jobs. Its destructor safely finalizes abandoned borrows. Consumer proxies enumerate their supported methods and use `PoolProxy::invoke()` or a lease explicitly; generic magic forwarding is intentionally unavailable because it cannot determine whether a returned stream, iterator, promise, or other lazy value still depends on the borrowed object.

Automatic fingerprints support nulls, booleans, integers, floats, strings, enums, lists, and maps. Construction config containing objects, closures, or resources must declare equivalence explicitly through a consumer's `pool.fingerprint` setting. Automatic pools use a finite idle TTL by default, while `PoolRecycler` sweeps expired objects, trims per-object idle entries, and removes whole idle pools.
