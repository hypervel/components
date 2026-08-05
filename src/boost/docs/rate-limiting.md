# Rate Limiting

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Available Stores](#available-stores)
    - [Database Store](#database-store)
    - [Swoole Store](#swoole-store)
- [Defining Policies](#defining-policies)
    - [Fixed Windows](#fixed-windows)
    - [Leaky Buckets](#leaky-buckets)
    - [Weighted Operations](#weighted-operations)
    - [Unlimited Policies](#unlimited-policies)
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
- custom stores registered through Hypervel's familiar manager extension API.

Every rate limit operation returns a result containing whether the operation was allowed, its remaining capacity, and any retry or reset delay. Your application does not need to perform another store lookup after consuming capacity.

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

The `prefix` keeps limiter state separate when multiple applications use the same backend. Hypervel includes this value when generating its hashed limiter keys.

<a name="available-stores"></a>
### Available Stores

Hypervel includes four rate limiter stores:

| Store | Scope | Recommended Use |
|---|---|---|
| `redis` | Shared across application servers | High-throughput distributed rate limiting |
| `database` | Shared across application servers | Distributed rate limiting without requiring Redis |
| `swoole` | Workers belonging to one Swoole server instance | Very high-throughput local limiting |
| `worker-array` | One worker process | Tests and deliberately worker-local workloads |

The Redis store performs each rate limit operation using one pooled connection checkout and one cached Lua script. The database store uses transactions and row locks, making it a portable choice when Redis is not available, though it does not offer the same throughput as Redis.

The Swoole store keeps native integer state in shared memory. It is shared by workers forked from the same server master, but it is not shared by independent Hypervel server instances or different machines. The `worker-array` store is not shared between workers at all. It does not prune entries in the background, so an expired entry remains until the same key is updated or cleared, or the worker restarts. Reverb clears its per-connection message limit when the connection closes.

<a name="database-store"></a>
### Database Store

The database store uses a dedicated `rate_limits` table. Fresh Hypervel applications include this migration by default. Existing applications may generate it using the `make:rate-limiter-table` command:

```shell
php artisan make:rate-limiter-table

php artisan migrate
```

The `rate-limiter:table` command is also available as an alias.

Do not change database rate limit state while the store's selected connection is already inside a transaction. This restriction applies to consuming capacity, recording failures, clearing state, and pruning expired rows. Hypervel will throw a `LogicException` when one of these operations is called inside an active transaction.

If your application must rate limit from inside a transaction, configure the rate limiter store to use a separate named database connection through its `connection` option. The connection may use the same database server or a dedicated rate limiter database. Run the `rate_limits` migration on every connection used by a database rate limiter store.

PostgreSQL limiter connections must use the default `READ COMMITTED` transaction isolation level. MySQL and MariaDB's default `REPEATABLE READ` isolation level is supported.

The `inspect` method remains available inside a transaction because it does not change rate limit state. However, when using `REPEATABLE READ` with MySQL or MariaDB, it reads the outer transaction's snapshot and may not include changes committed after that transaction began.

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

Size the table for the peak number of concurrently active physical limiter keys, plus headroom. A key remains active for its fixed window, leaky-bucket refill period, or backoff inactivity period. For example, if up to 40,000 client IP addresses may have active one-minute limits at once, configure substantially more than 40,000 rows.

Swoole rounds `rows` up to a power of two with a minimum of 64 and allocates an additional collision area based on `conflict_proportion`. Hash collisions may exhaust that collision area before the table's total row count reaches its configured size. Hypervel logs a warning when table or collision pressure enters the configured `memory_limit_buffer`, and fails closed if a live entry cannot be allocated. It never evicts active limiter state because doing so could admit excess traffic.

Expired rows are pruned by worker zero at the configured `prune_interval`, in seconds. A mutating operation also replaces expired state for its key. Inspection treats expired state as empty without changing the table.

<a name="defining-policies"></a>
## Defining Policies

Rate limit policies are immutable. Methods such as `by`, `cost`, and `burst` return a new policy without changing the original, so policies may be safely reused by long-running workers.

Use the `by` method to scope a policy to a user, tenant, IP address, or any other stable identifier:

```php
use Hypervel\RateLimiter\Limit;

$policy = Limit::perMinute(60)->by('user:'.$user->id);
```

String-backed and integer-backed enums, strings, integers, stringable objects, and `null` are accepted as keys. A `null` key represents the same shared policy as an empty string.

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

Each factory also accepts a duration multiplier. For example, the following policy allows 120 operations during a two-minute window:

```php
$policy = Limit::perMinute(120, decayMinutes: 2);
```

A denied operation does not consume capacity or extend the active window.

<a name="leaky-buckets"></a>
### Leaky Buckets

The `LeakyBucket` policy replenishes capacity continuously instead of resetting all capacity at one window boundary. Hypervel implements this behavior using the Generic Cell Rate Algorithm, which requires only one timestamp per limiter key.

```php
use Hypervel\RateLimiter\LeakyBucket;

$policy = LeakyBucket::perSecond(100)
    ->burst(200)
    ->by('api-token:'.$token->id);
```

This policy sustains 100 operations per second while allowing an initial burst of up to 200 operations. The burst value is the total immediately available capacity, not additional capacity beyond the configured rate.

If `burst` is omitted, it defaults to the rate supplied to the factory. To strictly smooth a policy to one immediately available operation, explicitly use `burst(1)`:

```php
$policy = LeakyBucket::perSecond(100)->burst(1);
```

The same `perMinute`, `perMinutes`, `perHour`, and `perDay` factories available on `Limit` are also available on `LeakyBucket`.

<a name="weighted-operations"></a>
### Weighted Operations

By default, an operation consumes one unit of capacity. Use `cost` when some operations should consume more:

```php
$policy = Limit::perMinute(100)
    ->cost(5)
    ->by('uploads:'.$user->id);
```

The cost may not exceed the fixed-window capacity or leaky-bucket burst capacity. A denied weighted operation leaves the current capacity unchanged.

<a name="unlimited-policies"></a>
### Unlimited Policies

Use `Limit::none()` when a named limiter should deliberately allow all operations:

```php
return $user->isAdministrator()
    ? Limit::none()
    : Limit::perMinute(60)->by($user->id);
```

Unlimited policies do not access the configured store.

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
- `retryAfter()`, the minimum whole seconds until this policy's cost may be accepted; and
- `resetAfter()`, the whole seconds until the fixed window expires or the leaky bucket becomes full.

Durations are rounded up, ensuring a caller is never instructed to retry before capacity is actually available.

<a name="inspecting-state"></a>
### Inspecting State

The `inspect` method returns a decision without consuming capacity or creating state:

```php
$result = RateLimiter::inspect($policy);

if ($result->allowed()) {
    // The policy's configured cost is currently available...
}
```

Inspection is useful when your application must decide whether to begin expensive work before recording a separate event. Because another request may consume capacity immediately afterward, you should not use `inspect` followed by `consume` as a replacement for a single atomic `consume` call.

<a name="attempting-operations"></a>
### Attempting Operations

The `attempt` method consumes capacity before executing a callback. It returns `false` when the policy is denied; otherwise, it returns the callback result. A `null` callback result is converted to `true`:

```php
$executed = RateLimiter::attempt($policy, function () use ($message): void {
    $message->send();
});

if ($executed === false) {
    return 'Too many messages sent!';
}
```

The accepted capacity remains consumed if the callback throws an exception. This preserves the atomic admission decision and avoids allowing repeated failing work for free.

<a name="clearing-state"></a>
### Clearing State

The `clear` method removes the state addressed by a policy:

```php
RateLimiter::clear(
    Limit::perMinute(5)->by('send-message:'.$user->id),
);
```

Policy type and stable parameters are part of the stored identity. Therefore, `clear` must receive the same policy type, capacity, window or refill settings, key, and global scope that created the state. Changing policy parameters intentionally starts fresh state while the old entry expires naturally.

Callbacks and operation cost are not part of the stable policy identity. This allows the same bucket to charge operations with different costs.

<a name="selecting-a-store"></a>
### Selecting a Store

Use `store` to perform an operation against a configured store other than the default:

```php
$result = RateLimiter::store('redis')->consume($policy);
```

The store name may also be an enum. You should configure the default store during application boot instead of changing it during a request, since the configured default is shared by the entire worker.

<a name="exponential-backoff"></a>
## Exponential Backoff

An exponential backoff policy tracks failures rather than admitted requests. This makes it suitable for authentication failures or unstable external services:

```php
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

You should register named limiters during application boot because their definitions are shared for the lifetime of the worker. Named limiters may be used by routing and queue middleware. The routing documentation covers [attaching named limiters to routes](/docs/{{version}}/routing#attaching-rate-limiters-to-routes), response callbacks, global policies, and stacked policies.

<a name="custom-stores"></a>
## Custom Stores

Custom drivers implement `Hypervel\RateLimiter\Contracts\Store`. Register the driver from a service provider's `boot` method using the manager's `extend` method:

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

The manager also passes the requested store name to the driver callback as `$config['name']`. This value is set by the manager and replaces any `name` entry in the store configuration.

A store receives a validated policy and a fixed-length key. It must implement `consume` atomically, provide a non-mutating `inspect` operation, record backoff failures, and clear keyed state. Custom stores should follow the same decision and timing semantics as Hypervel's built-in stores.

<a name="failure-behavior"></a>
## Failure Behavior

Rate limiting fails closed. Backend, connection pool, script, table allocation, and database errors are thrown instead of silently allowing the operation or falling back to a worker-local store.

Choose and operate the store according to the availability requirements of the protected operation. Hypervel never changes to a weaker store automatically because doing so would produce different limits on different workers or application servers.
