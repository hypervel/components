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
    - [Consumer Integration Examples](#consumer-integration-examples)
- [Connection Pools](#connection-pools)
    - [Defining a Connection Pool](#defining-a-connection-pool)
    - [Borrowing Connections](#borrowing-connections)
    - [Connection Pool Options](#connection-pool-options)
    - [Connection Pool Lifecycle](#connection-pool-lifecycle)
    - [Usage-Based Trimming](#usage-based-trimming)
    - [Periodic Idle Checks](#periodic-idle-checks)
- [Credits](#credits)

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

To create an object pool, resolve `Hypervel\Contracts\ObjectPool\Factory` from the container and invoke its `pool` method. This method accepts a pool name, a closure that creates an object, and any options you wish to customize:

```php
use Hypervel\Contracts\ObjectPool\Factory;

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

If pool names contain dynamic values, review the [OpenTelemetry pool metric guidance](/docs/{{version}}/opentelemetry#runtime-pool-metrics) before exporting them as metric attributes.

Call `pool()` immediately before each borrow instead of keeping the returned pool in a long-lived property. An idle managed pool may be removed and closed between operations.

If the callback depends on credentials or other values that may change, use a pool definition and build its fingerprint from those values.

<a name="pool-definitions"></a>
### Pool Definitions

If your objects depend on credentials or other configuration that may change, you may register a `PoolDefinition`. A definition describes which objects can safely share a pool:

- `identity` is the unique registry key for the pool.
- `resourceType` identifies the kind of object, such as a service client.
- `fingerprint` identifies the configuration used to create the object.
- `options` contains the pool's limits and timeouts.

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

Resolve `Hypervel\Contracts\ObjectPool\Factory` from the container and call `getOrCreate()`:

```php
use Hypervel\Contracts\ObjectPool\Factory;

$pool = app(Factory::class)->getOrCreate(
    $definition,
    fn () => new ServiceClient($clientConfig),
);
```

If a pool with the same identity already exists, Hypervel returns it when the resource type, fingerprint, and options match. Otherwise, an exception is thrown. Include every value that affects the object in its fingerprint so objects created with different credentials or settings cannot accidentally share a pool.

Managed pools do not accept a destruction callback because they may be removed and recreated without the original caller. If an object requires custom cleanup, use a standalone pool whose owner controls its complete lifecycle.

<a name="standalone-pools"></a>
### Standalone Pools

Sometimes you may want one service to own a pool directly instead of registering it with the pool manager. You may create a `CallbackObjectPool` with a closure that creates objects and an optional closure that closes them:

```php
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\CallbackObjectPool;

$pool = new CallbackObjectPool(
    createCallback: fn () => new ReportsClient,
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
| `max_objects` | `10` | Maximum pool capacity, including borrowed objects and reserved creation slots. |
| `wait_timeout` | `3.0` | Maximum seconds a coroutine waits for an object or newly freed creation capacity. |
| `max_lifetime` | `60.0` | Absolute object lifetime in seconds; `null` disables it. Expiry ignores the retention floor. |
| `max_idle_time` | `null` | Individual idle-object lifetime in seconds; `null` disables it. |
| `pool_idle_timeout` | `300.0` | Whole-pool idle timeout in seconds; `null` disables pool eviction. |

You may customize these options when creating the pool. Counts must be integers, and timeouts must be finite positive numbers of seconds. Use null to disable an optional timeout. Options cannot be changed after the pool is created, and unknown option names throw an exception.

Standalone pools are not registered with the recycler. Their owner may use `isIdleExpired()` to check whether `pool_idle_timeout` has elapsed.

<a name="borrowing-objects"></a>
### Borrowing Objects

For synchronous work, call `borrow()` and make sure the object is either released or discarded:

```php
$client = $pool->borrow();

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

$lease = new Lease($pool, $pool->borrow());
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

Use `getManagedCount()`, `getBorrowedCount()`, `getIdleCount()`, and `getWaitingCount()` to inspect a pool without changing it. The `getStats()` method returns these values under the keys `managed`, `borrowed`, `idle`, and `waiting`, together with `closed`.

Managed objects include those undergoing cleanup, but exclude objects whose creation has not finished. Therefore, the managed count can temporarily exceed borrowed plus idle while cleanup is in progress. Capacity limits also account for reserved creation slots.

<a name="object-pool-lifecycle"></a>
### Object Pool Lifecycle

To close a pool, invoke its `close` method. Idle objects are destroyed immediately, and borrowed objects are destroyed when they are returned. Waiting borrowers are woken, and further attempts to borrow an object throw an exception. A closed pool cannot be reopened.

You may use the manager's `purge` method to remove and close a registered pool. Hypervel removes the pool before closing it, allowing another operation to create a replacement while existing borrowers finish. If you wish to purge only a particular instance, pass that pool as the second argument:

```php
$manager->purge($identity, $pool);
```

The `purgeAll` method removes and closes every registered pool. Call it only during worker boot or tests.

Use `getPools()` to inspect registered pools and `getDefinition($identity)` to inspect a registered definition. `get($identity)` requires an existing pool and throws if none is registered. `has($identity)` checks current membership; another coroutine may purge the pool if your code yields before retrieving it.

After a worker starts, `PoolRecycler` regularly removes expired idle objects and pools. A pool is not removed while an object is borrowed or another coroutine is acquiring one. Pool maintenance does not make an inactive pool appear active.

To change the maintenance interval, bind the recycler during service provider registration:

```php
use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\ObjectPool\PoolRecycler;

$this->app->singleton(PoolRecycler::class, fn ($app) => new PoolRecycler(
    $app->make(Factory::class),
    interval: 5.0,
));
```

The interval must be a finite positive number of seconds. The recycler creates its own timer; you may supply a `Hypervel\Coordinator\Timer` through its `timer` constructor argument. Custom recyclers implement `Hypervel\Contracts\ObjectPool\Recycler`, which requires `start()` and `stop()`.

Close pools while the worker runtime is active. Application shutdown and garbage collection are not substitutes for `purge()`, `purgeAll()`, or a framework manager's `purge()` method.

<a name="consumer-integration-examples"></a>
### Consumer Integration Examples

These examples show how framework consumers use the shared pooling APIs and how custom integrations can follow the same patterns.

Hypervel does not provide a generic magic proxy for object pools. A proxy cannot know whether a result is complete or is a lazy stream, iterator, promise, or another object that still needs the borrowed resource. Consumer proxies should list their synchronous methods and use the protected `PoolProxy::invoke()` method. Deferred methods should keep a `Lease` until their work is finished.

Framework managers for filesystems, mail, and queues build definitions from the actual construction input, expose normalized `pool` configuration, and distinguish cache-only forgetting from pool-invalidating purge operations. Broadcasting does the same only for drivers explicitly marked as poolable. Prefer those manager APIs when using a framework resource instead of creating definitions directly.

For example, a custom broadcasting driver can opt into pooling during service provider boot, before it is resolved:

```php
use Hypervel\Broadcasting\BroadcastManager;

public function boot(BroadcastManager $broadcasts): void
{
    $broadcasts->addPoolableDriver('custom');
}
```

The filesystem, mail, queue, and broadcasting managers also expose `getPoolableDrivers()`, `removePoolableDriver($driver)`, and `setPoolableDrivers($drivers)`. Configure the list during worker boot; changing it does not replace drivers that have already been resolved.

Filesystem client pools and whole-driver pools use different construction input. S3 and Google Cloud Storage pools contain only the SDK client, so the logical disk name does not affect their fingerprint. Whole-driver pools contain the complete disk, so their fingerprints include the complete normalized disk configuration, the nullable logical name, and any serving-route owner or prefix that changes the constructed adapter.

If two custom whole-driver disks may safely share a pool despite having different names, configure the same `pool.fingerprint` for both disks. You may also configure the same `pool.name` when you want to choose the shared identity, but the fingerprint must still match. Never declare matching fingerprints unless every construction detail is equivalent, including serving-route behavior.

On a pooled proxy, `getPoolName()` returns the fully qualified registry name. The manager adds its namespace to a configured `pool.name`, or generates a name from the resource type and fingerprint when no name is configured. Use the returned name when looking up that pool in the registry.

<a name="connection-pools"></a>
## Connection Pools

The `Hypervel\ConnectionPool` component provides the lower-level foundation used by Hypervel's database and Redis connection pools. It is also available to package authors who need to manage another connection type.

Use `getManagedCount()`, `getBorrowedCount()`, `getIdleCount()`, and `getWaitingCount()` to inspect a pool without borrowing a connection. The `getStats()` method returns these counts under the `managed`, `borrowed`, `idle`, and `waiting` keys, together with a `closed` flag.

The managed count includes connections being checked or destroyed, but excludes connections still being created. During cleanup, it may therefore exceed the borrowed and idle counts combined.

Database and Redis each provide a `Pool\PoolManager`. Use `pool($name)` to resolve a named pool or `getPools()` to inspect existing pools without creating one:

```php
use Hypervel\Database\Pool\PoolManager;

$manager = app(PoolManager::class);
$pool = $manager->pool('mysql');

$stats = $pool->getStats();
```

During worker boot or tests, `purge($name)` removes and closes one pool, while `purgeAll()` removes all pools. The database manager also offers `purgeForConnection($name)` to remove a connection's read and write pools together. Existing borrowers may finish; their connections are destroyed when returned.

Hypervel's database pool owns borrowing, deadlines, heartbeat cancellation, and idle connection recycling. Each database connection owns its protocol-specific health check, reconnection, cleanup, and reuse rules. Therefore, PDO, native, and HTTP database drivers can use the same pool without exposing their underlying client to the pool component.

<a name="defining-a-connection-pool"></a>
### Defining a Connection Pool

To define a connection pool, extend the `ConnectionPool` class and implement its `createConnection` method. Each connection must implement the `Hypervel\Contracts\ConnectionPool\Connection` contract. You may extend the base `Connection` class when its release handling and idle-time checks fit your protocol:

```php
use Hypervel\ConnectionPool\Connection;
use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\ConnectionPool\ConnectionPool as ConnectionPoolContract;
use Hypervel\Contracts\Container\Container;

class ServicePool extends ConnectionPool
{
    protected function createConnection(): PoolConnection
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
        ConnectionPoolContract $pool,
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
            timeout: $this->pool->getOptions()->connectTimeout,
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

If a connection is closed or replaced while a call is running, the call's socket is dropped when it finishes instead of being returned for reuse. This cleanup does not send a protocol close message. Return a socket resource or client whose release or destructor closes the underlying connection.

Connection pools are worker-lifetime services. A package should keep them in a manager that returns the current pool for each operation instead of retaining a borrowed connection or a pool that has been removed.

<a name="borrowing-connections"></a>
### Borrowing Connections

The `borrow` method borrows one connection from the pool. Always release a healthy connection after the operation completes. If a network or protocol failure may have left the connection in an unknown state, discard it instead:

```php
$connection = $pool->borrow();

try {
    $response = $connection->getConnection()->send($request);
} catch (Throwable $exception) {
    $connection->discard();

    throw $exception;
}

$connection->release();
```

The pool rejects foreign connections, repeated releases or discards, and connection factories that return the same connection object more than once.

If capacity remains unavailable for `wait_timeout` seconds, borrowing throws `Hypervel\ConnectionPool\Exceptions\PoolExhaustedException`. Borrowing from a closed pool throws `Hypervel\ConnectionPool\Exceptions\PoolClosedException`.

<a name="connection-pool-options"></a>
### Connection Pool Options

Connection pool options are passed to the pool constructor as an array:

```php
$pool = app()->make(ServicePool::class, [
    'name' => 'reports',
    'config' => [
        'min_retained_connections' => 1,
        'max_connections' => 10,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat_interval' => null,
        'heartbeat_timeout' => 1.0,
        'idle_check_interval' => null,
        'max_idle_time' => 60.0,
        'max_lifetime' => null,
        'events' => [],
    ],
]);

$pool->start();
```

| Option | Default | Purpose |
|---|---:|---|
| `min_retained_connections` | `1` | Managed-connection floor used when trimming excess idle connections. Connections are not created eagerly or automatically replenished to this value. |
| `max_connections` | `10` | Maximum pool capacity, including managed connections and reserved creation slots. |
| `connect_timeout` | `10.0` | Maximum seconds allowed to establish a connection. The connection implementation must apply this value to its client. |
| `wait_timeout` | `3.0` | Maximum seconds a borrower waits for an idle connection or newly freed creation capacity. |
| `heartbeat_interval` | `null` | Heartbeat interval in seconds; null disables it. The connection or pool implementation must schedule the heartbeat. |
| `heartbeat_timeout` | `1.0` | Maximum seconds allowed for a heartbeat check. The heartbeat implementation must apply this value. |
| `idle_check_interval` | `null` | Interval between generic idle-connection checks; null disables them. |
| `max_idle_time` | `60.0` | Maximum idle time in seconds; null disables expiry. The base `Connection` class applies this value in its `check()` method. |
| `max_lifetime` | `null` | Maximum connection lifetime in seconds; null disables it. The connection implementation must enforce this limit. |
| `events` | `[]` | Connection lifecycle event class names. Include `ConnectionReleasing` to dispatch it before a connection returns to the pool. |

You may inspect a pool's options using the `getOptions` method:

```php
$options = $pool->getOptions();

$options->maxConnections;
$options->waitTimeout;
```

Options cannot be changed after the pool is created. Omitted options use the defaults above. Timeouts must be finite positive numbers of seconds; use null to disable an optional timeout. Invalid counts, unknown option names, and event classes that do not exist throw an exception.

The base pool enforces `max_connections` and `wait_timeout`, and uses `min_retained_connections` when trimming idle connections. Protocol-specific options are provided to connection and pool implementations; they do not add protocol behavior by themselves.

<a name="connection-pool-lifecycle"></a>
### Connection Pool Lifecycle

Connections are created when first needed, up to `max_connections`. The retained minimum controls trimming; it does not prewarm the pool or replenish discarded connections.

Database and Redis managers call `start()` after pool initialization succeeds. When constructing a pool yourself, call `start()` to enable its configured background maintenance. You may borrow and release connections before starting it. Repeated calls do not create duplicate timers.

When customizing pool resolution, ensure the container returns an open pool; throw an exception if initialization fails.

The `close()` method is terminal and may be called more than once. It destroys idle connections immediately, rejects new borrows, and destroys connections that were already borrowed when their owners return them.

To shrink an open pool, call `trimExcessIdle()`. This closes idle connections while the managed count exceeds `min_retained_connections`, regardless of their age. Borrowed connections remain available to their owners.

Close cached pools before forking or starting a worker to avoid inheriting open connections. Remove each pool from its manager before closing it, allowing concurrent callers to resolve a replacement while cleanup finishes.

> [!WARNING]
> Do not call native channel methods from a destructor. Close connection pools explicitly while the worker runtime is active.

<a name="usage-based-trimming"></a>
### Usage-Based Trimming

Database and Redis pools trim excess idle connections when usage drops below five borrows per sampled second. Their `BorrowRateTracker` samples 10 seconds, with a cooldown of more than 60 seconds. Both periods begin with the first successful borrow.

Custom pools may enable this behavior by overriding `createUsageTracker`:

```php
use Hypervel\ConnectionPool\BorrowRateTracker;
use Hypervel\Contracts\ConnectionPool\UsageTracker;

protected function createUsageTracker(): ?UsageTracker
{
    return new BorrowRateTracker;
}
```

The factory runs on the first successful borrow, after pool construction. Return a fresh tracker or null to disable trimming. Its result is cached. The factory must construct the policy without borrowing connections or performing I/O.

To customize the policy, extend `BorrowRateTracker` and set its protected `$window`, `$threshold`, and `$cooldown` properties. The window and cooldown use seconds. Read the rate with `getBorrowRate()`, or implement `UsageTracker` with your own `recordBorrow()` and `shouldTrimExcessIdle()` methods.

<a name="periodic-idle-checks"></a>
### Periodic Idle Checks

Set `idle_check_interval` to check idle connections even when no new connections are being borrowed:

```php
'pool' => [
    'idle_check_interval' => 5.0,
],
```

After `start()`, an empty pool schedules its `IdleConnectionMonitor` on the first successful borrow. A pool that already holds connections schedules it immediately. The monitor stops on pool closure or worker exit; null disables it. Each tick checks one idle connection in FIFO order. Unhealthy or expired connections are discarded, even below the retained minimum; checks do not refresh activity.

A full pass takes roughly one interval per idle connection, plus check time. The interval is not an expiry deadline.

The monitor calls `check()`, which must work without a request or operation context. Database and Redis heartbeats are separate; enabling both can cause overlapping checks.

For standalone use, construct `IdleConnectionMonitor($pool, $interval)` and call `start()` and `stop()`. It owns a fresh timer unless you supply a `Hypervel\Coordinator\Timer` as the third argument.

<a name="credits"></a>
## Credits

Hypervel's connection pool began as a port of [Hyperf Pool](https://github.com/hyperf/hyperf/tree/master/src/pool). It is maintained independently for Hypervel's APIs and coroutine runtime.
