# Porting from Laravel

- [Introduction](#introduction)
- [Why Laravel Code Needs Porting](#why-laravel-code-needs-porting)
- [Porting Workflow](#porting-workflow)
- [Namespaces and Dependencies](#namespaces-and-dependencies)
    - [Composer Dependencies](#composer-dependencies)
    - [Common Namespace Replacements](#common-namespace-replacements)
    - [Contracts](#contracts)
    - [Missing Equivalents](#missing-equivalents)
- [Type Declarations](#type-declarations)
    - [Inherited Properties](#inherited-properties)
- [Service Providers](#service-providers)
    - [Registering Bindings](#registering-bindings)
    - [Bootstrapping Services](#bootstrapping-services)
    - [Conditional Providers](#conditional-providers)
    - [Deferred Providers](#deferred-providers)
- [Coroutine Safety](#coroutine-safety)
    - [Request-Specific State](#request-specific-state)
    - [Worker-Lifetime State](#worker-lifetime-state)
    - [Container Lifecycles](#container-lifecycles)
    - [Coroutine-Aware Dependencies](#coroutine-aware-dependencies)
- [Configuration](#configuration)
- [Other API Differences](#other-api-differences)
    - [HTTP Client and Concurrency](#http-client-and-concurrency)
    - [Scout](#scout)
    - [Rate Limiting](#rate-limiting)
    - [Pagination](#pagination)
    - [Dates](#dates)
    - [UUIDs](#uuids)
    - [Filesystem](#filesystem)
- [Database, Cache, Sessions, and Queues](#database-cache-sessions-and-queues)
    - [Database](#database)
    - [Redis](#redis)
    - [Cache](#cache)
    - [Sessions](#sessions)
    - [Queues](#queues)
- [Testing Ports](#testing-ports)
    - [Application Tests](#application-tests)
    - [Package Tests](#package-tests)
    - [Testing Coroutine Isolation](#testing-coroutine-isolation)
- [Porting Applications](#porting-applications)
- [Porting Packages](#porting-packages)
- [Porting Checklist](#porting-checklist)

<a name="introduction"></a>
## Introduction

Hypervel is an independent, opinionated Swoole framework. It aims for Laravel API compatibility whenever those APIs fit its coroutine-first architecture, but it is not a Laravel port or a drop-in replacement. Hypervel deliberately differs in its runtime, service lifecycles, supported drivers, package structure, and some public APIs.

Laravel code is often straightforward to port, but it should not be copied into a Hypervel application or package without review. This guide explains how to port Laravel application code and Laravel packages to Hypervel. It focuses on the parts that usually matter during a port: dependencies, namespaces, service providers, configuration, tests, and coroutine safety.

Do not use a complete class-by-class diff between Laravel and Hypervel as a migration plan. Begin with the code you are actually porting, identify the framework features and integrations it uses, and verify each of those against the documentation and source for your target Hypervel version.

Hypervel actively monitors upstream Laravel changes and ports compatible additions when they make sense for Hypervel's architecture. If an application or package depends on a recent Laravel API that is not available, verify the current source and raise the concrete use case with the maintainers rather than assuming that every difference is permanent or accidental.

If you are building a new Hypervel package from scratch, you should also read the [package development documentation](/docs/{{version}}/packages). If you are testing a package, read the [Testbench documentation](/docs/{{version}}/testbench).

<a name="why-laravel-code-needs-porting"></a>
## Why Laravel Code Needs Porting

Traditional Laravel applications commonly run under a request-isolated PHP lifecycle, where each request starts with a fresh application runtime and ends by discarding its in-memory state. Hypervel is designed around long-lived Swoole workers. An HTTP or queue worker keeps the application and its shared services in memory while serving many requests or jobs over its lifetime.

This difference means request-specific state must not be stored on shared objects. For example, consider the shape of Laravel's `SessionGuard`: the guard caches the authenticated user on an instance property so repeated `user()` calls during a single request are fast. In a PHP-FPM request lifecycle, that instance disappears at the end of the request. In Hypervel, a singleton guard may live for the worker lifetime, so the cached user must be isolated per coroutine instead of stored directly on the shared object.

The same issue appears in translators, managers, middleware, repositories, event dispatchers, and any service that stores mutable request-specific data. When porting Laravel code, always ask whether a property is:

- Immutable configuration that can safely live for the worker lifetime.
- Per-request or per-job state that must live in [context](/docs/{{version}}/context) or [coroutine context](/docs/{{version}}/coroutine-context).
- Per-call builder state that should be held by a fresh object.

<a name="porting-workflow"></a>
## Porting Workflow

When porting Laravel code to Hypervel, work through the code in this order:

1. Choose the Hypervel version you are targeting and use the documentation and source for that version.
2. Inventory the Laravel code using the [porting checklist](#porting-checklist). Record its framework APIs, Composer dependencies, service providers, drivers, configuration, long-lived state, external I/O, and tests before changing the code.
3. For an application, create a fresh Hypervel application and move application code into it. For a package, begin by updating its Composer dependencies and package discovery metadata.
4. Replace `Illuminate` imports with verified `Hypervel` equivalents. Confirm that each replacement exists and provides the behavior the code expects.
5. Replace unsupported integrations and APIs with documented Hypervel features, then port service providers and configuration.
6. Review inherited property and method declarations against their Hypervel parents and traits.
7. Review singleton, static, manager, and pooled-resource usage for coroutine safety.
8. Port the relevant tests and add coroutine-isolation tests where needed. Confirm that no `Illuminate` imports remain, then run the test suite and static analysis.

The goal is not to make code look different for its own sake. Keep Laravel behavior and method names where Hypervel supports them. Change the implementation where Hypervel's runtime, supported drivers, public APIs, or package structure requires it.

<a name="namespaces-and-dependencies"></a>
## Namespaces and Dependencies

Most Laravel framework classes map directly from `Illuminate\...` to `Hypervel\...`. For example, `Illuminate\Support\Str` becomes `Hypervel\Support\Str`, and `Illuminate\Support\ServiceProvider` becomes `Hypervel\Support\ServiceProvider`.

When porting imports, update the import list first, then read the class again and verify that each replacement exists and has the behavior the code expects. Some Laravel packages depend on optional Laravel-only packages or drivers that Hypervel does not support.

<a name="composer-dependencies"></a>
### Composer Dependencies

For applications, use the `composer.json` file from a fresh Hypervel application as your starting point. Do not copy a Laravel application's framework dependencies, Composer scripts, or bootstrap files over the Hypervel skeleton.

For packages, replace `laravel/framework` and individual `illuminate/*` requirements with the Hypervel components the package actually uses. Replace `orchestra/testbench` with `hypervel/testbench` for package tests that boot an application, or require `hypervel/testing` for package unit tests that do not. If a third-party dependency requires Laravel or Illuminate components, use a Hypervel-compatible version or port that integration; do not retain Illuminate packages merely to fill missing framework classes.

Laravel package discovery metadata under `extra.laravel` does not register providers in Hypervel. Move Hypervel provider discovery to `extra.hypervel.providers` as described in the [package development documentation](/docs/{{version}}/packages#package-discovery).

<a name="common-namespace-replacements"></a>
### Common Namespace Replacements

The following replacements cover the most common Laravel framework dependencies:

| Laravel | Hypervel |
|---|---|
| `Illuminate\Auth\...` | `Hypervel\Auth\...` |
| `Illuminate\Broadcasting\...` | `Hypervel\Broadcasting\...` |
| `Illuminate\Bus\...` | `Hypervel\Bus\...` |
| `Illuminate\Cache\RateLimiter` | `Hypervel\RateLimiter\RateLimiter` |
| `Illuminate\Cache\RateLimiting\Limit` | `Hypervel\RateLimiter\Limit` |
| `Illuminate\Cache\...` | `Hypervel\Cache\...` |
| `Illuminate\Console\...` | `Hypervel\Console\...` |
| `Illuminate\Container\...` | `Hypervel\Container\...` |
| `Illuminate\Contracts\...` | `Hypervel\Contracts\...` |
| `Illuminate\Cookie\...` | `Hypervel\Cookie\...` |
| `Illuminate\Database\...` | `Hypervel\Database\...` |
| `Illuminate\Encryption\...` | `Hypervel\Encryption\...` |
| `Illuminate\Events\...` | `Hypervel\Events\...` |
| `Illuminate\Filesystem\...` | `Hypervel\Filesystem\...` |
| `Illuminate\Foundation\...` | `Hypervel\Foundation\...` |
| `Illuminate\Http\...` | `Hypervel\Http\...` |
| `Illuminate\Mail\...` | `Hypervel\Mail\...` |
| `Illuminate\Notifications\...` | `Hypervel\Notifications\...` |
| `Illuminate\Pagination\...` | `Hypervel\Pagination\...` |
| `Illuminate\Queue\...` | `Hypervel\Queue\...` |
| `Illuminate\Redis\...` | `Hypervel\Redis\...` |
| `Illuminate\Routing\...` | `Hypervel\Routing\...` |
| `Illuminate\Session\...` | `Hypervel\Session\...` |
| `Illuminate\Support\...` | `Hypervel\Support\...` |
| `Illuminate\Translation\...` | `Hypervel\Translation\...` |
| `Illuminate\Validation\...` | `Hypervel\Validation\...` |
| `Illuminate\View\...` | `Hypervel\View\...` |

The rate limiter is an important exception to the general cache namespace replacement. Its namespace and API are discussed in the [rate limiting](#rate-limiting) section of this guide.

<a name="contracts"></a>
### Contracts

Laravel contracts usually map to `Hypervel\Contracts\...`:

```php
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Support\Arrayable;
```

Some packages also define package-local contracts, such as `Hypervel\Permission\Contracts\Role` or `Hypervel\Scout\Contracts\SearchableInterface`. When porting a package, prefer the contract namespace used by the Hypervel package you are integrating with.

`Hypervel\Contracts\Support\MessageBag` extends `Stringable`, so a custom message bag without `__toString()` fails at class declaration rather than later when `ViewErrorBag` renders it.

<a name="missing-equivalents"></a>
### Missing Equivalents

Not every Laravel class has a one-for-one replacement. If a class or method is absent, first check the relevant Hypervel documentation and current source for the supported approach. If there is no equivalent, remove the integration or raise the concrete use case with the maintainers.

Do not recreate missing Laravel framework internals or add local classes under `Hypervel` namespaces merely to make a mechanical namespace replacement pass. An intentional adapter around a public contract may be appropriate for an application-owned or third-party integration, but it should adapt that integration to Hypervel's documented API instead of imitating missing framework internals.

<a name="type-declarations"></a>
## Type Declarations

Hypervel uses native PHP types more aggressively than Laravel. When porting Laravel code, do not blindly copy PHPDoc types into method signatures. Laravel's docblocks are sometimes broader or narrower than the values the code actually accepts and returns.

For example, a Laravel method may document a parameter as `string` while internal callers can pass `null`. In Hypervel, declaring that parameter as `string` would turn a working code path into a type error. Read the method body, trace its callers, and use the ported tests to confirm the correct type.

Declare strict types at the top of each file and use native parameter, property, and return types wherever the type is known. Keep PHPDoc for useful descriptions, generics, complex array shapes, `@throws` annotations, and cases PHP cannot express natively.

<a name="inherited-properties"></a>
### Inherited Properties

Hypervel parent classes and traits use native property types where Laravel may use PHPDoc. PHP requires a child class or composed trait property to be compatible with the inherited declaration, so copying an untyped Laravel property may cause a fatal error before the application boots.

For example, a Laravel model may declare its table and fillable attributes without native types:

```php
class Post extends Model
{
    protected $table = 'posts';

    protected $fillable = ['title', 'body'];
}
```

The corresponding Hypervel model properties must retain the native types declared by `Hypervel\Database\Eloquent\Model` and its traits:

```php
<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Model;

class Post extends Model
{
    protected ?string $table = 'posts';

    protected array $fillable = ['title', 'body'];
}
```

Common model properties to audit include `$connection`, `$table`, `$primaryKey`, `$keyType`, `$incrementing`, `$perPage`, `$fillable`, `$guarded`, `$casts`, and `$timestamps`.

Artisan command properties require the same review:

```php
<?php

declare(strict_types=1);

use Hypervel\Console\Command;

class SendReportsCommand extends Command
{
    protected ?string $signature = 'reports:send';

    protected string $description = 'Send the pending reports';
}
```

When a ported command accesses the application instance directly, replace Laravel's `$this->laravel`, `getLaravel()`, and `setLaravel()` members with `$this->hypervel`, `getHypervel()`, and `setHypervel()`.

Models and commands are common examples, but they are not an exhaustive list. Audit properties declared by mailables, form requests, queueable jobs, and any other class that extends a Hypervel class or composes a Hypervel trait. Inspect the current parent class and every composed trait before adding or retaining a property declaration.

Some typed properties have no default value. For example, a Hypervel mailable's `$markdown`, `$view`, and `$textView` properties must not be read directly before they have been initialized. Use `isset()` or `??` when testing an optional value, or assign a valid string before reading it.

<a name="service-providers"></a>
## Service Providers

Hypervel service providers use the same public shape as Laravel service providers. A ported service provider should extend `Hypervel\Support\ServiceProvider` and use the same `register` and `boot` separation you would use in Laravel.

```php
<?php

declare(strict_types=1);

namespace Courier;

use Hypervel\Support\ServiceProvider;

class CourierServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/courier.php',
            'courier'
        );

        $this->app->singleton(Courier::class, fn ($app) => new Courier(
            $app->make('config')->array('courier')
        ));
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/courier.php' => config_path('courier.php'),
        ], 'courier-config');
    }
}
```

<a name="registering-bindings"></a>
### Registering Bindings

Use the `register` method for container bindings and configuration merging. Hypervel supports the same public binding methods you expect from Laravel, including `bind`, `singleton`, `scoped`, and the `bindings` / `singletons` provider properties.

However, because Hypervel runs in long-lived workers, make sure you choose the binding lifecycle deliberately:

- Use `singleton` for stateless services or services that hold immutable worker-lifetime configuration. These are cached for the worker's lifetime.
- Use `scoped` for services that should be reused within one request or job coroutine and discarded afterward.
- Use `bind` for builders or mutable objects that should be fresh on each resolution.

<a name="bootstrapping-services"></a>
### Bootstrapping Services

Use the `boot` method for routes, event listeners, commands, publishing, view composers, migrations, and other work that should happen after all providers have been registered.

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

    if ($this->app->runningInConsole()) {
        $this->commands([
            Console\SyncCourierCommand::class,
        ]);
    }
}
```

Do not store request-specific state on service provider properties. When serving HTTP requests, the application registers and boots its providers once before the server workers are forked, not once per request.

<a name="conditional-providers"></a>
### Conditional Providers

Hypervel service providers may override the `isEnabled` method to opt out of registration based on configuration, environment, or feature flags. When this method returns `false`, the provider's `register` and `boot` methods will not be called:

```php
/**
 * Determine whether this provider should be registered and booted.
 */
public function isEnabled(): bool
{
    return config()->boolean('courier.enabled', false);
}
```

Hypervel calls `isEnabled` before the provider's `register` method. Configuration merged by that provider is not available yet, so this method may only read configuration already loaded by the application or framework. The fallback above is intentional because an unpublished package option may be absent.

<a name="deferred-providers"></a>
### Deferred Providers

Laravel's `DeferrableProvider` interface is not useful in Hypervel's long-running worker model. Providers are registered once during application bootstrap and then remain available to the long-lived runtime. When porting a Laravel provider that implements `DeferrableProvider`, remove the interface and the `provides` method.

<a name="coroutine-safety"></a>
## Coroutine Safety

Coroutine safety is the most important part of a Laravel-to-Hypervel port. In Hypervel, multiple requests or jobs may run concurrently inside the same worker process. Any mutable state on a singleton, static property, manager, or service provider can be observed by another coroutine if it is not isolated correctly.

<a name="request-specific-state"></a>
### Request-Specific State

Both [context](/docs/{{version}}/context) and [coroutine context](/docs/{{version}}/coroutine-context) are coroutine-isolated. The `Context` facade is the application-facing API and stores its repository inside `CoroutineContext` under the current coroutine. Use `Context` for metadata that should be available to logs, queued jobs, and other cross-boundary framework features. Use `CoroutineContext` directly for low-level package or framework state that only needs coroutine-local storage.

For example, a Laravel service might store the current locale on an instance property:

```php
class Translator
{
    protected string $locale = 'en';

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }
}
```

If that service is shared for the worker lifetime, the locale can leak between concurrent requests. Store the mutable value in coroutine context instead:

```php
use Hypervel\Context\CoroutineContext;

class Translator
{
    protected string $locale = 'en';

    public function getLocale(): string
    {
        return (string) CoroutineContext::get('__translator.locale', $this->locale);
    }

    public function setLocale(string $locale): void
    {
        CoroutineContext::set('__translator.locale', $locale);
    }
}
```

When the coroutine ends, its context is destroyed with it.

<a name="worker-lifetime-state"></a>
### Worker-Lifetime State

Static properties and singleton object properties persist (are cached) for the worker lifetime. This is a useful performance win for immutable metadata, compiled patterns, reflection results, parsed configuration, and other expensive values that are safe to share. It is unsafe for the current request, current user, current tenant, current locale, current request object, or any other mutable per-request data.

If a public method mutates static or singleton-held state, make sure that state is intended to affect the worker lifetime. If the mutation should only affect one request or job, move the state to context or bind the service as scoped.

<a name="container-lifecycles"></a>
### Container Lifecycles

Hypervel follows Laravel's named container APIs, but intentionally does not support container ArrayAccess or dynamic service properties. Convert those calls while porting:

| Laravel | Hypervel |
|---|---|
| `$app['events']` or `$app->events` | `$app->make('events')` |
| `isset($app['events'])` | `$app->bound('events')` |
| `$app['service'] = fn ($app) => ...` or `$app->service = fn ($app) => ...` | `$app->bind('service', fn ($app) => ...)` |
| `$app['service'] = $service` or `$app->service = $service` | `$app->instance('service', $service)` |
| Remove a temporary instance override | `$app->forgetInstance('service')` |

Use `get()` and `has()` instead when working through the PSR-11 container interface. Hypervel does not expose arbitrary binding removal; `forgetInstance()` clears a temporary instance so the original binding can resolve again.

Container lifecycles are adapted for Swoole:

| Need | Method |
|---|---|
| Fresh instance every call | `bind()` |
| One instance per request or job coroutine | `scoped()` |
| One instance per worker | `singleton()` |
| Fresh instance at the call site | `build()` or `buildWith()` |
| Fresh instance for every unbound resolution of a class hierarchy | Implement `Hypervel\Contracts\Container\Transient` on its base class |
| Resolve using bindings and lifecycle rules | `make()` |

> [!WARNING]
> Unbound concrete classes are automatically cached for the worker lifetime after their first resolution. If an unbound class captures the current user, tenant, request, or other mutable per-request data in its constructor, ordinary tests may pass while concurrent requests receive another request's state. Register the class with `bind()` for a fresh instance, use `scoped()` for one instance per request or job coroutine, construct a fresh instance with `build()`, or implement `Transient` when every subclass must always be fresh. Hypervel's intrinsically fresh model, value, builder, pending-request, pipeline, and response families already implement `Transient`; see [Transient Classes](/docs/{{version}}/container#transient-classes) for the current list and binding behavior.

<a name="coroutine-aware-dependencies"></a>
### Coroutine-Aware Dependencies

Hypervel's framework clients are designed to cooperate with Swoole coroutines, but a third-party library or PHP extension may perform blocking I/O. Swoole can hook many stream-based operations, while an extension that cannot yield will block the entire worker process until its work finishes.

Before porting an integration that performs network, filesystem, subprocess, or other external I/O, verify that its client is safe to use with Swoole's coroutine hooks. Prefer a coroutine-aware client. For unusual work that requires an unhookable extension or full process isolation, use the `process` concurrency driver described in the [concurrency documentation](/docs/{{version}}/concurrency#choosing-a-driver).

Pooled database and Redis resources belong to the coroutine or callback that borrowed them. Do not retain a checked-out low-level connection on a singleton or static property, and do not use it after its owning callback or coroutine ends. Use the manager or facade for each operation and the documented callback APIs, such as `Redis::withConnection()`, when several operations must share one borrowed connection. See [database connection pooling](/docs/{{version}}/database#connection-pooling) and [holding a pooled Redis connection](/docs/{{version}}/redis#holding-a-pooled-connection).

<a name="configuration"></a>
## Configuration

Hypervel configuration is loaded when the application boots. In a running Swoole server, configuration is process-global and shared by every coroutine in the worker. Do not mutate configuration at runtime to represent request-specific values.

For packages, merge default configuration from your service provider:

```php
/**
 * Register any package services.
 */
public function register(): void
{
    $this->mergeConfigFrom(
        __DIR__.'/../config/courier.php',
        'courier'
    );
}
```

If a configuration key contains a collection of named options, such as `connections`, `stores`, or `guards`, you may override `mergeableOptions` so applications can add entries without replacing the whole collection:

```php
/**
 * Get the configuration options that should be merged.
 *
 * @return array<int, string>
 */
protected function mergeableOptions(string $name): array
{
    return $name === 'courier' ? ['connections'] : [];
}
```

Start from Hypervel's shipped configuration files and reapply your application overrides. Fixed nested arrays are complete values. Collections such as `connections`, `stores`, and `guards` merge by entry name, but an application entry replaces the complete framework entry with the same name. Carry over required members; documented optional members may be omitted.

When porting Laravel configuration, pay particular attention to these current differences:

- Hypervel password broker records explicitly declare their `database` or `cache` driver.
- Hypervel's shipped background, deferred, Beanstalkd, SQS, Redis, and failover queues dispatch after commit by default; sync and database do not. A copied Laravel queue config restores Laravel's before-commit behavior. Beanstalkd records also require `port`. See the [queue guide](/docs/{{version}}/queues).
- The scheduling cache store is configured through `cache.schedule_store` and `SCHEDULE_CACHE_STORE`. Laravel's older `SCHEDULE_CACHE_DRIVER` name is not supported.
- Hypervel Socialite's X OAuth 2 driver reads `services.x`. Rename Laravel's legacy `services.x-oauth-2` configuration key when porting an application.

Application code should keep request-specific values in the request, session, context, or coroutine context instead of changing config values while the server is running.

<a name="other-api-differences"></a>
## Other API Differences

Many Laravel APIs have direct Hypervel equivalents under the `Hypervel` namespace. The following differences commonly require more than a namespace replacement.

<a name="http-client-and-concurrency"></a>
### HTTP Client and Concurrency

For concurrent HTTP requests, replace Laravel's `Http::pool` and `Http::batch` patterns with Hypervel's coroutine helpers, typically `parallel` from `Hypervel\Coroutine`. See the [HTTP client documentation](/docs/{{version}}/http-client#concurrent-requests) for examples.

Hypervel's `Concurrency` facade provides `coroutine`, `process`, and `sync` drivers. Laravel's `fork` driver is not available because coroutines are Hypervel's native lightweight execution model. Use the default `coroutine` driver for normal concurrent application work and reserve `process` for work that requires operating system process isolation. See the [concurrency documentation](/docs/{{version}}/concurrency#choosing-a-driver).

<a name="scout"></a>
### Scout

Hypervel compiles integer and float values passed to Scout's Algolia `where`, `whereIn`, and `whereNotIn` methods as numeric comparisons. Numeric-looking strings remain facet values. When porting an Algolia index, ensure the indexed attribute type matches the PHP value type used by these filters.

<a name="json-schema"></a>
### JSON Schema

When porting schemas that place sibling assertions beside a local `$ref` or use nullable composition, make overlapping assertions identical. Hypervel rejects conflicts instead of silently replacing referenced constraints. See the [JSON Schema documentation](/docs/{{version}}/json-schema#reconstructing-schemas).

<a name="rate-limiting"></a>
### Rate Limiting

Laravel's `Illuminate\Cache\RateLimiter` maps to `Hypervel\RateLimiter\RateLimiter`, not `Hypervel\Cache\RateLimiter`. Likewise, `Illuminate\Cache\RateLimiting\Limit` becomes `Hypervel\RateLimiter\Limit`. Two-argument calls to `RateLimiter::for($name, $callback)` port unchanged, including named route and queue limiters.

The lower-level API is intentionally different. Hypervel uses admission policies such as `Limit`, `SlidingWindow`, and `LeakyBucket` with operations including `consume`, `inspect`, `attempt`, and `clear`. Laravel's counter methods, including `tooManyAttempts`, `hit`, `remaining`, `availableIn`, `resetAttempts`, and `retriesLeft`, are not available. Although `attempt` and `clear` exist in both frameworks, their signatures and behavior differ; do not port those calls by name alone.

When porting custom throttling code, rebuild it using Hypervel's policy API described in the [rate limiting documentation](/docs/{{version}}/rate-limiting). Replace Laravel's `RateLimitedWithRedis` and `ThrottlesExceptionsWithRedis` queue middleware with `Hypervel\Queue\Middleware\RateLimited` or `ThrottlesExceptions` and select the Redis limiter store using `store('redis')`.

<a name="pagination"></a>
### Pagination

Hypervel ships Tailwind pagination views. The `Paginator::useBootstrap()`, `useBootstrapFour()`, and `useBootstrapFive()` methods are not available, so remove those calls from ported service providers. If the application does not use Tailwind, publish or create pagination views and select them using `Paginator::defaultView()` and `defaultSimpleView()`. See the [pagination documentation](/docs/{{version}}/pagination#customizing-the-pagination-view).

<a name="dates"></a>
### Dates

Hypervel's date factory, `now()` and `today()` helpers, ordinary Eloquent date casts, and request date casts return `Hypervel\Support\CarbonImmutable` by default. Assign the result of date modifiers when the changed value must be retained:

```php
$expiresAt = $expiresAt->addMinutes(5);
```

Review concrete `Hypervel\Support\Carbon` type declarations that receive factory-created values. Use `Carbon\CarbonInterface` at boundaries that may receive mutable or immutable dates, or `CarbonImmutable` when immutability is required. An application that deliberately requires mutable dates may configure the date factory during application boot. See the [date and time documentation](/docs/{{version}}/helpers#dates).

<a name="uuids"></a>
### UUIDs

Hypervel's `Str` UUID methods, factories, sequences, and freeze callbacks use `Symfony\Component\Uid\Uuid` values. Laravel uses `Ramsey\Uuid\UuidInterface`. Review concrete UUID type declarations and calls to package-specific methods instead of changing only the framework namespace.

Hypervel's `Str::orderedUuid()` returns a UUIDv7, while Laravel returns a timestamp-first COMB UUIDv4. Review code that validates UUID versions or depends on the exact ordering produced by this method.

<a name="filesystem"></a>
### Filesystem

Hypervel's `Filesystem::hash()` method uses `xxh128` by default. Pass `md5` explicitly when a port requires Laravel-compatible digests.

Unlike Laravel, Hypervel honors `read-only` on scoped disk records. Remove that option from any scoped disk that must accept writes.

<a name="database-cache-sessions-and-queues"></a>
## Database, Cache, Sessions, and Queues

Hypervel's drivers are designed around its Swoole runtime and do not mirror every Laravel driver. Begin with the configuration files from a fresh Hypervel application and move the required connection values into them; do not copy Laravel configuration files wholesale.

<a name="database"></a>
### Database

Hypervel supports MySQL, MariaDB, PostgreSQL, and SQLite database connections. SQL Server, MongoDB, and DynamoDB database integrations are not supported.

Database connections are persistent, pooled worker resources. Define every connection in `config/database.php` before the application boots. Dynamic connection creation through `DB::build()` and `DB::connectUsing()` is not supported. Review pool sizing and any database session state against the [database documentation](/docs/{{version}}/database#connection-pooling).

When a package constructs `DatabaseStore`, `DatabaseSessionHandler`, `DatabaseQueue`, or `DatabaseBatchRepository` directly, pass the database connection resolver and configured connection name instead of retaining a resolved connection. Framework-configured drivers already use this form.

Laravel's base `Connection` class exposes PDO methods. Hypervel's base `Connection` is driver-neutral, while its built-in SQL connections extend `PdoConnection`. Ported code that calls `getPdo`, `getReadPdo`, or another PDO-specific method should accept or narrow to `PdoConnection`. See [extending database connections](/docs/{{version}}/database#extending-database-connections) when porting a custom driver.

Laravel's nested `direct` connection endpoint and `::direct` suffix are not available. Configure the direct endpoint as a normal named connection and point the pooled connection's `migrations_connection` option at it.

Model casts are not applied to direct query builder operations or Eloquent key helpers. When ported code passes already-encoded binary strings to query builder `where`, bulk `update`, or `upsert` calls, or to Eloquent `find`, `whereKey`, or `whereKeyNot`, wrap them in `Hypervel\Database\BinaryParameter`. See [binding binary values](/docs/{{version}}/database#binding-binary-values) and [binary casting](/docs/{{version}}/eloquent-mutators#binary-casting).

Hypervel's `migrate:fresh` command discovers the connection declared by each migration and resets every resolved target before rebuilding the schema. Keep each migration's connection stable, and split manual cross-connection schema work into separate migrations with explicit connection declarations. See [drop all tables and migrate](/docs/{{version}}/migrations#drop-all-tables-migrate) for details.

<a name="redis"></a>
### Redis

Hypervel's Redis integration uses the PhpRedis extension exclusively. Its default `config/database.php` file does not contain a `client` option or `REDIS_CLIENT` environment variable. Remove those Laravel settings when porting configuration. A copied `client` option with any value other than `phpredis` is rejected; Predis is not supported.

Laravel's top-level `database.redis.clusters` configuration is also rejected. Each Hypervel Redis connection selects its standalone, Sentinel, or Cluster topology within the named connection, so begin with the matching Hypervel example instead of adapting Laravel's connection shape. Optional advanced members use their documented defaults when omitted. Hypervel does not support Laravel's `retry_interval` setting; configure retries with `max_retries`, `backoff_algorithm`, `backoff_base`, and `backoff_cap`. Configure Redis Cluster by adding a `cluster` array to a named Redis connection. See the [Redis configuration](/docs/{{version}}/redis#configuration) and [cluster documentation](/docs/{{version}}/redis#clusters).

<a name="cache"></a>
### Cache

Hypervel provides Redis, database, file, filesystem storage, Swoole table, session, stack, failover, array, worker-array, and null cache stores. Memcached, APC / APCu, DynamoDB, and MongoDB cache stores are not supported.

For local in-memory caching, use the [Swoole table cache](/docs/{{version}}/cache#swoole-table-cache). A Swoole table is shared by the workers on one application node. For applications running across several nodes, the [stack cache](/docs/{{version}}/cache#building-cache-stacks) may combine a short-lived Swoole L1 cache with a shared Redis L2 cache. `Cache::memo()` may also wrap a store with per-coroutine memoization at runtime.

The Redis cache store supports two tag modes. The default `all` mode follows Laravel's classic tagged-cache behavior. In `any` mode, tags are invalidation indexes: retrieve values by their plain keys, and flushing any one assigned tag removes the value. Review the [Redis tag mode documentation](/docs/{{version}}/cache#redis-tag-modes) before changing `REDIS_CACHE_TAG_MODE`.

Custom cache tag sets must declare `TagSet::reset(): bool` and `TagSet::flush(): bool`. Hypervel uses these results to report a rejected tagged flush instead of returning unconditional success. Custom `VersionedTagSet` subclasses should override `writeTagId()` for bulk reset persistence; `resetTag()` keeps returning the generated identifier.

<a name="sessions"></a>
### Sessions

Hypervel's persistent application session drivers are `file`, `cookie`, `database`, and `redis`. The non-persistent `array` and `null` drivers are available for testing. Redis sessions are stored directly in Redis and may select a named Redis connection using `SESSION_CONNECTION`.

Laravel's Memcached, APC / APCu, DynamoDB, and generic cache-backed session configurations do not port. Hypervel does not provide Laravel's cache session handler or `SESSION_STORE` setting. Select one of Hypervel's session drivers and review its requirements in the [session documentation](/docs/{{version}}/session).

<a name="queues"></a>
### Queues

Queue connections include `database`, `redis`, `sqs`, `beanstalkd`, `failover`, `sync`, `background`, `deferred`, and `null`. The `background` and `deferred` drivers run work inside the current worker process and are not durable external queues.

Hypervel stores job batches in a relational database. Laravel's DynamoDB batch repository and DynamoDB failed-job provider are not available. Supported failed-job drivers are `database`, `database-uuids`, `file`, and `null`. See the [queue documentation](/docs/{{version}}/queues) for connection and worker configuration.

When a Laravel package offers optional support for an unsupported driver, remove that integration from the Hypervel port unless the package can safely provide it through a separate optional dependency.

<a name="testing-ports"></a>
## Testing Ports

Tests are part of the port. When porting Laravel package functionality, port the relevant Laravel tests and adjust them to Hypervel's namespaces, stricter types, and coroutine-aware test lifecycle.

Laravel tests often rely on loose PHPDoc types or mocks that return values too broad for Hypervel's native type declarations. Fix the source type or test mock so it matches the real runtime behavior. Do not weaken the test just to make it pass.

<a name="application-tests"></a>
### Application Tests

Application tests that touch Hypervel services should extend your application's `Tests\TestCase` class. Hypervel's base test case runs test methods inside a coroutine so database pools, Redis pools, and coroutine context behave like they do in a real request or job.

Keep pure application unit tests on that same base and mark methods that do not need the application with `#[UnitTest]`. This skips application boot while retaining Hypervel's coroutine and cleanup lifecycle.

For more information, see the [testing documentation](/docs/{{version}}/testing#choosing-a-test-case).

<a name="package-tests"></a>
### Package Tests

Package unit tests that do not boot an application should use `Hypervel\Testing\UnitTestCase`. Package feature tests should use `Hypervel\Testbench\TestCase`. Testbench boots a disposable Hypervel application around your package, registers your service providers, and provides an isolated runtime skeleton for tests that publish files, cache routes, run migrations, or generate application files.

```php
<?php

declare(strict_types=1);

namespace Courier\Tests;

use Courier\CourierServiceProvider;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [
            CourierServiceProvider::class,
        ];
    }
}
```

For package testing details, see the [Testbench documentation](/docs/{{version}}/testbench).

<a name="testing-coroutine-isolation"></a>
### Testing Coroutine Isolation

If you move state into coroutine context, add a test that proves concurrent coroutines do not see each other's values. The `parallel` helper is useful for this:

```php
use function Hypervel\Coroutine\parallel;

[$first, $second] = parallel([
    function () use ($service) {
        $service->setLocale('en');

        usleep(5000);

        return $service->getLocale();
    },
    function () use ($service) {
        $service->setLocale('fr');

        usleep(5000);

        return $service->getLocale();
    },
]);

$this->assertSame('en', $first);
$this->assertSame('fr', $second);
```

The `usleep` call gives the runtime an opportunity to switch between coroutines before the value is read. Without it, both closures may finish sequentially and fail to prove isolation.

<a name="porting-applications"></a>
## Porting Applications

When porting an application, start from a fresh Hypervel application skeleton and move code over intentionally. Hypervel has a familiar application structure, but it is not a drop-in replacement for a Laravel `public/index.php` application.

Do not replace the Hypervel skeleton's `composer.json`, `bootstrap/app.php`, `config` directory, or `.env.example` with their Laravel counterparts. Move application providers into `bootstrap/providers.php`, move routes into Hypervel's `routes` files, and configure middleware through the Hypervel `bootstrap/app.php` file. Transfer environment values into the corresponding Hypervel configuration keys instead of copying the Laravel environment file unchanged.

Hypervel runs its Swoole HTTP server using `php artisan serve` and does not use `public/index.php` as its HTTP entry point. Review the [deployment documentation](/docs/{{version}}/deployment) before adapting web server or process-monitor configuration.

Hypervel uses Vite for frontend assets and does not provide Laravel Mix or the `mix()` helper. Keep or migrate assets to the Vite integration described in the [Vite documentation](/docs/{{version}}/vite).

Hypervel does not include support for Laravel Cloud, which is built for Laravel applications. For a managed platform built for Hypervel applications and their long-running services, use [SonicStack](https://sonicstack.io), the Hypervel team's deployment platform. See [Deploying With SonicStack](/docs/{{version}}/deployment#deploying-with-sonicstack).

Treat configuration as boot-time state. If Laravel code changes config values during a request to model the current tenant, locale, guard, or request, move that state to context or a scoped service.

<a name="porting-packages"></a>
## Porting Packages

When porting a Laravel package, keep its public API as close to Laravel as possible unless Hypervel's runtime or supported drivers require a difference. This makes the package familiar to Laravel developers and easier to compare against upstream Laravel changes.

A Hypervel package should provide a service provider through Composer package discovery:

```json
"extra": {
    "hypervel": {
        "providers": [
            "Courier\\CourierServiceProvider"
        ]
    }
}
```

Package providers may publish configuration, migrations, routes, views, language files, public assets, and commands using the APIs documented in the [package development documentation](/docs/{{version}}/packages).

If the Laravel package ships tests, port the relevant tests with the package. If the Laravel package supports drivers or integrations that Hypervel intentionally does not support, remove those integrations from the port and document the supported alternatives.

<a name="porting-checklist"></a>
## Porting Checklist

When reviewing a Laravel port, confirm the following:

- The target Hypervel version is explicit, and APIs have been checked against that version's documentation and source.
- Applications begin with a fresh Hypervel skeleton; Laravel bootstrap, configuration, Composer scripts, and environment files have not replaced the Hypervel files.
- `laravel/framework`, `illuminate/*`, Orchestra Testbench, and Laravel-only third-party dependencies have been removed or replaced with the required Hypervel packages.
- Package providers use `extra.hypervel.providers`, while application providers are registered in `bootstrap/providers.php`.
- Every `Illuminate` import has been replaced with an existing `Hypervel` import that provides the expected behavior; missing framework APIs have not been recreated as compatibility shims.
- Inherited methods and properties match the native declarations on the current Hypervel parent classes and composed traits.
- Code that receives framework-created dates handles immutable Carbon instances correctly.
- Service providers extend `Hypervel\Support\ServiceProvider`, keep bindings in `register`, and do not use `DeferrableProvider`.
- Request-specific state is not stored on static properties, singleton services, service providers, managers, or unbound concrete services.
- Per-request values use context, coroutine context, scoped bindings, or fresh objects, while static caches contain only worker-safe immutable data.
- Runtime configuration mutation has been removed or replaced with request-scoped state.
- Third-party I/O and PHP extensions are coroutine-aware or deliberately isolated in a separate process.
- Checked-out pooled resources, such as low-level database or Redis connections, do not escape their documented callback or coroutine lifetime.
- Database, cache, session, queue, mail, and filesystem integrations use drivers supported by Hypervel.
- Redis configuration uses PhpRedis and named-connection cluster settings instead of Laravel's client selector or top-level clusters array.
- Custom rate limiting uses Hypervel's policy API; HTTP pools and batches use coroutine concurrency; unsupported pagination selectors have been removed.
- Application frontend assets use Vite, and deployment configuration targets a Hypervel-compatible server or platform.
- Tests use the correct Hypervel or Testbench base class and cover the behavior being ported.
- Shared services that store per-request state have tests proving isolation between concurrent coroutines.
