# Testbench

- [Introduction](#introduction)
- [Installation](#installation)
- [Getting Started](#getting-started)
    - [PHPUnit Test Suites](#phpunit-test-suites)
- [How Testbench Works](#how-testbench-works)
    - [Runtime Skeleton Copies](#runtime-skeleton-copies)
    - [Configuration Lifecycle](#configuration-lifecycle)
    - [Coroutines](#coroutines)
- [Configuration](#configuration)
    - [The testbench.yaml File](#the-testbenchyaml-file)
    - [Environment Variables](#environment-variables)
    - [Package Providers](#package-providers)
    - [Package Discovery](#package-discovery)
    - [Custom Application Skeletons](#custom-application-skeletons)
- [Defining the Environment](#defining-the-environment)
    - [Using Attributes](#using-attributes)
    - [Application Timezone](#application-timezone)
    - [Framework Configuration](#framework-configuration)
- [Defining Databases](#defining-databases)
    - [Loading Migrations](#loading-migrations)
    - [Loading Hypervel Migrations](#loading-hypervel-migrations)
    - [Database Attributes](#database-attributes)
- [Defining Routes](#defining-routes)
    - [Cached Routes](#cached-routes)
- [Workbench](#workbench)
    - [Installing Workbench](#installing-workbench)
    - [Workbench Configuration](#workbench-configuration)
    - [Discovering Workbench Files](#discovering-workbench-files)
    - [Serving the Workbench Application](#serving-the-workbench-application)
    - [Syncing Workbench Directories](#syncing-workbench-directories)
- [Command Line](#command-line)
    - [Running Package Tests](#running-package-tests)
    - [SQLite Databases](#sqlite-databases)
    - [Purging the Skeleton](#purging-the-skeleton)
- [Testing Published Files](#testing-published-files)
- [Helpers](#helpers)

<a name="introduction"></a>
## Introduction

Testbench provides a convenient way to write feature and integration tests for Hypervel packages. It creates a small Hypervel application around your package so your tests may register service providers, publish files, define routes, run migrations, dispatch jobs, and make HTTP requests as if the package were installed in a real application.

Hypervel Testbench is a port of [Orchestra Testbench](https://github.com/orchestral/testbench) adapted for Hypervel's Swoole and coroutine runtime. It also includes several package-development helpers that are commonly useful when testing generated files, command-line behavior, and Workbench applications.

If you are building a package for Hypervel applications, you should generally use Testbench for your package's feature tests. For general package development concepts, see the [package development documentation](/docs/{{version}}/packages).

<a name="installation"></a>
## Installation

You may install Testbench into your package using Composer:

```shell
composer require hypervel/testbench --dev
```

Your package's `phpunit.xml` file should bootstrap Composer's autoloader and point PHPUnit at your package tests:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Package">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

<a name="getting-started"></a>
## Getting Started

To get started, create a base test case for your package that extends `Hypervel\Testbench\TestCase`:

```php
<?php

namespace Courier\Tests;

use Hypervel\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //
}
```

You may then write tests that extend your package's base test case:

```php
<?php

namespace Courier\Tests\Feature;

use Courier\Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_basic_example(): void
    {
        $this->assertTrue(true);
    }
}
```

Package tests may be run using PHPUnit:

```shell
./vendor/bin/phpunit
```

You may also run package tests through the Testbench CLI:

```shell
vendor/bin/testbench package:test
```

<a name="phpunit-test-suites"></a>
### PHPUnit Test Suites

You may split your package tests into multiple PHPUnit test suites so you can run a focused subset of tests when needed:

```xml
<testsuites>
    <testsuite name="Feature">
        <directory suffix="Test.php">./tests/Feature</directory>
    </testsuite>
    <testsuite name="Unit">
        <directory suffix="Test.php">./tests/Unit</directory>
    </testsuite>
</testsuites>
```

Run a single suite with PHPUnit's `--testsuite` option:

```shell
./vendor/bin/phpunit --testsuite=Feature
```

<a name="how-testbench-works"></a>
## How Testbench Works

Hypervel Testbench boots a disposable Hypervel application for your package tests. Understanding how that application is created will help you decide where to place configuration, routes, migrations, and package setup.

<a name="runtime-skeleton-copies"></a>
### Runtime Skeleton Copies

Testbench ships with a default Hypervel application skeleton. When Testbench boots, it copies that skeleton to a temporary runtime directory and points the application base path at the copy. The runtime directory name includes the current ParaTest token and process ID, so parallel workers receive separate filesystem copies.

This keeps the committed skeleton clean when tests generate files, publish assets, create migrations, mutate `bootstrap/providers.php`, or cache routes. The runtime copy is removed when the process exits, and stale copies from crashed runs are cleaned up on the next bootstrap.

You may inspect the active runtime skeleton path using the `default_skeleton_path` helper:

```php
use function Hypervel\Testbench\default_skeleton_path;

$path = default_skeleton_path();
```

<a name="configuration-lifecycle"></a>
### Configuration Lifecycle

Testbench builds the application in phases so package tests may customize the application at the right point in the boot process:

1. Environment attributes such as `#[WithEnv]`, `#[RequiresEnv]`, and `#[RequiresHypervel]` are processed before configuration is loaded.
2. Early application attributes such as `#[ResolvesHypervel]` and `#[UsesFrameworkConfiguration]` are processed just before configuration is loaded.
3. Hypervel's default configuration is loaded and package / Workbench configuration files are merged over the defaults.
4. Package providers, aliases, and provider overrides are written into the application configuration.
5. `#[WithConfig]` attributes are applied after configuration is loaded and before service providers are registered.
6. Service providers are registered.
7. `defineEnvironment` and `#[DefineEnvironment]` callbacks run between provider registration and provider booting.
8. Service providers are booted.
9. Routes, database migrations, and database testing attributes are prepared.

Because Hypervel merges its framework configuration under your package configuration, your Testbench configuration files usually only need to define the values that differ from Hypervel's defaults.

> [!NOTE]
> Configuration set during Testbench lifecycle hooks is isolated to the test application being built. This is safe even though Hypervel applications should not mutate configuration during live requests.

<a name="coroutines"></a>
### Coroutines

`Hypervel\Testbench\TestCase` extends Hypervel's coroutine-aware foundation test case. Each test method runs inside a Swoole coroutine, so framework services such as database pools, Redis pools, and coroutine context behave the same way they do in a Hypervel application.

The standard PHPUnit `setUp` and `tearDown` methods run outside the test method's coroutine. If your setup or teardown work needs to share the test method's coroutine context, use the `setUpInCoroutine` and `tearDownInCoroutine` hooks documented in the [testing documentation](/docs/{{version}}/testing#running-tests-in-coroutines).

<a name="configuration"></a>
## Configuration

<a name="the-testbenchyaml-file"></a>
### The testbench.yaml File

Testbench may be configured using a `testbench.yaml` file in your package root:

```yaml
providers:
  - Courier\CourierServiceProvider

env:
  APP_NAME: "Courier Testbench"

migrations:
  - workbench/database/migrations

workbench:
  health: true
  discovers:
    config: true
    factories: true
    web: true
    api: true
    commands: true
    views: true

purge:
  files:
    - .env
  directories:
    - public/vendor/*
```

The supported top-level keys are:

| Key | Description |
| --- | --- |
| `hypervel` | The path to a custom Hypervel application skeleton. |
| `providers` | Service providers that should be registered for the package test application. |
| `dont-discover` | Package names that should be ignored during package discovery. Use `*` to disable package discovery entirely. |
| `bootstrappers` | Additional bootstrappers to run after Hypervel's providers have booted. |
| `env` | Environment variables for the Testbench CLI application. |
| `migrations` | Migration paths that should be registered for the Testbench application. |
| `seeders` | Seeder classes that should run after the database is refreshed. |
| `purge` | Files and directories that should be removed by `package:purge-skeleton`. |
| `workbench` | Workbench-specific configuration. |

If your package does not need a Workbench application, you may configure providers, aliases, routes, and migrations directly on your package test case instead of creating a `testbench.yaml` file.

<a name="environment-variables"></a>
### Environment Variables

The top-level `env` values in `testbench.yaml` are applied to applications created by the `vendor/bin/testbench` CLI, such as `vendor/bin/testbench serve` and other commands run through the Testbench CLI.

> [!WARNING]
> The top-level `env` values in `testbench.yaml` are not applied to PHPUnit test methods. For PHPUnit tests, use the `#[WithEnv]` attribute, your `phpunit.xml` file, or your normal test environment configuration.

Boolean and null values in `testbench.yaml` are encoded using dotenv-compatible values:

```yaml
env:
  APP_DEBUG: false
  APP_NAME: "Courier Testbench"
```

If you need an environment variable for one test or test class, use the `#[WithEnv]` attribute:

```php
use Hypervel\Testbench\Attributes\WithEnv;

#[WithEnv('COURIER_ENDPOINT', 'https://example.test')]
class CourierClientTest extends TestCase
{
    //
}
```

By default, Testbench loads environment variables from the runtime skeleton's environment file before applying `#[WithEnv]` attributes. If a test case should not load that file, define the `$loadEnvironmentVariables` property:

```php
protected bool $loadEnvironmentVariables = false;
```

<a name="package-providers"></a>
### Package Providers

You may register your package service providers from `testbench.yaml`:

```yaml
providers:
  - Courier\CourierServiceProvider
```

Alternatively, you may override the `getPackageProviders` method on your package test case:

```php
use Courier\CourierServiceProvider;
use Hypervel\Contracts\Foundation\Application;

/**
 * Get package providers.
 *
 * @return array<int, class-string>
 */
protected function getPackageProviders(Application $app): array
{
    return [
        CourierServiceProvider::class,
    ];
}
```

You may register package aliases using the `getPackageAliases` method:

```php
use Courier\Facades\Courier;
use Hypervel\Contracts\Foundation\Application;

/**
 * Get package aliases.
 *
 * @return array<string, class-string>
 */
protected function getPackageAliases(Application $app): array
{
    return [
        'Courier' => Courier::class,
    ];
}
```

If you need to remove or replace one of Hypervel's application providers during a package test, override `overrideApplicationProviders`:

```php
use Hypervel\Contracts\Foundation\Application;
use Hypervel\View\ViewServiceProvider;

/**
 * Override application providers.
 *
 * @return array<class-string, class-string|false>
 */
protected function overrideApplicationProviders(Application $app): array
{
    return [
        ViewServiceProvider::class => false,
    ];
}
```

You may also override application container bindings before the application is bootstrapped:

```php
use Courier\Contracts\CourierContract;
use Courier\Testing\FakeCourier;
use Hypervel\Contracts\Foundation\Application;

/**
 * Override application bindings.
 *
 * @return array<class-string|string, class-string|string>
 */
protected function overrideApplicationBindings(Application $app): array
{
    return [
        CourierContract::class => FakeCourier::class,
    ];
}
```

If your package needs to run an additional bootstrapper after Hypervel's service providers have booted, override `getPackageBootstrappers`:

```php
use Courier\Bootstrap\PrepareCourier;
use Hypervel\Contracts\Foundation\Application;

/**
 * Get package bootstrappers.
 *
 * @return array<int, class-string>
 */
protected function getPackageBootstrappers(Application $app): array
{
    return [
        PrepareCourier::class,
    ];
}
```

<a name="package-discovery"></a>
### Package Discovery

By default, Testbench disables package discovery while running tests. You may opt into package discovery by defining the `$enablesPackageDiscoveries` property on your test case:

```php
protected bool $enablesPackageDiscoveries = true;
```

If you need more control, override `ignorePackageDiscoveriesFrom`:

```php
/**
 * Ignore package discovery from.
 *
 * @return array<int, string>
 */
public function ignorePackageDiscoveriesFrom(): array
{
    return [
        'vendor/package-to-ignore',
    ];
}
```

When using Workbench, the `dont-discover` key in `testbench.yaml` provides the same behavior:

```yaml
dont-discover:
  - vendor/package-to-ignore
```

<a name="custom-application-skeletons"></a>
### Custom Application Skeletons

Most packages can use Testbench's default skeleton. If your package needs a custom skeleton application, you may set the `hypervel` key in `testbench.yaml`:

```yaml
hypervel: ./skeleton
```

You may also override `applicationBasePath`:

```php
use function Hypervel\Testbench\package_path;

public static function applicationBasePath(): string
{
    return package_path('skeleton');
}
```

Relative paths in `testbench.yaml` are resolved from the package root.

<a name="defining-the-environment"></a>
## Defining the Environment

The `defineEnvironment` method is called after providers have been registered and before they are booted. This makes it the right place to set configuration that must exist before provider boot logic runs:

```php
use Hypervel\Contracts\Foundation\Application;

/**
 * Define environment setup.
 */
protected function defineEnvironment(Application $app): void
{
    $app->make('config')->set('database.default', 'testing');
    $app->make('config')->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);
}
```

If a config value must be available during provider boot logic, prefer `defineEnvironment` over `#[WithConfig]`.

<a name="using-attributes"></a>
### Using Attributes

You may call environment setup methods using the `#[DefineEnvironment]` attribute:

```php
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Attributes\DefineEnvironment;

class CourierTest extends TestCase
{
    #[DefineEnvironment('useArrayCache')]
    public function test_courier_uses_cache(): void
    {
        // ...
    }

    protected function useArrayCache(Application $app): void
    {
        $app->make('config')->set('cache.default', 'array');
    }
}
```

The `#[Define]` attribute provides a shorthand for environment, database, and route setup:

```php
use Hypervel\Testbench\Attributes\Define;

#[Define('env', 'configureCourier')]
class CourierTest extends TestCase
{
    //
}
```

Most Testbench attributes may be placed on a test class or on an individual test method. Method-only attributes such as `#[DefineDatabase]` and `#[DefineRoute]` should be placed on the test method they configure, while `#[ResetRefreshDatabaseState]` is class-only.

For simple per-test config overrides, you may use `#[WithConfig]`:

```php
use Hypervel\Testbench\Attributes\WithConfig;

#[WithConfig('cache.default', 'array')]
class CourierCacheTest extends TestCase
{
    //
}
```

You may skip tests when an environment variable or Hypervel version requirement is not satisfied:

```php
use Hypervel\Testbench\Attributes\RequiresEnv;
use Hypervel\Testbench\Attributes\RequiresHypervel;

#[RequiresEnv('COURIER_TOKEN')]
#[RequiresHypervel('>=0.4.0')]
class CourierLiveApiTest extends TestCase
{
    //
}
```

Hypervel uses immutable dates by default. The `#[WithImmutableDates]` attribute explicitly restores that default for the duration of a test, which is useful when the tested application or package bootstrap deliberately configures mutable dates:

```php
use Hypervel\Testbench\Attributes\WithImmutableDates;

#[WithImmutableDates]
class CourierDateTest extends TestCase
{
    //
}
```

If a test runs a command in a subprocess and that command needs the package's Composer dependencies inside the runtime skeleton, use the `#[UsesVendor]` attribute:

```php
use Hypervel\Testbench\Attributes\UsesVendor;

class CourierCommandTest extends TestCase
{
    #[UsesVendor]
    public function test_courier_command_can_run_in_a_subprocess(): void
    {
        // ...
    }
}
```

<a name="application-timezone"></a>
### Application Timezone

You may override the application timezone for a package test by overriding the `getApplicationTimezone` method:

```php
use Hypervel\Contracts\Foundation\Application;

protected function getApplicationTimezone(Application $app): ?string
{
    return 'UTC';
}
```

<a name="framework-configuration"></a>
### Framework Configuration

The `#[UsesFrameworkConfiguration]` attribute tells Testbench to load Hypervel's framework configuration files directly instead of the test skeleton configuration. This is primarily useful when testing framework packages themselves:

```php
use Hypervel\Testbench\Attributes\UsesFrameworkConfiguration;

#[UsesFrameworkConfiguration]
class FrameworkConfigurationTest extends TestCase
{
    //
}
```

If you need to modify the application before configuration is loaded, use `#[ResolvesHypervel]`:

```php
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Attributes\ResolvesHypervel;

#[ResolvesHypervel('useFixtureConfig')]
class CustomConfigTest extends TestCase
{
    protected function useFixtureConfig(Application $app): void
    {
        $app->useConfigPath(__DIR__.'/Fixtures/config');
    }
}
```

<a name="defining-databases"></a>
## Defining Databases

Hypervel's normal testing traits may be used in Testbench tests:

```php
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabase;
```

Testbench also provides helpers and attributes for loading package migrations into the runtime application.

<a name="loading-migrations"></a>
### Loading Migrations

You may load migrations from a package path using the `loadMigrationsFrom` method:

```php
use function Hypervel\Testbench\workbench_path;

/**
 * Define database migrations.
 */
protected function defineDatabaseMigrations(): void
{
    $this->loadMigrationsFrom(workbench_path('database/migrations'));
}
```

When used with `RefreshDatabase`, Testbench registers the migration paths before the database is refreshed. Otherwise, the migrations are run immediately and rolled back during teardown.

You may also define database migrations with the `#[DefineDatabase]` attribute:

```php
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Attributes\DefineDatabase;

class CourierDatabaseTest extends TestCase
{
    #[DefineDatabase('loadCourierMigrations')]
    public function test_courier_tables_exist(): void
    {
        // ...
    }

    protected function loadCourierMigrations(Application $app): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }
}
```

By default, `#[DefineDatabase]` defers the callback until after the database refresh callback has run. You may disable this behavior by passing `defer: false`:

```php
#[DefineDatabase('loadCourierMigrations', defer: false)]
public function test_courier_tables_exist(): void
{
    // ...
}
```

If your package needs to seed the database after migrations have run, override `defineDatabaseSeeders`:

```php
use Workbench\Database\Seeders\CourierSeeder;

/**
 * Define database seeders.
 */
protected function defineDatabaseSeeders(): void
{
    $this->seed(CourierSeeder::class);
}
```

<a name="loading-hypervel-migrations"></a>
### Loading Hypervel Migrations

If your package test needs Hypervel's default testing migrations, use `loadHypervelMigrations`:

```php
/**
 * Define database migrations.
 */
protected function defineDatabaseMigrations(): void
{
    $this->loadHypervelMigrations();
}
```

The `runHypervelMigrations` method runs all configured Hypervel migrations for the selected connection:

```php
$this->runHypervelMigrations(['--database' => 'testing']);
```

The `#[WithMigration]` attribute may be used on a test class or method:

```php
use Hypervel\Testbench\Attributes\WithMigration;

#[WithMigration]
class CourierDatabaseTest extends TestCase
{
    //
}
```

When no arguments are provided, `#[WithMigration]` loads Hypervel's default testing migrations. The `cache`, `queue`, and `session` aliases are accepted and currently resolve to the default Hypervel testing migrations:

```php
#[WithMigration('queue')]
class CourierQueueTest extends TestCase
{
    //
}
```

Additional named migration sets may be provided when a matching Testbench migration path exists:

```php
#[WithMigration('notifications')]
class CourierNotificationTest extends TestCase
{
    //
}
```

`WithMigration` only resolves named migration sets bundled with Testbench. For package or arbitrary migration directories, use `loadMigrationsFrom()` from `defineDatabaseMigrations()` as described above.

<a name="database-attributes"></a>
### Database Attributes

You may skip tests when a required database driver or version is not available using `#[RequiresDatabase]`:

```php
use Hypervel\Testbench\Attributes\RequiresDatabase;

#[RequiresDatabase('pgsql')]
class PostgresCourierTest extends TestCase
{
    //
}
```

You may provide multiple acceptable drivers:

```php
#[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]
class SqlCourierTest extends TestCase
{
    //
}
```

A version requirement may be supplied as the second argument:

```php
#[RequiresDatabase('pgsql', '>=18')]
public function test_vector_indexes(): void
{
    // ...
}
```

If a test class shares refresh database state across methods and needs to force a clean refresh state before and after the class runs, use `#[ResetRefreshDatabaseState]`:

```php
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;

#[ResetRefreshDatabaseState]
class CourierRefreshDatabaseTest extends TestCase
{
    //
}
```

<a name="defining-routes"></a>
## Defining Routes

You may define routes for your package tests by overriding the `defineRoutes` method:

```php
use Hypervel\Routing\Router;

/**
 * Define routes setup.
 */
protected function defineRoutes(Router $router): void
{
    $router->get('/courier/ping', fn () => 'pong');
}
```

Routes defined in `defineWebRoutes` are automatically grouped with the `web` middleware group:

```php
use Hypervel\Routing\Router;

/**
 * Define web routes setup.
 */
protected function defineWebRoutes(Router $router): void
{
    $router->get('/courier/dashboard', DashboardController::class);
}
```

You may also call a route definition method using `#[DefineRoute]`:

```php
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineRoute;

class CourierRouteTest extends TestCase
{
    #[DefineRoute('courierRoutes')]
    public function test_courier_route(): void
    {
        $this->get('/courier/ping')->assertOk();
    }

    protected function courierRoutes(Router $router): void
    {
        $router->get('/courier/ping', fn () => 'pong');
    }
}
```

<a name="cached-routes"></a>
### Cached Routes

If your package needs to test behavior with cached routes, use `defineCacheRoutes`:

```php
$this->defineCacheRoutes(<<<'PHP'
<?php

use Hypervel\Support\Facades\Route;

Route::get('/courier/ping', fn () => 'pong');
PHP);
```

The `defineCacheRoutes` method writes a temporary route file into the runtime skeleton, runs `route:cache`, reloads the application, and removes the generated files during teardown.

If you need to define routes through the same temporary route-file mechanism without route caching, use `defineStashRoutes`:

```php
$this->defineStashRoutes(function () {
    Route::get('/courier/ping', fn () => 'pong');
});
```

<a name="workbench"></a>
## Workbench

Workbench allows a package to keep a small package-local application inside a `workbench` directory. This is useful when your tests need package routes, controllers, views, translations, factories, seeders, commands, or migrations that are easier to express as application files.

To enable Workbench behavior for a test class, use the `WithWorkbench` concern:

```php
use Hypervel\Testbench\Concerns\WithWorkbench;

class CourierWorkbenchTest extends TestCase
{
    use WithWorkbench;
}
```

Workbench configuration is read from `testbench.yaml`.

<a name="installing-workbench"></a>
### Installing Workbench

You may scaffold a Workbench application for your package using the `package:install` command:

```shell
vendor/bin/testbench package:install
```

The command creates a `workbench` directory, writes a `testbench.yaml` file, adds Workbench PSR-4 autoloading to `composer.json`, creates a SQLite database file for the runtime skeleton, and refreshes Composer's autoloader. The default scaffold is auth-ready and includes a `User` model, factory, seeder, route files, and Workbench discovery.

Existing files are not overwritten unless you pass the `--force` option. To generate only the core Workbench model, factory, seeder, provider, and `testbench.yaml` file, pass the `--basic` option:

```shell
vendor/bin/testbench package:install --basic
```

> [!NOTE]
> Hypervel Testbench does not include Orchestra Workbench's devtool, build, Canvas, or asset-scaffolding commands. Use normal Hypervel frontend tooling alongside the Testbench CLI.

<a name="workbench-configuration"></a>
### Workbench Configuration

The supported `workbench` keys are:

| Key | Description |
| --- | --- |
| `install` | Whether Hypervel's default testing migrations should be included when Workbench migrations are loaded. |
| `auth` | Whether Workbench should include Hypervel's auth service provider when available. |
| `health` | Whether Workbench should register the `/up` health route. |
| `sync` | Directory symlinks that should be created by `package:sync-skeleton`. |
| `discovers` | Workbench files that should be discovered automatically. |

For example:

```yaml
workbench:
  install: true
  auth: true
  health: true
  sync:
    - from: storage
      to: workbench/storage
      reverse: true
  discovers:
    config: true
    factories: true
    web: true
    api: true
    commands: true
    views: true
```

<a name="discovering-workbench-files"></a>
### Discovering Workbench Files

The `discovers` options control which Workbench files are registered:

| Key | Description |
| --- | --- |
| `config` | Loads configuration files from `workbench/config`. These files are merged over Hypervel's default configuration. |
| `factories` | Registers Workbench factory and model name guessers. |
| `web` | Loads `workbench/routes/web.php` using the `web` middleware group. |
| `api` | Loads `workbench/routes/api.php` using the `api` middleware group. |
| `commands` | Loads `workbench/routes/console.php` and discovers command classes under `workbench/app/Console/Commands`. |
| `components` | Controls Workbench Blade component namespace discovery. By default, Workbench registers the `workbench::` component namespace when component classes exist. |
| `views` | Adds `workbench/resources/views` to the view paths. |

If `workbench/resources/views` exists, Workbench also registers the `workbench::` view namespace. Translation files are loaded from `workbench/lang` or `workbench/resources/lang` using the `workbench::` namespace.

Workbench can also detect custom console kernels, HTTP kernels, exception handlers, and user models from conventional Workbench paths:

```text
workbench/app/Console/Kernel.php
workbench/app/Http/Kernel.php
workbench/app/Exceptions/ExceptionHandler.php
workbench/app/Models/User.php
```

<a name="serving-the-workbench-application"></a>
### Serving the Workbench Application

The `serve` command starts the real Hypervel Swoole server for the Workbench application:

```shell
vendor/bin/testbench serve
```

If your package defines a Composer script, you may run it through Composer:

```json
{
    "scripts": {
        "serve": [
            "@php vendor/bin/testbench package:purge-skeleton --ansi",
            "@php vendor/bin/testbench serve --ansi"
        ]
    }
}
```

```shell
composer run serve
```

The `serve` command uses Hypervel's normal server configuration and starts the same Swoole server used by a Hypervel application.

You may pass `--host` and `--port` to temporarily override the configured HTTP server address for the current process:

```shell
vendor/bin/testbench serve --host=127.0.0.1 --port=8001
```

> [!NOTE]
> Unlike Orchestra Testbench, Hypervel's `serve` command does not provide preview-only conveniences such as a welcome page or automatic login.

<a name="syncing-workbench-directories"></a>
### Syncing Workbench Directories

The `sync` configuration creates symlinks between package directories and the runtime skeleton. This is useful when the Workbench application needs access to package storage or public directories:

```yaml
workbench:
  sync:
    - from: storage
      to: workbench/storage
      reverse: true
```

Run `package:sync-skeleton` to copy the Testbench configuration into the runtime skeleton and create configured symlinks:

```shell
vendor/bin/testbench package:sync-skeleton
```

The `reverse` option changes the direction of the symlink. When `reverse` is `false` or omitted, `from` is resolved from the package root and `to` is resolved from the runtime skeleton. When `reverse` is `true`, `from` is resolved from the runtime skeleton and `to` is resolved from the package root.

<a name="command-line"></a>
## Command Line

The `vendor/bin/testbench` binary boots the Testbench CLI application and may be used to run Artisan commands inside the package's runtime skeleton:

```shell
vendor/bin/testbench about

vendor/bin/testbench migrate
```

Testbench also provides commands specifically for package development.

<a name="running-package-tests"></a>
### Running Package Tests

The `package:test` command runs your package tests through PHPUnit or ParaTest:

```shell
vendor/bin/testbench package:test
```

To run tests in parallel, pass the `--parallel` option:

```shell
vendor/bin/testbench package:test --parallel
```

Package tests use the same parallel database, cache, and Redis isolation behavior as application tests.

The command supports the following options:

| Option | Description |
| --- | --- |
| `--without-tty` | Disable TTY output. |
| `--configuration=` | Read PHPUnit configuration from the given XML file. |
| `--coverage` | Collect code coverage. |
| `--min=` | Fail when coverage is below the given percentage. |
| `--parallel` | Run tests in parallel through ParaTest. |
| `--profile` | List the slowest tests. |
| `--recreate-databases` | Re-create test databases before parallel testing. |
| `--drop-databases` | Drop test databases after parallel testing. |
| `--without-databases` | Disable parallel database setup. |
| `--without-cache` | Disable parallel cache prefix setup. |

Additional PHPUnit and ParaTest arguments may be passed after the command options:

```shell
vendor/bin/testbench package:test --parallel --filter=Courier
```

<a name="sqlite-databases"></a>
### SQLite Databases

You may create or drop SQLite database files from the Testbench CLI:

```shell
vendor/bin/testbench package:create-sqlite-db

vendor/bin/testbench package:drop-sqlite-db
```

Both commands accept a `--database` option:

```shell
vendor/bin/testbench package:create-sqlite-db --database=courier.sqlite
```

The `package:create-sqlite-db` command also accepts `--force`, and the `package:drop-sqlite-db` command accepts `--all`.

<a name="purging-the-skeleton"></a>
### Purging the Skeleton

The `package:purge-skeleton` command clears generated files from the runtime skeleton:

```shell
vendor/bin/testbench package:purge-skeleton
```

It clears cached configuration, events, routes, views, configured purge files and directories, runtime SQLite databases, and Workbench symlinks.

<a name="testing-published-files"></a>
## Testing Published Files

The `InteractsWithPublishedFiles` concern provides assertion helpers for testing files generated by commands such as `vendor:publish`, `make:*`, or custom package commands:

```php
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;

class CourierPublishCommandTest extends TestCase
{
    use InteractsWithPublishedFiles;

    public function test_config_can_be_published(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'courier-config',
        ])->assertOk();

        $this->assertFilenameExists('config/courier.php');
        $this->assertFileContains([
            "'driver' => 'array'",
        ], 'config/courier.php');
    }
}
```

Available file assertions include:

```php
$this->assertFileContains([...], 'config/courier.php');
$this->assertFileDoesNotContains([...], 'config/courier.php');
$this->assertFilenameExists('config/courier.php');
$this->assertFilenameDoesNotExists('config/courier.php');
```

Migration-specific assertions are also available:

```php
$this->assertMigrationFileContains([...], 'create_courier_tables.php');
$this->assertMigrationFileDoesNotContains([...], 'create_courier_tables.php');
$this->assertMigrationFileExists('create_courier_tables.php');
$this->assertMigrationFileDoesNotExists('create_courier_tables.php');
```

Each migration assertion accepts an optional directory argument when the migration was published outside the default `database/migrations` directory:

```php
$this->assertMigrationFileExists('create_courier_tables.php', 'migrations');
```

Generated files listed on the test case's `$files` property and generated migration files are removed during teardown.

<a name="helpers"></a>
## Helpers

Testbench provides several helpers for package tests and command-line tooling:

| Helper | Description |
| --- | --- |
| `package_path()` | Resolve a path relative to the package root. |
| `testbench_path()` | Resolve a path relative to the installed Testbench package. |
| `workbench_path()` | Resolve a path relative to the package's Workbench directory. |
| `default_skeleton_path()` | Resolve a path inside the active runtime skeleton copy. |
| `default_migration_path()` | Resolve one of Testbench's default migration paths. |
| `artisan()` | Run an Artisan command against a Testbench application or test case. |
| `remote()` | Run a Testbench CLI command in a subprocess. |
| `workbench()` | Retrieve the resolved Workbench configuration array. |

For example, you may use `remote` when testing commands that need process isolation:

```php
use function Hypervel\Testbench\remote;

$process = remote('queue:work --stop-when-empty', [
    'APP_ENV' => 'testing',
]);

$process->mustRun();
```

The `remote` helper reuses the active Testbench runtime skeleton so subprocesses operate on the same disposable application copy as the parent test process.
