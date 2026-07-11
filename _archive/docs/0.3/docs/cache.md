# Cache
[[toc]]

## Introduction

Some of the data retrieval or processing tasks performed by your application could be CPU intensive or take several seconds to complete. When this is the case, it is common to cache the retrieved data for a time so it can be retrieved quickly on subsequent requests for the same data. The cached data is usually stored in a very fast data store such as [Redis](https://redis.io).

Thankfully, Hypervel provides an expressive, unified API for various cache backends, allowing you to take advantage of their blazing fast data retrieval and speed up your web application.

::: tip
Because Hypervel implements the same cache protocol as Laravel, you can share the cache and locks between the frameworks
:::

## Configuration

Your application's cache configuration file is located at `config/cache.php`. In this file, you may specify which cache driver you would like to be used by default throughout your application. Hypervel supports popular caching backends like [Redis](https://redis.io), relational databases and Swoole Table out of the box. Swoole Table out of the box. In addition, a file based cache driver is available, while `array` and "null" cache drivers provide convenient cache backends for your automated tests.

The cache configuration file also contains various other options, which are documented within the file, so make sure to read over these options. By default, Hypervel is configured to use the `redis` cache driver, which stores the serialized, cached objects on the redis server. For larger applications, it is recommended that you keep using Redis. You may even configure multiple cache configurations for the same driver.

### Driver Prerequisites

#### Database

When using the `database` cache driver, you will need a database table to contain the cache data. Before using database driver, you need to create `0001_01_01_000001_create_cache_table.php` [database migration](/docs/migrations) via `make:cache-table` Artisan command:

```shell
php artisan make:cache-table

php artisan migrate
```

#### Redis

Before using a Redis cache with Hypervel, you will need to either install the PhpRedis PHP extension via PECL.

## Cache Usage

### Obtaining a Cache Instance

To obtain a cache store instance, you may use the `Cache` facade, which is what we will use throughout this documentation. The `Cache` facade provides convenient, terse access to the underlying implementations of the Hypervel cache contracts:

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Support\Facades\Cache;

class UserController extends Controller
{
    /**
     * Show a list of all users of the application.
     */
    public function index(): array
    {
        $value = Cache::get('key');

        return [
            // ...
        ];
    }
}
```

#### Accessing Multiple Cache Stores

Using the `Cache` facade, you may access various cache stores via the `store` method. The key passed to the `store` method should correspond to one of the stores listed in the `stores` configuration array in your `cache` configuration file:

```php
$value = Cache::store('file')->get('foo');

Cache::store('redis')->put('bar', 'baz', 600); // 10 Minutes
```

### Retrieving Items From the Cache

The `Cache` facade's `get` method is used to retrieve items from the cache. If the item does not exist in the cache, `null` will be returned. If you wish, you may pass a second argument to the `get` method specifying the default value you wish to be returned if the item doesn't exist:

```php
$value = Cache::get('key');

$value = Cache::get('key', 'default');
```

You may even pass a closure as the default value. The result of the closure will be returned if the specified item does not exist in the cache. Passing a closure allows you to defer the retrieval of default values from a database or other external service:

```php
$value = Cache::get('key', function () {
    return DB::table(/* ... */)->get();
});
```

#### Determining Item Existence

The `has` method may be used to determine if an item exists in the cache. This method will also return `false` if the item exists but its value is `null`:

```php
if (Cache::has('key')) {
    // ...
}
```

#### Incrementing / Decrementing Values

The `increment` and `decrement` methods may be used to adjust the value of integer items in the cache. Both of these methods accept an optional second argument indicating the amount by which to increment or decrement the item's value:

```php
// Initialize the value if it does not exist...
Cache::add('key', 0, now()->addHours(4));

// Increment or decrement the value...
Cache::increment('key');
Cache::increment('key', $amount);
Cache::decrement('key');
Cache::decrement('key', $amount);
```

#### Retrieve and Store

Sometimes you may wish to retrieve an item from the cache, but also store a default value if the requested item doesn't exist. For example, you may wish to retrieve all users from the cache or, if they don't exist, retrieve them from the database and add them to the cache. You may do this using the `Cache::remember` method:

```php
$value = Cache::remember('users', $seconds, function () {
    return DB::table('users')->get();
});
```

If the item does not exist in the cache, the closure passed to the `remember` method will be executed and its result will be placed in the cache.

You may use the `rememberForever` method to retrieve an item from the cache or store it forever if it does not exist:

```php
$value = Cache::rememberForever('users', function () {
    return DB::table('users')->get();
});
```

#### Retrieve and Delete

If you need to retrieve an item from the cache and then delete the item, you may use the `pull` method. Like the `get` method, `null` will be returned if the item does not exist in the cache:

```php
$value = Cache::pull('key');
```

### Storing Items in the Cache

You may use the `put` method on the `Cache` facade to store items in the cache:

```php
Cache::put('key', 'value', $seconds = 10);
```

If the storage time is not passed to the `put` method, the item will be stored indefinitely:

```php
Cache::put('key', 'value');
```

Instead of passing the number of seconds as an integer, you may also pass a `DateTime` instance representing the desired expiration time of the cached item:

```php
Cache::put('key', 'value', now()->addMinutes(10));
```

#### Store if Not Present

The `add` method will only add the item to the cache if it does not already exist in the cache store. The method will return `true` if the item is actually added to the cache. Otherwise, the method will return `false`. The `add` method is an atomic operation:

```php
Cache::add('key', 'value', $seconds);
```

#### Storing Items Forever

The `forever` method may be used to store an item in the cache permanently. Since these items will not expire, they must be manually removed from the cache using the `forget` method:

```php
Cache::forever('key', 'value');
```

### Removing Items From the Cache

You may remove items from the cache using the `forget` method:

```php
Cache::forget('key');
```

You may also remove items by providing a zero or negative number of expiration seconds:

```php
Cache::put('key', 'value', 0);

Cache::put('key', 'value', -5);
```

You may clear the entire cache using the `flush` method:

```php
Cache::flush();
```

::: warning
Flushing the cache does not respect your configured cache "prefix" and will remove all entries from the cache. Consider this carefully when clearing a cache which is shared by other applications.
:::

### Building Cache Stacks

Hypervel provides multi-tier caching architecture. The `stack` driver allows you to combine multiple cache layers for ultra performance. To illustrate how to use cache stacks, let's take a look at an example configuration that you might see in a production application:

```php
'stack' => [
    'driver' => 'stack',
    'stores' => [
        'swoole' => [
            'ttl' => 3, // seconds
        ],
        'redis',
    ],
],
```

Let's dissect this configuration. First, notice our `stack` aggregates two other cache drivers via its option: `swoole` and `redis`. Consequently, when caching data, both of these cache drivers will be invoked sequentially. The `ttl` option can specify the Time to Live (TTL) for each driver.

In this configuration, if there's a cache hit in the `swoole` layer, the data will be returned immediately, and the `redis` cache will not be queried. However, if there's a cache miss in the `swoole` layer, the stack driver will proceed to check subsequent drivers to determine if the cache exists in those layers. Should a cache hit occur in the `redis` driver, the data will be returned from that layer and simultaneously cached in the `swoole` driver for future use.

### The Cache Helper

In addition to using the `Cache` facade, you may also use the global `cache` function to retrieve and store data via the cache. When the `cache` function is called with a single, string argument, it will return the value of the given key:

```php
$value = cache('key');
```

If you provide an array of key / value pairs and an expiration time to the function, it will store values in the cache for the specified duration:

```php
cache(['key' => 'value'], $seconds);

cache(['key' => 'value'], now()->addMinutes(10));
```

When the `cache` function is called without any arguments, it returns an instance of the `Hypervel\Cache\Contracts\Factory` implementation, allowing you to call other caching methods:

```php
cache()->remember('users', $seconds, function () {
    return DB::table('users')->get();
});
```

::: tip
When testing call to the global `cache` function, you may use the `Cache::shouldReceive` method just as if you were [testing the facade](/docs/mocking#mocking-facades).
:::

## Atomic Locks

::: warning
To utilize this feature, your application must be using the `redis`, `file`, `database` or `array` cache driver as your application's default cache driver. In addition, all servers must be communicating with the same central cache server.
:::

### Managing Locks

Atomic locks allow for the manipulation of distributed locks without worrying about race conditions. You may create and manage locks using the `Cache::lock` method:

```php
use Hypervel\Support\Facades\Cache;

$lock = Cache::lock('foo', 10);

if ($lock->get()) {
    // Lock acquired for 10 seconds...

    $lock->release();
}
```

The `get` method also accepts a closure. After the closure is executed, Hypervel will automatically release the lock:

```php
Cache::lock('foo', 10)->get(function () {
    // Lock acquired for 10 seconds and automatically released...
});
```

If the lock is not available at the moment you request it, you may instruct Hypervel to wait for a specified number of seconds. If the lock can not be acquired within the specified time limit, an `Hypervel\Cache\Exceptions\LockTimeoutException` will be thrown:

```php
use Hypervel\Cache\Exceptions\LockTimeoutException;

$lock = Cache::lock('foo', 10);

try {
    $lock->block(5);

    // Lock acquired after waiting a maximum of 5 seconds...
} catch (LockTimeoutException $e) {
    // Unable to acquire lock...
} finally {
    $lock?->release();
}
```

The example above may be simplified by passing a closure to the `block` method. When a closure is passed to this method, Hypervel will attempt to acquire the lock for the specified number of seconds and will automatically release the lock once the closure has been executed:

```php
Cache::lock('foo', 10)->block(5, function () {
    // Lock acquired after waiting a maximum of 5 seconds...
});
```

### Managing Locks Across Processes

Sometimes, you may wish to acquire a lock in one process and release it in another process. For example, you may acquire a lock during a web request and wish to release the lock at the end of a queued job that is triggered by that request. In this scenario, you should pass the lock's scoped "owner token" to the queued job so that the job can re-instantiate the lock using the given token.

In the example below, we will execute a task if a lock is successfully acquired. In addition, we will pass the lock's owner token to the task via the lock's `owner` method:

```php
use Hyperf\Task\TaskExecutor;
use Hyperf\Task\Task;

$podcast = Podcast::find($id);

$lock = Cache::lock('processing', 120);

if ($lock->get()) {
    app(TaskExecutor::class)
        ->execute(new Task(
            [MethodTask::class, 'handle'],
            $lock->owner()
        );
}
```

Within our application's `MethodTask` task, we can restore and release the lock using the owner token:

```php
class MethodTask
{
    public function handle($lockOwner)
    {
        $lock = Cache::restoreLock('processing', $lockOwner);
        $lock->release();
    }
}
```

If you would like to release a lock without respecting its current owner, you may use the `forceRelease` method:

```php
Cache::lock('processing')->forceRelease();
```

## Adding Custom Cache Drivers

### Writing the Driver

To create our custom cache driver, we first need to implement the `Hypervel\Cache\Contracts\Store` [contract](/docs/contracts). So, a MongoDB cache implementation might look something like this:

```php
<?php

namespace App\Extensions;

use Hypervel\Cache\Contracts\Store;

class MongoStore implements Store
{
    public function get($key) {}
    public function many(array $keys) {}
    public function put($key, $value, $seconds) {}
    public function putMany(array $values, $seconds) {}
    public function increment($key, $value = 1) {}
    public function decrement($key, $value = 1) {}
    public function forever($key, $value) {}
    public function forget($key) {}
    public function flush() {}
    public function getPrefix() {}
}
```

We just need to implement each of these methods using a MongoDB connection. For an example of how to implement each of these methods, take a look at the `Hypervel\Cache\RedisStore` in the [Hypervel framework source code](https://github.com/hypervel/components/blob/master/src/cache/src/RedisStore.php). Once our implementation is complete, we can finish our custom driver registration by calling the `Cache` facade's `extend` method:

```php
Cache::extend('mongo', function (Application $app) {
    return Cache::repository(new MongoStore);
});
```

::: note
If you're wondering where to put your custom cache driver code, you could create an `Extensions` namespace within your `app` directory. However, keep in mind that Hypervel does not have a rigid application structure and you are free to organize your application according to your preferences.
:::

### Registering the Driver

To register the custom cache driver with Hypervel, we will use the `extend` method on the `Cache` facade. Since other service providers may attempt to read cached values within their `boot` method, we will register our custom driver within a `booting` callback. By using the `booting` callback, we can ensure that the custom driver is registered just before the `boot` method is called on our application's service providers but after the `register` method is called on all of the service providers. We will register our `booting` callback within the `register` method of our application's `App\Providers\AppServiceProvider` class:

```php
<?php

namespace App\Providers;

use App\Extensions\MongoStore;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->booting(function () {
            Cache::extend('mongo', function (Application $app) {
                return Cache::repository(new MongoStore);
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ...
    }
}
```

The first argument passed to the `extend` method is the name of the driver. This will correspond to your `driver` option in the `config/cache.php` configuration file. The second argument is a closure that should return an `Hypervel\Cache\Repository` instance. The closure will be passed an `$app` instance, which is an instance of the [service container](/docs/container).

Once your extension is registered, update your `config/cache.php` configuration file's `driver` option to the name of your extension.

## Events

To execute code on every cache operation, you may listen for various [events](/docs/events) dispatched by the cache:

<div class="overflow-auto">

| Event Name                                   |
|----------------------------------------------|
| `Hypervel\Cache\Events\CacheFlushed`       |
| `Hypervel\Cache\Events\CacheFlushing`      |
| `Hypervel\Cache\Events\CacheHit`           |
| `Hypervel\Cache\Events\CacheMissed`        |
| `Hypervel\Cache\Events\ForgettingKey`      |
| `Hypervel\Cache\Events\KeyForgetFailed`    |
| `Hypervel\Cache\Events\KeyForgotten`       |
| `Hypervel\Cache\Events\KeyWriteFailed`     |
| `Hypervel\Cache\Events\KeyWritten`         |
| `Hypervel\Cache\Events\RetrievingKey`      |
| `Hypervel\Cache\Events\RetrievingManyKeys` |
| `Hypervel\Cache\Events\WritingKey`         |
| `Hypervel\Cache\Events\WritingManyKeys`    |

</div>

To increase performance, cache events are disabled in Hypervel by default. You may enable cache events by setting the `events` configuration option to `true` for a given cache store in your application's `config/cache.php` configuration file:

```php
'database' => [
    'driver' => 'database',
    // ...
    'events' => true,
],
```
