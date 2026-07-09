# Redis

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Configuring the Connection Scheme](#configuring-the-connection-scheme)
    - [PhpRedis](#phpredis)
    - [Retry and Backoff Configuration](#retry-and-backoff-configuration)
    - [Unix Socket Connections](#unix-socket-connections)
    - [PhpRedis Serialization and Compression](#phpredis-serialization)
    - [Clusters](#clusters)
    - [Sentinel](#sentinel)
    - [Connection Pooling](#connection-pooling)
- [Interacting With Redis](#interacting-with-redis)
    - [Using Multiple Redis Connections](#using-multiple-redis-connections)
    - [Holding a Pooled Connection](#holding-a-pooled-connection)
    - [Pinned Connections](#pinned-connections)
    - [Checking Cluster Connections](#checking-cluster-connections)
    - [Concurrency Limiting](#concurrency-limiting)
    - [Rate Limiting](#rate-limiting)
    - [Deleting Keys by Pattern](#deleting-keys-by-pattern)
    - [Transactions](#transactions)
    - [Pipelining Commands](#pipelining-commands)
    - [Advanced Helpers](#advanced-helpers)
- [Pub / Sub](#pubsub)
    - [Wildcard Subscriptions](#wildcard-subscriptions)
    - [Using the Subscriber](#using-the-subscriber)

<a name="introduction"></a>
## Introduction

[Redis](https://redis.io) is an open source, advanced key-value store. It is often referred to as a data structure server since keys can contain [strings](https://redis.io/docs/latest/develop/data-types/strings/), [hashes](https://redis.io/docs/latest/develop/data-types/hashes/), [lists](https://redis.io/docs/latest/develop/data-types/lists/), [sets](https://redis.io/docs/latest/develop/data-types/sets/), and [sorted sets](https://redis.io/docs/latest/develop/data-types/sorted-sets/).

Before using Redis with Hypervel, you should install and enable the [PhpRedis](https://github.com/phpredis/phpredis) PHP extension. Hypervel's Redis integration is built on PhpRedis and uses pooled connections so Redis commands may be executed efficiently across Swoole coroutines.

<a name="configuration"></a>
## Configuration

You may configure your application's Redis settings via the `config/database.php` configuration file. Within this file, you will see a `redis` array containing the Redis servers utilized by your application:

```php
'redis' => [
    'options' => [
        'prefix' => env('REDIS_PREFIX', app_id() . ':'),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', 'localhost'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => (int) env('REDIS_DB', 0),
        'max_retries' => (int) env('REDIS_MAX_RETRIES', 3),
        'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
        'backoff_base' => (int) env('REDIS_BACKOFF_BASE', 100),
        'backoff_cap' => (int) env('REDIS_BACKOFF_CAP', 1000),
        'pool' => [
            'min_connections' => (int) env('REDIS_MIN_CONNECTIONS', 1),
            'max_connections' => (int) env('REDIS_MAX_CONNECTIONS', 10),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => (float) env('REDIS_HEARTBEAT', -1),
            'heartbeat_timeout' => (float) env('REDIS_HEARTBEAT_TIMEOUT', 1.0),
            'max_idle_time' => (float) env('REDIS_MAX_IDLE_TIME', 60),
            'max_lifetime' => (float) env('REDIS_MAX_LIFETIME', -1),
        ],
    ],

    'cache' => [
        'url' => env('REDIS_CACHE_URL', env('REDIS_URL')),
        'host' => env('REDIS_CACHE_HOST', env('REDIS_HOST', 'localhost')),
        'username' => env('REDIS_CACHE_USERNAME', env('REDIS_USERNAME')),
        'password' => env('REDIS_CACHE_PASSWORD', env('REDIS_PASSWORD')),
        'port' => (int) env('REDIS_CACHE_PORT', env('REDIS_PORT', 6379)),
        'database' => (int) env('REDIS_CACHE_DB', env('REDIS_DB', 0)),
        'max_retries' => (int) env('REDIS_CACHE_MAX_RETRIES', env('REDIS_MAX_RETRIES', 3)),
        'backoff_algorithm' => env('REDIS_CACHE_BACKOFF_ALGORITHM', env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter')),
        'backoff_base' => (int) env('REDIS_CACHE_BACKOFF_BASE', env('REDIS_BACKOFF_BASE', 100)),
        'backoff_cap' => (int) env('REDIS_CACHE_BACKOFF_CAP', env('REDIS_BACKOFF_CAP', 1000)),
        'pool' => [
            'min_connections' => (int) env('REDIS_CACHE_MIN_CONNECTIONS', env('REDIS_MIN_CONNECTIONS', 1)),
            'max_connections' => (int) env('REDIS_CACHE_MAX_CONNECTIONS', env('REDIS_MAX_CONNECTIONS', 10)),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => (float) env('REDIS_CACHE_HEARTBEAT', env('REDIS_HEARTBEAT', -1)),
            'heartbeat_timeout' => (float) env('REDIS_CACHE_HEARTBEAT_TIMEOUT', env('REDIS_HEARTBEAT_TIMEOUT', 1.0)),
            'max_idle_time' => (float) env('REDIS_CACHE_MAX_IDLE_TIME', env('REDIS_MAX_IDLE_TIME', 60)),
            'max_lifetime' => (float) env('REDIS_CACHE_MAX_LIFETIME', env('REDIS_MAX_LIFETIME', -1)),
        ],
    ],
],
```

Hypervel's default configuration also includes `session`, `queue`, and `reverb` Redis connections. Each connection follows the same shape as the `default` and `cache` connections shown above.

Each standalone Redis server defined in your configuration file is required to have a name, host, and port unless you define a single URL to represent the Redis connection:

```php
'redis' => [
    'options' => [
        'prefix' => env('REDIS_PREFIX', app_id() . ':'),
    ],

    'default' => [
        'url' => 'tcp://127.0.0.1:6379?database=0',
    ],

    'cache' => [
        'url' => 'tls://user:password@127.0.0.1:6380?database=0',
    ],
],
```

<a name="configuring-the-connection-scheme"></a>
#### Configuring the Connection Scheme

By default, Redis connections will use the `tcp` scheme when connecting to your Redis servers. However, you may use TLS / SSL encryption by specifying a `scheme` configuration option in your Redis server's configuration array:

```php
'default' => [
    'scheme' => 'tls',
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', 'localhost'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => (int) env('REDIS_PORT', 6379),
    'database' => (int) env('REDIS_DB', 0),
],
```

<a name="phpredis"></a>
### PhpRedis

Hypervel communicates with Redis using the PhpRedis extension. In addition to the default configuration options, Hypervel supports the following connection parameters: `url`, `scheme`, `host`, `username`, `password`, `port`, `database`, `timeout`, `retry_interval`, `read_timeout`, `context`, `max_retries`, `backoff_algorithm`, `backoff_base`, and `backoff_cap`.

```php
'default' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', 'localhost'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => (int) env('REDIS_PORT', 6379),
    'database' => (int) env('REDIS_DB', 0),
    'timeout' => 5.0,
    'retry_interval' => 0,
    'read_timeout' => 60,
    'context' => [
        // 'stream' => ['verify_peer' => false],
    ],
],
```

The `read_timeout` value is applied both when the Redis socket is opened and as the PhpRedis `Redis::OPT_READ_TIMEOUT` option. If you need to configure PhpRedis options such as a serializer, compression, or key prefix, add them to the `options` array.

<a name="retry-and-backoff-configuration"></a>
#### Retry and Backoff Configuration

The `max_retries`, `backoff_algorithm`, `backoff_base`, and `backoff_cap` options may be used to configure how PhpRedis backs off between retry attempts. The following backoff algorithms are supported: `default`, `decorrelated_jitter`, `equal_jitter`, `exponential`, `uniform`, and `constant`:

```php
'default' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', 'localhost'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => (int) env('REDIS_PORT', 6379),
    'database' => (int) env('REDIS_DB', 0),
    'max_retries' => (int) env('REDIS_MAX_RETRIES', 3),
    'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
    'backoff_base' => (int) env('REDIS_BACKOFF_BASE', 100),
    'backoff_cap' => (int) env('REDIS_BACKOFF_CAP', 1000),
],
```

Hypervel also reconnects and retries a failed Redis command once when PhpRedis throws a `RedisException` from a pooled connection.

<a name="unix-socket-connections"></a>
#### Unix Socket Connections

Redis connections can also be configured to use Unix sockets instead of TCP. This can offer improved performance by eliminating TCP overhead for connections to Redis instances on the same server as your application. To configure Redis to use a Unix socket, set your `REDIS_HOST` environment variable to the path of the Redis socket and the `REDIS_PORT` environment variable to `0`:

```env
REDIS_HOST=/run/redis/redis.sock
REDIS_PORT=0
```

<a name="phpredis-serialization"></a>
#### PhpRedis Serialization and Compression

The PhpRedis extension may also be configured to use a variety of serializers and compression algorithms. These algorithms can be configured via the `options` array of your Redis configuration:

```php
'redis' => [
    'options' => [
        'prefix' => env('REDIS_PREFIX', app_id() . ':'),
        'serializer' => Redis::SERIALIZER_MSGPACK,
        'compression' => Redis::COMPRESSION_LZ4,
    ],

    // ...
],
```

Currently supported serializers include: `Redis::SERIALIZER_NONE` (default), `Redis::SERIALIZER_PHP`, `Redis::SERIALIZER_JSON`, `Redis::SERIALIZER_IGBINARY`, and `Redis::SERIALIZER_MSGPACK`.

Supported compression algorithms include: `Redis::COMPRESSION_NONE` (default), `Redis::COMPRESSION_LZF`, `Redis::COMPRESSION_ZSTD`, and `Redis::COMPRESSION_LZ4`.

<a name="clusters"></a>
### Clusters

If your application is utilizing Redis Cluster, you should define a `cluster` array on the Redis connection. Hypervel uses PhpRedis' native Redis Cluster client:

```php
'default' => [
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'timeout' => 5.0,
    'cluster' => [
        'enable' => true,
        'name' => env('REDIS_CLUSTER_NAME', 'mycluster'),
        'seeds' => explode(',', env('REDIS_CLUSTER_SEEDS', '127.0.0.1:6379')),
        'read_timeout' => 5.0,
        'persistent' => false,
        'context' => [],
    ],
],
```

The `seeds` option should contain one or more `host:port` entries for nodes in the cluster. Redis Cluster does not support selecting logical databases, so the `database` option is ignored for clustered connections.

Hypervel automatically hash-tags Redis queue storage keys and Redis funnel slot keys when a clustered connection is used and the configured queue or funnel name does not already contain a valid Redis Cluster hash tag. This keeps all keys used by Hypervel's multi-key Lua scripts on the same hash slot. You may still provide your own hash tag, such as `{orders}:high`, when you need to control key placement.

<a name="sentinel"></a>
### Sentinel

Redis Sentinel provides high availability for Redis by monitoring your Redis master and replica instances and reporting the current master node. To configure Redis Sentinel in your Hypervel application, add a `sentinel` array to the Redis connection:

```php
'default' => [
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'database' => (int) env('REDIS_DB', 0),
    'timeout' => 5.0,
    'retry_interval' => 0,
    'sentinel' => [
        'enable' => true,
        'master_name' => env('REDIS_SENTINEL_MASTER', 'mymaster'),
        'nodes' => explode(',', env('REDIS_SENTINEL_NODES', '127.0.0.1:26379')),
        'persistent' => '',
        'read_timeout' => 5.0,
        'auth' => env('REDIS_SENTINEL_PASSWORD'),
    ],
],
```

When Sentinel is enabled, Hypervel asks Sentinel for the current master address and then connects to that Redis master. The `auth` value in the `sentinel` array is used to authenticate with Sentinel itself; Redis authentication still uses the connection's top-level `username` and `password` values.

<a name="connection-pooling"></a>
### Connection Pooling

Hypervel pools Redis connections so commands can reuse established sockets across coroutines instead of opening a new connection for every command. Each Redis connection may define a `pool` array:

```php
'default' => [
    // ...

    'pool' => [
        'min_connections' => 1,
        'max_connections' => 10,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => -1,
        'heartbeat_timeout' => 1.0,
        'max_idle_time' => 60.0,
        'max_lifetime' => -1,
    ],
],
```

The `min_connections` and `max_connections` options define the size of the pool. The `connect_timeout` option controls how long Hypervel will wait while opening a new Redis connection. The `wait_timeout` option controls how long a coroutine may wait for a pooled connection to become available. The `heartbeat` option controls how often Hypervel validates idle connections in the worker pool; set this value to `-1` to disable background heartbeats. The `heartbeat_timeout` option controls how long a heartbeat ping may run before the connection is discarded. The `max_idle_time` option controls how long an idle connection may remain reusable before it is recycled, and the `max_lifetime` option controls the upper bound for how long a pooled connection generation may live before it is recycled while idle or before it is reused; Hypervel assigns each generation an effective lifetime between 90-100% of this value to avoid synchronized reconnects. Set `max_lifetime` to `-1` to disable lifetime recycling.

Idle and lifetime recycling are checked when a connection is borrowed from the pool. When heartbeat is enabled, Hypervel also runs a background sweep over idle pooled Redis connections so stale sockets are found before a request needs them. Heartbeat and max lifetime recycling apply to Hypervel's worker pool whether the connection points directly at Redis, a managed Redis service, or a proxy.

<a name="interacting-with-redis"></a>
## Interacting With Redis

You may interact with Redis by calling various methods on the `Redis` [facade](/docs/{{version}}/facades). The `Redis` facade supports dynamic methods, meaning you may call any [Redis command](https://redis.io/commands) on the facade and the command will be passed directly to Redis. In this example, we will call the Redis `GET` command by calling the `get` method on the `Redis` facade:

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Support\Facades\Redis;
use Hypervel\View\View;

class UserController extends Controller
{
    /**
     * Show the profile for the given user.
     */
    public function show(string $id): View
    {
        return view('user.profile', [
            'user' => Redis::get('user:profile:'.$id),
        ]);
    }
}
```

As mentioned above, you may call any of Redis' commands on the `Redis` facade. Hypervel uses magic methods to pass the commands to the Redis server. If a Redis command expects arguments, you should pass those to the facade's corresponding method:

```php
use Hypervel\Support\Facades\Redis;

Redis::set('name', 'Taylor');

$values = Redis::lrange('names', 5, 10);
```

Alternatively, you may pass commands to the server using the `Redis` facade's `command` method, which accepts the name of the command as its first argument and an array of values as its second argument:

```php
$values = Redis::command('lrange', ['name', 5, 10]);
```

<a name="using-multiple-redis-connections"></a>
#### Using Multiple Redis Connections

Your application's `config/database.php` configuration file allows you to define multiple Redis connections / servers. You may obtain a connection to a specific Redis connection using the `Redis` facade's `connection` method:

```php
$redis = Redis::connection('cache');
```

To obtain an instance of the default Redis connection, you may call the `connection` method without any additional arguments:

```php
$redis = Redis::connection();
```

You may disconnect a named Redis connection and flush its pool using the `purge` method. This method is intended for bootstrapping code, tests, or administrative tooling:

```php
Redis::purge('cache');
```

<a name="holding-a-pooled-connection"></a>
#### Holding a Pooled Connection

Most Redis facade calls check out a connection from the pool, execute the command, and immediately return the connection to the pool. If you need to run several operations against the same pooled connection, use the `withConnection` method:

```php
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Redis;

$values = Redis::withConnection(function (RedisConnection $connection): array {
    $connection->set('first', 'Taylor');
    $connection->set('second', 'Abigail');

    return $connection->mget(['first', 'second']);
});
```

By default, Hypervel applies Laravel-style transformations to certain PhpRedis command results. If you need the raw PhpRedis result shape for a block of work, pass `transform: false` to `withConnection`:

```php
$values = Redis::withConnection(function (RedisConnection $connection): array {
    return $connection->mget(['first', 'missing']);
}, transform: false);
```

In raw mode, PhpRedis returns `false` for missing keys in an `mget` result, while Hypervel's transformed mode returns `null`.

<a name="pinned-connections"></a>
#### Pinned Connections

If you want regular `Redis` facade calls inside a callback to reuse the same pooled connection, you may use the `withPinnedConnection` method:

```php
use Hypervel\Support\Facades\Redis;

Redis::withPinnedConnection(function () {
    Redis::incr('request:count');
    Redis::expire('request:count', 60);
});
```

The pinned connection is stored in coroutine context for the duration of the callback and is returned to the pool when the callback completes.

<a name="checking-cluster-connections"></a>
#### Checking Cluster Connections

You may determine if a Redis connection is configured as a Redis Cluster connection using the `isCluster` method:

```php
if (Redis::connection('cache')->isCluster()) {
    // ...
}
```

<a name="concurrency-limiting"></a>
#### Concurrency Limiting

The `Redis::funnel` method limits how many operations may run at the same time for a named resource:

```php
use Hypervel\Support\Facades\Redis;

Redis::funnel('reports')
    ->limit(3)
    ->releaseAfter(60)
    ->block(10)
    ->then(function () {
        // One of three slots is held for this callback...
    });
```

The `limit` method defines the number of slots. The `releaseAfter` method defines a crash-safety timeout in seconds, and `block` defines how long to wait for a free slot. If a slot cannot be acquired within the wait time and no failure callback is supplied, Hypervel throws a `Hypervel\Contracts\Limiters\LimiterTimeoutException`:

```php
use Hypervel\Contracts\Limiters\LimiterTimeoutException;

try {
    Redis::funnel('reports')
        ->limit(3)
        ->releaseAfter(60)
        ->block(10)
        ->then(fn () => $this->buildReport());
} catch (LimiterTimeoutException $e) {
    // Unable to acquire a slot...
}
```

For work that needs to hold a slot across several operations, acquire a lease and release it when the work is complete:

```php
$lease = Redis::funnel('reports')
    ->limit(3)
    ->releaseAfter(60)
    ->block(10)
    ->acquire();

try {
    foreach ($steps as $step) {
        $this->runStep($step);

        $lease->refresh();
    }
} finally {
    $lease->release();
}
```

Redis funnel leases are refreshable. Calling `refresh()` extends the slot's `releaseAfter` timeout if the lease still owns it, while `release()` frees the slot immediately. The `owner()` method returns the lease owner token, and `getRemainingLifetime()` returns the number of seconds before the slot expires or `null` when the slot does not exist or has no expiry.

If the process exits without releasing the lease, Redis expires the slot after the `releaseAfter` timeout. A `releaseAfter(0)` lease is permanent: Redis will not expire the slot, `getRemainingLifetime()` returns `null`, and `refresh()` only verifies that the lease still owns the slot. Permanent leases must be released explicitly to reclaim capacity.

<a name="rate-limiting"></a>
#### Rate Limiting

The `Redis::throttle` method limits how many times an operation may run during a time window:

```php
Redis::throttle('api')
    ->allow(100)
    ->every(60)
    ->block(5)
    ->then(function () {
        // At most 100 executions per 60 seconds...
    }, function () {
        // Could not acquire capacity within five seconds...
    });
```

Unlike `funnel`, a throttle does not return a releasable lease. It records an execution in a sliding window and lets Redis expire that record when it falls out of the configured duration.

<a name="deleting-keys-by-pattern"></a>
#### Deleting Keys by Pattern

The `flushByPattern` method deletes all keys matching a pattern using Redis' `SCAN` command. The pattern should not include your configured Redis prefix, since Hypervel handles the prefix automatically:

```php
$deleted = Redis::flushByPattern('users:*');
```

<a name="transactions"></a>
### Transactions

The `Redis` facade's `transaction` method provides a convenient wrapper around Redis' native `MULTI` and `EXEC` commands. The `transaction` method accepts a closure as its only argument. This closure will receive a Redis connection instance and may issue any commands it would like to this instance. All of the Redis commands issued within the closure will be executed in a single, atomic transaction:

```php
use Hypervel\Support\Facades\Redis;

Redis::transaction(function (\Redis|\RedisCluster $redis): void {
    $redis->incr('user_visits', 1);
    $redis->incr('total_visits', 1);
});
```

> [!NOTE]
> In Hypervel, the closure form of `transaction` returns the pooled connection as soon as the transaction is executed. Calling `Redis::transaction()` without a closure pins a connection for the rest of the coroutine, so the closure form is preferred for application code.

> [!WARNING]
> When defining a Redis transaction, you may not retrieve any values from the Redis connection. Remember, your transaction is executed as a single, atomic operation and that operation is not executed until your entire closure has finished executing its commands.

#### Lua Scripts

The `eval` method provides another method of executing multiple Redis commands in a single, atomic operation. However, the `eval` method has the benefit of being able to interact with and inspect Redis key values during that operation. Redis scripts are written in the [Lua programming language](https://www.lua.org).

The `eval` method can be a bit scary at first, but we'll explore a basic example to break the ice. The `eval` method expects several arguments. First, you should pass the Lua script (as a string) to the method. Secondly, you should pass the number of keys (as an integer) that the script interacts with. Thirdly, you should pass the names of those keys. Finally, you may pass any other additional arguments that you need to access within your script.

In this example, we will increment a counter, inspect its new value, and increment a second counter if the first counter's value is greater than five. Finally, we will return the value of the first counter:

```php
$value = Redis::eval(<<<'LUA'
    local counter = redis.call("incr", KEYS[1])

    if counter > 5 then
        redis.call("incr", KEYS[2])
    end

    return counter
LUA, 2, 'first-counter', 'second-counter');
```

> [!WARNING]
> Please consult the [Redis documentation](https://redis.io/commands/eval) for more information on Redis scripting.

<a name="pipelining-commands"></a>
### Pipelining Commands

Sometimes you may need to execute dozens of Redis commands. Instead of making a network trip to your Redis server for each command, you may use the `pipeline` method. The `pipeline` method accepts one argument: a closure that receives a Redis instance. You may issue all of your commands to this Redis instance and they will all be sent to the Redis server at the same time to reduce network trips to the server. The commands will still be executed in the order they were issued:

```php
use Hypervel\Support\Facades\Redis;

Redis::pipeline(function (\Redis $pipe): void {
    for ($i = 0; $i < 1000; $i++) {
        $pipe->set("key:$i", $i);
    }
});
```

> [!NOTE]
> In Hypervel, the closure form of `pipeline` returns the pooled connection as soon as the pipeline is executed. Calling `Redis::pipeline()` without a closure pins a connection for the rest of the coroutine, so the closure form is preferred for application code.

<a name="advanced-helpers"></a>
### Advanced Helpers

If you need to stream keys with Redis' `SCAN` command, use `safeScan` while holding a pooled connection. The `safeScan` method handles PhpRedis' `OPT_PREFIX` behavior by adding the prefix to the scan pattern and removing it from returned keys:

```php
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Redis;

$keys = Redis::withConnection(function (RedisConnection $connection): array {
    return iterator_to_array($connection->safeScan('users:*'));
});
```

The `evalWithShaCache` method executes a Lua script using `evalSha` and automatically falls back to `eval` when Redis has not cached the script yet:

```php
$result = Redis::evalWithShaCache(
    script: 'return redis.call("get", KEYS[1])',
    keys: ['first-counter'],
);
```

If you have configured PhpRedis serialization or compression but need to run a block of commands using raw values, you may use the `withoutSerializationOrCompression` method:

```php
$count = Redis::withoutSerializationOrCompression(function (): int {
    return Redis::incr('raw-counter');
});
```

When passing serialized values to Lua scripts as `ARGV` values, the `pack` method may be used to pack values using PhpRedis' configured serializer:

```php
$packed = Redis::withConnection(function (RedisConnection $connection): array {
    return $connection->pack([
        'Taylor',
        'Abigail',
    ]);
});
```

<a name="pubsub"></a>
## Pub / Sub

Hypervel provides a convenient interface to the Redis `publish` and `subscribe` commands. These Redis commands allow you to listen for messages on a given "channel". You may publish messages to the channel from another application, or even using another programming language, allowing easy communication between applications and processes.

First, let's set up a channel listener using the `subscribe` method. We'll place this method call within an [Artisan command](/docs/{{version}}/artisan) since calling the `subscribe` method begins a long-running process:

```php
<?php

namespace App\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Support\Facades\Redis;

class RedisSubscribe extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'redis:subscribe';

    /**
     * The console command description.
     */
    protected string $description = 'Subscribe to a Redis channel';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Redis::subscribe(['test-channel'], function (string $message): void {
            echo $message;
        });
    }
}
```

Now we may publish messages to the channel using the `publish` method:

```php
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\Facades\Route;

Route::get('/publish', function () {
    // ...

    Redis::publish('test-channel', json_encode([
        'name' => 'Taylor',
    ]));
});
```

Hypervel's pooled Redis connections cannot execute raw `subscribe` or `psubscribe` commands directly because pub/sub requires a long-lived dedicated socket. The `Redis::subscribe`, `Redis::psubscribe`, and `Redis::subscriber` methods create a dedicated subscriber socket instead of using the connection pool.

<a name="wildcard-subscriptions"></a>
#### Wildcard Subscriptions

Using the `psubscribe` method, you may subscribe to a wildcard channel, which may be useful for catching all messages on all channels. The channel name will be passed as the second argument to the provided closure:

```php
Redis::psubscribe(['*'], function (string $message, string $channel): void {
    echo $message;
});

Redis::psubscribe(['users.*'], function (string $message, string $channel): void {
    echo $message;
});
```

<a name="using-the-subscriber"></a>
#### Using the Subscriber

For more control over a long-running subscriber, you may use the `subscriber` method. This method returns a `Hypervel\Redis\Subscriber\Subscriber` instance backed by a dedicated socket connection:

```php
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Support\Facades\Redis;

$subscriber = Redis::subscriber();

try {
    $subscriber->subscribe('orders');

    while ($message = $subscriber->channel()->pop()) {
        /** @var Message $message */
        echo $message->channel.': '.$message->payload;
    }
} finally {
    $subscriber->close();
}
```

The subscriber supports `subscribe`, `unsubscribe`, `psubscribe`, `punsubscribe`, `ping`, `channel`, and `close` methods. Messages received from pattern subscriptions include the matched pattern on the message's `pattern` property.
