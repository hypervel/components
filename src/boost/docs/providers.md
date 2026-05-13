# Service Providers

- [Introduction](#introduction)
- [Writing Service Providers](#writing-service-providers)
    - [The Register Method](#the-register-method)
    - [Merging Configuration](#merging-configuration)
    - [The Boot Method](#the-boot-method)
    - [Conditionally Loading Providers](#conditionally-loading-providers)
- [Registering Providers](#registering-providers)
    - [Provider Priority](#provider-priority)
- [Deferred Providers](#deferred-providers)

<a name="introduction"></a>
## Introduction

Service providers are the central place of all Laravel application bootstrapping. Your own application, as well as all of Laravel's core services, are bootstrapped via service providers.

But, what do we mean by "bootstrapped"? In general, we mean **registering** things, including registering service container bindings, event listeners, middleware, and even routes. Service providers are the central place to configure your application.

Laravel uses dozens of service providers internally to bootstrap its core services, such as the mailer, queue, cache, and others. Many of these providers are "deferred" providers, meaning they will not be loaded on every request, but only when the services they provide are actually needed.

All user-defined service providers are registered in the `bootstrap/providers.php` file. In the following documentation, you will learn how to write your own service providers and register them with your Laravel application.

> [!NOTE]
> If you would like to learn more about how Laravel handles requests and works internally, check out our documentation on the Laravel [request lifecycle](/docs/{{version}}/lifecycle).

<a name="writing-service-providers"></a>
## Writing Service Providers

All service providers extend the `Hypervel\Support\ServiceProvider` class. Most service providers contain a `register` and a `boot` method. Within the `register` method, you should **only bind things into the [service container](/docs/{{version}}/container)**. You should never attempt to register any event listeners, routes, or any other piece of functionality within the `register` method.

The Artisan CLI can generate a new provider via the `make:provider` command. Laravel will automatically register your new provider in your application's `bootstrap/providers.php` file:

```shell
php artisan make:provider RiakServiceProvider
```

<a name="the-register-method"></a>
### The Register Method

As mentioned previously, within the `register` method, you should only bind things into the [service container](/docs/{{version}}/container). You should never attempt to register any event listeners, routes, or any other piece of functionality within the `register` method. Otherwise, you may accidentally use a service that is provided by a service provider which has not loaded yet.

Let's take a look at a basic service provider. Within any of your service provider methods, you always have access to the `$app` property which provides access to the service container:

```php
<?php

namespace App\Providers;

use App\Services\Riak\Connection;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\ServiceProvider;

class RiakServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Connection::class, function (Application $app) {
            return new Connection(config('riak'));
        });
    }
}
```

This service provider only defines a `register` method, and uses that method to define an implementation of `App\Services\Riak\Connection` in the service container. If you're not yet familiar with Laravel's service container, check out [its documentation](/docs/{{version}}/container).

<a name="the-bindings-and-singletons-properties"></a>
#### The `bindings` and `singletons` Properties

If your service provider registers many simple bindings, you may wish to use the `bindings` and `singletons` properties instead of manually registering each container binding. When the service provider is loaded by the framework, it will automatically check for these properties and register their bindings:

```php
<?php

namespace App\Providers;

use App\Contracts\DowntimeNotifier;
use App\Contracts\ServerProvider;
use App\Services\DigitalOceanServerProvider;
use App\Services\PingdomDowntimeNotifier;
use App\Services\ServerToolsProvider;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array
     */
    public $bindings = [
        ServerProvider::class => DigitalOceanServerProvider::class,
    ];

    /**
     * All of the container singletons that should be registered.
     *
     * @var array
     */
    public $singletons = [
        DowntimeNotifier::class => PingdomDowntimeNotifier::class,
        ServerProvider::class => ServerToolsProvider::class,
    ];
}
```

<a name="merging-configuration"></a>
#### Merging Configuration

Package service providers may merge their default configuration into the application's configuration using the `mergeConfigFrom` method. This is typically done within the `register` method:

```php
/**
 * Register any application services.
 */
public function register(): void
{
    $this->mergeConfigFrom(
        __DIR__.'/../config/riak.php', 'riak'
    );
}
```

By default, configuration is merged at the top level. If your configuration contains arrays that should allow applications to add new entries without replacing the entire array, override the `mergeableOptions` method:

```php
/**
 * Get the options within the configuration that should be merged.
 *
 * @return array<int, string>
 */
protected function mergeableOptions(string $name): array
{
    return $name === 'riak' ? ['connections'] : [];
}
```

In this example, the application's `riak.connections` entries will be merged with the package's default connections. Entries with the same key will still be replaced by the application's configuration.

<a name="the-boot-method"></a>
### The Boot Method

So, what if we need to register a [view composer](/docs/{{version}}/views#view-composers) within our service provider? This should be done within the `boot` method. **This method is called after all other service providers have been registered**, meaning you have access to all other services that have been registered by the framework:

```php
<?php

namespace App\Providers;

use Hypervel\Support\Facades\View;
use Hypervel\Support\ServiceProvider;

class ComposerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('view', function () {
            // ...
        });
    }
}
```

<a name="boot-method-dependency-injection"></a>
#### Boot Method Dependency Injection

You may type-hint dependencies for your service provider's `boot` method. The [service container](/docs/{{version}}/container) will automatically inject any dependencies you need:

```php
use Hypervel\Contracts\Routing\ResponseFactory;

/**
 * Bootstrap any application services.
 */
public function boot(ResponseFactory $response): void
{
    $response->macro('serialized', function (mixed $value) {
        // ...
    });
}
```

<a name="conditionally-loading-providers"></a>
### Conditionally Loading Providers

You may prevent a service provider from being registered or booted by overriding the `isEnabled` method. This is useful for packages that are installed in many applications but should only load in some of them:

```php
/**
 * Determine whether this provider should be registered and booted.
 */
public function isEnabled(): bool
{
    return (bool) config('modules.riak.enabled');
}
```

When this method returns `false`, the provider's `register` and `boot` methods will not be called, its `bindings` and `singletons` properties will not be processed, and the provider will not be marked as loaded.

<a name="registering-providers"></a>
## Registering Providers

All service providers are registered in the `bootstrap/providers.php` configuration file. This file returns an array that contains the class names of your application's service providers:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

When you invoke the `make:provider` Artisan command, Laravel will automatically add the generated provider to the `bootstrap/providers.php` file. However, if you have manually created the provider class, you should manually add the provider class to the array:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ComposerServiceProvider::class, // [tl! add]
];
```

<a name="provider-priority"></a>
### Provider Priority

Auto-discovered package providers may define a `priority` property to control their registration order relative to other discovered package providers. Providers with a higher priority are registered first:

```php
use Hypervel\Support\ServiceProvider;

class RiakServiceProvider extends ServiceProvider
{
    /**
     * The registration priority for this provider.
     */
    public int $priority = 10;
}
```

Provider priority only applies to auto-discovered package providers. Framework providers are always registered before discovered package providers, and application providers are registered after them.

<a name="deferred-providers"></a>
## Deferred Providers

If your provider is **only** registering bindings in the [service container](/docs/{{version}}/container), you may choose to defer its registration until one of the registered bindings is actually needed. Deferring the loading of such a provider will improve the performance of your application, since it is not loaded from the filesystem on every request.

Laravel compiles and stores a list of all of the services supplied by deferred service providers, along with the name of its service provider class. Then, only when you attempt to resolve one of these services does Laravel load the service provider.

To defer the loading of a provider, implement the `\Hypervel\Contracts\Support\DeferrableProvider` interface and define a `provides` method. The `provides` method should return the service container bindings registered by the provider:

```php
<?php

namespace App\Providers;

use App\Services\Riak\Connection;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Support\DeferrableProvider;
use Hypervel\Support\ServiceProvider;

class RiakServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Connection::class, function (Application $app) {
            return new Connection($app['config']['riak']);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [Connection::class];
    }
}
```
