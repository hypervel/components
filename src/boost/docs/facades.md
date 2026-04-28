# Facades

- [Introduction](#introduction)
- [When to Utilize Facades](#when-to-use-facades)
    - [Facades vs. Dependency Injection](#facades-vs-dependency-injection)
    - [Facades vs. Helper Functions](#facades-vs-helper-functions)
- [How Facades Work](#how-facades-work)
- [Real-Time Facades](#real-time-facades)
- [Facade Class Reference](#facade-class-reference)

<a name="introduction"></a>
## Introduction

Throughout the Laravel documentation, you will see examples of code that interacts with Laravel's features via "facades". Facades provide a "static" interface to classes that are available in the application's [service container](/docs/{{version}}/container). Laravel ships with many facades which provide access to almost all of Laravel's features.

Laravel facades serve as "static proxies" to underlying classes in the service container, providing the benefit of a terse, expressive syntax while maintaining more testability and flexibility than traditional static methods. It's perfectly fine if you don't totally understand how facades work - just go with the flow and continue learning about Laravel.

All of Laravel's facades are defined in the `Hypervel\Support\Facades` namespace. So, we can easily access a facade like so:

```php
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Route;

Route::get('/cache', function () {
    return Cache::get('key');
});
```

Throughout the Laravel documentation, many of the examples will use facades to demonstrate various features of the framework.

<a name="helper-functions"></a>
#### Helper Functions

To complement facades, Laravel offers a variety of global "helper functions" that make it even easier to interact with common Laravel features. Some of the common helper functions you may interact with are `view`, `response`, `url`, `config`, and more. Each helper function offered by Laravel is documented with their corresponding feature; however, a complete list is available within the dedicated [helper documentation](/docs/{{version}}/helpers).

For example, instead of using the `Hypervel\Support\Facades\Response` facade to generate a JSON response, we may simply use the `response` function. Because helper functions are globally available, you do not need to import any classes in order to use them:

```php
use Hypervel\Support\Facades\Response;

Route::get('/users', function () {
    return Response::json([
        // ...
    ]);
});

Route::get('/users', function () {
    return response()->json([
        // ...
    ]);
});
```

<a name="when-to-use-facades"></a>
## When to Utilize Facades

Facades have many benefits. They provide a terse, memorable syntax that allows you to use Laravel's features without remembering long class names that must be injected or configured manually. Furthermore, because of their unique usage of PHP's dynamic methods, they are easy to test.

However, some care must be taken when using facades. The primary danger of facades is class "scope creep". Since facades are so easy to use and do not require injection, it can be easy to let your classes continue to grow and use many facades in a single class. Using dependency injection, this potential is mitigated by the visual feedback a large constructor gives you that your class is growing too large. So, when using facades, pay special attention to the size of your class so that its scope of responsibility stays narrow. If your class is getting too large, consider splitting it into multiple smaller classes.

<a name="facades-vs-dependency-injection"></a>
### Facades vs. Dependency Injection

One of the primary benefits of dependency injection is the ability to swap implementations of the injected class. This is useful during testing since you can inject a mock or stub and assert that various methods were called on the stub.

Typically, it would not be possible to mock or stub a truly static class method. However, since facades use dynamic methods to proxy method calls to objects resolved from the service container, we actually can test facades just as we would test an injected class instance. For example, given the following route:

```php
use Hypervel\Support\Facades\Cache;

Route::get('/cache', function () {
    return Cache::get('key');
});
```

Using Laravel's facade testing methods, we can write the following test to verify that the `Cache::get` method was called with the argument we expected:

```php tab=Pest
use Hypervel\Support\Facades\Cache;

test('basic example', function () {
    Cache::shouldReceive('get')
        ->with('key')
        ->andReturn('value');

    $response = $this->get('/cache');

    $response->assertSee('value');
});
```

```php tab=PHPUnit
use Hypervel\Support\Facades\Cache;

/**
 * A basic functional test example.
 */
public function test_basic_example(): void
{
    Cache::shouldReceive('get')
        ->with('key')
        ->andReturn('value');

    $response = $this->get('/cache');

    $response->assertSee('value');
}
```

<a name="facades-vs-helper-functions"></a>
### Facades vs. Helper Functions

In addition to facades, Laravel includes a variety of "helper" functions which can perform common tasks like generating views, firing events, dispatching jobs, or sending HTTP responses. Many of these helper functions perform the same function as a corresponding facade. For example, this facade call and helper call are equivalent:

```php
return Hypervel\Support\Facades\View::make('profile');

return view('profile');
```

There is absolutely no practical difference between facades and helper functions. When using helper functions, you may still test them exactly as you would the corresponding facade. For example, given the following route:

```php
Route::get('/cache', function () {
    return cache('key');
});
```

The `cache` helper is going to call the `get` method on the class underlying the `Cache` facade. So, even though we are using the helper function, we can write the following test to verify that the method was called with the argument we expected:

```php
use Hypervel\Support\Facades\Cache;

/**
 * A basic functional test example.
 */
public function test_basic_example(): void
{
    Cache::shouldReceive('get')
        ->with('key')
        ->andReturn('value');

    $response = $this->get('/cache');

    $response->assertSee('value');
}
```

<a name="how-facades-work"></a>
## How Facades Work

In a Laravel application, a facade is a class that provides access to an object from the container. The machinery that makes this work is in the `Facade` class. Laravel's facades, and any custom facades you create, will extend the base `Hypervel\Support\Facades\Facade` class.

The `Facade` base class makes use of the `__callStatic()` magic-method to defer calls from your facade to an object resolved from the container. In the example below, a call is made to the Laravel cache system. By glancing at this code, one might assume that the static `get` method is being called on the `Cache` class:

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Support\Facades\Cache;
use Hypervel\View\View;

class UserController extends Controller
{
    /**
     * Show the profile for the given user.
     */
    public function showProfile(string $id): View
    {
        $user = Cache::get('user:'.$id);

        return view('profile', ['user' => $user]);
    }
}
```

Notice that near the top of the file we are "importing" the `Cache` facade. This facade serves as a proxy for accessing the underlying implementation of the `Hypervel\Contracts\Cache\Factory` interface. Any calls we make using the facade will be passed to the underlying instance of Laravel's cache service.

If we look at that `Hypervel\Support\Facades\Cache` class, you'll see that there is no static method `get`:

```php
class Cache extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
}
```

Instead, the `Cache` facade extends the base `Facade` class and defines the method `getFacadeAccessor()`. This method's job is to return the name of a service container binding. When a user references any static method on the `Cache` facade, Laravel resolves the `cache` binding from the [service container](/docs/{{version}}/container) and runs the requested method (in this case, `get`) against that object.

<a name="real-time-facades"></a>
## Real-Time Facades

Using real-time facades, you may treat any class in your application as if it was a facade. To illustrate how this can be used, let's first examine some code that does not use real-time facades. For example, let's assume our `Podcast` model has a `publish` method. However, in order to publish the podcast, we need to inject a `Publisher` instance:

```php
<?php

namespace App\Models;

use App\Contracts\Publisher;
use Hypervel\Database\Eloquent\Model;

class Podcast extends Model
{
    /**
     * Publish the podcast.
     */
    public function publish(Publisher $publisher): void
    {
        $this->update(['publishing' => now()]);

        $publisher->publish($this);
    }
}
```

Injecting a publisher implementation into the method allows us to easily test the method in isolation since we can mock the injected publisher. However, it requires us to always pass a publisher instance each time we call the `publish` method. Using real-time facades, we can maintain the same testability while not being required to explicitly pass a `Publisher` instance. To generate a real-time facade, prefix the namespace of the imported class with `Facades`:

```php
<?php

namespace App\Models;

use App\Contracts\Publisher; // [tl! remove]
use Facades\App\Contracts\Publisher; // [tl! add]
use Hypervel\Database\Eloquent\Model;

class Podcast extends Model
{
    /**
     * Publish the podcast.
     */
    public function publish(Publisher $publisher): void // [tl! remove]
    public function publish(): void // [tl! add]
    {
        $this->update(['publishing' => now()]);

        $publisher->publish($this); // [tl! remove]
        Publisher::publish($this); // [tl! add]
    }
}
```

When the real-time facade is used, the publisher implementation will be resolved out of the service container using the portion of the interface or class name that appears after the `Facades` prefix. When testing, we can use Laravel's built-in facade testing helpers to mock this method call:

```php tab=Pest
<?php

use App\Models\Podcast;
use Facades\App\Contracts\Publisher;
use Hypervel\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

test('podcast can be published', function () {
    $podcast = Podcast::factory()->create();

    Publisher::shouldReceive('publish')->once()->with($podcast);

    $podcast->publish();
});
```

```php tab=PHPUnit
<?php

namespace Tests\Feature;

use App\Models\Podcast;
use Facades\App\Contracts\Publisher;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PodcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A test example.
     */
    public function test_podcast_can_be_published(): void
    {
        $podcast = Podcast::factory()->create();

        Publisher::shouldReceive('publish')->once()->with($podcast);

        $podcast->publish();
    }
}
```

<a name="facade-class-reference"></a>
## Facade Class Reference

Below you will find every facade and its underlying class. This is a useful tool for quickly digging into the API documentation for a given facade root. The [service container binding](/docs/{{version}}/container) key is also included where applicable.

<div class="overflow-auto">

| Facade | Class | Service Container Binding |
| --- | --- | --- |
| App | [Hypervel\Foundation\Application](https://api.hypervel.org/docs/{{version}}/Hypervel/Foundation/Application.html) | `app` |
| Artisan | [Hypervel\Contracts\Console\Kernel](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Console/Kernel.html) | `artisan` |
| Auth (Instance) | [Hypervel\Contracts\Auth\Guard](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Auth/Guard.html) | `auth.driver` |
| Auth | [Hypervel\Auth\AuthManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Auth/AuthManager.html) | `auth` |
| Blade | [Hypervel\View\Compilers\BladeCompiler](https://api.hypervel.org/docs/{{version}}/Hypervel/View/Compilers/BladeCompiler.html) | `blade.compiler` |
| Broadcast (Instance) | [Hypervel\Contracts\Broadcasting\Broadcaster](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Broadcasting/Broadcaster.html) | &nbsp; |
| Broadcast | [Hypervel\Contracts\Broadcasting\Factory](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Broadcasting/Factory.html) | &nbsp; |
| Bus | [Hypervel\Contracts\Bus\Dispatcher](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Bus/Dispatcher.html) | &nbsp; |
| Cache (Instance) | [Hypervel\Cache\Repository](https://api.hypervel.org/docs/{{version}}/Hypervel/Cache/Repository.html) | `cache.store` |
| Cache | [Hypervel\Cache\CacheManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Cache/CacheManager.html) | `cache` |
| Config | [Hypervel\Config\Repository](https://api.hypervel.org/docs/{{version}}/Hypervel/Config/Repository.html) | `config` |
| Context | [Hypervel\Log\Context\Repository](https://api.hypervel.org/docs/{{version}}/Hypervel/Log/Context/Repository.html) | &nbsp; |
| Cookie | [Hypervel\Cookie\CookieJar](https://api.hypervel.org/docs/{{version}}/Hypervel/Cookie/CookieJar.html) | `cookie` |
| Crypt | [Hypervel\Encryption\Encrypter](https://api.hypervel.org/docs/{{version}}/Hypervel/Encryption/Encrypter.html) | `encrypter` |
| Date | [Hypervel\Support\DateFactory](https://api.hypervel.org/docs/{{version}}/Hypervel/Support/DateFactory.html) | `date` |
| DB (Instance) | [Hypervel\Database\Connection](https://api.hypervel.org/docs/{{version}}/Hypervel/Database/Connection.html) | `db.connection` |
| DB | [Hypervel\Database\DatabaseManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Database/DatabaseManager.html) | `db` |
| Event | [Hypervel\Events\Dispatcher](https://api.hypervel.org/docs/{{version}}/Hypervel/Events/Dispatcher.html) | `events` |
| Exceptions (Instance) | [Hypervel\Contracts\Debug\ExceptionHandler](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Debug/ExceptionHandler.html) | &nbsp; |
| Exceptions | [Hypervel\Foundation\Exceptions\Handler](https://api.hypervel.org/docs/{{version}}/Hypervel/Foundation/Exceptions/Handler.html) | &nbsp; |
| File | [Hypervel\Filesystem\Filesystem](https://api.hypervel.org/docs/{{version}}/Hypervel/Filesystem/Filesystem.html) | `files` |
| Gate | [Hypervel\Contracts\Auth\Access\Gate](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Auth/Access/Gate.html) | &nbsp; |
| Hash | [Hypervel\Contracts\Hashing\Hasher](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Hashing/Hasher.html) | `hash` |
| Http | [Hypervel\Http\Client\Factory](https://api.hypervel.org/docs/{{version}}/Hypervel/Http/Client/Factory.html) | &nbsp; |
| Lang | [Hypervel\Translation\Translator](https://api.hypervel.org/docs/{{version}}/Hypervel/Translation/Translator.html) | `translator` |
| Log | [Hypervel\Log\LogManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Log/LogManager.html) | `log` |
| Mail | [Hypervel\Mail\Mailer](https://api.hypervel.org/docs/{{version}}/Hypervel/Mail/Mailer.html) | `mailer` |
| Notification | [Hypervel\Notifications\ChannelManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Notifications/ChannelManager.html) | &nbsp; |
| Password (Instance) | [Hypervel\Auth\Passwords\PasswordBroker](https://api.hypervel.org/docs/{{version}}/Hypervel/Auth/Passwords/PasswordBroker.html) | `auth.password.broker` |
| Password | [Hypervel\Auth\Passwords\PasswordBrokerManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Auth/Passwords/PasswordBrokerManager.html) | `auth.password` |
| Pipeline (Instance) | [Hypervel\Pipeline\Pipeline](https://api.hypervel.org/docs/{{version}}/Hypervel/Pipeline/Pipeline.html) | &nbsp; |
| Process | [Hypervel\Process\Factory](https://api.hypervel.org/docs/{{version}}/Hypervel/Process/Factory.html) | &nbsp; |
| Queue (Base Class) | [Hypervel\Queue\Queue](https://api.hypervel.org/docs/{{version}}/Hypervel/Queue/Queue.html) | &nbsp; |
| Queue (Instance) | [Hypervel\Contracts\Queue\Queue](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Queue/Queue.html) | `queue.connection` |
| Queue | [Hypervel\Queue\QueueManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Queue/QueueManager.html) | `queue` |
| RateLimiter | [Hypervel\Cache\RateLimiter](https://api.hypervel.org/docs/{{version}}/Hypervel/Cache/RateLimiter.html) | &nbsp; |
| Redirect | [Hypervel\Routing\Redirector](https://api.hypervel.org/docs/{{version}}/Hypervel/Routing/Redirector.html) | `redirect` |
| Redis (Instance) | [Hypervel\Redis\Connections\Connection](https://api.hypervel.org/docs/{{version}}/Hypervel/Redis/Connections/Connection.html) | `redis.connection` |
| Redis | [Hypervel\Redis\RedisManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Redis/RedisManager.html) | `redis` |
| Request | [Hypervel\Http\Request](https://api.hypervel.org/docs/{{version}}/Hypervel/Http/Request.html) | `request` |
| Response (Instance) | [Hypervel\Http\Response](https://api.hypervel.org/docs/{{version}}/Hypervel/Http/Response.html) | &nbsp; |
| Response | [Hypervel\Contracts\Routing\ResponseFactory](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Routing/ResponseFactory.html) | &nbsp; |
| Route | [Hypervel\Routing\Router](https://api.hypervel.org/docs/{{version}}/Hypervel/Routing/Router.html) | `router` |
| Schedule | [Hypervel\Console\Scheduling\Schedule](https://api.hypervel.org/docs/{{version}}/Hypervel/Console/Scheduling/Schedule.html) | &nbsp; |
| Schema | [Hypervel\Database\Schema\Builder](https://api.hypervel.org/docs/{{version}}/Hypervel/Database/Schema/Builder.html) | &nbsp; |
| Session (Instance) | [Hypervel\Session\Store](https://api.hypervel.org/docs/{{version}}/Hypervel/Session/Store.html) | `session.store` |
| Session | [Hypervel\Session\SessionManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Session/SessionManager.html) | `session` |
| Storage (Instance) | [Hypervel\Contracts\Filesystem\Filesystem](https://api.hypervel.org/docs/{{version}}/Hypervel/Contracts/Filesystem/Filesystem.html) | `filesystem.disk` |
| Storage | [Hypervel\Filesystem\FilesystemManager](https://api.hypervel.org/docs/{{version}}/Hypervel/Filesystem/FilesystemManager.html) | `filesystem` |
| URL | [Hypervel\Routing\UrlGenerator](https://api.hypervel.org/docs/{{version}}/Hypervel/Routing/UrlGenerator.html) | `url` |
| Validator (Instance) | [Hypervel\Validation\Validator](https://api.hypervel.org/docs/{{version}}/Hypervel/Validation/Validator.html) | &nbsp; |
| Validator | [Hypervel\Validation\Factory](https://api.hypervel.org/docs/{{version}}/Hypervel/Validation/Factory.html) | `validator` |
| View (Instance) | [Hypervel\View\View](https://api.hypervel.org/docs/{{version}}/Hypervel/View/View.html) | &nbsp; |
| View | [Hypervel\View\Factory](https://api.hypervel.org/docs/{{version}}/Hypervel/View/Factory.html) | `view` |
| Vite | [Hypervel\Foundation\Vite](https://api.hypervel.org/docs/{{version}}/Hypervel/Foundation/Vite.html) | &nbsp; |

</div>
