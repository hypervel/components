# Porting from Laravel

- [Introduction](#introduction)
- [Why Laravel Code Needs Porting](#why-laravel-code-needs-porting)
- [Porting Workflow](#porting-workflow)
- [Namespaces and Dependencies](#namespaces-and-dependencies)
    - [Common Namespace Replacements](#common-namespace-replacements)
    - [Contracts](#contracts)
- [Type Declarations](#type-declarations)
- [Service Providers](#service-providers)
    - [Registering Bindings](#registering-bindings)
    - [Bootstrapping Services](#bootstrapping-services)
    - [Conditional Providers](#conditional-providers)
    - [Deferred Providers](#deferred-providers)
- [Coroutine Safety](#coroutine-safety)
    - [Request-Specific State](#request-specific-state)
    - [Worker-Lifetime State](#worker-lifetime-state)
    - [Container Lifecycles](#container-lifecycles)
- [Configuration](#configuration)
- [Other API Differences](#other-api-differences)
- [Database, Cache, Sessions, and Queues](#database-cache-sessions-and-queues)
- [Testing Ports](#testing-ports)
    - [Application Tests](#application-tests)
    - [Package Tests](#package-tests)
    - [Testing Coroutine Isolation](#testing-coroutine-isolation)
- [Porting Applications](#porting-applications)
- [Porting Packages](#porting-packages)
- [Porting Checklist](#porting-checklist)

<a name="introduction"></a>
## Introduction

Laravel code is often straightforward to port to Hypervel, but it should not be copied into a Hypervel application or package without review. Hypervel intentionally follows Laravel's public APIs wherever that makes sense, while running on long-lived Swoole workers that may handle many concurrent requests and jobs in the same PHP process.

This guide explains how to port Laravel application code and Laravel packages to Hypervel. It focuses on the parts that usually matter during a port: namespaces, service providers, configuration, tests, and coroutine safety.

If you are building a new Hypervel package from scratch, you should also read the [package development documentation](/docs/{{version}}/packages). If you are testing a package, read the [Testbench documentation](/docs/{{version}}/testbench).

<a name="why-laravel-code-needs-porting"></a>
## Why Laravel Code Needs Porting

Laravel is stateful PHP framework, where each request starts with a fresh PHP runtime and ends by destroying all in-memory state. Hypervel is a stateful framework that runs inside long-lived Swoole workers. A worker boots the application once, keeps it in memory, and serves many requests and jobs over its lifetime.

This difference means request-specific state must not be stored on shared objects. For example, consider the shape of Laravel's `SessionGuard`: the guard caches the authenticated user on an instance property so repeated `user()` calls during a single request are fast. In a PHP-FPM request lifecycle, that instance disappears at the end of the request. In Hypervel, a singleton guard may live for the worker lifetime, so the cached user must be isolated per coroutine instead of stored directly on the shared object.

The same issue appears in translators, managers, middleware, repositories, event dispatchers, and any service that stores mutable request-specific data. When porting Laravel code, always ask whether a property is:

- Immutable configuration that can safely live for the worker lifetime.
- Per-request or per-job state that must live in [context](/docs/{{version}}/context) or [coroutine context](/docs/{{version}}/coroutine-context).
- Per-call builder state that should be held by a fresh object.

<a name="porting-workflow"></a>
## Porting Workflow

When porting Laravel code to Hypervel, work through the code in this order:

1. Replace `Illuminate` namespaces with the matching `Hypervel` namespaces.
2. Replace unsupported Laravel features with Hypervel-supported alternatives.
3. Convert Laravel service providers to Hypervel service providers.
4. Review singleton, static, and manager state for coroutine safety.
5. Modernize type declarations where the Hypervel equivalent is stricter than Laravel.
6. Verify the types against runtime behavior and tests.
7. Port the relevant Laravel tests and add Hypervel-specific tests for coroutine isolation when needed.
8. Run the test suite and static analysis for the project or package you are porting.

The goal is not to make code look different for its own sake. Keep Laravel behavior and method names where Hypervel supports them. Change the implementation only where Hypervel's runtime, supported drivers, or package structure requires it.

<a name="namespaces-and-dependencies"></a>
## Namespaces and Dependencies

Most Laravel framework classes map directly from `Illuminate\...` to `Hypervel\...`. For example, `Illuminate\Support\Str` becomes `Hypervel\Support\Str`, and `Illuminate\Support\ServiceProvider` becomes `Hypervel\Support\ServiceProvider`.

When porting imports, update the import list first, then read the class again and verify that each replacement exists and has the behavior the code expects. Some Laravel packages depend on optional Laravel-only packages or drivers that Hypervel does not support.

<a name="common-namespace-replacements"></a>
### Common Namespace Replacements

The following replacements cover the most common Laravel framework dependencies:

| Laravel | Hypervel |
|---|---|
| `Illuminate\Auth\...` | `Hypervel\Auth\...` |
| `Illuminate\Broadcasting\...` | `Hypervel\Broadcasting\...` |
| `Illuminate\Bus\...` | `Hypervel\Bus\...` |
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

Not every class has a one-for-one replacement. If a Laravel class belongs to a package Hypervel does not support, remove that integration or replace it with a Hypervel-supported feature.

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

<a name="type-declarations"></a>
## Type Declarations

Hypervel uses native PHP types more aggressively than Laravel. When porting Laravel code, do not blindly copy PHPDoc types into method signatures. Laravel's docblocks are sometimes broader or narrower than the values the code actually accepts and returns.

For example, a Laravel method may document a parameter as `string` while internal callers can pass `null`. In Hypervel, declaring that parameter as `string` would turn a working code path into a type error. Read the method body, trace its callers, and use the ported tests to confirm the correct type.

Declare strict types at the top of each file and use native parameter, property, and return types wherever the type is known. Keep PHPDoc for useful descriptions, generics, complex array shapes, `@throws` annotations, and cases PHP cannot express natively.

<a name="service-providers"></a>
## Service Providers

Hypervel service providers use the same public shape as Laravel service providers. A ported service provider should extend `Hypervel\Support\ServiceProvider` and use the same `register` and `boot` separation you would use in Laravel.

```php
<?php

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
            $app->make('config')->get('courier')
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

Do not store request-specific state on service provider properties. Providers are registered and booted when the worker starts, not once per request.

<a name="conditional-providers"></a>
### Conditional Providers

Hypervel service providers may override the `isEnabled` method to opt out of registration based on configuration, environment, or feature flags. When this method returns `false`, the provider's `register` and `boot` methods will not be called:

```php
/**
 * Determine whether this provider should be registered and booted.
 */
public function isEnabled(): bool
{
    return (bool) config('courier.enabled');
}
```

<a name="deferred-providers"></a>
### Deferred Providers

Laravel's `DeferrableProvider` interface is not useful in Hypervel's long-running worker model. Providers are registered once during worker boot and then stay in memory. When porting a Laravel provider that implements `DeferrableProvider`, remove the interface and the `provides` method.

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

Hypervel's container has the same public shape as Laravel's container, but its lifecycle is adapted for Swoole:

| Need | Method |
|---|---|
| Fresh instance every call | `bind()` |
| One instance per request or job coroutine | `scoped()` |
| One instance per worker | `singleton()` |
| Fresh instance at the call site | `build()` or `buildWith()` |
| Resolve using bindings and lifecycle rules | `make()` |

Unbound concrete classes are automatically cached for the worker lifetime after their first resolution. This is a performance optimization for stateless services. If a class captures per-call values in its constructor, do not rely on zero-configuration resolution for that class; register it with `bind()` or resolve it with `build()`.

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

Application code should keep request-specific values in the request, session, context, or coroutine context instead of changing config values while the server is running.

<a name="other-api-differences"></a>
## Other API Differences

Most Laravel APIs have direct Hypervel equivalents under the `Hypervel` namespace. When an API is absent, it is usually because Hypervel's coroutine runtime provides a simpler primitive.

For concurrent HTTP requests, replace Laravel's `Http::pool` and `Http::batch` patterns with Hypervel's coroutine helpers, typically `parallel` from `Hypervel\Coroutine`. See the [HTTP client documentation](/docs/{{version}}/http-client#concurrent-requests) for examples.

<a name="database-cache-sessions-and-queues"></a>
## Database, Cache, Sessions, and Queues

Hypervel supports a smaller set of drivers than Laravel. When porting code, replace unsupported drivers rather than documenting or configuring them. For the full configuration surface, see the [database](/docs/{{version}}/database), [cache](/docs/{{version}}/cache), [session](/docs/{{version}}/session), and [queue](/docs/{{version}}/queues) documentation.

Common differences include:

- Database connections are pooled worker-level resources. Define connections in `config/database.php` before the worker boots. Dynamic connection creation via `DB::build()` and `DB::connectUsing()` is not supported.
- Hypervel supports MySQL, MariaDB, PostgreSQL, and SQLite database connections. SQL Server, MongoDB, and DynamoDB database integrations are not supported.
- Cache stores include Redis, relational databases, file storage, Swoole tables, session storage, stack / failover stores, and the `array` and `null` stores. Memcached, DynamoDB, and MongoDB cache stores are not supported.
- `Cache::memo()` may wrap any cache store with per-coroutine memoization. It is accessed at runtime rather than configured as a separate store.
- Session storage should use a driver supported by Hypervel. Redis is recommended for maximum performance and scalability when sessions need to be shared across workers or servers.
- Queue connections include `database`, `redis`, `sqs`, `beanstalkd`, `failover`, `sync`, `background`, `deferred`, and `null`. The `background` and `deferred` drivers run work inside the current worker process and are not durable external queues.

When a Laravel package offers optional support for unsupported drivers, remove those integrations from the Hypervel port unless the package can safely provide them through a separate optional dependency.

<a name="testing-ports"></a>
## Testing Ports

Tests are part of the port. When porting Laravel package functionality, port the relevant Laravel tests and adjust them to Hypervel's namespaces, stricter types, and coroutine-aware test lifecycle.

Laravel tests often rely on loose PHPDoc types or mocks that return values too broad for Hypervel's native type declarations. Fix the source type or test mock so it matches the real runtime behavior. Do not weaken the test just to make it pass.

<a name="application-tests"></a>
### Application Tests

Application tests that touch Hypervel services should extend your application's `Tests\TestCase` class. Hypervel's base test case runs test methods inside a coroutine so database pools, Redis pools, and coroutine context behave like they do in a real request or job.

Pure unit tests that do not boot the framework may extend PHPUnit's base test case. If a test touches Hypervel services, use the Hypervel test case.

For more information, see the [testing documentation](/docs/{{version}}/testing#running-tests-in-coroutines).

<a name="package-tests"></a>
### Package Tests

Package feature tests should use `Hypervel\Testbench\TestCase`. Testbench boots a disposable Hypervel application around your package, registers your service providers, and provides an isolated runtime skeleton for tests that publish files, cache routes, run migrations, or generate application files.

```php
<?php

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

When porting an application, start from a fresh Hypervel application skeleton and move code over intentionally. Hypervel 0.4 has a familiar application structure, but it is not a drop-in replacement for a Laravel `public/index.php` application.

Common application changes include:

- Register application service providers in `bootstrap/providers.php`.
- Move routing into Hypervel's `routes` files and middleware configuration into `bootstrap/app.php`.
- Replace `Illuminate` imports with `Hypervel` imports.
- Replace unsupported database, cache, session, queue, mail, or filesystem drivers.
- Review all singleton services, static properties, middleware, and manager classes for request-specific state.
- Use `php artisan serve` to run the Swoole server. Hypervel does not use `public/index.php` as the HTTP entry point.
- Update tests to use Hypervel's coroutine-aware test case when they touch framework services.

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

- All `Illuminate` imports have been replaced with the correct `Hypervel` imports.
- Service providers extend `Hypervel\Support\ServiceProvider`.
- Package providers are registered through `extra.hypervel.providers`; application providers are registered in `bootstrap/providers.php`.
- Request-specific state is not stored on static properties, singleton services, service providers, or managers.
- Per-request values use context, coroutine context, scoped bindings, or fresh objects.
- Runtime configuration mutation has been removed or replaced with request-scoped state.
- Unsupported drivers and Laravel-only integrations have been removed or replaced.
- Tests use the correct Hypervel or Testbench base class.
- Coroutine isolation is tested for shared services that store per-request state.
- Static caches only contain worker-safe immutable data and can be reset in tests when needed.
