# Porting Hyperf Code to Hypervel

Read `../../AGENTS.codex` before using this guide. This file applies only to packages whose README points to Hyperf or to low-level Swoole code that needs Hyperf comparison. Keep Hypervel's Laravel-style architecture.

## Container Conversion

Hyperf `get()` caches a singleton and Hyperf `make()` returns a fresh object. Hypervel `make()` follows the registered lifetime and auto-singletons unbound concrete classes. This difference must be checked for every converted Hyperf `make()` call. Stateless services, middleware, listeners, factories, and formatters are normally safe to share; mutable DTOs, stateful builders, and objects that capture request data are not.

| Hyperf | Hypervel | Check |
|---|---|---|
| `ApplicationContext::getContainer()` | `Container::getInstance()` | Remove `hasContainer()` guards; Hypervel always returns a container. |
| `ApplicationContext::setContainer($container)` | `Container::setInstance($container)` | Tests only. |
| `$container->get(Foo::class)` | `$container->make(Foo::class)` | Both are shared under their normal source patterns. |
| `$container->make(Foo::class)` | `$container->make(Foo::class)` | Hyperf was fresh; Hypervel may auto-singleton. Confirm the class is stateless. |

If the Hyperf object must stay fresh, use a Hypervel `bind()`, contextual parameters, or a cloned prototype according to the required binding and swap behavior. Use `build()` only when bypassing the top-level binding and alias system is deliberate.

## ConfigProvider Conversion

Before converting a Hyperf `ConfigProvider`, read:

- `src/boost/docs/providers.md`.
- `src/boost/docs/aop.md` when it declares aspects.
- `src/boost/docs/packages.md#class-map-overrides` when it replaces class maps.
- `Hypervel\Support\ServiceProvider`.

Useful low-level references include `pool`, `object-pool`, `engine`, `server`, `signal`, and `sentry`.

Translate each entry:

| Hyperf entry | Hypervel location |
|---|---|
| `dependencies` | Bindings in provider `register()`. |
| `listeners` | Typed listeners registered in provider `boot()`. |
| `commands` | `$this->commands([...])` in `register()`. Each command needs `#[AsCommand(name: '...')]` so `ContainerCommandLoader` can resolve it lazily. |
| `publish` | `$this->publishes([...])` in `boot()`. |
| `aspects` | `$this->aspects([...])` in `register()`. |

Hypervel aspects extend `Hypervel\Di\Aop\AbstractAspect`, target classes through public `$classes`, and remain stateless because their instances are normally reused for the worker lifetime. Hypervel does not support Hyperf annotation-based aspect targets.

Choose each converted binding using Hypervel lifetimes and aliases, not the Hyperf dependency entry. Delete simple factory and resolver wrappers after replacing them with provider closures. Delete the converted `ConfigProvider` and remove `extra.hyperf.config` metadata.

A circular dependency or missing-resolution error normally means the binding key is wrong, the provider is not registered, or a string concrete needs to be a closure. The database provider is the reference for string-key aliases such as `'db'`, `'db.schema'`, and `'db.transactions'`, provider closures, direct boot setup, and facades resolving canonical keys.

## Listeners and Events

Convert a Hyperf listener as follows:

1. Remove `ListenerInterface` and `listen()`.
2. Rename `process(object $event)` to `handle(SpecificEvent $event)`.
3. Remove the now-redundant `@var` cast.
4. Use a union parameter for a listener handling several events.
5. Register each event in the service provider and resolve the listener through the container.

Convert `BootApplication` listeners into direct provider `boot()` calls. They are setup hooks, not runtime events. If the listener only wraps one setup call, make that call directly and delete the listener.

For Hyperf event classes:

- Change `Hyperf\{Package}\Event` to `Hypervel\{Package}\Events`.
- Add readonly promoted properties where correct.
- Remove `StoppableEventInterface` and the `Stoppable` trait. Hypervel uses listener `return false` and `until()` for propagation behavior.
- Remove the Hyperf license header.

## Test Conversion

- Put tests in `tests/{PackageName}/` and mirror the Hyperf file layout.
- Merge overlapping Hyperf and Laravel coverage into one file, using the more complete version as the base and preserving unique tests.
- Change `HyperfTest\{Package}` to `Hypervel\Tests\{Package}` and `Hyperf\` imports to `Hypervel\`.
- Remove Hyperf license headers and coverage attributes.
- Replace `Psr\Container\ContainerInterface` mocks with `Hypervel\Contracts\Container\Container` and change both expectations and direct calls from `get()` to `make()`.
- Replace Hyperf `StdoutLoggerInterface` and `FormatterInterface` error-reporting mocks with `Hypervel\Contracts\Debug\ExceptionHandler`. Expect `has(ExceptionHandlerContract::class)` to return true, `make()` to return the handler, and `report($exception)` on the handler.
- Extract methods marked `#[Group('NonCoroutine')]` into a separate class with `$runTestsInCoroutine = false`.
