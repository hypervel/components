# Hypervel PHPStan Rules (PR 2)

Branch: `feature/phpstan-hypervel-rules`. This PR builds on PR 1 (`docs/plans/2026-11-12-hypervel-attributes.md`, merged into `0.4`): the `hypervel/attributes` package, converted markers, `#[Unsupported]` markers, and `WorkerStateHelper::hasLifetimeAttribute()`.
- Commit the runtime changes (section 1) separately from the analysis work, so they can be reviewed and reverted on their own.

Owner-approved:
- `Container::resolvedShared()` on the container contract, with facade roots from non-shared bindings becoming coroutine-local;
- binding `date` in `FoundationServiceProvider`, so a `Date` facade subclass overriding `DEFAULT_FACADE` needs an explicit `date` binding;
- narrowing the connection resolver contract to `Connection` (section 1.5);
- config value types only for the application's repository (section 3.9);
- the `AGENTS.md` edits in section 6.

After implementation and signoff, show the owner each section 6 `AGENTS.md` change before and after in chat.

## 1. Runtime changes

### 1.1 `Container::resolvedShared()`

**Why.** Facades must cache only resolutions the container keeps for the worker. `isShared()` reports explicit lifetimes but not auto-singletons (`$autoSingletons`, `Container.php:1340`). Identity checks are wrong, because a `bind()` factory may select between two singletons per coroutine.

The contract (`src/contracts/src/Container/Container.php`) gets the method beside `isScopedInstance()`. The container places it beside `resolvedScoped()`:

```php
/**
 * Determine if the container holds a worker-shared instance for the given type.
 */
public function resolvedShared(string $abstract): bool
{
    $abstract = $this->getAlias($abstract);

    return isset($this->instances[$abstract])
        || array_key_exists($abstract, $this->instances)
        || isset($this->autoSingletons[$abstract]);
}
```

- An `instance()` registration over a scoped binding counts. It lives in the worker map, and `make()` returns it.
- `isShared()` is unchanged.

**Tests**, next to the `resolvedScoped()` tests:
- singleton, `instance()` (including `null`), auto-singleton and alias → true;
- `bind()`, a `Transient` class and a `bind()` factory returning a singleton → false;
- an `instance()` over a scoped binding → true.

### 1.2 Facade roots follow the container lifetime

**Why.** `Facade::resolveFacadeInstance()` caches every root except scoped ones and `$cached = false` facades in the worker-wide `$resolvedInstance`. So a facade over a `bind()`/`Transient` service keeps one request's object for every later request. Laravel under FPM resolves it once per request; the coroutine is Hypervel's equivalent.

New state class `src/support/src/Facades/FacadeState.php` (AGENTS "…State classes"):

```php
class FacadeState implements NonCopyableContext
{
    /**
     * The coroutine-local facade roots, keyed by accessor, with the generation each was resolved in.
     *
     * @var array<string, array{int, mixed}>
     */
    public array $roots = [];
}
```

`NonCopyableContext` means copied child coroutines resolve their own roots, and concurrent coroutines never share a fresh object.

In `Facade`:
- add `protected const string STATE_CONTEXT_KEY = '__support.facade_state';`;
- add `/** @var array<string, int> */ protected static array $coroutineAccessors = [];`, the accessors with coroutine-local roots and their current generation.

```php
protected static function resolveFacadeInstance(string $name): mixed
{
    if (isset(static::$resolvedInstance[$name])) {
        return static::$resolvedInstance[$name];
    }

    if (! static::$app) {
        return null;
    }

    if (static::$cached && isset(static::$coroutineAccessors[$name])) {
        $root = CoroutineContext::get(self::STATE_CONTEXT_KEY)?->roots[$name] ?? null;

        if ($root !== null && $root[0] === static::$coroutineAccessors[$name]) {
            return $root[1];
        }
    }

    $instance = static::$app->make($name);

    if (! static::$cached || isset(static::$scopedAccessors[$name]) || static::$app->isScoped($name)) {
        return $instance;
    }

    if (static::$app->resolvedShared($name)) {
        static::$resolvedInstance[$name] = $instance;
    } elseif (static::$app->isScopedInstance($instance)) {
        // A transient factory can return a scoped service. Remember its lifetime
        // so later calls neither retain the object nor repeat the scoped-store scan.
        static::$scopedAccessors[$name] = true;
    } else {
        $state = CoroutineContext::get(self::STATE_CONTEXT_KEY)
            ?? CoroutineContext::set(self::STATE_CONTEXT_KEY, new FacadeState);

        $state->roots[$name] = [static::$coroutineAccessors[$name] ??= 0, $instance];
    }

    return $instance;
}
```

How the lookup behaves:

- **Warm path.** Shared hits are unchanged. Uncached and scoped accessors do no extra lookup.
- **`$cached` gate.** The coroutine lookup checks `static::$cached`, because two facade classes may share an accessor with different `$cached` flags, and a coroutine root differs from what `make()` would return. The worker-cache read stays ungated, as in Laravel: it holds only shared instances, which `make()` returns anyway, and swaps.
- **Allocation.** A coroutine creates its `FacadeState` only when it has none (`CoroutineContext::set()` returns the value).
- **Invalidation:**
  - `clearResolvedInstance($name)` also does `if (isset(static::$coroutineAccessors[$name])) { ++static::$coroutineAccessors[$name]; }`.
  - `clearResolvedInstances()` also increments every `$coroutineAccessors` value.
  - Later facade calls in every live coroutine and in the non-coroutine store then resolve again. References already held stay old.
  - Generations only increase, so an old entry can't become valid again.
  - Update both docblocks: they currently say concurrent coroutines may keep the previous instance; they should say later facade calls everywhere resolve again, and only references already held stay old.
- **Unchanged:**
  - `setFacadeApplication()`. All four callers clear first: `RegisterFacades:18-19`, testbench `TestCase:229-230`, `CreatesApplication:356-357` and `AfterEachTestSubscriber:291-292`.
  - `swap()`. It sets the worker cache, which is read first, plus an instance binding.
  - The `isFake()` checks.

**Docs:**
- `src/docs/facades.md:196`:
  > A facade keeps the instance it resolves for as long as the container shares it: services the container shares, such as singletons and auto-singletons, for the lifetime of the worker, and services registered with `bind()` or marked `Transient` for the current request or job coroutine. Scoped services always resolve from the current coroutine, including when a `bind()` binding or factory returns a scoped instance. To resolve the service on every facade call, set `protected static bool $cached = false` on the facade.
- Remove the facade paragraph at `src/docs/porting-from-laravel.md:438` and the facade bullet in `src/support/README.md` "Differences From Laravel". Facades now follow the container's lifetimes, as Laravel's do per request, and the warning above line 438 already covers worker-lifetime auto-singletons. `$cached = false` behaves as in Laravel.

**Tests** (`tests/Support/SupportFacadeTest.php`). Existing tests already cover `$cached = false`, scoped facades including `bind()` delegates, replicated contexts, clearing, swap and cached-once. Add only:
- a `bind()` accessor that's consistent within a coroutine and isolated across concurrent coroutines (`parallel()` + `usleep()`). The same test first resolves it outside any coroutine, as boot code does, and asserts the request roots aren't that boot root, so a non-coroutine resolution is never promoted to the worker cache;
- a `bind()` factory selecting between two singletons per coroutine, so each coroutine sees its selection;
- a copied child coroutine resolving its own root;
- clearing with two live coroutines, after which both resolve again;
- a `$cached = false` facade sharing an accessor with a cached facade, which never returns the other's coroutine root.

### 1.3 `date` binding and the `Date` facade

**Why.** `Date::resolveFacadeInstance()` (`Facades/Date.php:126`) calls the tests-only `swap()` when `date` is unbound, and nothing binds `date`. So the first `Date::` call, usually in a request, registers a container instance at runtime. There's no concurrency defect: the construction doesn't yield.

- `FoundationServiceProvider::register()`: `$this->app->singletonIf('date', DateFactory::class);`, so an earlier binding wins. `date` isn't in the alias table, so a string concrete is correct (AGENTS binding pattern 2).
- `Date::resolveFacadeInstance()` keeps its condition (`static::$app === null || ! static::$app->bound($name)`), so it still works with no app or a plain container. It replaces `static::swap(new $class)` with `static::$resolvedInstance[$name] = new (static::DEFAULT_FACADE);`, writing nothing to the container.
- Tests (`tests/Support/DateFacadeTest.php`):
  - a booted app resolves `date` as a `DateFactory` singleton;
  - a plain container without the binding gets the default factory and `bound('date')` stays false;
  - an existing `date` binding wins over the provider's.
- Docs: add a bullet to `src/support/README.md` "Differences From Laravel" (owner-approved difference): "The framework binds `date` to `DateFactory`, so a `Date` facade subclass's `DEFAULT_FACADE` only applies while `date` is unbound. In an application, bind `date` to your factory instead."

### 1.4 Callback typing (PHPDoc only)

- `Filesystem\Concerns\InteractsWithPooledFilesystem`, each with `@return T`:
  - `withDriver()`: `@template TDriver of FilesystemOperator`, `@param Closure(TDriver): T $callback`;
  - `withAdapter()`: `@template TAdapter of FlysystemAdapter` (League's, imported under that alias), `Closure(TAdapter): T`;
  - `withClient()`: an unbounded `@template TClient`, `Closure(TClient): T`.
  - Callbacks may narrow the parameter to the disk's concrete driver, adapter or SDK client (`League\Flysystem\Filesystem`, League's `AwsS3V3Adapter`, `S3Client`). A fixed parameter type rejects those, so the template takes the callback's own type. Untyped callbacks get the bound; clients share no useful bound, so theirs stays `mixed`. `types/Filesystem/Filesystem.php` covers both forms.
- `Filesystem\FilesystemAdapter::withDriver()`/`withAdapter()`: the same types. It has no `withClient()`.
- `RedisProxy::withPinnedConnection()`, `RedisStore::withPinnedConnection()`: `@param callable(): T $callback`, `@return T`.
- `QueuePoolProxy::withConnection()`: add `@template T`, with `Closure(SqsQueue): T` and `@return T`.
- `Connection::withPinnedSession()` is already typed.
- Run `composer analyse` and fix newly exposed caller types at their source.

### 1.5 Connection resolver contract

**Why.** `ConnectionResolverInterface::connection()` declared `ConnectionInterface`, but `Model::resolveConnection()`, `Migrator::resolveConnection()` and `DatabaseManager::reconnect()` return `Connection` and carried ignores for it. Built-in and `DB::extend()` connections all come from `makeConnection(): Connection`, so a resolver returning anything else already breaks Eloquent. Laravel's contract documents `ConnectionInterface`, so this is a documented difference.

- Narrow to the driver-neutral `Connection`, never `PdoConnection`:
  - `ConnectionResolverInterface::connection()` and `CachedConnectionResolver::getResolvedConnection()`;
  - `ConnectionResolver`'s `connection()`, `getHeldConnection()`, private context reader and stored-instance checks;
  - `SimpleConnectionResolver::connection()`, and `DatabaseManager`'s `connection()`, `getHeldConnection()` and `resolveConnectionDirectly()`;
  - Capsule `connection()`/`getConnection()`, and the testing `DatabaseConnectionResolver`, including its retained connections;
  - the `DB` facade's `connection`/`getHeldConnection` tags. Its `@mixin ConnectionInterface` stays, since it supplies `transactionLevel()`'s impurity.
- Remove what the types make redundant: the `Model`, `Migrator` and `reconnect()` ignores, the manager's `@var Connection` casts, and `instanceof Connection` checks on values already typed `Connection`, including the `forgetRecordModificationState()` guards in the `RefreshDatabase` and `DatabaseTransactions` testing traits.
- Narrow the queue accessors that return a resolver connection, `DatabaseQueue::getDatabase()` (Laravel documents `Connection`) and `DatabaseOutboxRepository::getConnection()`, to `Connection`, and remove their `instanceof Connection` guards before `supportsSkipLocked()`. Independently supplied `ConnectionInterface` inputs, such as outbox `insert()`, stay. `testLockForPoppingUsesTheConnectionCapability()` covers both `supportsSkipLocked()` results; the interface-only fallback test goes, since a resolver can't return such a connection.
- Test doubles crossing the narrowed boundary mock `Connection`; ones passed straight to a `ConnectionInterface` parameter stay. Non-PDO cases keep a base `Connection` double.
- Docs: a `src/database/README.md` "Differences From Laravel" bullet and a porting-guide database paragraph. Custom resolvers return a `Connection`, and test doubles returned from `connection()` mock `Connection`.
- It's a runtime change, so it gets its own commit, including the queue accessors and their tests.

## 2. Shared analysis infrastructure

### 2.1 Facade roots

New `src/phpstan/src/Support/FacadeHelper.php`, an `@internal` `#[AutowiredService]`, memoized per facade class. It finds the method a facade static call runs without running application code: no accessor or root is executed. `FacadesMethodsExtension` keeps its existing booted-root lookup for methods a facade's PHPDoc omits; the new rules don't use it.

- `getAccessor(ClassReflection $facade): ?string` reads the nearest `getFacadeAccessor()` declaration through `@cachedRichParser`, as `ConstructorParser` does. Only a body that is a single `return` of a string literal or `X::class` gives an accessor; anything else is unknown.
- `getRootClass(ClassReflection $facade): ?string` gives the exact class the root is built as, or null when that isn't known. Aliases are never evidence: they name a container key, not the class its binding returns.
  - **Resolution path.** A `resolveFacadeInstance()` or `getFacadeRoot()` declared by the facade, or a parent below `Facade`, makes the root unknown. The one exception is the framework's own `Date::resolveFacadeInstance()`, when no subclass overrides it: with `date` bound it resolves normally, and without the binding the root is the facade's `DEFAULT_FACADE` constant.
  - **Booted container**, starting from the accessor's canonical key (`getAlias()`) and following each step's own key:
    - a booted instance → its class;
    - a binding registered with a class name (`WorkerLifetimes::getBindingConcrete()`, made public static) → resolve that class's key the same way, so a concrete that is itself bound follows its binding; a concrete equal to its abstract → that class if it's buildable;
    - a closure binding whose body is exactly `new X(...)`, as an arrow function or a single `return` → `X`. This is the AGENTS binding pattern 1 form, used for keys such as `cache`, `session`, `log`, `redis` and `mail.manager`; multi-statement factories such as `events` and `router` are covered only when the boot already resolved them;
    - no binding and a buildable class → that class;
    - anything else, or a cycle → unknown.
  - **Only exact construction counts.** These rules identify the root; they don't interpret factories, extenders or callbacks.
    - `Container::resolve()` applies registered extenders after construction (`Container.php:1299`), and an extender can return another object. So a not-yet-resolved chain with an extender registered for any of its keys (the container's `$extenders`, read like the other container state) is unknown. A booted instance stays evidence, since `extend()` replaces a stored instance directly.
    - A class reached without a factory that implements `SelfBuilding` is unknown: the container builds it through `newInstance()`, which may return a subclass. A literal `new X(...)` in a factory is still exact.
    - "Buildable" uses `WorkerLifetimes`' rules (a concrete class without `#[Bind]`/`#[BindWhen]`), shared as public static `WorkerLifetimes::isBuildableClass(ClassReflection)`; each caller looks the class up itself, so `WorkerLifetimes` still records it as a dependency. The `SelfBuilding` check stays local to `FacadeHelper`, so the helper's meaning doesn't change for `WorkerLifetimes`.
  - **Closure source matching.** `ReflectionFunction` gives the file and the start and end lines but no column. Parse the file with `@cachedRichParser` and take the closures and arrow functions with exactly that line range. Anything other than exactly one candidate is unknown, so two factories on one line never resolve. `X` must be a named class resolved through the file's namespace and imports. `self`, `static`, `parent`, dynamic class expressions and anonymous classes are unknown.
- `getMethod(ClassReflection $facade, string $name): ?ExtendedMethodReflection`: the facade's own public static native method (`swap()`, …), else the root class's public native method. A `__callStatic()` declared outside `Facade`, `Hash` and `Crypt` can forward anywhere, so it gives null after the facade's own methods; `Hash`'s and `Crypt`'s forward to the root as `Facade`'s does, keeping sensitive arguments out of stack traces.
- **Tests** (through `UnsupportedCallRule`, which reads roots without contexts):
  - a facade over a single-expression `new X` factory resolves;
  - the same factory with an extender registered is unknown, as are a `SelfBuilding` root and a factory sharing its line with another closure;
  - a facade with its own `__callStatic()` is unknown for root methods, while its own marked static method is still reported;
  - a class-name binding chain resolves to its final class.
- Every call, memoized or not, tracks `RuntimeInputs::track($scope)`, `trackSources($scope, $facade, Facade::class)` for the accessor body, and `trackClass()` for the root and any class whose declaration made it unknown. A binding closure comes from a file the boot loaded, which `track()` already covers.

### 2.2 Lifetime queries on `WorkerLifetimes`

- `sharesAbstract(string $abstract): TrinaryLogic` maps `getLifetime(getAlias($abstract), true)`: shared → yes, unknown → maybe, scoped or fresh → no.
  - `computeLifetime()` keeps a binding's own lifetime when the built class is unknown: the container caches whatever a shared or scoped closure factory returns, so it's shared or scoped; a `bind()` factory may return a singleton, so it's unknown. An unbuildable same-class concrete or an unknown concrete chain follows the same rule.
  - Yes therefore covers booted instances (including one over a scoped binding), shared bindings with closure factories, auto-singletons and a `bind()` to a shared class.
- Injected parameters: `$configParameters` becomes `$injectedParameters`, mapping `class::method::parameter` (declaring class; `__construct` for constructors) to the canonical abstract the container injects, or null when unknown.
  - Sources: booted controller actions (`discoverRoute()`; route-bound, defaulted and contextual-attribute parameters record null), job handlers (`discoverJob()`; a contextual class name replaces the dependency, a contextual closure records null), and constructors run by `build()` (`resolveConstructorParameter()` returns the outcome with the injected abstract; unknown arguments record null). A closure factory, shared or contextual, records null for every parameter of its abstract's constructor, since it may build that class with any arguments.
  - Merge: every call records through `recordInjection()`. Calls that inject different abstracts, or an unknown one, leave the entry null, so an inherited action, handler or constructor gets evidence only when every route, job or build agrees. The result doesn't depend on traversal order.
  - `isConfigParameter()` checks `isGlobalConfig()` of the recorded abstract. `sharesInjectedParameter($class, $method, $parameter): TrinaryLogic` gives no when unrecorded, maybe when null, else `sharesAbstract()` of the abstract.
- `isContainerBuilt(string $class, string $method)`: whether the container built every instance the method handles an operation for, so promoted properties hold what it injected.
  - `addContext()` records each context's receiver class per method declaration, and helpers inherit their caller's receivers through propagation. An inherited handler or helper is analyzed in its declaring class's scope, so the receivers decide, not that class.
  - A receiver counts when it isn't `ShouldQueue`, or when the dispatcher resolves it as a listener or observer (section 2.3). A serialized job is restored from its payload even when other code also resolves it from the container.
  - Every receiver must count, so a base method that a serialized-job subclass also runs gives no evidence. A method no booted handler reaches is judged by its own class.
- The new queries are used only by section 3.1 and the config-write check.

### 2.3 Listener and operation contexts in `WorkerLifetimes`

`discoverListeners()` already makes listener classes roots. Extend it to record execution contexts per registered handler, as an operation context separate from lifetime roots:

- **Registries.** The dispatcher keeps four: `listeners` (exact names), `wildcards` (`listen('x*')`), `observers` (`observe()` with exact names) and `observerWildcards` (`observe('x*')`, and `listen('*')`, which the dispatcher routes there). The first two are queueable listeners; the observer registries hold passive observers, which `makeObserver()` resolves from the container and calls synchronously. All four and `compiledWildcardPatterns` are read through closure access.
- **Handler:** `Class@method` strings, `[Class, 'method']` arrays, otherwise `handle()`; for a listener, a method the class lacks becomes `__invoke()` (`Dispatcher::createClassCallable()`). Observers have no `__invoke()` fallback. The receiver comes from `makeHandler()`, so a bound subclass and the declaring class follow the existing handling.
- **Queued handlers:** a `ShouldQueue` listener, including a `ShouldQueue` `#[ObservedBy]` observer (registered as a listener), gets a `job` context for its registered method whatever its event, since `CallQueuedListener::handle()` resolves it from the container in the queue worker and calls that method. A passive observer is never queued, so it keeps its event's context, including for a `ShouldQueue` class's `handle()`. Either way the built class is recorded as a container-built receiver for `isContainerBuilt()`. The event payload is never injected-parameter evidence.
- **Registration wins over class-only inference.** The constructor's `JOB_METHODS` loop skips a queued class's method that a registration of that same class already gave a context (a snapshot of `contextReceivers` before the loop), so another class inheriting the method, such as a serialized-job subclass, still adds its own receiver, and `WorkerStateAnalysis::getContext()` prefers the lifetimes context over `getFunctionFacts()`' class-only `job` for `ShouldQueue` handlers, keeping that inference where there's no registration.
- **Cloned listeners.** `CallQueuedListener::setJobInstanceIfNecessary()` clones the resolved listener when it uses `InteractsWithQueue`, then calls the handler on the clone. The clone is shallow: its own properties and arrays are copied for that job, but objects they hold are the container instance's. The handler method and the helpers it reaches on that receiver keep the container's shared flag in their context, since they run on a copy of a shared instance, and record the receiver as a copy (`copies[key][receiver]`). `addContext()` takes the copy kind: null, `COPY_CLONE` here, or `COPY_WITHOUT_CONSTRUCTOR` for the option instances below. The kind travels through propagation, and the visited key includes it. `COPY_WITHOUT_CONSTRUCTOR` also marks the method `withoutConstructor`, which makes `isContainerBuilt()` false: a method of the class can initialize a readonly promoted property itself, so it may not hold what the constructor would have been given. "Copy" is the analysis's shared category for both; PHP doesn't clone the option instance. A shared context that isn't a copy marks its method `direct`. Methods on the container's instance stay shared and are seeded as their own entries: `failed()` through the `JOB_METHODS` loop, the dispatcher's `shouldQueue()` (`handlerWantsToBeQueued()`) with the event's context, which may be unknown, and `CallQueuedListener::uniqueVia()`/`debounceVia()` with `job`, only for a `ShouldBeUnique` or debounced listener. The unique and debounce locks call those two when the dispatcher acquires them and again when they're released.
  - **Option instances.** The dispatcher reads the job's options from an instance of the registered class made with `newInstanceWithoutConstructor()` (`createListenerAndJob()`), not from the container, so a binding to a subclass doesn't apply to it. Its option methods are seeded on the registered class with the event's context, unshared, as `COPY_WITHOUT_CONSTRUCTOR` visits:
    - `viaConnection()`, `viaQueue()` and `withDelay()` (`queueHandler()`); `backoff()`, `retryUntil()`, `tries()`, `messageGroup()` and `middleware()` (`propagateListenerOptions()`);
    - `deduplicator()`, or `deduplicationId()` when the class has no `deduplicator()` (the SQS queue calls it when pushing);
    - `uniqueId()` and `uniqueFor()` for a `ShouldBeUnique` class, and `debounceId()` for a debounced one.

    The instance holds only property defaults, so its copy-only methods' writes to its own properties and arrays stay with it, through the copied-writes handling below. Static writes, restricted calls and swallowed cancellation are checked in the event's context. A helper it shares with a shared receiver's method, such as `shouldQueue()`, stays shared.
  - **Debounced listener**, as the dispatcher's `getAttributeValue()` reads it from outside the listener: a `#[DebounceFor]` attribute (`ReflectionHelper::findClassAttribute()`; its value is never null), or a public non-static `debounceFor` property with a non-null default, `0` included. A default isn't a declaration, so `getDefaultSources()` returns each class whose default was read, including when it disables debouncing, and `WorkerStateAnalysis::track()` tracks them with `trackSources($scope, $class, null)`.

  A context entry may therefore have an unknown operation (`addContext()` takes a nullable context and keeps a known one), and the visited key includes the operation so each one propagates. `addHandlerContext()` records every handler visit whose receiver has the method, including a passive observer of an event with no known operation: `Dispatcher::makeObserver()` calls the resolved instance synchronously, so that visit is `direct` and keeps a queued registration of the same class from making the method copy-only. No operation is invented for it, and it counts as a registration in the `JOB_METHODS` snapshot like a known-context observer. After propagation, the constructor computes the copy-only methods once, so every class it reads is a dependency like the other lifetime decisions. A method is copy-only when it has copy receivers, isn't `direct`, and every shared receiver that is its class or a subclass and runs its declaration (`resolveHelper()`, so a parent's private method counts for subclasses) is one of its copy receivers. So a concrete parent the container also shares on its own, or an abstract parent with a shared sibling subclass, stays shared. `runsOnCopy(class, method)` reads that map.
  - **Copied writes.** Section 3.1's `WorkerStateRule::checkWrite()` treats a copy-only method's copied writes as unshared for the unbounded-cache, retained-state and handler-write branches. `WorkerStateWriteCollector` records `copied`: the write replaces the property itself, or every offset level it writes or unsets is definitely not an object (an array, a string, or null that the write turns into an array). A write into an object the property holds or may hold at any level, such as a `Collection` or an `array|Collection` union, stays shared. Each write is judged on its own, so a write into an object the method itself just put in the property is still checked; following property replacements would need flow analysis of the method body.
  - Static writes, constructor captures and configuration writes are unchanged. Restricted calls still see a clone's promoted properties as shared, but not those of a method an option instance reaches (`withoutConstructor`).
- **Context sources**, where any registration proves runtime use. An exact name takes its event's context. A wildcard pattern takes every distinct context of the framework events below that it matches (the dispatcher's compiled pattern), plus `operation` when it starts with `eloquent.`; a handler with several contexts is recorded once per context.
  - `request`: `HttpServer\Events\RequestReceived`, `RequestHandled`, `RequestTerminated`, `ResponseSent`, `RequestCanceled`, and `Routing\Events\Routing`, `RouteMatched`, `PreparingResponse`, `ResponsePrepared`.
  - `job`: `Queue\Events\JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred`, `JobAttempted`, `JobReleasedAfterException`, `JobTimedOut`.
  - `operation`: `Core\Events\OnReceive`, `OnPacket`, `OnTask`, `OnFinish`, `OnPipeMessage`, `OnConnect`, `OnClose`. Each is dispatched per server operation from its `Core\Bootstrap\*Callback`.
  - `operation` for Eloquent observer methods: they can run during any operation that persists models, including requests, jobs, commands and seeders. Messages say "during an operation", not HTTP.
    - Listeners registered under `eloquent.*` events in the booted dispatcher (`observe()` in a provider).
    - `#[ObservedBy]` observers. `HasEvents::bootHasEvents()` registers them lazily at a model's first use, so the booted dispatcher lacks them. `WorkerStateClassCollector` records each concrete `Model` subclass's effective observers: the `#[ObservedBy]` class-strings of the model and its ancestors below `Model` (as `resolveObserveAttributes()` merges them), with its observable events (the standard `getObservableEvents()` names plus its inherited or redeclared `$observables` default). Property defaults aren't declarations, so it tracks `RuntimeInputs::trackSources($scope, $model, Model::class)`.
    - A model overriding `bootHasEvents()`, `observe()`, `resolveObserveAttributes()`, `registerObserver()` or `getObservableEvents()`, or whose `$observables` default isn't a list of strings, records nothing; booted registrations still count. Runtime `setObservableEvents()`/`addObservableEvents()` changes aren't modeled.
    - New `WorkerLifetimes::discoverObservers()` makes each observer a root through `makeHandler()` and gives `operation` to each event method the observer has natively (inherited included), as `registerObserver()` registers `Observer@event`.
- Unknown app events add nothing. Contexts propagate to `$this` helpers through the existing helper-call propagation.
- **Tests** (`WorkerStateRuleTest`): a request-event listener, a `Class@method` listener, a queued listener (job context), an `observe()` observer, direct and repeated `#[ObservedBy]`, an attributed abstract parent with a concrete child adding a custom event, an inherited observer method, a helper the event method calls (context) and an unused helper (none). A wildcard listener matching job events (job), a `listen('*')` observer, and a `ShouldQueue` passive observer's `handle()` (request context, not job, with a promoted repository write reported through `isContainerBuilt()`). An `InteractsWithQueue` queued listener whose handler keeps request state and a request-keyed cache, writes a string offset and an offset of a null `?array` property, and calls a helper writing a property (silent), with its `failed()` write and a request-keeping helper that both `handle()` and `shouldQueue()` call (reported); the existing queued listener without the trait stays reported. Writes through shared objects on the clone path: a request-valued offset into a `Collection` property, a scalar written two levels deep into an array of `Collection`s and a scalar offset of an `array|Collection` property in `handle()`, and a scalar `Collection` offset in a helper only `handle()` reaches (reported). An inherited helper of a concrete parent the container also shares, and of an abstract parent with a shared sibling subclass (reported). An `InteractsWithQueue` queued listener that is also a passive observer of an unknown event, whose `handle()` keeps the request (reported).
  - Option instances (`worker-state-listener-options.php`, its own test method), on request-event queued listeners: `viaQueue()` writing a static property (reported) and a scalar, a request value and a request-keyed array entry to its own properties (silent); a request-keeping helper both `viaQueue()` and `shouldQueue()` call (reported). A registered class bound to a subclass that overrides `viaQueue()`: the registered class's request write is silent, the override's is reported. A static write in `uniqueId()` of a non-unique listener (silent) and of a `ShouldBeUnique` one (reported). Static writes in `deduplicationId()` (reported), and in a class with `deduplicator()`, in that (reported) and in its `deduplicationId()` (silent). Static writes in `debounceId()` with `#[DebounceFor]` and with a `debounceFor = 0` default (reported), and with a `null` default (silent).
  - `RestrictedCallRuleTest`: a `viaQueue()` that initializes a readonly promoted registry with `??=` and calls a `whenShared` method on it (silent), beside the same call in a constructed listener's `handle()` (reported).
- These contexts feed `getContext()`, so `WorkerStateRule` gains them too. Re-run its tests and review any new reports.

### 2.4 Collected data and the shared model

`WorkerLifetimes` exists only in a `CollectedDataNode` rule, because it's built from every file's collected classes, resolutions, route actions and helper calls. Rules that need contexts or lifetimes therefore collect per-file facts and report from the collected data.

- **Shared model.** Move the model building out of `WorkerStateRule::processNode()` (grouping the collected resolutions, singletons, queued classes, helper calls, route actions and reassigned parameters, then constructing `WorkerLifetimes`) into a new `Rules/Coroutine/WorkerStateAnalysis`, together with the rule's `getContext()`, `findAction()`, `getFunctionKey()`, `isReassigned()` and `isInjectedConfig()`.
  - `WorkerStateHelper::analyze(CollectedDataNode $node): WorkerStateAnalysis` builds it once per node, keeping the last node and its analysis. The helper takes over `HasContainer` from `WorkerStateRule`.
  - Each rule calls `WorkerStateAnalysis::track($scope)` after its queries. It tracks the booted application and the lifetime classes, which a query can add to. `describeContext()` moves there too, shared by the three rules.
  - `WorkerStateRule`, `RestrictedCallRule` and `SwallowedCancellationRule` all use it.
- **Facts.** `WorkerStateCallCollector` keeps its one fact per call (resolution, route action, helper edge or config write). New facts come from their own collectors, so a call can be both, for example a route registration that is also a restricted call:
  - New `RestrictedCallCollector` (on `CallLike`, under `checkRestrictedCalls`) records `{line, method, attribute, condition, may, whenShared, receiver, function}`. `condition` is the marker's parameter condition for the message, and `may` means it may not hold. `receiver` is section 3.1's evidence: `{abstracts}` (a facade accessor or a literal resolution), `{parameter}`, `{property: [class, property]}`, or null. Unmarked calls, calls whose parameter condition definitely doesn't hold, and configuration writes (section 3.1) aren't collected.
  - New `SwallowedCancellationCollector` (on `TryCatch`, under `checkCancellationHandling`) records `{line, operation, function}` for each catch that section 3.17 reports once a context is known.
- **Enablement.** `WorkerStateHelper::isEnabled()` also covers `checkRestrictedCalls` and `checkCancellationHandling`, so the class, call and parameter collectors gather what the shared model needs when only a new check is on. New `hasStateChecks()` covers the four existing switches: `WorkerStateRule` and the write and config-offset collectors use it.
  - Regression test: with the four worker-state switches off and `checkRestrictedCalls` on, a restricted call in a controller action's `$this` helper is still reported (its own neon config beside the rule tests).

### 2.5 Rule conventions

- Each rule is an `@internal` class with `#[AutowiredService]`, or `#[Conditional(attribute: new AutowiredService, if: '%switch%')]` for switched rules. It filters by node kind and method or function name before reflection.
- Rules use `RuleErrorBuilder` with the identifier and a tip pointing at the fix.
- Switches go in `extension.neon` `parameters` and `parametersSchema` (`bool()`).
- Every rule gets a `RuleTestCase` in `tests/PHPStan/Rules/...` with a `data/` fixture: violations, the allowed alternatives, and each documented exception.
- Run each test file immediately: `timeout 600 vendor/bin/phpunit -c phpunit.phpstan.xml.dist --no-progress <file>`.
- Each `static-analysis.md` section states the pattern, a short example, the fix and the legitimate exception (ignore with a reason), in Laravel-docs prose.

**Rules that identify calls** share these requirements:
- Creating a first-class callable (`$factory->globalOptions(...)`) isn't a call and is never reported. PHPStan passes rules a `*CallableNode` instead of the call node, so only code reading nested raw nodes (a statement's expression, a walked callback) checks `isFirstClassCallable()`.
- A call is matched by its resolved declaration, through nullsafe calls too, with PHP's case-insensitive names. PHPStan passes a nullsafe call to `CallLike` rules twice, as a virtual `MethodCall` and then as the original `NullsafeMethodCall`, so such a rule (`NoCoroutinesInTransactionsRule`) skips the top-level original; raw body walks still match it. A user override or custom implementation is judged by its own declaration, so its body is unknown; config, container and facade methods are recognized by exact declaring class, not by name or ancestry. A facade subclass's own native method is such an override.
- Arguments bind to parameters by name or position (`CallLike::getArg($name, $position)`). An unpacked argument at or before the parameter's position makes the binding unknown; it's never guessed positionally.

**Variable writes.** `CoroutineRuleHelper::assigns()` decides whether a node writes a variable: `=`, `=&` or a compound assignment, a destructuring leaf, a `foreach` key or value target, or a catch binding. Destructuring uses `CoroutineRuleHelper::getTargetLeaves()`, a public static moved from `InspectsConnections`, which its trait callers use too. Its callers are the swallowed-cancellation rethrow and `$previous` forwarding checks and `#[BindWhen]`'s container parameter.

**Cache dependencies** use the existing `RuntimeInputs` and scope tracking, never a new mechanism:
- booted state (bindings, aliases, routes, listeners, config, package mode, `bound()`): `RuntimeInputs::track()`;
- facade accessor bodies: `RuntimeInputs::trackSources($scope, $facade, Facade::class)`;
- queued listeners' `debounceFor` defaults (2.3): `trackSources($scope, $listener, null)`, whose base class is nullable to cover every ancestor;
- classes whose declarations decide a result (facade roots, lifetime classes, Socialite parents): `trackClass()`/`trackClassDependency()`;
- code read beyond declarations, which PHPStan's exported nodes don't cover. Track their files with `trackFileDependency()`:
  - exception constructor chains (3.17, the files from `SourceFiles::forClass()`: constructor bodies only, so no property defaults);
  - first-class-callable `#[BindWhen]` conditions (3.16), the declaration's file;
  - declared default values (`whenNotDefault`; exported nodes only record that a default exists): `SourceFiles::forParameterDefault()`, the method's file and the files of the constants the default reads;
  - attribute arguments reading constants from other files: exported nodes keep the argument expressions, not the constants' values. `SourceFiles::forAttributeArguments()` takes an optional method name for method attributes. It covers lifetime markers (3.1), `#[Unsupported]` messages (3.2) and each model ancestor's `#[ObservedBy]` (2.3).

Warm-cache tests go in `tests/PHPStan/WorkerStateDependencyTest.php`, one per mechanism rather than per rule: a booted registration changing a lifetime, a facade accessor body, a constructor forwarding `$previous`, a declared default value read from another class's constant, and a queued listener's `debounceFor` default, inherited from a parent in another file so no collected data changes, going from `null` to `0` while its `debounceId()` is unchanged. `SourceFilesTest` covers the method-attribute lookup.
- `WorkerStateRule` reuse tests analyse once more before the edit, only with `reuseCollectedDataRuleErrors` on (without it the rule always runs). PHPStan 2.3.5's `ClassResultCacheValueExtension` puts the declaring file's path in a class's value, and a stub-backed class (`Redis`, `Swoole\Server`, an interface such as `Countable`) gets its stub path spelled differently depending on the source locator that found it. In the `WorkerStateDependency` fixture the stored values differ between a cold and a warm run, so the first warm run after a cold run reruns `WorkerStateRule` whatever changed, and a cold → edit → warm test passes without the tracking it means to prove.

**Test sizes.** Each concrete `ResultCacheTestCase` subclass is `#[Large]`: they copy an application and run PHPStan in subprocesses, and PHPUnit doesn't inherit size attributes from a parent. `phpunit.phpstan.xml.dist` sets `timeoutForLargeTests="180"`; other tests keep the 60-second limit. `RuntimeInputsDependencyTest` deliberately runs its analyses in parallel, so its parameters cap them at two processes (`maximumNumberOfProcesses: 2`) rather than one per CPU under each ParaTest worker.

**Testbench runtime copy.** A package analysis boots a Testbench copy of the skeleton that is deleted at exit. `Bootstrapper::copySkeleton()` copies or links the skeleton's `vendor`, or links the working package's when the skeleton has none.

- Tracking `PackageManifest::$vendorPath`'s metadata by the copy's path made every warm run reanalyse each `track()` consumer (about a third of components).
- `RuntimeInputs::createKey()` instead tracks two things:
  - `composer/installed.json` and `installed.php` under the skeleton's `vendor` and under the working vendor, each by its own spelling and including absence;
  - the skeleton `vendor` directory's presence, through a new directory-presence entry in `RuntimeInputsResultCacheValueExtension`. An empty one stops the link to the working vendor.
- `Bootstrapper::runtimeVendorPath()` returns the working vendor path, stored and reset beside `runtimeSourcePath()`. `runtimeCopy()` resolves it with the other ownership fields.
- Only the copy's standard `/vendor` is replaced. Any other manifest vendor keeps the `sourcePath()` handling.
- `RuntimeInputsPackageDependencyTest` covers an unchanged rerun, a repointed working-vendor link whose old target remains, and an empty skeleton `vendor` added and removed. It also covers skeleton metadata appearing after the working vendor was used.

### 2.6 Global variable writes

**Why.** A global variable is shared by the whole worker like a static property, so `$GLOBALS['current_user'] = $user` in a request has the same cross-request harm as the static writes `WorkerStateRule` reports. PHPStan's scope doesn't say which variables are global (`GlobalHandler` only assigns `mixed`), and its `VariableAssignNode` for a `$GLOBALS` offset write carries only the whole array's new type.

**How.** `WorkerStateGlobalWriteCollector` (under `checkStaticStateMutation`, `Node` filtered by kind) records:
- on raw nodes: the targets of `CoroutineRuleHelper::getWriteTargets()` (assignments, compound assignments, `=&`, destructuring, `foreach` keys and values, `catch` bindings), increments and decrements, and each offset or property `unset()` removes. A plain variable the node unsets or binds by reference (`getDetachedNames()`: `unset()`, `=&`, a by-reference `foreach` value or destructuring item, found by `getReferenceLeaves()`) is rebound, not written; an offset or property bound by reference is still written. Each `$GLOBALS`-rooted target is a `global` fact, and each other variable-rooted target given request state a `value` fact. State comes from a whole value only: an assignment's or `??=`'s expression, or the iterable's value or key type for a whole `foreach` value or key target. `$this` targets are skipped, since `$this` can't be bound to a global;
- arguments a by-reference parameter writes, joined from two sides without resolving callees. Raw calls (not first-class callables, and not PHPStan's original nullsafe call beside its virtual method call) give candidates: an argument rooted at `$GLOBALS` (from the call node) or at a bound name (from the body walk) is a fact with `argument: true`. PHPStan's own call handling gives the evidence: its `VariableAssignNode` or `PropertyAssignNode` for a by-reference argument writes a `NativeTypeExpr`, and any `VariableAssignNode` of `$GLOBALS` counts too, since an offset argument is reported like any offset write. Both carry the target key, and an argument fact counts only with evidence at the same key, without state. PHPStan writes a `NativeTypeExpr` elsewhere too (`foreach` values), so the position join is what ties the event to the argument. An offset of a bound name passed by reference (`sort($held['key'])`) isn't covered: PHPStan reports it exactly like `$held['key'] = …`, so telling them apart would need evidence for every offset write or callee resolution;
- on `InClassMethodNode`, `InFunctionNode` and `InClosureNode`: a walk of the function's own top-level statements in order. A top-level `global` binds its names; one under a condition binds nothing. So does a top-level reference assignment between a plain variable and global storage (`CoroutineRuleHelper::getStorageAlias()`): `$alias =& $GLOBALS['key']`, `$alias =& $bound` or an offset of it, or that storage `=& $local`. A property isn't followed, since a property can return storage other than its object's by reference. It's read before the statement detaches the left name, and a plain left side is only re-pointed (`global $held; $held =& $local;` doesn't make `$local` global). `CoroutineRuleHelper::getDetachedNames()` (`unset()` of the variable, `static`, a by-reference assignment to it) detaches a name. Closures, arrow functions, named functions and classes are skipped, since they have their own bindings. Each write to a bound name is a `bound` fact.

Facts carry a target key: the enclosing class and function and the target's position. Trait code is collected under the using class's file with positions from the trait's own file, so the position alone isn't unique. `WorkerStateRule` gives a `bound` fact the state of the `value` fact with the same file and key, then reports `hypervel.staticStateMutation` with `STATIC_TIP`: "Global variable $GLOBALS['x'] keeps the current request, which the whole worker then shares." when it keeps state, else "Global variable $x is written while handling a request, but global variables are shared by the whole worker." in a context. Request-keyed arrays on globals aren't checked as unbounded caches.

**Tests** (`worker-state-globals.php`, `WorkerStateRuleTest::testGlobalVariables`): in the request helper, `preg_match()` into a bound name, `sort($GLOBALS['list'])`, a nullsafe call's by-reference argument of a bound name and of a `$GLOBALS` entry (each reported once) and `sort()` of a bound object's property (reported), with read-only arguments of the same shapes and creating a first-class callable (silent); outside any context, forward, reverse, offset-reverse and chained aliases of global storage, `??=` into a bound name and a `$GLOBALS` entry (they keep the request), and a re-pointed bound name, an unset alias, a rebound alias and a reference to a bound object's property (silent); in a booted action, a `$GLOBALS` request value, a `$GLOBALS` increment and `unset()`, and a named global in a helper the action calls (reported); in another helper, a named global's offset and property `unset()`, a by-reference offset write of the request (it keeps it), compound assignments to a named global and a `$GLOBALS` entry, and destructuring into both, reported through the context (reported), a `foreach` key and value (the value keeps the request), a destructured `foreach` value, a by-reference `foreach` into a `$GLOBALS` entry (it keeps the request), a reference destructuring into a named global's offset and a `catch` binding (reported), with a guarded named function writing its own variable of a bound name, a write after a bare `unset()`, and writes after a by-reference `foreach` value or a reference destructuring item rebinds a bound name (silent); outside any context, a named global's request-valued offset and a `$GLOBALS` request value (reported); an ordinary local, destructuring a request into a bound name, a nested closure's own variable, writes after `unset()` and after a by-reference rebinding, a conditional `global`, a write before the `global` statement and a provider's boot-time scalar (silent). Two traits used by one class, in their own files (`worker-state-globals-request.php`, `worker-state-globals-account.php`), write a request and a user to a bound name at the same position, and each is reported with its own state. The same files are silent with `checkStaticStateMutation` off.

## 3. Rules

| # | Rule class | Identifier | Switch (default) |
|---|---|---|---|
| 3.1 | `Rules/Coroutine/RestrictedCallRule` | `hypervel.restrictedCall` | `checkRestrictedCalls` (true) |
| 3.1 | `Rules/Coroutine/RestrictedAttributeRule` | `hypervel.restrictedAttribute` | `checkRestrictedCalls` (true) |
| 3.2 | `Rules/UnsupportedCallRule` | `hypervel.unsupportedCall` | none |
| 3.3 | `Rules/Coroutine/BorrowedResourceEscapeRule` | `hypervel.borrowedResourceEscape` | `checkBorrowedResources` (true) |
| 3.4 | `Rules/ManagedTransactionRule` | `hypervel.transactionEndedEarly` | `checkManagedTransactions` (true) |
| 3.5 | `Rules/NoNativeRequestApisRule` | `hypervel.nativeRequestApi` | `noNativeRequestApis` (true) |
| 3.6 | `Rules/DiscardedDateRule` | `hypervel.discardedDate` | `checkDiscardedDates` (true) |
| 3.7 | `Rules/NoMutableDatesRule` | `hypervel.mutableDate` | `noMutableDates` (true) |
| 3.8 | `Rules/UncastEnvValueRule` | `hypervel.uncastEnvValue` | `checkConfigEnvCasts` (true) |
| 3.9 | `Rules/TypedConfigGetterRule` | `hypervel.typedConfigGetter` | `checkTypedConfigGetters` (true) |
| 3.10 | `Rules/ObserverAttributeRule` | `hypervel.observerAttribute` | `checkObserverAttributes` (true) |
| 3.11 | `Rules/MigrationColumnTypeRule` | `hypervel.migrationColumnType` | `checkMigrationColumnTypes` (false) |
| 3.12 | `Rules/BindingCycleRule` | `hypervel.bindingCycle` | `checkBindingCycles` (true) |
| 3.13 | `Rules/Coroutine/NoRawCoroutinesRule` | `hypervel.rawCoroutine` | `noRawCoroutines` (true) |
| 3.14 | `Rules/SocialitePropertyRule` | `hypervel.socialiteProperty` | `checkSocialiteProviders` (true) |
| 3.15 | `Rules/Coroutine/SwooleTableInProviderRule` | `hypervel.swooleTableInProvider` | `checkSwooleTables` (true) |
| 3.16 | `Rules/RequestDependentBindingRule` | `hypervel.requestDependentBinding` | `checkBindWhenConditions` (true) |
| 3.17 | `Rules/Coroutine/SwallowedCancellationRule` | `hypervel.cancellation.swallowed` | `checkCancellationHandling` (existing) |

### 3.1 Restricted calls

`RestrictedCallRule` is a `CollectedDataNode` rule over the `restricted` facts (section 2.4).

**Reports.** A call to a method carrying `#[BootOnly]`, `#[TestsOnly]` or `#[BootOrTestsOnly]`:
- **Where:** in a request, job or operation context, as `WorkerStateAnalysis::getContext()` gives it: booted middleware and controller actions, route closures, form requests, queued handlers, the section 2.3 contexts, and their `$this` helpers.
- **Which methods:** the resolved declaration of an instance or static call (including a facade's own methods such as `swap()`); for other facade static calls, `FacadeHelper::getMethod()`. A facade whose root is unknown isn't collected.
- **Not reported:** configuration writes, exactly the calls `WorkerStateCallCollector::isConfigWrite()` recognizes (`config()` with an array, a `Config` facade write, or `set`/`push`/`prepend`/`offsetSet`/`offsetUnset` on a repository). Move that method to `WorkerStateHelper` so both collectors call it. `noRuntimeConfigMutation` owns those writes, using its own global-repository evidence, so disabling it silences them completely. Every other marked repository method, such as `Config::macro()`, stays a restricted call.
  - Its evidence gains the receiver forms below: an application-container resolution (`getApplicationResolvedAbstracts()`) and a readonly promoted repository property, where `isContainerBuilt()` holds and the constructor's injected abstract is the global repository (`WorkerStateAnalysis::isPromotedConfig()`). Method and offset writes both use it.
  - An offset write on a repository-typed property that `getConfigReceiver()` recognizes is a configuration write, as its `set()` is, so `WorkerStateWriteCollector` skips it and one write gets one diagnostic. Other repository properties, such as a static one or one the constructor doesn't promote, get the ordinary static and shared-write checks, like any other object property.
  - `WorkerStateConfigOffsetCollector` collects `Assign`, `AssignOp` and `Unset_` nodes (its node type is `Node`, filtered by kind first), with PHPStan's scope at the write, so an offset assignment inside a return, an argument, a condition, a chained assignment or an arrow function is collected. Nested callbacks stay excluded by the context rules; an arrow-function route action is covered.
- **Message:** names the restriction and the context. The tip says to move the call to a service provider (boot), a test, or to keep per-request state in `CoroutineContext`/`scoped()`.

**Conditions** (from PR 1's attribute arguments):
- **`whenNotDefault: 'p'`.** Find the argument bound to `p`. Omitted, or a constant equal to the declared default → silent. A type that may differ (unknown, union), or an unknown binding → reported, with "may change" wording.
- **`whenProvided: 'p'`.** An argument bound to `p` → reported; omitted → silent; an unknown binding → reported with "may change" wording.
- **`whenShared: true`.** Report only with evidence that the receiver is the worker-shared instance:
  - a facade whose accessor `sharesAbstract()` is yes;
  - a literal resolution from the application container whose abstract is shared, through `WorkerStateHelper::getApplicationResolvedAbstracts()`: `app('x')`, `resolve('x')`, `App::make('x')`, or the framework container's `make`/`get`/`factory` called on an application instance (`app()`, an injected application, `$this->app`) or on `Container::getInstance()`. A worker runs one application, so an `Application`-typed receiver is the booted one. Another container (`(new Container)->make('x')`) or a user override of the resolving method may hold other bindings, so it isn't evidence;
  - a parameter of a container-called entry method that the container actually supplies on that invocation path, where `sharesInjectedParameter()` is yes: booted controller actions through `ResolvesRouteDependencies::transformDependency()` (excluding route-bound and defaulted parameters), and job `handle()`/`__invoke()` through `Container::call()` as `WorkerLifetimes::discoverJob()` models it. Event, listener and Bus command payloads, contextual attributes and route closure parameters aren't evidence. A parameter reassigned before the call loses the evidence, including through a by-reference argument, which PHPStan reports as a variable assignment. Under `checkRestrictedCalls`, `WorkerStateParameterCollector` also records reassignments of method parameters that may hold an object.
  - a `readonly` promoted constructor property read as `$this->prop` outside closures (`WorkerStateHelper::getPromotedProperty()`; the code's class must run the constructor that promotes it), where `WorkerStateAnalysis::sharesPromotedProperty()` holds: `isContainerBuilt()` for the code's method, and `sharesInjectedParameter($constructorClass, '__construct', $prop)` is yes, so every build of that constructor injects the shared instance.

  Everything else is silent, including bounded local `new` evidence (a `new` expression receiver, or a local variable assigned only from `new`). `WorkerLifetimes::isShared($class)` is class-level and never counts as receiver evidence. Reference-alias mutations (`$alias = &$registry; $alias = new Registry;`) aren't modeled: a later call through the parameter keeps its evidence.
- **A parameter condition and `whenShared`:** both must hold.
- **Unconditional attributes:** reported regardless of receiver.

**`RestrictedAttributeRule`** (on `InClassMethodNode`) reports a lifetime attribute that would silently disable or misstate enforcement: `whenNotDefault` or `whenProvided` naming a missing parameter or one without a default, both set, or `whenShared` on a static method.

**Tests:**
- static, instance and facade calls, and a facade with an unknown root (silent);
- each condition's positive, negative, named-argument and explicit-default cases, plus an unpacked argument;
- contexts: request, job, operation listener and `$this` helper;
- a boot provider call, a test-case call and a first-class callable, all silent;
- `Config::set()` in a request: one `hypervel.runtimeConfigMutation` diagnostic only; with `noRuntimeConfigMutation` off (in the 2.4 switch config), `Config::set()` is silent while `Config::macro()` is still a restricted call;
- `Date::useClass()` in a request (the `Date` resolution path in section 2.1);
- receivers: a singleton-factory promoted property (reported) with contextual class and closure counterparts (silent); `app()->make()` (reported) and a local container's `make()` (silent); a parameter replaced through a by-reference argument (silent); a queued listener's promoted property (reported in a job) beside the serialized job's (silent); an inherited queued-listener handler and the helper it calls (reported), with the base's `handle()` that a serialized-job subclass runs (silent); an inherited job handler and a twice-routed action whose injections disagree, in the order where keeping one injection would report (silent);
- promoted repository property writes: `set()` and an offset write, one config diagnostic each, and a contextual repository (silent); offset writes to a static and to a non-promoted repository property, each reported once by the ordinary write checks (`WorkerStateRuleTest`);
- offset writes inside a chained assignment, an argument, an `if` condition (and a nested statement in its body) and a return, and an arrow-function route action (reported) beside a nested callback inside a route (silent) (`WorkerStateRuleTest`);
- the `Http\Client\Factory` example: `(new Factory)->globalOptions()` silent, `Http::globalOptions()` reported;
- `RestrictedAttributeRule`: each malformed case and a valid marker.

### 3.2 Unsupported calls

- **Reports:** any method or static call whose resolved declaration has `#[Unsupported]`, and facade static calls whose `FacadeHelper::getMethod()` has it. A per-node rule: it needs no lifetimes or contexts.
- **Message:** "`Class::method()` is not supported by this implementation and always throws.", followed by a newline and the attribute's `message` when `getArgumentTypes()['message']` has exactly one nonempty constant string (PHPStan maps positional arguments to parameter names). The guidance goes in the message, not a tip, because the GitHub, Checkstyle, JSON, GitLab and Raw formatters drop tips; PHPStan's deprecation rules also append their description on a new line. No validation of the message itself.
- **Silent:** an override without the attribute, calls through a contract or base whose resolved declaration is unmarked, and calls inside a method that is itself marked, which may reject through another (a stream's `rewind()` calls `seek()`).
- **Tests:** API-client `PendingRequest::setClient()` reported with its positional message; a bare fixture marker reported with the generic message only; the HTTP client's `setClient()` not reported; a subclass override; a marked method delegating; a facade call; a facade with its own `__callStatic()` (section 2.1).

### 3.3 Borrowed resource escape

A per-node rule on the lending call: it reads the callback literal (closure or arrow function) as written, since PHPStan's scope doesn't track aliases.

**Applies** inside the callback of these methods, matched by resolved declaring class (a user override or same-named method is skipped):
- `RedisProxy::withConnection()` (and the `Redis` facade's) and `StoreContext::withConnection()`: raw handle `client()`;
- `QueuePoolProxy::withConnection()`: `getSqs()`;
- `withDriver()`/`withAdapter()`/`withClient()` declared by `FilesystemPoolProxy` or `ClientPooledFilesystem` (the `InteractsWithPooledFilesystem` users); a plain `FilesystemAdapter` lends its own driver;
- `Connection::withPinnedSession()` (and the `DB` facade's): `getPdo()`/`getReadPdo()` on the same connection, which is a variable the callback receives and never reassigns, `$this`, a `$this->property` the callback never writes, or `DB::` statics for a `DB::` pin. A property owner must be a native, non-static, non-virtual property without a get hook (`InspectsConnections::getPropertyKey()`), so every read returns the stored connection, and the callback must have `$this` (not static). Other owner forms are unknown, so silent. A `DB::connection('x')` pin isn't followed: the callback receives nothing, and `connection()`'s driver-neutral `Connection` has no PDO getters, so code taking a PDO narrows the connection into a variable first, which the variable form covers.

Raw handles are recognized only on the lent parameter or its aliases, whose class the lending method fixes. A pinned session's PDO getters count only through the framework's declarations (`runsConnectionMethod()`, which includes `PdoConnection`), so a facade subclass's or connection subclass's own `getPdo()` is unknown.

**Tracked values:** the lent parameter, the raw handles taken from it, local variables holding them, and arrays they were appended or written into. A value may come from any branch of `?:`, `??`, a ternary or a `match` (`getAlternatives()`), for kinds, facts and closures passed to a spawn alike. As with statement bodies, these branches are inspected syntactically; the rule does not prove reachability and can report an escape written in a dead branch. `??=` stores its assigned value, and its result is the target or that value, so a borrowed target stays borrowed. It isn't a reset: on a defined variable it merges, so `$client = null; $client ??= …;` stays untracked. A value read back from an array (an offset, a `foreach` value, or a destructured leaf without an exact literal item) is unknown, since an array holding a borrowed value may hold safe ones too. Nested non-spawn closures are walked too, receiving tracked variables through `use` or arrow-function capture; their returns stay inside the callback. A named function declared in the callback takes nothing from it, so its body is skipped.

**Statement order.** Each function body is walked forward over its top-level statements with one current environment: each variable's borrowed kind, the defined names, and the closures each variable may hold, each with what it captured by value when it was created. There's no branch merging, loop fixpoint or history.
- **A reset** is exactly `$v = expr;` as a top-level statement. Its right-hand side is checked for escapes first, then its fact replaces `$v`'s: a closure or array literal gives fresh facts, and `$v = $w` copies `$w`'s. So `$b = $a; $a = null;` keeps `$b`, and an `$items = []` after a local use empties the array.
- **Any other statement** applies its writes conservatively first, then checks its escapes. Each write runs at most once, in node order, unless the statement's own nodes hold a loop or a `goto`, or a value it assigns holds another assignment, which the value may read before or after (`repeatsWrites()`). Then a small fixpoint over the statement's writes follows the alias chains a loop carries. So a later append in a branch doesn't flow back into an earlier copy. This isn't execution order in general: a loop anywhere in the statement repeats all its writes, and a value read before its own nested assignment (`[$client, ($client = null)]`) isn't followed. The writes:
  - a write to a defined variable keeps it borrowed only when the old and new values both are;
  - a write to an undefined variable takes the new value's kind;
  - a write with an unknown value (list, `foreach`, `catch`, `static`, `global`) untracks it;
  - a borrowed append or offset write (`$items[] = …`, `$result['pdo'] = …`) makes the array borrowed until a reset;
  - closures merge, so any alternative that captures still reports. Two values holding a closure from the same node merge it (`mergeCallables()`): it keeps the first fixed kind either captured and every name either reads by reference.
- **Captures.** A by-value capture (an arrow function, a by-value `use`) is fixed when the closure is created, so reassigning the captured variable afterwards doesn't clear the closure. A by-reference `use` is read from the current environment wherever the closure escapes: a spawn, a return or a property store, including when the closure is carried in an array, copied to another local, or captured by value by another closure.
  - A held closure is `array{ArrowFunction|Closure, ?string, array<int, array<string, true>>}`: the node, the kind it captured by value (from the frozen kinds of the callables it captured, never their live references), and the by-reference variables read live through it, keyed by the closure capturing them: itself and every callable it captured by value at creation, with the names for the same closure merged. So it keeps them even when the variable that held a closure is replaced, and `getLiveKind()` reads them at the escape. The `visited` guard records the names already read for each closure, which covers closures capturing themselves.
  - **Carried into a nested function.** A nested function's environment doesn't have the enclosing function's variables, so each held closure it captures is frozen at its creation: its live kind is read then, from the environment without the variables the enclosing function writes in the creating statement or later (the same rule as by-reference captures below). A variable the nested function itself captures by reference is the same binding, which it can change, so it's left out of that read too, and the held closure keeps only those by-reference names live, read from the nested function's own facts when it runs. A variable it captures by value under the same name is another binding, so it's never read for the held closure. A pre-pass collects each statement's own nodes and `$lastWritten` once, and the walk reuses them.
- **Reference bindings.** `$a =& $b` (or a side rooted at a variable, such as `$items[0]` or `$holder->x`) makes both root variable names unknown from the end of that statement: their facts are cleared, and the environment's `referenced` set keeps `setFact()` from giving them new ones. The statement's own escapes are checked first, so `$this->items =& $items` is still a store. Every binding adds both sides, which covers aliases of aliases without reference groups. `collectWrites()` also counts both roots, and a source `$this->property`, as written, so owner identity, external holders and the delayed nested-function pass drop them like any other write. By-value snapshots taken earlier stay. By-reference destructuring and `foreach` values tie their target and source roots the same way (`getReferenceSides()`). By-reference arguments aren't modeled.
- **Detachment.** `unset()`, `static`, `global`, or binding the variable by reference (`=&`, a by-reference `foreach` value or destructuring item) points a name at another binding (`CoroutineRuleHelper::getDetachedNames()`, plus `global`). A held closure reading the name by reference keeps the old binding, which the walk can no longer follow by name, so the name leaves its live set.
  - **A statement that is itself the detachment** (a top-level `unset`, `static`, `global` or `$v =& …;`) has no escapes of its own, so it applies before the statement's writes, and the old binding keeps what it holds then: its value and the fixed kinds of the closures it holds join the held closure's fixed kind, and those closures' live sets join its own. A detached name reached through those closures is followed the same way, each name once, so closures sharing each other's bindings end. A top-level unset leaves the name undefined and unreferenced.
  - **A detachment inside another statement** applies where the walk of the statement's own nodes reaches it, so the statement's earlier escapes (a `return` or spawn before `finally { unset(...) }`) keep their facts. It may not run, or may follow a write to the binding, so it only removes the name from live sets. A path follows the statement's writes and nested detachments in node order from the environment before the statement (syntactic order, not a proven execution path); a detaching node's own write, such as `=&`, applies after its detachment. A nested `unset()` clears its names on that path and rebuilds only the unset names and the later writes that read them, directly or through each other (`getReadNames()`, which counts a nested function by what it captures). The later writes are evaluated on the path, so a rebuilt value reads its other inputs as they stand at that point. In a statement whose writes repeat, they're also evaluated with the statement's facts for the names not rebuilt, which an earlier iteration may leave, and the two are merged. Every other name keeps the statement's facts, and only the rebuilt names are copied back: a value copied from them earlier keeps it, a write the unset discards doesn't return, a copy made after the unset doesn't keep the old value, an earlier nested unset stays applied, and a closure copied afterwards doesn't regain a detached live name. After the statement, since the unset may not have run, each rebuilt name keeps only the facts it had both before and after the rebuild, as the merge of a conditional write would.
  - Limits: node order isn't execution order, so an escape in a later branch than the unset (`if (…) { unset($x); } else { return $x; }`) is missed; a closure the same statement creates after the detaching node also loses that name's live read, since a loop can run the detachment after it; a write in a `for` loop's step expression, which comes before the body in node order, isn't later than an unset in the body, so it keeps the statement's facts; and an append before a conditional unset in a loop is dropped after the loop.
  - `unset()` isn't a write in `collectWrites()`: it doesn't change the binding a nested function captured by reference, and a later write to the name already drops that capture's facts. Other writes in a compound statement still apply before its escapes (the conservative model above), so `try { return $client; } finally { $client = null; }` is a coverage limit.
- A nested function that isn't reported at a spawn is analyzed after the walk with the facts at its creation, without invocation tracking. Each closure passed to a spawn gets its own kind; only one with a kind is reported there and skipped afterwards. A spawned closure without one is analyzed like the others, except that it doesn't receive the `DB` facade owner: `Connection` is `NonCopyableContext`, so a child coroutine resolves its own default connection, and a same-coroutine `defer()` runs after the pin. A captured variable or `$this->property` owner keeps its evidence, since the child holds that same object. A by-reference capture that the enclosing function writes in the creating statement or a later one holds whatever it was last given when the function runs, so its facts are dropped; one written only before creation keeps them. Owner and external-holder invalidation stay order-insensitive: dropping on any write is the conservative direction.

**Reports** when a tracked value is:
- returned from the callback (including an arrow function's body), directly, as an item of an array literal, or captured by a returned closure or arrow function;
- assigned to a `$this`/static property (including array dimensions and nested properties), to a property of an object from outside the callback (a variable it receives through `use` or arrow-function capture, which a nested closure inherits only when it captures that same variable), or to the callback's by-reference `use` variable, including through a nested closure's by-reference `use` of it (arrow functions capture by value, so they can't write it). Global variables count the same way: a `$GLOBALS[...]` destination, a name bound by a top-level `global` statement from that statement on (one under a condition may not run, so it binds nothing), a property of an object a bound name holds, and a nested closure's by-reference `use` of a name bound when the closure is created. `CoroutineRuleHelper::getDetachedNames()` (`unset()`, `static` or a by-reference assignment to the variable) detaches a name from both the global and the callback's by-reference destinations, a `global` statement detaches it from the by-reference one before binding it, and a nested closure keeps the bindings it captured when it was created. The node walk detaches a name before checking the node's own store, so binding a plain variable by reference only points it at another value and is never a store. A top-level reference assignment between a plain variable and global or by-reference storage (`CoroutineRuleHelper::getStorageAlias()`, read before the left name is detached) binds the variable to that storage from then on, as `global` does; compound forms and property destinations aren't followed. A destructuring assignment stores each leaf without a key the item at its position of a keyless array literal; any other leaf's value is unknown. A nested function capturing a global or by-reference name by value holds the object the name held when the function was created, so a property written through it is a store, while assigning the name only changes its copy. Each closure object has its own `static` variables, so a callback's `static` variable doesn't outlive it. A closure or arrow function capturing a tracked value, or an array holding one, counts as the value, so storing it is reported too. A static nested closure has no `$this`;
- captured by a closure passed to a spawn that can outlive the callback. A closure counts when passed directly, in an array literal, or through a local variable or array assigned it (`$tasks[] = fn () => …; parallel($tasks)`):
  - `go()`, `co()`, `Coroutine::create()`/`fork()`/`createOwned()`/`forkOwned()`/`defer()`, the Engine and Swoole `create()`/`defer()`, Swoole's `go()`/`defer()` functions, the `defer()` helpers, `Concurrent`/`WaitConcurrent` `create()`/`fork()`, and coordinator `Timer::after()`/`tick()`/`until()`;
  - `parallel()`, `Parallel::add()`, and `run()`/`defer()` on the `Concurrency` facade, the `Driver` contract, `CoroutineDriver` and `ProcessDriver`, plus `SyncDriver::defer()`. When the owner is canceled, `Parallel` cancels its children synchronously but doesn't join them, so a child whose cleanup yields can still be running after the callback returns. The `Concurrency` coroutine driver uses `Parallel`, and the driver is configuration, so every facade `run()` counts;
  - `wait()`/`Waiter::wait()` unless `waitForChildTermination` is a constant true expression. By default a timed-out or canceled wait stops waiting after the cleanup allowance while the child runs on. An expression with variables is unknown, since the lending call's scope doesn't know the callback's own variables.
- Spawning methods match by exact declaring class: an override, such as a `Parallel` subclass's own `add()`, runs its own body.
- An instance spawn's receiver class comes from a local assigned only `new X`, or from the lending call's scope for an expression using only `$this` and variables the callback receives unchanged.

**Not reported:**
- local holders used inside the callback, including one a nested closure captures;
- a nested function's parameter shadowing a tracked name, an anonymous class body, and a named function declared in the callback;
- `wait()`/`Waiter::wait()` with a constant true `waitForChildTermination`, which waits for the child to terminate on every exit, including the owner's cancellation;
- results, proxies and streams.

**Tests:** each source and escape kind, including `parallel()` directly and through `$tasks[]`, `Parallel::add()` on a local, a property and an inheriting subclass, `Timer::after()`, `Concurrent::create()`, `Storage::disk('s3')`, and a nested closure's spawn; the non-escapes, with `wait(..., waitForChildTermination: true)` and a true class constant against the default `wait()` and a callback-local variable, `SyncDriver::run()`, a `Parallel` subclass overriding `add()`, a reassigned alias and owner, a nested closure's return, and a same-named user method; a facade subclass's and a connection subclass's own `getPdo()` (silent). Escapes through values: a returned closure and array, a closure stored on a property, a value stored on a received holder directly, from a nested closure and from an arrow function, an array of closures passed to `parallel()` (reported), with a local holder and a nested closure capturing it, a shadowing parameter and an anonymous class (silent). Property owners: a pinned `$this->database` returning or storing its PDO (reported), another property, a reassigned owner, an owner replaced as a `foreach` target and a hooked property (silent). Order and arrays: an appended array stored on a property, a task reassigned only under a condition before `go()`, a task spawned after the connection it captured is reassigned, a by-reference capture spawned, a nested closure writing the callback's by-reference variable, and a called nested closure storing a raw handle it captured by reference after the handle was assigned (reported); an array reset after a local use and then returned, a task used locally and then replaced before `go()`, a by-reference capture whose variable is reassigned before the spawn or before the stored closure is returned, and a called nested closure storing a by-reference connection reassigned after its creation (silent). Captured callables: a task capturing a by-reference closure whose variable takes the connection after the task is created, with the local holding that closure then replaced (reported), and the reverse, a connection replaced after the task is created (silent). References: `$alias =& $connection; $connection = $other; return $alias;`, an alias of an alias, a called nested closure storing a by-reference connection replaced through an alias after its creation, and a pinned variable owner and `$this->database` owner replaced through an alias before `getPdo()` (silent); `$this->items =& $items` with `$items` holding the connection (reported). Nested carry: a task wrapping a by-reference closure, spawned inside an `array_map()` callback and inside a called `$launch` closure, and a `$launch` sharing the task's by-reference client that assigns it the raw handle before `go($task)` (reported); the client assigned to the referenced variable only after the wrapper is created, a `$launch` sharing that reference that clears it before `go($task)`, and a `$launch` sharing only the task's `&$attempts` and checking its by-value copy of the client before `go($task)`, after the outer client is cleared (silent). Spawned closures with no borrowed capture: a child storing `getPdo()` of a captured connection variable or of `$this->database` (reported), a child storing `DB::getPdo()` (silent), and a local nested closure storing `DB::getPdo()` (reported). Globals (`GlobalStores`): a store after `global`, a `$GLOBALS` store, a property of a global object and a nested closure's by-reference `use` of a bound name (reported); a store before the `global` statement, after `unset()`, under a conditional `global`, to a `static` variable, after a by-reference rebinding or a later `static`, in a nested closure without the capture, and a by-reference rebinding of a by-reference `use` variable (silent); a closure and an arrow function capturing a bound global object by value and writing its property (reported), and a local holder captured beside a bound global and a guarded named function returning its own parameter and writing its own variable of a bound name (silent). By-reference destinations: a store after `$out =& $local` or `unset($out)` (silent), and a nested closure that captured `&$out` before an `unset($out)`, and one capturing by value the object `$out` was given (reported). Detachment: a task sharing a client by reference, spawned after the client's name is unset or bound by reference to another variable, a task sharing a closure that shares the client, spawned after the closure's name is unset, and a top-level unset of a referenced name followed by a conditional assignment of the client that is returned (reported); a task sharing a null client, spawned after the name is unset and given the client, the same after `global` rebinds the name (the global store is reported), the closure-sharing task after the client is cleared, a client cleared and unset under a condition and then cleared, the conditional assignment after an unset under a condition, and an appended array unset and rebuilt before it's returned (silent). Ordering inside a statement: a return and a spawn before `finally { unset($client); }`, a by-value copy returned after its source is unset, an append and a plain assignment of the client after an unset in the same branch, a loop body that clears and then appends the client, and a task sharing a self-capturing closure that shares the connection, spawned after `unset($inner, $connection)` (reported); a later read of a name the branch unset and reassigned, an append removed by an unset in the same branch, a loop body that appends and then clears, a task sharing a client that a conditional unset detaches before the client is assigned, a by-reference `use` variable rebound by a by-reference `foreach` before it's assigned, an appended array cleared by an unset and then copied, the same with a second unset before a later copy, a task sharing a client by reference, copied and stored after the client is unset and given the connection, a client rebound by reference to a null before an unrelated unset, then copied and returned, an appended array cleared and copied inside a loop, and consecutive unsets inside a `while` (silent); a copy of an appended array after an unrelated unset, a loop-carried copy of an appended array around an unrelated unset, a closure rebuilt after an unrelated unset that captured the client before it's cleared, the same inside a loop through an array, a rebuilt loop closure capturing an array an earlier iteration appended the client to, and a loop closure capturing an array appended to after it (reported); a branch copying an array before the client is appended to it, beside an unrelated unset and a closure and an arrow function holding their own assignments, and a nested assignment clearing the client before the same value reads it (silent). Reference sources: an array whose element a by-reference destructuring item or `foreach` value clears, returned afterwards or stored by a nested closure capturing it by reference (silent), and a copy taken before the `foreach` (reported). Store forms (`StoreForms`): `??=` onto a property, a `$GLOBALS` entry and a by-reference variable, a value through a ternary, `?:`, `??` and `match`, closures through a ternary passed to `go()` directly and through a variable, a positional destructuring store, a `??=` result stored on a property, a possibly borrowed target kept through `??=` and returned, and a by-reference, global, reverse, chained and earlier-captured alias (reported); a positional destructuring storing only a string, a keyed destructuring of a literal holding the client beside a string, a re-pointed bound name, an unset alias, a closure capturing the alias before it's bound, and a reference destructuring item re-pointing a bound name (silent).

### 3.4 Managed transaction ended early

**Applies** inside a closure passed to the framework's `transaction()`. Both this rule and `JobDispatchedInTransactionUsesAfterCommitRule` recognize it the same way:
- a new `Concerns/InspectsConnections` trait holds:
  - `getTransactionReceiverType()` (replacing the queue rule's `isTransactionCall()`), which gives the connection or `DB` facade type when its `transaction()` resolves to `ConnectionInterface`, `Connection` (the `ManagesTransactions` trait) or the `DB` facade;
  - `runsConnectionMethod()`, the same declaration check for any method. The framework declarations are `ConnectionInterface`, `Connection`, `DatabaseManager` and `DB`: PHPStan's facade stub resolves `DB::connection()` through its `@mixin DatabaseManager`. An override, including a `DB` facade subclass's own static method, is unknown;
  - `isFacadeConnectionCall()`, a `DB::connection()` call running the framework's `connection()`, and `getConnectionName()`, the key of a literal `DB::connection('x')` call. The queue rule's `getTransactionConnection()` uses `isFacadeConnectionCall()` too, and `BorrowedResourceEscapeRule` uses `runsConnectionMethod()` for its PDO getters;
- move the `isFacade()` it calls from `InspectsQueuedJobs` to a public static `ReflectionHelper::isFacade()`, since it's a pure name check; its callers in `InspectsQueuedJobs` and the queue rule call it there.

**Owner identity.** A transaction-control call (`beginTransaction()`, `commit()`, `rollBack()`) belongs to the managed transaction when its receiver has the same identity as the `transaction()` call's. It counts only through the framework declarations, resolved on the transaction's receiver type for the callback's owner variables; an overriding control method makes the rest uncertain. Identity:
- the closure's first parameter, which `ManagesTransactions::transaction()` passes as the connection itself (`$callback($this)`), matches any form;
- a captured variable matches the same variable when the closure doesn't reassign it before the call;
- a `$this->property` (the same `getPropertyKey()` property as section 3.3) matches itself when the closure has `$this` and doesn't write the property before the call;
- `DB::` static calls match `DB::` static calls (both the default connection);
- `DB::connection('x')` matches the same literal name.

Anything else is unknown, and unknown owners never match each other. A call on an unknown or different-form owner that may be the same connection (`DB::connection('mysql')->commit()` inside `DB::transaction()`) makes the rest uncertain rather than resolving configured names. A receiver whose classes are all known and none is a `ConnectionInterface` isn't a control call; PHPStan's "maybe" for a non-final class isn't enough to make it uncertain. A variable's or property's identity ends at the first top-level statement that writes it: an assignment, a compound assignment or a `foreach` target, walked through destructuring to its leaves (`InspectsConnections::getTargetLeaves()`), each a variable name or a `$this->property` key. Section 3.3's owner invalidation uses the same walk.

**Walk.** The rule (a per-node rule on the `transaction()` call; the walk reads the callback literal) walks the closure's top-level statements in order (an arrow function's body is one returned statement), with a depth counter starting at 0:
- `beginTransaction()` adds one; `commit()`, `rollBack()` and `rollBack(null)` remove one.
- A nested function-like is skipped without clearing the facts.
- An `if`, loop, `switch` or `try` containing a control call, a control call under a conditional expression (`match`, `?:`, `&&`, `||`, `??`), an unpacked argument, or a `rollBack()` level other than a constant `null` or `0` makes the rest uncertain, so it goes silent. Branches without control calls don't. The rule doesn't model what `rollBack(N)` does, since the entry level is unknown.
- It stops after a top-level `return` or `throw`.

**Reports:**
- `commit()`, `rollBack()` or `rollBack(null)` at depth 0;
- `rollBack(0)` (positional or `toLevel: 0`) anywhere before the walk goes silent.

**Tests:** balanced nested begin/commit; the closure parameter, a captured connection, a reassigned capture (silent), a capture and a parameter reassigned through destructuring (silent), a `$this->connection` owner in a closure and an arrow function (reported), a reassigned property, a property reassigned through destructuring and a get-hooked property (silent), the `DB` facade and a `DB::connection('mysql')` pair; a non-connection `commit()` before a reported one; a branch and a ternary going silent; a statement after `return` (silent); `rollBack(0)` and `rollBack(toLevel: 0)`; `rollBack(2)` (silent); another connection (silent); a nested `afterCommit()` closure (silent); an inherited `PdoConnection` (reported), and a connection overriding `transaction()`, one overriding `commit()` and a `DB` facade subclass with its own `transaction()` or `connection()` (silent).

### 3.5 Native request APIs

**Reports**, everywhere in analyzed code:
- calls to `header`, `header_remove`, `headers_sent`, `headers_list`, `http_response_code`, `setcookie`, `setrawcookie`, `session_*` except `session_create_id` (it only generates an ID; the session handlers use it), `connection_aborted`, `connection_status` and `ignore_user_abort`;
- calls to `getallheaders` and `apache_request_headers`;
- `filter_input`, `filter_input_array` and `filter_has_var` when their input type (`type`, or `input_type` for `filter_has_var`, bound by name or position) is `INPUT_GET`, `INPUT_POST` or `INPUT_COOKIE`. They read the original SAPI input. A selector that isn't constant, or is unpacked, is skipped, and `INPUT_SERVER`/`INPUT_ENV` read the process like `$_SERVER`/`$_ENV`;
- reads or writes of `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_SESSION`.

Native function resolution applies throughout: an application function of the same name is silent.

**Message:** these PHP APIs don't operate on Hypervel's request, response or session state. The tip gives the replacement:
- the `Request` methods, including `header()` for the header functions; for the filter functions, reading the value from the `Request` and validating or filtering that;
- response `header()`/`cookie()`/`setStatusCode()`;
- the `Session` store;
- streamed-response disconnect handling: a generator callback or `IterableStreamedResponse` stops at the first failed write after the client disconnects, and `cancelOnDisconnect()` (opt-in) also stops it during a wait when the connection closes;
- for `$_SESSION`, that it's shared by the worker process.

`$_SERVER`/`$_ENV` aren't covered. Intentional interoperability takes an ignore with a reason.

**Framework hits** (checked):
- `LoadEnvironmentVariables::writeErrorAndDie()` calls `http_response_code(500)`, an FPM leftover from Laravel. It does nothing in Hypervel, which bootstraps from the CLI before any response exists, so remove the line.
- Horizon `WorkCommand::handle()` calls `ignore_user_abort(true)` for `fast_termination`, as upstream does. In the CLI it stops the worker dying on its next output after its terminal goes away (it doesn't affect signals), so it takes an ignore with that reason.

**Porting guide:** the "Streamed Responses" section becomes "Native Request Functions" (`#native-request-functions`), one concise paragraph covering the native header, cookie, status, session, superglobal and connection functions. It keeps the streaming replacement sentence and link, and links `static-analysis#native-request-apis`, which section 4 creates.

### 3.6 Discarded dates

A per-node rule on `Stmt\Expression`. It **reports** a statement-level method call (result unused, including nullsafe calls and the last call of a chain) on a date receiver, when all of these hold:
- for every class the receiver may be, Carbon declares the method natively (declaring class `CarbonInterface`, `CarbonImmutable`, `Carbon` or a Hypervel subclass; their traits count) or by an `@method` tag on one of those classes. A native override is judged by its own declaring class. Carbon's `__call()` runs its tagged methods before any macro, so a tagged name is never a macro, and a name only a macro or a user's tag declares is skipped;
- its name is on the verified date-value modifier list (matched case-insensitively, as PHP calls native methods):
  - prefixes `add*`, `sub*`, `startOf*`, `endOf*`, `next*`, `previous*`, `nth*`, `firstOf*`, `lastOf*`, `round*`, `floor*`, `ceil*`;
  - exact names `modify`, `change`, `set`, `setUnit`, `setUnitNoOverflow`, `setDate`, `setTime`, `setDateTime`, `setTimestamp`, `timestamp`, `setTimezone`, `timezone`, `setISODate`, `setDateFrom`, `setTimeFrom`, `setDateTimeFrom`, `setTimeFromTimeString`, `setDaysFromStartOfWeek`, `setAnchorDay`, `utc`, `shiftTimezone`, `midDay`, `average`, `plus`, `minus` (Carbon's tags and `DateHelpers`), `rawAdd`, `rawSub`;
  - the unit setters of Carbon's `$units` (`year`, `month`, `day`, `hour`, `minute`, `second`, `milli`, `millisecond`, `micro`, `microsecond`), each in its plain, plural, `set` and `set`-plural form (e.g. `years`, `setYear`, `setYears`). They always set: `setUnit()` has no getter form.

**Getter/setters** act as getters when their first argument is omitted or null: `dayOfYear`, `weekday`, `isoWeekday`, `week`, `isoWeek`, `weekYear`, `isoWeekYear`, `tz`, `utcOffset`, and Carbon's 77 tagged unit-of-unit methods (`dayOfWeek`, `weekOfYear`, …), matched by `/^[a-z]+Of[A-Z][a-z]+$/` after the modifier prefixes, case-sensitively as Carbon dispatches them. Reported only when the value argument is supplied and isn't definitely null; a possibly-null or unpacked value gets the may-message. The value binds by the native parameter name when the method is native: PHPStan merges the `Week` trait's `@method static dayOfYear(int $dayOfYear)` tag into the native `dayOfYear(?int $value)`, but a named argument binds to `$value`.

Option setters (`locale`, `settings`, `setLocalTranslator`) never match, because they mutate and return `$this`. No Carbon method is marked pure.

**Receivers:** every class is a `DateTimeImmutable` → definite; every class is a `DateTime` (mutable `Carbon`) → silent; otherwise (`CarbonInterface`, a union) → may.

**Messages** (tip: "Immutable date methods return a new date, so assign the result where it's needed."):
- definite: "The result of addDay() is discarded, so the immutable date is unchanged."
- may: "The result of addDay() is discarded, so any change it makes to an immutable date is lost."

Native `DateTimeImmutable` methods are skipped, since PHPStan reports those.

**Tests:** each family on `CarbonImmutable`, plus the Hypervel subclass's tags and `plus()`, a nullsafe call and a chain; `CarbonInterface` and a mutable/immutable union (may); the getter/setter cases (positional, named, possibly null, unpacked, omitted, null, a tagged unit-of-unit method); option setters, a used result and first-class callables (silent); mutable `Carbon` and native `DateTimeImmutable::modify()` (silent); a native override and a macro documented by a user `@method` tag (silent).

### 3.7 Mutable date creation

A per-node rule on `CallLike`. **Reports:**
- `new Carbon\Carbon`/`new Hypervel\Support\Carbon`;
- a static call on exactly those classes, named (not `self`/`static`/`parent`, not a subclass), whose resolved result apart from null and false is a mutable `Carbon` (`now()`, `parse()`, `create*()`, `instance()`, `make()`, …). A void method such as `setTestNow()` leaves no class and is silent, as is `getTestNow()` (`Closure|CarbonInterface|null`).

**Message:** "Hypervel\Support\Carbon::now() creates a mutable date." (or "new Carbon\Carbon …"). **Tip:** "Create dates with the Date facade, the now() and today() helpers, or CarbonImmutable." (AGENTS "Use immutable dates by default"). Intentional mutable interop or conversion takes an ignore.

**Framework hits** (checked):
- `HasAttributes::serializeDate()`'s `Carbon::instance()` takes an inline ignore. The branch isn't dead: `instance()` clones a date that's already a `Hypervel\Support\Carbon`, so a mutable subclass's `toJSON()` applies, which `CarbonImmutable::instance()` would lose.
- The `types/Database/Eloquent/Casts/Attribute.php` fixture uses `new CarbonImmutable` for its narrower getter.

**Tests:** `new` and static calls on both classes, a finder returning null (`make()`, `createFromFormat()`); `hasTestNow()`, `getTestNow()` and `setTestNow()`, a subclass and its `static::` call, `CarbonImmutable::now()`, `Date::now()`, `now()` and a first-class callable (silent). `IntegrationTest`'s expectations gain the reports for the ported fixtures' deliberate `Carbon::now()` calls (`bug-1744`, `model-properties`).

### 3.8 Uncast env values

A rule on PHPStan's `LiteralArrayNode`, using each item's scope. **Reports**, in config files, an `env()`/`Env::get()` read (including an `Env` subclass that inherits `get()`; one overriding it is unknown) whose default is definitely a bool, int or float and whose value reaches an array item's value unchanged: as the item value itself, through an assignment's value, `?:`, `??` and ternary branches (not the condition), or as another read's default (`env('A', env('B', 60))` reports the inner read). A read in a condition or cast is converted, so `(int)` and the `($x = env(...)) === null ? null : (int) $x` form are silent. Values passed through other calls (`filter_var()`) or held in variables aren't followed. Checking the item's resulting type instead would misreport `env('REDIS_TLS', false) ? 'tls' : 'tcp'`, whose string result is intended. The config-file check runs only once a read is found.

**Config files:** `NoEnvCallsOutsideOfConfigRule`'s detection, including its editor-mode file mapping, moves to `ConfigSourceResolver::isConfigFile()` (beside `isPackageConfigFile()`), which both rules call. The resolver takes the autowired `singleReflectionFile`/`singleReflectionInsteadOfFile` parameters; the env rule's test builds a resolver with them.

**Message:** "env('DB_PORT') can return a string, but its default is an int." **Tip:** "Cast it in the config file to its default's type, such as (int) env('DB_PORT', 3306). When the variable holds a name or other text, use a string default instead."

**Framework hits** (checked):
- `logging.php` `syslog.facility` (framework config and both skeletons): Monolog maps only facility names from strings and rejects numeric strings, so the variable always holds a name. The default becomes `'user'`, which Monolog maps to `LOG_USER`; `LogManager`'s `?? LOG_USER` fallback stays. It's a runtime change, so it gets its own commit. Skeleton configs carry no ignores (the sync tool strips `class.notFound` ones), since an app without this check would report an unmatched ignore.
- `src/sentry/config/sentry.php` `spotlight` (true, false or a URL, read at `SentryConfig::configHasSpotlightEnabled()`) takes an inline ignore with that reason.

**Tests:** in a config directory: `env()` and `Env::get()` with int, bool and float defaults, `?:`, `??` and ternary branches, an assignment, a list item, a nested default, a typed non-constant default and Testbench's inheriting `Env` (reported); a cast, `filter_var()`, a ternary condition, the null-check form, a variable, a string default, no default and an `Env` subclass overriding `get()` (silent). The same read outside a config directory is silent. `NoEnvCallsOutsideOfConfigRule` applies the same exact-declaration check to its `Env` readers, with the overriding subclass in its readers fixture.

### 3.9 Typed config getters

Application mode only (`! RuntimeInputs::isPackageApplication()`, after `RuntimeInputs::track()`): an application using a package can give the keys it reads other values. The components analysis and the analyzer tests boot a package application, so the rule is silent there; the skeleton analysis runs it.

**Reports** `config('key')` (the resolved `config` function), or `Config::get('key')`/a repository `get('key')` as `ConfigHelper::getMethodName()` recognizes them, with a constant key (a union of constant keys counts) where `ConfigParser::getValue()` says every key is definitely present and the union of their values is definitely one of `string`, `int`, `float`, `bool`, `array`. An absent key's default doesn't count. Unpacked arguments are skipped.

**Application repository only.** The application's config values say nothing about another repository, which may hold other values. So `ConfigHelper::getMethodName()` recognizes a repository method only on:
- `config()` without arguments;
- a literal application-container resolution whose abstracts all alias to `'config'` (`WorkerStateHelper::getApplicationResolvedAbstracts()`, after `RuntimeInputs::track()`);
- the `Config` facade.

A repository received as a parameter or property, or built with `new`, gets its declared return types, and the config rules skip it. This covers the return-type extension, `ConfigTypeRule`, `ConfigCollectionRule` and this rule. Typed getters keep their declared types, and the existing override checks are unchanged. The type tests cover a parameter and a `new Repository` with values that differ from the application's config.

**Message:** "Config key 'app.name' always holds a string, so read it with string()." **Tip:** "Use config()->string(), Config::string() or the repository's string(), which return the value's type and throw if the configuration changes it." There's no check for unneeded defaults.

Keys are described with `VerbosityLevel::precise()`: `value()` truncates long keys. `ConfigTypeRule` and `ConfigCollectionRule` switch to it too.

**Tests:** a `tests/PHPStan/application/config/typed.php` fixture config. In application mode (the test flips `RuntimeInputs::$packageApplication` and restores it): each type, spread across the helper, the facade and a repository, a present key with a default, a union of keys (reported); a union value, null, an absent key with a default, typed getters and a write (silent). The same file in package mode is silent. `ResultCacheTestCase` turns the switch off, since its fixtures read config through `get()` to exercise its types.

### 3.10 `#[ObservedBy]` convention

A rule on `InClassMethodNode`. **Reports** an unconditional top-level `X::observe(...)` statement (directly in the method body, not under a condition or closure) in a service provider's `register()`/`boot()`, where:
- `X` is a named `Model` subclass whose native `observe()` is `Model`'s own (an override is skipped);
- the argument is a constant class-string or constant list of them (`Observer::class`, an array of them, a class constant); objects and runtime values are dynamic and silent;
- the application declares the model: a model whose real file path is under an installed Composer package's real directory (`InstalledVersions::getAllRawData()`, excluding the root package) is a dependency, where the application can't add an attribute. Real directories cover path repositories, whose source can be outside the vendor directory. The decision tracks `RuntimeInputs::track()`, which covers Composer's installed metadata. PHPStan's `analysedPaths` isn't used: it narrows to the edited file in editor mode, so results would differ between editor and CLI runs; Composer's metadata doesn't depend on the analysed file.

**Message:** "App\Models\Invoice registers its observers with observe() in a service provider." **Tip:** "By convention, list them in #[ObservedBy] on the model. Subclasses inherit attribute observers, so keep observe() with an ignore when they are meant for this class only." (AGENTS "Use Laravel observer conventions"; `resolveObserveAttributes()` merges ancestors' attributes.)

**Tests:** a class-string, a literal list and a class-constant list in `register()` and `boot()` (reported); an observer object, a method's result, a model overriding `observe()`, dependency models (a fixture Composer vendor directory whose `installed.php` lists a vendor package and a linked package outside it), a conditional, a closure and a non-provider class (silent).

### 3.11 Migration column types (default off)

A per-node rule on `MethodCall`. **Reports** these `Blueprint` methods, matched by resolved declaring class (a subclass override is skipped). `Blueprint` is the schema builder, so no migration-path check is needed:
- `enum()`: "enum() creates a database enum, so adding a value needs a migration." Tip: "Use a string column with a PHP enum cast."
- `set()`: "set() creates a database set, so adding a value needs a migration." Tip: "Store the values in a jsonb() column cast with AsEnumCollection, or in a related table."
- `json()`: "json() creates a json column, which PostgreSQL can't index or compare like jsonb." Tip: "Use jsonb() for JSON the database queries, indexes or validates, or text() for a value only read back whole. jsonb() uses the appropriate type for each database." (AGENTS "Use semantic column types".) The docs section says the same.

There's no `*Tz` check. Off by default because existing migrations shouldn't be edited; the neon comment says so.

**Tests** (a neon turning the switch on): each method on a closure's `Blueprint` (reported); `string()`, `jsonb()`, `text()` and a subclass's `json()` override (silent).

### 3.12 Binding cycles

A per-node rule. **Reports** `bind`/`singleton`/`scoped` on the application container, with exactly one constant string each for `abstract` and `concrete` (bound by name or position), which differ, where the booted container's `getAlias(concrete)` is the abstract (after `RuntimeInputs::track()`). `Container::getClosure()` builds directly only when abstract === concrete, and `resolve()` applies `getAlias()` first, so the binding resolves itself.

- **Application container:** the framework container's method on an application instance or `Container::getInstance()`, through `WorkerStateHelper::isApplicationContainerCall()` (extracted from `getApplicationResolvedAbstracts()`), or the `App` facade when the method's declaring class is exactly `App`. A facade subclass's own method and `(new Container)` are silent.
- `bindIf`/`singletonIf`/`scopedIf` aren't reported: they skip an abstract that's already bound, and the booted state can't show whether the call installed the binding or was skipped.

**Message:** "singleton() binds 'auth' to Hypervel\Auth\AuthManager, which is an alias of 'auth', so resolving it never ends." **Tip:** "Bind a closure that creates AuthManager with new instead of its class name." (AGENTS binding pattern 1).

**Tests:** `$this->app->singleton()`, `app()->bind()`, `App::scoped()`, `Container::getInstance()->singleton()` and an `Application` parameter's `bind()` (reported); `singletonIf()`, a closure, the same class, a concrete that isn't an alias, `(new Container)` and a facade subclass overriding `singleton()` (silent).

### 3.13 Raw coroutines

An exception escaping a raw coroutine, including a cancellation, is a fatal error that ends the worker process (probed).

**Reports**, matched by exact declaring class (an override is silent, an inheriting subclass is reported):
- `Swoole\Coroutine::create()`;
- `Swoole\Coroutine\go()`, by resolved function name, so an aliased import counts;
- `Hypervel\Engine\Coroutine::create()` and `execute()`, the instance form of the same spawn.

Swoole short names are already rejected by the application's bootstrap. `Swoole\Coroutine\run()` isn't reported: `Hypervel\Coroutine\run()` only adds hook-flag setup around it, which isn't the safety this rule is about.

**Tip:** use `Hypervel\Coroutine\Coroutine::create()`, `go()` or `co()`; `Coroutine::fork()` and the helpers' `copyContext` argument copy the parent's context. A direct call stays, with an ignore, only when its callback catches every exception itself (AGENTS "Contain cancellation in direct engine coroutines").

**Framework hits** (checked), each with an inline ignore:
- `Engine\Coroutine::create()`/`execute()` and `Coroutine\Coroutine::createWithContext()`: the primitive and the wrapper;
- `Watcher\Watcher` and `Grpc\Client\Connection::startReceiver()`: their callbacks contain cancellation and failures. The gRPC receiver is the terminal owner of its coroutine: cancellation ends it, and any other escape, including a native close failure carrying cancellation (`preferCancellation()`), is reported through `FailureReporter::report()`, since an uncaught exception in a raw coroutine stops the process. `terminateAfterCancellation()` reports a termination failure the same way and rethrows the caller's cancellation;
- `Engine\SafeSocket::loop()`: the engine package sits below `hypervel/coroutine`, and its send loop contains every failure, including its logger's, which it reports through `FailureReporter::report()` before closing the socket;
- `RunTestsInCoroutine`'s deadline watchdog: it runs no application code, catches its own timeout, and nothing cancels it.

**Tests:** each reported form, including an inheriting subclass; the framework helpers, `Swoole\Coroutine\run()`, an override and a first-class callable (silent).

### 3.14 Socialite properties

A per-node rule on `PropertyFetch`. It reports any `$this->X` access, read or write, in a subclass of the framework class whose getter reads X through `getContext('X', $this->X)`, covering redeclared defaults (e.g. `FacebookProvider::$scopes`). Providers are cached for the worker (`new` in `SocialiteManager`, so the worker-state checks don't see them): a read misses the request's value, and a write changes the default for every request, as Laravel's own setters (`$this->parameters = $parameters`) would if ported.

| Properties | Framework class | Getter / setter |
|---|---|---|
| `parameters`, `stateless`, `additionalConfig` | `Socialite\AbstractProvider` | `getParameters()`/`with()`, `isStateless()`/`stateless()`, `getConfig()`/`setConfig()` |
| `clientId`, `clientSecret`, `redirectUrl`, `scopes`, `usesPKCE` | `Socialite\Two\AbstractProvider` | `getClientId()`/`setConfig()`, `getClientSecret()`/`setConfig()`, `getRedirectUrl()`/`redirectUrl()`, `getScopes()` (also merges configured scopes)/`setScopes()`, `usesPKCE()`/`enablePKCE()` |
| `fields`, `popup`, `reRequest`, `version` | `Socialite\Two\FacebookProvider` | `getFields()`/`fields()`, `getPopup()`/`asPopup()`, `getReRequest()`/`reRequest()`, `getGraphVersion()`/`usingGraphVersion()` |
| `scopeKey` | `Socialite\Two\SlackProvider` | `getScopeKey()`/`setScopeKey()` |

**Not reported:** property declarations; the framework class itself, which owns the property (`withConfig()` writes `additionalConfig`); an override of that property's getter, which reads the default by design; a constructor, which runs once when the provider is built, so it can only set or read the default; and a same-named property on an unrelated provider. PHPStan records a class's parents as dependencies of its file, so the ancestry needs no tracking. There are no framework hits today.

**Message:** "$this->scopes holds the provider's default for every request, not the current request's value." **Tip:** "Read it with getScopes() and set it with setScopes(), which use the provider's coroutine context."

**Tests:** a read, an inherited base property, a write and a `FacebookProvider` property (reported); a redeclared default, a constructor write, a getter override, the non-context `scopeSeparator` and a same-named property on another provider (silent).

### 3.15 Swoole tables in providers

A per-node rule on `New_`. **Reports** `new` of `Swoole\Table` or a subclass (it isn't final) executed in a `ServiceProvider`'s `register()` or `boot()` body, including under conditions, but not inside closures or arrow functions (singleton factories, listeners), which run later. There are no framework or monorepo hits: Reverb's tables are built in a lazy singleton factory.

**Message:** "A Swoole table created in a service provider's register() is allocated by every process that boots the application." **Tip:** "Create it from a BeforeServerStart listener, so only the server allocates it before starting its workers." (AGENTS).

**Tests:** `register()` and a subclass under a condition in `boot()` (reported); a closure and an arrow-function factory, and a non-provider `register()` (silent).

### 3.16 Request-dependent `#[BindWhen]`

A rule on `InClassNode`, over the class's exact `#[BindWhen]` attributes (the container matches the name exactly). The condition (`condition`, or the second positional argument) is read as written and never executed: PHPStan analyzes attribute closures, but nothing there tells a rule it's in a condition. Names resolve through the class scope, since PHP 8.5 constant-expression closures can't capture variables. A condition closure is walked whole; PHP allows only static closures and first-class callables there, not arrow functions. Nested closures and arrow functions are walked too, since predicates often run callbacks (`array_any(..., fn ($pattern) => request()->is($pattern))`). The rule finds reads in the condition's code without proving a callback runs, and its message says the condition "contains" a read. Named functions and class bodies, including anonymous classes, are declarations and are skipped. A first-class callable naming a static method that is itself one of the reads below (`Auth::check(...)`, `RequestContext::has(...)`) is reported directly. Otherwise a first-class callable naming a function or static method (`Conditions::onApi(...)`, or `self::`/`parent::` resolved from the class scope; `static::` is late-bound, so unknown) is walked through its parsed declaration (`ConstructorParser::findMethod()`, which `findConstructor()` now uses), and its file is tracked. Its names resolve in a scope for the declaration's own file and namespace (`ScopeFactory`, `enterNamespace()`), since an unqualified function name falls back from that namespace, and for a method, inside its declaring class (`enterClass()`), so `self::` constants resolve. Each condition tracks `RuntimeInputs::track()`, since the booted aliases decide which abstracts resolve the request.

**Reports** the first read of request or user state. A facade or `RequestContext` subclass counts, but its own declaration of the method is an override, so it's unknown. A facade subclass forwards to the root only when its `__callStatic()` is `Facade`'s, `Hash`'s or `Crypt`'s (`FacadeHelper::forwardsToRoot()`, shared with `getMethod()`), so one with its own `__callStatic()` is unknown for `Request` and `Auth` reads and guard sources:
- `request()`;
- a `Request` facade call that the current request runs: not the facade's own methods (`swap()`, …) or the request's static methods (`hasMacro()`, `create()`, …);
- `RequestContext::get()`, `getOrNull()`, `has()`, `getValue()` and `getOrSetValue()`;
- `user()`/`check()`/`guest()`/`id()`/`hasUser()` on the `Auth` facade, or on `auth()` or a guard chained from it or `Auth::guard()`;
- resolving an abstract that aliases to `request` from the application container (`WorkerStateHelper::getApplicationResolvedAbstracts()`), or from the condition's first parameter, which receives the resolving container (new `getContainerResolvedAbstracts()`, sharing the argument reading). The parameter's evidence follows PHP's scoping:
  - a nested arrow function keeps it unless one of its own parameters has the same name;
  - a nested closure keeps it only through `use`, and not when one of its parameters has that name;
  - a body that writes the variable (`CoroutineRuleHelper::assigns()`, section 2.5) loses it, regardless of order. Each body is scanned on its own, so a nested closure's own variable of that name doesn't affect the outer body.

Configuration reads such as `Auth::getDefaultDriver()` are fine. A guard held in a variable isn't followed. The `Context` facade and the session aren't covered.

**Message** (at the read, or at the attribute for a first-class callable): "The #[BindWhen] condition on App\Payments contains a read of the current request or user, but a matched condition binds for the worker's lifetime." The docs say nested callbacks are inspected. **Tip:** "Conditions must depend only on state set while the application boots. Register a scoped() binding to choose an implementation per request."

**Tests:** each read form, including a named `condition` argument, the container parameter resolving `Request::class`, a static-method and a function first-class callable, `self::` and `parent::` callables, `RequestContext::has(...)` and `Auth::check(...)` named directly, and a static method resolving `self::REQUEST` (reported); an overriding `Auth` subclass's `check(...)` named directly, and an `Auth` subclass with its own `__callStatic()` (silent); `Auth::getDefaultDriver()`, resolving `config`, a boot-stable static method, `Request::hasMacro()`, overriding `RequestContext` and `Auth` subclasses (including `guard()`), and a reassigned container parameter, including through destructuring (silent). Nested callbacks: an `array_any()` callback reading `request()`, and a `use ($app)` closure resolving `request` after an earlier nested closure assigns its own `$app` (reported); an arrow function whose own `$app` parameter shadows the container, and a closure that doesn't capture `$app` (silent). A second fixture file in another namespace declares its own `request()`: its attribute naming the first namespace's `onAdminPath(...)` is reported, and the first file's attribute naming its `onLocalFlag(...)` is silent. The fixtures use PHP 8.5 static attribute closures, which PHP 8.4 can't compile, so `composer.json` excludes both files from the dev classmap by name (Composer's `*` needs at least one character): they're only parsed, never loaded.

### 3.17 Swallowed cancellation

`SwallowedCancellationRule` is a `CollectedDataNode` rule over `SwallowedCancellationCollector`'s facts (section 2.4).

**Applies** in request, job and operation contexts and their `$this` helpers, as `WorkerStateAnalysis::getContext()` gives them.

**Reports** a `try` whose block (outside nested closures) directly contains one of the operations below, when the first catch that receives `CanceledException` (a type it `is()`: that class, `Exception` or `Throwable`) doesn't propagate it. Later catches never receive it. The message names the first operation found.

- **Nested tries.** An operation in the block of a nested `try` whose receiving catch doesn't propagate it doesn't count here; that `try` is reported itself. One whose catch propagates counts, as do the nested catch and `finally` bodies.
- **Receivers.** The collector has the scope at the `try`, so a method call's receiver, and a static call's class expression, is typed by `CoroutineRuleHelper::getReceiverType()`, now public and shared with `ExactCancellationCatchRule`. A local the block may change is one it mentions other than inside the expression being typed or as a method or static call's receiver:
  - a chain rooted at a local variable that the block only calls methods on keeps the variable's type at the `try`;
  - one assignment of the variable, as a top-level statement before every call on it, types the chain with the assigned expression in the variable's place (`$query = Import::query(); $query->where(...)->get()`, `$model = Import::class; $model::create(...)`). A `??=` assignment counts the same way, typed as the assigned expression when the variable is undefined at the `try`, as the variable's own type when it's definitely defined and never null (the assigned expression never runs, so it isn't read), and otherwise as the union of its non-null type there and the assigned expression;
  - a call argument reading a local the block may change is typed as `TypeExpr(mixed)`, so a call that selects its result's class by it gives no class (`$class = X::class; $app->make($class)->pop()`, `app($class)`), while a call whose type doesn't depend on it keeps its class (`$query->where('tenant', $tenant)`, `Import::whereIn('id', $ids)`). PHPStan's own inference, including return-type extensions, decides this, not a list of factories;
  - any other read of such a local leaves the type unknown, such as an assigned expression reading one (`$held = new X; $alias = $held;`), as does any other write of the root variable. An assigned expression or type-dependent argument reading a `$this` property the block writes directly (`getWriteTargets()`) is unknown too, without following what the property was given;
  - the chain is rebuilt as new nodes, since a clone keeps PHPStan's cached expression key;
  - a `$this->property` the block writes directly (`getWriteTargets()`, the targets `assigns()` walks) follows the same rules as a local when it's a native, non-static, non-virtual property without a `get` or `set` hook, so `$this->channel = $replacement; $this->channel->pop()` is typed by `$replacement`. A hook could return or store another object, so a hooked or virtual property that the block writes is unknown. A `??=` on a property is typed as the union, since a typed property may be uninitialized;
  - without a direct write, a property, `$this` and other roots use the scope's type: calls that change a property aren't followed;
  - null is left out of the receiver's type, since a call on null fails or, through `?->`, doesn't run. PHPStan gives no class reflections for a union with null, so a nullable receiver would otherwise never count.

  A direct construction is just one such assignment. The exact-catch rule gains the same typing, so a channel assigned from a typed variable counts as a native wait.

**Operations.** Only these exact forms count, matched by resolved declaration with overrides respected. A model's or builder's forwarded method counts as the method it forwards to, read through the reflection's prototype, since PHPStan wraps extension methods. The receiver's class reflections come from its type, so they keep the type arguments a builder's model macros depend on. A trait method is declared by the class using it, so a method of a framework trait on an application model (`SoftDeletes`, `Prunable`) is matched by its declaring trait (`PhpMethodReflection::getDeclaringTrait()`) when the declaring class has no entry and isn't a Redis class (`RedisProxy` takes `pipeline()` and `transaction()` from its `MultiExec` trait); a model's own override has no declaring trait. Each runs its work during the call, can reach a cancellation point there, and lets the cancellation reach the caller (fast paths that skip I/O, fakes and pretend mode don't make swallowing safe):
- `Connection::runQueryCallback()` rethrows when `causedByCancellation()`;
- `RedisProxy` and `PendingRequest` (`:1191`, `:1358`) propagate it;
- `WaitGroup::wait()`, `Waiter::wait()` and `Parallel::wait()` throw `CanceledException` themselves;
- under Hypervel's `throwException: true` cancellation, Swoole channel `push`/`pop`, Swoole `WaitGroup::wait()`, `Coroutine::join()`, `Coroutine::sleep()` and hooked `sleep`/`usleep` throw `CanceledException` (probed);
- a discarded `Sleep` sleeps in its destructor at the end of the statement, and `then()` sleeps during the call, so a cancellation reaches the `try` (probed).
- **Database:**
  - `ConnectionInterface`, `Connection`, `PdoConnection`, `DatabaseManager` and the `DB` facade: `select`, `selectOne`, `selectFromWriteConnection`, `selectResultSets`, `scalar`, `insert`, `update`, `delete`, `statement`, `affectingStatement`, `unprepared`, `threadCount`, `transaction`, `beginTransaction`, `commit`, `rollBack`;
  - query `Builder` (with its `BuildsQueries` and `ExplainsQueries` methods), directly or through Eloquent forwarding: `get`, `first`, `firstOrFail`, `sole`, `find`, `findOr`, `value`, `rawValue`, `soleValue`, `pluck`, `pluckWithColumn`, `implode`, `count`, `min`, `max`, `sum`, `avg`, `average`, `aggregate`, `numericAggregate`, `exists`, `existsOr`, `doesntExist`, `doesntExistOr`, `getCountForPagination`, `explain`, `insert`, `insertGetId`, `insertOrIgnore`, `insertOrIgnoreReturning`, `insertUsing`, `insertOrIgnoreUsing`, `update`, `updateFrom`, `updateOrInsert`, `upsert`, `delete`, `truncate`, `increment`, `incrementEach`, `decrement`, `decrementEach`, `paginate`, `simplePaginate`, `cursorPaginate`, `chunk`, `chunkMap`, `chunkById`, `chunkByIdDesc`, `orderedChunkById`, `each`, `eachById`;
  - Eloquent `Builder`: those it declares itself, plus `firstWhere`, `firstOr`, `findSole`, `findOrFail`, `findOrNew`, `findMany`, `valueOrFail`, `getModels`, `fromQuery`, `fillAndInsert`, `fillAndInsertOrIgnore`, `fillAndInsertGetId`, `create`, `createQuietly`, `forceCreate`, `forceCreateQuietly`, `firstOrCreate`, `firstOrNew`, `updateOrCreate`, `createOrFirst`, `incrementOrCreate`, `touch`, `forceDelete`. Not `fillForInsert()`, which only prepares models;
  - `Model`, including static calls and the protected increments its `__call()` runs: `all`, `destroy`, `forceDestroy`, `save`, `saveQuietly`, `saveOrFail`, `saveOrIgnore`, `update`, `updateQuietly`, `updateOrFail`, `push`, `pushQuietly`, `delete`, `deleteQuietly`, `deleteOrFail`, `forceDelete`, `refresh`, `refreshForUpdate`, `fresh`, `touch`, `touchQuietly`, `increment`, `decrement`, `incrementEach`, `decrementEach` and their `Quietly` forms, `load`, `loadMissing`, `loadAggregate`, `loadCount`, `loadMax`, `loadMin`, `loadSum`, `loadAvg`, `loadExists`, `loadMorph` and its `Aggregate`/`Count`/`Max`/`Min`/`Sum`/`Avg` forms;
  - `SoftDeletes`: `restore`, `restoreQuietly`, `forceDelete`, `forceDeleteQuietly`, `forceDestroy`; its scope's builder macros `restore`, `restoreOrCreate`, `createOrRestore`, which `BuilderMacroResolver` resolves to `SoftDeletingMacros`; `Prunable`: `prune`, `pruneAll`; `MassPrunable`: `pruneAll`;
  - Eloquent `Collection`: `load`, `loadMissing`, `loadMissingRelationshipChain`, `loadAggregate`, `loadCount`, `loadMax`, `loadMin`, `loadSum`, `loadAvg`, `loadExists`, `loadMorph`, `loadMorphCount`, `fresh`. Not its in-memory `find()`/`findOrFail()` or `toQuery()`;
  - relations, by declaring class (calls a relation forwards to its builder match the builder's):
    - `Relation`: `get`, `getEager`, `sole`, `touch`, `rawUpdate`; `getResults()` is abstract, so each concrete declaration counts: `HasOne`, `HasMany`, `MorphOne`, `MorphMany`, `BelongsTo`, `HasOneThrough`, `HasManyThrough`, and `BelongsToMany` below;
    - `HasOneOrMany`: `findOrNew`, `firstOrNew`, `firstOrCreate`, `createOrFirst`, `restoreOrCreate`, `createOrRestore`, `updateOrCreate`, `incrementOrCreate`, `upsert`, `save`, `saveMany`, `create`, `createMany`, `forceCreate`, `forceCreateMany` and their `Quietly` forms; `MorphOneOrMany`: `forceCreate`, `upsert`; `MorphMany`: `forceCreate`; `BelongsTo` and `MorphTo`: `touch`; `MorphTo`: `getEager`;
    - `BelongsToMany`: the same find-or-create and save methods (without `upsert` and `forceCreateMany`), `getResults`, `get`, `find`, `findSole`, `findMany`, `findOrFail`, `findOr`, `firstWhere`, `first`, `firstOrFail`, `firstOr`, `paginate`, `simplePaginate`, `cursorPaginate`, `chunk`, `chunkMap`, `chunkById`, `chunkByIdDesc`, `orderedChunkById`, `each`, `eachById`, `touch`, `allRelatedIds`, and its `InteractsWithPivotTable` writes, which PHPStan declares on `BelongsToMany`: `attach`, `detach`, `sync`, `syncWithoutDetaching`, `syncWithPivotValues`, `toggle`, `updateExistingPivot` and their `OrFail` transaction forms. Not `newPivot*()` or `withPivot()`;
    - `HasOneOrManyThrough`: the find-or-create methods and `BelongsToMany`'s query methods except `getResults` and `orderedChunkById`;
    - `Pivot` and `MorphPivot`: `delete`.
  - the schema `Builder` and the `Schema` facade: `createDatabase`, `dropDatabaseIfExists`, `getSchemas`, `hasTable`, `hasView`, `getTables`, `getTableListing`, `getViews`, `getTypes`, `hasColumn`, `hasColumns`, `whenTableHasColumn`, `whenTableDoesntHaveColumn`, `whenTableHasIndex`, `whenTableDoesntHaveIndex`, `getColumnType`, `getColumnListing`, `getColumn`, `getColumns`, `getIndexes`, `getIndexListing`, `hasIndex`, `hasForeignKey`, `getForeignKeys`, `getPartitions`, `table`, `create`, `createRangePartition`, `createMigrationRepositoryTable`, `drop`, `dropIfExists`, `dropColumns`, `truncateTables`, `rename`, `enableForeignKeyConstraints`, `disableForeignKeyConstraints`, `withoutForeignKeyConstraints`, `ensureExtensionExists`, `ensureVectorExtensionExists`, `executeBlueprint`. The base builder's `dropAllTables`, `dropAllViews` and `dropAllTypes` only throw `LogicException`, so an inherited one doesn't count; the facade adds them, since it runs the configured driver's builder. Each driver builder's own overrides that run statements count too (`MySqlBuilder`: `dropAllTables`, `dropAllViews`; `PostgresBuilder`: those plus `executeBlueprint`, `hasView`, `dropAllTypes`, `getCurrentSchemaName`; `SQLiteBuilder`: `executeBlueprint`, `getTables`, `getViews`, `getColumns`, the `*ForSchemaState` getters, `dropAllTables`, `dropAllViews`, `pragma` and the foreign key toggles, but not its database file creation and removal).
- **Redis**, on `RedisProxy` or through the `Redis` facade: commands it doesn't declare natively (resolved through `RedisCommandMethodsExtension`), `command`, `withConnection`, `withPinnedConnection`, `scan`, `hScan`, `sScan`, `zScan`, and `pipeline`/`transaction` with a callback argument.
- **HTTP client**, on `PendingRequest` or through the `Http` facade: `send`, `get`, `post`, `put`, `patch`, `delete`, `head`.
- **Waits:** `Swoole\Coroutine\Channel::push`/`pop` (including `Hypervel\Engine\Channel` and the `ChannelInterface` contract, which documents both as waiting), `WaitGroup::wait` (Hypervel and Swoole), `Waiter::wait` and `wait()`, `Parallel::wait` and `parallel()`, `Concurrent::create`/`fork`/`waitForAvailableSlot` (they wait for a free slot and throw `CanceledException` when canceled) and `WaitConcurrent::create`/`fork`/`wait`, `Concurrency::run()` (the default `coroutine` driver waits through `Parallel`), `Coroutine::join` (Hypervel, Engine and Swoole), and `Coroutine::sleep` (Hypervel and Swoole). `WaitGroup::add`/`done` and channel `close` never wait, so they aren't operations.
- **Hooked functions:** `sleep`, `usleep`.
- **Sleeps:** an expression statement discarding a `Sleep` it creates (`new Sleep` or `Sleep::for()`/`until()`/`usleep()`/`sleep()`, through its fluent methods, nullsafe ones included, such as `Sleep::for(1)->second()`), where the creating method, the constructor (the factories build with `new static`), the fluent methods, `__destruct()` and `goodnight()` are all the framework's declarations; and `Sleep::then()` wherever it's called, including when its result is assigned, on a class whose constructor and `goodnight()` are the framework's. A subclass constructor can turn sleeping off through the protected `$shouldSleep`.
- **Consumed lazy queries.** A query `Builder`'s or Eloquent builder's `cursor()` or `lazy*()` (`BuildsQueries::lazy()` runs `get()` during iteration, and `cursor()` defers the connection's cursor), including through model forwarding and the relations that declare them, counts when it's the direct subject of a `foreach` in the block or a direct `each()` call (`EnumeratesValues::each()` iterates at once), including a nullsafe `?->each()`. A framework connection's `cursor()` is a generator (`PdoConnection::cursor()` runs the query inside it), so only a `foreach` consumes it. The query method is matched by resolved declaration, so a custom `cursor()` or `lazy()` doesn't count. The builders' lazy queries return a `LazyCollection`, so `each()` is matched by name on them. Creating a first-class callable (`->each(...)`) runs nothing. A lazy result held in a variable or passed through other lazy collection methods isn't followed.

Not operations:
- **Deferred forms**, whose work doesn't run inside the `try`: an unconsumed `cursor()` or `lazy*()`, `pipeline()`/`transaction()` without a callback, and a `Sleep` held in a variable, which sleeps when the variable releases it. The return type alone isn't evidence, since a method may return a `Sleep` held elsewhere.
- **Cache, filesystem, queue and mail calls.** This is a deliberate capability choice, not a correctness limit. The rule's docs say these calls aren't checked and can still deliver cancellation, so a broad catch around them needs the same handling. Application code reaches them through contracts and facades whose driver is configuration, so they stay unknown. Concrete I/O-backed classes (`RedisStore`, `RedisQueue`, …) could count, but code rarely calls them directly, so listing them would add upkeep for little coverage.
- **Pool and session internals** (`getPdo()`, `ping()`, `reconnect()`, `executeSessionStatement()`), the conditional `touchOwners()`/`touchIfTouching()`, and model factories, which are test and seeding code.

**A catch propagates when** both of these hold:
- nothing leaves its body without throwing `$e`: no `return`, no `break`/`continue` leaving more loops and switches than the body opens around it (`break N` counts N levels; nested functions and classes are skipped), and no other `throw` that escapes the catch. A loop-local `break` doesn't leave the catch, but a `continue` of a loop outside the `try` does. A `throw` of `$e` or of a wrap keeping it as the previous exception (below) propagates it; any other `throw`, such as an early `throw new RuntimeException()`, swallows it unless an enclosing `try` inside the catch body catches that throw (its class from `new`, otherwise `Throwable`, against the catch types);
- its last top-level statement is `throw $e`, or `throw new X(...)` with an argument passing `$e` that binds to the native or inherited `$previous` parameter. Every argument passing `$e` is checked, so `new Wrapper($e, 'message', $e)` counts. An overriding constructor counts only when `ConstructorParser::findConstructor()` shows a top-level `parent::__construct()` passing a bound parameter as the previous argument, with no `return` and no write to that parameter (`assigns()`) before it. The constructor is parsed and its parent call found once for all the bound parameters. This is followed up the chain;
- `$e` reaches that throw unchanged. Every write to it (`assigns()`, section 2.5, which includes a nested catch binding the same name) must be a top-level `$e = new X(..., $e)` that keeps it as the previous exception by the rule above.

Alternatively, its first top-level `Coroutine::causedByCancellation($e)` guard decides the path a cancellation takes, which must meet the same conditions: the statements before the guard, the branch it takes (a positive guard's own branch, a negated guard's `else` or nothing), and the statements after the guard unless that branch ends with a `throw`. A negated guard with `elseif`s is unknown, since their conditions run for a cancellation too. The statements before it run for a cancellation too, so they can't leave the catch or discard `$e`; a wrap keeping it as the previous exception is allowed, as in the rethrow. The exact-catch rule's `keepsCancellationApart()` still requires the guard first, since a statement before it handles the cancellation as an ordinary failure.

`ForeignCancellationException` never counts, because `causedByCancellation()` stops at it (`Coroutine.php:251`).

**No duplicate diagnostics.** Extract `ExactCancellationCatchRule`'s per-try verdict into `CoroutineRuleHelper::findExactCancellationMisses()`, which both rules call. The new rule skips only a catch that the existing rule reports for that try.

`NATIVE_WAITS` (moved to `CoroutineRuleHelper`) lists the native channel, WaitGroup, Waiter and join methods a try may call and still keep an exact catch. Its comment says each either doesn't wait or throws cancellation directly, which holds in Hypervel's throwing cancellation mode. Its behavior changes through the shared receiver typing above, and its `keepsCancellationApart()` also accepts a later `Throwable` catch that handles ordinary failures inside a negated guard without `elseif`/`else` and then rethrows `$e` unchanged as its only other statement.

**Tests:**
- a fallback `return` in a `Throwable` catch, around a query and around a channel `pop()`;
- an exact catch returning a fallback;
- a rethrow, a chained wrap and an unchained wrap;
- a caught variable overwritten before `throw $e`, including as a `foreach` value, and wrappers whose constructor overwrites the cause, including by destructuring, or forwards it only in a branch (reported); `$e = new Wrapper(..., $e); throw $e;` and a wrapper receiving `$e` as its first argument and again as `$previous` (silent);
- a query on a local builder from `Import::query()` and a pop on a locally constructed channel (reported); a typed channel replaced by a subclass overriding `pop()`, and an alias of a typed parameter that the block first replaces with that subclass (silent);
- a `where()` value, a static `whereIn()` root's value and an assigned class-string's `$model::create()` that the block sets (reported); `$app->make($class)->pop()` and an assigned `app($class)` whose class the block changes (silent);
- one try per database family (reported): `truncate()`, a model's `__call()` `increment()`, `load()`, a relation's own `get()` and a forwarded `count()`, a pivot `attach()`, a `SoftDeletes` `restore()`, the `onlyTrashed()->restore()` macro, an Eloquent collection's `load()`, `Schema::hasTable()` and `MySqlBuilder`'s own `dropAllTables()`; a model overriding `restore()`, `MySqlBuilder`'s inherited, throwing `dropAllTypes()`, a collection's `find()` and `toQuery()`, and a relation's `lazy()` (silent);
- nested tries: an inner catch swallowing a wait (inner reported, outer silent), and an inner catch rethrowing it (outer reported);
- `swallowed-cancellation-waits.php`: `WaitConcurrent::wait()`, `Concurrent::create()` and a `ChannelInterface` `pop()`, a query on a nullable connection property assigned from a `?Connection` handler parameter before the try, and the same property assigned from `DB::connection()` inside the try before a query (reported);
- `ExactCancellationCatchRule`: a channel assigned from a typed parameter is a native wait (silent); a channel property replaced in the block by a subclass overriding `pop()`, one assigned another property that the block first sets to that subclass, and a `set`-hooked property assigned a framework channel (reported); a nullable channel's `?->pop()` (silent); a negated guard reporting ordinary failures before a rethrow (silent), and a catch reporting before rethrowing (reported);
- an early `throw new RuntimeException()` before the rethrow and a cancellation guard throwing a replacement (reported); a wrap and a nested `try` catching its own throw (silent); a connection assigned with `??=` before a query (reported);
- `ForeignCancellationException`;
- a guard with an early return, and a negated guard reporting and returning before a rethrow (silent);
- a `report()` call before a positive guard that rethrows and before a negated guard followed by a rethrow (silent); a conditional `return` before the guard, and `$e` replaced with an unrelated exception before it (reported); an inner try reporting before its guard inside an outer catch returning a fallback (inner silent, outer reported); a positive guard falling through to a rethrow, with an `else` that returns, and a negated guard whose `else` reports before the rethrow (silent), and a positive guard falling through to a `return` (reported);
- a discarded `Sleep::usleep()`, a fluent `Sleep::for(1)->second()->…` and a nullsafe `Sleep::for(1)?->second()`, `then()` and an assigned `then()` result (reported); a held `Sleep`'s modifier, a subclass with a no-op `goodnight()` both discarded and through `then()`, and a subclass whose constructor turns sleeping off, created through `for()`, both discarded and through `then()` (silent);
- a loop- and switch-local `break`/`continue 2` before a rethrow (silent), and a `continue` of a loop around the `try` (reported);
- a try with only deferred forms (`cursor()`, `Redis::pipeline()`), silent;
- a `RedisProxy` `pipeline()` with a callback in a job (reported);
- consumed lazy queries: `foreach` over a model's `lazy()`, a relation's `lazy()` and a connection's `cursor()`, `each()` directly on a builder's `cursor()`, and a nullsafe `?->each()` on a model's `lazy()` (reported); a lazy query held in a variable before its `foreach`, one passed through `map()` before `each()`, and a first-class `each(...)` callable (silent);
- a `??=` on a non-null parameter whose class overrides `pop()` with no wait, assigning a framework channel before the `pop()` (silent), and on a non-null framework channel whose never-run assigned expression reads a class name the block sets (reported);
- the same try outside any context, silent;
- the existing rule's case producing exactly one diagnostic;
- the warm-cache test for a constructor forwarding `$previous` (section 2.5).

## 4. Docs

- `src/docs/static-analysis.md`:
  - one section per rule in section 3, placed with related sections (worker state, cancellation, configuration, dates, Eloquent, container), with the switch listed in the configuration reference;
  - in PR 1's Method Attributes section, add that the analysis reports calls to lifetime-marked methods from request, job and operation code (applying the conditions) and calls that reach an `#[Unsupported]` method, including its message, linking to the rule sections;
  - extend Worker State's "Request and job checks cover …" sentence with the listener, observer and server-operation contexts from section 2.3.
- `src/docs/facades.md`, `src/docs/porting-from-laravel.md` and `src/support/README.md` (sections 1.2, 1.3 and 3.5).
- Don't repeat the rule catalogue elsewhere.

## 5. Verification

Run sequentially from the components root:
1. Each changed or new test file immediately.
2. `composer analyse`: fix or ignore with reasons all new framework reports; global NEON ignores need consensus plus owner approval.
3. `composer test:phpstan`.
4. Once section 1 is done, one `composer test:parallel -- --processes=4 tests/Support` run, which holds the facade swap and mock tests the facade change can affect. Section 1.4 is PHPDoc only, so `composer analyse` covers it. The full suite is left to CI.
5. `COMPOSER_PROCESS_TIMEOUT=0 composer lint:fix`.
6. Once stable, measure cold and warm `composer analyse` time against `0.4`, after the owner confirms the machine is idle.

Monorepo consumers: no class in `packages/hypervel/src` or `apps` implements the container contract, implements or extends a connection resolver, or subclasses the `Date` facade (checked by grep). Section 1.5 changed two package tests' connection doubles to `Connection` on monorepo `main`. The monorepo's hypervel/phpstan adoption remains owner-gated.

## 6. `AGENTS.md` (agreed wording; PR 1's edits already applied)

1. In "Mark restricted worker-state mutators", insert after the attribute list:
   > PHPStan checks marked calls in recognized runtime contexts.
2. Append to "Mark intentionally unsupported methods":
   > PHPStan reports calls that reach a marked method.
3. Replace binding pattern 3, including its code block, with:
   > **3. Abstract and concrete are the same class — do not bind merely to share it.** Unbound concrete classes without a different declared lifetime are auto-singletons. Explicit registration can change lifetimes, `bound()`, optional constructor injection and rebinding; retain it when those effects are intended.
4. Replace the last sentence of the alias paragraph, "Binding an alias instead of the canonical key can orphan the binding.", with:
   > Binding an alias removes its mapping to the canonical key; the two keys can then resolve differently.

## Status

Done: sections 1 to 6, including the benchmark, Testbench runtime-copy cache fix (section 2.5), final integration review and reviewed bot corrections. Next: push the current corrections to both remotes, reply to the remaining bot comments, and complete the next CI and review round.
