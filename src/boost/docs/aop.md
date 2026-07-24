# AOP (Aspect-Oriented Programming)

- [Introduction](#introduction)
- [How It Works](#how-it-works)
- [Defining Aspects](#defining-aspects)
    - [Targeting Classes and Methods](#targeting-classes-and-methods)
    - [Calling the Original Method](#calling-the-original-method)
    - [Working with Arguments](#working-with-arguments)
    - [Aspect Priority](#aspect-priority)
- [Registering Aspects](#registering-aspects)
- [Proxy Generation](#proxy-generation)
- [Testing Aspects](#testing-aspects)

<a name="introduction"></a>
## Introduction

Aspect-oriented programming allows you to intercept method calls without modifying the target class. In Hypervel, this is useful for package and framework integrations such as tracing, monitoring, and other behavior that should wrap an existing method call.

Hypervel provides a single "around" aspect model. An aspect receives a `Hypervel\Di\Aop\ProceedingJoinPoint`, may run code before or after the original method, may modify the method arguments, and may return the original method result or a replacement result.

> [!NOTE]
> AOP is an advanced feature. Most application code should prefer explicit service classes, events, middleware, decorators, or service container bindings. Use AOP when you need to intercept code you do not own, or when a package needs to apply behavior consistently across a target class or method.

<a name="how-it-works"></a>
## How It Works

Hypervel applies aspects by generating proxy classes during application bootstrap. When an aspect targets a class, Hypervel rewrites the target class through a generated proxy so matching method calls pass through an aspect pipeline before the original method is invoked.

The AOP bootstrap process runs after all service providers have been registered and before they are booted. This means aspects must be registered from a service provider's `register` method, before the target classes are loaded.

<a name="defining-aspects"></a>
## Defining Aspects

Aspect classes typically extend `Hypervel\Di\Aop\AbstractAspect`. Define the classes and methods you want to target using the public `$classes` property, then implement the `process` method:

```php
<?php

namespace App\Aspects;

use App\Services\ReportService;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Psr\Log\LoggerInterface;

class ProfileReports extends AbstractAspect
{
    public array $classes = [
        ReportService::class . '::generate',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $startedAt = microtime(true);

        $result = $proceedingJoinPoint->process();

        $this->logger->info('Report generated', [
            'method' => $proceedingJoinPoint->className . '::' . $proceedingJoinPoint->methodName,
            'milliseconds' => (microtime(true) - $startedAt) * 1000,
        ]);

        return $result;
    }
}
```

Aspects are resolved from the service container when they run, so constructor dependencies may be injected as usual.

> [!NOTE]
> Aspect instances are usually reused for the worker lifetime. Keep aspects stateless, or store only immutable configuration on the instance. Request-specific values should live in local variables or coroutine context, not on aspect properties.

> [!WARNING]
> The `$classes` and `$priority` properties are read from the aspect class's default public property values during service provider registration. Define these values directly on the property. Do not set them dynamically in the constructor.

<a name="targeting-classes-and-methods"></a>
### Targeting Classes and Methods

The `$classes` property accepts exact class names, exact methods, and wildcard patterns:

```php
public array $classes = [
    ReportService::class,
    ReportService::class . '::generate',
    'App\Services\*',
    'App\Services\ReportService::gen*',
];
```

| Rule | Matches |
| --- | --- |
| `App\Services\ReportService` | Every method of the class except `__construct` |
| `App\Services\ReportService::generate` | A single method |
| `App\Services\*` | Classes matching the wildcard pattern |
| `App\Services\ReportService::gen*` | Methods matching the wildcard pattern |

Whenever possible, use `ClassName::class . '::method'` for exact rules so class names remain refactorable.

> [!NOTE]
> Exact class rules are resolved through Composer's autoloader. Wildcard rules are matched against Composer's class map, so wildcard targeting works best when Composer has an optimized class map. If wildcard rules do not match PSR-4 classes during development, run `composer dump-autoload -o`.

Hypervel does not support Hyperf's annotation-based aspect targeting. Register aspects explicitly from a service provider and target classes or methods using the `$classes` property.

<a name="calling-the-original-method"></a>
### Calling the Original Method

Within an aspect, call `process` to continue through the remaining aspects and eventually call the original method:

```php
public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
{
    // Run code before the original method...

    $result = $proceedingJoinPoint->process();

    // Run code after the original method...

    return $result;
}
```

You may call `processOriginalMethod` to bypass any remaining aspects and invoke the original method directly:

```php
$result = $proceedingJoinPoint->processOriginalMethod();
```

Most aspects should call `process` so other matching aspects still run.

<a name="working-with-arguments"></a>
### Working with Arguments

The join point exposes the intercepted class, method, object instance, reflection method, and arguments:

```php
$class = $proceedingJoinPoint->className;
$method = $proceedingJoinPoint->methodName;
$instance = $proceedingJoinPoint->getInstance();
$reflectionMethod = $proceedingJoinPoint->getReflectMethod();
$arguments = $proceedingJoinPoint->getArguments();
```

Arguments are stored by parameter name in `$proceedingJoinPoint->arguments['keys']`. You may modify these values before continuing through the pipeline:

```php
use Hypervel\Support\Str;

public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
{
    $request = $proceedingJoinPoint->arguments['keys']['request'];

    $proceedingJoinPoint->arguments['keys']['request'] = $request->withHeader(
        'X-Trace-Id',
        (string) Str::uuid(),
    );

    return $proceedingJoinPoint->process();
}
```

This pattern is used by Hypervel's Sentry and Telescope integrations to instrument Guzzle requests.

<a name="aspect-priority"></a>
### Aspect Priority

You may set a priority using the public `$priority` property:

```php
public ?int $priority = 100;
```

Higher priority aspects execute first and wrap lower priority aspects. When no priority is defined, the aspect priority defaults to `0`.

<a name="registering-aspects"></a>
## Registering Aspects

Register aspects from a [service provider](/docs/{{version}}/providers) using the `aspects` method. Aspects should be registered from the provider's `register` method:

```php
<?php

namespace App\Providers;

use App\Aspects\ProfileReports;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->aspects([
            ProfileReports::class,
        ]);
    }
}
```

You may pass a single aspect class, an array of aspect classes, or multiple aspect class arguments:

```php
$this->aspects(ProfileReports::class);

$this->aspects([
    ProfileReports::class,
    TraceHttpRequests::class,
]);

$this->aspects(ProfileReports::class, TraceHttpRequests::class);
```

> [!WARNING]
> Aspects must be registered during `register`, before the target classes are loaded. Runtime registration cannot retroactively apply aspects to classes that have already been loaded or proxied.

<a name="proxy-generation"></a>
## Proxy Generation

Hypervel generates AOP proxy classes automatically during application bootstrap. Generated proxies are written to the `storage/framework/aop` directory.

If no aspects have been registered, proxy generation does nothing. Existing proxies are reused only while their content fingerprint still matches the source path and contents, aspect rules, registered AST visitors, generator implementation, PHP parser, and PHP version. Changes to any of those inputs regenerate the affected proxy during the next application bootstrap.

Each targeted source file must declare exactly one named class, interface, trait, or enum. Nested anonymous classes are supported, but multiple named class-like declarations should be split into separate files before applying an aspect. Methods that return by reference cannot be intercepted because the aspect pipeline returns values rather than reference identities.

The `cache:clear` Artisan command removes generated AOP proxies:

```shell
php artisan cache:clear
```

You may also inspect whether AOP proxies are cached using the `about` command:

```shell
php artisan about
```

<a name="testing-aspects"></a>
## Testing Aspects

In full application or Testbench tests, target classes may already be proxied during application bootstrap. In that case, call the target method normally and the proxy will intercept the call automatically.

For focused tests where the target object is not already proxied, use the `Hypervel\Foundation\Testing\Concerns\InteractsWithAop` trait. This trait can run a method through the registered aspect pipeline without relying on generated proxy files:

```php
<?php

namespace Tests\Feature;

use App\Aspects\ProfileReports;
use App\Services\ReportService;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAop;
use Tests\TestCase;

class ProfileReportsTest extends TestCase
{
    use InteractsWithAop;

    public function test_it_runs_the_aspect(): void
    {
        AspectCollector::setAround(ProfileReports::class, [
            ReportService::class . '::generate',
        ]);

        $result = $this->callWithAspects(
            new ReportService,
            'generate',
            ['id' => 1],
        );

        $this->assertNotNull($result);
    }
}
```

The `callWithAspects` method expects an object that has not already been proxied. If the object is already proxied, call the method normally instead.
