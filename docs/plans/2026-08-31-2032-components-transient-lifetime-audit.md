# Components Transient Lifetime Audit

## Goal

Make every container-buildable framework class use the lifetime implied by who owns its state. Eleven caller-owned value, date, response, and builder hierarchies must opt out of Hypervel's unbound concrete auto-singleton cache through the existing `Hypervel\Contracts\Container\Transient` marker. Worker-safe services must remain cached.

Use the existing `Transient` lifetime semantics for both hierarchy-wide and per-call freshness. Add one `makeTransient(string $abstract)` container operation for a call site that must suppress Hypervel's implicit auto-singleton and constructor-derived execution scope while retaining aliases, bindings, explicit lifetimes, extenders, resolving callbacks, and normal nested dependency lifetimes. Do not add a registry, reflection heuristic, cache eviction, exception path, or another lifetime abstraction. Remove concrete bindings that become redundant, preserve canonical string-key bindings, correct nearby lifetime guidance, and cover public behavior rather than marker syntax.

## Runtime model and design rules

- `Container::resolve()` already excludes `Transient` hierarchies from auto-singleton publication and shared-resolution coordination with `is_a(..., Transient::class, true)`. `Container::computeBuildRecipe()` uses the same check when deriving execution scope. The marker is inherited and adds no new hot-path work.
- An unbound auto-singleton is deliberately absent from `bound()`. This preserves nullable/default constructor parameters. A transient class remains unbound, so an optional dependency such as `?Collection $items = null` still receives `null`.
- Explicit `bind()`, `singleton()`, `scoped()`, and `instance()` registrations remain authoritative for a transient class. Parameterized `make()` / `makeWith()` already bypass every cache.
- `makeTransient()` is the per-call equivalent of the marker. It behaves exactly like normal zero-parameter resolution except that an ordinary unbound concrete cannot read, coordinate, or publish an implicit auto-singleton or reuse constructor-derived execution scope. Explicitly declared shared lifetimes still win. The method takes no parameter array because non-empty parameters already make `make()` bypass every cache.
- State ownership decides the lifetime:
  - worker-owned caches, registries, configuration, callbacks, and connections remain auto-singletoned;
  - caller-, request-, or operation-owned values use `Transient` only when freshness is intrinsic to the full class hierarchy;
  - one fresh registration uses `bind()` instead;
  - one instance per request/coroutine uses `scoped()`;
  - request data captured by an unscoped constructor is a defect, not a reason to label arbitrary mutable services transient.
- A longer-lived owner retains an injected transient instance. Callers needing freshness per operation must resolve at the operation boundary; the marker does not act as a proxy.
- The `collections`, `support`, `pipeline`, and `http` packages already require `hypervel/contracts`; no Composer metadata changes are needed.
- Laravel constructs unbound concrete classes afresh, so these value and builder APIs already have fresh container semantics there. The marker preserves that familiar outcome only for the affected hierarchies while Hypervel keeps its faster worker-level default for services.

The existing primitive remains:

```php
interface Transient
{
}
```

The implementation change on each hierarchy is intentionally declarative:

```php
use Hypervel\Contracts\Container\Transient;

class Collection implements ArrayAccess, Enumerable, Transient
{
    // Existing implementation is unchanged.
}
```

### Per-call transient resolution

Add `makeTransient(string $abstract): mixed` to the concrete container, `Hypervel\Contracts\Container\Container`, and the `App` facade method list. This is a Hypervel-specific resolution strategy and therefore belongs on the contract beside `build()` / `buildWith()`; Laravel does not need it because Laravel already constructs ordinary unbound concretes on every `make()`.

`makeTransient()` must be a thin wrapper over `resolve()`. Derive the per-call transient flag inside `resolve()` only after canonical alias resolution and before-resolving callbacks, or an alias such as `Mailer::class` or a callback-registered singleton could bypass its explicit lifetime. Extract `isExplicitlyShared()` for the predicate shared by `isShared()` and `resolve()`: instances, explicit shared bindings, and class-level `#[Singleton]` / `#[Scoped]` attributes are explicit. Preserve `isShared()`'s existing scoped-instance registry write and its bound guard before derived execution-scope analysis; `isDerivedExecutionScoped()` owns the remaining class-existence check. The flag must:

- remain false for explicit `singleton()`, `scoped()`, `instance()`, `#[Singleton]`, and `#[Scoped]` lifetimes;
- override constructor-derived execution scope, which is a safety inference rather than an explicit lifetime and is already excluded by `Transient`, `SelfBuilding`, and explicit non-shared bindings;
- prevent awaiting, reading, coordinating, and publishing implicit auto-singletons and constructor-derived scoped instances;
- leave explicit non-shared bindings, `Transient`, and `SelfBuilding` behavior unchanged;
- stay out of `BuildRecipe` analysis and its worker-lifetime static cache;
- leave contextual resolution, circular checks, resolving callbacks, extenders, nested dependency lifetimes, and resolved tracking unchanged.

Keep the warmed ordinary path in its existing order. An in-flight resolution should inspect the transient mode only after finding an owner, and the auto-singleton read should put `! $transient` last. Derive the effective flag only after that cache misses. The raw flag is correct at the auto-singleton read because every explicit shared lifetime clears any implicit cache entry or publishes through a different cache; the derived flag remains authoritative for scoped reads, coordination, and publication. Document this asymmetry beside the read so it is not later collapsed into earlier lifetime analysis.

The observable effect is intentionally narrow: only an ordinary unbound class that would otherwise receive an implicit auto-singleton or constructor-derived execution scope differs from `make()`. This is the missing call-site counterpart to the hierarchy-wide marker, not a general cache-bypass API.

## 1. Mark intrinsically fresh framework hierarchies

Add `Transient` to these eleven framework-owned base classes. Preserve all existing APIs, method order, state, and construction behavior.

| Class | File | Caller-owned state and hierarchy check |
|---|---|---|
| `Hypervel\Support\Collection` | `src/collections/src/Collection.php` | Items and mutators describe one caller's collection. Descendants such as Eloquent, notification, nested-set, routing, Scout, testing, and Testbench collections are also value containers. `Testing\LoggedExceptionCollection` is deliberately shared through `instance()`, whose explicit lifetime remains authoritative. |
| `Hypervel\Support\LazyCollection` | `src/collections/src/LazyCollection.php` | The public mutable `$source` belongs to the instance; replacing it must not alter another resolution. |
| `Hypervel\Support\Fluent` | `src/support/src/Fluent.php` | Attributes are caller-owned. Schema definitions and Testbench configuration descendants retain the same value-object lifetime. |
| `Hypervel\Support\MessageBag` | `src/support/src/MessageBag.php` | Validation messages are accumulated for one result. |
| `Hypervel\Support\ViewErrorBag` | `src/support/src/ViewErrorBag.php` | Named message bags are assembled for one response/view. |
| `Hypervel\Support\Stringable` | `src/support/src/Stringable.php` | The wrapped string is caller-owned and the supported ArrayAccess API can mutate it in place. |
| `Hypervel\Support\Carbon` | `src/support/src/Carbon.php` | A no-argument construction represents the current time and returns a caller-owned mutable date. Caching it both freezes "now" and shares later mutation. Application subclasses inherit the same construction contract. |
| `Hypervel\Support\CarbonImmutable` | `src/support/src/CarbonImmutable.php` | A no-argument construction represents the current time. Caching an immutable instance still freezes "now" at the first resolution for the worker lifetime. Application subclasses inherit the same construction contract. |
| `Hypervel\Pipeline\Pipeline` | `src/pipeline/src/Pipeline.php` | The passable and configured builder chain belong to each constructed instance. Routing, gRPC, and AOP descendants share that ownership; Routing may intentionally compile one constructed pipeline into a worker-cached route closure. |
| `Hypervel\Http\Response` | `src/http/src/Response.php` | Content, status, headers, cookies, and original content belong to one response. |
| `Hypervel\Http\JsonResponse` | `src/http/src/JsonResponse.php` | JSON data, encoding options, headers, callback, and original data belong to one response. |

Do not add class docblocks merely to explain the marker. The contract and container documentation own that explanation.

No Pipeline hierarchy is container-resolved in framework source. Routing, gRPC, AOP, bus, and queue paths all construct their pipelines directly with an explicit container. The marker therefore changes public unbound resolution without adding allocations to those dispatch paths, including Routing's compile-once cached route pipeline.

### Binding cleanup

#### Pipeline

In `src/pipeline/src/PipelineServiceProvider.php`, remove the redundant concrete binding and its now-stale comment, then inline the factory into its sole remaining canonical binding:

```php
$this->app->bind('pipeline', fn ($app) => new Pipeline($app));
```

The facade resolves the canonical `'pipeline'` key, which has no alias to the concrete class and must remain fresh. Keep the pipeline hub singleton unchanged.

This matches Laravel's current Pipeline provider, which binds the hub contract and canonical `'pipeline'` key but has no concrete Pipeline binding. Hypervel's marker supplies the fresh unbound behavior that Laravel gets from its container lifecycle.

No resolving callback or extender targets the concrete Pipeline key, so removing its binding does not orphan container customization. Unbound resolutions continue to fire normal resolving callbacks and extenders.

This preserves both constructor paths:

- Application resolution of unbound `Pipeline::class` now uses the inherited marker and still receives the application through the `ContainerContract` instance registered by `Application::registerBaseBindings()`.
- `ApiClient\PendingRequest::__construct(?Pipeline $pipeline = null)` keeps its default `null`, because the concrete is no longer `bound()`. Its existing fallback resolves a fresh transient pipeline from the application container.

#### HTTP response

In `src/http/src/HttpServiceProvider.php`, remove the call to `registerResponseFactory()` and delete that private method. Its only behavior is a fresh concrete `Response::class` binding, which the marker now expresses at the owning hierarchy.

Do not change the canonical `'request'` binding. It must continue consulting coroutine-local `RequestContext` on every resolution. Do not change the application alias from Symfony's response class to `Hypervel\Http\Response`; alias resolution will reach the now-transient concrete naturally.

No resolving callback or extender targets the concrete Response key, so removing its binding does not remove an existing hook.

## 2. Correct adjacent lifetime guidance and stale code

### Framework contributor rules

Keep the approved `AGENTS.md` changes that:

- require lifetime classification when adding, porting, or changing mutable state on a container-buildable class;
- explain state ownership rather than treating all mutable classes alike, while naming services, middleware, listeners, factories, and formatters as the normal auto-singleton class families;
- identify unscoped constructor-captured request state as a coroutine-safety defect;
- warn against inferring `Transient` from setters or a no-argument constructor;
- replace the contradictory binding list with one ordered resolution-strategy list covering auto-singletons, canonical singletons, instances, execution scope, scoped and fresh bindings, `Transient`, per-call `makeTransient()`, `SelfBuilding`, parameterized `make()`, and direct `build()`.

Update the matching auto-singleton comment in `src/container/src/Container.php`. It currently says services are stateless singletons by design, which is narrower than the real rule: worker-safe services may retain service-owned initialized state. Keep the surrounding explanation of explicit bindings, `SelfBuilding`, `Transient`, `raiseEvents`, and `$autoSingletons` intact.

### Deliberate fresh bindings

Keep both registrations in `QueueServiceProvider::registerCallQueuedHandler()`:

```php
$this->app->bind(CallQueuedHandler::class);
$this->app->bind('Illuminate\\Queue\\CallQueuedHandler', CallQueuedHandler::class);
```

Add one concise WHY comment: `CallQueuedHandler::$runningCommand` is per-job state, so concurrent jobs require fresh handler instances. A single framework-internal class at one registration boundary is correctly modeled by `bind()`; marking its hierarchy transient would be broader than the need.

In `Console\Scheduling\Schedule::job()`, resolve class-string jobs through `makeTransient()` on every firing. Cloning an auto-singleton is incorrect even when possible because the constructor and its per-firing context still run only once. Fresh container resolution restores Laravel's construction semantics while preserving Hypervel's explicit binding lifetimes and resolution hooks. Keep one concise WHY comment explaining that ordinary `make()` would reuse Hypervel's implicit auto-singleton across firings.

An explicitly shared class-string job is dispatched as the shared instance returned by the container. Laravel's scheduler behaves the same way, and cloning or rejecting that result would override the explicit lifetime that `makeTransient()` promises to preserve. Do not add scheduler-owned isolation for `singleton()`, `instance()`, `#[Singleton]`, or scoped lifetimes.

Caller-supplied objects are dispatched as provided, matching Laravel. Queued dispatch applies connection and queue state to that object through its normal mutators. Do not clone supplied jobs: the API does not promise an immutable snapshot, shallow cloning cannot isolate nested objects, and invoking arbitrary `__clone()` methods creates a new failure path without fixing a supported-use defect.

### `DefaultProviders` value semantics

Preserve an explicitly empty provider list. The constructor currently uses `?:`, so both `new DefaultProviders([])` and `except()` removing the final provider silently restore every default. Use null as the only signal for the built-in list:

```php
$this->providers = $providers ?? [
    // defaults
];
```

Laravel currently carries the same defect. Hypervel must fix it rather than preserve incorrect upstream behavior.

Make `DefaultProviders::merge()` match `replace()` and `except()` by returning a new value without mutating its receiver:

```php
public function merge(array $providers): static
{
    return new static(array_merge($this->providers, $providers));
}
```

The sole framework call to `merge()` already uses the returned object, so application output is unchanged. This removes a surprising mutable edge from a collection-like value API and prevents a retained prototype from changing if reused. Laravel currently contains the same stray receiver assignment, but preserving that implementation detail would retain a verified API inconsistency without adding compatibility.

Restore Laravel's simpler `except()` implementation:

```php
return new static((new Collection($this->providers))
    ->diff($providers)
    ->values()
    ->toArray());
```

Hypervel's current `reject(fn ($p) => in_array($p, $providers))` drift uses a forbidden loose comparison, abbreviates the callback variable, and allocates a callback for behavior `diff()` already owns.

Restore the upstream class-string generic information that native `array` cannot express: `array<class-string>` for the property, constructor, `merge()`, `except()`, and `toArray()`; `array<class-string, class-string>` for `replace()`. Do not claim list keys because the constructor accepts caller-provided array keys unchanged, and do not add redundant `@return static` annotations where native types already express them.

### Message bag contract and `ViewErrorBag` native types

Complete the existing Hypervel typing of `ViewErrorBag` without changing its Laravel behavior:

```php
public function getBag(string $key): MessageBagContract;
public function __get(string $key): MessageBagContract;
public function __set(string $key, MessageBagContract $value): void;
```

Remove the redundant `@param` and `@return` annotations from the magic methods once native types express them. These types follow the actual `put()` boundary and fallback `MessageBag`; they do not narrow a supported value.

Make `Hypervel\Contracts\Support\MessageBag` extend PHP's `Stringable`. `ViewErrorBag::__toString()` renders the contract returned by `getBag()`, while `put()` and `MessageProvider` permit any contract implementation. Laravel's contract omits this required capability, so a conforming custom bag without `__toString()` fails only when rendered. Hypervel must express the real contract and move that failure to class declaration. Existing implementations that declare `__toString()` already satisfy `Stringable` automatically and need no change.

Leave `Hypervel\Support\MessageBag`'s explicit Laravel-aligned `Stringable` implementation untouched. Do not add an intersection type, concrete return type, runtime guard, fallback encoder, or alternate implementation merely to test PHP interface enforcement.

Correct the numeric-key support already accepted by `MessageBag::__construct()`. Laravel's untyped `transform()` accepts the integer keys used by its own `ViewErrorBag` tests, while Hypervel's `string $messageKey` throws before the default-format fast path. Use `int|string $messageKey`; `Str::is()` and `str_replace()` already accept that value without a new branch or coercion.

Make the affected PHPDoc surfaces truthful: use `array-key` for top-level message keys on the property, constructor, `merge()`, `get()`, wildcard result, `messages()`, `getMessages()`, `toArray()`, and `jsonSerialize()`, and `list<array-key>` for `keys()`. Add `array<string>` to `unique()`, matching `all()`. Keep `all()` and `transform()` as `array<string>` because inner message keys may be sparse or strings and the default-format path preserves them. Do not add generic annotations to the contract, whose title-only method docs are already accurate.

### Collection native return types

Complete the thirteen collection methods whose native return types were omitted because Eloquent transformations do not always preserve the concrete receiver type:

```php
collapse();
flatten();
flip();
keys();
map();
mapWithKeys();
flatMap();
mapInto();
partition();
pluck();
pad();
countBy();
zip();
```

Use the narrowest correct native type at each hierarchy level:

- `Enumerable`: `Collection|static` on all thirteen declarations. A direct implementation may return either its own type or Hypervel's base collection.
- `EnumeratesValues`: `Collection|static` on `flatMap()`, `mapInto()`, and `partition()`.
- `Collection`: `Collection` on its ten concrete methods. Each constructs through `newInstance()`, so the concrete runtime object still preserves subclasses where appropriate, while the declared base type permits Eloquent's deliberate fallback.
- `LazyCollection`: `static` on its ten concrete methods because each preserves the lazy receiver type.
- `Eloquent\Collection`: imported `BaseCollection` on its eleven overrides because these methods deliberately produce support collections. Import `Arrayable` and replace the inline fully qualified name on `zip()` while editing that signature.

Keep the thirteen existing `Enumerable` `@return static<...>` annotations. For an interface-typed receiver, `static` resolves to `Enumerable`, which every valid base `Collection` result satisfies; the native union exposes the base arm for direct implementors, and changing the annotations adds no precision to current PHPStan inference. Likewise, keep the existing concrete `Collection`, `LazyCollection`, Eloquent override, and trait `partition()` annotations.

Correct only the genuinely false inherited annotations on `EnumeratesValues::flatMap()` and `mapInto()` to `Collection<...>|static<...>`. An Eloquent receiver inherits both methods, and each can return a base collection that is not an Eloquent collection. Remove the stale missing-return-type paragraphs from both `Enumerable::flatMap()` and the trait method. Add one concise paragraph to the `Enumerable` class docblock explaining that transformations may return a base collection when the implementation cannot preserve its concrete item contract.

Complete the final two untyped public methods in the collection hierarchy. `Eloquent\Collection::find()` accepts an unconstrained caller default, so declare its parameters and return as `mixed` while retaining the conditional generic PHPDoc. `findOrFail()` returns a model for scalar keys and the concrete collection for array or `Arrayable` keys, so declare `Model|static` and correct its false model-only PHPDoc to the same conditional shape. Preserve its intentional loose key comparison, which provides Laravel's numeric-string key matching.

Correct the six loose null checks guarding optional collection filters: eager `sole()` and `hasSole()`, shared `hasMany()`, and lazy `sole()`, `hasSole()`, and `firstOrFail()`. Use `$filter === null`, matching the existing strict guards in the same hierarchy. The current spelling uniquely treats an empty string as null: it silently disables filtering in five methods and makes lazy `firstOrFail('')` return an item while the eager implementation rejects the same non-callable string. Do not alter the intentional loose value and key comparisons used by Laravel's non-strict collection APIs.

These collection corrections need only exact declarations and direct null comparisons; no wrapper, reflection test, or compatibility machinery is needed.

## 3. User documentation

### Scheduling documentation

Add one concise paragraph after the two documented `Schedule::job(new Heartbeat)` examples. Explain that class-string jobs are resolved through the container for every due firing and explicit container lifetimes remain authoritative, so a singleton job is dispatched as that same instance on every firing. Supplied objects follow Laravel's direct-dispatch behavior and need no separate documentation.

### Container documentation

Update `src/docs/container.md` under **Transient Classes** to name the built-in families that already or now implement the marker:

- Eloquent models and seeders;
- HTTP-client and API-client pending requests;
- collections and lazy collections;
- fluent values, message and view error bags, stringables, and dates;
- pipelines;
- HTTP and JSON responses.

Keep the existing warning that constructor-injecting a transient into a longer-lived service retains that exact instance. Keep the guidance concise and centered on ownership, hierarchy-wide freshness, and explicit binding overrides.

Correct the adjacent per-call-state guidance to include `scoped()` for one instance per request or job, alongside `bind()` and direct `build()` for freshness at narrower boundaries. Keep `Transient` as the separate hierarchy-wide choice.

Add `makeTransient()` beside `make()` and `build()` in the resolution table and lifecycle guidance. Define it as one normal container resolution that suppresses the implicit auto-singleton and constructor-derived execution scope. State that explicit lifetimes remain authoritative and that `makeTransient()` is preferable to `build()` when aliases, top-level bindings, extenders, or resolving callbacks must remain active.

### Laravel porting guide

In `src/docs/porting-from-laravel.md`, replace the narrow sentence that only Eloquent models implement `Transient` with a concise reference to Hypervel's built-in transient families and link readers to the container documentation. Do not turn the porting guide into a full inventory or repeat the lifetime explanation.

Add `makeTransient()` to the lifecycle table as Hypervel's per-call counterpart to Laravel's naturally fresh unbound `make()`. Keep the explanation concise and link to the canonical container documentation.

Under Contracts, state that `Hypervel\Contracts\Support\MessageBag` extends `Stringable`, so a custom implementation without `__toString()` fails at class declaration instead of later when rendered through `ViewErrorBag`. This is a deliberate correction of a verified Laravel contract defect, not a new rendering API.

## 4. Rejected audit candidates

These decisions keep the marker narrow and prevent future sessions from reintroducing the same proposals:

- `Support\Timebox` remains unmarked. `SessionGuard` and `PasswordBroker` intentionally retain a prototype and clone it per operation; marking the dependency would not refresh an instance already retained by a longer-lived owner.
- `Support\SplPriorityQueue` remains unmarked. Production owners construct it directly, while `Aop\AstVisitorRegistry` intentionally stores one as boot-time shared state. There is no supported container path needing hierarchy-wide freshness.
- `Http\ResponseHeaderBag` and `HttpServer\RequestHeaderBag` remain unmarked. Both are internal construction details of their owning response or request path, and neither has a supported container-resolution construction API: responses clone a response-owned header prototype, while `RequestBridge` constructs the final internal request bag directly.
- Direct- or factory-owned engine resources, validation rule builders, prompt/image builders, notification/mail payload builders, Saloon repositories and fakes, process fakes, queue middleware, `PoolOption`, and `Server\Port` remain unchanged. The audit found no realistic container resolution path or hierarchy-wide lifetime defect.
- `Coroutine\Waiter` is the negative control: its constructor holds safe configuration and invocation state remains local, so auto-singletoning is correct.
- Scheduled class strings resolved to an explicitly shared lifetime are dispatched as that shared instance. Laravel's `Schedule::job()` does the same, and cloning or rejecting the result would override an explicit registration the container just honored. `makeTransient()` fixes only Hypervel's implicit auto-singleton and constructor-derived execution scope; a singleton job receiving per-dispatch mutation is a contradiction in application configuration, not a framework lifetime defect. Do not use repeated direct `CallbackEvent::run()` calls in one coroutine to infer production scoped behavior: `ScheduleRunCommand` evaluates each firing in a finite coroutine, while a direct call correctly retains the caller's current scope.
- Caller-supplied scheduled objects are dispatched as provided, matching Laravel. The original clone addressed class strings resolved from Hypervel's implicit auto-singleton cache, which `makeTransient()` now fixes at the owning boundary. Do not reintroduce supplied-object cloning: it invokes arbitrary user code and a shallow copy still shares nested objects, so it cannot provide the isolation it appears to promise.

Do not add warning docblocks to rejected classes. They would document an implementation choice that is already governed by the container lifetime rule and would become stale.

## 5. Tests

Test behavior through the container. Do not assert only `instanceof Transient`; that can pass while resolution, inheritance, state isolation, or binding precedence is wrong.

### Framework transient behavior

Add `tests/Container/FrameworkTransientTest.php` using `Hypervel\Tests\TestCase` and an isolated `Container`. A data provider must resolve two instances of the nine mutable classes, mutate the first through a supported public API, and prove both distinct identity and pristine state on the second:

| Class | First-instance mutation | Snapshot expected on first / second |
|---|---|---|
| `Collection` | `push('changed')` | `['changed']` / `[]` |
| `LazyCollection` | replace public `$source` with `['changed']` | `['changed']` / `[]` from `all()` |
| `Fluent` | `set('changed', true)` | `['changed' => true]` / `[]` |
| `MessageBag` | `add('field', 'changed')` | `['field' => ['changed']]` / `[]` from `getMessages()` |
| `ViewErrorBag` | `put('default', new MessageBag(...))` | `['default']` / `[]` from `array_keys(getBags())` |
| `Stringable` | set offset `0` to `'x'` | `'x'` / `''` |
| `Pipeline` | `send('changed')` | `'changed'` / `null` from `thenReturn()` |
| `Response` | `setContent('changed')` | `'changed'` / `''` |
| `JsonResponse` | `setData(['changed' => true])` | changed array / empty array from associative `getData()` |

Representative test shape:

```php
$first = $container->make($class);
$second = $container->make($class);

$mutate($first);

self::assertNotSame($first, $second);
self::assertSame($changed, $snapshot($first));
self::assertSame($pristine, $snapshot($second));
```

Add one focused `testFrameworkDatesResolveFreshFromTheContainer()` method beside the matrix. Freeze Carbon's shared test clock, resolve two mutable dates, mutate the first, and prove identity and state isolation. Then resolve an immutable date, advance the shared test clock, and prove a second resolution is a distinct instance at the new time while the first retains the old value. Do not duplicate clock cleanup: `AfterEachTestSubscriber` already resets Carbon's authoritative default factory after every test.

### Package regression coverage

- Add `tests/Support/DefaultProvidersTest.php` proving that an explicit empty list remains empty, `merge()` leaves its receiver unchanged and returns the merged provider list, and `except()` can remove every provider without mutating its receiver. Assert exact arrays rather than provider counts so the tests do not depend on the framework's default inventory.
- Extend the existing collection-class-provider coverage in `SupportCollectionTest.php` with one method proving eager and lazy `sole()`, `hasSole()`, `hasMany()`, and `firstOrFail()` reject an empty non-callable filter string just like every other non-callable string.
- Port all sixteen current Laravel cases from `tests/Support/SupportViewErrorBagTest.php` into the matching Hypervel path and filename. Adapt strict types, namespaces, the Hypervel base test case, native `void` returns, and exact identity assertions, but preserve every supported behavior. Add one case proving that an empty bag stringifies to `[]` through `getBag()`'s fallback. The upstream dynamic-call case is the regression for numeric constructor keys reaching `MessageBag::transform()`; do not duplicate it or manufacture an alternate contract implementation.
- Keep `tests/ApiClient/PendingRequestTest.php` unchanged. `testApiMiddlewareAcceptsEveryPipelineShapeAndContainerInjection()` creates `PendingRequest` through its real constructor fallback and runs class-string middleware. That path reaches `Pipeline::getContainer()`, which throws when no container was injected, so the existing test catches a broken unbound Pipeline construction after the concrete binding is removed without exposing protected state.
- Keep `tests/Pipeline/CoroutineIsolationTest.php` unchanged; it already proves two application-resolved concrete pipelines do not share mutable state across interleaved coroutines after the binding removal.
- Keep `tests/Integration/Http/ResponseBindingTest.php` unchanged; it already proves fresh concrete responses and fresh resolution through the Symfony alias after the response binding removal. `FrameworkTransientTest` supplies the missing JSON-response coverage.
- Keep the existing `LoggedExceptionCollection` tests unchanged. `FoundationServiceProvider` deliberately publishes one test-lifetime collection through `instance()`, and `MakesHttpRequestsTest` proves that an explicit replacement is retained; explicit instances remain authoritative over the inherited collection marker.
- Keep `tests/Queue/LaravelInteropTest.php` as regression coverage for the retained queue bindings. In `ScheduleTest.php`, replace the identity-only class-string regression with a construction counter and per-construction stamp proving a synchronous class-string job is constructed for every firing. Add two queued events for the same unbound class string: the first supplies a queue override and the second must retain its own default, directly covering the `$queue ?? $job->queue` bleed boundary. Add one single-firing synchronous case that registers a specific job with `instance()` and proves the dispatcher receives that exact object; this protects explicit-lifetime authority without encoding queued mutation as desired behavior. Add one supplied queued-object case proving the dispatcher receives the exact object and applies the scheduled connection and queue to it. Do not add cloneability branches or a duplicate synchronous supplied-object test.
- Extend `ContainerTest.php` with focused `makeTransient()` coverage: two calls ignore a warmed auto-singleton without evicting or replacing it; explicit singleton, scoped, instance, class-level `#[Singleton]` / `#[Scoped]`, and one non-shared binding retain their established behavior; aliases, extenders, and before/resolving/after callbacks remain active; and nested worker-shared dependencies remain shared. Cover a canonical aliased singleton explicitly so the transient flag cannot be derived before alias resolution. Prove constructor-derived execution scope is bypassed per call while ordinary `make()` remains scoped. Do not duplicate the existing hierarchy-wide `Transient` matrix.
- Extend `CoroutineSafetyTest.php` across both shared-resolution branches. A slow normal auto-singleton resolution and `makeTransient()` of the same class must neither await nor publish each other's object, and the normal result must remain the later normal cache hit. Run the explicit-singleton callback fixture with both ordinary and transient waiters, proving that both join the owner and observe the completed callback state without duplicating the fixture.
- Extend `types/Database/Eloquent/Collection.php` with PHPStan assertions proving that inherited `flatMap()` and `mapInto()` of a non-model target expose a base support collection and that `findOrFail()` preserves its scalar-model and array-collection results. In `types/Collections/Collection.php`, add representative `flatten()` assertions proving interface-typed receivers retain `Enumerable` inference and lazy receivers retain `LazyCollection` inference.

Do not add a duplicate generic `Transient` test, a marker- or binding-presence test, an accessor exposing protected state, or another auto-singleton negative control. `tests/Container/ContainerTest.php` already covers generic transient inheritance, explicit lifetime overrides, instances, scoped bindings, extenders, nullable/default dependency preservation, parameterized resolution, and auto-singleton behavior.

## Performance and compatibility

- Existing constructors, aliases, binding APIs, and explicit lifetime precedence remain intact. The only deliberate Laravel contract tightening is `MessageBag` requiring the string conversion that `ViewErrorBag` already performs; custom implementations without `__toString()` now fail at declaration instead of render time.
- The collection native return types express existing runtime behavior without adding a branch or allocation. They use PHP 8.4-compatible unions and retain the existing generic precision.
- `DefaultProviders::except()` removes a callback and loose comparison by returning to the existing `Collection::diff()` primitive.
- The hierarchy markers do not change the container algorithm. Each relevant path already performs the `is_a(..., Transient::class, true)` check; only its result changes for the eleven declared hierarchies.
- `makeTransient()` adds one false-by-default resolver flag. A warmed ordinary auto-singleton pays only one predictable boolean after the existing cache lookup conditions and performs no new lifetime analysis. Transient calls reuse the existing resolver and suppress implicit auto-singleton coordination, reads, and publication plus constructor-derived scoped caching; benchmark the normal and per-call paths to confirm the remaining branch is noise.
- `resolve()` gains an optional fourth protected parameter for the per-call mode. This is a deliberate Hypervel 0.4 extension-point signature change; subclass overrides must accept it. Keep `shouldCoordinateSharedResolution()`'s protected signature unchanged by gating transient calls at its sole call site.
- Correctly fresh value objects allocate once per requested instance. Worker-safe services continue using the auto-singleton cache. This is the narrow performance boundary the audit is intended to enforce.
- Removing the redundant concrete Pipeline and Response bindings avoids their closure/binding lookup while preserving behavior through the marker. Canonical string-key behavior remains explicit.
- Scheduled jobs incur no reflection or cloning. Ordinary unbound class strings use fresh construction, explicitly shared class strings retain their registered identity, and supplied objects are dispatched as provided.
- Disable Composer's process timeout on the `test`, `test:parallel`, and `test:testbench` scripts. The full suite can exceed Composer's default 300-second wrapper limit on a loaded machine; each runner and CI job retains its own failure and job-timeout behavior.
- Do not add a committed benchmark harness. Run a local focused microbenchmark comparing ordinary warmed `make()` before and after the resolver change and measuring `makeTransient()` against direct transient resolution. The correctness tests remain authoritative; the benchmark only verifies that the false-by-default branch adds no meaningful normal-path cost.

## Implementation sequence

1. Copy the local `.env` from the main components worktree and run `composer install` in this worktree so all checks use its branch and dependencies.
2. Add `FrameworkTransientTest.php` and run it. All nine mutation rows and the focused date test must expose the current shared-instance defects against an isolated container; service-provider bindings are deliberately absent from this class-level lifetime test.
3. Apply the collection return types and PHPDoc corrections one source file at a time. Extend the Eloquent PHPStan fixture, run its types configuration immediately, then run the support collection and lazy-collection tests.
4. Add the eleven `Transient` declarations one source file at a time, complete the `ViewErrorBag` native types while editing that class, then rerun `FrameworkTransientTest.php`.
5. Remove the concrete Pipeline binding, then run the unchanged API-client and pipeline coroutine tests to verify constructor fallback container injection and mutable-state isolation.
6. Remove the HTTP response factory and run `ResponseBindingTest.php` plus `FrameworkTransientTest.php`.
7. Add `DefaultProvidersTest.php`, run it to confirm receiver mutation and empty-list restoration, apply the complete value-semantic correction, and rerun it. Copy and port `SupportViewErrorBagTest.php`, run it to expose the numeric-key transform defect, then apply the complete `MessageBag` contract and implementation type correction and rerun it.
8. Add failing focused `makeTransient()` tests to `ContainerTest.php` and `CoroutineSafetyTest.php`. Implement the contract, facade annotation, concrete method, and resolver flag; run both files and targeted container PHPStan.
9. Correct the container and queue-handler comments. Replace class-string cloning with per-firing transient resolution, complete the class-string construction/queue-isolation and explicit-shared identity coverage, prove supplied queued objects are dispatched as provided, and rerun `ScheduleTest.php`.
10. Verify the contributor-guide edit already present in the worktree, add the `makeTransient()` strategy, apply the user-documentation updates, then inspect every changed comment and paragraph against the final source.
11. Run `git diff --check`, targeted formatting and static analysis, and the focused tests below. Do not rerun the full parallel suite for this reviewed correction.
12. Self-review every changed file and trace both marker and per-call transient resolution through container publication, coordination, build recipes, aliases, defaults, explicit bindings, callbacks, extenders, and nested dependencies. Remove stale imports, methods, comments, or duplicated tests before code review.

## Validation

Run changed test files immediately when written, then finish with:

```bash
./vendor/bin/phpunit --no-progress tests/Container/FrameworkTransientTest.php
./vendor/bin/phpunit --no-progress tests/Container/ContainerTest.php
./vendor/bin/phpunit --no-progress tests/Container/CoroutineSafetyTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportCollectionTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportLazyCollectionTest.php
./vendor/bin/phpunit --no-progress tests/ApiClient/PendingRequestTest.php
./vendor/bin/phpunit --no-progress tests/Support/DefaultProvidersTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportViewErrorBagTest.php
./vendor/bin/phpunit --no-progress tests/Pipeline/CoroutineIsolationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Http/ResponseBindingTest.php
./vendor/bin/phpunit --no-progress tests/Queue/LaravelInteropTest.php
./vendor/bin/phpunit --no-progress tests/Console/Scheduling/ScheduleTest.php
```

Run `./vendor/bin/phpstan analyse --configuration=phpstan.types.neon.dist` immediately after changing `types/Database/Eloquent/Collection.php`; the `types/` fixtures are not PHPUnit tests.

## Completion criteria

- All eleven caller-owned hierarchies resolve fresh when unbound. The nine mutable classes have representative mutation isolation, the two dates have clock freshness coverage, and `ContainerTest::testTransientLifetimeIsInheritedBySubclasses()` covers inheritance by application subclasses.
- Explicit bindings still override `Transient`, covered by `ContainerTest::testTransientClassCanBeExplicitlySingletoned()` and its neighboring lifetime tests; nullable/default constructor dependencies remain defaults, covered by `ContainerTest::testResolutionOfClassWithDefaultParameters()`.
- `makeTransient()` ignores and never mutates an ordinary unbound auto-singleton while retaining explicit binding lifetimes, canonical aliases, callbacks, extenders, nested dependency lifetimes, resolved tracking, circular checks, and shared-resolution isolation.
- Concrete Pipeline and Response bindings are gone; the `'pipeline'` and `'request'` bindings and Symfony response alias retain their intended behavior.
- Queue handler freshness remains explicit. Ordinary class-string jobs are constructed through the full container resolution pipeline on every firing without reading or publishing an implicit auto-singleton. Explicit lifetimes remain authoritative, and an explicitly shared class-string job is dispatched as the registered instance without scheduler-owned cloning or rejection. Supplied objects are dispatched as provided, with no reflection, cloning, cloneability guard, catch, or fallback.
- All public collection methods have exact native return types. The thirteen transformation methods are correct across the interface, shared trait, support implementations, and Eloquent overrides; Eloquent `find()` and `findOrFail()` express their mixed and model-or-collection boundaries. False inherited and conditional PHPDoc returns are corrected, existing valid generic annotations remain precise, and the PHPStan fixtures prove the load-bearing hierarchy paths.
- Optional collection filters use strict null guards consistently across eager and lazy implementations; an empty non-callable string can no longer silently disable filtering or produce different eager and lazy results.
- `DefaultProviders` preserves explicit empty lists, `merge()` is pure, and `except()` uses the precise existing collection primitive.
- The message-bag contract expresses the string conversion required by `ViewErrorBag`; the complete upstream suite plus empty fallback coverage passes, numeric constructor keys reach formatting without a type error, and all affected implementation PHPDocs describe their real array-key shapes.
- Contributor and user documentation describe the same ownership-based lifetime model and complete built-in family list.
- `makeTransient()` is the only added container surface. No registry, cache eviction, reflection heuristic, alternate recipe, runtime exception, or second lifetime mechanism remains.
- No rejected candidate is marked, and no marker-only test, stale comment, unused import, dead method, or redundant binding remains anywhere in the change set.
- Targeted formatting, static analysis, and focused tests pass.
