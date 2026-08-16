# Container named API and ArrayAccess removal plan

## Status and objective

Design consensus and peer plan review are complete; `claude-array-access` signed off after three review passes. Implementation is authorized and in progress.

Hypervel 0.4 will intentionally omit Laravel's container `ArrayAccess` API and the equivalent dynamic service properties. Container registration, lookup, and lifecycle changes will use named methods throughout the framework and its tests. The change also tightens the facade application boundary to the container contract, fixes temporary override cleanup, and records the public incompatibility where users and future Laravel ports will find it.

The owner approved the exact test deletion and consolidation list, applied the protected `AGENTS.md` update after peer signoff, and approved removing the inherited scalar `concurrency.driver` compatibility path.

## Final design

### Public contract

- `Hypervel\Contracts\Container\Container` extends only PSR-11 `ContainerInterface`. `Hypervel\Contracts\Foundation\Application` inherits this change transitively.
- `Hypervel\Container\Container` implements only the Hypervel container contract. Remove its `ArrayAccess` import, interface, four `offset*` methods, and `__get` / `__set` service accessors.
- Do not add a deprecation shim, compatibility trait, helper, or custom error. Native PHP errors and warnings expose unsupported array and dynamic-property use without a compatibility fallback.
- Do not add `forgetBinding()`. No production caller needs arbitrary binding deletion, and the removed `offsetUnset()` semantics are not a sound public operation: they clear selected binding, resolution, lifecycle, instance, and alias state while leaving tags, extenders, contextual bindings, rebound callbacks, and aliases that may target the canonical key.
- Keep `make()` for Hypervel resolution and `get()` for PSR-11 callers. Use `bound()` for Hypervel binding checks and `has()` where the variable is intentionally PSR-11-shaped.

The concrete class keeps a concise marker at the former offset-method location:

```php
// Hypervel intentionally omits container array and dynamic property access.
// Use named methods so resolution and binding lifecycles remain explicit.
```

This is the source marker required for an intentionally omitted Laravel feature. `flushState()` remains the last method after the removed trailing offset and magic-method block.

### Why this is the clean boundary

- `ArrayAccess` is suitable for real map or collection objects; it is not deprecated or generally bad PHP. The problem is the container mapping: reads perform resolution, writes silently select transient binding semantics, and unset mutates several lifecycle stores.
- `make()` carries Hypervel's conditional class-string generic return. `offsetGet(): mixed` loses that information and cannot accept resolution parameters.
- `$app[$key] = $value` hides whether the caller intends a transient factory or a shared existing value. Named `bind()` and `instance()` make that lifecycle choice reviewable.
- `$app->service` and `$app->service = $value` are even less discoverable and can be confused with declared properties.
- PSR-11 exposes `get()` and `has()`. Current PHP-DI, Symfony DI, and League Container implementations do not implement `ArrayAccess`.
- Laravel 13 retains ArrayAccess and dynamic properties on its concrete container, so documentation must not call them deprecated. Laravel's contract omits `ArrayAccess`, its current container guide documents named methods rather than array or dynamic-property access, and Laravel rejected adding `ArrayAccess` to the contract because it is a separate concern that is not required for a container.
- Hypervel 0.4 is the correct hard-break point. A compatibility layer would allow ported and application code to keep introducing the syntax this design is meant to remove.

### Named-method migration rules

Audit every candidate; do not bulk replace it.

| Existing use | Replacement |
|---|---|
| `$container[$abstract]` | `$container->make($abstract)` |
| `isset($container[$abstract])` | `$container->bound($abstract)`, or `has()` for an intentionally PSR-11-typed value |
| `$container[$abstract] = $closure` | `$container->bind($abstract, $closure)` |
| `$container[$abstract] = $objectOrValue` | `$container->instance($abstract, $objectOrValue)` when the caller supplies one shared value; retain `bind(fn () => $value)` only when transient binding and rebinding behavior are intentional |
| `unset($container[$abstract])` | Refactor around the caller's actual lifecycle. The verified temporary overrides use `forgetInstance()` |
| `$container->service` | `$container->make('service')` |
| `$container->service = $value` | Explicit `instance()` or `bind()` after reviewing lifetime intent |

For config, resolve the repository explicitly. Use typed getters only when presence and type are guaranteed. Keep `get()` plus the existing fallback or type guard when current behavior accepts a missing or wrong-typed value. When `null` intentionally selects or disables behavior, preserve that meaning and ensure the null branch has a focused behavioral test.

`offsetSet()` currently does fire rebound callbacks: `bind()` calls `registerBinding()`, which calls `rebound()` for an already-resolved abstract. Conversions that must preserve offset assignment semantics use `bind()`, not `instance()`.

## Implementation

### 1. Remove the API at its owning boundary

Update one file at a time:

1. `src/contracts/src/Container/Container.php`
   - Remove the `ArrayAccess` import and parent interface.
   - Keep the existing PSR and Hypervel named method surface unchanged.
2. `src/container/src/Container.php`
   - Remove the `ArrayAccess` import and implemented interface.
   - Remove `offsetExists()`, `offsetGet()`, `offsetSet()`, `offsetUnset()`, `__get()`, and `__set()`.
   - Add the concise intentional-removal marker at the former method block.
   - Do not alter `bound()`, `resolved()`, `bind()`, `instance()`, `forgetInstance()`, alias handling, or cached-instance internals as part of the removal.

The `isset(...) || array_key_exists(...)` pairs in `bound()`, `resolved()`, and `resolve()` remain. They deliberately support `instance($abstract, null)` while retaining an `isset()` fast path.

### 2. Convert production consumers

The verified production migration surface is grouped below. Each file must be read, changed, and checked separately. A name-independent receiver sweep found three caller shapes missed by the original app/container-name search: 21 `$this->hypervel[...]` reads across 13 console-command files, 10 `$this[...]` accesses in `Application`, and one local `$hypervel[...]` read in Testbench `UsesVendor`. The two other `$this[...]` source hits are inside the container magic accessors removed at the owning boundary.

| Area | Files | Required conversion |
|---|---|---|
| Cache console | `src/cache/src/Console/ClearCommand.php` | Resolve events through `$this->hypervel->make()`. |
| Concurrency | `src/concurrency/src/ConcurrencyManager.php` | Resolve config explicitly and apply the approved default/per-driver key correction described below. |
| Console | `src/console/src/Concerns/ConfiguresPrompts.php`, `src/console/src/Concerns/CreatesMatchingTest.php` | Resolve validator and path services through `$this->hypervel->make()`. |
| Database | `src/database/src/Capsule/Manager.php`, `src/database/src/Console/Migrations/MigrateCommand.php`, `src/database/src/Console/Migrations/RefreshCommand.php`, `src/database/src/Console/WipeCommand.php`, `src/database/src/DatabaseServiceProvider.php`, `src/database/src/Migrations/Migrator.php` | Use `make()` for config, database, filesystem, event, migration, and dispatcher services. Console commands use `$this->hypervel->make()`. Preserve config repository writes as config writes. |
| Filesystem | `src/filesystem/src/FilesystemManager.php` | Resolve the URL service with `make()`. |
| Foundation | `src/foundation/src/Application.php`, `src/foundation/src/Bootstrap/HandleExceptions.php`, `src/foundation/src/Console/ConfigCacheCommand.php`, `src/foundation/src/Console/EnvironmentCommand.php`, `src/foundation/src/Console/Kernel.php`, `src/foundation/src/Console/RouteCacheCommand.php`, `src/foundation/src/Console/RouteListCommand.php`, `src/foundation/src/Http/Kernel.php`, `src/foundation/src/Providers/FoundationServiceProvider.php`, `src/foundation/src/Support/Providers/RouteServiceProvider.php` | Replace config, event, environment, file, router, and URL-generator reads with named methods. Console commands use `$this->hypervel->make()`; `Application` follows the lifecycle audit below. |
| Foundation testing | `src/foundation/src/Testing/Concerns/InteractsWithAuthentication.php`, `src/foundation/src/Testing/Concerns/InteractsWithSession.php`, `src/foundation/src/Testing/Concerns/MakesHttpRequests.php`, `src/foundation/src/Testing/DatabaseTruncation.php`, `src/foundation/src/Testing/WithConsoleEvents.php` | Use named service reads. Apply the temporary middleware override fix described below. |
| Logging | `src/log/src/Context/ContextServiceProvider.php`, `src/log/src/LogManager.php` | Resolve events with `make()`. |
| Queue | `src/queue/src/Console/ClearCommand.php`, `src/queue/src/Console/RetryCommand.php`, `src/queue/src/Console/WorkCommand.php`, `src/queue/src/QueueManager.php`, `src/queue/src/SyncQueue.php` | Resolve event, queue, and cache services with `make()`; console commands use `$this->hypervel->make()`. |
| Sentry | `src/sentry/src/SentryServiceProvider.php` | Apply the typed user-configuration conversion below. |
| Support | `src/support/src/ServiceProvider.php` | Replace dynamic config-property access using the behavior-preserving snippet below. |
| Support facades | `src/support/src/Facades/Cookie.php`, `src/support/src/Facades/Date.php`, `src/support/src/Facades/Facade.php`, `src/support/src/Facades/Queue.php`, `src/support/src/Facades/Schema.php`, `src/support/src/Facades/Storage.php` | Use named container methods and apply the typed facade boundary described below. |
| Testbench | `src/testbench/src/Attributes/UsesVendor.php`, `src/testbench/src/Concerns/HandlesDatabases.php`, `src/testbench/src/Concerns/InteractsWithPublishedFiles.php` | Resolve the cloned application's guaranteed vendor-symlink flag, events, and files with `make()`. Remove `UsesVendor`'s unreachable null fallback after `CreateVendorSymlink::handle()` registers the boolean instance. |
| Testing | `src/testing/src/Concerns/TestCaches.php`, `src/testing/src/Concerns/TestViews.php`, `src/testing/src/PendingCommand.php` | Resolve config/compiler services by method and apply the temporary console-output override fix below. |
| Validation | `src/validation/src/ValidationServiceProvider.php` | Replace offset reads with `make()` and the two-entry `isset` check with explicit `bound()` checks before resolving. |

Keep these verified ordinary-array uses unchanged even though the variables resemble containers:

- `src/reverb/src/ConfigApplicationProvider.php`
- `src/server/src/ServerManager.php`
- `src/coordinator/src/CoordinatorManager.php`
- `src/di/src/Aop/AspectManager.php`
- `src/foundation/src/PackageManifest.php` (`$hypervel` is the validated Composer `extra.hypervel` array)

#### Console command application access

`Command::$hypervel` is nullable during construction, but `Console\Application::addCommand()` and `freshCommandForRun()` inject it before command execution. `Command` and its subclasses already use the direct property throughout their execution paths, matching Laravel's command idiom. Convert offset reads directly to `$this->hypervel->make(...)`; do not introduce `getHypervel()` only for converted calls and create two styles in one method. When the same resolved service is reused, store that service in a local variable instead of aliasing the application property.

#### Application self-access

`Hypervel\Foundation\Application` extends the container, so its ten `$this[...]` uses are container access even though they have no receiver variable name. Convert event and environment reads to `$this->make(...)`. In `detectEnvironment()`, register the detected string as the shared application environment and preserve the return value:

```php
return $this->instance('env', (new EnvironmentDetector)->detect($callback, $args));
```

`instance()` returns the supplied value and expresses the environment's actual shared-value lifetime. It replaces the current hidden `bind('env', fn () => $value)` selected by offset assignment. Extend one existing `detectEnvironment()` integration case to assert that `environment()` returns the detected value; do not add a separate test method.

#### Inherited concurrency configuration defect

`ConcurrencyManager` inherits three incompatible uses of `concurrency.driver` from Laravel:

- `getDefaultInstance()` reads `concurrency.driver` as a legacy scalar fallback after `concurrency.default`.
- `setDefaultInstance()` writes both `concurrency.default` and scalar `concurrency.driver`.
- `getInstanceConfig()` reads `concurrency.driver.{$name}` as a per-driver array.

Writing the default can therefore replace the entire per-driver map. The shipped Hypervel and Laravel configs define only `concurrency.default`, and current Laravel contains the same collision. Laravel history shows the shipped configuration moving from `driver` to `default` in `50205f4a13`, followed by `15e0224750` (`support driver`) adding the dual scalar read/write; the earlier `fbef34c77b` index change also explains why per-driver configuration now occupies the singular `driver` key. Hypervel 0.4 has never shipped the legacy scalar key. `AGENTS.md` says not to port backwards-compatibility shims for versions or features Hypervel does not support, so removing this path follows existing porting policy rather than introducing a novel divergence.

The owner approved the clean 0.4 behavior: use `concurrency.default` exclusively for the default, read it with the typed `string()` getter without duplicating the shipped config default in code, and make `setDefaultInstance()` write only that key. A missing or misspelled merged key must fail at the config boundary instead of silently selecting `coroutine`. Keep the existing `concurrency.driver.{$name}` location for per-driver configuration so fixing the collision does not invent a second schema change. Add a regression proving that changing the default does not overwrite configured per-driver data.

#### Typed Sentry configuration

`SentryServiceProvider::getUserConfig()` promises an array. Resolve config explicitly and use:

```php
return $this->app->make('config')->array(static::$abstract);
```

Remove the current `empty()` ternary. `register()` merges the package config before this method is called, so the root key is guaranteed and must not duplicate the package default in code. A missing or invalid non-array value now fails at the typed configuration boundary with `InvalidArgumentException` instead of being hidden or flowing to a return-type `TypeError`.

#### View-path compatibility

`ServiceProvider::loadViewsFrom()` currently skips a missing or non-array `view.paths` value. `Repository::array()` would throw and change that behavior. Use:

```php
$config = $this->app->make('config');

if (is_array($viewPaths = $config->get('view.paths'))) {
    foreach ($viewPaths as $viewPath) {
```

The single `is_array()` check covers the current missing-value case because `is_array(null)` is false.

#### Temporary middleware overrides

`withoutMiddleware()` installs either `middleware.disable` or selected fake middleware through `instance()`. Replace `withMiddleware()`'s unsets with `forgetInstance()`.

This is a bug fix as well as an API conversion. An explicit binding survives `instance()`, but current `offsetUnset()` deletes it. `forgetInstance()` removes the fake and restores the original binding and lifecycle. It also deliberately preserves the abstract's `resolved()` history, so a later `bind()` or `extend()` may fire rebinding callbacks where destructive offset-unset cleanup did not.

Add one regression to `tests/Foundation/Testing/Concerns/MakesHttpRequestsTest.php`: pre-bind middleware with behavior that cannot be recovered by zero-configuration construction, call `withoutMiddleware($class)` and `withMiddleware($class)`, then prove the original binding remains and resolves again. Keep the existing formerly-unbound and global-disable cases.

#### Temporary console output

`PendingCommand::mockConsoleOutput()` creates one mock object. Register it with `instance(OutputStyle::class, $mock)` and replace the `finally` cleanup with `forgetInstance(OutputStyle::class)`.

Do not add a dedicated test. Existing `tests/Console/ArtisanCommandTest.php` console paths exercise the cleanup and assert that `OutputStyle::class` is no longer bound. `ContainerTest::testForgettingTemporaryInstanceRestoresScopedLifecycle` already proves that an instance override can be forgotten while the original scoped registration survives.

### 3. Type the facade application boundary

`Facade` stops accepting an untyped ArrayAccess duck type once resolution uses `make()`. Make the real requirement native and nullable:

```php
protected static ?ContainerContract $app = null;

public static function getFacadeApplication(): ?ContainerContract;

public static function setFacadeApplication(?ContainerContract $app): void;
```

`resolveFacadeInstance()` uses `static::$app->make($name)`. The existing truthy guard narrows the nullable property there.

Eight nullable-boundary sites deliberately fail if no facade application has been set. Preserve that fail-fast behavior without guards or a throwing helper. At each site, copy `static::$app` or `static::getFacadeApplication()` to a local variable narrowed with `/** @var ContainerContract $app */`, then use the container:

- `Facade.php`: `resolved()`
- `Cookie.php`: `has()` and `get()`
- `Schema.php`: `connection()`
- `Storage.php`: `fake()`, `persistentFake()`, and `buildDiskConfiguration()`
- `Queue.php`: the `QueueFake` constructor call in `fake()`

Do not make `QueueFake` nullable. `Queue::fake()` already requires a facade root and therefore a container.

`Date::resolveFacadeInstance()` replaces its nested app/offset `isset` with the equivalent `static::$app === null || ! static::$app->bound($name)` fallback condition.

In `Storage::fake()` and `persistentFake()`, read the guaranteed default disk with the config repository's `string('filesystems.default')` getter. `buildDiskConfiguration()` must keep tolerant nested config behavior:

```php
$originalConfig = $app->make('config')->get("filesystems.disks.{$disk}") ?? [];
```

Do not use `array(..., [])`; the existing code does not eagerly reject a non-array value before reading its optional `throw` offset.

Update `tests/Support/SupportFacadeTest.php` deliberately:

- `ApplicationStub` extends concrete `Hypervel\Container\Container` instead of implementing `ArrayAccess`.
- Replace the ArrayAccess-era `setAttributes()` helper with `setInstances()`, registering each supplied key through `instance()`.
- `CountingApplicationStub` counts `make()` calls using the exact parent signature `public function make(string $abstract, array $parameters = []): mixed`, forwards `$parameters`, and exposes a clearly named count.
- Keep the uncached-facade assertion: it must prove two container resolutions.
- Add or retain coverage that `setFacadeApplication(null)` is supported.

All other inspected facade setup callers pass a concrete Hypervel container, an Application contract implementation, or `null` and require no compatibility layer.

### 4. Migrate tests without hiding lifecycle choices

Generate the candidate list with broad `grep` searches across all `tests/`, then inspect it one file at a time. After changing each test file, immediately run that file before moving to the next.

For tests where bracket syntax is only setup:

- Replace existing object and scalar registrations with `instance()`. This applies to config repositories, environment strings, cache doubles, storage paths, boolean provider flags, and similar fixed values.
- Replace closure registrations with `bind()`.
- Replace reads with `make()` and existence checks with `bound()`.
- Preserve config fallbacks and guards using the same typed-getter rule as source.
- Add `: void` to every test method whose body is changed, as required by the repository's test typing rules.
- Do not touch a value merely because its local variable is named `$app` or `$container`. For example, `tests/Integration/Http/RequestBindingTest.php` obtains a plain array from `config()->array('app')`; its `unset($app['url'])` remains unchanged. The reflected coordinator registry arrays in `tests/Coordinator/TimerTest.php` also remain arrays.
- Keep the local `$hypervel = []` Composer-metadata array in `tests/Testing/PHPUnit/TestStateRegistrarsTest.php`; it is distinct from the cloned application named `$hypervel` in Testbench `UsesVendor`.
- Convert the two cloned-application reads in `tests/Testbench/Foundation/Bootstrap/CreateVendorSymlinkTest.php` from `$application['TESTBENCH_VENDOR_SYMLINK']` to `make()`. They are real container reads, not Composer metadata arrays.

Fixed-value assignment sites that need an explicit `instance()` audit include:

- `tests/Support/SupportCapsuleManagerTraitTest.php`
- `tests/Integration/Horizon/Feature/MonitorSupervisorMemoryTest.php`
- `tests/Integration/Horizon/Feature/MonitorMasterSupervisorMemoryTest.php`
- `tests/Integration/Foundation/FoundationServiceProvidersTest.php`
- `tests/Integration/Encryption/KeyGenerateCommandTest.php`
- `tests/Telescope/FeatureTestCase.php`
- `tests/Cache/ClearCommandTest.php`
- `tests/Log/LogManagerTest.php`
- `tests/Foundation/FoundationHelpersTest.php`
- `tests/Foundation/FoundationDevCommandsTest.php`
- `tests/Foundation/Testing/DatabaseTruncationTest.php`
- `tests/Testbench/Fixtures/Providers/ParentServiceProvider.php`
- `tests/Testbench/Fixtures/Providers/ChildServiceProvider.php`

Convert the four real magic config accesses in `tests/Support/SupportMaintenanceModeTest.php` to an explicitly resolved config repository. Do not change declared test properties such as `bootstrapFile`, `frameworkBootstrapCount`, or `waiterEntered`.

The two queue payload fixtures, `tests/Integration/Queue/CustomPayloadTest.php` and `tests/Testbench/TestbenchTest.php`, should register the generated one-time password with `instance()` and clear it with `forgetInstance()`. Their purpose is to expose a stale static payload callback closing over a previous Application after its one-time entry has been cleared. The eager test-fixture value does not change that behavior.

#### Container test changes

The owner approved this exact list:

| Current test | Change | Preserved coverage / marker |
|---|---|---|
| `ContainerTest::testArrayAccess` | Remove | Sole subject is the omitted interface. Leave `// REMOVED: Container ArrayAccess is intentionally unsupported; use named container methods.` at the matching upstream location. |
| `ContainerTest::testUnsetRemoveBoundInstances` | Consolidate into `testForgetInstanceForgetsInstance` | Add `bound()` assertions before and after `forgetInstance()`. Leave a `REMOVED:` marker at the upstream method location naming the destination coverage. |
| `ContainerTest::testBoundInstanceAndAliasCheckViaArrayAccess` | Keep under a named-method-oriented name | Replace `isset` with `bound()` for the instance and alias. Record the upstream-name mapping in the plan only; no removal marker. |
| `ContainerTest::testOffsetUnsetClearsScopedInstance` | Remove | Hypervel-original duplicate of existing `testForgetInstanceForgetsScopedInstance`; no upstream marker. |
| `ContainerTest::testOffsetUnsetClearsScopedLifecycleMarker` | Remove | Hypervel-original assertion for intentionally removed destructive unset behavior. `forgetInstance()` correctly preserves scoped lifecycle; an explicit non-scoped `bind()` changes lifecycle. No upstream marker. |
| `ContainerTest::testContainerCanDynamicallySetService` | Remove | Sole subject is offset existence/set/get. Leave the same concise ArrayAccess `REMOVED:` marker at this separate upstream location. |

This produces three `REMOVED:` marker comments across four accounted upstream methods. The renamed bound/alias test remains executable but will require manual matching if its upstream-named counterpart changes.

In `tests/Container/ContainerExtendTest.php`, convert offset-assignment setup to explicit transient `bind()` closures. Keep the current unset/extender test as a `forgetExtenders()` test and remove only the obsolete unset step.

### 5. Document the intentional difference

#### Package README

Update `src/container/README.md` in the required README order:

- Keep the header and badge.
- Add `Documentation: https://hypervel.org/docs/container` because the package has a meaningful public documentation page.
- Add `Differences From Laravel` explaining that Hypervel omits container ArrayAccess and dynamic service properties, and directs users to `make()` / `get()`, `bound()` / `has()`, `bind()`, `instance()`, and `forgetInstance()` for temporary overrides. State that arbitrary binding removal is not exposed because registrations are worker-global boot-time state.
- Add `Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Container` after the difference section, matching the component-tree form used by Laravel-derived package READMEs.

Do not describe ArrayAccess as deprecated or claim Laravel discourages it. State the verified current facts: Laravel retains the concrete API, while its contract and current documentation use named methods.

#### Porting guide

Update `src/docs/porting-from-laravel.md` concisely at `Container Lifecycles`, where it currently says Hypervel's container has Laravel's public shape:

- State that Hypervel intentionally supports named container methods but not Laravel's ArrayAccess or dynamic service properties.
- Give compact porting mappings for read, existence, closure registration, fixed-instance registration, and temporary-instance cleanup.
- Keep the lifecycle table and worker-lifetime explanation as the authoritative lifecycle guidance.
- Do not add internal implementation detail, repository migration counts, edge-case history, or repeated rationale.

#### Main container guide

Update the introductory Laravel-comparison sentence in `src/docs/container.md`. It currently says the container's bindings and resolution helpers all behave like Laravel's, which becomes too broad after this public API removal. Keep the useful lifecycle comparison, but say that Hypervel follows Laravel's named binding and resolution APIs while documenting intentional public API differences in the porting guide. Do not duplicate the mappings or rationale here. No `src/docs` container example currently uses the removed syntax.

#### Release overview

Broaden the single intentional-differences clause in `src/docs/releases.md`'s `Laravel-Style Package Ports` section so it also says Hypervel may omit Laravel public APIs whose semantics do not suit Hypervel. Keep this at one high-level clause; do not add an API-specific subsection.

Do not add an `src/docs/upgrade.md` entry. That guide is for Hypervel 0.3-to-0.4 applications, and the Hyperf container used by 0.3 did not implement ArrayAccess or the removed offset methods. Keep the separate `porting-from-laravel.md` entry for Laravel developers. Also leave `porting-from-laravel.md`'s statement about supported public binding methods unchanged; `bind()`, `singleton()`, `scoped()`, and provider `bindings` / `singletons` properties remain supported.

#### Protected agent guide

The owner approved and applied a deliberately minimal `AGENTS.md` update after peer signoff. It changes four conceptual locations so the guide never instructs future ports to use a removed API:

1. `Container`: say Hypervel keeps Laravel's named API surface and intentionally omits array and dynamic service access.
2. `Porting Packages > Policy`: require ported container array and dynamic-property access to use named methods.
3. `Porting Laravel Tests > Approved unsupported features`: add container ArrayAccess and dynamic service properties to the exhaustive list so future tests whose sole subject is those APIs are removed with the required markers.
4. `Development Conventions`: clarify in the existing typed-config rule that code must not supply a second fallback when framework or package config defines the key, so missing or misspelled keys fail loudly.

The quick checklist now points to the exhaustive list instead of duplicating it. No method mapping, compatibility rationale, or arbitrary binding-removal guidance was added; those unneeded instructions would increase context and the risk of misinterpretation without addressing an observed agent failure.

### 6. Keep verified generic behavior unchanged

- `src/facade-documenter/facade.php::fulfillsBuiltinInterface()` remains generic. Its ArrayAccess filter still applies to real ArrayAccess facade targets and simply stops matching the App container; no generated App offset methods exist.
- `src/testbench/src/Bootstrapper.php` keeps `static::$configuration?->offsetExists('hypervel')` and `static::$configuration['hypervel']`. That value implements `Hypervel\Testbench\Contracts\Config`, which intentionally extends ArrayAccess; it is not the application container.
- Genuine ArrayAccess APIs elsewhere in Hypervel—collections, config repositories, HTTP responses, requests, data objects, views, cache tag wrappers, and similar map-like objects—remain supported and documented.
- Preserve `_archive` unchanged. It is a parked historical snapshot scheduled for deletion after 0.4, not active framework source, and is excluded from migration and search gates.
- Private package and application source had no real container array or dynamic-property callers after excluding vendor, generated, and reference-copy directories. Re-run that verification when implementing, but do not edit third-party or generated trees.

## Testing and verification

### Per-file cadence

- After each changed test file: `./vendor/bin/phpunit --no-progress path/to/Test.php`.
- Run `tests/Container/ContainerTest.php` immediately after its source/test edits, then `tests/Container/ContainerExtendTest.php`.
- Run `tests/Support/SupportFacadeTest.php` after the facade boundary and test-double conversion.
- Run `tests/Foundation/Testing/Concerns/MakesHttpRequestsTest.php` after the middleware cleanup fix.
- Run `tests/Console/ArtisanCommandTest.php` after `PendingCommand` changes even if the test file itself does not change.
- Run `tests/Foundation/FoundationApplicationTest.php` and `tests/Foundation/ApplicationRunningInConsoleTest.php` after converting `Application` self-access and extending the stored-environment assertion.
- Run `tests/Console/ConfiguresPromptsTest.php`, `tests/Console/GeneratorCommandTest.php`, and each affected package's matching command tests after the console-command conversions.
- Run `tests/Testbench/Attributes/UsesVendorTest.php` after converting the cloned application read.
- Run `tests/Concurrency/ConcurrencyTest.php` immediately after the approved manager and regression-test changes.
- Run the queue custom-payload and Testbench files immediately after each fixture edit.
- Run `composer test:testbench` after the Testbench source/test slice.

### Search gates

Use broad Bash `grep` searches across the whole `src/` and `tests/` trees:

- Confirm the Hypervel container contract and concrete class no longer import, extend, or implement `ArrayAccess` and define no `offset*`, `__get`, or `__set` methods.
- Before implementation and again as the closing backstop, rank every bracket receiver without assuming container variable names:

  ```bash
  grep -rhoE -e '(\$this->[a-zA-Z_][a-zA-Z0-9_]*|static::\$[a-zA-Z_][a-zA-Z0-9_]*|\$[a-zA-Z_][a-zA-Z0-9_]*)\[' src tests --include='*.php' \
    | sed -E 's/\[$//' | sort | uniq -c | sort -rn
  ```

  Inspect the complete ranked output for any receiver that is or may be a container. This discovery step is mandatory; the original fixed-name search missed both `$this->hypervel` and container self-access.
- Search bracket access through the known container identifier family: `$this->app`, `$app`, `static::$app`, `$this->application`, `$application`, `$this->container`, `$container`, `$this->hypervel`, and `$hypervel`. Also search bare `$this[...]` across all source and tests; the current twelve hits are the ten `Application` callers plus the two container magic-accessor implementations, so none should remain after this change. Inspect every other remainder; only genuine array or non-container ArrayAccess values may remain. In source, the known ordinary-array files are the Reverb config provider, ServerManager, CoordinatorManager, AspectManager, and `PackageManifest`; Testbench `Bootstrapper::$configuration` is the known non-container ArrayAccess value.
- Search direct `offsetExists/Get/Set/Unset` calls on app/container variables; none may remain.
- Run a shape-based magic-property search across source and tests, including `app`, `application`, `container`, and `hypervel` identifiers:

  ```bash
  grep -RInP --include='*.php' '(?:->(?:app|application|container|hypervel)|\$(?:app|application|container|hypervel))->[a-z_][A-Za-z0-9_]*(?![A-Za-z0-9_]|\s*\()' src tests
  ```

  Inspect every hit. No container service property may remain; declared fixture or test-double properties such as `bootstrapFile`, `frameworkBootstrapCount`, `middlewarePriority`, `waiterEntered`, and parallel-runner tracking fields remain valid.
- Search active `src/docs`, package READMEs, and the porting guide for stale claims or examples that say container array/dynamic access is supported; exclude `_archive` and historical plan files.
- Repeat the same checks across `apps/`, `packages/hypervel/`, and `packages/hypervel-dev/`, excluding dependency, generated, temporary-reference, and storage trees; any real caller must be converted under the same rules.

### Final checks

After all targeted tests are green, run `composer fix` once from the worktree root. It runs formatting, both PHPStan configurations, the full parallel suite, Testbench package-mode tests, and the dogfood package tests.

If `composer fix` fails:

1. Investigate the exact failure and apply only verified fixes.
2. Run the corrected targeted test/check.
3. Inspect the current `fix` script and run the failed entry plus every remaining entry in order. Do not repeat an earlier passing entry unless the fix can affect it.
4. Re-run the search gates after formatter or review fixes.

## Implementation order

1. Remove ArrayAccess from the contract and concrete container, including magic accessors and the required source marker.
2. Convert production consumers one file at a time, including middleware/PendingCommand lifecycle fixes and facade typing fallout.
3. Convert tests one file at a time, running each file immediately and leaving verified ordinary arrays unchanged.
4. Update the container README, main container guide, porting guide, and release overview with targeted edits.
5. Run focused suites, search gates, `composer test:testbench` for the Testbench slice, and one full `composer fix` checkpoint.
6. Re-read this plan, inspect the final diff file by file for stale compatibility code or documentation, and request code review before handoff.

## Primary references

- Laravel 13 container documentation: <https://laravel.com/docs/13.x/container>
- Laravel 13 concrete container: <https://github.com/laravel/framework/blob/13.x/src/Illuminate/Container/Container.php>
- Laravel 13 container contract: <https://github.com/laravel/framework/blob/13.x/src/Illuminate/Contracts/Container/Container.php>
- Laravel discussion rejecting ArrayAccess on the contract: <https://github.com/laravel/framework/pull/40252>
- PSR-11 specification and rationale: <https://www.php-fig.org/psr/psr-11/> and <https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-11-container-meta.md>
- Current PHP-DI, Symfony DI, and League Container concrete sources, checked only to establish that method-only containers are normal modern PHP practice.

Historical wording must stay narrow: ArrayAccess was present in the first tagged Illuminate container, while Laravel 3.2 used its own static IoC class without it. Do not call the API Pimple inheritance.
