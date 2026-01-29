# Service Providers
[[toc]]

## Introduction

Service providers are the central place of all Hypervel application bootstrapping. Your own application, as well as all of Laravel's core services, are bootstrapped via service providers.

Hypervel ensures that all components are properly configured and ready for use as soon as the application starts. This approach allows for a modular and flexible configuration system, where each component can manage its own configuration independently.

But, what do we mean by "bootstrapped"? In general, we mean **registering** things, including registering service container bindings, event listeners, middleware, and even routes. Service providers are the central place to configure your application.

If you open the `config/app.php` file included with Hypervel, you will see a providers array. These are all of the service provider classes that will be loaded for your application. By default, a set of Hypervel core service providers are listed in this array.

In this overview, you will learn how to write your own service providers and register them with your Hypervel application.

::: note
If you would like to learn more about how Hypervel handles requests and works internally, check out our documentation on the Laravel [request lifecycle](/docs/lifecycle).
:::

## Writing Service Providers

All service providers extend the `Hypervel\Support\ServiceProvider` class. Most service providers contain a `register` and a `boot` method. Within the `register` method, you should **only bind things into the [service container](/docs/container)**. You should never attempt to register any event listeners, routes, or any other piece of functionality within the `register` method.

The Artisan CLI can generate a new provider via the `make:provider` command.

```shell:no-line-numbers
php artisan make:provider RiakServiceProvider
```

### The Register Method

As mentioned previously, within the `register` method, you should only bind things into the [service container](/docs/container). You should never attempt to register any event listeners, routes, or any other piece of functionality within the `register` method. Otherwise, you may accidentally use a service that is provided by a service provider which has not loaded yet.

Let's take a look at a basic service provider. Within any of your service provider methods, you always have access to the `$app` property which provides access to the service container:

```php
<?php

namespace App\Providers;

use App\Services\Riak\Connection;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\ServiceProvider;

class RiakServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Connection::class, function (Application $app) {
            return new Connection(config('riak'));
        });
    }
}
```

This service provider only defines a `register` method, and uses that method to define an implementation of `App\Services\Riak\Connection` in the service container. If you're not yet familiar with Hypervel's service container, check out [its documentation](/docs/container).

#### The `bindings` Properties

If your service provider registers many simple bindings, you may wish to use the `bindings` property instead of manually registering each container binding. When the service provider is loaded by the framework, it will automatically check for these properties and register their bindings:

```php
<?php

namespace App\Providers;

use App\Contracts\ServerProvider;
use App\Services\DigitalOceanServerProvider;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     */
    public array $bindings = [
        ServerProvider::class => DigitalOceanServerProvider::class,
    ];
}
```

:::tip
You can also set these bindings in `config/dependencies.php`.
:::

### The Boot Method

So, what if we need to extend `Request` functions within our service provider? This should be done within the `boot` method. **This method is called after all other service providers have been registered**, meaning you have access to all other services that have been registered by the framework:

```php
<?php

namespace App\Providers;

use Hypervel\Http\Request;
use Hypervel\Foundation\Macros\RequestMacro;
use Hypervel\Support\ServiceProvider;

class RequestServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Request::mixin(new RequestMacro());
    }
}
```

#### Boot Method Dependency Injection

You may type-hint dependencies for your service provider's `boot` method. The [service container](/docs/container) will automatically inject any dependencies you need:

```php
use Hyperf\Database\ConnectionResolverInterface;

/**
 * Bootstrap any application services.
 */
public function boot(ConnectionResolverInterface $resolver): void
{
    $resolver->setDefaultConnection('mysql');
}
```

### Writing Provider Config

Some behaviors must be achieved in config provider, such as class map replacement or aspect registration. Service providers can also provide the same functionality, you just need to implement `getProviderConfig` in the service provider:

```php
public static function getProviderConfig(): array
{
    return [
        'dependencies' => [],
        'annotations' => [
            'scan' => [
                'paths' => [
                    __DIR__,
                ],
            ],
        ],
        'listeners' => [],
        'publish' => [
            [
                'id' => 'config',
                'description' => 'description of this config file.',
                'source' => __DIR__ . '/../publish/file.php',
                'destination' => BASE_PATH . '/config/autoload/file.php',
            ],
         ],
    ];
}
```

Then you need to configure your service provider in `composer.json` for auto discovery:

```json
"extra": {
    "branch-alias": {
        "dev-main": "0.3-dev"
    },
    "hypervel": {
        "providers": [
            "Hypervel\\Sanctum\\SanctumServiceProvider"
        ]
    }
}
```

## Registering Providers

All service providers are registered in the `config/app.php` configuration file. This file contains a `providers` array where you can list the class names of your service providers. By default, a set of Hypervel core service providers are registered in this array. The default providers bootstrap the core Hypervel components.

To register your provider, add it to the array:

```php
'providers' => [
    Hypervel\Foundation\Providers\FoundationServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
]
```
