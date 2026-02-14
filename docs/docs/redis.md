# Redis
[[toc]]

## Introduction

[Redis](https://redis.io) is an open source, advanced key-value store. It is often referred to as a data structure server since keys can contain [strings](https://redis.io/docs/data-types/strings/), [hashes](https://redis.io/docs/data-types/hashes/), [lists](https://redis.io/docs/data-types/lists/), [sets](https://redis.io/docs/data-types/sets/), and [sorted sets](https://redis.io/docs/data-types/sorted-sets/).

Before using Redis with Hypervel Hyperf, you need to install and use the [PhpRedis](https://github.com/phpredis/phpredis) PHP extension via PECL.

::: warning
Unlike in Laravel, Hypervel doesn't support `predis` as an alternative for connection driver.
:::

## Configuration

You may configure your application's Redis settings via the `config/database.php` configuration file. Within this file, you will see a `redis` array containing the Redis servers utilized by your application:

```php
'redis' => [
    'options' => [
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'hypervel'), '_') . '_database_'),
        ],

    'default' => [
        'host' => env('REDIS_HOST', 'localhost'),
        'auth' => env('REDIS_AUTH', null),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('REDIS_DB', 0),
    ],

    'queue' => [
        'host' => env('REDIS_HOST', 'localhost'),
        'auth' => env('REDIS_AUTH', null),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('REDIS_DB', 0),
    ],
],
```

Each Redis server defined in your configuration file is required to have a name, host, and a port for the Redis connection.

### Clusters

If your application is utilizing a cluster of Redis servers, you should define these clusters within a `clusters` key of your Redis configuration. This configuration key does not exist by default so you will need to create it within your application's `config/database.php` configuration file:

```php
'redis' => [
    'default' => [
        'host' => env('REDIS_HOST', 'localhost'),
        'auth' => env('REDIS_AUTH', null),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('REDIS_DB', 0),
        'cluster' => [
            'enable' => true,
            'name' => 'mycluster',
            'seeds' => [],
        ],
    ],
    // ...
],
```

You can also configure `seeds` in `cluster` option without filling name for the cluster, like:

```php
'cluster' => [
    'enable' => true,
    'name' => null,
    'seeds' => [
        '192.168.1.110:6379',
        '192.168.1.111:6379',
    ],
```

### Sentinel

Redis Sentinel provides high availability for Redis by implementing monitoring, notifications, automatic failover, and configuration provider. Sentinel helps manage your Redis deployment by constantly checking if your master and replica instances are working as expected.

To configure Redis Sentinel in your Hypervel application, you can use the following configuration in your `config/database.php`:

```php
'redis' => [
    'default' => [
    'sentinel' => [
        'enable' => (bool) env('REDIS_SENTINEL_ENABLE', false),
        'master_name' => env('REDIS_MASTER_NAME', 'mymaster'),
        'nodes' => explode(';', env('REDIS_SENTINEL_NODE', '')),
        'persistent' => false,
        'read_timeout' => 30.0,
        'auth' =>  env('REDIS_SENTINEL_PASSWORD', ''),
    ],
],
```

When Sentinel is enabled, Hypervel will automatically handle failover scenarios by connecting to the current Redis master node as determined by the Sentinel cluster.

### Connection Pooling

Hypervel implements connection pooling for Redis to efficiently manage and reuse Redis connections. Connection pooling helps improve performance by maintaining a pool of pre-established connections that can be reused across requests, rather than creating and destroying connections for each operation. This reduces connection overhead and improves response times, especially in high-concurrency scenarios.


```php
'redis' => [
    'default' => [
    'pool' => [
        'min_connections' => 1,
        'max_connections' => 10,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => -1,
        'max_idle_time' => (float) env('REDIS_MAX_IDLE_TIME', 60),
    ],
],
```

### Other Configuration

In addition to the default configuration options, PhpRedis supports the following additional connection parameters: `name`, `persistent`, `persistent_id`, `prefix`, `read_timeout`, `retry_interval`, `max_retries`, `backoff_algorithm`, `backoff_base`, `backoff_cap`, `timeout`, and `context`. You may add any of these options to your Redis server configuration in the `config/database.php` configuration file:

```php
'default' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_DB', '0'),
    'read_timeout' => 60,
    'context' => [
        // 'auth' => ['username', 'secret'],
        // 'stream' => ['verify_peer' => false],
    ],
],
```

#### PhpRedis Serialization and Compression

The PhpRedis extension may also be configured to use a variety of serializers and compression algorithms. These algorithms can be configured via the `options` array of your Redis configuration:

```php
'redis' => [
    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'hypervel'), '_').'_database_'),
        'serializer' => Redis::SERIALIZER_MSGPACK,
        'compression' => Redis::COMPRESSION_LZ4,
    ],
    // ...
],
```

Currently supported serializers include: `Redis::SERIALIZER_NONE` (default), `Redis::SERIALIZER_PHP`, `Redis::SERIALIZER_JSON`, `Redis::SERIALIZER_IGBINARY`, and `Redis::SERIALIZER_MSGPACK`.

Supported compression algorithms include: `Redis::COMPRESSION_NONE` (default), `Redis::COMPRESSION_LZF`, `Redis::COMPRESSION_ZSTD`, and `Redis::COMPRESSION_LZ4`.

## Interacting With Redis

You may interact with Redis by calling various methods on the `Redis` [facade](/docs/facades). The `Redis` facade supports dynamic methods, meaning you may call any [Redis command](https://redis.io/commands) on the facade and the command will be passed directly to Redis. In this example, we will call the Redis `GET` command by calling the `get` method on the `Redis` facade:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Hypervel\Support\Facades\Redis;
use use Hyperf\ViewEngine\Contract\ViewInterface;

class UserController extends Controller
{
    /**
     * Show the profile for the given user.
     */
    public function show(string $id): ViewInterface
    {
        return view('user.profile', [
            'user' => Redis::get('user:profile:'.$id)
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

#### Using Multiple Redis Connections

Your application's `config/database.php` configuration file allows you to define multiple Redis connections / servers. You may obtain a connection to a specific Redis connection using the `Redis` facade's `connection` method:

```php
$redis = Redis::connection('connection-name');
```

To obtain an instance of the default Redis connection, you may call the `connection` method without any additional arguments:

```php
$redis = Redis::connection();
```

### Transactions

The `Redis` facade's `transaction` method provides a convenient wrapper around Redis' native `MULTI` and `EXEC` commands. The `transaction` method accepts a closure as its only argument. This closure will receive a Redis connection instance and may issue any commands it would like to this instance. All of the Redis commands issued within the closure will be executed in a single, atomic transaction:

```php
use Redis;
use Hypervel\Support\Facades;

Facades\Redis::transaction(function (Redis $redis) {
    $redis->incr('user_visits', 1);
    $redis->incr('total_visits', 1);
});
```

::: warning
When defining a Redis transaction, you may not retrieve any values from the Redis connection. Remember, your transaction is executed as a single, atomic operation and that operation is not executed until your entire closure has finished executing its commands.
:::

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

::: warning
Please consult the [Redis documentation](https://redis.io/commands/eval) for more information on Redis scripting.
:::

### Pipelining Commands

Sometimes you may need to execute dozens of Redis commands. Instead of making a network trip to your Redis server for each command, you may use the `pipeline` method. The `pipeline` method accepts one argument: a closure that receives a Redis instance. You may issue all of your commands to this Redis instance and they will all be sent to the Redis server at the same time to reduce network trips to the server. The commands will still be executed in the order they were issued:

```php
use Redis;
use Hypervel\Support\Facades;

Facades\Redis::pipeline(function (Redis $pipe) {
    for ($i = 0; $i < 1000; $i++) {
        $pipe->set("key:$i", $i);
    }
});
```

## Pub / Sub

Hypervel provides a convenient interface to the Redis `publish` and `subscribe` commands. These Redis commands allow you to listen for messages on a given "channel". You may publish messages to the channel from another application, or even using another programming language, allowing easy communication between applications and processes.

First, let's setup a channel listener using the `subscribe` method. We'll place this method call within an [Artisan command](/docs/artisan) since calling the `subscribe` method begins a long-running process:

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
        Redis::subscribe(['test-channel'], function (string $message) {
            echo $message;
        });
    }
}
```

Now we may publish messages to the channel using the `publish` method:

```php
use Hypervel\Support\Facades\Redis;

Route::get('/publish', function () {
    // ...

    Redis::publish('test-channel', json_encode([
        'name' => 'Adam Wathan'
    ]));
});
```

#### Wildcard Subscriptions

Using the `psubscribe` method, you may subscribe to a wildcard channel, which may be useful for catching all messages on all channels. The channel name will be passed as the second argument to the provided closure:

```php
Redis::psubscribe(['*'], function (string $message, string $channel) {
    echo $message;
});

Redis::psubscribe(['users.*'], function (string $message, string $channel) {
    echo $message;
});