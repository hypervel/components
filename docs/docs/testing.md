# Testing: Getting Started
[[toc]]

## Introduction

Hypervel is built with testing in mind. In fact, support for testing with PHPUnit is included out of the box and a `phpunit.xml` file is already set up for your application. The framework also ships with convenient helper methods that allow you to expressively test your applications.

By default, your application's `tests` directory contains two directories: `Feature` and `Unit`. Unit tests are tests that focus on a very small, isolated portion of your code. In fact, most unit tests probably focus on a single method. Feature tests may test a larger portion of your code, including how several objects interact with each other or even a full HTTP request to a JSON endpoint.

An `ExampleTest.php` file is provided in both the `Feature` and `Unit` test directories. After installing a new Hypervel application, run `vendor/bin/phpunit` on the command line to run your tests.

## Environment

When running tests via `vendor/bin/phpunit`, Hypervel will automatically set the configuration environment to `testing` because of the environment variables defined in the `phpunit.xml` file. Hypervel also automatically configures the session and cache to the `array` driver while testing, meaning no session or cache data will be persisted while testing.

You are free to define other testing environment configuration values as necessary. The `testing` environment variables may be configured in the `phpunit.xml` file, but make sure to clear your configuration cache using the `config:clear` Artisan command before running your tests!

## Creating & Running Tests

To create a new test case, use the `make:test` Artisan command:

```shell:no-line-numbers
// Create a test in the Feature directory...
php artisan make:test UserTest

// Create a test in the Unit directory...
php artisan make:test UserTest --unit
```

Once the test has been generated, you may define test methods as you normally would using PHPUnit. To run your tests, execute the `phpunit` or `artisan test` command from your terminal:

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testBasicTest()
    {
        $this->assertTrue(true);
    }
}
```

::: note
If you define your own `setUp` / `tearDown` methods within a test class, be sure to call the respective `parent::setUp()` / `parent::tearDown()` methods on the parent class.
:::

## Running Tests in Coroutines

Sometimes you may want to test your functions running in coroutines. If you extend your testing class from `PHPUnit\Framework\TestCase`, you will have unexpected errors since your logics are not running in coroutines.

```php
use PHPUnit\Framework\TestCase;

class UnitTest extends TestCase
{
    public function testCoroutines(): void
    {
        dump(Coroutine::id());
    }
}
```

In the above example you will get `-1` since there's no coroutine container running outside of your application.

To solve this issue, you can wrap your logics with a coroutine container via `Hypervel\Coroutine\run` function.

```php
use PHPUnit\Framework\TestCase;

use function Hypervel\Coroutine\run;

class UnitTest extends TestCase
{
    public function testCoroutines(): void
    {
        run(function () {
            dump(Coroutine::id());
        });
    }
}
```

A more convenient way to make sure all the tests in this test class will be executed in coroutine environment is by using `RunTestsInCoroutine` trait.

```php
use PHPUnit\Framework\TestCase;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

class UnitTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testCoroutines(): void
    {
        dump(Coroutine::id());
    }
}
```

By this way, coroutine container will be wrapped before executing your test functions except `setUp` and `tearDown`.

::: info

If your test class extends `Tests\TestCase`, then `RunTestsInCoroutine` is included by default. You don't need to require it once again.
:::

### Context in Coroutine Tests

When you want to test your coroutine components, you can mock your context using `Context` directly.

```php
use PHPUnit\Framework\TestCase;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

class UnitTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testCoroutines(): void
    {
        Context::set('auth_context.users.foo');

        // your logics which uses related auth context inside
        // ...
    }
}
```

In traditional test cases, we usually prepare our necessary objects or bootstrapping flows in `setUp` function.

```php
use PHPUnit\Framework\TestCase;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

class UnitTest extends TestCase
{
    use RunTestsInCoroutine;

    public function setUp(): void
    {
        Context::set('auth_context.users.foo');
    }

    public function testCoroutines(): void
    {
        dump(Context::get('auth_context.users.foo'));
    }

    public function tearDown(): void
    {
        Context::destroy('auth_context.users.foo');
    }
}
```

Remember what what we mentioned previously that `setUp` and `tearDown` functions will not be executed in coroutines?

But in the above example you will find you can still get correct mocked context in your test functions. Why is that happening?

That's because behind `RunTestsInCoroutine`, it automatically copies the context from non-coroutine environment to your current coroutine in the test function. If you'd like to disable this behavior, you can disable it by setting `copyNonCoroutineContext` property in your test class.

```php
protected bool $copyNonCoroutineContext = false;
```

::: warning
However, you still need to be very careful for your context states in non-coroutine environment. Because all the non-coroutine environment share the same context, you need to clean these context states manually. Otherwise it will cause states bleeding.
:::

Or you can use `invokeSetupInCoroutine` and `invokeTearDownInCoroutine` functions to make sure they're sharing the coroutine context with your test functions.

```php
use PHPUnit\Framework\TestCase;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

class UnitTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function invokeSetupInCoroutine(): void
    {
        Context::set('auth_context.users.foo');
    }

    protected function invokeTearDownInCoroutine(): void
    {
        // ...
    }

    public function testCoroutines(): void
    {
        dump(Context::get('auth_context.users.foo'));
    }
}
```