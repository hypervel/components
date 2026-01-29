# Porting Laravel Packages
[[toc]]

## Introduction

While Hypervel has already ported the majority of fundamental Laravel components to make them coroutine-safe and compatible, there are still some other [first-party packages](https://laravel.com/docs/packages) not included in this framework.

Due to the current team size and maintenance capacity, the Hypervel team may only port the packages that are essential for internal use. However, to help grow the Hypervel ecosystem, if you find a first-party Laravel package that has not yet been ported, the best approach is to contribute a port to Hypervel yourself.

This documentation will guide you through the process of porting existing Laravel packages to Hypervel.

## Why Port Packages?

You **CANNOT** install Laravel packages **directly** on Hypervel. Why is this the case? All components in Laravel are designed without consideration for coroutines. Many stateful properties are stored in Laravel's objects under the assumption that each instance will only serve a single request lifecycle.

For example, here's a snippet of Laravel’s `SessionGuard` in the **Auth** component:

```php
class SessionGuard implements StatefulGuard, SupportsBasicAuth
{
    // ...

    /**
     * Get the currently authenticated user.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function user()
    {
        if ($this->loggedOut) {
            return;
        }

        // If we've already retrieved the user for the current request we can just
        // return it back immediately. We do not want to fetch the user data on
        // every call to this method because that would be tremendously slow.
        if (! is_null($this->user)) {
            return $this->user;
        }

        // ...
    }
}
```

All guards are managed in `AuthManager`, which is registered as a singleton in the app container:

```php
class AuthManager implements FactoryContract
{
    // ...

    /**
     * The array of created "drivers".
     *
     * @var array
     */
    protected $guards = [];

    // ...

    /**
     * Attempt to get the guard from the local cache.
     *
     * @param  string|null  $name
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     */
    public function guard($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->guards[$name] ?? $this->guards[$name] = $this->resolve($name);
    }
}
```

As you can see, the guard is resolved only once and kept in the `guards` property within the `AuthManager`. The `user` property in `SessionGuard` is cached for performance. This approach works perfectly in a traditional stateless PHP environment such as PHP-FPM.

In order to work in long-living environments like Octane, Octane must flush these states before or after every request, for example:

```php
class FlushAuthenticationState
{
    /**
     * Handle the event.
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        if ($event->sandbox->resolved('auth.driver')) {
            $event->sandbox->forgetInstance('auth.driver');
        }

        if ($event->sandbox->resolved('auth')) {
            with($event->sandbox->make('auth'), function ($auth) use ($event) {
                $auth->setApplication($event->sandbox);
                $auth->forgetGuards();
            });
        }
    }
}
```

However, this mechanism is **far from sufficient** in coroutine environments. All singleton objects must be designed to serve multiple requests concurrently, which means their state needs to be isolated within a **Coroutine-Level Container (Context)**.

This is the main reason why Laravel packages cannot run on Hypervel: state leakage between coroutines becomes a serious issue.

## How To Port a Laravel Package?

To port a Laravel package to Hypervel, you need to follow these steps:

1. Replace Dependencies with Hypervel Equivalents
2. Replace PHPDocs with Native Type Declarations
3. Isolate States for Coroutines
4. Migrate from Service Provider to Config Provider
5. Adjust Tests for the Package

The following sections demonstrate how to port Laravel's `Translation` package, step by step.

### Replace Dependencies with Hypervel

Laravel packages typically rely on `Illuminate`-namespaced components, such as contracts, app, support, filesystem, config, cache, etc.

Laravel collects all package contracts in a standalone `Contracts` package, but Hypervel does **not**. Instead, Hypervel keeps such contracts within their respective packages. Therefore, you'll need to move all contracts to `Hypervel\Translation\Contracts`, for example:

* `Illuminate\Contracts\Translation\HasLocalePreference::class`
* `Illuminate\Contracts\Translation\Loader::class`
* `Illuminate\Contracts\Translation\Translator::class`

```php
<?php

namespace Hypervel\Translation;

use Hypervel\Translation\Contracts\HasLocalePreference;
use Hypervel\Translation\Contracts\Loader;
use Hypervel\Translation\Contracts\Translator;

// ...
```

#### Contract Reference

Apart from contracts, you will also need to replace other dependencies with their Hypervel counterparts. Here is a reference list of related components:

| Illuminate Contract | Hypervel Contract  |
|---------------------|--------------------|
| Illuminate\Contracts\Auth\Access\Authorizable | Hypervel\Auth\Contracts\Authorizable |
| Illuminate\Contracts\Auth\Access\Gate | Hypervel\Auth\Contracts\Gate |
| Illuminate\Contracts\Auth\Authenticatable | Hypervel\Auth\Contracts\Authenticatable |
| Illuminate\Contracts\Auth\Factory | Hypervel\Auth\Contracts\Factory |
| Illuminate\Contracts\Auth\Guard | Hypervel\Auth\Contracts\Guard |
| Illuminate\Contracts\Auth\StatefulGuard | Hypervel\Auth\Contracts\StatefulGuard |
| Illuminate\Contracts\Auth\UserProvider | Hypervel\Auth\Contracts\UserProvider |
| Illuminate\Contracts\Broadcasting\Broadcaster | Hypervel\Broadcasting\Contracts\Broadcaster |
| Illuminate\Contracts\Broadcasting\ShouldBroadcast | Hypervel\Broadcasting\Contracts\ShouldBroadcast |
| Illuminate\Contracts\Broadcasting\ShouldBroadcastNow | Hypervel\Broadcasting\Contracts\ShouldBroadcastNow |
| Illuminate\Contracts\Broadcasting\ShouldBeUnique | Hypervel\Broadcasting\Contracts\ShouldBeUnique |
| Illuminate\Contracts\Bus\Dispatcher | Hypervel\Bus\Contracts\Dispatcher |
| Illuminate\Contracts\Bus\QueueingDispatcher | Hypervel\Bus\Contracts\QueueingDispatcher |
| Illuminate\Contracts\Cache\Factory | Hypervel\Cache\Contracts\Factory |
| Illuminate\Contracts\Cache\Lock | Hypervel\Cache\Contracts\Lock |
| Illuminate\Contracts\Cache\LockProvider | Hypervel\Cache\Contracts\LockProvider |
| Illuminate\Contracts\Cache\Store | Hypervel\Cache\Contracts\Store |
| Illuminate\Contracts\Config\Repository | Hyperf\Contract\ConfigInterface |
| Illuminate\Contracts\Console\Application | Hypervel\Console\Contracts\Application |
| Illuminate\Contracts\Console\Kernel | Hypervel\Foundation\Console\Contracts\Kernel |
| Illuminate\Contracts\Container\Container | Psr\Container\ContainerInterface, Hypervel\Container\Contracts\Container|Hypervel\Foundation\Contracts\Application |
| Illuminate\Contracts\Cookie\Factory | Hypervel\Cookie\Contracts\Cookie |
| Illuminate\Database\Connection | Hyperf\Database\ConnectionResolverInterface (`->connection()`) |
| Illuminate\Contracts\Debug\ExceptionHandler | Hypervel\Foundation\Exceptions\Handler |
| Illuminate\Contracts\Encryption\Encrypter | Hypervel\Encryption\Contracts\Encrypter |
| Illuminate\Contracts\Events\Dispatcher | Psr\EventDispatcher\EventDispatcherInterface, Hypervel\Event\Contracts\Dispatcher |
| Illuminate\Contracts\Filesystem\Cloud | Hypervel\Filesystem\Contracts\Cloud |
| Illuminate\Contracts\Filesystem\Factory | Hypervel\Filesystem\Contracts\Factory |
| Illuminate\Contracts\Filesystem\Filesystem | Hypervel\Filesystem\Contracts\Filesystem |
| Illuminate\Contracts\Foundation\Application | Psr\Container\ContainerInterface, Hypervel\Container\Contracts\Container |
| Illuminate\Contracts\Hashing\Hasher | Hypervel\Hashing\Contracts\Hasher |
| Illuminate\Http\Request | Psr\Http\Message\ServerRequestInterface, Hypervel\Http\Contracts\RequestContract |
| Illuminate\Http\Response | Hyperf\HttpServer\Contract\ResponseInterface, Hypervel\Http\Contracts\ResponseContract |
| Illuminate\Log\LogManager | Psr\Log\LoggerInterface |
| Illuminate\Contracts\Mail\Mailable | Hypervel\Mail\Contracts\Mailable |
| Illuminate\Contracts\Mail\Mailer | Hypervel\Mail\Contracts\Mailer |
| Illuminate\Contracts\Mail\MailQueue | Hypervel\Mail\Contracts\MailQueue |
| Illuminate\Contracts\Notifications\Dispatcher | Hypervel\Mail\Contracts\Mailable |
| Illuminate\Contracts\Notifications\Dispatcher | Hypervel\Notifications\Contracts\Dispatcher |
| Illuminate\Contracts\Notifications\Factory | Hypervel\Notifications\Contracts\Factory |
| Illuminate\Contracts\Notifications\Dispatcher | Hypervel\Notifications\Contracts\Dispatcher |
| Illuminate\Contracts\Pipeline\Pipeline | Hypervel\Support\Pipeline::make() |
| Illuminate\Contracts\Queue\EntityResolver | Hypervel\Queue\Contracts\EntityResolver |
| Illuminate\Contracts\Queue\Factory | Hypervel\Queue\Contracts\Factory |
| Illuminate\Contracts\Queue\Job | Hypervel\Queue\Contracts\Job |
| Illuminate\Contracts\Queue\Monitor | Hypervel\Queue\Contracts\Monitor |
| Illuminate\Contracts\Queue\Queue | Hypervel\Queue\Contracts\Queue |
| Illuminate\Contracts\Queue\QueueableCollection | Hypervel\Queue\Contracts\QueueableCollection |
| Illuminate\Contracts\Queue\QueueableEntity | Hypervel\Queue\Contracts\QueueableEntity |
| Illuminate\Contracts\Queue\ShouldQueue | Hypervel\Queue\Contracts\ShouldQueue |
| Illuminate\Contracts\Redis\Factory | Hyperf\Redis\RedisFactory (`getConnection()`) |
| Illuminate\Contracts\Routing\UrlGenerator | Hypervel\Router\Contracts\UrlGenerator |
| Illuminate\Contracts\Routing\UrlRoutable | Hypervel\Router\Contracts\UrlRoutable |
| Illuminate\Contracts\Session\Session | Hypervel\Session\Contracts\Session |
| Illuminate\Contracts\Support\Arrayable | Hypervel\Support\Contracts\Arrayable |
| Illuminate\Contracts\Support\Htmlable | Hypervel\Support\Contracts\Htmlable |
| Illuminate\Contracts\Support\Jsonable | Hypervel\Support\Contracts\Jsonable |
| Illuminate\Contracts\Support\MessageBag | Hypervel\Support\Contracts\MessageBag |
| Illuminate\Contracts\Support\MessageProvider | Hypervel\Support\Contracts\MessageProvider |
| Illuminate\Contracts\Support\Renderable | Hypervel\Support\Contracts\Renderable |
| Illuminate\Contracts\Support\Responsable | Hypervel\Support\Contracts\Responsable |
| Illuminate\Filesystem\Filesystem | Hypervel\Filesystem\Filesystem |
| Illuminate\Support\Arr | Hypervel\Support\Arr |
| Illuminate\Support\Carbon | Hypervel\Support\Carbon |
| Illuminate\Support\Collection | Hypervel\Support\Collection |
| Illuminate\Support\DateFactory | Hypervel\Support\DateFactory |
| Illuminate\Support\Fluent | Hypervel\Support\Fluent |
| Illuminate\Support\Manager | Hypervel\Support\Manager |
| Illuminate\Support\NamespacedItemResolver | Hypervel\Support\NamespacedItemResolver |
| Illuminate\Support\Number | Hypervel\Support\Number |
| Illuminate\Support\ServiceProvider | Hypervel\Support\ServiceProvider |
| Illuminate\Support\Sleep | Hypervel\Support\ServiceProvider |
| Illuminate\Support\Str | Hypervel\Support\Str |
| Illuminate\Support\Traits\Conditionable | Hypervel\Support\Traits\Conditionable |
| Illuminate\Support\Traits\Macroable | Hypervel\Support\Traits\Macroable |
| Illuminate\Support\Traits\ReflectsClosures | Hypervel\Support\Traits\ReflectsClosures |
| Illuminate\Contracts\Translation\Loader | Hypervel\Translation\Contracts\Loader |
| Illuminate\Contracts\Translation\Translator | Hypervel\Translation\Contracts\Translator |
| Illuminate\Contracts\Validation\Factory | Hypervel\Validation\Contracts\Factory |
| Illuminate\Contracts\Validation\ValidatesWhenResolved | Hypervel\Validation\Contracts\ValidatesWhenResolved |
| Illuminate\Contracts\Validation\ValidationRule | Hypervel\Validation\Contracts\Rule |
| Illuminate\Contracts\Validation\Validator | Hypervel\Validation\Contracts\Validator |
| Illuminate\Contracts\View\Engine | Hyperf\ViewEngine\Contract\EngineInterface |
| Illuminate\Contracts\View\Factory | Hyperf\ViewEngine\Contract\FactoryInterface |
| Illuminate\Contracts\View\View | Hyperf\ViewEngine\Contract\ViewInterface |

::: note
Some components may not be 100% identical to their original Laravel counterparts. Be sure to compare and address any differences before replacing these dependencies.
:::

### Replace PHPDocs with Native Types Declaration

Most Laravel classes are **NOT** type-friendly. Laravel primarily uses PHPDoc annotations to specify the types of class properties and methods, and only the more recent packages use native type declarations.

Unfortunately, the types specified via PHPDoc in Laravel are frequently inaccurate. If you simply convert PHPDoc types into native PHP type declarations, you'll encounter inconsistencies and omissions during execution.

For example, the `item` parameter of the `getLine` function in `Translator::class` is documented as a **string** in the PHPDoc, but it should actually be `null|string`. This kind of incorrect typing is widespread in Laravel.

```php
/**
 * Retrieve a language line out the loaded array.
 *
 * @param  string  $namespace
 * @param  string  $group
 * @param  string  $locale
 * @param  string  $item
 * @param  array  $replace
 * @return string|array|null
 */
protected function getLine($namespace, $group, $locale, $item, array $replace)
{
    $this->load($namespace, $group, $locale);

    $line = Arr::get($this->loaded[$namespace][$group][$locale], $item);

    // ...
}
```

Therefore, after implementation, you **must** rely on testing to ensure that your type declarations are correct.

Here’s an example using `FileLoader` from the translation component:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Translation\Contracts\Loader;
use RuntimeException;

class FileLoader implements Loader
{
    /**
     * The default paths for the loader.
     */
    protected array $paths = [];

    /**
     * All of the registered paths to JSON translation files.
     */
    protected array $jsonPaths = [];

    /**
     * All of the namespace hints.
     */
    protected array $hints = [];

    /**
     * Create a new file loader instance.
     *
     * @param  Filesystem  $files The filesystem instance.
     */
    public function __construct(
        protected Filesystem $files,
        array|string $path
    ) {
        $this->files = $files;

        $this->paths = is_string($path) ? [$path] : $path;
    }

    /**
     * Load the messages for the given locale.
     */
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        if ($group === '*' && $namespace === '*') {
            return $this->loadJsonPaths($locale);
        }

        if (is_null($namespace) || $namespace === '*') {
            return $this->loadPaths($this->paths, $locale, $group);
        }

        return $this->loadNamespaced($locale, $group, $namespace);
    }

    // ...
}
```

Note the use of `declare(strict_types=1);` at the beginning of the file. This enforces strict type checking, preventing bugs through early error detection, improving reliability by eliminating implicit type conversions, and enhancing maintainability through explicit code intentions.

In PHP 8.0+, constructor property promotion allows developers to define and initialize class properties directly within the constructor parameters, significantly reducing boilerplate code and improving both readability and maintainability.

All properties and functions in the class are thus declared with native types and default values.

### Isolating States for Coroutines

As mentioned previously, Laravel components are not designed for coroutine environments. If you simply copy and paste code from Laravel to Hypervel, you are likely to encounter state leakage at some point.

For instance, in the `Translator` class:

```php
class Translator extends NamespacedItemResolver implements TranslatorContract
{
    // ...

    /**
     * The default locale being used by the translator.
     *
     * @var string
     */
    protected $locale;

    /**
     * Get the default locale being used.
     *
     * @return string
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * Set the default locale.
     *
     * @param  string  $locale
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function setLocale($locale)
    {
        if (Str::contains($locale, ['/', '\\'])) {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

        $this->locale = $locale;
    }

    // ...
}
```

Such getter-setter designs are common for the `locale` property in stateless PHP environments. However, the `Translator` is intended to be a singleton, meaning its properties, including `locale`, will be shared across all requests.

To mitigate this, Octane provides a `FlushLocaleState` listener to restore the states for `Translator`:

```php
<?php

namespace Laravel\Octane\Listeners;

use Carbon\Laravel\ServiceProvider as CarbonServiceProvider;

class FlushLocaleState
{
    /**
     * Handle the event.
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        $config = $event->sandbox->make('config');

        tap($event->sandbox->make('translator'), function ($translator) use ($config) {
            $translator->setLocale($config->get('app.locale'));
            $translator->setFallback($config->get('app.fallback_locale'));
        });

        $provider = tap(new CarbonServiceProvider($event->app))->updateLocale();

        collect($event->sandbox->getProviders($provider))
            ->values()
            ->whenNotEmpty(fn ($providers) => $providers->first()->setAppGetter(fn () => $event->sandbox));
    }
}
```

Unfortunately, this does not solve the issue in Hypervel, because each worker may serve multiple requests **simultaneously**.

To resolve this, you need a **Coroutine-Level Container** for isolating state between coroutines, referred to in Hypervel as [**Context**](/docs/context).

The code related to the `locale` property should be refactored as follows:

```php
use Hypervel\Context\Context;

class Translator extends NamespacedItemResolver implements TranslatorContract
{
    // ...

    /**
     * Get the default locale being used.
     */
    public function getLocale(): string
    {
        return (string) (Context::get('__translator.locale') ?? $this->locale);
    }

    /**
     * Set the default locale.
     *
     * @throws InvalidArgumentException
     */
    public function setLocale(string $locale): void
    {
        if (Str::contains($locale, ['/', '\\'])) {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

        Context::set('__translator.locale', $locale);
    }

    // ...
}
```

By storing the `locale` state in the coroutine context, each coroutine maintains its own state.

### Migrate from Service Provider to Config Provider

In Laravel, the Service Provider is a crucial component for binding and bootstrapping your services. Hypervel supports service providers in a similar way, but encourages developers to **rewrite** them as Config Providers to maintain compatibility with the Hyperf ecosystem.

Consider the following `TranslationServiceProvider` from Laravel:

```php
<?php

namespace Illuminate\Translation;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerLoader();

        $this->app->singleton('translator', function ($app) {
            $loader = $app['translation.loader'];

            // When registering the translator component, we'll need to set the default
            // locale as well as the fallback locale. So, we'll grab the application
            // configuration so we can easily get both of these values from there.
            $locale = $app->getLocale();

            $trans = new Translator($loader, $locale);

            $trans->setFallback($app->getFallbackLocale());

            return $trans;
        });
    }

    /**
     * Register the translation line loader.
     *
     * @return void
     */
    protected function registerLoader()
    {
        $this->app->singleton('translation.loader', function ($app) {
            return new FileLoader($app['files'], [__DIR__.'/lang', $app['path.lang']]);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['translator', 'translation.loader'];
    }
}
```
In `TranslationServiceProvider`, two bindings — `translation.loader` and `translator` — are registered via `singleton`. In Hypervel, all dependencies in the container are singleton by default, so there is no need for an explicit `singleton` method.

To implement these bindings in Hypervel's Config Provider, define them in the `dependencies` array within the `__invoke` function:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Translation\Contracts\Loader as LoaderContract;
use Hypervel\Translation\Contracts\Translator as TranslatorContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                LoaderContract::class => LoaderFactory::class,
                TranslatorContract::class => TranslatorFactory::class,
            ],
        ];
    }
}
```

Of course, you can use closures as resolving callbacks, but defining dedicated factory classes makes your code more organized:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Filesystem\Contracts\Filesystem;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Hypervel\Translation\Contracts\Loader as LoaderContract;
use Psr\Container\ContainerInterface;

class LoaderFactory
{
    public function __invoke(ContainerInterface $container): LoaderContract
    {
        $langPath = $container instanceof ApplicationContract
            ? $container->langPath()
            : BASE_PATH . DIRECTORY_SEPARATOR . 'lang';

        return new FileLoader(
            $container->get(Filesystem::class),
            [
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang',
                $langPath,
            ]
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Translation\Contracts\Loader as LoaderContract;
use Hypervel\Translation\Contracts\Translator as TranslatorContract;
use Psr\Container\ContainerInterface;

class TranslatorFactory
{
    public function __invoke(ContainerInterface $container): TranslatorContract
    {
        $config = $container->get(ConfigInterface::class);

        // When registering the translator component, we'll need to set the default
        // locale as well as the fallback locale. So, we'll grab the application
        // configuration so we can easily get both of these values from there.
        $trans = new Translator(
            $container->get(LoaderContract::class),
            $config->get('app.locale', 'en')
        );

        $trans->setFallback($config->get('app.fallback_locale', 'en'));

        return $trans;
    }
}
```

Hypervel does not require a `DeferrableProvider`, as this trait only provides a performance tweak for PHP’s traditional request lifecycle. In Hypervel, service/config providers are loaded at framework bootstrap and remain in memory for the application's lifetime.

Don't forget to add your config provider for auto-discovery in `composer.json`:

```json
"extra": {
    "hyperf": {
        "config": "Hypervel\\Translation\\ConfigProvider"
    }
}
```

### Adjust Tests for The Package

The final step is to port the unit tests from Laravel. Thanks to Laravel's comprehensive and reliable test suites, you can verify the correctness of your port by running these tests.

You will likely discover more incorrect type declarations during this process. Fix all such issues as they arise.

You may extend `Hypervel\Tests\TestCase::class` to leverage features such as assertion counting and automatic mockery closing.

If you need a Hypervel environment for feature testing, you can see [Packages Toolkit](/docs/testbench) for more details.

#### Tests in Coroutines

Sometimes you may want to explicitly test your classes in a coroutine environment. In that case, you must execute tests within a coroutine container.

For instance, since you’ve refactored the `setLocale` function using `Context`, you need to test that each coroutine maintains its own locale. Run the test in a coroutine context to observe the state isolation:

> See [Running Tests in Coroutines](/docs/testing#running-tests-in-coroutines) for more details.

```php
use Hepervel\Coroutine\run;

public function testSetLocale()
{
    $translator = new Translator($this->getLoader(), 'en');

    run(function () use ($translator) {
        Coroutine::create(function () use ($translator) {
            $translator->setLocale('fr');
            $this->assertSame('fr', $translator->getLocale());
        });
    });

    $this->assertSame('en', $translator->getLocale());
}
```