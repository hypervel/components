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
- **Cloned listeners.** `CallQueuedListener::setJobInstanceIfNecessary()` clones the resolved listener when it uses `InteractsWithQueue`, then calls the handler on the clone, so its writes stay with that job. The handler method and the helpers it reaches on that receiver get their context with `shared: false` and are marked cloned; the flag travels through propagation, and the visited key includes it. Methods on the container's instance stay shared and are seeded as their own entries: `failed()` through the `JOB_METHODS` loop, the dispatcher's `shouldQueue()` (`handlerWantsToBeQueued()`) with the event's context, which may be unknown, and `CallQueuedListener::uniqueVia()`/`debounceVia()` with `job`. Other option methods (`viaQueue()`, `backoff()`, …) run on a `newInstanceWithoutConstructor()` copy in `createListenerAndJob()`, so they aren't seeded. A context entry may therefore have an unknown operation (`addContext()` takes a nullable context and keeps a known one), and the visited key includes the operation so each one propagates. `runsOnClone(class, method)` is true for a cloned method that no shared path also reaches; section 3.1's `WorkerStateRule::checkWrite()` treats such a method's instance writes as unshared for the unbounded-cache, retained-state and handler-write branches. Static writes, constructor captures and configuration writes are unchanged, and restricted calls still see promoted properties as shared, since the clone is shallow.
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
- **Tests** (`WorkerStateRuleTest`): a request-event listener, a `Class@method` listener, a queued listener (job context), an `observe()` observer, direct and repeated `#[ObservedBy]`, an attributed abstract parent with a concrete child adding a custom event, an inherited observer method, a helper the event method calls (context) and an unused helper (none). A wildcard listener matching job events (job), a `listen('*')` observer, and a `ShouldQueue` passive observer's `handle()` (request context, not job, with a promoted repository write reported through `isContainerBuilt()`). An `InteractsWithQueue` queued listener whose handler keeps request state and a request-keyed cache and calls a helper writing a property (silent), with its `failed()` write and a request-keeping helper that both `handle()` and `shouldQueue()` call (reported); the existing queued listener without the trait stays reported.
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
- A call is matched by its resolved declaration, through nullsafe calls too, with PHP's case-insensitive names. A user override or custom implementation is judged by its own declaration, so its body is unknown; config, container and facade methods are recognized by exact declaring class, not by name or ancestry. A facade subclass's own native method is such an override.
- Arguments bind to parameters by name or position (`CallLike::getArg($name, $position)`). An unpacked argument at or before the parameter's position makes the binding unknown; it's never guessed positionally.

**Cache dependencies** use the existing `RuntimeInputs` and scope tracking, never a new mechanism:
- booted state (bindings, aliases, routes, listeners, config, package mode, `bound()`): `RuntimeInputs::track()`;
- facade accessor bodies: `RuntimeInputs::trackSources($scope, $facade, Facade::class)`;
- classes whose declarations decide a result (facade roots, lifetime classes, Socialite parents): `trackClass()`/`trackClassDependency()`;
- code read beyond declarations, which PHPStan's exported nodes don't cover. Track their files with `trackFileDependency()`:
  - exception constructor chains (3.17, the files from `SourceFiles::forClass()`: constructor bodies only, so no property defaults);
  - first-class-callable `#[BindWhen]` conditions (3.16), the declaration's file;
  - declared default values (`whenNotDefault`; exported nodes only record that a default exists): `SourceFiles::forParameterDefault()`, the method's file and the files of the constants the default reads;
  - attribute arguments reading constants from other files: exported nodes keep the argument expressions, not the constants' values. `SourceFiles::forAttributeArguments()` takes an optional method name for method attributes. It covers lifetime markers (3.1), `#[Unsupported]` messages (3.2) and each model ancestor's `#[ObservedBy]` (2.3).

Warm-cache tests go in `tests/PHPStan/WorkerStateDependencyTest.php`, one per mechanism rather than per rule: a booted registration changing a lifetime, a facade accessor body, a constructor forwarding `$previous`, and a declared default value read from another class's constant. `SourceFilesTest` covers the method-attribute lookup.

**Testbench runtime copy.** A package analysis boots a Testbench copy of the skeleton that is deleted at exit. `Bootstrapper::copySkeleton()` copies or links the skeleton's `vendor`, or links the working package's when the skeleton has none.

- Tracking `PackageManifest::$vendorPath`'s metadata by the copy's path made every warm run reanalyse each `track()` consumer (about a third of components).
- `RuntimeInputs::createKey()` instead tracks two things:
  - `composer/installed.json` and `installed.php` under the skeleton's `vendor` and under the working vendor, each by its own spelling and including absence;
  - the skeleton `vendor` directory's presence, through a new directory-presence entry in `RuntimeInputsResultCacheValueExtension`. An empty one stops the link to the working vendor.
- `Bootstrapper::runtimeVendorPath()` returns the working vendor path, stored and reset beside `runtimeSourcePath()`. `runtimeCopy()` resolves it with the other ownership fields.
- Only the copy's standard `/vendor` is replaced. Any other manifest vendor keeps the `sourcePath()` handling.
- `RuntimeInputsPackageDependencyTest` covers an unchanged rerun, a repointed working-vendor link whose old target remains, and an empty skeleton `vendor` added and removed. It also covers skeleton metadata appearing after the working vendor was used.

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
  - An offset write on a repository-typed property is a configuration write, as its `set()` is, so `WorkerStateWriteCollector` skips it and one write gets one diagnostic.
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
- promoted repository property writes: `set()` and an offset write, one config diagnostic each, and a contextual repository (silent);
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

**Tracked values:** the lent parameter, the raw handles taken from it, and local variables assigned only tracked values (order-independent; a variable with any other write isn't tracked). Nested non-spawn closures are walked too, receiving tracked variables through `use` or arrow-function capture; their returns stay inside the callback.

**Reports** when a tracked value is:
- returned from the callback (including an arrow function's body), directly, as an item of an array literal, or captured by a returned closure or arrow function;
- assigned to a `$this`/static property (including array dimensions and nested properties), to a property of an object from outside the callback (a variable it receives through `use` or arrow-function capture, which a nested closure inherits only when it captures that same variable), or to the callback's by-reference `use` variable. A closure or arrow function capturing a tracked value, or an array literal holding one, counts as the value, so storing it is reported too. A static nested closure has no `$this`;
- captured by a closure passed to a spawn that can outlive the callback. A closure counts when passed directly, in an array literal, or through a local variable or array assigned it (`$tasks[] = fn () => …; parallel($tasks)`):
  - `go()`, `co()`, `Coroutine::create()`/`fork()`/`createOwned()`/`forkOwned()`/`defer()`, the Engine and Swoole `create()`/`defer()`, Swoole's `go()`/`defer()` functions, the `defer()` helpers, `Concurrent`/`WaitConcurrent` `create()`/`fork()`, and coordinator `Timer::after()`/`tick()`/`until()`;
  - `parallel()`, `Parallel::add()`, and `run()`/`defer()` on the `Concurrency` facade, the `Driver` contract, `CoroutineDriver` and `ProcessDriver`, plus `SyncDriver::defer()`. When the owner is canceled, `Parallel` cancels its children synchronously but doesn't join them, so a child whose cleanup yields can still be running after the callback returns. The `Concurrency` coroutine driver uses `Parallel`, and the driver is configuration, so every facade `run()` counts;
  - `wait()`/`Waiter::wait()` unless `waitForChildTermination` is a constant true expression. By default a timed-out or canceled wait stops waiting after the cleanup allowance while the child runs on. An expression with variables is unknown, since the lending call's scope doesn't know the callback's own variables.
- Spawning methods match by exact declaring class: an override, such as a `Parallel` subclass's own `add()`, runs its own body.
- An instance spawn's receiver class comes from a local assigned only `new X`, or from the lending call's scope for an expression using only `$this` and variables the callback receives unchanged.

**Not reported:**
- local holders used inside the callback, including one a nested closure captures;
- a nested function's parameter shadowing a tracked name, and an anonymous class body;
- `wait()`/`Waiter::wait()` with a constant true `waitForChildTermination`, which waits for the child to terminate on every exit, including the owner's cancellation;
- results, proxies and streams.

**Tests:** each source and escape kind, including `parallel()` directly and through `$tasks[]`, `Parallel::add()` on a local, a property and an inheriting subclass, `Timer::after()`, `Concurrent::create()`, `Storage::disk('s3')`, and a nested closure's spawn; the non-escapes, with `wait(..., waitForChildTermination: true)` and a true class constant against the default `wait()` and a callback-local variable, `SyncDriver::run()`, a `Parallel` subclass overriding `add()`, a reassigned alias and owner, a nested closure's return, and a same-named user method; a facade subclass's and a connection subclass's own `getPdo()` (silent). Escapes through values: a returned closure and array, a closure stored on a property, a value stored on a received holder directly, from a nested closure and from an arrow function, an array of closures passed to `parallel()` (reported), with a local holder and a nested closure capturing it, a shadowing parameter and an anonymous class (silent). Property owners: a pinned `$this->database` returning or storing its PDO (reported), another property, a reassigned owner, an owner replaced as a `foreach` target and a hooked property (silent).

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
- reads or writes of `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_SESSION`.

**Message:** these PHP APIs don't operate on Hypervel's request, response or session state. The tip gives the replacement:
- the `Request` methods;
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
- `json()`: "json() creates a json column, which PostgreSQL can't index or compare like jsonb." Tip: "Use jsonb() for JSON the database queries, indexes or validates, or text() for a value only read back whole." (AGENTS "Use semantic column types".)

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

A rule on `InClassNode`, over the class's exact `#[BindWhen]` attributes (the container matches the name exactly). The condition (`condition`, or the second positional argument) is read as written and never executed: PHPStan analyzes attribute closures, but nothing there tells a rule it's in a condition. Names resolve through the class scope, since PHP 8.5 constant-expression closures can't capture variables. A closure or arrow function is walked whole. Nested closures and arrow functions are walked too, since predicates often run callbacks (`array_any(..., fn ($pattern) => request()->is($pattern))`). The rule finds reads in the condition's code without proving a callback runs, and its message says the condition "contains" a read. Named functions and class bodies, including anonymous classes, are declarations and are skipped. A first-class callable naming a static method that is itself one of the reads below (`Auth::check(...)`, `RequestContext::has(...)`) is reported directly. Otherwise a first-class callable naming a function or static method (`Conditions::onApi(...)`) is walked through its parsed declaration (`ConstructorParser::findMethod()`, which `findConstructor()` now uses), and its file is tracked. Its names resolve in a scope for the declaration's own file and namespace (`ScopeFactory`, `enterNamespace()`), since an unqualified function name falls back from that namespace, and for a method, inside its declaring class (`enterClass()`), so `self::` constants resolve. Each condition tracks `RuntimeInputs::track()`, since the booted aliases decide which abstracts resolve the request.

**Reports** the first read of request or user state. A facade or `RequestContext` subclass counts, but its own declaration of the method is an override, so it's unknown:
- `request()`;
- a `Request` facade call that the current request runs: not the facade's own methods (`swap()`, …) or the request's static methods (`hasMacro()`, `create()`, …);
- `RequestContext::get()`, `getOrNull()`, `has()`, `getValue()` and `getOrSetValue()`;
- `user()`/`check()`/`guest()`/`id()`/`hasUser()` on the `Auth` facade, or on `auth()` or a guard chained from it or `Auth::guard()`;
- resolving an abstract that aliases to `request` from the application container (`WorkerStateHelper::getApplicationResolvedAbstracts()`), or from the condition's first parameter, which receives the resolving container (new `getContainerResolvedAbstracts()`, sharing the argument reading). The parameter's evidence follows PHP's scoping:
  - a nested arrow function keeps it unless one of its own parameters has the same name;
  - a nested closure keeps it only through `use`, and not when one of its parameters has that name;
  - a body that assigns the variable (`=`, `=&` or a compound assignment) loses it, regardless of order. Each body is scanned on its own, so a nested closure's own variable of that name doesn't affect the outer body.

Configuration reads such as `Auth::getDefaultDriver()` are fine. A guard held in a variable isn't followed. The `Context` facade and the session aren't covered.

**Message** (at the read, or at the attribute for a first-class callable): "The #[BindWhen] condition on App\Payments contains a read of the current request or user, but a matched condition binds for the worker's lifetime." The docs say nested callbacks are inspected. **Tip:** "Conditions must depend only on state set while the application boots. Register a scoped() binding to choose an implementation per request."

**Tests:** each read form, including a named `condition` argument, the container parameter resolving `Request::class`, a static-method and a function first-class callable, `RequestContext::has(...)` and `Auth::check(...)` named directly, and a static method resolving `self::REQUEST` (reported); an overriding `Auth` subclass's `check(...)` named directly (silent); `Auth::getDefaultDriver()`, resolving `config`, a boot-stable static method, `Request::hasMacro()`, overriding `RequestContext` and `Auth` subclasses (including `guard()`), and a reassigned container parameter (silent). Nested callbacks: an `array_any()` callback reading `request()`, and a `use ($app)` closure resolving `request` after an earlier nested closure assigns its own `$app` (reported); an arrow function whose own `$app` parameter shadows the container, and a closure that doesn't capture `$app` (silent). A second fixture file in another namespace declares its own `request()`: its attribute naming the first namespace's `onAdminPath(...)` is reported, and the first file's attribute naming its `onLocalFlag(...)` is silent. The fixture uses PHP 8.5 attribute closures; it's only parsed, never loaded.

### 3.17 Swallowed cancellation

`SwallowedCancellationRule` is a `CollectedDataNode` rule over `SwallowedCancellationCollector`'s facts (section 2.4).

**Applies** in request, job and operation contexts and their `$this` helpers, as `WorkerStateAnalysis::getContext()` gives them.

**Reports** a `try` whose block (outside nested closures) directly contains one of the operations below, when the first catch that receives `CanceledException` (a type it `is()`: that class, `Exception` or `Throwable`) doesn't propagate it. Later catches never receive it. The message names the first operation found.

- **Nested tries.** An operation in the block of a nested `try` whose receiving catch doesn't propagate it doesn't count here; that `try` is reported itself. One whose catch propagates counts, as do the nested catch and `finally` bodies.
- **Receivers.** The collector has the scope at the `try`, so a method call's receiver, and a static call's class expression, is typed by `CoroutineRuleHelper::getReceiverType()`, now public and shared with `ExactCancellationCatchRule`. A local the block may change is one it mentions other than inside the expression being typed or as a method or static call's receiver:
  - a chain rooted at a local variable that the block only calls methods on keeps the variable's type at the `try`;
  - one assignment of the variable, as a top-level statement before every call on it, types the chain with the assigned expression in the variable's place (`$query = Import::query(); $query->where(...)->get()`, `$model = Import::class; $model::create(...)`). A `??=` assignment counts the same way, typed as the assigned expression when the variable is undefined at the `try`, and otherwise as the union of its non-null type there and the assigned expression;
  - a call argument reading a local the block may change is typed as `TypeExpr(mixed)`, so a call that selects its result's class by it gives no class (`$class = X::class; $app->make($class)->pop()`, `app($class)`), while a call whose type doesn't depend on it keeps its class (`$query->where('tenant', $tenant)`, `Import::whereIn('id', $ids)`). PHPStan's own inference, including return-type extensions, decides this, not a list of factories;
  - any other read of such a local leaves the type unknown, such as an assigned expression reading one (`$held = new X; $alias = $held;`), as does any other write of the root variable;
  - the chain is rebuilt as new nodes, since a clone keeps PHPStan's cached expression key;
  - `$this` and other roots use the scope's type.

  A direct construction is just one such assignment. The exact-catch rule gains the same typing, so a channel assigned from a typed variable counts as a native wait.

**Operations.** Only these exact forms count, matched by resolved declaration with overrides respected. A model's or builder's forwarded method counts as the method it forwards to, read through the reflection's prototype, since PHPStan wraps extension methods. The receiver's class reflections come from its type, so they keep the type arguments a builder's model macros depend on. A trait method is declared by the class using it, so a method of a framework trait on an application model (`SoftDeletes`, `Prunable`) is matched by its declaring trait (`PhpMethodReflection::getDeclaringTrait()`) when the declaring class has no entry; a model's own override has no declaring trait. Each runs its work during the call, can reach a cancellation point there, and lets the cancellation reach the caller (fast paths that skip I/O, fakes and pretend mode don't make swallowing safe):
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
- **Waits:** `Swoole\Coroutine\Channel::push`/`pop` (including `Hypervel\Engine\Channel`), `WaitGroup::wait` (Hypervel and Swoole), `Waiter::wait` and `wait()`, `Parallel::wait` and `parallel()`, `Concurrency::run()` (the default `coroutine` driver waits through `Parallel`), `Coroutine::join` (Hypervel, Engine and Swoole), and `Coroutine::sleep` (Hypervel and Swoole).
- **Hooked functions:** `sleep`, `usleep`.
- **Sleeps:** an expression statement discarding a `Sleep` it creates (`new Sleep` or `Sleep::for()`/`until()`/`usleep()`/`sleep()`, through its fluent methods, such as `Sleep::for(1)->second()`), where the creating method, the fluent methods, `__destruct()` and `goodnight()` are all the framework's declarations; and `Sleep::then()` wherever it's called, including when its result is assigned, on a class whose `goodnight()` is the framework's.

Not operations:
- **Deferred forms**, whose work doesn't run inside the `try`: `cursor()`, `lazy*()`, `pipeline()`/`transaction()` without a callback, and a `Sleep` held in a variable, which sleeps when the variable releases it. The return type alone isn't evidence, since a method may return a `Sleep` held elsewhere.
- **Cache, filesystem, queue and mail calls.** This is a deliberate capability choice, not a correctness limit. The rule's docs say these calls aren't checked and can still deliver cancellation, so a broad catch around them needs the same handling. Application code reaches them through contracts and facades whose driver is configuration, so they stay unknown. Concrete I/O-backed classes (`RedisStore`, `RedisQueue`, …) could count, but code rarely calls them directly, so listing them would add upkeep for little coverage.
- **Pool and session internals** (`getPdo()`, `ping()`, `reconnect()`, `executeSessionStatement()`), the conditional `touchOwners()`/`touchIfTouching()`, and model factories, which are test and seeding code.

**A catch propagates when** both of these hold:
- nothing leaves its body without throwing `$e`: no `return`, no `break`/`continue` leaving more loops and switches than the body opens around it (`break N` counts N levels; nested functions and classes are skipped), and no other `throw` that escapes the catch. A loop-local `break` doesn't leave the catch, but a `continue` of a loop outside the `try` does. A `throw` of `$e` or of a wrap keeping it as the previous exception (below) propagates it; any other `throw`, such as an early `throw new RuntimeException()`, swallows it unless an enclosing `try` inside the catch body catches that throw (its class from `new`, otherwise `Throwable`, against the catch types);
- its last top-level statement is `throw $e`, or `throw new X(...)` whose argument binds to the native or inherited `$previous` parameter. An overriding constructor counts only when `ConstructorParser::findConstructor()` shows a top-level `parent::__construct()` passing the bound parameter as the previous argument, with no `return` and no write to that parameter before it. This is followed up the chain;
- `$e` reaches that throw unchanged. Every write to it (`=`, `=&`, a compound assignment, or a nested catch binding the same name) must be a top-level `$e = new X(..., $e)` that keeps it as the previous exception by the rule above.

Alternatively, its first statement is a `Coroutine::causedByCancellation($e)` guard: a positive guard whose branch meets the same conditions, or a negated guard without `elseif`/`else` whose following statements meet them.

`ForeignCancellationException` never counts, because `causedByCancellation()` stops at it (`Coroutine.php:251`).

**No duplicate diagnostics.** Extract `ExactCancellationCatchRule`'s per-try verdict into `CoroutineRuleHelper::findExactCancellationMisses()`, which both rules call. The new rule skips only a catch that the existing rule reports for that try.

`ExactCancellationCatchRule`'s `NATIVE_WAITS` comment stays; it's accurate for Hypervel's throwing cancellation mode. Its behavior changes through the shared receiver typing above, and its `keepsCancellationApart()` also accepts a later `Throwable` catch that handles ordinary failures inside a negated guard without `elseif`/`else` and then rethrows `$e` unchanged as its only other statement.

**Tests:**
- a fallback `return` in a `Throwable` catch, around a query and around a channel `pop()`;
- an exact catch returning a fallback;
- a rethrow, a chained wrap and an unchained wrap;
- a caught variable overwritten before `throw $e`, and wrappers whose constructor overwrites the cause or forwards it only in a branch (reported); `$e = new Wrapper(..., $e); throw $e;` (silent);
- a query on a local builder from `Import::query()` and a pop on a locally constructed channel (reported); a typed channel replaced by a subclass overriding `pop()`, and an alias of a typed parameter that the block first replaces with that subclass (silent);
- a `where()` value, a static `whereIn()` root's value and an assigned class-string's `$model::create()` that the block sets (reported); `$app->make($class)->pop()` and an assigned `app($class)` whose class the block changes (silent);
- one try per database family (reported): `truncate()`, a model's `__call()` `increment()`, `load()`, a relation's own `get()` and a forwarded `count()`, a pivot `attach()`, a `SoftDeletes` `restore()`, the `onlyTrashed()->restore()` macro, an Eloquent collection's `load()`, `Schema::hasTable()` and `MySqlBuilder`'s own `dropAllTables()`; a model overriding `restore()`, `MySqlBuilder`'s inherited, throwing `dropAllTypes()`, a collection's `find()` and `toQuery()`, and a relation's `lazy()` (silent);
- nested tries: an inner catch swallowing a wait (inner reported, outer silent), and an inner catch rethrowing it (outer reported);
- `ExactCancellationCatchRule`: a channel assigned from a typed parameter is a native wait (silent); a negated guard reporting ordinary failures before a rethrow (silent), and a catch reporting before rethrowing (reported);
- an early `throw new RuntimeException()` before the rethrow and a cancellation guard throwing a replacement (reported); a wrap and a nested `try` catching its own throw (silent); a connection assigned with `??=` before a query (reported);
- `ForeignCancellationException`;
- a guard with an early return, and a negated guard reporting and returning before a rethrow (silent);
- a discarded `Sleep::usleep()`, a fluent `Sleep::for(1)->second()->…`, `then()` and an assigned `then()` result (reported); a held `Sleep`'s modifier, and a subclass with a no-op `goodnight()` both discarded and through `then()` (silent);
- a loop- and switch-local `break`/`continue 2` before a rethrow (silent), and a `continue` of a loop around the `try` (reported);
- a try with only deferred forms (`cursor()`, `Redis::pipeline()`), silent;
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

Done: sections 1 to 6, including the benchmark, Testbench runtime-copy cache fix (section 2.5), and final integration review. The PRs are open on both remotes. Next: finish review of the current round's bot-finding corrections, then run the remaining bot round.
