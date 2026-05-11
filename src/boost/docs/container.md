# Service Container

- [Introduction](#introduction)
    - [Zero Configuration Resolution](#zero-configuration-resolution)
    - [When to Utilize the Container](#when-to-use-the-container)
- [Resolution Lifecycles](#resolution-lifecycles)
    - [Choosing a Lifecycle](#choosing-a-lifecycle)
    - [Per-Call State on Shared Instances](#per-call-state-on-shared-instances)
- [Binding](#binding)
    - [Binding Basics](#binding-basics)
    - [Binding Interfaces to Implementations](#binding-interfaces-to-implementations)
    - [Contextual Binding](#contextual-binding)
    - [Contextual Attributes](#contextual-attributes)
    - [Binding Primitives](#binding-primitives)
    - [Binding Typed Variadics](#binding-typed-variadics)
    - [Tagging](#tagging)
    - [Extending Bindings](#extending-bindings)
- [Resolving](#resolving)
    - [The Make Method](#the-make-method)
    - [Forcing a Fresh Instance](#forcing-a-fresh-instance)
    - [Self-Building Classes](#self-building-classes)
    - [Automatic Injection](#automatic-injection)
- [Method Invocation and Injection](#method-invocation-and-injection)
- [Container Events](#container-events)
    - [Rebinding](#rebinding)
- [PSR-11](#psr-11)

<a name="introduction"></a>
## Introduction

The Hypervel service container is a powerful tool for managing class dependencies and performing dependency injection. "Dependency injection" essentially means this: class dependencies are "injected" into the class via the constructor or, in some cases, "setter" methods.

Let's look at a simple example:

```php
<?php

namespace App\Http\Controllers;

use App\Services\AppleMusic;
use Hypervel\View\View;

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

Hypervel's container is similar to Laravel's but resolves under a long-running Swoole worker. The bindings, attributes, and resolution helpers all behave like Laravel's, but instance caching is more aggressive and the per-request lifecycle is keyed to a coroutine rather than a fresh PHP process. See [Resolution Lifecycles](#resolution-lifecycles) for the behaviors that differ.

<a name="zero-configuration-resolution"></a>
### Zero Configuration Resolution

If a class has no dependencies or only depends on other concrete classes (not interfaces), the container does not need to be instructed on how to resolve that class. For example, you may place the following code in your `routes/web.php` file:

```php
<?php

class Service
{
    // ...
}

Route::get('/', function (Service $service) {
    dd($service::class);
});
```

In this example, hitting your application's `/` route will automatically resolve the `Service` class and inject it into your route's handler. This is game changing. It means you can develop your application and take advantage of dependency injection without worrying about bloated configuration files.

Thankfully, many of the classes you will be writing when building a Hypervel application automatically receive their dependencies via the container, including [controllers](/docs/{{version}}/controllers), [event listeners](/docs/{{version}}/events), [middleware](/docs/{{version}}/middleware), and more. Additionally, you may type-hint dependencies in the `handle` method of [queued jobs](/docs/{{version}}/queues). Once you taste the power of automatic and zero configuration dependency injection it feels impossible to develop without it.

> [!NOTE]
> Hypervel will auto-singleton (automatically cache) unbound concrete classes for the worker's lifetime — the first resolution of `Service` constructs an instance, and every subsequent resolution returns that same instance until the worker restarts. This is the right default for stateless services. Classes whose constructors capture per-call state should be [bound explicitly](#binding) or resolved with [`build()`](#forcing-a-fresh-instance).

<a name="when-to-use-the-container"></a>
### When to Utilize the Container

Thanks to zero configuration resolution, you will often type-hint dependencies on routes, controllers, event listeners, and elsewhere without ever manually interacting with the container. For example, you might type-hint the `Hypervel\Http\Request` object on your route definition so that you can easily access the current request. Even though we never have to interact with the container to write this code, it is managing the injection of these dependencies behind the scenes:

```php
use Hypervel\Http\Request;

Route::get('/', function (Request $request) {
    // ...
});
```

In many cases, thanks to automatic dependency injection and [facades](/docs/{{version}}/facades), you can build Hypervel applications without **ever** manually binding or resolving anything from the container. **So, when would you ever manually interact with the container?** Let's examine two situations.

First, if you write a class that implements an interface and you wish to type-hint that interface on a route or class constructor, you must [tell the container how to resolve that interface](#binding-interfaces-to-implementations). Secondly, if you are [writing a package](/docs/{{version}}/packages) that you plan to share with other Hypervel developers, you may need to bind your package's services into the container.

<a name="resolution-lifecycles"></a>
## Resolution Lifecycles

Because Hypervel runs inside a long-running Swoole worker, instance lifecycles are different from a traditional PHP-FPM application. The same worker process handles thousands of requests, so the container caches instances aggressively to avoid rebuilding stateless services on every call. The table below summarizes the public resolution methods and what each one returns:

| Need | Method | Behavior |
|---|---|---|
| Fresh instance every call, ignoring all bindings and caches | `build($class)` | Always constructs a new instance. Nested constructor dependencies are still resolved through the container. |
| Fresh instance with parameter overrides | `buildWith($class, $params)` | Same as `build()` but applies the given parameter overrides during construction. |
| Class declares its own factory | `implements SelfBuilding` + static `newInstance()` | Container invokes the static factory with DI on its parameters. Skips auto-singletoning by default; honors any explicit `singleton()` / `scoped()` binding. |
| Resolve respecting bindings and caching | `make($class)` | Honors `bind()` / `singleton()` / `scoped()`. Auto-singletons unbound concrete classes for the worker's lifetime. |
| Resolve with parameter overrides | `make($class, $params)` / `makeWith()` | Same as `make()` but contextual parameters bypass all caching. |
| One instance per worker | `$app->singleton($abstract, ...)` or `#[Singleton]` | Cached for the worker's lifetime. Lives until the worker restarts. |
| One instance per coroutine (per request / job) | `$app->scoped($abstract, ...)` or `#[Scoped]` | Cached in [CoroutineContext](/docs/{{version}}/context) for the lifetime of the coroutine handling the request or job. |
| Fresh every call by binding | `$app->bind($abstract, ...)` | A new instance every `make()`. |
| Pre-constructed instance | `$app->instance($abstract, $obj)` | Returns the exact object that was passed, every time. |
| PSR-11 compliance | `get($id)` / `has($id)` | PSR-11 wrappers around `make()` and `bound()`. |

<a name="choosing-a-lifecycle"></a>
### Choosing a Lifecycle

Most application code does not pick a lifecycle deliberately — it just type-hints a dependency and lets the container do the rest. When you do need to choose:

- **Stateless service that does the same work on every call** (formatters, parsers, manager classes): no binding needed. Auto-singletoning gives you the right behavior for free.
- **Service that should hold per-request state** (request-derived caches, accumulated context): `scoped()`. The instance dies at the end of the coroutine (request / job).
- **Service that should be built once and shared for the worker's lifetime** (heavy bootstrap, immutable configuration): `singleton()` is explicit; auto-singletoning achieves the same thing for unbound classes.
- **Object that takes per-call inputs in its constructor** (builders, view components, form-request-style classes): `bind()` so each `make()` returns fresh, or skip the binding entirely and call `build()` / `buildWith()` at the resolution site.

<a name="per-call-state-on-shared-instances"></a>
### Per-Call State on Shared Instances

A class whose `__construct` reads request data, session data, or other per-call context into instance properties is not safe to auto-singleton. The first resolution freezes the captured values, and every subsequent `make()` hands back the frozen instance. Make sure such classes are either registered with `bind()` or instantiated with [`build()`](#forcing-a-fresh-instance):

```php
class ReportBuilder
{
    protected array $filters;

    public function __construct(Request $request)
    {
        $this->filters = $request->input('filters', []);
    }
}

// Wrong — auto-singletoned on first call, captured filters become stale.
$report = $this->app->make(ReportBuilder::class);

// Right — fresh instance per call.
$report = $this->app->build(ReportBuilder::class);
```

Alternatively, mark the class with [`SelfBuilding`](#self-building-classes) and leave it unbound — every `make()` will then call `newInstance` and rebuild the instance from scratch.

The same caution applies to mutating state on a worker-lifetime singleton at runtime — anything you assign to `$this->foo` on a shared instance persists across every request that worker handles. For per-request state that lives on a shared service, use [CoroutineContext](/docs/{{version}}/context) instead of instance properties.

Framework code that ships with Hypervel — view components, form requests, and so on — already routes through the fresh-instance path when needed, so you only need to think about this for your own classes.

<a name="binding"></a>
## Binding

<a name="binding-basics"></a>
### Binding Basics

<a name="simple-bindings"></a>
#### Simple Bindings

Almost all of your service container bindings will be registered within [service providers](/docs/{{version}}/providers), so most of these examples will demonstrate using the container in that context.

Within a service provider, you always have access to the container via the `$this->app` property. We can register a binding using the `bind` method, passing the class or interface name that we wish to register along with a closure that returns an instance of the class:

```php
use App\Services\Transistor;
use App\Services\PodcastParser;
use Hypervel\Contracts\Foundation\Application;

$this->app->bind(Transistor::class, function (Application $app) {
    return new Transistor($app->make(PodcastParser::class));
});
```

Note that we receive the container itself as an argument to the resolver. We can then use the container to resolve sub-dependencies of the object we are building.

As mentioned, you will typically be interacting with the container within service providers; however, if you would like to interact with the container outside of a service provider, you may do so via the `App` [facade](/docs/{{version}}/facades):

```php
use App\Services\Transistor;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\App;

App::bind(Transistor::class, function (Application $app) {
    // ...
});
```

You may use the `bindIf` method to register a container binding only if a binding has not already been registered for the given type:

```php
$this->app->bindIf(Transistor::class, function (Application $app) {
    return new Transistor($app->make(PodcastParser::class));
});
```

For convenience, you may omit providing the class or interface name that you wish to register as a separate argument and instead allow Hypervel to infer the type from the return type of the closure you provide to the `bind` method:

```php
App::bind(function (Application $app): Transistor {
    return new Transistor($app->make(PodcastParser::class));
});
```

> [!NOTE]
> You don't need to bind classes the container can resolve via reflection. Hypervel will auto-singleton the resolved instance for the worker's lifetime, which is the right behavior for stateless services. Bind the class explicitly with `bind()` if you need a fresh instance per call, or call [`build()`](#forcing-a-fresh-instance) at the resolution site.

<a name="binding-a-singleton"></a>
#### Binding A Singleton

The `singleton` method binds a class or interface into the container that should only be resolved one time for the lifetime of the worker process. Once a singleton binding is resolved, the same object instance will be returned on every subsequent call into the container until the worker restarts:

```php
use App\Services\Transistor;
use App\Services\PodcastParser;
use Hypervel\Contracts\Foundation\Application;

$this->app->singleton(Transistor::class, function (Application $app) {
    return new Transistor($app->make(PodcastParser::class));
});
```

You may use the `singletonIf` method to register a singleton container binding only if a binding has not already been registered for the given type:

```php
$this->app->singletonIf(Transistor::class, function (Application $app) {
    return new Transistor($app->make(PodcastParser::class));
});
```

<a name="singleton-attribute"></a>
#### Singleton Attribute

Alternatively, you may mark an interface or class with the `#[Singleton]` attribute to indicate to the container that it should be resolved one time:

```php
<?php

namespace App\Services;

use Hypervel\Container\Attributes\Singleton;

#[Singleton]
class Transistor
{
    // ...
}
```

<a name="binding-scoped"></a>
#### Binding Scoped Singletons

The `scoped` method binds a class or interface into the container that should only be resolved one time per coroutine. Each HTTP request and each queued job runs in its own coroutine, so a scoped binding behaves like a per-request singleton. The instance is stored in [CoroutineContext](/docs/{{version}}/context) and is automatically discarded when the coroutine ends:

```php
use App\Services\Transistor;
use App\Services\PodcastParser;
use Hypervel\Contracts\Foundation\Application;

$this->app->scoped(Transistor::class, function (Application $app) {
    return new Transistor($app->make(PodcastParser::class));
});
```

You may use the `scopedIf` method to register a scoped container binding only if a binding has not already been registered for the given type:

```php
$this->app->scopedIf(Transistor::class, function (Application $app) {
    return new Transistor($app->make(PodcastParser::class));
});
```

<a name="scoped-attribute"></a>
#### Scoped Attribute

Alternatively, you may mark an interface or class with the `#[Scoped]` attribute to indicate to the container that it should be resolved one time per coroutine (the equivalent of one time per request or queued job):

```php
<?php

namespace App\Services;

use Hypervel\Container\Attributes\Scoped;

#[Scoped]
class Transistor
{
    // ...
}
```

<a name="binding-instances"></a>
#### Binding Instances

You may also bind an existing object instance into the container using the `instance` method. The given instance will always be returned on subsequent calls into the container:

```php
use App\Services\Transistor;
use App\Services\PodcastParser;

$service = new Transistor(new PodcastParser);

$this->app->instance(Transistor::class, $service);
```

<a name="binding-interfaces-to-implementations"></a>
### Binding Interfaces to Implementations

A very powerful feature of the service container is its ability to bind an interface to a given implementation. For example, let's assume we have an `EventPusher` interface and a `RedisEventPusher` implementation. Once we have coded our `RedisEventPusher` implementation of this interface, we can register it with the service container like so:

```php
use App\Contracts\EventPusher;
use App\Services\RedisEventPusher;

$this->app->bind(EventPusher::class, RedisEventPusher::class);
```

This statement tells the container that it should inject the `RedisEventPusher` when a class needs an implementation of `EventPusher`. Now we can type-hint the `EventPusher` interface in the constructor of a class that is resolved by the container. Remember, controllers, event listeners, middleware, and various other types of classes within Hypervel applications are always resolved using the container:

```php
use App\Contracts\EventPusher;

/**
 * Create a new class instance.
 */
public function __construct(
    protected EventPusher $pusher,
) {}
```

<a name="bind-attribute"></a>
#### Bind Attribute

Hypervel also provides a `Bind` attribute for added convenience. You can apply this attribute to any interface to tell Hypervel which implementation should be automatically injected whenever that interface is requested. When using the `Bind` attribute, there is no need to perform any additional service registration in your application's service providers.

In addition, multiple `Bind` attributes may be placed on an interface in order to configure a different implementation that should be injected for a given set of environments:

```php
<?php

namespace App\Contracts;

use App\Services\FakeEventPusher;
use App\Services\RedisEventPusher;
use Hypervel\Container\Attributes\Bind;

#[Bind(RedisEventPusher::class)]
#[Bind(FakeEventPusher::class, environments: ['local', 'testing'])]
interface EventPusher
{
    // ...
}
```

Furthermore, [Singleton](#singleton-attribute) and [Scoped](#scoped-attribute) attributes may be applied to indicate if the container bindings should be resolved once or once per coroutine (per request / job lifecycle):

```php
use App\Services\RedisEventPusher;
use Hypervel\Container\Attributes\Bind;
use Hypervel\Container\Attributes\Singleton;

#[Bind(RedisEventPusher::class)]
#[Singleton]
interface EventPusher
{
    // ...
}
```

<a name="contextual-binding"></a>
### Contextual Binding

Sometimes you may have two classes that utilize the same interface, but you wish to inject different implementations into each class. For example, two controllers may depend on different implementations of the `Hypervel\Contracts\Filesystem\Filesystem` [contract](/docs/{{version}}/contracts). Hypervel provides a simple, fluent interface for defining this behavior:

```php
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\VideoController;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Support\Facades\Storage;

$this->app->when(PhotoController::class)
    ->needs(Filesystem::class)
    ->give(function () {
        return Storage::disk('local');
    });

$this->app->when([VideoController::class, UploadController::class])
    ->needs(Filesystem::class)
    ->give(function () {
        return Storage::disk('s3');
    });
```

<a name="contextual-attributes"></a>
### Contextual Attributes

Since contextual binding is often used to inject implementations of drivers or configuration values, Hypervel offers a variety of contextual binding attributes that allow to inject these types of values without manually defining the contextual bindings in your service providers.

For example, the `Storage` attribute may be used to inject a specific [storage disk](/docs/{{version}}/filesystem):

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Container\Attributes\Storage;
use Hypervel\Contracts\Filesystem\Filesystem;

class PhotoController extends Controller
{
    public function __construct(
        #[Storage('local')] protected Filesystem $filesystem
    ) {
        // ...
    }
}
```

In addition to the `Storage` attribute, Hypervel offers `Auth`, `Cache`, `Config`, `Context`, `Database` (with `DB` as a short alias), `Give`, `Log`, `RouteParameter`, and [Tag](#tagging) attributes:

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\UserRepository;
use App\Models\Photo;
use App\Repositories\DatabaseRepository;
use Hypervel\Container\Attributes\Auth;
use Hypervel\Container\Attributes\Cache;
use Hypervel\Container\Attributes\Config;
use Hypervel\Container\Attributes\Context;
use Hypervel\Container\Attributes\DB;
use Hypervel\Container\Attributes\Give;
use Hypervel\Container\Attributes\Log;
use Hypervel\Container\Attributes\RouteParameter;
use Hypervel\Container\Attributes\Tag;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Database\Connection;
use Psr\Log\LoggerInterface;

class PhotoController extends Controller
{
    public function __construct(
        #[Auth('web')] protected Guard $auth,
        #[Cache('redis')] protected Repository $cache,
        #[Config('app.timezone')] protected string $timezone,
        #[Context('uuid')] protected string $uuid,
        #[Context('ulid', hidden: true)] protected string $ulid,
        #[DB('mysql')] protected Connection $connection,
        #[Give(DatabaseRepository::class)] protected UserRepository $users,
        #[Log('daily')] protected LoggerInterface $log,
        #[RouteParameter('photo')] protected Photo $photo,
        #[Tag('reports')] protected iterable $reports,
    ) {
        // ...
    }
}
```

Furthermore, Hypervel provides `CurrentUser` and `Authenticated` attributes for injecting the currently authenticated user into a given route or class. `CurrentUser` requires that a user is authenticated; `Authenticated` returns `null` when no user is authenticated, which is useful for optional auth:

```php
use App\Models\User;
use Hypervel\Container\Attributes\Authenticated;
use Hypervel\Container\Attributes\CurrentUser;
use Hypervel\Contracts\Auth\Authenticatable;

Route::get('/user', function (#[CurrentUser] User $user) {
    return $user;
})->middleware('auth');

Route::get('/profile', function (#[Authenticated('web')] ?Authenticatable $user) {
    return $user?->name ?? 'guest';
});
```

<a name="defining-custom-attributes"></a>
#### Defining Custom Attributes

You can create your own contextual attributes by implementing the `Hypervel\Contracts\Container\ContextualAttribute` contract. The container will call your attribute's `resolve` method, which should resolve the value that should be injected into the class utilizing the attribute. In the example below, we will re-implement Hypervel's built-in `Config` attribute:

```php
<?php

namespace App\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Config implements ContextualAttribute
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(public string $key, public mixed $default = null)
    {
    }

    /**
     * Resolve the configuration value.
     *
     * @param  self  $attribute
     * @param  \Hypervel\Contracts\Container\Container  $container
     * @return mixed
     */
    public static function resolve(self $attribute, Container $container)
    {
        return $container->make('config')->get($attribute->key, $attribute->default);
    }
}
```

<a name="binding-primitives"></a>
### Binding Primitives

Sometimes you may have a class that receives some injected classes, but also needs an injected primitive value such as an integer. You may easily use contextual binding to inject any value your class may need:

```php
use App\Http\Controllers\UserController;

$this->app->when(UserController::class)
    ->needs('$variableName')
    ->give($value);
```

Sometimes a class may depend on an array of [tagged](#tagging) instances. Using the `giveTagged` method, you may easily inject all of the container bindings with that tag:

```php
$this->app->when(ReportAggregator::class)
    ->needs('$reports')
    ->giveTagged('reports');
```

If you need to inject a value from one of your application's configuration files, you may use the `giveConfig` method:

```php
$this->app->when(ReportAggregator::class)
    ->needs('$timezone')
    ->giveConfig('app.timezone');
```

<a name="binding-typed-variadics"></a>
### Binding Typed Variadics

Occasionally, you may have a class that receives an array of typed objects using a variadic constructor argument:

```php
<?php

use App\Models\Filter;
use App\Services\Logger;

class Firewall
{
    /**
     * The filter instances.
     *
     * @var array
     */
    protected $filters;

    /**
     * Create a new class instance.
     */
    public function __construct(
        protected Logger $logger,
        Filter ...$filters,
    ) {
        $this->filters = $filters;
    }
}
```

Using contextual binding, you may resolve this dependency by providing the `give` method with a closure that returns an array of resolved `Filter` instances:

```php
$this->app->when(Firewall::class)
    ->needs(Filter::class)
    ->give(function (Application $app) {
          return [
              $app->make(NullFilter::class),
              $app->make(ProfanityFilter::class),
              $app->make(TooLongFilter::class),
          ];
    });
```

For convenience, you may also just provide an array of class names to be resolved by the container whenever `Firewall` needs `Filter` instances:

```php
$this->app->when(Firewall::class)
    ->needs(Filter::class)
    ->give([
        NullFilter::class,
        ProfanityFilter::class,
        TooLongFilter::class,
    ]);
```

<a name="variadic-tag-dependencies"></a>
#### Variadic Tag Dependencies

Sometimes a class may have a variadic dependency that is type-hinted as a given class (`Report ...$reports`). Using the `needs` and `giveTagged` methods, you may easily inject all of the container bindings with that [tag](#tagging) for the given dependency:

```php
$this->app->when(ReportAggregator::class)
    ->needs(Report::class)
    ->giveTagged('reports');
```

<a name="tagging"></a>
### Tagging

Occasionally, you may need to resolve all of a certain "category" of binding. For example, perhaps you are building a report analyzer that receives an array of many different `Report` interface implementations. After registering the `Report` implementations, you can assign them a tag using the `tag` method:

```php
$this->app->bind(CpuReport::class, function () {
    // ...
});

$this->app->bind(MemoryReport::class, function () {
    // ...
});

$this->app->tag([CpuReport::class, MemoryReport::class], 'reports');
```

Once the services have been tagged, you may easily resolve them all via the container's `tagged` method:

```php
$this->app->bind(ReportAnalyzer::class, function (Application $app) {
    return new ReportAnalyzer($app->tagged('reports'));
});
```

<a name="extending-bindings"></a>
### Extending Bindings

The `extend` method allows the modification of resolved services. For example, when a service is resolved, you may run additional code to decorate or configure the service. The `extend` method accepts two arguments, the service class you're extending and a closure that should return the modified service. The closure receives the service being resolved and the container instance:

```php
$this->app->extend(Service::class, function (Service $service, Application $app) {
    return new DecoratedService($service);
});
```

<a name="resolving"></a>
## Resolving

<a name="the-make-method"></a>
### The `make` Method

You may use the `make` method to resolve a class instance from the container. The `make` method accepts the name of the class or interface you wish to resolve:

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

If you are outside of a service provider in a location of your code that does not have access to the `$app` variable, you may use the `App` [facade](/docs/{{version}}/facades) or the `app` [helper](/docs/{{version}}/helpers#method-app) to resolve a class instance from the container:

```php
use App\Services\Transistor;
use Hypervel\Support\Facades\App;

$transistor = App::make(Transistor::class);

$transistor = app(Transistor::class);
```

If you would like to have the container instance itself injected into a class that is being resolved by the container, you may type-hint the `Hypervel\Container\Container` class on your class's constructor:

```php
use Hypervel\Container\Container;

/**
 * Create a new class instance.
 */
public function __construct(
    protected Container $container,
) {}
```

<a name="forcing-a-fresh-instance"></a>
### Forcing a Fresh Instance

When you need an instance that is guaranteed not to come from any cache, use the `build` or `buildWith` methods. These methods bypass binding lookups, scoped and singleton caching, and the auto-singleton optimization, so each call returns a freshly constructed instance:

```php
use App\Services\Transistor;

$fresh = $this->app->build(Transistor::class);

$fresh = $this->app->buildWith(Transistor::class, ['id' => 1]);
```

Nested constructor dependencies are still resolved through the container, so they pick up bindings, contextual bindings, resolving callbacks, and constructor-parameter attribute injection as normal. For the class being built itself, `build` and `buildWith` skip the container's lifecycle machinery — they bypass binding lookups, contextual binding for that abstract, and resolving callbacks. Class-level attribute callbacks registered via `afterResolvingAttribute()` still fire. `#[Singleton]` and `#[Scoped]` are intentionally ignored by `build()` — they're caching markers that only apply via `make()`. This is what makes `build()` reliable as "always fresh."

`buildWith` is the right choice when a class needs parameter overrides and must not be cached, for example a builder object or a class whose constructor captures per-call state. Internally, Hypervel uses this method to instantiate view components so each render gets a fresh instance even though the component class has no explicit binding.

<a name="self-building-classes"></a>
### Self-Building Classes

When a class needs fresh construction *and* container-resolvable dependencies, mark it with the `Hypervel\Contracts\Container\SelfBuilding` marker interface and provide a static `newInstance` method. The container calls `newInstance` on every resolution and resolves its parameters via dependency injection, the same way it resolves a controller method:

```php
<?php

namespace App\Services;

use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\Http\Request;

class WeeklyDigest implements SelfBuilding
{
    public function __construct(
        public readonly string $audience,
        public readonly array $filters,
    ) {
    }

    public static function newInstance(Request $current): static
    {
        return new static(
            audience: $current->user()->email,
            filters: $current->input('filters', []),
        );
    }
}
```

By default, self-building classes are excluded from auto-singletoning, so every `make()` calls `newInstance` and returns a fresh instance. The container still fires `resolving` and `afterResolving` callbacks around the result, so anything layered on top of resolution (for example, validation callbacks) keeps working.

`SelfBuilding` is the right choice when fresh construction depends on container-resolvable values that aren't available at the call site. `buildWith` covers the inverse case — fresh construction with caller-supplied parameters. Hypervel uses `SelfBuilding` internally for `FormRequest`: every controller method that type-hints a form request gets a new instance hydrated from the current coroutine's `Request`.

Explicit caching bindings still apply on top of `SelfBuilding`. Bind a self-building class with `singleton()` / `scoped()` (or apply `#[Singleton]` / `#[Scoped]`) and the container runs `newInstance` once and caches the result — a useful pattern for lazy custom construction of a service that's stateless once built. Only do this when the cached instance is safe to share. Classes whose `newInstance` reads per-call state must stay unbound so each resolution rebuilds from the current request; that's why nothing in the framework binds `FormRequest`.

<a name="automatic-injection"></a>
### Automatic Injection

Alternatively, and importantly, you may type-hint the dependency in the constructor of a class that is resolved by the container, including [controllers](/docs/{{version}}/controllers), [event listeners](/docs/{{version}}/events), [middleware](/docs/{{version}}/middleware), and more. Additionally, you may type-hint dependencies in the `handle` method of [queued jobs](/docs/{{version}}/queues). In practice, this is how most of your objects should be resolved by the container.

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

<a name="method-invocation-and-injection"></a>
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

<a name="container-events"></a>
## Container Events

The service container fires an event each time it resolves an object. You may listen to this event using the `resolving` method:

```php
use App\Services\Transistor;
use Hypervel\Contracts\Foundation\Application;

$this->app->resolving(Transistor::class, function (Transistor $transistor, Application $app) {
    // Called when container resolves objects of type "Transistor"...
});

$this->app->resolving(function (mixed $object, Application $app) {
    // Called when container resolves object of any type...
});
```

As you can see, the object being resolved will be passed to the callback, allowing you to set any additional properties on the object before it is given to its consumer.

<a name="rebinding"></a>
### Rebinding

The `rebinding` method allows you to listen for when a service is re-bound to the container, meaning it is registered again or overridden after its initial binding. This can be useful when you need to update dependencies or modify behavior each time a specific binding is updated:

```php
use App\Contracts\PodcastPublisher;
use App\Services\SpotifyPublisher;
use App\Services\TransistorPublisher;
use Hypervel\Contracts\Foundation\Application;

$this->app->bind(PodcastPublisher::class, SpotifyPublisher::class);

$this->app->rebinding(
    PodcastPublisher::class,
    function (Application $app, PodcastPublisher $newInstance) {
        //
    },
);

// New binding will trigger rebinding closure...
$this->app->bind(PodcastPublisher::class, TransistorPublisher::class);
```

<a name="psr-11"></a>
## PSR-11

Hypervel's service container implements the [PSR-11](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-11-container.md) interface. Therefore, you may type-hint the PSR-11 container interface to obtain an instance of the container:

```php
use App\Services\Transistor;
use Psr\Container\ContainerInterface;

Route::get('/', function (ContainerInterface $container) {
    $service = $container->get(Transistor::class);

    // ...
});
```

`get()` is a PSR-11 wrapper around `make()` — it honors all the same caching rules. An exception is thrown if the given identifier can't be resolved. The exception will be an instance of `Psr\Container\NotFoundExceptionInterface` if the identifier was never bound. If the identifier was bound but was unable to be resolved, an instance of `Psr\Container\ContainerExceptionInterface` will be thrown.
