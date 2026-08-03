# Pools

- [Introduction](#introduction)
    - [Choosing a Pool](#choosing-a-pool)
- [Object Pools](#object-pools)
    - [Managed Pools](#managed-pools)
    - [Pool Definitions](#pool-definitions)
    - [Standalone Pools](#standalone-pools)
    - [Object Pool Options](#object-pool-options)
    - [Borrowing Objects](#borrowing-objects)
    - [Leases](#leases)
    - [Object Pool Lifecycle](#object-pool-lifecycle)
    - [Consumer Integration](#consumer-integration)
- [Connection Pools](#connection-pools)
    - [Defining a Connection Pool](#defining-a-connection-pool)
    - [Borrowing Connections](#borrowing-connections)
    - [Connection Pool Options](#connection-pool-options)
    - [Connection Pool Lifecycle](#connection-pool-lifecycle)

<a name="introduction"></a>
## Introduction

Hypervel provides object pools for general-purpose reusable objects and connection pools for packages that manage database, network, or other protocol connections. Both pool types limit how many resources may exist and ensure that one borrowed resource is not shared by concurrent operations.

Most applications interact with pools through features such as the database, Redis, filesystem, mail, and queue services, or through broadcasting drivers explicitly marked as poolable. However, you may also use the pool components directly when building an application service or package.

<a name="choosing-a-pool"></a>
### Choosing a Pool

Use an object pool when an object is expensive to create and cannot safely handle concurrent operations, but does not need connection-specific health checks or reconnection behavior. Mutable SDK clients and protocol sessions are common examples.

Use a connection pool when building a package that manages real connections and needs connection health checks, reconnection, timeouts, heartbeats, lifetime limits, or protocol-specific cleanup. Applications should configure Hypervel's built-in [database connection pools](/docs/{{version}}/database#connection-pooling) and [Redis connection pools](/docs/{{version}}/redis#connection-pooling) through their normal configuration files instead of constructing connection pools directly.

Stateless wrappers and clients that safely multiplex concurrent requests should normally be shared directly or created for each operation instead of being pooled.

<a name="object-pools"></a>
## Object Pools

The `Hypervel\ObjectPool` component provides managed pools for application and framework resources, as well as standalone pools for resources with one clear owner.

<a name="managed-pools"></a>
### Managed Pools

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

> [!WARNING]
> Managed pool callbacks may be retained across requests. Do not capture request-specific values in a callback while reusing one fixed pool name. Include those values in the pool name, or use a pool definition whose fingerprint includes every value that changes how the object is created. Use a distinct identity when several configurations must coexist.

Named pools share one registry within each worker. Prefix names with your application or package name, such as `app:reports`, to avoid collisions.

Call `pool()` immediately before each borrow instead of keeping the returned pool in a long-lived property. An idle managed pool may be removed and closed between operations.

If the callback depends on credentials or other values that may change, use a pool definition and build its fingerprint from those values.

<a name="pool-definitions"></a>
### Pool Definitions

Every managed pool is registered from an immutable `PoolDefinition` containing four values:

- `identity` is the unique registry key for the pool.
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

$fingerprint = PoolFingerprint::fromConfig($clientConfig);

$definition = new PoolDefinition(
    identity: 'app:service-client:' . $fingerprint,
    resourceType: 'service-client',
    fingerprint: $fingerprint,
    options: PoolOptions::fromArray([
        'max_objects' => 20,
    ]),
);
```

`PoolFingerprint::fromConfig()` creates a stable fingerprint from nulls, booleans, integers, floats, strings, enums, lists, and associative arrays. The order of associative-array keys does not affect the result, but list order does. Objects, closures, and resources are rejected because they cannot describe how an object should be created. Framework features that use object pools provide a `pool.fingerprint` setting when you need to declare this value yourself.

Resolve `Hypervel\ObjectPool\Contracts\Factory` from the container and call `getOrCreate()`:

```php
use Hypervel\ObjectPool\Contracts\Factory;

$pool = app(Factory::class)->getOrCreate(
    $definition,
    fn () => new ServiceClient($clientConfig),
);
```

If the identity already exists, the resource type, fingerprint, and normalized options must all match. Matching definitions return the existing pool and ignore the new callback. A mismatch throws an exception instead of sharing objects created with different credentials or settings. Therefore, every value captured by the callback should come from the configuration used to build the fingerprint.

Managed pools do not accept a destruction callback because they may be removed and recreated without the original caller. If an object requires custom cleanup, use a standalone pool whose owner controls its complete lifecycle.

<a name="standalone-pools"></a>
### Standalone Pools

Sometimes you may want one service to own a pool directly instead of registering it with the pool manager. You may create a `SimpleObjectPool` using an object factory, normalized pool options, and an optional callback that destroys an object:

```php
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;

$pool = new SimpleObjectPool(
    callback: fn () => new ReportsClient,
    options: PoolOptions::fromArray([
        'max_objects' => 20,
    ]),
    destroyCallback: fn (ReportsClient $client) => $client->close(),
);
```

The factory must return a new object each time it is called. It should also capture only values that remain valid for the lifetime of the pool. The destruction callback runs when an object is discarded, expires, or is destroyed during pool closure. Exceptions from this callback are reported and do not prevent the remaining cleanup.

When the owning service is stopped, call the pool's `close()` method to destroy its idle objects and ensure that borrowed objects are destroyed when returned.

<a name="object-pool-options"></a>
### Object Pool Options

| Option | Default | Purpose |
|---|---:|---|
| `min_retained_objects` | `1` | Idle-trimming floor. Objects are not created eagerly or replenished to this value. |
| `max_objects` | `10` | Maximum number of managed objects, including checked-out objects and creation slots. |
| `wait_timeout` | `3.0` | Maximum seconds a coroutine waits for an object or newly freed creation capacity. |
| `max_lifetime` | `60.0` | Absolute object lifetime in seconds; `0` disables it. Expiry ignores the retention floor. |
| `max_idle_time` | `0.0` | Individual idle-object lifetime in seconds; `0` disables it. |
| `idle_ttl` | `300.0` | Managed-pool idle lifetime in seconds; explicit `null` disables pool eviction. |

Counts must be integers and durations must be finite integers or floats. Unknown option keys are rejected so misspellings cannot silently select defaults. Standalone pools are not registered with the recycler, so their `idle_ttl` is only used when their owner calls `isIdle()`.

<a name="borrowing-objects"></a>
### Borrowing Objects

For synchronous work, borrow with `get()` and make sure the object is either released or discarded:

```php
$client = $pool->get();

try {
    $result = $client->execute($command);
} catch (Throwable $exception) {
    $pool->discard($client);

    throw $exception;
}

$pool->release($client);
```

Use `release()` only when the object remains healthy. Use `discard()` after a protocol or network failure, or when a failed reset may have left it in an unknown state. The pool rejects foreign objects, repeated releases or discards, and factories that return an object the pool already manages.

<a name="leases"></a>
### Leases

Use a `Lease` when a stream, job, response callback, or other deferred result must retain the borrowed object beyond the current method call:

```php
use Hypervel\ObjectPool\Lease;

$lease = new Lease($pool, $pool->get());
$client = $lease->get();

try {
    $result = $client->beginDeferredOperation();
} catch (Throwable $exception) {
    $lease->discard();

    throw $exception;
}

// Transfer $lease alongside $result, then release or discard it when the
// deferred operation finishes. An abandoned lease releases on destruction.
```

Leases finalize exactly once. An optional release callback may reset an object before it is returned; if that callback throws, the lease discards the object and propagates the reset failure.

<a name="object-pool-lifecycle"></a>
### Object Pool Lifecycle

`ObjectPool::close()` permanently closes the pool. It rejects future checkouts, wakes waiting borrowers, destroys every idle object, and destroys borrowed objects when they are eventually returned. `PoolManager::remove($identity)` removes one registry entry before closing its pool, so another operation may create a fresh pool without waiting for old borrows to finish. `PoolManager::flush()` removes and closes every pool and should only be called during worker boot or tests.

After a worker starts, `PoolRecycler` regularly removes expired idle objects and pools. A pool is not removed while an object is borrowed or another coroutine is acquiring one. Pool maintenance does not make an inactive pool appear active.

Close pools while the worker runtime is active. Application shutdown and garbage collection are not substitutes for `remove()`, `flush()`, or a framework manager's `purge()` method.

<a name="consumer-integration"></a>
### Consumer Integration

Hypervel does not provide a generic magic proxy for object pools. A proxy cannot know whether a result is complete or is a lazy stream, iterator, promise, or another object that still needs the borrowed resource. Consumer proxies should list their synchronous methods and use the protected `PoolProxy::invoke()` method. Deferred methods should keep a `Lease` until their work is finished.

Framework managers for filesystems, mail, and queues build definitions from the actual construction input, expose normalized `pool` configuration, and distinguish cache-only forgetting from pool-invalidating purge operations. Broadcasting does the same only for drivers explicitly marked as poolable. Prefer those manager APIs when using a framework resource instead of creating definitions directly.

<a name="connection-pools"></a>
## Connection Pools

The `Hypervel\Pool` component provides the lower-level foundation used by Hypervel's database and Redis connection pools. It is also available to package authors who need to manage another connection type.

<a name="defining-a-connection-pool"></a>
### Defining a Connection Pool

To define a connection pool, extend the `Pool` class and implement its `createConnection` method. Each connection must implement `ConnectionInterface`. You may extend the base `Connection` class when its release handling and idle-time checks fit your protocol:

```php
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Connection;
use Hypervel\Pool\Pool;

class ServicePool extends Pool
{
    protected function createConnection(): ConnectionInterface
    {
        return $this->container->make(ServiceConnection::class, [
            'pool' => $this,
        ]);
    }
}

class ServiceConnection extends Connection
{
    protected ServiceClient $connection;

    public function __construct(
        Container $container,
        PoolInterface $pool,
        protected ServiceClientFactory $clientFactory,
    ) {
        parent::__construct($container, $pool);
    }

    public function getActiveConnection(): ServiceClient
    {
        if (! $this->check()) {
            $this->reconnect();
        }

        $this->lastUseTime = hrtime(true) / 1e9;

        return $this->connection;
    }

    public function reconnect(): bool
    {
        $this->close();

        $this->connection = $this->clientFactory->connect(
            timeout: $this->pool->getOption()->getConnectTimeout(),
        );
        $this->lastUseTime = hrtime(true) / 1e9;
        $this->markValid();

        return true;
    }

    public function check(): bool
    {
        return isset($this->connection)
            && parent::check()
            && $this->connection->isConnected();
    }

    public function close(): bool
    {
        if (isset($this->connection)) {
            $connection = $this->connection;
            unset($this->connection);

            $connection->close();
        }

        return true;
    }
}
```

The connection class is responsible for translating connection options into the underlying client and implementing any protocol-specific health check, reconnect, heartbeat, lifetime, and close behavior it needs. Hypervel's database and Redis connections provide useful examples of complete integrations.

If a protocol needs to keep one socket alive with a periodic heartbeat, you may extend `KeepaliveConnection`. This connection type exposes a `call()` method for working with its socket and does not allow direct `getConnection()` access. Your subclass should create the socket through `getActiveConnection()` and may override `heartbeat()` and `sendClose()` for the protocol.

Connection pools are worker-lifetime services. A package should keep them in a manager that returns the current pool for each operation instead of retaining a borrowed connection or a pool that has been removed.

<a name="borrowing-connections"></a>
### Borrowing Connections

The `get` method borrows one connection from the pool. Always release a healthy connection after the operation completes. If a network or protocol failure may have left the connection in an unknown state, discard it instead:

```php
$connection = $pool->get();

try {
    $response = $connection->getConnection()->send($request);
} catch (Throwable $exception) {
    $connection->discard();

    throw $exception;
}

$connection->release();
```

The pool rejects foreign connections, repeated releases or discards, and connection factories that return the same connection object more than once.

<a name="connection-pool-options"></a>
### Connection Pool Options

Connection pool options are passed to the pool constructor as an array:

```php
$pool = app()->make(ServicePool::class, [
    'name' => 'reports',
    'config' => [
        'min_connections' => 1,
        'max_connections' => 10,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => -1.0,
        'heartbeat_timeout' => 1.0,
        'max_idle_time' => 60.0,
        'max_lifetime' => -1.0,
        'events' => [],
    ],
]);
```

| Option | Default | Purpose |
|---|---:|---|
| `min_connections` | `1` | Managed-connection floor used when `flush()` trims excess idle connections. Connections are not created eagerly or automatically replenished to this value. |
| `max_connections` | `10` | Maximum number of managed connections, including borrowed connections and connections being created. |
| `connect_timeout` | `10.0` | Maximum seconds allowed to establish a connection. The connection implementation must apply this value to its client. |
| `wait_timeout` | `3.0` | Maximum seconds a borrower waits for an idle connection or newly freed creation capacity. |
| `heartbeat` | `-1.0` | Heartbeat interval in seconds; `-1` disables it. The connection or pool implementation must schedule the heartbeat. |
| `heartbeat_timeout` | `1.0` | Maximum seconds allowed for a heartbeat check. The heartbeat implementation must apply this value. |
| `max_idle_time` | `60.0` | Maximum idle time in seconds. The base `Connection` class applies this value in its `check()` method. |
| `max_lifetime` | `-1.0` | Maximum connection lifetime in seconds; `-1` disables it. The connection implementation must enforce this limit. |
| `events` | `[]` | Connection lifecycle event class names. The base `Connection` class dispatches `ReleaseConnection` when it is included. |

The base pool enforces `max_connections` and `wait_timeout`, and uses `min_connections` when `flush()` trims idle connections. The remaining connection-specific options are provided to connection and pool implementations; they do not add protocol behavior by themselves. Unknown option keys are rejected.

<a name="connection-pool-lifecycle"></a>
### Connection Pool Lifecycle

Connection pools create connections lazily as callers need them, up to `max_connections`, so the first caller that needs a new connection pays the cost of opening it. The `min_connections` option controls how far `flush()` may reduce the total managed connection count while trimming idle connections. It does not prewarm the pool or guarantee a minimum number of idle or managed connections. A pool may have no idle connections while they are borrowed, and unhealthy, expired, discarded, or failed connections may leave the managed count below this value.

The `close()` method is terminal and may be called more than once. It destroys idle connections immediately, rejects new borrows, and destroys connections that were already borrowed when their owners return them.

Packages that cache connection pools must close them before the server forks and before each worker starts so a child process never inherits an open connection. Remove the cached pool from its manager before calling `close()`. Closing may yield while resources are released, and another coroutine must be able to resolve a fresh pool instead of receiving the pool being closed.

> [!WARNING]
> Do not call native channel methods from a destructor. Close connection pools explicitly while the worker runtime is active.
