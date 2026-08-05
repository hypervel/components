# Rate Limiting

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Available Stores](#available-stores)
    - [Database Store](#database-store)
    - [Swoole Store](#swoole-store)
- [Defining Rate Limits](#defining-rate-limits)
    - [Fixed Windows](#fixed-windows)
    - [Leaky Buckets](#leaky-buckets)
    - [Weighted Operations](#weighted-operations)
    - [Unlimited](#unlimited)
- [Using the Rate Limiter](#using-the-rate-limiter)
    - [Consuming Capacity](#consuming-capacity)
    - [Inspecting State](#inspecting-state)
    - [Attempting Operations](#attempting-operations)
    - [Clearing State](#clearing-state)
    - [Selecting a Store](#selecting-a-store)
- [Exponential Backoff](#exponential-backoff)
- [Named Rate Limiters](#named-rate-limiters)
- [Custom Stores](#custom-stores)
- [Failure Behavior](#failure-behavior)

<a name="introduction"></a>
## Introduction

Hypervel includes a powerful rate limiter that you may use to limit HTTP routes, queued jobs, authentication attempts, external API calls, and other operations. Rate limit state is stored using dedicated, atomic operations instead of Hypervel's general-purpose cache.

The rate limiter supports:

- fixed-window limits;
- continuously replenishing leaky buckets;
- weighted operations;
- capped exponential failure backoff;
- Redis, Swoole, database, and worker-local array stores; and
- custom rate limiter stores.

After consuming capacity, Hypervel returns the decision, remaining capacity, and retry or reset delay. Your application does not need to query the store again.

> [!NOTE]
> If you are limiting incoming HTTP requests, consult the [routing rate limiter documentation](/docs/{{version}}/routing#rate-limiting). For queued jobs, consult the [queue middleware documentation](/docs/{{version}}/queues#rate-limiting).

<a name="configuration"></a>
## Configuration

The default rate limiter configuration is stored in your application's `config/rate-limiter.php` file:

```php
return [
    'default' => env('RATE_LIMITER_STORE', 'database'),

    'stores' => [
        'database' => [
            'driver' => 'database',
            'connection' => env('RATE_LIMITER_DB_CONNECTION'),
            'table' => env('RATE_LIMITER_DB_TABLE', 'rate_limits'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('RATE_LIMITER_REDIS_CONNECTION', 'default'),
        ],

        'swoole' => [
            'driver' => 'swoole',
            'rows' => (int) env('RATE_LIMITER_SWOOLE_ROWS', 65536),
            'conflict_proportion' => 0.2,
            'memory_limit_buffer' => 0.05,
            'prune_interval' => 60,
        ],

        'worker-array' => [
            'driver' => 'worker-array',
        ],
    ],

    'prefix' => env('RATE_LIMITER_PREFIX', app_id() . '_rate_limiter'),
];
```

The `prefix` keeps rate limit state separate when multiple applications use the same backend. Hypervel includes this value when generating its hashed keys.

<a name="available-stores"></a>
### Available Stores

Hypervel includes four rate limiter stores:

| Store | Scope | Recommended Use |
|---|---|---|
| `redis` | Shared across application servers | High-throughput distributed rate limiting |
| `database` | Shared across application servers | Distributed rate limiting without requiring Redis |
| `swoole` | Workers belonging to one Swoole server instance | Very high-throughput local limiting |
| `worker-array` | One worker process | Tests and deliberately worker-local workloads |

The Redis store evaluates each fixed-window, leaky-bucket, and backoff decision atomically in a single cached Lua script, using one pooled connection checkout per operation. The database store uses transactions and row locks. It is a portable shared option when Redis is not available, but does not offer the same throughput.

The Swoole store keeps native integer state in shared memory. It is shared by workers forked from the same server master, but not by independent Hypervel server instances or different machines. The `worker-array` store is limited to one worker. It does not prune entries in the background, so an expired entry remains until its key is updated or cleared, or the worker restarts.

<a name="database-store"></a>
### Database Store

The database store uses a dedicated `rate_limits` table. Fresh Hypervel applications include this migration by default. Existing applications may generate it using the `make:rate-limiter-table` command:

```shell
php artisan make:rate-limiter-table

php artisan migrate
```

The `rate-limiter:table` command is also available as an alias.

> [!WARNING]
> You may not consume capacity, record failures, clear state, or prune expired rows while the selected database connection is already inside a transaction. Hypervel will throw a `LogicException` if you attempt to do so.

If your application needs to rate limit while another connection is inside a transaction, configure a separate named connection using the store's `connection` option. The connection may use the same database server or a dedicated rate limiter database. Run the `rate_limits` migration on every connection used by a database rate limiter store.

PostgreSQL limiter connections must use the default `READ COMMITTED` transaction isolation level. MySQL and MariaDB's default `REPEATABLE READ` isolation level is supported.

> [!NOTE]
> The `inspect` method remains available inside a transaction because it does not change rate limit state. Under MySQL or MariaDB's `REPEATABLE READ` isolation, it reads the outer transaction's snapshot and may not include changes committed after the transaction began.

Expired database rows should be pruned periodically. You may schedule the prune command to run hourly:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('rate-limiter:prune')->hourly();
```

You may provide a store name and batch size when necessary:

```shell
php artisan rate-limiter:prune database --chunk=2000
```

<a name="swoole-store"></a>
### Swoole Store

The Swoole store allocates its table before server workers are forked. Changes to its table settings therefore require a server restart.

Set `rows` higher than the greatest number of rate limit keys that may be active at once. A key remains active for its fixed window, leaky-bucket refill time, or backoff inactivity time. For example, if up to 40,000 client IP addresses may have active one-minute limits at once, configure substantially more than 40,000 rows.

Swoole rounds `rows` up to a power of two with a minimum of 64 and allocates an additional collision area based on `conflict_proportion`. Hash collisions may exhaust that collision area before the table's total row count reaches its configured size. Hypervel logs a warning when table or collision pressure enters the configured `memory_limit_buffer`, and throws `Hypervel\RateLimiter\Exceptions\SwooleTableFullException` if a live entry cannot be allocated. It never evicts active limiter state because doing so could admit excess traffic.

Worker zero prunes expired rows at the configured `prune_interval`, in seconds. Consuming capacity, recording a failure, or clearing a key also replaces or removes expired state for that key. Inspection treats expired state as empty without changing the table.

<a name="defining-rate-limits"></a>
## Defining Rate Limits

Rate limits are immutable. Methods such as `by`, `cost`, and `burst` return a new rate limit without changing the original, so you may safely reuse them in long-running workers.

Use the `by` method to scope a rate limit to a user, tenant, IP address, or any other stable identifier:

```php
use Hypervel\RateLimiter\Limit;

$limit = Limit::perMinute(60)->by('user:'.$user->id);
```

Enums, strings, integers, stringable objects, and `null` are accepted as keys. Backed enums use their values, while unit enums use their case names. A `null` key represents the same shared rate limit as an empty string.

Invalid rate limit settings throw `Hypervel\RateLimiter\Exceptions\InvalidRateLimitException` before the store is changed.

<a name="fixed-windows"></a>
### Fixed Windows

The `Limit` class defines a fixed window whose timer begins with its first accepted operation:

```php
use Hypervel\RateLimiter\Limit;

$perSecond = Limit::perSecond(10);
$perMinute = Limit::perMinute(60);
$perFiveMinutes = Limit::perMinutes(5, 300);
$perHour = Limit::perHour(1000);
$perDay = Limit::perDay(10_000);
```

Each factory also accepts a duration multiplier. For example, the following rate limit allows 120 operations during a two-minute window:

```php
$limit = Limit::perMinute(120, decayMinutes: 2);
```

A denied operation does not consume capacity or extend the active window.

<a name="leaky-buckets"></a>
### Leaky Buckets

The `LeakyBucket` class replenishes capacity continuously instead of resetting all capacity at one window boundary. Hypervel implements this leaky-bucket behavior using the Generic Cell Rate Algorithm (GCRA).

```php
use Hypervel\RateLimiter\LeakyBucket;

$limit = LeakyBucket::perSecond(100)
    ->burst(200)
    ->by('api-token:'.$token->id);
```

This rate limit sustains 100 operations per second while allowing an initial burst of up to 200 operations. The burst value is the total immediately available capacity, not additional capacity beyond the configured rate.

If `burst` is omitted, it defaults to the rate supplied to the factory. To keep only one operation immediately available at a time, use `burst(1)`:

```php
$limit = LeakyBucket::perSecond(100)->burst(1);
```

The same `perMinute`, `perMinutes`, `perHour`, and `perDay` factories available on `Limit` are also available on `LeakyBucket`.

<a name="weighted-operations"></a>
### Weighted Operations

By default, an operation consumes one unit of capacity. Use `cost` when some operations should consume more:

```php
$limit = Limit::perMinute(100)
    ->cost(5)
    ->by('uploads:'.$user->id);
```

The cost may not exceed the fixed-window capacity or leaky-bucket burst capacity. A denied weighted operation leaves the current capacity unchanged.

<a name="unlimited"></a>
### Unlimited

Use `Limit::none()` when a named limiter should deliberately allow all operations:

```php
return $user->isAdministrator()
    ? Limit::none()
    : Limit::perMinute(60)->by($user->id);
```

Unlimited rate limits do not access the configured store.

<a name="using-the-rate-limiter"></a>
## Using the Rate Limiter

You may interact with the rate limiter using the `Hypervel\Support\Facades\RateLimiter` facade. By default, operations use the store configured by the `default` option. You may also inject `Hypervel\RateLimiter\RateLimiter` into your classes.

<a name="consuming-capacity"></a>
### Consuming Capacity

The `consume` method atomically decides whether the requested capacity is available and, when allowed, commits the operation:

```php
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\RateLimiter;

$result = RateLimiter::consume(
    Limit::perMinute(5)->by('send-message:'.$user->id),
);

if ($result->denied()) {
    return 'Try again in '.$result->retryAfter().' seconds.';
}
```

A `LimitResult` provides:

- `allowed()` and `denied()`;
- `limit()`, the fixed-window capacity or leaky-bucket burst capacity;
- `remaining()`, the whole capacity immediately available after the decision;
- `retryAfter()`, the minimum whole seconds until the same cost may be accepted; and
- `resetAfter()`, the whole seconds until the fixed window expires or the leaky bucket becomes full.

Durations are rounded up, ensuring a caller is never instructed to retry before capacity is actually available.

<a name="inspecting-state"></a>
### Inspecting State

The `inspect` method returns a decision without consuming capacity or creating state:

```php
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\RateLimiter;

$limit = Limit::perMinute(5)->by('send-message:'.$user->id);

$result = RateLimiter::inspect($limit);

if ($result->allowed()) {
    // The requested capacity is currently available...
}
```

Inspection is useful when your application must decide whether to begin expensive work before recording a separate event. Because another request may consume capacity immediately afterward, you should not use `inspect` followed by `consume` as a replacement for a single atomic `consume` call.

<a name="attempting-operations"></a>
### Attempting Operations

The `attempt` method consumes capacity before executing a callback. It returns `false` when the rate limit is denied; otherwise, it returns the callback result. A `null` callback result is converted to `true`:

```php
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\RateLimiter;

$limit = Limit::perMinute(5)->by('send-message:'.$user->id);

$executed = RateLimiter::attempt($limit, function () use ($message): void {
    $message->send();
});

if ($executed === false) {
    return 'Too many messages sent!';
}
```

The accepted capacity remains consumed if the callback throws an exception. This preserves the atomic admission decision and avoids allowing repeated failing work for free.

<a name="clearing-state"></a>
### Clearing State

The `clear` method removes the state for a rate limit:

```php
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\RateLimiter;

$limit = Limit::perMinute(5)->by('send-message:'.$user->id);

RateLimiter::clear($limit);
```

To clear existing state, use the same rate limit type, capacity, window or refill settings, key, and global scope that created it. Changing any of these settings starts fresh state while the old entry expires naturally.

Callbacks and operation cost do not change the stored identity. This allows the same rate limit to charge operations with different costs.

<a name="selecting-a-store"></a>
### Selecting a Store

Use `store` to perform an operation against a configured store other than the default:

```php
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\RateLimiter;

$limit = Limit::perMinute(5)->by('send-message:'.$user->id);

$result = RateLimiter::store('redis')->consume($limit);
```

The store name may also be an enum. You should configure the default store during application boot instead of changing it during a request, since the configured default is shared by the entire worker.

<a name="exponential-backoff"></a>
## Exponential Backoff

Exponential backoff tracks failures rather than admitted requests. This makes it suitable for authentication failures or unstable external services:

```php
use Hypervel\Auth\AuthenticationException;
use Hypervel\RateLimiter\Backoff;
use Hypervel\Support\Facades\RateLimiter;

$backoff = Backoff::exponential(
    after: 5,
    initialDelay: 1,
    maxDelay: 300,
    resetAfter: 3600,
)->by('login:'.$username.':'.$request->ip());

$decision = RateLimiter::inspect($backoff);

if ($decision->denied()) {
    return 'Try again in '.$decision->retryAfter().' seconds.';
}

try {
    $this->authenticate($request);
    RateLimiter::clear($backoff);
} catch (AuthenticationException $exception) {
    RateLimiter::recordFailure($backoff);

    throw $exception;
}
```

The failure identified by `after` creates the initial delay. Every subsequent recorded failure doubles that delay until `maxDelay` is reached. If no failure is recorded during `resetAfter`, the history expires. A successful operation should call `clear`.

The returned `BackoffResult` provides `allowed()`, `denied()`, `failures()`, and `retryAfter()`.

<a name="named-rate-limiters"></a>
## Named Rate Limiters

Named rate limiters are registered with the facade's `for` method and may select their own store:

```php
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\Support\Facades\RateLimiter;

RateLimiter::for('api', function ($request) {
    return LeakyBucket::perSecond(100)
        ->burst(200)
        ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
}, store: 'redis');
```

You should register named limiters during application boot because their definitions are shared for the lifetime of the worker. Named limiters may be used by routing and queue middleware. The routing documentation covers [attaching named limiters to routes](/docs/{{version}}/routing#attaching-rate-limiters-to-routes), response callbacks, global rate limits, and stacked rate limits.

<a name="custom-stores"></a>
## Custom Stores

Custom drivers implement `Hypervel\RateLimiter\Contracts\Store`. The contract contains the following methods:

```php
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\BackoffResult;
use Hypervel\RateLimiter\LimitResult;

interface Store
{
    public function consume(string $key, AdmissionPolicy $policy): LimitResult;

    public function inspect(
        string $key,
        AdmissionPolicy|Backoff $policy,
    ): LimitResult|BackoffResult;

    public function recordFailure(string $key, Backoff $backoff): BackoffResult;

    public function clear(string $key): bool;
}
```

A custom store receives validated `Limit` and `LeakyBucket` objects through the `AdmissionPolicy` type, while backoff operations receive a `Backoff` instance. The `$key` has already been hashed to a fixed length. The `consume` method must check and consume capacity atomically, while `inspect` must not change state. The `recordFailure` method updates backoff state, and `clear` removes state for a key. Custom stores should return the same decisions and timing values as Hypervel's built-in stores.

If your custom store retains expired state, it may also implement `Hypervel\RateLimiter\Contracts\PrunableStore` so it can be targeted by the `rate-limiter:prune` command.

The `PrunableStore` contract contains one method:

```php
interface PrunableStore
{
    public function pruneExpired(int $chunkSize = 1000): int;
}
```

You may register a custom driver from a service provider's `boot` method using the manager's `extend` method:

```php
use Hypervel\Contracts\Foundation\Application;
use Hypervel\RateLimiter\Contracts\Store;
use Hypervel\RateLimiter\RateLimiter;

public function boot(RateLimiter $rateLimiter): void
{
    $rateLimiter->extend('custom', function (Application $app, array $config): Store {
        return new CustomRateLimiterStore(/* ... */);
    });
}
```

Then add the driver to `rate-limiter.stores`:

```php
'custom' => [
    'driver' => 'custom',
],
```

The manager also passes the requested store name to the driver callback as `$config['name']`. This value replaces any `name` entry in the store configuration.

<a name="failure-behavior"></a>
## Failure Behavior

If the configured store fails, Hypervel throws an exception. It never silently allows the operation or switches to another store.

Choose a store that provides the availability and sharing your application requires. Switching stores automatically would produce different limits on different workers or application servers.
