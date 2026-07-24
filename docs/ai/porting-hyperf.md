# Porting Hyperf Code to Hypervel

Read this before porting Hyperf code or modifying a Hyperf-ported package. It covers the Hyperf side of the conversion: container calls, ConfigProviders, listeners/events, and tests. Hypervel's own container semantics, binding patterns, and alias rules live in the Container section of `AGENTS.md` — this doc assumes you have read them.

Hyperf ports do not aim for upstream fidelity. The preserve-upstream rules under Porting Packages in `AGENTS.md` exist for upstreams we keep merging from — Laravel first-party and Laravel-ecosystem packages — and Hyperf is neither: it's a historical reference. Adapt ported code fully to Hypervel structure, style, and naming, including cleaning up variable and method names, following this guide.

## Container Conversion

Hyperf and Hypervel have fundamentally different container semantics. Every ported file that touches the container needs these updates.

### How the Hyperf container differs

- `get($id)` — returns a singleton. Caches the result in `$resolvedEntries`; subsequent calls return the cached instance.
- `make($name)` — always returns a fresh instance. No caching. This is how Hyperf code gets non-shared objects.
- `ApplicationContext::getContainer()` — static access to the container. Returns `Psr\Container\ContainerInterface` (PSR — only exposes `get()` and `has()`).
- Everything resolved via `get()` is implicitly a singleton. There is no `singleton()`, `scoped()`, or `bind()`.

### What to change when porting

**1. `ApplicationContext` → `Container::getInstance()`**

```php
// Hyperf
use Hyperf\Context\ApplicationContext;
$container = ApplicationContext::getContainer();

// Hypervel
use Hypervel\Container\Container;
$container = Container::getInstance();
```

Remove `ApplicationContext::hasContainer()` guards — `Container::getInstance()` auto-creates via `??= new static()`, so it always returns a container instance.

Replace `ApplicationContext::setContainer($c)` with `Container::setInstance($c)` (tests only).

**2. `->get()` → `->make()` on the container**

All `$container->get()` / `$this->container->get()` calls become `->make()`. In Hypervel both methods resolve identically, but `make()` is the Laravel convention (internal API, not PSR wrapper). Use `make()` consistently.

```php
// Hyperf
$this->container->get(ConfigInterface::class);

// Hypervel
$this->container->make(ConfigInterface::class);
```

**3. Audit `make()` calls for auto-singleton safety**

In Hyperf, `$container->make(Foo::class)` always returns a fresh `Foo`. In Hypervel, if `Foo` has no explicit binding, it will be auto-singletoned (cached for the worker lifetime). This is usually desirable for Swoole performance, but needs verification:

- **Safe as auto-singleton (most cases):** Services, middleware, listeners, factories, formatters — stateless or process-global by nature. Leave as `make()`.
- **Needs fresh instances:** Mutable request-scoped DTOs, builders that accumulate state, objects that capture per-request data in their constructor. **STOP and report** with a recommendation (typically: register with `bind()` so the container returns fresh instances).
- **`make()` with parameters always returns fresh:** `$container->make(Foo::class, ['bar' => $baz])` bypasses all caching (singleton, scoped, and auto-singleton) because parameters trigger a contextual build. No action needed for these calls.

### Quick reference

| Hyperf | Hypervel | Behavior change? |
|---|---|---|
| `ApplicationContext::getContainer()->get(Foo::class)` | `Container::getInstance()->make(Foo::class)` | No — both return singletons |
| `$this->container->get(Foo::class)` | `$this->container->make(Foo::class)` | No — convention change only |
| `$this->container->make(Foo::class)` | `$this->container->make(Foo::class)` | **Yes** — Hyperf: fresh each time. Hypervel: auto-singletoned if unbound. Verify safe. |
| `$this->container->make(Foo::class)` when freshness is needed but bindings/swaps must still apply | `$this->container->make(Foo::class, [...])`, explicit `bind()`, or clone a resolved prototype depending on the use case | `build(Foo::class)` is only correct when you intentionally want to bypass top-level bindings/aliases/caches. |
| `ApplicationContext::hasContainer()` | Remove guard | `getInstance()` always returns a container |
| `ApplicationContext::setContainer($c)` | `Container::setInstance($c)` | Tests only |

## Migrating Hyperf ConfigProviders

Hyperf packages use `ConfigProvider` classes to register bindings, listeners, commands, publishable files, and aspects. Hypervel packages use normal service providers. When porting a Hyperf package, treat the Hyperf `ConfigProvider` as source input and translate each entry into Hypervel's documented provider APIs.

### Read the Hypervel provider APIs first

Before migrating a ConfigProvider, read:

- `src/boost/docs/providers.md`
- `src/boost/docs/aop.md` if the ConfigProvider has aspects
- `src/boost/docs/packages.md#class-map-overrides` if the package uses class map replacement
- `Hypervel\Support\ServiceProvider`

Use existing Hypervel packages as pattern references. For low-level Swoole / Hyperf-style infrastructure, useful references include `pool`, `object-pool`, `engine`, `server`, `signal`, and `sentry`. The `database` package is a good reference for translating Hyperf provider patterns into Hypervel provider code.

### Categorize the ConfigProvider entries

Read the Hyperf package's ConfigProvider and translate each entry:

| Hyperf entry | Hypervel destination |
|---|---|
| `dependencies` | Container bindings in the service provider's `register()` method. |
| `listeners` | Hypervel listener classes registered in the provider's `boot()` method — see "Converting Hyperf Listeners and Events" below. |
| `commands` | `$this->commands([...])` in `register()`. Commands must have `#[AsCommand(name: '...')]` so Hypervel can resolve them lazily through `ContainerCommandLoader`. |
| `publish` | `$this->publishes([...])` in `boot()`. |
| `aspects` | `$this->aspects([...])` in `register()`. |

Hypervel aspects extend `Hypervel\Di\Aop\AbstractAspect`, target classes with the public `$classes` property, and should stay stateless because aspect instances are usually reused for the worker lifetime. Hypervel does not support Hyperf annotation-based aspect targeting.

Once the entries have been migrated, delete the ConfigProvider. Do not keep Hyperf `extra.hyperf.config` metadata in Hypervel package composer files.

### Translate bindings into Hypervel container patterns

Do not copy Hyperf dependency registrations mechanically. Hyperf dependency entries often rely on Hyperf container behavior, while Hypervel has `bind()`, `singleton()`, `scoped()`, aliases, and auto-singletoning. Choose binding types and keys per the Container section in `AGENTS.md` (binding-type choice, binding patterns, alias rules). Additionally:

- If a Hyperf factory class only wraps simple construction logic, replace it with an inline closure and delete the factory.
- If a Hyperf resolver class only wraps a one-line callback, replace it with an inline closure and delete the resolver.

Do not add new core aliases just because Hyperf had a dependency key. Only add aliases when Hypervel needs that alias as part of its public container surface.

### Register the provider

Register providers and aliases through the package Composer metadata and the root Composer metadata, following the "Provider registration" section in `AGENTS.md` (DefaultProviders vs package discovery, `registerBaseServiceProviders()` rules).

### Quick checklist

1. Read Hypervel provider docs and relevant existing Hypervel package patterns.
2. Read the Hyperf ConfigProvider and categorize entries.
3. Translate dependencies into Hypervel bindings.
4. Convert listeners, commands, publish entries, and aspects to provider APIs.
5. Check aliases before choosing binding keys.
6. Delete unnecessary Hyperf factories / resolvers.
7. Delete the ConfigProvider.
8. Register providers and aliases in both Composer metadata locations.
9. Run phpstan and tests.

Circular dependency errors or "not found" errors usually indicate:

- A binding key mismatch.
- A missing provider registration.
- A string concrete binding that should be a closure.

## Converting Hyperf Listeners and Events

When porting Hyperf packages, their `ListenerInterface` listeners and event classes must be converted to Hypervel listener patterns.

### Converting listeners

**Hyperf pattern** — `ListenerInterface` with `listen()` returning event class names, `process(object $event)` as the handler:

```php
use Hyperf\Event\Contract\ListenerInterface;

class AfterWorkerStartListener implements ListenerInterface
{
    public function listen(): array
    {
        return [AfterWorkerStart::class];
    }

    public function process(object $event): void
    {
        /** @var AfterWorkerStart $event */
        // ... logic
    }
}
```

**Hypervel pattern** — plain class with typed `handle()` method:

```php
class AfterWorkerStartListener
{
    public function handle(AfterWorkerStart $event): void
    {
        // ... same logic, typed parameter instead of docblock
    }
}
```

**Steps:**
1. Remove `implements ListenerInterface` and the `use` import
2. Delete the `listen()` method entirely
3. Rename `process(object $event)` → `handle(SpecificEvent $event)` with the typed parameter
4. Remove the `@var` docblock cast — the type hint replaces it

**Multi-event listeners:** When a Hyperf listener handles multiple event types (returns multiple classes from `listen()`), use a union type parameter:

```php
// Hyperf: listen() returns [OnStart::class, OnManagerStart::class, AfterWorkerStart::class, BeforeProcessHandle::class]
// Hypervel:
public function handle(AfterWorkerStart|OnStart|OnManagerStart|BeforeProcessHandle $event): void
```

The service provider registers separate `$events->listen()` calls for each event type, all pointing to the same listener.

### Registering listeners in service providers

Hyperf auto-discovered listeners via the ConfigProvider `listeners` array. In Hypervel, register them in the service provider's `boot()` method using closures that resolve the listener from the container — see "Listener registration" in `AGENTS.md` for the pattern.

### `BootApplication` listeners

Some Hyperf listeners listen for `BootApplication`, which fires during kernel bootstrap (before the app is fully booted). These are not true event-driven listeners — they run setup logic that needs to happen early.

Convert these to **direct calls** in the service provider's `boot()` method. No event dispatch:

```php
// Hyperf ConfigProvider: listeners => [ExceptionHandlerListener::class]
// (with ExceptionHandlerListener::listen() returning [BootApplication::class])

// Hypervel service provider:
public function boot(): void
{
    $this->app->make(ExceptionHandlerListener::class)->handle(new BootApplication());
}
```

If the listener only existed to run once at boot time and has no reason to be event-driven, calling it directly is simpler and more explicit than dispatching a synthetic event. If the setup can be expressed directly without keeping the listener class, prefer the direct framework call — for example, a listener that only sets a global model resolver should usually become an explicit call in the provider's `boot()` method.

### Converting event classes

Hyperf events are plain PHP classes — the conversion is minimal:

1. **Namespace:** `Hyperf\{Package}\Event` → `Hypervel\{Package}\Events` (singular → plural, matching Laravel)
2. **Modernize properties:** Add `readonly` to constructor-promoted properties where appropriate
3. **Remove PSR interfaces:** Drop `StoppableEventInterface` and the `Stoppable` trait. Laravel handles propagation stopping via listener `return false` and the `until()` dispatch method — no interface needed.
4. **Remove boilerplate:** Delete the Hyperf license header

Event classes are just data carriers. Their structure is fundamentally the same in both systems — the differences are namespace and type modernization, not architectural.

## Porting Hyperf Tests

### Namespace changes

- `HyperfTest\{Package}` → `Hypervel\Tests\{Package}`
- All `Hyperf\` source imports → `Hypervel\`

### Boilerplate removal

- Remove the Hyperf license header block (`@link`, `@document`, `@contact`, `@license`)
- Remove `#[CoversNothing]` and `#[CoversClass(…)]` attributes entirely — we don't use them (see "Docblocks and types" under Writing Tests in `AGENTS.md`)

### Container mocking

Hyperf tests use `Psr\Container\ContainerInterface`. Change to `Hypervel\Contracts\Container\Container`. Also change all `->get()` to `->make()` — both mock expectations AND direct calls on the container in test setup code (see Container Conversion above for why):

```php
// Hyperf
use Psr\Container\ContainerInterface;
$container = Mockery::mock(ContainerInterface::class);
$container->shouldReceive('get')->with(Foo::class)->andReturn(new Foo());
$result = $container->get(Foo::class);  // test setup call

// Hypervel
use Hypervel\Contracts\Container\Container as ContainerContract;
$container = m::mock(ContainerContract::class);
$container->shouldReceive('make')->with(Foo::class)->andReturn(new Foo());
$result = $container->make(Foo::class);  // test setup call
```

### Error handler mocking

Hyperf tests use `StdoutLoggerInterface` + `FormatterInterface` for error reporting in coroutines. Hypervel uses `ExceptionHandler`:

```php
// Hyperf
$container->shouldReceive('has')->withAnyArgs()->andReturnTrue();
$container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn($logger);
$logger->shouldReceive('warning')->with('unit')->twice();
$container->shouldReceive('get')->with(FormatterInterface::class)->andReturn($formatter);
$formatter->shouldReceive('format')->with($exception)->twice()->andReturn('unit');

// Hypervel
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
$container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
$container->shouldReceive('make')->with(ExceptionHandlerContract::class)
    ->andReturn($handler = m::mock(ExceptionHandlerContract::class));
$handler->shouldReceive('report')->with($exception)->twice();
```

### NonCoroutine tests

Hyperf uses `#[Group('NonCoroutine')]` on individual test methods to mark tests that must run outside a coroutine. In Hypervel, extract those methods to a separate test class with `protected bool $runTestsInCoroutine = false;`.

### Hyperf quick checklist

1. Update namespace from `HyperfTest\{Package}` to `Hypervel\Tests\{Package}`
2. Add `declare(strict_types=1);`
3. Change `Hyperf\` imports to `Hypervel\`
4. Remove Hyperf license header; remove `#[CoversNothing]` and `#[CoversClass(…)]` attributes
5. Extend `Hypervel\Tests\TestCase` (not `PHPUnit\Framework\TestCase`)
6. Change container mock to `Hypervel\Contracts\Container\Container`, all `->get()` to `->make()` (expectations AND direct calls)
7. Change error handler mock to `Hypervel\Contracts\Debug\ExceptionHandler`
8. Extract `#[Group('NonCoroutine')]` methods to separate class with `$runTestsInCoroutine = false`
9. Ensure `parent::setUp()` is called
10. Run tests and fix any remaining type errors
