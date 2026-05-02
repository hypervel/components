# Cache

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Driver Prerequisites](#driver-prerequisites)
    - [Swoole Table Cache](#swoole-table-cache)
    - [Building Cache Stacks](#building-cache-stacks)
    - [Session Cache](#session-cache)
    - [Cache Failover](#cache-failover)
- [Cache Usage](#cache-usage)
    - [Obtaining a Cache Instance](#obtaining-a-cache-instance)
    - [Retrieving Items From the Cache](#retrieving-items-from-the-cache)
    - [Storing Items in the Cache](#storing-items-in-the-cache)
    - [Extending Item Lifetime](#extending-item-lifetime)
    - [Removing Items From the Cache](#removing-items-from-the-cache)
    - [Cache Memoization](#cache-memoization)
    - [The Cache Helper](#the-cache-helper)
- [Cache Tags](#cache-tags)
    - [Redis Tag Modes](#redis-tag-modes)
    - [All Tag Mode](#all-tag-mode)
    - [Any Tag Mode](#any-tag-mode)
    - [Pruning Stale Tag Entries](#pruning-stale-tag-entries)
- [Atomic Locks](#atomic-locks)
    - [Managing Locks](#managing-locks)
    - [Managing Locks Across Processes](#managing-locks-across-processes)
    - [Refreshing Locks](#refreshing-locks)
    - [Flushing Locks](#flushing-locks)
    - [Concurrency Limiting](#concurrency-limiting)
- [Adding Custom Cache Drivers](#adding-custom-cache-drivers)
    - [Writing the Driver](#writing-the-driver)
    - [Registering the Driver](#registering-the-driver)
- [Console Commands](#console-commands)
- [Events](#events)

<a name="introduction"></a>
## Introduction

Some of the data retrieval or processing tasks performed by your application could be CPU intensive or take several seconds to complete. When this is the case, it is common to cache the retrieved data for a time so it can be retrieved quickly on subsequent requests for the same data. The cached data is usually stored in a very fast data store such as [Redis](https://redis.io), a relational database, local files, or Swoole tables.

Thankfully, Hypervel provides an expressive, unified API for various cache backends, allowing you to take advantage of their blazing fast data retrieval and speed up your web application.

> [!NOTE]
> Non-tagged Hypervel Redis cache keys and atomic lock keys can be shared with Laravel applications using the same Redis connection, cache prefix, and serialization settings. Tagged-cache storage is Hypervel-specific and is not interchangeable with Laravel's tagged caches because Hypervel uses separate `_all` and `_any` tag namespaces to support its Redis tag modes.

<a name="configuration"></a>
## Configuration

Your application's cache configuration file is located at `config/cache.php`. In this file, you may specify which cache store you would like to be used by default throughout your application. Hypervel supports Redis, relational databases, file storage, Swoole tables, session storage, cache stacks, failover stores, and the `array` and `null` stores that are convenient for automated tests.

The cache configuration file also contains a variety of other options that you may review. By default, Hypervel is configured to use the `database` cache driver, which stores serialized cache values in your application's database.

<a name="driver-prerequisites"></a>
### Driver Prerequisites

<a name="prerequisites-database"></a>
#### Database

When using the `database` cache driver, you will need database tables to contain cache data and cache locks. Hypervel's application skeleton includes default `cache` and `cache_locks` table migrations. If your application does not contain these migrations, you may use the `make:cache-table` Artisan command to create them:

```shell
php artisan make:cache-table

php artisan migrate
```

<a name="redis"></a>
#### Redis

Before using a Redis cache with Hypervel, you will need to install the PhpRedis PHP extension via PECL.

Redis cache tags support two modes. The default `all` mode works on standard Redis deployments. The `any` tag mode requires Redis 8.0+ or Valkey 9.0+ and PhpRedis 6.3.0+, because it uses Redis hash-field expiration commands for tag indexes.

```php
'redis' => [
    'driver' => 'redis',
    'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
    'tag_mode' => env('REDIS_CACHE_TAG_MODE', 'all'),
    'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'cache'),
],
```

You may run the `cache:redis-doctor` command to verify your Redis cache configuration and feature support:

```shell
php artisan cache:redis-doctor
```

<a name="swoole-table-cache"></a>
### Swoole Table Cache

The `swoole` cache driver stores cache values in a Swoole table. This can be useful for very hot cache values that should be served from memory without a Redis or database round trip. Swoole tables are stored in memory and are cleared when the server restarts.

Swoole tables are bounded by their configured row count and column size. Hypervel's Swoole cache store supports `lru`, `lfu`, `ttl`, and `noeviction` eviction policies, as well as a memory-limit buffer and eviction proportion:

```php
use Hypervel\Cache\SwooleStore;

'swoole' => [
    'driver' => 'swoole',
    'table' => 'default',
    'memory_limit_buffer' => 0.05,
    'eviction_policy' => SwooleStore::EVICTION_POLICY_LRU,
    'eviction_proportion' => 0.05,
    'eviction_interval' => 10000, // milliseconds
],
```

The available eviction policy constants are `EVICTION_POLICY_LRU`, `EVICTION_POLICY_LFU`, `EVICTION_POLICY_TTL`, and `EVICTION_POLICY_NOEVICTION`.

The table itself is configured in the `swoole_tables` section of your `config/cache.php` file:

```php
'swoole_tables' => [
    'default' => [
        'rows' => 1024,
        'bytes' => 10240,
        'conflict_proportion' => 0.2,
    ],
],
```

<a name="building-cache-stacks"></a>
### Building Cache Stacks

Hypervel provides a multi-tier caching architecture. The `stack` driver allows you to combine multiple cache layers for high-performance reads. To illustrate how to use cache stacks, let's take a look at an example configuration that you might see in a production application:

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

This configuration aggregates two other cache stores: `swoole` and `redis`. When caching data, both stores are written sequentially. The `ttl` option may be used to override the time to live for a specific layer.

When retrieving data, if there is a cache hit in the `swoole` layer, the data will be returned immediately and the Redis cache will not be queried. If there is a cache miss in the `swoole` layer, the stack driver will check the Redis layer. If Redis contains the value, the value will be returned and backfilled into the Swoole layer for future requests.

<a name="session-cache"></a>
### Session Cache

The `session` cache driver stores cache values inside the active session store. This is useful for per-user scratch data that should live with the user's session:

```php
'session' => [
    'driver' => 'session',
    'key' => env('SESSION_CACHE_KEY', '_cache'),
],
```

<a name="cache-failover"></a>
### Cache Failover

The `failover` cache driver provides automatic failover functionality when interacting with the cache. If the primary cache store of the `failover` store fails for any reason, Hypervel will automatically attempt to use the next configured store in the list. This is particularly useful for ensuring high availability in production environments where cache reliability is critical.

To configure a failover cache store, specify the `failover` driver and provide an array of store names to attempt in order. By default, Hypervel includes an example failover configuration in your application's `config/cache.php` configuration file:

```php
'failover' => [
    'driver' => 'failover',
    'stores' => [
        'database',
        'array',
    ],
],
```

Once you have configured a store that uses the `failover` driver, you will need to set the failover store as your default cache store in your application's `.env` file to make use of the failover functionality:

```ini
CACHE_STORE=failover
```

When a cache store operation fails and failover is activated, Hypervel will dispatch the `Hypervel\Cache\Events\CacheFailedOver` event, allowing you to report or log that a cache store has failed.

<a name="cache-usage"></a>
## Cache Usage

<a name="obtaining-a-cache-instance"></a>
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

<a name="accessing-multiple-cache-stores"></a>
#### Accessing Multiple Cache Stores

Using the `Cache` facade, you may access various cache stores via the `store` method. The key passed to the `store` method should correspond to one of the stores listed in the `stores` configuration array in your `cache` configuration file:

```php
$value = Cache::store('file')->get('foo');

Cache::store('redis')->put('bar', 'baz', 600); // 10 Minutes
```

<a name="retrieving-items-from-the-cache"></a>
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

<a name="determining-item-existence"></a>
#### Determining Item Existence

The `has` method may be used to determine if an item exists in the cache. This method will also return `false` if the item exists but its value is `null`:

```php
if (Cache::has('key')) {
    // ...
}
```

<a name="incrementing-decrementing-values"></a>
#### Incrementing / Decrementing Values

The `increment` and `decrement` methods may be used to adjust the value of integer items in the cache. Both of these methods accept an optional second argument indicating the amount by which to increment or decrement the item's value:

```php
// Initialize the value if it does not exist...
Cache::add('key', 0, now()->plus(hours: 4));

// Increment or decrement the value...
Cache::increment('key');
Cache::increment('key', $amount);
Cache::decrement('key');
Cache::decrement('key', $amount);
```

<a name="retrieve-store"></a>
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

<a name="negative-caching"></a>
#### Negative Caching

Sometimes `null` is a real result that you want to cache. For example, you may look up a user, a feature flag, or an external resource and determine that nothing exists. Caching that "not found" result can prevent repeated database queries or API calls for the same missing data. This is often referred to as *negative caching*.

By default, the `remember` family does not cache `null` values. If the closure returns `null`, the value will be treated as a cache miss and the closure will run again on the next call.

If you would like to cache `null` results, you may use the `rememberNullable`, `rememberForeverNullable`, and `flexibleNullable` methods:

```php
$value = Cache::rememberNullable('users:'.$id, 300, function () use ($id) {
    return User::find($id);
});

$value = Cache::rememberForeverNullable('settings:'.$key, function () use ($key) {
    return Settings::get($key);
});

$value = Cache::flexibleNullable('users:'.$id, [5, 10], function () use ($id) {
    return User::find($id);
});
```

If the closure returns a real value, it will be cached as usual. If the closure returns `null`, Hypervel will cache that result and return `null` on subsequent calls until the cache entry expires or is cleared.

The `searNullable` method is also available as an alias of `rememberForeverNullable`.

<a name="swr"></a>
#### Stale While Revalidate

When using the `Cache::remember` method, some users may experience slow response times if the cached value has expired. For certain types of data, it can be useful to allow partially stale data to be served while the cached value is recalculated in the background, preventing some users from experiencing slow response times while cached values are calculated. This is often referred to as the "stale-while-revalidate" pattern, and the `Cache::flexible` method provides an implementation of this pattern.

The flexible method accepts an array that specifies how long the cached value is considered "fresh" and when it becomes "stale". The first value in the array represents the number of seconds the cache is considered fresh, while the second value defines how long it can be served as stale data before recalculation is necessary.

If a request is made within the fresh period (before the first value), the cache is returned immediately without recalculation. If a request is made during the stale period (between the two values), the stale value is served to the user, and a [deferred function](/docs/{{version}}/helpers#deferred-functions) is registered to refresh the cached value after the response is sent to the user. If a request is made after the second value, the cache is considered expired, and the value is recalculated immediately, which may result in a slower response for the user:

```php
$value = Cache::flexible('users', [5, 10], function () {
    return DB::table('users')->get();
});
```

<a name="retrieve-delete"></a>
#### Retrieve and Delete

If you need to retrieve an item from the cache and then delete the item, you may use the `pull` method. Like the `get` method, `null` will be returned if the item does not exist in the cache:

```php
$value = Cache::pull('key');

$value = Cache::pull('key', 'default');
```

<a name="storing-items-in-the-cache"></a>
### Storing Items in the Cache

You may use the `put` method on the `Cache` facade to store items in the cache:

```php
Cache::put('key', 'value', $seconds = 10);
```

If the storage time is not passed to the `put` method, the item will be stored indefinitely:

```php
Cache::put('key', 'value');
```

Instead of passing the number of seconds as an integer, you may also pass a `DateTimeInterface` instance representing the desired expiration time of the cached item:

```php
Cache::put('key', 'value', now()->plus(minutes: 10));
```

<a name="store-if-not-present"></a>
#### Store if Not Present

The `add` method will only add the item to the cache if it does not already exist in the cache store. The method will return `true` if the item is actually added to the cache. Otherwise, the method will return `false`. The `add` method is an atomic operation on stores that provide native support for it:

```php
Cache::add('key', 'value', $seconds);
```

<a name="extending-item-lifetime"></a>
### Extending Item Lifetime

The `touch` method allows you to extend the lifetime (TTL) of an existing cache item. The `touch` method will return `true` if the cache item exists and its expiration time was successfully extended. If the item does not exist in the cache, the method will return `false`:

```php
Cache::touch('key', 3600);
```

You may provide an integer number of seconds, a `DateInterval`, or a `DateTimeInterface` instance to specify the new lifetime or expiration time:

```php
Cache::touch('key', now()->plus(hours: 2));
```

<a name="storing-items-forever"></a>
#### Storing Items Forever

The `forever` method may be used to store an item in the cache permanently. Since these items will not expire, they must be manually removed from the cache using the `forget` method:

```php
Cache::forever('key', 'value');
```

> [!NOTE]
> If you are using the `swoole` driver, items stored using the `forever` method are stored with a long expiration time and may still expire or be evicted when the table reaches its capacity, depending on the configured eviction policy.

<a name="removing-items-from-the-cache"></a>
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

> [!WARNING]
> Flushing the cache does not respect your configured cache "prefix" and may remove entries from the underlying cache store that are used by other applications. Consider this carefully when clearing a cache which is shared by other applications.

<a name="cache-memoization"></a>
### Cache Memoization

Hypervel's memoized cache allows you to temporarily store resolved cache values in memory during a single request or job execution. This prevents repeated cache hits within the same execution, significantly improving performance.

To use the memoized cache, invoke the `memo` method:

```php
use Hypervel\Support\Facades\Cache;

$value = Cache::memo()->get('key');
```

The `memo` method optionally accepts the name of a cache store, which specifies the underlying cache store the memoized driver will decorate:

```php
// Using the default cache store...
$value = Cache::memo()->get('key');

// Using the Redis cache store...
$value = Cache::memo('redis')->get('key');
```

The first `get` call for a given key retrieves the value from your cache store, but subsequent calls within the same request or job will retrieve the value from memory:

```php
// Hits the cache...
$value = Cache::memo()->get('key');

// Does not hit the cache, returns memoized value...
$value = Cache::memo()->get('key');
```

When calling methods that modify cache values (such as `put`, `increment`, `remember`, etc.), the memoized cache automatically forgets the memoized value and delegates the mutating method call to the underlying cache store:

```php
Cache::memo()->put('name', 'Taylor'); // Writes to underlying cache...
Cache::memo()->get('name');           // Hits underlying cache...
Cache::memo()->get('name');           // Memoized, does not hit cache...

Cache::memo()->put('name', 'Tim');    // Forgets memoized value, writes new value...
Cache::memo()->get('name');           // Hits underlying cache again...
```

Memoized values are scoped to the current request, job, or coroutine context. They are not stored on the worker for the lifetime of the process.

<a name="the-cache-helper"></a>
### The Cache Helper

In addition to using the `Cache` facade, you may also use the global `cache` function to retrieve and store data via the cache. When the `cache` function is called with a single, string argument, it will return the value of the given key:

```php
$value = cache('key');
```

If you provide an array of key / value pairs and an expiration time to the function, it will store values in the cache for the specified duration:

```php
cache(['key' => 'value'], $seconds);

cache(['key' => 'value'], now()->plus(minutes: 10));
```

When the `cache` function is called without any arguments, it returns an instance of the `Hypervel\Contracts\Cache\Factory` implementation, allowing you to call other caching methods:

```php
cache()->remember('users', $seconds, function () {
    return DB::table('users')->get();
});
```

> [!NOTE]
> When testing calls to the global `cache` function, you may use the `Cache::shouldReceive` method just as if you were [testing the facade](/docs/{{version}}/mocking#mocking-facades).

<a name="cache-tags"></a>
## Cache Tags

> [!WARNING]
> Cache tags are supported by the `redis`, `array`, `failover`, and `null` cache drivers. They are not supported by the `file`, `database`, `swoole`, `stack`, `session`, or `memo` drivers.

<a name="redis-tag-modes"></a>
### Redis Tag Modes

Hypervel's Redis cache driver supports two tag modes: `all` and `any`. You may configure the mode using the `tag_mode` option for your Redis cache store:

```php
'redis' => [
    'driver' => 'redis',
    'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
    'tag_mode' => env('REDIS_CACHE_TAG_MODE', 'all'),
],
```

The `all` mode is the default and behaves like Laravel's classic tagged cache: the full set of tags acts as a namespace. The `any` mode treats tags as invalidation indexes instead of namespaces.

The two Redis tag modes write to different tag namespaces (`_all:tag:...` and `_any:tag:...`) and cannot share tagged cache data. If you switch modes on an existing store, you should flush the cache first.

<a name="all-tag-mode"></a>
### All Tag Mode

In `all` mode, cache tags allow you to tag related items in the cache and then flush all cached values that have been assigned a given tag. You may access a tagged cache by passing an ordered array of tag names. For example, let's access a tagged cache and `put` a value into the cache:

```php
use Hypervel\Support\Facades\Cache;

Cache::tags(['people', 'artists'])->put('John', $john, $seconds);
Cache::tags(['people', 'authors'])->put('Anne', $anne, $seconds);
```

Items stored via tags may not be accessed without also providing the tags that were used to store the value. To retrieve a tagged cache item, pass the same ordered list of tags to the `tags` method, then call the `get` method with the key you wish to retrieve:

```php
$john = Cache::tags(['people', 'artists'])->get('John');

$anne = Cache::tags(['people', 'authors'])->get('Anne');
```

You may flush all items that are assigned a tag or list of tags. For example, the following code would remove all caches tagged with either `people`, `authors`, or both. So, both `Anne` and `John` would be removed from the cache:

```php
Cache::tags(['people', 'authors'])->flush();
```

In contrast, the code below would remove only cached values tagged with `authors`, so `Anne` would be removed, but not `John`:

```php
Cache::tags('authors')->flush();
```

<a name="any-tag-mode"></a>
### Any Tag Mode

The Redis `any` tag mode requires Redis 8.0+ or Valkey 9.0+ and PhpRedis 6.3.0+. In this mode, tags are used for writing, indexing, and flushing only. Items are stored under their plain cache keys, so you should retrieve them without calling `tags()`:

```php
use Hypervel\Support\Facades\Cache;

Cache::tags(['user:42', 'team:7'])->put('profile:42', $profile, 3600);

$profile = Cache::get('profile:42');
```

Because tags are invalidation indexes in `any` mode, flushing any one tag removes all cache items that were written with that tag:

```php
Cache::tags(['user:42'])->flush();
```

> [!WARNING]
> In `any` mode, attempting to retrieve, check, pull, forget, or retrieve many cache items through a tagged cache will throw a `BadMethodCallException`. Use the direct `Cache::get`, `Cache::has`, `Cache::pull`, `Cache::forget`, and `Cache::many` methods with the full cache key instead.

The `items` method returns a generator yielding all key / value pairs indexed by the given tags. This can be useful for debugging or bulk operations:

```php
foreach (Cache::tags(['user:42'])->items() as $key => $value) {
    // ...
}
```

<a name="pruning-stale-tag-entries"></a>
### Pruning Stale Tag Entries

Redis tag indexes may accumulate stale references to expired or deleted cache items. You may prune stale tag entries using the `cache:prune-stale-tags` Artisan command:

```shell
php artisan cache:prune-stale-tags

php artisan cache:prune-stale-tags redis
```

You may schedule this command to run periodically based on how often tagged cache entries are written and expired by your application.

<a name="atomic-locks"></a>
## Atomic Locks

> [!WARNING]
> To utilize this feature, your application must be using the `redis`, `database`, `file`, or `array` cache driver as your application's default cache driver. For distributed locks, all servers must be communicating with the same central cache server.

<a name="managing-locks"></a>
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

If the lock is not available at the moment you request it, you may instruct Hypervel to wait for a specified number of seconds. If the lock cannot be acquired within the specified time limit, a `Hypervel\Contracts\Cache\LockTimeoutException` will be thrown:

```php
use Hypervel\Contracts\Cache\LockTimeoutException;

$lock = Cache::lock('foo', 10);

try {
    $lock->block(5);

    // Lock acquired after waiting a maximum of 5 seconds...
} catch (LockTimeoutException $e) {
    // Unable to acquire lock...
} finally {
    $lock->release();
}
```

The example above may be simplified by passing a closure to the `block` method. When a closure is passed to this method, Hypervel will attempt to acquire the lock for the specified number of seconds and will automatically release the lock once the closure has been executed:

```php
Cache::lock('foo', 10)->block(5, function () {
    // Lock acquired for 10 seconds after waiting a maximum of 5 seconds...
});
```

You may specify the number of milliseconds to sleep between blocked lock acquisition attempts using the `betweenBlockedAttemptsSleepFor` method:

```php
Cache::lock('foo', 10)
    ->betweenBlockedAttemptsSleepFor(500)
    ->block(5, function () {
        // ...
    });
```

<a name="managing-locks-across-processes"></a>
### Managing Locks Across Processes

Sometimes, you may wish to acquire a lock in one process and release it in another process. For example, you may acquire a lock during a web request and wish to release the lock at the end of a queued job that is triggered by that request. In this scenario, you should pass the lock's scoped "owner token" to the queued job so that the job can re-instantiate the lock using the given token.

In the example below, we will dispatch a queued job if a lock is successfully acquired. In addition, we will pass the lock's owner token to the queued job via the lock's `owner` method:

```php
$podcast = Podcast::find($id);

$lock = Cache::lock('processing', 120);

if ($lock->get()) {
    ProcessPodcast::dispatch($podcast, $lock->owner());
}
```

Within our application's `ProcessPodcast` job, we can restore and release the lock using the owner token:

```php
Cache::restoreLock('processing', $this->owner)->release();
```

If you would like to release a lock without respecting its current owner, you may use the `forceRelease` method:

```php
Cache::lock('processing')->forceRelease();
```

<a name="refreshing-locks"></a>
### Refreshing Locks

The `redis`, `database`, and `array` cache lock drivers support atomic TTL refresh and remaining-lifetime inspection via the `Hypervel\Contracts\Cache\RefreshableLock` interface. This is useful for long-running work where you want to extend the lock as you go rather than acquiring a single conservative lock up front:

```php
$lock = Cache::lock('processing', 60);

if ($lock->get()) {
    try {
        foreach ($items as $item) {
            $this->process($item);

            $lock->refresh();
        }
    } finally {
        $lock->release();
    }
}
```

The `refresh` method is atomic. If the lock has expired or has been acquired by another process, it will return `false` without modifying the lock. You may pass an explicit TTL to refresh the lock for a different duration:

```php
$lock->refresh(120);
```

If the lock was acquired permanently by passing `0` seconds, calling `refresh` without arguments is a no-op that returns `true`. Calling `refresh` with a non-positive explicit TTL will throw an `InvalidArgumentException`.

You may inspect the number of seconds remaining before a refreshable lock expires using the `getRemainingLifetime` method. This method returns `null` if the lock does not exist or has no expiration:

```php
$remaining = $lock->getRemainingLifetime();
```

> [!NOTE]
> File locks do not support lock refreshing. If your code may receive different lock implementations, check that the lock is an instance of `Hypervel\Contracts\Cache\RefreshableLock` before calling `refresh` or `getRemainingLifetime`.

<a name="flushing-locks"></a>
### Flushing Locks

You may clear all atomic locks in the cache using the `flushLocks` method:

```php
Cache::flushLocks();
```

The `flushLocks` method is supported by the `redis`, `database`, `file`, and `array` cache drivers. Redis, database, and file stores only support flushing locks when lock storage is configured separately from regular cache storage. If lock storage is shared with regular cache storage, Hypervel will throw a `RuntimeException`. If a store does not support flushing locks, Hypervel will throw a `BadMethodCallException`.

> [!WARNING]
> The `flushLocks` method removes every lock in the lock store, regardless of which application or process owns the lock. Use it carefully in shared environments.

You may also flush only cache locks from the command line using the `--locks` option:

```shell
php artisan cache:clear --locks
```

<a name="concurrency-limiting"></a>
### Concurrency Limiting

Hypervel's atomic lock functionality also provides a few ways to limit concurrent execution of closures. Use `withoutOverlapping` when you want to allow only one running instance across your infrastructure:

```php
Cache::withoutOverlapping('foo', function () {
    // Lock acquired after waiting a maximum of 10 seconds...
});
```

By default, the lock is held until the closure finishes executing, and the method waits up to 10 seconds to acquire the lock. You may customize these values using additional arguments:

```php
Cache::withoutOverlapping('foo', function () {
    // Lock acquired for 120 seconds after waiting a maximum of 5 seconds...
}, lockFor: 120, waitFor: 5);
```

If the lock cannot be acquired within the specified wait time, a `Hypervel\Contracts\Cache\LockTimeoutException` will be thrown.

If you want controlled parallelism, use the `funnel` method to set a maximum number of concurrent executions. The `funnel` method works with any cache driver that supports locks:

```php
Cache::funnel('foo')
    ->limit(3)
    ->releaseAfter(60)
    ->block(10)
    ->then(function () {
        // Concurrency lock acquired...
    }, function () {
        // Could not acquire concurrency lock...
    });
```

The `funnel` key identifies the resource being limited. The `limit` method defines the maximum concurrent executions. The `releaseAfter` method sets a safety timeout in seconds before an acquired slot is automatically released. The `block` method sets how many seconds to wait for an available slot.

If you prefer to handle the timeout via exceptions instead of providing a failure closure, you may omit the second closure. A `Hypervel\Cache\Limiters\LimiterTimeoutException` will be thrown if the lock cannot be acquired within the specified wait time:

```php
use Hypervel\Cache\Limiters\LimiterTimeoutException;

try {
    Cache::funnel('foo')
        ->limit(3)
        ->releaseAfter(60)
        ->block(10)
        ->then(function () {
            // Concurrency lock acquired...
        });
} catch (LimiterTimeoutException $e) {
    // Unable to acquire concurrency lock...
}
```

If you would like to use a specific cache store for the concurrency limiter, you may invoke the `funnel` method on the desired store:

```php
Cache::store('redis')->funnel('foo')
    ->limit(3)
    ->block(10)
    ->then(function () {
        // Concurrency lock acquired using the "redis" store...
    });
```

> [!NOTE]
> The `funnel` method requires the cache store to implement the `Hypervel\Contracts\Cache\LockProvider` interface. If you attempt to use `funnel` with a cache store that does not support locks, a `BadMethodCallException` will be thrown.

<a name="adding-custom-cache-drivers"></a>
## Adding Custom Cache Drivers

<a name="writing-the-driver"></a>
### Writing the Driver

To create our custom cache driver, we first need to implement the `Hypervel\Contracts\Cache\Store` [contract](/docs/{{version}}/contracts). So, a custom cache implementation might look something like this:

```php
<?php

namespace App\Extensions;

use Hypervel\Contracts\Cache\Store;

class CustomStore implements Store
{
    public function get(string $key): mixed {}
    public function many(array $keys): array {}
    public function put(string $key, mixed $value, int $seconds): bool {}
    public function putMany(array $values, int $seconds): bool {}
    public function increment(string $key, int $value = 1): bool|int {}
    public function decrement(string $key, int $value = 1): bool|int {}
    public function forever(string $key, mixed $value): bool {}
    public function touch(string $key, int $seconds): bool {}
    public function forget(string $key): bool {}
    public function flush(): bool {}
    public function getPrefix(): string {}
}
```

We just need to implement each of these methods using your underlying cache backend. For an example of how to implement each of these methods, take a look at the `Hypervel\Cache\RedisStore` in the [Hypervel framework source code](https://github.com/hypervel/components/blob/main/src/cache/src/RedisStore.php). Once our implementation is complete, we can finish our custom driver registration by calling the `Cache` facade's `extend` method:

```php
Cache::extend('custom', function (Application $app, array $config) {
    return Cache::repository(new CustomStore);
});
```

> [!NOTE]
> If you're wondering where to put your custom cache driver code, you could create an `Extensions` namespace within your `app` directory. However, keep in mind that Hypervel does not have a rigid application structure and you are free to organize your application according to your preferences.

<a name="registering-the-driver"></a>
### Registering the Driver

To register the custom cache driver with Hypervel, we will use the `extend` method on the `Cache` facade. Since other service providers may attempt to read cached values within their `boot` method, we will register our custom driver within a `booting` callback. By using the `booting` callback, we can ensure that the custom driver is registered just before the `boot` method is called on our application's service providers but after the `register` method is called on all of the service providers. We will register our `booting` callback within the `register` method of our application's `App\Providers\AppServiceProvider` class:

```php
<?php

namespace App\Providers;

use App\Extensions\CustomStore;
use Hypervel\Contracts\Foundation\Application;
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
            Cache::extend('custom', function (Application $app, array $config) {
                return Cache::repository(new CustomStore);
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

The first argument passed to the `extend` method is the name of the driver. This will correspond to your `driver` option in the `config/cache.php` configuration file. The second argument is a closure that should return a `Hypervel\Cache\Repository` instance. The closure will be passed an `$app` instance, which is an instance of the [service container](/docs/{{version}}/container), and the cache store's configuration array.

Once your extension is registered, update the `CACHE_STORE` environment variable or `default` option within your application's `config/cache.php` configuration file to the name of your extension.

<a name="console-commands"></a>
## Console Commands

Hypervel includes several Artisan commands for working with cache stores:

<div class="overflow-auto">

| Command | Purpose |
| --- | --- |
| `cache:clear [store]` | Flush a cache store. |
| `cache:clear [store] --tags=tag-a,tag-b` | Flush tagged cache entries for a store that supports tags. |
| `cache:clear [store] --locks` | Flush cache locks for a store that supports lock flushing. |
| `cache:forget {key} [store]` | Forget a single cache key. |
| `cache:prune-db-expired [store]` | Prune expired rows from a database cache store. |
| `cache:prune-stale-tags [store]` | Prune stale Redis tag index entries. |
| `cache:redis-doctor [--store=]` | Run diagnostic checks against a Redis cache store. |
| `cache:redis-benchmark [--store=]` | Benchmark Redis cache scenarios, including tag modes, bulk operations, and reads. |
| `make:cache-table` | Create the `cache` and `cache_locks` table migrations. |
| `cache:table` | Alias of `make:cache-table`. |

</div>

<a name="events"></a>
## Events

To execute code on every cache operation, you may listen for various [events](/docs/{{version}}/events) dispatched by the cache:

<div class="overflow-auto">

| Event Name                                      |
|-------------------------------------------------|
| `Hypervel\Cache\Events\CacheFailedOver`       |
| `Hypervel\Cache\Events\CacheFlushed`          |
| `Hypervel\Cache\Events\CacheFlushing`         |
| `Hypervel\Cache\Events\CacheFlushFailed`      |
| `Hypervel\Cache\Events\CacheLocksFlushed`     |
| `Hypervel\Cache\Events\CacheLocksFlushing`    |
| `Hypervel\Cache\Events\CacheLocksFlushFailed` |
| `Hypervel\Cache\Events\CacheHit`              |
| `Hypervel\Cache\Events\CacheMissed`           |
| `Hypervel\Cache\Events\ForgettingKey`         |
| `Hypervel\Cache\Events\KeyForgetFailed`       |
| `Hypervel\Cache\Events\KeyForgotten`          |
| `Hypervel\Cache\Events\KeyWriteFailed`        |
| `Hypervel\Cache\Events\KeyWritten`            |
| `Hypervel\Cache\Events\RetrievingKey`         |
| `Hypervel\Cache\Events\RetrievingManyKeys`    |
| `Hypervel\Cache\Events\WritingKey`            |
| `Hypervel\Cache\Events\WritingManyKeys`       |

</div>

To increase performance, you may disable cache events by setting the `events` configuration option to `false` for a given cache store in your application's `config/cache.php` configuration file:

```php
'database' => [
    'driver' => 'database',
    // ...
    'events' => false,
],
```
