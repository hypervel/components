# Testing: Getting Started

- [Introduction](#introduction)
- [Environment](#environment)
- [Creating Tests](#creating-tests)
    - [Running Tests in Coroutines](#running-tests-in-coroutines)
    - [Macro State](#macro-state)
    - [Using Pest](#using-pest)
- [Running Tests](#running-tests)
    - [Running Tests in Parallel](#running-tests-in-parallel)
    - [Reporting Test Coverage](#reporting-test-coverage)
    - [Profiling Tests](#profiling-tests)
- [Configuration and Route Caching](#configuration-and-route-caching)

<a name="introduction"></a>
## Introduction

Hypervel is built with testing in mind. In fact, support for testing with [PHPUnit](https://phpunit.de) is included out of the box and a `phpunit.xml` file is already set up for your application. The framework also ships with convenient helper methods that allow you to expressively test your applications.

By default, your application's `tests` directory contains two directories: `Feature` and `Unit`. Unit tests are tests that focus on a very small, isolated portion of your code. In fact, most unit tests probably focus on a single method. When a unit test does not need the framework booted, you may mark that method with `#[UnitTest]` to keep it inside Hypervel's testing infrastructure while skipping the application boot for that method.

Feature tests may test a larger portion of your code, including how several objects interact with each other or even a full HTTP request to a JSON endpoint. **Generally, most of your tests should be feature tests. These types of tests provide the most confidence that your system as a whole is functioning as intended.**

An `ExampleTest.php` file is provided in both the `Feature` and `Unit` test directories. After installing a new Hypervel application, execute the `vendor/bin/phpunit` or `php artisan test` commands to run your tests.

<a name="environment"></a>
## Environment

When running tests, Hypervel will automatically set the [configuration environment](/docs/{{version}}/configuration#environment-configuration) to `testing` because of the environment variables defined in the `phpunit.xml` file. Hypervel also automatically configures the session and cache to the `array` driver so that no session or cache data will be persisted while testing.

You are free to define other testing environment configuration values as necessary. The `testing` environment variables may be configured in your application's `phpunit.xml` file, but make sure to clear your configuration cache using the `config:clear` Artisan command before running your tests!

<a name="the-env-testing-environment-file"></a>
#### The `.env.testing` Environment File

In addition, you may create a `.env.testing` file in the root of your project. This file will be used instead of the `.env` file when running PHPUnit tests or executing Artisan commands with the `--env=testing` option.

<a name="creating-tests"></a>
## Creating Tests

To create a new test case, use the `make:test` Artisan command. By default, tests will be placed in the `tests/Feature` directory:

```shell
php artisan make:test UserTest
```

If you would like to create a test within the `tests/Unit` directory, you may use the `--unit` option when executing the `make:test` command:

```shell
php artisan make:test UserTest --unit
```

Hypervel application tests should extend your application's `Tests\TestCase` class. This base test case extends Hypervel's coroutine-aware test case, so each test method runs inside the same Swoole coroutine lifecycle that framework services expect:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_basic_test(): void
    {
        $this->assertTrue(true);
    }
}
```

If you have a test class that mostly relies on Hypervel's testing features, but a specific test method does not need the framework booted, you may apply the `#[UnitTest]` attribute to that method to skip booting the application for just that test.

```php
<?php

namespace Tests\Feature;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Tests\TestCase;

class LocationServiceTest extends TestCase
{
    public function test_get_coordinates_resolves_address(): void
    {
        // This test uses Hypervel's testing features...
    }

    #[UnitTest]
    public function test_get_state_returns_state_from_abbreviation(): void
    {
        // This test runs without booting the application...
    }
}
```

> [!NOTE]
> Test stubs may be customized using [stub publishing](/docs/{{version}}/artisan#stub-customization).

> [!WARNING]
> If you define your own `setUp` / `tearDown` methods within a test class, be sure to call the respective `parent::setUp()` / `parent::tearDown()` methods on the parent class. Typically, you should invoke `parent::setUp()` at the start of your own `setUp` method, and `parent::tearDown()` at the end of your `tearDown` method.

<a name="running-tests-in-coroutines"></a>
### Running Tests in Coroutines

Hypervel runs on Swoole, so framework services such as database pools, Redis pools, coroutine context, and request-scoped state expect to execute inside a coroutine. Your application's `Tests\TestCase` class extends `Hypervel\Foundation\Testing\TestCase`, which includes the `RunTestsInCoroutine` trait and automatically wraps each test method in a coroutine container.

Most application tests should keep Hypervel's coroutine wrapper enabled. If a test intentionally verifies behavior outside Hypervel's coroutine runtime, set the `$runTestsInCoroutine` property to `false` on the test class:

```php
<?php

namespace Tests\Unit;

use Hypervel\Coroutine\Coroutine;
use Tests\TestCase;

class NonCoroutineRuntimeTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function test_code_runs_outside_a_coroutine(): void
    {
        $this->assertSame(-1, Coroutine::id());
    }
}
```

The standard PHPUnit `setUp` and `tearDown` methods run outside the test method's coroutine. If your setup or teardown work needs to share the same coroutine-aware lifecycle as the test method, define `setUpInCoroutine` or `tearDownInCoroutine` methods:

```php
<?php

namespace Tests\Feature;

use Hypervel\Context\CoroutineContext;
use Tests\TestCase;

class AuthContextTest extends TestCase
{
    protected function setUpInCoroutine(): void
    {
        CoroutineContext::set('auth_context.users.foo', 'Taylor');
    }

    protected function tearDownInCoroutine(): void
    {
        CoroutineContext::forget('auth_context.users.foo');
    }

    public function test_context_value_is_available(): void
    {
        $this->assertSame('Taylor', CoroutineContext::get('auth_context.users.foo'));
    }
}
```

By default, Hypervel copies coroutine context values prepared outside the test method into the coroutine that runs the test. This allows setup work performed by the testing lifecycle, including database transaction setup, to remain visible to the test method. If you need a test class to start with an isolated coroutine context, set `$copyNonCoroutineContext` to `false`:

```php
protected bool $copyNonCoroutineContext = false;
```

<a name="macro-state"></a>
### Macro State

Macroable classes store registered macros in static state for the life of the PHP process. Typically, macros should be registered during application boot from a [service provider](/docs/{{version}}/providers).

If you register a temporary macro from inside a test, flush that class's macro state before the test finishes so the macro does not affect later tests in the same process:

```php
<?php

namespace Tests\Feature;

use Hypervel\Support\Collection;
use Tests\TestCase;

class CollectionMacroTest extends TestCase
{
    protected function tearDown(): void
    {
        Collection::flushMacros();

        parent::tearDown();
    }

    public function test_collection_macro(): void
    {
        Collection::macro('summary', function () {
            return $this->implode(', ');
        });

        $this->assertSame('first, second', collect(['first', 'second'])->summary());
    }
}
```

<a name="using-pest"></a>
### Using Pest

Pest is not installed, officially supported, or tested by Hypervel 0.4.

If you choose to install and configure Pest yourself, ensure tests that touch Hypervel services use your application's coroutine-aware `Tests\TestCase` class. Otherwise, those tests will not run through Hypervel's coroutine testing lifecycle, and services such as database pools, Redis pools, and coroutine context may not behave correctly.

<a name="running-tests"></a>
## Running Tests

As mentioned previously, once you've written tests, you may run them using `phpunit`:

```shell
./vendor/bin/phpunit
```

In addition to the `phpunit` command, you may use the `test` Artisan command to run your tests. The Artisan test runner provides verbose test reports in order to ease development and debugging:

```shell
php artisan test
```

Any arguments that can be passed to the `phpunit` command may also be passed to the Artisan `test` command:

```shell
php artisan test --testsuite=Feature --stop-on-failure
```

<a name="running-tests-in-parallel"></a>
### Running Tests in Parallel

By default, Hypervel and PHPUnit execute your tests sequentially within a single process. However, you may greatly reduce the amount of time it takes to run your tests by running tests simultaneously across multiple processes. Hypervel's application skeleton includes the `brianium/paratest` Composer package as a development dependency, so you may include the `--parallel` option when executing the `test` Artisan command:

```shell
php artisan test --parallel
```

By default, Hypervel will create as many processes as there are available CPU cores on your machine. However, you may adjust the number of processes using the `--processes` option:

```shell
php artisan test --parallel --processes=4
```

> [!WARNING]
> When running tests in parallel, some PHPUnit options (such as `--do-not-cache-result`) may not be available.

<a name="parallel-testing-and-databases"></a>
#### Parallel Testing and Databases

As long as you have configured a primary database connection, Hypervel automatically handles creating and migrating a test database for each parallel process that is running your tests. The test databases will be suffixed with a process token which is unique per process. For example, if you have two parallel test processes, Hypervel will create and use `your_db_test_1` and `your_db_test_2` test databases.

By default, test databases persist between calls to the `test` Artisan command so that they can be used again by subsequent `test` invocations. However, you may re-create them using the `--recreate-databases` option:

```shell
php artisan test --parallel --recreate-databases
```

If you would like Hypervel to drop the test databases after the parallel test run completes, use the `--drop-databases` option:

```shell
php artisan test --parallel --drop-databases
```

If you need to run tests in parallel without automatically configuring parallel databases or cache prefixes, you may use the `--without-databases` and `--without-cache` options:

```shell
php artisan test --parallel --without-databases --without-cache
```

<a name="parallel-testing-hooks"></a>
#### Parallel Testing Hooks

Occasionally, you may need to prepare certain resources used by your application's tests so they may be safely used by multiple test processes.

Using the `ParallelTesting` facade, you may specify code to be executed on the `setUp` and `tearDown` of a process or test case. The given closures receive the `$token` and `$testCase` variables that contain the process token and the current test case, respectively:

```php
<?php

namespace App\Providers;

use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ParallelTesting::setUpProcess(function (int $token) {
            // ...
        });

        ParallelTesting::setUpTestCase(function (int $token, TestCase $testCase) {
            // ...
        });

        // Executed after a test database is created and before migrations run...
        ParallelTesting::setUpTestDatabaseBeforeMigrating(function (string $database, int $token) {
            // ...
        });

        // Executed when a test database has been migrated...
        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });

        ParallelTesting::tearDownTestCase(function (int $token, TestCase $testCase) {
            // ...
        });

        ParallelTesting::tearDownProcess(function (int $token) {
            // ...
        });
    }
}
```

<a name="accessing-the-parallel-testing-token"></a>
#### Accessing the Parallel Testing Token

If you would like to access the current parallel process "token" from any other location in your application's test code, you may use the `token` method. This token is a unique, string identifier for an individual test process and may be used to segment resources across parallel test processes. For example, Hypervel automatically appends this token to the end of the test databases created by each parallel testing process:

    $token = ParallelTesting::token();

When working with temporary files in parallel tests, you may use the `tempDir` method to generate a per-worker temporary directory:

    $path = ParallelTesting::tempDir('images');

<a name="reporting-test-coverage"></a>
### Reporting Test Coverage

> [!WARNING]
> This feature requires [Xdebug](https://xdebug.org) or [PCOV](https://pecl.php.net/package/pcov).

When running your application tests, you may want to determine whether your test cases are actually covering the application code and how much application code is used when running your tests. To accomplish this, you may provide the `--coverage` option when invoking the `test` command:

```shell
php artisan test --coverage
```

<a name="enforcing-a-minimum-coverage-threshold"></a>
#### Enforcing a Minimum Coverage Threshold

You may use the `--min` option to define a minimum test coverage threshold for your application. The test suite will fail if this threshold is not met:

```shell
php artisan test --coverage --min=80.3
```

<a name="profiling-tests"></a>
### Profiling Tests

The Artisan test runner also includes a convenient mechanism for listing your application's slowest tests. Invoke the `test` command with the `--profile` option to be presented with a list of your ten slowest tests, allowing you to easily investigate which tests can be improved to speed up your test suite:

```shell
php artisan test --profile
```

<a name="configuration-and-route-caching"></a>
## Configuration and Route Caching

When running tests, Hypervel boots the application for each individual test method. Without a cached configuration file, each configuration file in your application must be loaded at the start of a test. To build the configuration once and re-use it for all tests in a single run, you may use the `Hypervel\Foundation\Testing\WithCachedConfig` trait:

```php
<?php

namespace Tests\Feature;

use Hypervel\Foundation\Testing\WithCachedConfig;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    use WithCachedConfig;

    // ...
}
```

If your application has many route files, you may use the `Hypervel\Foundation\Testing\WithCachedRoutes` trait to build your routes once and re-use the compiled routes for all tests in a single run:

```php
<?php

namespace Tests\Feature;

use Hypervel\Foundation\Testing\WithCachedRoutes;
use Tests\TestCase;

class RouteTest extends TestCase
{
    use WithCachedRoutes;

    // ...
}
```
