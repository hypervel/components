# Package Development
[[toc]]

## Introduction

Packages are the primary way of adding functionality to Hypervel. There are different types of packages. Some packages are stand-alone, meaning they work with any PHP framework. Carbon and PHPUnit are examples of stand-alone packages. Any of these packages may be used with Hypervel by requiring them in your `composer.json` file.

On the other hand, other packages are specifically intended for use with Hypervel. These packages may have routes, controllers, views, and configuration specifically intended to enhance a Hypervel application. This guide primarily covers the development of those packages that are Hypervel specific.

## Package Discovery

In a Hypervel application's `config/app.php` configuration file, the `providers` option defines a list of service providers that should be loaded by Hypervel. When someone installs your package, you will typically want your service provider to be included in this list. Instead of requiring users to manually add your service provider to the list, you may define the provider in the `extra` section of your package's `composer.json` file. In addition to service providers, you may also list any [facades](/docs/facades) you would like to be registered:

```json
"extra": {
    "hypervel": {
        "providers": [
            "Barryvdh\\Debugbar\\ServiceProvider"
        ],
        "aliases": {
            "Debugbar": "Barryvdh\\Debugbar\\Facade"
        }
    }
},
```

Once your package has been configured for discovery, Hypervel will automatically register its service providers and facades when it is installed, creating a convenient installation experience for your package's users.

#### Opting Out of Package Discovery

If you are the consumer of a package and would like to disable package discovery for a package, you may list the package name in the `extra` section of your application's `composer.json` file:

```json
"extra": {
    "hypervel": {
        "dont-discover": [
            "barryvdh/laravel-debugbar"
        ]
    }
},
```

You may disable package discovery for all packages using the `*` character inside of your application's `dont-discover` directive:

```json
"extra": {
    "hypervel": {
        "dont-discover": [
            "*"
        ]
    }
},
```

::: important
Hypervel also supports `Config Provider` for the compatibility with Hyperf. We encourage using config providers that we can empower Hyperf's ecosystem at same time.
:::

## Config Providers

Config providers are placed in each root directory of the component. These providers will supply all the configuration information of the corresponding component, which will be started by the Hypervel when loaded.

The final configuration information in config providers will be merged into the corresponding implementation class of `Hyperf\Contract\ConfigInterface`. This process enables the configuration initialization of each component when used under the Hypervel.

A sample config provider looks like below:

```php
<?php

namespace Hypervel\Foo;

class ConfigProvider
{
     public function __invoke(): array
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
}
```

* `dependencies`:
This key is used to define dependency injection configurations. It will be merged into the `config/dependencies.php` file. You can define your dependency bindings here, which is equivalent to binding interfaces in service containers.

* `annotations`:
This key is used to configure annotation scanning. It will be merged into the `config/annotations.php` file. In this example, it sets the scan path to the current directory.

* `commands`:
This key is used to define command classes. It will be merged into commands config array, which can also be understood as corresponding to the `config/commands.php` file. And these commands will be registered when the framework bootstrapped.

* `listeners`:
This key functions similarly to commands and is used to define listeners.

* `publish`:
This key is used to define the component's default configuration files. When the publish command is executed, the file corresponding to the source will be copied to the path corresponding to the destination. In this example, it defines a configuration file with the ID 'config', including a description, source file path, and destination file path.

* `Other configurations`:
In addition to the predefined configuration keys above, you can define other configurations. These configurations will ultimately be merged into the configuration corresponding to `ConfigInterface`.

::: note
Unlike service providers, config providers don't have direct approach to register your resources to the application. You can only declare your resource assets to be published.

Also, resource path in Hyperf framework has strong coupling with `BASE_PATH` constant. If you use helper functions like `base_path`, `storage_path`, `resource_path`, etc, it will only work in Hypervel.
:::

## Service Providers

[Service providers](/docs/providers) are the connection point between your package and Hypervel. A service provider is responsible for binding things into Hypervel's [service container](/docs/container) and informing Hypervel where to load package resources such as views, configuration, and language files.

A service provider extends the `Hypervel\Support\ServiceProvider` class and contains two methods: `register` and `boot`. The base `ServiceProvider` class is located in the `hypervel/support` Composer package, which you should add to your own package's dependencies. To learn more about the structure and purpose of service providers, check out [their documentation](/docs/providers).

## Resources

### Configuration

Typically, you will need to publish your package's configuration file to the application's `config` directory. This will allow users of your package to easily override your default configuration options. To allow your configuration files to be published, call the `publishes` method from the `boot` method of your service provider:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->publishes([
        __DIR__.'/../config/courier.php' => config_path('courier.php'),
    ]);
}
```

Now, when users of your package execute Hypervel's `vendor:publish` command, your file will be copied to the specified publish location. Once your configuration has been published, its values may be accessed like any other configuration file:

```php
$value = config('courier.option');
```

#### Default Package Configuration

You may also merge your own package configuration file with the application's published copy. This will allow your users to define only the options they actually want to override in the published copy of the configuration file. To merge the configuration file values, use the `mergeConfigFrom` method within your service provider's `register` method.

The `mergeConfigFrom` method accepts the path to your package's configuration file as its first argument and the name of the application's copy of the configuration file as its second argument:

```php
/**
 * Register any application services.
 */
public function register(): void
{
    $this->mergeConfigFrom(
        __DIR__.'/../config/courier.php', 'courier'
    );
}
```

::: warning
This method only merges the first level of the configuration array. If your users partially define a multi-dimensional configuration array, the missing options will not be merged.
:::

### Routes

If your package contains routes, you may load them using the `loadRoutesFrom` method. This method will automatically determine if the application's routes are cached and will not load your routes file if the routes have already been cached:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
}
```

### Migrations

If your package contains [database migrations](/docs/migrations), you may use the `loadMigrationsFrom` method to inform Laravel how to load them. The `loadMigrationsFrom` method accepts the path to your package's migrations as its only argument:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
}
```

Once your package's migrations have been registered, they will automatically be run when the `php artisan migrate` command is executed. You do not need to export them to the application's `database/migrations` directory.

### Language Files

If your package contains [language files](/docs/localization), you may use the `loadTranslationsFrom` method to inform Hypervel how to load them. For example, if your package is named `courier`, you should add the following to your service provider's `boot` method:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->loadTranslationsFrom(__DIR__.'/../lang', 'courier');
}
```

Package translation lines are referenced using the `package::file.line` syntax convention. So, you may load the `courier` package's `welcome` line from the `messages` file like so:

```php
echo trans('courier::messages.welcome');
```

You can register JSON translation files for your package using the `loadJsonTranslationsFrom` method. This method accepts the path to the directory that contains your package's JSON translation files:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
}
```

#### Publishing Language Files

If you would like to publish your package's language files to the application's `lang/vendor` directory, you may use the service provider's `publishes` method. The `publishes` method accepts an array of package paths and their desired publish locations. For example, to publish the language files for the `courier` package, you may do the following:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->loadTranslationsFrom(__DIR__.'/../lang', 'courier');

    $this->publishes([
        __DIR__.'/../lang' => $this->app->langPath('vendor/courier'),
    ]);
}
```

Now, when users of your package execute Hypervel's `vendor:publish` Artisan command, your package's language files will be published to the specified publish location.

### Views

To register your package's [views](/docs/views) with Laravel, you need to tell Hypervel where the views are located. You may do this using the service provider's `loadViewsFrom` method. The `loadViewsFrom` method accepts two arguments: the path to your view templates and your package's name. For example, if your package's name is `courier`, you would add the following to your service provider's `boot` method:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->loadViewsFrom(__DIR__.'/../resources/views', 'courier');
}
```

Package views are referenced using the `package::view` syntax convention. So, once your view path is registered in a service provider, you may load the `dashboard` view from the `courier` package like so:

```php
Route::get('/dashboard', function () {
    return view('courier::dashboard');
});
```

#### Overriding Package Views

When you use the `loadViewsFrom` method, Hypervel actually registers two locations for your views: the application's `resources/views/vendor` directory and the directory you specify. So, using the `courier` package as an example, Hypervel will first check if a custom version of the view has been placed in the `resources/views/vendor/courier` directory by the developer. Then, if the view has not been customized, Hypervel will search the package view directory you specified in your call to `loadViewsFrom`. This makes it easy for package users to customize / override your package's views.

#### Publishing Views

If you would like to make your views available for publishing to the application's `resources/views/vendor` directory, you may use the service provider's `publishes` method. The `publishes` method accepts an array of package view paths and their desired publish locations:

```php
/**
 * Bootstrap the package services.
 */
public function boot(): void
{
    $this->loadViewsFrom(__DIR__.'/../resources/views', 'courier');

    $this->publishes([
        __DIR__.'/../resources/views' => resource_path('views/vendor/courier'),
    ]);
}
```

Now, when users of your package execute Hypervel's `vendor:publish` Artisan command, your package's views will be copied to the specified publish location.

### View Components

If you are building a package that utilizes Blade components or placing components in non-conventional directories, you will need to manually register your component class and its HTML tag alias so that Hypervel knows where to find the component. You should typically register your components in the `boot` method of your package's service provider:

```php
use Hypervel\Support\Facades\Blade;
use VendorPackage\View\Components\AlertComponent;

/**
 * Bootstrap your package's services.
 */
public function boot(): void
{
    Blade::component('package-alert', AlertComponent::class);
}
```

Once your component has been registered, it may be rendered using its tag alias:

```html
<x-package-alert/>
```

#### Autoloading Package Components

Alternatively, you may use the `componentNamespace` method to autoload component classes by convention. For example, a `Nightshade` package might have `Calendar` and `ColorPicker` components that reside within the `Nightshade\Views\Components` namespace:

```php
use Hypervel\Support\Facades\Blade;

/**
 * Bootstrap your package's services.
 */
public function boot(): void
{
    Blade::componentNamespace('Nightshade\\Views\\Components', 'nightshade');
}
```

This will allow the usage of package components by their vendor namespace using the `package-name::` syntax:

```html
<x-nightshade::calendar />
<x-nightshade::color-picker />
```

Blade will automatically detect the class that's linked to this component by pascal-casing the component name. Subdirectories are also supported using "dot" notation.

#### Anonymous Components

If your package contains anonymous components, they must be placed within a `components` directory of your package's "views" directory (as specified by the [`loadViewsFrom` method](#views)). Then, you may render them by prefixing the component name with the package's view namespace:

```html
<x-courier::alert />
```

## Commands

To register your package's Artisan commands with Hypervel, you may use the `commands` method. This method expects an array of command class names. Once the commands have been registered, you may execute them using the [Artisan CLI](/docs/artisan):

```php
use Courier\Console\Commands\InstallCommand;
use Courier\Console\Commands\NetworkCommand;

/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->commands([
        InstallCommand::class,
        NetworkCommand::class,
    ]);
}
```

## Public Assets

Your package may have assets such as JavaScript, CSS, and images. To publish these assets to the application's `public` directory, use the service provider's `publishes` method. In this example, we will also add a `public` asset group tag, which may be used to easily publish groups of related assets:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->publishes([
        __DIR__.'/../public' => public_path('vendor/courier'),
    ], 'public');
}
```

Now, when your package's users execute the `vendor:publish` command, your assets will be copied to the specified publish location. Since users will typically need to overwrite the assets every time the package is updated, you may use the `--force` flag:

```shell:no-line-numbers
php artisan vendor:publish courier-package --tag=public --force
```

## Publishing File Groups

You may want to publish groups of package assets and resources separately. For instance, you might want to allow your users to publish your package's configuration files without being forced to publish your package's assets. You may do this by "tagging" them when calling the `publishes` method from a package's service provider. For example, let's use tags to define two publish groups for the `courier` package (`courier-config` and `courier-migrations`) in the `boot` method of the package's service provider:

```php
/**
 * Bootstrap any package services.
 */
public function boot(): void
{
    $this->publishes([
        __DIR__.'/../config/package.php' => config_path('package.php')
    ], 'courier-config');

    $this->publishes([
        __DIR__.'/../database/migrations/' => database_path('migrations')
    ], 'courier-migrations');
}
```

Now your users may publish these groups separately by referencing their tag when executing the `vendor:publish` command:

```shell:no-line-numbers
php artisan vendor:publish courier-package --tag=courier-config
```