# Object Pools

- [Introduction](#introduction)
- [Named Pools](#named-pools)
- [Pool Definitions](#pool-definitions)
- [Pool Options](#pool-options)
- [Borrowing and Leases](#borrowing-and-leases)
- [Lifecycle and Invalidation](#lifecycle-and-invalidation)
- [Consumer Integration](#consumer-integration)

<a name="introduction"></a>
## Introduction

Hypervel's object-pool component manages mutable or connection-owning objects safely inside long-lived coroutine workers. It provides bounded capacity, coroutine-aware waiting, explicit ownership tracking, deterministic destruction, absolute and idle expiry, and whole-pool reclamation.

Use an object pool only for a resource that cannot safely serve concurrent operations itself. Stateless wrappers and clients designed to multiplex requests should normally be shared directly or constructed per operation instead.

This guide documents the public, general-purpose `Hypervel\ObjectPool` API. The separate `Hypervel\Pool` component is lower-level connection-pool infrastructure used internally by database and Redis integrations; configure those pools through their consumer configuration rather than constructing them through this guide.

<a name="named-pools"></a>
## Named Pools

For most application pools, resolve `Hypervel\ObjectPool\Contracts\Factory` from the container and call `pool()` with a name, a callback, and any pool options:

```php
use Hypervel\ObjectPool\Contracts\Factory;

$pool = app(Factory::class)->pool(
    'app:reports',
    fn () => new ReportsClient,
    ['max_objects' => 20],
);
```

Use the same name only when every callback creates the same kind of object with the same configuration. If the pool already exists, Hypervel returns it and ignores the new callback. Passing different options for the same name throws an exception.

Named pools share one registry across the application. Prefix names with your application or package name, such as `app:reports`, to avoid collisions.

Call `pool()` immediately before each borrow instead of keeping the returned pool in a long-lived property. An idle managed pool may be removed and closed between operations.

If the callback depends on credentials or other values that may change, use a pool definition and build its fingerprint from those values.

<a name="pool-definitions"></a>
## Pool Definitions

Every managed pool is registered from an immutable `PoolDefinition` containing four values:

- `identity` is the registry key and must be namespace-safe within the application.
- `resourceType` prevents an explicit identity from joining pools of different object kinds.
- `fingerprint` identifies the exact construction input for the pooled object.
- `options` is a normalized `PoolOptions` value.

```php
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolOptions;

$clientConfig = [
    'endpoint' => 'https://service.example.com',
    'token' => $token,
];

$definition = new PoolDefinition(
    identity: 'app:service-client:' . PoolFingerprint::fromConfig($clientConfig),
    resourceType: 'service-client',
    fingerprint: PoolFingerprint::fromConfig($clientConfig),
    options: PoolOptions::fromArray([
        'max_objects' => 20,
    ]),
);
```

`PoolFingerprint::fromConfig()` canonicalizes nulls, booleans, integers, floats, strings, enums, lists, and maps before hashing. Map insertion order does not affect the result; list order does. Objects, closures, and resources are rejected because their runtime identity does not prove construction equivalence. Framework consumers expose a `pool.fingerprint` setting for configurations that need an explicit equivalence declaration.

Resolve `Hypervel\ObjectPool\Contracts\Factory` from the container and call `getOrCreate()`:

```php
use Hypervel\ObjectPool\Contracts\Factory;

$pool = app(Factory::class)->getOrCreate(
    $definition,
    fn () => new ServiceClient($clientConfig),
);
```

If the identity already exists, the resource type, fingerprint, and normalized options must all match. Matching definitions return the existing pool and ignore the new construction callback. A mismatch throws rather than silently sharing objects built from different credentials or settings. Every value captured by the callback must therefore derive from the fingerprinted construction input.

Managed pools deliberately do not accept a destruction callback: proxies may recreate them after purge or idle eviction, so a callback supplied only during the first registration could not be preserved honestly. Standalone `SimpleObjectPool` instances may receive a constructor-immutable destruction callback when their owner controls the complete lifecycle.

<a name="pool-options"></a>
## Pool Options

| Option | Default | Purpose |
|---|---:|---|
| `min_retained_objects` | `1` | Idle-trimming floor. Objects are not created eagerly or replenished to this value. |
| `max_objects` | `10` | Maximum number of managed objects, including checked-out objects and creation slots. |
| `wait_timeout` | `3.0` | Maximum seconds a coroutine waits for an object or newly freed creation capacity. |
| `max_lifetime` | `60.0` | Absolute object lifetime in seconds; `0` disables it. Expiry ignores the retention floor. |
| `max_idle_time` | `0.0` | Individual idle-object lifetime in seconds; `0` disables it. |
| `idle_ttl` | `300.0` | Whole-pool idle lifetime in seconds; explicit `null` disables pool eviction. |

Counts must be integers and durations must be finite integers or floats. Unknown option keys are rejected so misspellings cannot silently select defaults.

<a name="borrowing-and-leases"></a>
## Borrowing and Leases

For synchronous work, borrow with `get()` and always finalize in a guarded path:

```php
use Hypervel\ObjectPool\PoolErrorReporter;

$client = $pool->get();

try {
    $result = $client->execute($command);
} catch (Throwable $exception) {
    try {
        $pool->discard($client);
    } catch (Throwable $discardException) {
        PoolErrorReporter::report($discardException);
    }

    throw $exception;
}

$pool->release($client);
```

Use `release()` only when the object remains healthy. Use `discard()` after a protocol, network, or partial-reset failure that may have corrupted it. The pool rejects foreign objects, double releases, double discards, and factories that return an object the pool already manages.

Use a `Lease` when a stream, job, response callback, or other deferred result must retain the borrow beyond the current method call:

```php
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolErrorReporter;

$lease = new Lease($pool, $pool->get());
$client = $lease->get();

try {
    $result = $client->beginDeferredOperation();
} catch (Throwable $exception) {
    try {
        $lease->discard();
    } catch (Throwable $discardException) {
        PoolErrorReporter::report($discardException);
    }

    throw $exception;
}

// Transfer $lease alongside $result and call release() or discard() at the
// real terminal boundary. An abandoned lease releases safely on destruction.
```

Leases finalize exactly once. An optional release callback may reset an object before it is returned; if that callback throws, the lease discards the object and propagates the reset failure.

<a name="lifecycle-and-invalidation"></a>
## Lifecycle and Invalidation

`ObjectPool::close()` is terminal and idempotent. It rejects future checkouts, wakes parked borrowers, destroys every idle object, and destroys checked-out objects when they are eventually returned. `PoolManager::remove($identity)` removes the registry entry before closing, so another operation may create a fresh pool without waiting for old borrows to finish. `PoolManager::flush()` removes and closes every pool.

`PoolRecycler` periodically sweeps absolute expiry, trims per-object idle entries, and removes pools whose `idle_ttl` elapsed with no checked-out object or in-flight acquisition. Maintenance requeues do not reset user-activity timestamps.

Do not call native channel methods from destructors. Pool closure is deterministic lifecycle work for a live runtime; application shutdown and garbage collection are not substitutes for `remove()`, `flush()`, or a consumer's `purge()` method.

<a name="consumer-integration"></a>
## Consumer Integration

Generic magic proxying is intentionally unavailable. A proxy cannot know whether an arbitrary result is a fully computed value or a lazy stream, iterator, promise, or object retaining the borrow. Consumer proxies must enumerate their safe synchronous methods and use the protected `PoolProxy::invoke()` primitive. Deferred methods must retain an explicit `Lease` until their true terminal boundary.

Framework managers for filesystems, mail, queues, and broadcasting build definitions from the actual construction input, expose normalized `pool` configuration, and distinguish cache-only forgetting from pool-invalidating purge operations. Prefer those manager APIs when using a framework resource instead of creating definitions directly.
