# Hypervel Attributes (PR 1)

Branch: `feature/hypervel-attributes` (from `0.4`). This PR is complete on its own: it adds the `hypervel/attributes` package, replaces the docblock lifetime tags (`Boot-only.`, `Tests only.`, `Boot or tests only.`) with attributes, adds `#[Unsupported]` markers, gives each container its own coroutine context keys (the container markers depend on it), and switches the existing PHPStan worker-state analysis to the attributes, so current analysis keeps working. The new call-site rules, the facade lifetime fix and the other PHPStan work are PR 2 (`docs/plans/2026-11-12-hypervel-phpstan-rules.md`), which builds on this PR after it merges.

Owner-approved: the new package, and the `AGENTS.md` wording below (agreed with the peer reviewer). After implementation and signoff, show the owner each `AGENTS.md` change before and after in chat.

## Why

- Docblock tags are prose. The worker-state helper matches them with a regex (`WorkerStateHelper::LIFETIME_TAG_PATTERN`, which also matches conditional prose such as "Boot-only when setting"), and PR 2's call-site rules need exact, machine-readable metadata, including the conditions under which a call is restricted.
- PHPStan's `AttributeReflectionFactory::fromNameAndArgumentExpressions()` drops an attribute that has arguments when the attribute class isn't installed. The attributes must therefore live in a real package that every using package declares (AGENTS "Declare only real dependencies"). That's why they don't go in `hypervel/support`, which several marked packages (`collections`, `container`, `contracts`, `core`, `coroutine`, `macroable`, `prompts`) don't require.
- Laravel precedent: `illuminate/conditionable` and `illuminate/macroable` are separate tiny packages sharing the `Illuminate\Support` namespace. `hypervel/conditionable` and `hypervel/macroable` already follow this with `Hypervel\Support\`.

## 1. The `hypervel/attributes` package

`src/attributes/`:

- `composer.json`: copy `src/conditionable/composer.json` and change the name to `hypervel/attributes`, the description to "The Hypervel Attributes package.", and the keywords to `php`, `attributes`, `hypervel`. Keep `"php": "^8.4"` as the only requirement, and keep PSR-4 `"Hypervel\\Support\\": "src/"`.
- `LICENSE.md`: `cp src/api-client/LICENSE.md` (a Hypervel-original MIT license).
- `README.md`:
  ```
  Attributes for Hypervel
  ===

  [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/attributes)

  Documentation: https://hypervel.org/docs/static-analysis#method-attributes
  ```
- `src/Attributes/BootOnly.php`, `TestsOnly.php` and `BootOrTestsOnly.php` are identical apart from the class name. They stay open (AGENTS "Don't make classes final by default"; no invariant needs `final`), with no class docblock:
  ```php
  <?php

  declare(strict_types=1);

  namespace Hypervel\Support\Attributes;

  use Attribute;

  #[Attribute(Attribute::TARGET_METHOD)]
  class BootOnly
  {
      /**
       * Create a new attribute instance.
       *
       * @param null|string $whenNotDefault the parameter whose values other than its declared default make the call restricted
       * @param null|string $whenProvided the parameter whose presence in the call makes it restricted
       * @param bool $whenShared whether the restriction applies only to a worker-shared receiver
       */
      public function __construct(
          public ?string $whenNotDefault = null,
          public ?string $whenProvided = null,
          public bool $whenShared = false,
      ) {
      }
  }
  ```
- `src/Attributes/Unsupported.php`: an open `#[Attribute(Attribute::TARGET_METHOD)]` class whose constructor takes `public ?string $message = null` (`@param null|string $message guidance for callers`). The constructor is required: PHPStan drops an attribute with arguments when its class has no constructor.

Semantics. PR 2 enforces them at call sites; in this PR the worker-state analysis only uses the markers for the body exemption in section 4.

- **`whenNotDefault: 'name'`:** a call is restricted when it passes that parameter (positionally or by name) a value that may differ from its declared default. An omitted argument, or one that is definitely the default, is a read.
- **`whenProvided: 'name'`:** a call is restricted when it passes that parameter at all, whatever the value. For methods that treat an explicit default differently from an omitted argument (`Passport::rememberConsentFor(null)` clears the duration).
- **`whenNotDefault` and `whenProvided` are mutually exclusive.** Each names a parameter with a default.
- **`whenShared: true`:** the method changes only its own instance's state, so the restriction applies only when the receiver is the worker-shared instance. Instance methods only.
- **A parameter condition and `whenShared` together:** both must hold.

Wiring:

- Root `composer.json`:
  - add `"hypervel/attributes": "self.version"` to `replace`;
  - add `"src/attributes/src/"` to the `Hypervel\\Support\\` autoload list, keeping it alphabetical;
  - run `composer dump-autoload`.
- `src/framework/composer.json`: add `"hypervel/attributes": "^0.4"`, sorted. Direct edit, since sub-package manifests have no lockfile.
- Every package that places one of the attributes in its `src` declares `"hypervel/attributes": "^0.4"`, sorted. That's every package with converted markers (69 packages hold tags today) plus every package receiving `#[Unsupported]`.
- Packages with a `tests/{Package}/PackageMetadataTest.php` that lists all direct dependencies (for example Auth) get `'hypervel/attributes'` added to the list, so the declaration stays guarded. The `tests/Contracts` and `tests/Support` metadata tests list only selected third-party dependencies and stay unchanged.
- `hypervel/phpstan` is the one exception: it only compares attribute names through `::class` constants, which never load the classes. Its existing precedent is the same: framework classes referenced by name aren't required.
- `bin/split.sh` and the skeleton CI's `.github/scripts/use-local-packages.php` discover `src/*` automatically, so nothing else changes. The owner creates the `hypervel/attributes` split repository and Packagist entry; record that in `docs/todo.md` (section 6).

## 2. Converting the lifetime tags

**Inventory.** There are 1,051 tag lines in 341 files:
- 1,015 unconditional `* Tag.` lines, 602 of them on instance methods;
- 36 conditional lines, listed below.

Write a throwaway php-parser script in the scratchpad (not committed). It produces candidates for source review; it doesn't decide markers. For each `ClassMethod` whose docblock has a tag line, it lists:
- the class;
- whether the method is static;
- the tag and its explanation;
- a body classification, following calls on `$this`/`self`/`static` to methods in the same class:
  - **external:** writes a static property, writes through another object or service, or calls another service's mutator;
  - **instance-only:** writes only `$this` properties, including array dimensions;
  - **none:** no visible mutation.

  `??=` fills and by-reference writes (`array_unshift()`, `Arr::set()`) are writes; only memoization verified as stable in the source is left out of the restriction decision. The container's coroutine-local resolution-state writes are ignored. The script stays advisory.

It also reports tag lines on anything other than a method docblock.

**Conversion rules.** The final marker must match the method's supported contract, checked in the source: delegates, parent and trait methods, nested objects, callbacks and references can change who owns the state a method writes.

- **Attribute.** The tag selects the attribute: `#[BootOnly]`, `#[TestsOnly]` or `#[BootOrTestsOnly]`. Place it on the line directly above the method signature, after any existing attributes, and import it.
- **Docblock.** Remove the tag phrase and keep the explanation sentence(s) naming the runtime harm, reflowing the paragraph to its original width. A paragraph left empty is removed together with its blank separator line. `src/phpstan/stubs/common/Config/Repository.stub` repeats source docblocks, so its tags go too.
- **instance-only:** add `whenShared: true`. Example: `Http\Client\Factory::globalOptions()`/`globalMiddleware()` write only the factory's own properties, and `tests/Http/HttpClientTest.php:4405` uses an independent `(new Factory)->globalOptions(...)`. Adjust prose that claims a worker-lifetime effect for every instance, e.g. "persists on the shared factory for the worker lifetime".
- **external:** stays unconditional, even on instance methods. Examples: `Router::resourceParameters()` writes `ResourceRegistrar`'s statics, and manager purges close shared pools. These instance methods are unconditional although the script sees only `$this` writes, because they change state other objects own: `DatabaseManager::purge()`/`whenQueryingForLongerThan()`; the database, Redis and object-pool `PoolManager` purges; `RedisManager::purge()`/`enableEvents()`/`disableEvents()`; `ConfigMutationTracker::observe()`/`applyAndRecord()`/`replay()`; `MercureBroadcaster::setHub()`; Mcp `Registrar::web()`; `PoolRecycler::start()`/`stop()`; `QueueManager::setApplication()`; `Router::setRoutes()`/`setCompiledRoutes()`/`compileAndWarm()`; `Route::flushController()`; `BladeCompiler::anonymousComponentPath()`; Sentry `HttpClientIntegration::onBoot()`.
- **none:** review it, and either correct the tag (the method isn't a mutator, so drop the attribute and the tag) or document the delegated effect.
- **Safe at runtime:** a tagged method whose runtime use is supported, such as clearing a cache that is rebuilt on demand, gets no attribute. Its docblock keeps the effect sentences without the tag (see the view and pooled-manager rows below). Examples: the routing reflection, signature, enum and middleware-sort cache flushes, `ControllerDispatcher::warmReflection()` (the same fill dispatch does lazily), `HasAttributes::flushCasterCache()` and `AspectManager::clear()` (chains recompile from unchanged `AspectCollector` rules and resolve aspects at invocation).
- **Condition not expressible:** when the restriction depends on something the bounded arguments can't name, such as which name a call passes, the method gets no attribute and keeps accurate prose. Never invent an unconditional restriction. `Process\Factory::recordIfRecording()` stays unmarked: it calls `record()` only after `fake()`. `ProcessManager::setRunning()` stays unmarked: server startup and SIGTERM shutdown both own it.
- **Reads:** a getter returning a shared object gets no attribute. `SaloonManager::middleware()` only returns the pipeline; its prose says that changing the returned pipeline affects later requests.
- **Forwarders:** a method forwarding to a static or application-global target is unconditional. One forwarding to a nested object gets `whenShared: true` only where the source shows the receiver owns that object. `View\Factory` owns its finder: `view.finder` is bound transient, so each factory builds its own, and `__clone()` clones it (`Mail\Markdown` relies on that for render-time `replaceNamespace()`). Holding an object in a property doesn't prove ownership. Otherwise the target's lifetime decides, so the method gets no attribute and its docblock says so (`NullDispatcher`, which `HasEvents` wraps around the shared dispatcher, `EventFake`, `BusFake`, the session decorator, the Sentry filesystem decorator), e.g. "This changes the wrapped dispatcher, which is usually the application's worker-shared dispatcher." `DatabaseManager::extend()`/`forgetExtension()` are unconditional: pools resolve `db.factory` from the container, so extensions only work on the application's factory. A constructor-injected target is unknown even when the application binds it as a singleton, since independently built instances are supported: `Translator`'s loader, `HeadManager`'s registry, the event dispatcher of the View factory, `Log\Logger`, the context `Repository` and `Router::matched()`, and the handler of `Configuration\Exceptions`. Those forwarders stay unmarked; the targets' own mutators carry their markers (`FileLoader`/`Loader`, `TagRegistry`, `Dispatcher`, `Handler`). A target verified as worker-shared and fetched from the application or a static, in the method or the constructor, stays restricted (`BladeCompiler::anonymousComponentPath()`, `Schedule::useCache()`).
- **Direct wrappers:** a method that directly calls a marked method gets the same marker and its own harm sentence, whether or not it was tagged and whatever its visibility. Classify it by its lifecycle contract; a runtime guard that already throws is no reason to omit the marker. Examples: `Lottery::determineResultsNormally()`, the facade `fake*()` methods, `Facade::createFreshMockInstance()`, `Facades\Queue::popUsing()`, `ServiceProvider::commands()`/`aspects()`/`classMap()`/`mergeConfigFrom()`/`replaceConfigRecursivelyFrom()`, `Socialite::fake()`, `AuthManager::viaRequest()`, `GrpcRouter::syncMiddlewareFrom()`, `AbstractProcess::bind()`, `Application::register()`/`configure()`, and every `ApplicationBuilder` and `Configuration\Middleware` method that registers on the application or a middleware's static settings. Constructors, startup/shutdown lifecycle hooks (provider `register()`/`boot()`, application bootstrappers, server boot listeners and shutdown handlers), their protected/private implementation helpers (provider `register*()` helpers, Horizon's `authorization()`/`gate()`/`configure()`) and lifecycle-hook bases (Sentry `Feature::onBoot()`, Telescope `Watcher::register()`) stay unmarked unless tagged. Test-case methods and traits, PHPUnit extensions and subscribers, and Testbench only run in tests and also stay unmarked unless tagged. This does not exempt request/job/operation listeners or generally callable registration APIs.
- **Supported lazy registration:** a runtime path that registers stable definitions on first use isn't a wrapper and stays unmarked: `Container::getConcreteBindingFromAttributes()` (`#[Bind]` attributes), the `Date` facade's default install (replaced by PR 2's `date` binding), Pennant's `resolveFeature()` → `ensureDynamicFeatureIsDefined()` → `define()` (`pennant.md` promises class features need no boot registration), `MercureBroadcaster::exemptCookieFromEncryption()` (run by the lazily built broadcaster's constructor; `EncryptCookies::except()` deduplicates the configured cookie name), Eloquent model and trait boot hooks (`booted()`, `bootHasGlobalScopes()`, `bootSoftDeletes()`, Scout's `bootSearchable()`), which register scopes, observers and macros once per model class on first boot, and `Application::configurationIsCached()`/`routesAreCached()`/`eventsAreCached()`, which bind a stable flag on first call. Never split out a duplicate internal write to get around a marker. At the Pennant checkpoint, separate stable lazy class registration from replacing a resolver, which does affect later requests; if the bounded conditions can't describe the restriction on `define()`, leave it unmarked with accurate prose.
- **Fresh objects:** a `whenShared` setter that framework code also calls on an object it has just built stays marked; PR 2's receiver analysis treats such locals as non-shared. Examples: `CreatesUserProviders::createEloquentProvider()` → `EloquentUserProvider::enableCache()` (its worker-wide invalidation registration is deduplicated, so `enableCache()`/`setModel()` are `whenShared`), `CacheManager::createRedisDriver()` → `RedisStore::setTagMode()`/`setLockConnection()`, `FilesystemManager`'s lazy disk creation → `shouldServeSignedUrls()`/`servingRoute()`. Pool proxies set `whenShared` state on exclusively borrowed objects (`BroadcastPoolProxy`/`QueuePoolProxy::configureBorrowed()`, the queue proxy's release callback); those object parameters carry no shared-receiver evidence. `Connection`'s dispatcher and transaction-manager setters and unsetters are `#[TestsOnly(whenShared: true)]`: a reusable physical connection keeps them for later borrowers (`resetForPool()` doesn't reset them), while the manager and pool set them on connections they own (`PooledConnection::newLease()`/`attachLease()`). `Redis\Console\BenchmarkCommand::runSuite()` changing the cached store's tag mode, and the Horizon and watcher restart strategies calling `DotenvManager::reload()` in their supervising CLI process, are PR 2 context-test cases, not reasons for a console exemption.
- **Properties:** attributes target methods only. The property tags (Queue `Worker` ×7, `Http\Client\Response::$defaultJsonDecodingFlags`, Sentry ×2) lose the tag and keep corrected prose, e.g. "Setting this mutates…".
- **Contracts and their implementations.** Attributes aren't inherited, so check them as a set (for example `Config\Repository::set()`/`prepend()`/`push()`, `Http\Kernel` middleware mutators, `Translator::setFallback()`, `ObjectPool\Factory::purgeAll()`, `Recycler::start()`/`stop()`, `CoroutineInterface::set()`/`getContextFor()`, `Image\Driver::transformUsing()`, `ModelIdentifier::useMorphMap()`):
  - every implementation of a marked contract method carries the same attribute unless its own behavior differs (the queue fakes' no-op `setConnectionName()` stays unmarked). Include inherited implementations: a parent that doesn't implement the interface can supply the method (`Queue::setConnectionName()` for `Contracts\Queue\Queue`, `Container::resolveEnvironmentUsing()` for `Contracts\Foundation\Application`);
  - a marked implementation whose contract method is unmarked is reviewed the same way, and the contract method gets the implementation's attribute (for example `ServerInterface::init()` → `#[BootOnly]`, Foundation `Application::flush()` → `#[TestsOnly]`, `Contracts\View\Factory::addNamespace()`/`replaceNamespace()` → `#[BootOnly(whenShared: true)]`);
  - a contract method that gains an attribute gets no new prose; the implementation owns the harm explanation, unless the restriction can't be understood from the signature and attribute. Explanations a contract already had stay.
- **Constructors.** Tagged constructors (only the test fixture today) get the attribute the same way.
- **Bulk edits.** Do the mechanical conversion with the script, as text edits from the bottom of each file up, then review the complete diff (CLAUDE.md bulk-edit rule; this plan is the agreed replacement pattern). Hand-edit the conditional methods below.

**Conditional tags (36):**

| Method(s) | Result |
|---|---|
| `Relation::morphMap(?array $map = null, bool $merge = true)` | `#[BootOnly(whenNotDefault: 'map')]` |
| `ResourceRegistrar::verbs(array $verbs = [])`, `Router::resourceVerbs(array $verbs = [])` | `#[BootOnly(whenNotDefault: 'verbs')]` (`Router` writes `ResourceRegistrar`'s statics, so there's no `whenShared`) |
| Fortify `Features::twoFactorAuthentication()`, `passkeys()` (`array $options = []`) | `#[BootOnly(whenNotDefault: 'options')]` |
| `Passport::defaultScopes()` | `#[BootOnly(whenNotDefault: 'scopes')]` |
| `Passport::tokensExpireIn()`, `refreshTokensExpireIn()`, `personalAccessTokensExpireIn()`, `clientCredentialsTokensExpireIn()`, `dpopTokensExpireIn()` | `#[BootOnly(whenNotDefault: 'date')]` |
| `Passport::cookie()` | `#[BootOnly(whenNotDefault: 'cookie')]` |
| `Passport::rememberConsentFor()` | `#[BootOnly(whenProvided: 'duration')]`. It branches on `func_num_args()`, so an explicit `null` clears the duration; no runtime change. |
| `Prompts\Concerns\Themes::theme()` | `#[BootOnly(whenNotDefault: 'name')]` |
| Validation `Email::defaults()`, `Password::defaults()`, `File::defaults()` | `#[BootOnly(whenNotDefault: 'callback')]` |
| `Str::freezeUuids()`, `Str::freezeUlids()` | `#[TestsOnly]`. Replace "Tests only unless a callback is supplied" prose: both forms install a worker-wide factory (the callback form only until it returns) and aren't coroutine-safe. No runtime caller exists. |
| `View\Component::flushCache()`, `CompilerEngine::forgetCompiledOrNotExpired()` | No attribute: clearing is safe at runtime (`view:clear` does it), since the next render or compile check rebuilds the cache. Replace the tag sentence; keep the effect sentence and "The view:clear command owns runtime invalidation." |
| `View\Factory::flushFinderCache()`, `FileViewFinder::flush()` (unconditional tags) | No attribute: `find()` rebuilds the cleared `$views` from unchanged paths, hints and extensions. Remove the stale warning. `ViewFinderInterface` gets `#[BootOnly(whenShared: true)]` on its other six methods and leaves `flush()` unmarked; `Contracts\View\Factory::flushFinderCache()` stays unmarked. |
| `View\Factory::addLocation()`, `prependLocation()`, `addNamespace()`, `prependNamespace()`, `replaceNamespace()` (unconditional tags) | `#[BootOnly(whenShared: true)]`: they change only the factory's own finder (see Forwarders). Prose names "the shared factory's finder". `addExtension()` gets no attribute: it changes the factory's own state, and with a resolver also the engine resolver, which clones share, so the condition can't be expressed. |
| `AiManager::forgetInstance()`, `AiManager::purge()` | No attribute: forgetting an on-demand provider only clears `CoroutineContext`, while forgetting a configured one affects the worker, and the marker can't express which name a call passes. Replace the tag sentence with "Forgetting a configured provider affects every request on the worker; on-demand providers belong to the current operation." The inherited `MultipleInstanceManager` methods keep their attribute. |
| `Ai\Providers\Concerns\Has{Text,Image,Audio,Transcription,Embedding,Reranking,File,Store,Classification}Gateway::use*Gateway()` | `#[BootOrTestsOnly(whenShared: true)]`: configured providers are shared, while providers built per request are not. |
| `BroadcastManager::purge()`, `QueueManager::purge()`, `MailManager::purge()`, `FilesystemManager::purge()` | No attribute. Their documented contract (`queues.md:189`, `mail.md:362`, `filesystem.md:335`) is safe runtime recovery: other users transparently acquire a fresh pool. Replace the tag sentence with "Use this to recover broken pooled resources." and keep the effect sentences. `DatabaseManager`, `RedisManager`, `CacheManager` and `MultipleInstanceManager` purges keep `#[BootOrTestsOnly]`, since their documented effects on concurrent coroutines are unsafe. |

**Container context keys.** The container markers rely on each container owning its coroutine context entries. Scoped instances (`'__container.scoped.' . $abstract`) and resolution state (`'__container.resolution_state'`) used fixed keys, so a second container returned, reported, replaced and cleared the first's scoped instances, and resolving through another container inside a binding threw `CircularDependencyException`. Fix in `Container`:
- `contextKey(string $name)` returns `$this->contextKeyPrefix ??= '__container.' . ++self::$contextKeyPrefixCount . '.'` plus the name: `'scoped.' . $abstract` or `'resolution_state'`. The two key constants go. The counter is never reset, including by `flushState()`, so prefixes are never reused. Not `spl_object_id()`, which is reused after GC, and no registry or shared map. Values stay direct context entries, so fork copying (`ReplicableContext`/`NonCopyableContext`) is unchanged.
- `__clone()`: the clone takes a new prefix and copies the current coroutine's resolved scoped instances, found through `$scopedInstances`, into its own entries, like its shallow-copied singleton arrays. Its resolution state starts empty.
- Tests: `ContainerTest` covers two containers isolated for `make()`/`resolvedScoped()`/`isScopedInstance()`; invalidation through `forgetInstance()`, `forgetInstances()`, `forgetScopedInstances()`, `bind()`, `instance()` and `extend()` against a sibling's populated entry; nested cross-container resolution; a clone's starting instances, independent invalidation, and a clone taken before resolving. `CoroutineSafetyTest` covers a forked child inheriting scoped instances. The inspection stubs use `getResolutionState()`, and the transient-lifetime test uses `resolvedScoped()`.
- Markers: the binding and instance-cache group keeps `whenShared: true`; `forgetScopedInstances()` becomes `#[BootOrTestsOnly(whenShared: true)]`.

**CacheManager memo keys.** `memo()` stored its repository at `'__cache.memoized.' . $driver`, so independent managers with the same store name shared one memoized repository and `forgetDriver()` evicted another manager's memo. Each manager now takes a prefix lazily from a never-reset static sequence (lazy, so constructor-less partial mocks still work), used by `memo()` and `forgetDriver()`. Laravel binds the memo on the manager's container; per-manager keys follow ownership, since the memo wraps that manager's stores. `forgetDriver()` keeps `whenShared: true`. `CacheManagerTest::testMemoizedRepositoriesBelongToTheirManager` covers values and `forgetDriver()` isolation.

**Redis pinned-connection keys.** `RedisProxy` kept pinned connections and deferred-release owners at `'__redis.connection.' . $pool` and `'__redis.deferred_release_owner.' . $pool`, so proxies on different `PoolManager`s with the same connection name shared one pin, and releasing or discarding through one ended the other's. The internal `PoolManager::contextKey()` now builds both keys from a lazy, never-reset per-manager prefix, with role-prefixed names (`connection.<pool>`, `deferred_release_owner.<pool>`) so the two can't collide. Each proxy caches its two keys. Proxies for the same manager and name still share a pin. `CONNECTION_CONTEXT_PREFIX` goes, and tests read keys through the proxy. `RedisProxyTest` covers separate pins and discard isolation across managers, shared pins on one manager, and deferred cleanup releasing both managers' connections.

**Guard context keys.** All six guards (`SessionGuard`, `RequestGuard`, auth `TokenGuard`, `JwtGuard`, `SanctumGuard`, Passport `TokenGuard`) kept per-request users, credential caches and state at `"__auth.guards.{$name}…"`. A guard created later with the same name, such as after `AuthManager::forgetGuards()`, therefore adopted the previous guard's explicit user and client and its unseeded caches, including entries the test HTTP sync copies back.
- `GuardHelpers::contextKey()` now builds every guard key from a lazy prefix: the using class plus that class's own never-reset sequence. Trait statics are separate per using class, so the class keeps prefixes unique.
- `SessionGuard` keeps its readonly keys.
- The `SessionGuard`/`JwtGuard` `getContextStateKey()` wrappers and `JwtGuard::GUARD_CONTEXT_KEY_PREFIX` go.
- Token hashing, session keys, remember cookies, logout and persisted credentials are unchanged, so a replacement guard still reauthenticates from a valid session.
- Tests:
  - each guard suite covers a same-name replacement: explicit users, Passport's explicit client, the request and bearer caches, and JWT token state;
  - `InteractsWithAuthenticationTest` covers `forgetGuards()` dropping an `actingAs()` user through the HTTP sync, and reloading the user from a valid session.

**ArrayStore cloning.** `Cache\Repository::__clone()` clones its store, but `ArrayStore` currently copies its context keys unchanged, so writes through the clone change the original's values and locks. Add `ArrayStore::__clone()` to allocate fresh keys from the existing sequence and shallow-copy the current context's storage and lock records. Keep the readonly key properties; PHP supports reinitializing them during cloning. Cover initial values and independent subsequent value and lock mutations through a cloned repository. Check other context-key holders only where their supported clone paths require the same isolation.

**Check.** After converting, `grep -rnE '\* (Boot-only|Tests only|Boot or tests only)\b' src tests` returns nothing. Historical `docs/plans/*` are immutable and keep their text.

## 3. `#[Unsupported]` markers

**Criteria.** Mark a retained public method that rejects every call and is unsupported for every instance of its declaring class.

Excluded:
- base defaults that supporting implementations override (Query and Schema grammar `compile*` defaults, `Schema\Builder::dropAll*()`, the `Connection` driver hooks, `Lock::refresh()`/`getRemainingLifetime()`);
- extension hooks (`Prunable::prunable()`, `MassPrunable::prunable()`);
- deliberate control-flow throws (`ResponseTrait::throwResponse()`, `SocialiteManager::getDefaultDriver()`);
- conditional rejections;
- test fakes (`FakeTransporter::run()`);
- `offsetSet()`/`offsetUnset()` on read-only `ArrayAccess`, which is reached through `[]=` syntax.

Engine `Channel::hasProducers()`/`hasConsumers()`/`isReadable()`/`isWritable()` are excluded by owner direction (section 6 TODO).

**Mark these 72 methods.** Each keeps its existing throw and message; check each body is still an unconditional throw. Also mark retained public methods that always reach one of them, such as `Engine\Http\Stream::rewind()` (→ `seek(0)`), unless a supported override resolves to its own declaration. Where a marked PSR-7 method lacks native parameter types, use the PSR-7 v2 types (`Stream::seek(int $offset, int $whence = SEEK_SET)`); check `CurlStreamingBody::seek()` the same way.

**Messages.** Pass guidance as the message when source or documentation establishes a supported alternative or behavior: one short imperative sentence, without repeating that the method is unsupported, reusing the exception's guidance where it's accurate. Never invent a replacement; leave the marker bare otherwise (engine limits, provider capabilities, stream seeking, session bags, scoped filesystem internals). Established alternatives include returning responses rather than calling `Response::send*()` (`responses.md`), `request()` or injection rather than `Request::createFromGlobals()` (`requests.md`), and the typed constructors of the Data validation attributes. Raw cache reads are `@internal`, so `AnyModeTaggedCache` raw methods point at `Cache::get()`/`many()`. Remaining packages:
- grpc `HealthService::watch()`: "Use check() to poll the health status."
- Saloon `CursorPaginator::pool()`: "Iterate over the paginator sequentially; each cursor depends on the previous response."
- Prompts `prompt()`: `Spinner` "Use spin() instead.", `Progress` "Use map(), or start(), advance() and finish().", `Task` "Use run() instead.", `Stream` "Use append() and close() instead."

- `Session\SymfonySessionDecorator`: `registerBag()`, `getBag()`, `getMetadataBag()`
- `Cache\AnyModeTaggedCache`: `get()`, `getRaw()`, `getAuthoritativeRaw()`, `many()`, `manyRaw()`, `has()`, `pull()`, `forget()`, `touch()`
- `Database\DatabaseManager`: `build()`, `connectUsing()`. `Database\Schema\ColumnDefinition::whereNotNull()`. `Query\Grammars\MariaDbGrammar::compileJoinLateral()`. `Schema\Grammars\SQLiteGrammar`: `compileSpatialIndex()`, `compileDropSpatialIndex()`
- `Filesystem\Concerns\InteractsWithPooledFilesystem`: `getDriver()`, `getAdapter()`, `getClient()`. `Filesystem\ScopedFilesystemProxy`: `getDriver()`, `getAdapter()`, `getClient()`, `serveUsing()`, `buildTemporaryUrlsUsing()`, `buildTemporaryUploadUrlsUsing()`. Keep one truthful shared `getClient()` message; don't move or duplicate the method.
- Scout `AlgoliaEngine`, `TypesenseEngine`, `TurbopufferEngine`: `createIndex()`
- `Grpc\Health\HealthService::watch()`. Saloon `Pagination\CursorPaginator::pool()`. `Engine\Http\Stream`: `tell()`, `seek()`. `ConnectionPool\KeepaliveConnection::getConnection()`
- `Support\Str::flushCache()`
- Prompts `Stream`, `Progress`, `Spinner`, `Task`: `prompt()`
- `Console\Scheduling\CallbackEvent::runInBackground()`. ApiClient `PendingRequest::setClient()`. The HTTP client's `PendingRequest::setClient()` is supported and stays unmarked.
- `Http\Response`: `send()`, `sendHeaders()`, `sendContent()`. `Http\Request::createFromGlobals()`. `Http\Resources\DelegatesToResource`: `resolveRouteBinding()`, `resolveChildRouteBinding()`. `Http\Resources\JsonApi\JsonApiResource`: `wrap()`, `withoutWrapping()`. `Http\Client\CurlStreamingBody`: `seek()`, `write()`
- Mcp `Server\Content\ResourceLink::toResource()`, `Notification::toTool()`/`toPrompt()`/`toResource()`, `Blob::toTool()`/`toPrompt()`
- Ai `Gateway\Anthropic\AnthropicGateway` and `Gateway\Xai\XaiGateway`: `generateImage()`, `generateAudio()`, `generateTranscription()`, `generateEmbeddings()`. `Files\S3Document::content()`, `Files\ProviderDocument::content()`
- Data `Concerns\PaginatedDataCollectable::withoutWrapping()`. `Attributes\Validation\AnyOf::create()`, `Can::create()`, `Password::create()`

## 4. PHPStan: read attributes instead of tags

`src/phpstan/src/Rules/Coroutine/WorkerStateHelper.php`:
- Remove `LIFETIME_TAG_PATTERN` and `hasLifetimeTag()`.
- Add the attribute list, imported from `Hypervel\Support\Attributes`, and the method:
  ```php
  /**
   * The attributes that restrict a method to boot time or tests.
   */
  private const array LIFETIME_ATTRIBUTES = [BootOnly::class, TestsOnly::class, BootOrTestsOnly::class];

  /**
   * Determine whether the function is marked as running only while the application boots or in tests.
   */
  public function hasLifetimeAttribute(FunctionReflection|ExtendedMethodReflection $function): bool
  {
      return array_any(
          $function->getAttributes(),
          static fn (AttributeReflection $attribute): bool => in_array($attribute->getName(), self::LIFETIME_ATTRIBUTES, true),
      );
  }
  ```
- `getFunctionFacts()` calls `hasLifetimeAttribute($function)` in place of the docblock check, and its docblock says "marked" instead of "documented".
- The exemption deliberately ignores the attribute arguments. Conditional methods' writes are their restricted setter writes, which the inventory verifies, as the tag exemption did before.
- `Scope::getFunction()` returns `?PhpFunctionFromParserNodeReflection`, which implements `FunctionReflection`; its method subclass implements `ExtendedMethodReflection`. Both declare `getAttributes()`.

`src/phpstan/src/Collectors/WorkerStateClassCollector.php:66`: a constructor marked with a lifetime attribute keeps its parameters by design. Read it through `$class->getNativeMethod('__construct')->getAttributes()` when the class declares its own constructor (the AST `getMethod('__construct')` check already establishes that), using `hasLifetimeAttribute()`. Update the comment to "A constructor marked as boot-time or test-only keeps its parameters by design."

Tests, in `tests/PHPStan/Rules/Coroutine/data/worker-state.php` and `WorkerStateRuleTest`:
- Convert the fixture tags to attributes, keeping their expected results:
  - `setRequestForTesting()` (line 105) → `#[TestsOnly]`;
  - `TestingRequestHolder::__construct()` (line 205) → `#[TestsOnly]`;
  - `configure()` (line 727) → `#[BootOnly]`.
- Add a `#[TestsOnly(whenNotDefault: 'request')]` method on the fixture's `SharedService` that stores its `?Request $request = null` parameter in `$this->request`. It isn't reported, while the existing unmarked `remember()` write to the same property is reported as `hypervel.workerSharedState`. This proves attributes with arguments are read. During implementation, confirm that removing the attribute makes the diagnostic appear.
- Run `vendor/bin/phpunit -c phpunit.phpstan.xml.dist --no-progress tests/PHPStan/Rules/Coroutine/WorkerStateRuleTest.php` immediately.

## 5. Guidance and documentation

`AGENTS.md` (agreed wording):

1. Replace the "Document worker-lifetime mutators" bullet, lines 392–397, with:
   > - **Mark restricted worker-state mutators** — when adding or changing a public mutator intended only for startup or tests, use an attribute from `Hypervel\Support\Attributes`:
   >   - `#[BootOnly]` — startup configuration
   >   - `#[TestsOnly]` — test fakes, swaps, and overrides
   >   - `#[BootOrTestsOnly]` — configuration or cleanup allowed in either context
   >
   >   Use `whenNotDefault: 'parameter'` only when the restriction applies to values differing from that parameter's declared default, `whenProvided: 'parameter'` when it applies whenever the argument is passed, and `whenShared: true` when it applies only to a worker-shared receiver. Keep a concise docblock explaining the runtime harm. If an API is intended for runtime use, fix unsafe shared-state behavior through a peer second opinion rather than restricting it to boot or tests.
2. Replace the `flushState()` paragraph at line 577 ("Do not add `Boot-only.`, …") with:
   > Leave `flushState()` unmarked; PHPStan recognizes this cleanup hook by name.
3. Insert after the "Fail fast" Code conventions bullet (line 217):
   > - **Mark intentionally unsupported methods** — when a public method must be retained but intentionally rejects every call, mark it with `#[Unsupported]` from `Hypervel\Support\Attributes`, passing the supported alternative as its message when one exists. Exclude base defaults that supporting implementations override, extension hooks, deliberate control-flow throws, and conditional rejections.

`src/docs/static-analysis.md`, in Laravel-docs prose:
- New `### Method Attributes` section (anchor `method-attributes`) at the end of "What the Analysis Knows", after "Custom Builders and Collections":
  - Mark a public method that changes worker-wide state and should only run while the application boots or in tests with `#[BootOnly]`, `#[TestsOnly]` or `#[BootOrTestsOnly]` from `Hypervel\Support\Attributes`, keeping a short docblock explaining the harm. The worker state checks don't report writes in these methods. Example: the current Worker State `setRequestForTesting()` example, with `#[TestsOnly]` above it and the docblock keeping only the title and the harm sentence.
  - When only some calls are restricted, the attribute says which: `whenNotDefault` restricts calls passing the named parameter a value other than its default, so the method can still be read at runtime; `whenProvided` restricts any call passing it; `whenShared` restricts calls only on a worker-shared receiver, the instance the container keeps for the whole worker (not a scoped or fresh one), for methods that change just their own object. Example signatures: `#[BootOnly(whenNotDefault: 'map')] morphMap(?array $map = null, bool $merge = true)` and `#[BootOnly(whenShared: true)] globalOptions(array|Closure $options)`.
  - Mark a public method that must exist but always throws, such as an interface method an implementation can't support, with `#[Unsupported]`. Pass the supported alternative as its message when there is one: `#[Unsupported('Use call() instead.')]`.
- Worker State (lines 885–897): replace the docblock-tag paragraph and its example with one sentence: "Writes in methods marked as [boot-time or test-only](#method-attributes), and in `flushState` methods, are not reported."

`docs/todo.md`:
- In "Release preparation", add `hypervel/attributes` to the repositories and Packagist registrations to create, alongside `hypervel/workbench` and `hypervel/sentinel`.
- Add an "Engine" section with one entry:
  > - `Hypervel\Engine\Channel::hasProducers()`, `hasConsumers()`, `isReadable()` and `isWritable()` are inherited Hyperf stubs that always throw. Implement them with Swoole's `Channel::stats()` (`producer_num`, `consumer_num`, `queue_num`), or remove them from `ChannelInterface`, after checking their close, read and write semantics.

## 6. Monorepo consumers (after this PR merges)

`packages/hypervel/src` in the monorepo has 29 files using the tags:
- tenancy 10, workflow 7, webhooks 4, activity-log 2, kafka 2, ssh 2, jmespath 1, oidc 1.

It reaches components through a path repository, so `hypervel/attributes` resolves locally.

After this PR merges to `0.4`:
- convert those files on monorepo `main` with the same rules;
- add `"hypervel/attributes": "*"` to each affected sub-package `composer.json` (`src/{tenancy,workflow,…}/composer.json`; direct edit, no lockfile);
- run `composer require "hypervel/attributes:*"` in `packages/hypervel`, whose root manifest lists the components it uses;
- run that package's `composer analyse` and affected tests;
- get peer review, then commit on `main`. CLAUDE.md's "Consumer and sibling checks" workflow authorizes this.

This follow-up isn't part of the PR's completion.

## 7. Verification

Run sequentially from the components root:
1. Each changed or new test file as it's edited: `WorkerStateRuleTest` and each touched `PackageMetadataTest`.
2. One-off packaging smoke check, not committed:
   - copy `src/attributes` to a scratch directory, `composer dump-autoload` there;
   - run a script that requires its `vendor/autoload.php` and instantiates every attribute with its constructor arguments (`new BootOnly(whenNotDefault: 'map', whenShared: true)`, `new BootOnly(whenProvided: 'duration')`, `new Unsupported`, `new Unsupported('Use call() instead.')`), independent of the root mappings;
   - delete the directory.
3. A one-off script check that every package except `phpstan` whose `src` uses an attribute declares `hypervel/attributes`.
4. `composer analyse`. The conversion changes no runtime behavior, so this, rather than the runtime suites, verifies the converted files.
5. `composer test:phpstan`, since the worker-state helper changed.
6. `COMPOSER_PROCESS_TIMEOUT=0 composer lint:fix` before review.

## Status

Plan signed off by the peer and implementation approved by the owner.

- Done: sections 1 and 4; the container, CacheManager memo, Redis pinned-connection and guard context keys; the ArrayStore clone correction; sections 2 and 3 for ai, api-client, auth, broadcasting, bus, cache, collections, concurrency, config, connection-pool, console, container, context, contracts, cookie, core, coroutine, data, database, di, encryption, engine, events, filesystem, foundation, hashing, head, horizon, http, http-server, inertia, log, macroable, mail, mcp, object-pool, pagination, permission, pipeline, process, prompts, queue, redis, reverb, routing, scout, server, server-process, session, signal, support, testing, translation, validation, view and websocket-server.
- Remaining: sections 2 and 3 for the other packages, in reviewed checkpoints of about 50–80 files; section 5; section 7.
- Next step: after the peer signs off this checkpoint, convert the next package group.
