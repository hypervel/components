# Hypervel PHPStan Rules (PR 2)

Branch: `feature/phpstan-hypervel-rules`. This PR builds on PR 1 (`docs/plans/2026-11-12-hypervel-attributes.md`, merged into `0.4`): the `hypervel/attributes` package, converted markers, `#[Unsupported]` markers, and `WorkerStateHelper::hasLifetimeAttribute()`.
- Commit the runtime changes (section 1) separately from the analysis work, so they can be reviewed and reverted on their own.

Owner-approved:
- `Container::resolvedShared()` on the container contract, with facade roots from non-shared bindings becoming coroutine-local;
- binding `date` in `FoundationServiceProvider`, so a `Date` facade subclass overriding `DEFAULT_FACADE` needs an explicit `date` binding;
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
    - "Buildable" uses `WorkerLifetimes::getBuildableClass()`'s rules (a concrete class without `#[Bind]`/`#[BindWhen]`), shared as a public static method that takes the reflection provider. The `SelfBuilding` check stays local to `FacadeHelper`, so the helper's meaning doesn't change for `WorkerLifetimes`.
  - **Closure source matching.** `ReflectionFunction` gives the file and the start and end lines but no column. Parse the file with `@cachedRichParser` and take the closures and arrow functions with exactly that line range. Anything other than exactly one candidate is unknown, so two factories on one line never resolve. `X` must be a named class resolved through the file's namespace and imports. `self`, `static`, `parent`, dynamic class expressions and anonymous classes are unknown.
- `getMethod(ClassReflection $facade, string $name): ?ExtendedMethodReflection`: the facade's own public static native method (`swap()`, …), else the root class's public native method.
- **Tests** (through `UnsupportedCallRule`, which reads roots without contexts):
  - a facade over a single-expression `new X` factory resolves;
  - the same factory with an extender registered is unknown, as are a `SelfBuilding` root and a factory sharing its line with another closure;
  - a class-name binding chain resolves to its final class.
- Every call, memoized or not, tracks `RuntimeInputs::track($scope)`, `trackSources($scope, $facade, Facade::class)` for the accessor body, `trackClass($scope, $root)`, and `trackFileDependency()` for a parsed binding closure's file.

### 2.2 Lifetime queries on `WorkerLifetimes`

- `sharesAbstract(string $abstract): TrinaryLogic`, for the canonical key:
  - yes: a booted instance (including one over a scoped binding); a shared, non-scoped binding, closure factories included, since the container caches whatever they return; or a resolution the existing private `getLifetime($abstract, true)` reports shared (auto-singletons, a `bind()` to a shared class);
  - no: scoped or fresh;
  - maybe: unknown, including `bind()` closure factories, which may return a singleton.
- `sharesParameter(string $class, string $parameter): TrinaryLogic`: `build()` records each constructor parameter's outcome from `resolveConstructorParameter()` in a new `$sharedParameters` map, keyed by declaring class and parameter, beside `$freshParameters`.
  - yes only when every known build of that executed constructor injects a shared value.
  - Unknown arguments, factories or conflicting outcomes give maybe; they're never dropped from the conjunction.
- Injected entry parameters: `$configParameters` becomes `$injectedParameters`, mapping `class::method::parameter` to the abstract the container injects into a booted controller action (`discoverRoute()`) or job handler (`discoverJob()`, using a job's contextual class-name concrete; a contextual closure isn't recorded). `isConfigParameter()` checks `isGlobalConfig()` of the recorded abstract, and new `sharesInjectedParameter($class, $method, $parameter): TrinaryLogic` is its `sharesAbstract()` (no when unrecorded).
- The new queries are used only by section 3.1.

### 2.3 Listener and operation contexts in `WorkerLifetimes`

`discoverListeners()` already makes listener classes roots. Extend it to record execution contexts per registered handler, as an operation context separate from lifetime roots:

- **Handler:** `Class@method` strings, `[Class, 'method']` arrays, otherwise `handle()`, else `__invoke()`.
- **Context sources**, where any registration proves runtime use:
  - `request`: `HttpServer\Events\RequestReceived`, `RequestHandled`, `RequestTerminated`, `ResponseSent`, `RequestCanceled`, and `Routing\Events\Routing`, `RouteMatched`, `PreparingResponse`, `ResponsePrepared`.
  - `job`: `Queue\Events\JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred`, `JobAttempted`, `JobReleasedAfterException`, `JobTimedOut`.
  - `operation`: `Core\Events\OnReceive`, `OnPacket`, `OnTask`, `OnFinish`, `OnPipeMessage`, `OnConnect`, `OnClose`. Each is dispatched per server operation from its `Core\Bootstrap\*Callback`.
  - `operation` for Eloquent observer methods registered under `eloquent.*`: they can run during any operation that persists models, including requests, jobs, commands and seeders. Messages say "during an operation", not HTTP.
- Unknown app events add nothing. Contexts propagate to `$this` helpers through the existing helper-call propagation.
- These contexts feed `getContext()`, so `WorkerStateRule` gains them too. Re-run its tests and review any new reports.

### 2.4 Collected data and the shared model

`WorkerLifetimes` exists only in a `CollectedDataNode` rule, because it's built from every file's collected classes, resolutions, route actions and helper calls. Rules that need contexts or lifetimes therefore collect per-file facts and report from the collected data.

- **Shared model.** Move the model building out of `WorkerStateRule::processNode()` (grouping the collected resolutions, singletons, queued classes, helper calls, route actions and reassigned parameters, then constructing `WorkerLifetimes`) into a new `Rules/Coroutine/WorkerStateAnalysis`, together with the rule's `getContext()`, `findAction()`, `getFunctionKey()`, `isReassigned()` and `isInjectedConfig()`.
  - `WorkerStateHelper::analyze(CollectedDataNode $node, Scope $scope): WorkerStateAnalysis` builds it once per node (keeping the last node and its analysis) and, on every call, tracks the booted application and the lifetime classes on the caller's scope, as `WorkerStateRule` does today.
  - `WorkerStateRule`, `RestrictedCallRule` and `SwallowedCancellationRule` all use it.
- **Facts.** `WorkerStateCallCollector` keeps its one fact per call (resolution, route action, helper edge or config write). New facts come from their own collectors, so a call can be both, for example a route registration that is also a restricted call:
  - New `RestrictedCallCollector` (on `CallLike`, under `checkRestrictedCalls`) records `{line, method, attribute, may, whenShared, receiver, function}`. `may` means the parameter condition may not hold. `receiver` is section 3.1's evidence: `{abstracts}` (a facade accessor or a literal resolution), `{parameter}`, `{property: [class, property]}`, or null. Unmarked calls, calls whose parameter condition definitely doesn't hold, and configuration writes (section 3.1) aren't collected.
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
- Creating a first-class callable (`$factory->globalOptions(...)`) isn't a call and is never reported.
- A call is matched by its resolved declaration, through nullsafe calls too, with PHP's case-insensitive names. A user override or custom implementation is judged by its own declaration; config, container and facade methods are recognized by declaring class, not by name.
- Arguments bind to parameters by name or position (`CallLike::getArg($name, $position)`). An unpacked argument at or before the parameter's position makes the binding unknown; it's never guessed positionally.

**Cache dependencies** use the existing `RuntimeInputs` and scope tracking, never a new mechanism:
- booted state (bindings, aliases, routes, listeners, config, package mode, `bound()`): `RuntimeInputs::track()`;
- facade accessor bodies: `RuntimeInputs::trackSources($scope, $facade, Facade::class)`;
- classes whose declarations decide a result (facade roots, lifetime classes, Socialite parents): `trackClass()`/`trackClassDependency()`;
- code read beyond declarations, which PHPStan's exported nodes don't cover: exception constructor chains (3.17, the files from `SourceFiles::forInstance()`) and declared default values (`whenNotDefault`; exported nodes only record that a default exists). Track their files with `trackFileDependency()`;
- marker attributes and their arguments are part of exported nodes, so the declaring file's normal dependency already covers them.

Warm-cache tests go in `tests/PHPStan/WorkerStateDependencyTest.php`, one per mechanism rather than per rule: a booted registration changing a lifetime, a facade accessor body, a constructor forwarding `$previous`, and a declared default value.

## 3. Rules

| # | Rule class | Identifier | Switch (default) |
|---|---|---|---|
| 3.1 | `Rules/Coroutine/RestrictedCallRule` | `hypervel.restrictedCall` | `checkRestrictedCalls` (true) |
| 3.1 | `Rules/Coroutine/RestrictedAttributeRule` | `hypervel.restrictedAttribute` | `checkRestrictedCalls` (true) |
| 3.2 | `Rules/UnsupportedCallRule` | `hypervel.unsupportedCall` | none |
| 3.3 | `Rules/Coroutine/BorrowedResourceEscapeRule` | `hypervel.borrowedResourceEscape` | `checkBorrowedResources` (true) |
| 3.4 | `Rules/Coroutine/ManagedTransactionRule` | `hypervel.transactionEndedEarly` | `checkManagedTransactions` (true) |
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
- **Message:** names the restriction and the context. The tip says to move the call to a service provider (boot), a test, or to keep per-request state in `CoroutineContext`/`scoped()`.

**Conditions** (from PR 1's attribute arguments):
- **`whenNotDefault: 'p'`.** Find the argument bound to `p`. Omitted, or a constant equal to the declared default → silent. A type that may differ (unknown, union), or an unknown binding → reported, with "may change" wording.
- **`whenProvided: 'p'`.** An argument bound to `p` → reported; omitted → silent; an unknown binding → reported with "may change" wording.
- **`whenShared: true`.** Report only with evidence that the receiver is the worker-shared instance:
  - a facade whose accessor `sharesAbstract()` is yes;
  - a literal container resolution (`app('x')`, `resolve('x')`, `$this->app->make('x')`, `App::make('x')`) whose abstract is shared;
  - a parameter of a container-called entry method that the container actually supplies on that invocation path, where `sharesInjectedParameter()` is yes: booted controller actions through `ResolvesRouteDependencies::transformDependency()` (excluding route-bound and defaulted parameters), and job `handle()`/`__invoke()` through `Container::call()` as `WorkerLifetimes::discoverJob()` models it. Event, listener and Bus command payloads, contextual attributes and route closure parameters aren't evidence. A parameter reassigned before the call (the existing reassignment facts) or taken by reference loses the evidence.
  - a `readonly` promoted constructor property read as `$this->prop` on a container-created entry receiver, or its `$this` helpers, where `sharesParameter()` is yes for the executed constructor's declaring class. Queued-job constructor state never counts.

  Everything else is silent, including bounded local `new` evidence (a `new` expression receiver, or a local variable assigned only from `new`). `WorkerLifetimes::isShared($class)` is class-level and never counts as receiver evidence.
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
- the `Http\Client\Factory` example: `(new Factory)->globalOptions()` silent, `Http::globalOptions()` reported;
- `RestrictedAttributeRule`: each malformed case and a valid marker.

### 3.2 Unsupported calls

- **Reports:** any method or static call whose resolved declaration has `#[Unsupported]`, and facade static calls whose `FacadeHelper::getMethod()` has it. A per-node rule: it needs no lifetimes or contexts.
- **Message:** "`Class::method()` is not supported by this implementation and always throws.", followed by a newline and the attribute's `message` when `getArgumentTypes()['message']` has exactly one nonempty constant string (PHPStan maps positional arguments to parameter names). The guidance goes in the message, not a tip, because the GitHub, Checkstyle, JSON, GitLab and Raw formatters drop tips; PHPStan's deprecation rules also append their description on a new line. No validation of the message itself.
- **Silent:** an override without the attribute, and calls through a contract or base whose resolved declaration is unmarked.
- **Tests:** API-client `PendingRequest::setClient()` reported with its positional message; a bare fixture marker reported with the generic message only; the HTTP client's `setClient()` not reported; a subclass override; a facade call.

### 3.3 Borrowed resource escape

**Applies** inside the callback of:
- `RedisProxy::withConnection()` and `StoreContext::withConnection()` (raw handle `RedisConnection::client()`);
- `QueuePoolProxy::withConnection()` (`SqsQueue::getSqs()`);
- pooled-disk `withDriver()`/`withAdapter()`/`withClient()` (`InteractsWithPooledFilesystem`; plain `FilesystemAdapter` is skipped by its type);
- inside `Connection::withPinnedSession()`, `getPdo()`/`getReadPdo()` called on the same connection.

Raw-handle methods are matched on their owning classes.

**Reports** when the borrowed parameter, or a direct local alias or raw handle taken from it, is:
- returned;
- assigned to a `$this`/static property (including array dimensions) or to a by-reference `use` variable;
- captured by a closure passed to a spawn that can outlive the callback:
  - `go()`, `co()`, `Coroutine::create()`, `fork()`, `createOwned()`, `forkOwned()`, `defer()`, and the Engine and Swoole create;
  - `parallel()`, `Parallel::add()` and `Concurrency::run()`. When the owner is canceled, `Parallel` cancels its children synchronously but doesn't join them, so a child whose cleanup yields can still be running after the callback returns. The `Concurrency` coroutine driver uses `Parallel`, and the driver is configuration, so every `Concurrency::run()` counts;
  - `wait()`/`Waiter::wait()` unless `waitForChildTermination` is the constant `true`. By default a timed-out or canceled wait stops waiting after the cleanup allowance while the child runs on.

**Not reported:**
- local holders used inside the callback;
- `wait()`/`Waiter::wait()` with `waitForChildTermination: true`, which waits for the child to terminate on every exit, including the owner's cancellation;
- results, proxies and streams.

**Tests:** each source and escape kind, including `parallel()`; the non-escapes, with `wait(..., waitForChildTermination: true)` against the default `wait()`; and a same-named user method.

### 3.4 Managed transaction ended early

**Applies** inside a closure passed to `transaction()`, recognized as `JobDispatchedInTransactionUsesAfterCommitRule::isTransactionCall()` does. Both rules share it:
- move `isTransactionCall()` into a new `Concerns/InspectsTransactions` trait used by both rules;
- move the `isFacade()` it calls from `InspectsQueuedJobs` to a public static `ReflectionHelper::isFacade()`, since it's a pure name check; its callers in `InspectsQueuedJobs` and the queue rule call it there.

**Owner identity.** A transaction-control call (`beginTransaction()`, `commit()`, `rollBack()` declared by `ConnectionInterface`, or the `DB` facade equivalents) belongs to the managed transaction when its receiver has the same identity as the `transaction()` call's:
- the closure's first parameter, which `ManagesTransactions::transaction()` passes as the connection itself (`$callback($this)`), matches any form;
- a captured variable matches the same variable when the closure doesn't reassign it before the call;
- `DB::` static calls match `DB::` static calls (both the default connection);
- `DB::connection('x')` matches the same literal name.

Anything else is unknown, and unknown owners never match each other. A call on an unknown or different-form owner that may be the same connection (`DB::connection('mysql')->commit()` inside `DB::transaction()`) makes the rest uncertain rather than resolving configured names.

**Walk.** The rule walks the closure's top-level statements in order, with a depth counter starting at 0:
- `beginTransaction()` adds one; `commit()`, `rollBack()` and `rollBack(null)` remove one.
- A nested function-like is skipped without clearing the facts.
- An `if`, loop, `switch`, `match` or `try`, an unpacked argument, or a `rollBack()` level other than a constant `null` or `0` makes the rest uncertain, so it goes silent. The rule doesn't model what `rollBack(N)` does, since the entry level is unknown.
- It stops after a top-level `return` or `throw`.

**Reports:**
- `commit()`, `rollBack()` or `rollBack(null)` at depth 0;
- `rollBack(0)` (positional or `toLevel: 0`) anywhere before the walk goes silent.

**Tests:** balanced nested begin/commit; the closure parameter, a captured connection, a reassigned capture (silent) and the `DB` facade; a branch going silent; a statement after `return` (silent); `rollBack(0)` and `rollBack(toLevel: 0)`; `rollBack(2)` (silent); another connection (silent).

### 3.5 Native request APIs

**Reports**, everywhere in analyzed code:
- calls to `header`, `header_remove`, `headers_sent`, `headers_list`, `http_response_code`, `setcookie`, `setrawcookie`, `session_*` except `session_create_id` (it only generates an ID; the session handlers use it), `connection_aborted`, `connection_status` and `ignore_user_abort`;
- reads or writes of `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_SESSION`.

**Message:** these PHP APIs don't operate on Hypervel's request, response or session state. The tip gives the replacement:
- the `Request` methods;
- response `header()`/`cookie()`/`setStatusCode()`;
- the `Session` store;
- streamed-response disconnect handling;
- for `$_SESSION`, that it's shared by the worker process.

`$_SERVER`/`$_ENV` aren't covered. Intentional interoperability takes an ignore with a reason.

**Framework hits** (checked):
- `LoadEnvironmentVariables::writeErrorAndDie()` calls `http_response_code(500)`, an FPM leftover from Laravel. It does nothing in Hypervel, which bootstraps from the CLI before any response exists, so remove the line.
- Horizon `WorkCommand::handle()` calls `ignore_user_abort(true)` for `fast_termination`, as upstream does. In the CLI it stops the worker dying on its next output after its terminal goes away (it doesn't affect signals), so it takes an ignore with that reason.

**Porting guide:** replace the `connection_aborted()` paragraph (`porting-from-laravel.md:555`) with one concise paragraph covering the native header, cookie, status, session, superglobal and connection functions. Keep its streaming replacement sentence and link, and link the static-analysis section.

### 3.6 Discarded dates

**Reports** a statement-level method call (result unused) on a date receiver, when all of these hold:
- the method is declared by Carbon (`Carbon\CarbonInterface`, `Carbon\CarbonImmutable`, `Carbon\Carbon`, their traits, or the Hypervel subclasses), natively or by `@method`;
- it isn't a macro or a user override;
- its name is on the verified date-value modifier list:
  - prefixes `add*`, `sub*`, `startOf*`, `endOf*`, `next*`, `previous*`, `nth*`, `firstOf*`, `lastOf*`, `round*`, `floor*`, `ceil*`;
  - exact names `modify`, `change`, `set`, `setUnit`, `setUnitNoOverflow`, `setDate`, `setTime`, `setDateTime`, `setTimestamp`, `setTimezone`, `setISODate`, `setDateFrom`, `setTimeFrom`, `setDateTimeFrom`, `setTimeFromTimeString`, `tz`, `timezone`, `utc`, `shiftTimezone`;
  - the unit setters `year`, `month`, `day`, `hour`, `minute`, `second`, `millisecond`, `microsecond`, each in its plain, plural, `set` and `set`-plural form (e.g. `years`, `setYear`, `setYears`).

**Getter/setters** (`dayOfYear`, `weekday`, `isoWeekday`, `week`, `isoWeek`, `weekYear`, `isoWeekYear`, `dayOfWeek`, `weekOfYear`): reported only when the value argument is supplied and isn't definitely null. A possibly-null value gets the may-message.

Option setters (`locale`, `settings`, `setLocalTranslator`, …) never match, because they mutate and return `$this`. No Carbon method is marked pure.

**Messages:**
- a definite `DateTimeImmutable`/`CarbonImmutable` receiver: "The result of `addDay()` is discarded; the immutable date is unchanged."
- a `CarbonInterface` that isn't definitely mutable: "may be immutable; capture the result."
- Mutable `Carbon` is silent.

Native `DateTimeImmutable` methods are skipped, since PHPStan reports those.

**Tests:** each family on `CarbonImmutable` and `CarbonInterface`, the getter/setter argument cases, option setters, a macro and an override.

### 3.7 Mutable date creation

**Reports:**
- `new Carbon\Carbon`/`new Hypervel\Support\Carbon`;
- a static call on exactly those classes whose return type is an instance of them (`now()`, `parse()`, `create*()`, `instance()`, …).

**Tip:** use `Date::`/`now()`/`CarbonImmutable` (AGENTS "Use immutable dates by default"). Intentional mutable interop or conversion takes an ignore. For example, `HasAttributes`'s `Carbon::instance()` gets an inline ignore with its reason.

### 3.8 Uncast env values

**Reports**, in config files (the `NoEnvCallsOutsideOfConfig` detection of config files), an `env()`/`Env::get()` call with a constant bool, int or float default whose config item's resulting expression type still includes `string`. Checking the resulting type recognizes casts, `filter_var()`, `intval()` and equivalent normalization.

**Tip:** cast in the config file (AGENTS Configuration). Legitimate unions take an ignore; `src/sentry/config/sentry.php:35` `spotlight` (bool or URL, consumed at `SentryConfig.php:143`) gets one with its reason.

### 3.9 Typed config getters

Application mode only (`! RuntimeInputs::isPackageApplication()`).

**Reports** `config('key')`, `Config::get('key')` or a repository `get('key')` with a constant key whose value is definitely present and has exactly one non-null type among `string`, `int`, `float`, `bool`, `array`.

**Tip:** the matching `config()->string()`/`integer()`/`float()`/`boolean()`/`array()` getter, or the `Config` facade equivalent. There's no check for unneeded defaults.

### 3.10 `#[ObservedBy]` convention

**Reports** an unconditional top-level `Model::observe(<constant class-string list>)` statement in a service provider's `register()`/`boot()`, where the model is declared in analyzed files.

**Tip:** `#[ObservedBy]` on the model, the Laravel convention (AGENTS "Use Laravel observer conventions"). The rule is labeled a convention. The tip notes that subclasses inherit attribute observers (`resolveObserveAttributes()`), so registration intended for exactly one class, or dynamic registration, keeps `observe()` with an ignore.

### 3.11 Migration column types (default off)

**Reports**, in migrations:
- `enum()` → a string column plus a PHP enum cast;
- `set()` → multiple-value storage (a `jsonb` column cast with `AsEnumCollection`, or a related table), not a single enum;
- `json()` → `jsonb()` for JSON the database queries, indexes or validates, or `text()` for opaque values (AGENTS "Use semantic column types").

There's no `*Tz` check. Off by default because existing migrations shouldn't be edited.

### 3.12 Binding cycles

**Reports** `bind`/`singleton`/`scoped` on the application container (`$this->app`, `app()`, `App` facade, the `Application` contract), with a constant abstract and a constant class-string concrete that differ, where the booted final alias map resolves `getAlias(concrete)` back to the abstract. `Container::getClosure()` builds directly only when abstract === concrete.

`bindIf`/`singletonIf`/`scopedIf` aren't reported: they skip an abstract that's already bound, and the booted state can't show whether the call installed the binding or was skipped.

**Tip:** use a closure with `new` (AGENTS binding pattern 1).

### 3.13 Raw coroutines

**Reports:**
- `Swoole\Coroutine::create()`;
- `Swoole\Coroutine\go()`;
- `Hypervel\Engine\Coroutine::create()`.

Swoole short names are already rejected by the analyzer's bootstrap. `Swoole\Coroutine\run()` isn't reported: `Hypervel\Coroutine\run()` only adds hook-flag setup around it, which isn't the safety this rule is about.

**Tip:** use `Hypervel\Coroutine\Coroutine::create()`, `go()` or `co()`, which contain exceptions and cancellation; `Coroutine::fork()`, or the helpers' `copyContext` argument, copies the parent's context (`create()` starts with a fresh one). A direct engine coroutine is allowed when its callback contains cancellation itself (AGENTS "Contain cancellation in direct engine coroutines"): ignore with that reason.

**Framework hits** (checked): the wrappers themselves (`Coroutine\Coroutine`, `Engine\Coroutine`), `Watcher\Watcher` (`:47`, its callback catches `Throwable`) and `Grpc\Client\Connection` (`:460`, `receive()` catches `CanceledException`) take inline ignores with those reasons.

### 3.14 Socialite properties

The rule reports a read of `$this->X` in a subclass of the framework class whose getter reads X through `getContext('X', $this->X)`, covering redeclared defaults (e.g. `FacebookProvider::$scopes`):

| Properties | Framework class | Getter |
|---|---|---|
| `parameters`, `stateless`, `additionalConfig` | `Socialite\AbstractProvider` | `getParameters()`, `isStateless()`, `getConfig()` |
| `clientId`, `clientSecret`, `redirectUrl`, `scopes`, `usesPKCE` | `Socialite\Two\AbstractProvider` | `getClientId()`, `getClientSecret()`, `getRedirectUrl()`, `getScopes()` (also merges configured scopes), `usesPKCE()` |
| `fields`, `popup`, `reRequest`, `version` | `Socialite\Two\FacebookProvider` | `getFields()`, `getPopup()`, `getReRequest()`, `getGraphVersion()` |
| `scopeKey` | `Socialite\Two\SlackProvider` | `getScopeKey()` |

Property declarations and reads inside an override of that property's getter are allowed. A same-named property on an unrelated provider doesn't match. There are no framework hits today.

### 3.15 Swoole tables in providers

**Reports** `new Swoole\Table` executed directly in a service provider's `register()` or `boot()` body, not inside nested closures, callbacks or deferred factories.

**Tip:** create it from a `BeforeServerStart` listener (AGENTS).

### 3.16 Request-dependent `#[BindWhen]`

**Reports** a `#[BindWhen]` condition closure, or an inspectable first-class callable, that reads request or user state:
- `request()`;
- the `Request` facade;
- `RequestContext::get()`;
- `user()`/`check()`/`guest()`/`id()`/`hasUser()` through `auth()`, the `Auth` facade, or a guard obtained from them;
- resolving `request` or its aliases from the container.

Configuration reads such as `Auth::getDefaultDriver()` are fine. Conditions are never executed.

**Tip:** the binding persists for the worker, so the condition must be boot-stable; use `scoped()` for per-request selection.

### 3.17 Swallowed cancellation

`SwallowedCancellationRule` is a `CollectedDataNode` rule over `SwallowedCancellationCollector`'s facts (section 2.4).

**Applies** in request, job and operation contexts and their `$this` helpers, as `WorkerStateAnalysis::getContext()` gives them.

**Reports** a `try` whose block (outside nested closures) directly contains one of the operations below, followed by a catch that can receive `CanceledException` (that class, `Exception` or `Throwable`) and doesn't propagate it.

**Operations.** Only these exact forms count, matched by resolved declaration with overrides respected. Each runs its work during the call, can reach a cancellation point there, and lets the cancellation reach the caller (fast paths that skip I/O, fakes and pretend mode don't make swallowing safe):
- `Connection::runQueryCallback()` rethrows when `causedByCancellation()`;
- `RedisProxy` and `PendingRequest` (`:1191`, `:1358`) propagate it;
- `WaitGroup::wait()`, `Waiter::wait()` and `Parallel::wait()` throw `CanceledException` themselves;
- under Hypervel's `throwException: true` cancellation, Swoole channel `push`/`pop`, `Coroutine::join()`, `Coroutine::sleep()` and hooked `sleep`/`usleep` throw `CanceledException` (probed).
- **Database:**
  - `ConnectionInterface`: `select`, `selectOne`, `selectFromWriteConnection`, `scalar`, `insert`, `update`, `delete`, `statement`, `affectingStatement`, `unprepared`, `transaction`;
  - query `Builder`, directly or through Eloquent forwarding: `get`, `first`, `sole`, `find`, `value`, `pluck`, `count`, `min`, `max`, `sum`, `avg`, `exists`, `doesntExist`, `insert`, `insertGetId`, `insertOrIgnore`, `update`, `updateOrInsert`, `upsert`, `delete`, `increment`, `decrement`, `paginate`, `simplePaginate`, `cursorPaginate`, `chunk`, `chunkById`, `each`, `eachById`;
  - Eloquent `Builder`: those it declares itself, plus `firstOrFail`, `findOrFail`, `findMany`, `create`, `forceCreate`, `firstOrCreate`, `firstOrNew`, `updateOrCreate`, `createOrFirst`;
  - `Model`: `save`, `saveOrFail`, `update`, `delete`, `deleteOrFail`, `forceDelete`, `refresh`, `fresh`, `push`, `touch`.
- **Redis**, on `RedisProxy` or through the `Redis` facade: commands it doesn't declare natively (resolved through `RedisCommandMethodsExtension`), `command`, `withConnection`, `withPinnedConnection`, `scan`, `hScan`, `sScan`, `zScan`, and `pipeline`/`transaction` with a callback argument.
- **HTTP client**, on `PendingRequest` or through the `Http` facade: `send`, `get`, `post`, `put`, `patch`, `delete`, `head`.
- **Waits:** `Swoole\Coroutine\Channel::push`/`pop` (including `Hypervel\Engine\Channel`), `Hypervel\Coroutine\WaitGroup::wait`, `Waiter::wait` and `wait()`, `Parallel::wait` and `parallel()`, `Concurrency::run()` (the default `coroutine` driver waits through `Parallel`), `Coroutine::join` (Hypervel and Swoole), and `Swoole\Coroutine::sleep`.
- **Hooked functions:** `sleep`, `usleep`.

Not operations:
- **Deferred forms**, whose work doesn't run inside the `try`: `cursor()`, `lazy*()`, `pipeline()`/`transaction()` without a callback, and `Sleep` objects, which sleep on destruction.
- **Cache, filesystem, queue and mail calls.** This is a deliberate capability choice, not a correctness limit. The rule's docs say these calls aren't checked and can still deliver cancellation, so a broad catch around them needs the same handling. Application code reaches them through contracts and facades whose driver is configuration, so they stay unknown. Concrete I/O-backed classes (`RedisStore`, `RedisQueue`, …) could count, but code rarely calls them directly, so listing them would add upkeep for little coverage.

**A catch propagates when** both of these hold:
- its body has no `return`, `break` or `continue` outside nested functions;
- its last top-level statement is `throw $e`, or `throw new X(...)` whose argument binds to the native or inherited `$previous` parameter (an overriding constructor counts only when `ConstructorParser::findConstructor()` shows the bound parameter forwarded as the previous argument of `parent::__construct()`, followed up the chain).

Alternatively, its first statement is a positive `Coroutine::causedByCancellation($e)` guard whose branch meets the same conditions.

`ForeignCancellationException` never counts, because `causedByCancellation()` stops at it (`Coroutine.php:251`).

**No duplicate diagnostics.** Extract `ExactCancellationCatchRule`'s per-try verdict into a `CoroutineRuleHelper` method that both rules call. The new rule skips only a catch the existing rule actually reports.

`ExactCancellationCatchRule`'s behavior and its `NATIVE_WAITS` comment stay as they are; the comment is accurate for Hypervel's throwing cancellation mode.

**Tests:**
- a fallback `return` in a `Throwable` catch, around a query and around a channel `pop()`;
- an exact catch returning a fallback;
- a rethrow, a chained wrap and an unchained wrap;
- `ForeignCancellationException`;
- a guard with an early return;
- a try with only deferred forms (`cursor()`, `Redis::pipeline()`), silent;
- the same try outside any context, silent;
- the existing rule's case producing exactly one diagnostic.

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

Monorepo consumers: there's nothing to change. No class in `packages/hypervel/src` or `apps` implements the container contract or subclasses the `Date` facade (checked by grep). The monorepo's hypervel/phpstan adoption remains owner-gated.

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

Done: section 1. Next step: section 2.1.
