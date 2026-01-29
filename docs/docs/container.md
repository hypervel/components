# Service Container
[[toc]]

## Introduction

Service container in Hyperf follows [PSR-11](https://www.php-fig.org/psr/psr-11/) and provides minimum abilities for managing service bindings. And it's designed for long-life applications and with AOP support.

Based on Hyperf's container, Hypervel extends a bunch of features from Laravel's container such as alias and container events.

The Hypervel service container is a powerful tool for managing class dependencies and performing dependency injection. Dependency injection is a fancy phrase that essentially means this: class dependencies are "injected" into the class via the constructor or, in some cases, "setter" methods.

Let's look at a simple example:

```php
<?php

namespace App\Http\Controllers;

use App\Services\AppleMusic;
use Hyperf\ViewEngine\View;

class PodcastController extends Controller
{
    /**
    * Create a new controller instance.
    */
    public function __construct(
        protected AppleMusic $apple,
    ) {}

    /**
    * Show information about the given podcast.
    */
    public function show(string $id): View
    {
        return view('podcasts.show', [
            'podcast' => $this->apple->findPodcast($id)
        ]);
    }
}
```

In this example, the `PodcastController` needs to retrieve podcasts from a data source such as Apple Music. So, we will **inject** a service that is able to retrieve podcasts. Since the service is injected, we are able to easily "mock", or create a dummy implementation of the `AppleMusic` service when testing our application.

A deep understanding of the Hypervel service container is essential to building a powerful, large application, as well as for contributing to the Laravel core itself.

### Differences between Laravel Container

To ensure compatibility with the Hyperf Container, there are some important differences you need to be aware of:

* Different from Laravel, every binding in the Hyperf Container is a `singleton`. You need to ensure that every instance can be shared across different requests and coroutines. **Do not store states in the objects!** Instead, use [Context](/docs/context) if you need to maintain states within objects.

* If you need to get a new instance rather than fetching the same singleton object from the container, you can use the `make` function. This will initiate a new object and inject related dependencies (note that these dependencies are still singletons).

* The `has` function in the Hyperf Container follows PSR-11 standards. It only determines if the target can be resolved. It will return true if the container can return an entry for the given identifier. `has($id)` returning true does not mean that `get($id)` will not throw an exception. It does, however, mean that `get($id)` will not throw a `NotFoundExceptionInterface`. You can use the `bound` method as an alternative for checking if an ID is bound to the container.

::: important
Please read the above description carefully before using the container in Hypervel!
:::

### Zero Configuration Resolution

If a class has no dependencies or only depends on other concrete classes (not interfaces), the container does not need to be instructed on how to resolve that class. For example, you may place the following code in your `routes/web.php` file:

```php
<?php

class Service
{
    // ...
}

Route::get('/', function (Service $service) {
    dump($service::class);
});
```

In this example, hitting your application's `/` route will automatically resolve the `Service` class and inject it into your route's handler. This is game changing. It means you can develop your application and take advantage of dependency injection without worrying about bloated configuration files.

Thankfully, many of the classes you will be writing when building a Hypervel application automatically receive their dependencies via the container, including [controllers](/docs/controllers), [event listeners](/docs/events), [middleware](/docs/middleware), and more. Once you taste the power of automatic and zero configuration dependency injection it feels impossible to develop without it.

### When to Utilize the Container

Thanks to zero configuration resolution, you will often type-hint dependencies on routes, controllers, event listeners, and elsewhere without ever manually interacting with the container. For example, you might type-hint the `Hypervel\Http\Request` object on your route definition so that you can easily access the current request. Even though we never have to interact with the container to write this code, it is managing the injection of these dependencies behind the scenes:

```php
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;

Route::get('/', function (Request $request) {
    // ...
});
```

In many cases, thanks to automatic dependency injection and [facades](/docs/facades), you can build Hypervel applications without **ever** manually binding or resolving anything from the container. **So, when would you ever manually interact with the container?** Let's examine two situations.

First, if you write a class that implements an interface and you wish to type-hint that interface on a route or class constructor, you must [tell the container how to resolve that interface](#binding-interfaces-to-implementations). Secondly, if you are [writing a Hypervel package](/docs/packages) that you plan to share with other Hypervel developers, you may need to bind your package's services into the container.

## Binding

### Binding Basics

#### Simple Bindings

Almost all of your service container bindings will be registered within [service providers](/docs/providers), so most of these examples will demonstrate using the container in that context.

Within a service provider, you always have access to the container via the `$this->app` property. We can register a binding using the `bind` method, passing the class or interface name that we wish to register along with a closure that returns an instance of the class:

```php
use App\Services\Transistor;
use App\Services\PodcastParser;
use Hypervel\Foundation\Contracts\Application;

$this->app->bind(Transistor::class, function (Application $app) {
    return new Transistor($app->get(PodcastParser::class));
});
```

Note that we receive the container itself as an argument to the resolver. We can then use the container to resolve sub-dependencies of the object we are building.

As mentioned, you will typically be interacting with the container within service providers; however, if you would like to interact with the container outside of a service provider, you may do so via the `App` [facade](/docs/facades):

```php
use App\Services\Transistor;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\Facades\App;

App::bind(Transistor::class, function (Application $app) {
    // ...
});
```

You may use the `bindIf` method to register a container binding only if a binding has not already been registered for the given type:

```php
$this->app->bindIf(Transistor::class, function (Application $app) {
    return new Transistor($app->get(PodcastParser::class));
});
```

::: note
There is no need to bind classes into the container if they do not depend on any interfaces. The container does not need to be instructed on how to build these objects, since it can automatically resolve these objects using reflection.
:::

#### Binding Instances

You may also bind an existing object instance into the container using the `instance` method. The given instance will always be returned on subsequent calls into the container:

```php
use App\Services\Transistor;
use App\Services\PodcastParser;

$service = new Transistor(new PodcastParser);

$this->app->instance(Transistor::class, $service);
```

### Binding Interfaces to Implementations

A very powerful feature of the service container is its ability to bind an interface to a given implementation. For example, let's assume we have an `EventPusher` interface and a `RedisEventPusher` implementation. Once we have coded our `RedisEventPusher` implementation of this interface, we can register it with the service container like so:

```php
use App\Contracts\EventPusher;
use App\Services\RedisEventPusher;

$this->app->bind(EventPusher::class, RedisEventPusher::class);
```

This statement tells the container that it should inject the `RedisEventPusher` when a class needs an implementation of `EventPusher`. Now we can type-hint the `EventPusher` interface in the constructor of a class that is resolved by the container. Remember, controllers, event listeners, middleware, and various other types of classes within Laravel Hyper applications are always resolved using the container:

```php
use App\Contracts\EventPusher;

/**
 * Create a new class instance.
 */
public function __construct(
    protected EventPusher $pusher
) {}
```

### Extending Bindings

The `extend` method allows the modification of resolved services. For example, when a service is resolved, you may run additional code to decorate or configure the service. The `extend` method accepts two arguments, the service class you're extending and a closure that should return the modified service. The closure receives the service being resolved and the container instance:

```php
$this->app->extend(Service::class, function (Service $service, Application $app) {
    return new DecoratedService($service);
});
```

## Resolving

### The `get` Method

You may use the `get` method to resolve a class instance from the container. The `get` method accepts the name of the class or interface you wish to resolve:

```php
use App\Services\Transistor;

$transistor = $this->app->get(Transistor::class);
```

::: important
Every bindings fetched via `get` function is singleton.
:::

### The `make` Method

If you want to get new instance object from the container, you may use the `make` method to resolve a class instance from the container. It's suitable for short-lived use cases.

```php
use App\Services\Transistor;

$transistor = $this->app->make(Transistor::class);
```

If some of your class's dependencies are not resolvable via the container, you may inject them by passing them as an associative array into the `makeWith` method. For example, we may manually pass the `$id` constructor argument required by the `Transistor` service:

```php
use App\Services\Transistor;

$transistor = $this->app->makeWith(Transistor::class, ['id' => 1]);
```

The `bound` method may be used to determine if a class or interface has been explicitly bound in the container:

```php
if ($this->app->bound(Transistor::class)) {
    // ...
}
```

If you are outside of a service provider in a location of your code that does not have access to the `$app` variable, you may use the `App` [facade](/docs/facades) or the `app` [helper](/docs/helpers#method-app) to resolve a class instance from the container:

```php
use App\Services\Transistor;
use Hypervel\Support\Facades\App;

$transistor = App::make(Transistor::class);

$transistor = app(Transistor::class);
```

If you would like to have the Hypervel container instance itself injected into a class that is being resolved by the container, you may type-hint the `Hypervel\Container\Contracts\Container` class on your class's constructor:

```php
use Hypervel\Container\Contracts\Container;

/**
 * Create a new class instance.
 */
public function __construct(
    protected Container $container
) {}
```

::: tip
You can get the container instance by this bindings:

* `app`
* `Psr\Container\ContainerInterface::class`
* `Hyperf\Di\Container::class`
* `Hyperf\Contract\ContainerInterface::class`
* `Hypervel\Container\Contracts\Container::class`
* `Hypervel\Container\Container::class`
* `Hypervel\Foundation\Contracts\Application::class`
* `Hypervel\Foundation\Application::class`
:::

### Automatic Injection

Alternatively, and importantly, you may type-hint the dependency in the constructor of a class that is resolved by the container, including [controllers](/docs/controllers), [event listeners](/docs/events), [middleware](/docs/middleware), and more. In practice, this is how most of your objects should be resolved by the container.

For example, you may type-hint a service defined by your application in a controller's constructor. The service will automatically be resolved and injected into the class:

```php
<?php

namespace App\Http\Controllers;

use App\Services\AppleMusic;

class PodcastController extends Controller
{
    /**
        * Create a new controller instance.
        */
    public function __construct(
        protected AppleMusic $apple,
    ) {}

    /**
     * Show information about the given podcast.
     */
    public function show(string $id): Podcast
    {
        return $this->apple->findPodcast($id);
    }
}
```

## Method Invocation and Injection

Sometimes you may wish to invoke a method on an object instance while allowing the container to automatically inject that method's dependencies. For example, given the following class:

```php
<?php

namespace App;

use App\Services\AppleMusic;

class PodcastStats
{
    /**
     * Generate a new podcast stats report.
     */
    public function generate(AppleMusic $apple): array
    {
        return [
            // ...
        ];
    }
}
```

You may invoke the `generate` method via the container like so:

```php
use App\PodcastStats;
use Hypervel\Support\Facades\App;

$stats = App::call([new PodcastStats, 'generate']);
```

The `call` method accepts any PHP callable. The container's `call` method may even be used to invoke a closure while automatically injecting its dependencies:

```php
use App\Services\AppleMusic;
use Hypervel\Support\Facades\App;

$result = App::call(function (AppleMusic $apple) {
    // ...
});
```

## Container Events

The service container fires an event each time it resolves an object. You may listen to this event using the `resolving` method:

```php
use App\Services\Transistor;
use Hypervel\Foundation\Contracts\Application;

$this->app->resolving(Transistor::class, function (Transistor $transistor, Application $app) {
    // Called when container resolves objects of type "Transistor"...
});

$this->app->resolving(function (mixed $object, Application $app) {
    // Called when container resolves object of any type...
});
```

As you can see, the object being resolved will be passed to the callback, allowing you to set any additional properties on the object before it is given to its consumer.

## PSR-11

Hypervel's service container implements the [PSR-11](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-11-container.md) interface. Therefore, you may type-hint the PSR-11 container interface to obtain an instance of the Hypervel container:

```php
use App\Services\Transistor;
use Psr\Container\ContainerInterface;

Route::get('/', function (ContainerInterface $container) {
    $service = $container->get(Transistor::class);

    // ...
});
```

An exception is thrown if the given identifier can't be resolved. The exception will be an instance of `Psr\Container\NotFoundExceptionInterface` if the identifier was never bound. If the identifier was bound but was unable to be resolved, an instance of `Psr\Container\ContainerExceptionInterface` will be thrown.