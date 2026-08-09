# Server Reloader and Worker Configuration Refresh

## Goal

Make `server:reload` and programmatic server reloads start event and task workers from the latest environment and configuration without retaining config-derived objects from the master process. Each service provider refreshes the worker state it owns through one small lifecycle contract. Retained framework objects keep their identity when other live services hold them; replaceable cached objects are forgotten and rebuilt lazily.

The public surface stays Laravel-shaped:

- `ServerReloader::reload()` is an injectable service for programmatic reloads.
- `server:reload` is a thin console adapter over that service.
- Providers implement `ReloadsConfiguration::reloadConfiguration()` when they own worker state derived from configuration.
- Manager reset methods use established plural names such as `forgetDrivers()`, `forgetConnections()`, and `forgetDisks()`.

The refresh runs once per replacement worker before it accepts work. It adds no request-path work.

## Verified Lifecycle Facts

- The server application is registered and booted before Swoole forks workers. Workers inherit the container, registered providers, resolved singleton objects, callbacks, and cached manager state.
- `WorkerStartCallback` dispatches `BeforeWorkerStart` before worker-type events, startup logging, `AfterWorkerStart`, and the worker-start coordinator barrier.
- `ReloadDotenvAndConfig` already handles `BeforeWorkerStart`. It reloads dotenv, rebuilds configuration through `LoadConfiguration`, repopulates the existing config repository through `replaceItems()`, and replays `ConfigMutationTracker` mutations.
- Config repository identity is preserved, so services retaining the repository see its new contents. Objects that copied config values into properties or resolved drivers in the master keep that stale state unless their owning provider updates or forgets them.
- `Application::getProviders($type)` filters the existing ordered provider list; it does not resolve new providers.
- Provider order is:
  1. base Event, Log, Context, and Routing providers;
  2. `Hypervel\` providers explicitly listed in `app.providers`, preserving their order;
  3. discovered package providers sorted by descending `ServiceProvider::$priority`, preserving ties;
  4. remaining application providers, preserving their order.
- Listing a discovered `Hypervel\` provider in `app.providers` moves it into group 2 because the later `unique()` keeps the first occurrence. A discovered provider hook must therefore not depend on another discovered provider's relative position.
- Swoole reloads event workers with `SIGUSR1` and task workers with `SIGUSR2`. Custom server processes, queue workers, the scheduler, and Horizon processes are separate process lifecycles.
- Laravel boots providers per PHP process. Hypervel must instead separate master bootstrap from worker configuration refresh while keeping Laravel-style provider ownership.

## Anti-Overengineering Rules

> This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.
>
> Complexity must pay for itself with at least one of:
>
> - a demonstrated failure;
> - a complete source trace proving a realistic vulnerable schedule;
> - a clear general capability with real consumers and owner approval;
> - deletion of greater or riskier complexity elsewhere.
>
> Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.
>
> Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.
>
> Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.
>
> The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

## Configuration Reload Contract

Add the contract to Foundation contracts, where provider lifecycle behavior belongs:

```php
namespace Hypervel\Contracts\Foundation;

interface ReloadsConfiguration
{
    public function reloadConfiguration(): void;
}
```

Registered service providers implement the contract directly. `ReloadDotenvAndConfig` invokes them after the existing repository has been repopulated and recorded config mutations have been replayed:

```php
protected function reloadConfig(): void
{
    $config = $this->rebuildConfigRepository();

    $this->configMutationTracker->replay($config);

    foreach ($this->container->getProviders(ReloadsConfiguration::class) as $provider) {
        $provider->reloadConfiguration();
    }
}
```

Rules:

- Hooks are synchronous and fail fast. A broken refreshed configuration must prevent the replacement worker from becoming ready.
- The contract and every public implementation carry a `Boot-only.` warning stating that request-time use mutates shared worker state while concurrent coroutines may still hold the previous objects.
- Do not add retries, rollback, event hierarchies, priorities, a hook registry, or a dependency graph.
- Hooks may rely on the documented provider group order, but optional package hooks must not depend on another discovered provider's position.
- Hooks update inherited config snapshots. Work that must happen for every newly started worker after all configuration is current remains on `AfterWorkerStart`.
- Auth and Sanctum cache validation remains on `AfterWorkerStart`. Moving it into Auth's hook would validate against a cache store that Cache's later hook may still replace.
- Cache serializable-class finalization remains on `AfterWorkerStart` for the same completed-worker-bootstrap reason.
- Invalid refreshed config may put replacement workers into a restart loop until the configuration is fixed and reload is attempted again. The master continues running its existing image. Document this behavior instead of masking it.

### Replay derived configuration as operations

`ConfigMutationTracker` supports both recorded values and closures reevaluated against the rebuilt repository. Use the existing `applyAndRecord()` shape for pure derived configuration. In Fortify, Sentry, and Horizon, move every config read and write in the derivation into one `static` closure with no captures and use this shared comment:

> Derived config can depend on the worker environment, so replay the operation after config reload rather than its master result.

- Fortify recomputes its four derived `passkeys.*` values from current `fortify.passkeys.*`, `app.url`, and `app.key`. Keep `Passkeys::ignoreRoutes()` and the redirect callback outside the closure as master-installed state.
- Sentry recomputes its two missing log-channel defaults from the current channel map and log level. Explicit application channel configuration must remain untouched.
- Horizon recomputes an empty `horizon.name` from current `app.name`; an explicit name remains untouched.

Resolve the concrete config repository and do not add cached-configuration guards. These operations are idempotent and perform no file I/O. Keep value snapshots for `app.providers` and Reverb/gRPC `server.servers`: those values describe providers or server topology already installed in the master, so recomputing them only in replacement workers would make config disagree with the running process. Add a concise source comment at each of those three `set()` calls so a future change does not convert the snapshot into a replayed operation.

## Object Identity and Resolution Rules

Each hook must apply these rules:

- Forget an object only when no framework-owned live object retains it.
- Mutate an object in place when another live framework service holds it.
- Preserve user-registered manager creators, extensions, callbacks, named definitions, and routes unless they are themselves built from refreshed config.
- Guard the canonical bound abstract with `resolved()` before resolving an optional object. Do not guard only a concrete class used as a string binding target.
- A string-concrete binding can make `resolved(Concrete::class)` true without caching a direct concrete instance. Resolving that concrete may create an unused auto-singleton. Never use that false-positive path.
- Do not eagerly resolve services during refresh. Queue is the one intentional exception: a previously resolved Queue manager already eagerly established its configured `background` and `deferred` connections, so the hook restores those same connections after clearing them.
- Public methods that clear worker-held registries or mutate worker configuration must use the exact `Boot-only.` or `Boot or tests only.` warning and name the shared-state race caused by request-time use.

## Programmatic Server Reload

Add injectable `Hypervel\Server\ServerReloader` with:

```php
public function reload(): void;
```

Behavior:

1. Read the current `server.settings.pid_file` through the config repository.
2. Read the PID through the low-level `Hypervel\Filesystem\Filesystem` service. The disk-oriented Filesystem contract is the wrong boundary: its `get()` accepts disk-relative paths and may return `null`, while the server PID path is a local absolute path and the existing concrete service throws `FileNotFoundException`.
3. Reject non-numeric or non-positive PID contents with the server package `InvalidArgumentException`.
4. Send `SIGUSR1` for event workers.
5. Send `SIGUSR2` only when `server.settings.task_worker_num` is greater than zero.
6. Throw `ServerException` naming the failed signal and worker class when a signal cannot be sent.
7. Let the existing filesystem exception report an unreadable PID file.

Use constructor injection for the config contract and the low-level concrete Filesystem service. Keep signal delivery behind one protected method so unit tests can record success and failure without signaling a real process.

Leave the concrete service unbound so Hypervel's normal auto-singleton behavior applies. Do not add a facade, container alias, or interface for a class with one implementation.

`ServerReloadCommand` becomes a thin adapter: print the start message, call `ServerReloader::reload()`, map the service's filesystem, invalid-PID, and signal exceptions to command failure with their messages, and print `Done.` on success. Remove its direct config, filesystem, and signal ownership. Do not add progress callbacks merely to interleave an extra task-worker message.

Keep the existing `ReloadsWorkers` trait separate. Its silent best-effort `SIGUSR1` behavior serves a different internal cleanup path and deliberately does not claim strict task-worker reload semantics. Do not merge it into the service or add PID liveness checks, polling, locks, retries, readiness tracking, or stale-PID recovery.

## Container Correction

`Container::forgetInstance()` must canonicalize aliases before clearing cached instance, scoped, and auto-singleton state:

```php
public function forgetInstance(string $abstract): void
{
    $this->forgetCachedInstances($this->getAlias($abstract));
}
```

Keep `forgetCachedInstances()` operating on an already canonical key. Add a regression proving that forgetting through an alias clears the canonical cached object and the next resolution builds a new instance.

## Swoole Cache Table Safety

Cache and Rate Limiter Swoole tables are process topology: they must be created in the master before Swoole forks workers. Rate Limiter already seals its `TableManager` after `BeforeServerStart` initialization and rejects a later unknown table. Cache currently creates an unknown table lazily in the calling worker, which silently gives each worker private cache state after a reload introduces a new table.

Give `SwooleTableManager` the same minimal invariant:

- add a `sealed` flag;
- return existing states before checking it;
- throw `LogicException("Swoole cache table [{$name}] was not initialized before the server fork.")` when a missing state is requested after sealing;
- add idempotent `seal(): void` with the exact `Boot-only.` warning explaining the cross-worker split;
- call `seal()` after table creation on every `CreateSwooleTable` dispatch, even when no Swoole cache store is configured. `BeforeServerStart` fires once per configured port, so later dispatches must reuse existing states and reseal without failing.

Do not add table recreation, config diffing, worker coordination, or another lifecycle. This converts a silent data split into the same explicit restart requirement the rate limiter already enforces.

## Shared Reset and Mutation APIs

Add the smallest plural reset or setter that matches each existing manager/class responsibility:

The plural manager reset methods return `static`, matching existing Laravel-style fluent resets such as `forgetDrivers()`, `forgetGuards()`, and `forgetMailers()`.

| Owner | API | Required behavior |
|---|---|---|
| Password | `forgetBrokers()` | Clear resolved brokers while preserving broker construction behavior. |
| Cache | `forgetDrivers()` | Clear resolved cache drivers while preserving custom creators and the shared serialization policy. |
| `MultipleInstanceManager` | `forgetInstances()` | Clear resolved named instances while preserving creators. Used by Concurrency and Rate Limiter. |
| Filesystem | `forgetDisks()` | Clear resolved disks while preserving custom creators. |
| Log | `forgetChannels()` | Clear resolved channels while preserving custom creators and shared context. |
| Queue | `forgetConnections()` | Clear resolved queue connections while preserving connectors and callbacks owned elsewhere. |
| URL generator | `setAssetRoot(?string)` | Update the retained generator's asset root at worker boot. |
| URL generator | existing `setRequest()` | Change its warning from `Tests only.` to `Boot or tests only.` because Routing legitimately refreshes the fallback request at worker boot. |
| View compiler | `reloadConfiguration(...)` | Update the retained compiler's five config-derived fields without losing application-registered compilation behavior. |
| Translator | `setBaseLocale(string)` | Update the retained worker-wide base locale without changing a coroutine-local override. |
| Redirector | existing `setSession()` | Add a `Boot-only.` warning because Session refreshes the retained Redirector. |
| Telescope database repository | `setConnection(string)` | Update the connection used by the retained repository. |
| Telescope database repository | `setChunkSize(?int)` | Apply the constructor's existing falsy-to-default rule. |

Extract `DatabaseEntriesRepository::DEFAULT_CHUNK_SIZE = 1000`; initialize the property and setter fallback from the same constant, and make the constructor delegate to the two setters.

For the View compiler, keep only `Filesystem` promoted. Declare the five config-derived fields as normal properties and make the constructor delegate to one validated boot-only method:

```php
public function reloadConfiguration(
    string $cachePath,
    string $basePath,
    bool $shouldCache,
    string $compiledExtension,
    bool $shouldCheckTimestamps,
): void;
```

Keep the existing non-empty cache-path validation before assignment. Leave this concrete lifecycle method off `CompilerInterface`, whose rendering contract remains `getCompiledPath()`, `isExpired()`, and `compile()`.

### Translator base-locale correction

The translator constructor currently calls coroutine-local `setLocale()` after property promotion has already assigned the same base locale. This leftover Laravel assignment seeds no request coroutine and masks later base-locale changes in the current or non-coroutine context.

Correct the ownership model:

- Declare `protected string $locale` as a normal property; keep only the loader promoted.
- Make the constructor call `setBaseLocale()` so that setter is the single assignment path.
- Extract `assertValidLocale()` and call it from both `setLocale()` and `setBaseLocale()`.
- Keep the `/`, `\`, `.`, and `..` path checks unchanged.
- Move the cross-file validation comment to the shared validator and update `FileLoader`'s matching comment.
- `setBaseLocale()` changes only the property. An existing coroutine-local `setLocale()` override continues to win.
- Keep `setBaseLocale()` off the Translation contract. It is a framework concrete lifecycle operation, not a requirement for application-supplied translators.
- Update the Translation README's existing Laravel-difference sentence to name `setBaseLocale()` as the boot-only counterpart. Do not add framework-lifecycle API detail to the application localization guide.

## Provider Refresh Matrix

### Core and default providers

| Provider | Refresh behavior |
|---|---|
| Foundation | Reapply `app.timezone`; rerun dumper registration with current `view.compiled`; clear maintenance-mode manager drivers; flush the boot-reachable `WorkerCachedMaintenanceMode` snapshot; forget `MaintenanceModeContract`. A provider may populate that snapshot by calling `Application::isDownForMaintenance()` before the fork, and an interval of zero would otherwise retain it forever. Add the standard `Boot or tests only.` warning to `flushCache()`. |
| Routing | Preserve routes, middleware, and the URL generator. Replace the fallback request from current `app.url`, set the asset root, and call `forceHttps()` with the current boolean so both enabling and disabling apply. Preserve Redirector and ResponseFactory identities. |
| Session | Clear Session manager drivers and forget `session.store`. If canonical `redirect` is resolved, update the retained Redirector with the refreshed store. ResponseFactory keeps its retained Redirector reference. |
| Auth | Clear resolved guards. Cache validation remains on `AfterWorkerStart`. |
| Password | Clear resolved brokers. |
| Broadcast | Clear manager drivers and forget the cached default broadcaster contract. |
| Bus | Forget both `BatchRepository` and `DatabaseBatchRepository`. The outer singleton resolves and separately caches the same config-built concrete, so clearing only one key leaves stale batching configuration reachable through the other. No framework master-boot retainer requires in-place mutation. |
| Cache | Clear manager drivers and forget `cache.store`. Preserve custom creators and the shared `SerializableClassPolicy`; finalization remains on `AfterWorkerStart`. Swoole cache-table definitions remain restart-owned, and the sealed table manager rejects a newly configured table after fork. |
| Concurrency | Clear resolved manager instances. Preserve custom creators. |
| Rate Limiter | Clear resolved manager instances. Preserve custom creators, named limiters, store policy, and key scopes. Swoole rate-limiter table definitions remain restart-owned; its existing sealed manager already rejects a newly configured table after fork. |
| Cookie | If the canonical cookie service is resolved, mutate the retained `CookieJar` through `setDefaultPathAndDomain()` with current session config. Middleware and the session handler retain this object. |
| Database | Keep existing pre-refresh resource cleanup. After config rebuild, forget `db.resolver` and the auto-singleton `ConnectionResolver`. |
| Encryption | Reset the Serializable Closure secret and forget the encrypter. No framework master-boot object requires retaining the old encrypter identity. |
| Filesystem | Clear disks and forget `filesystem.disk`. |
| Hashing | Clear drivers and forget `hash.driver`. |
| Log | Clear channels and refresh the retained stdout logger in place. Guard and resolve `StdoutLoggerInterface`, then mutate only the framework `StdoutLogger`. Remove the hard-coded stdout refresh from `WorkerStartCallback`. |
| Mail | Clear mailers and forget Markdown. |
| Notifications | Clear ChannelManager drivers and forget MailChannel. |
| Object Pool | On `BeforeServerFork`, flush an already resolved `PoolManager`; keep the existing recycler on `AfterWorkerStart`. Do not add a worker-start flush: the master manager is empty after the fork-time flush, and a second flush could close a pool created by an earlier worker-start listener. `ObjectPool::destroyObject()` already contains user cleanup failures, so do not add exception aggregation to `PoolManager::flush()`. |
| Queue | Clear connections. For an already resolved manager, restore configured `background` and `deferred` exception callbacks through one protected provider method used by registration and reload. Keep the exception-handler guard, per-connector `InvalidArgumentException` handling, and eager restoration limited to those two already-eager connections. Forget `queue.connection` and `queue.failer`. |
| Translation | If canonical `translator` is resolved and is the framework Translator, set base locale and fallback from config and clear loaded groups. Preserve loader paths/namespaces, extensions, callbacks, selector, and stringable handlers. |
| View | Preserve Factory, FileViewFinder, Blade compiler, EngineResolver, and resolved engine identities. Update the retained finder paths and flush only its lookup cache. Update the retained framework compiler's config fields through `Compiler::reloadConfiguration()` while preserving directives, conditions, tags, echo format, precompilers, components, and other registrations. Clear `CompilerEngine`'s boot-reachable compile-check map so a view rendered during provider boot cannot bypass refreshed cache or timestamp policy. Do not forget the compiler or engine: normal engine eviction rebuilds around the same compiler singleton, while Sentry's resolver rebuilds a decorator around its captured old engine. |

Foundation reapplies the timezone after mutation replay because `LoadConfiguration` sets it before replay, and a recorded boot mutation may then change `app.timezone`. It does not reapply `mb_internal_encoding`; that value is deliberately fixed to UTF-8 rather than config-derived.

No configuration hook is added to these base/default providers:

- Event and Context register process-wide infrastructure with no config-derived snapshot.
- Console, Engine, Form Request, and Pipeline register commands, callbacks, or stateless factories without copying configuration.
- HTTP's fallback request binding reads the preserved config repository when it resolves.
- Pagination's boot-installed resolvers read request context or the retained container when invoked.
- Redis retains its config repository and pool factory, reads connection configuration on demand, and already discards process connections before worker start.
- Server and Server Process own master-installed server/process topology, not reloadable worker configuration.
- Signal reads `signal.handlers` only when its listener starts after configuration refresh.
- Validation retains the Translator and Database manager identities; their owning providers refresh those objects or their dependent state in place.

### Optional providers

| Provider | Refresh behavior |
|---|---|
| JWT | `ClaimFactory::reloadConfiguration()` rereads issuer and subject-lock settings. `JwtManager::reloadConfiguration()` clears drivers and validations, rereads blacklist enablement, and resolves the current Blacklist only when enabled. The provider updates a resolved ClaimFactory, forgets resolved Parser and Blacklist objects, then refreshes a resolved manager. Constructors delegate to these boot-only methods after their required base initialization. Preserve custom driver creators. |
| Permission | If resolved, call `PermissionRegistrar::initializeCache()`. |
| Reverb | Clear resolved `ApplicationManager` drivers and forget an already resolved `WebhookBatchBuffer`. Preserve `ServerProviderManager`; server topology remains restart-owned. |
| Scout | If `EngineManager` is resolved, call `forgetEngines()` while preserving custom creators. Forget resolved Algolia, Meilisearch, and Typesense client bindings so rebuilt engines use current client configuration. |
| Sentry | Extract the existing Hub binding's client construction and integration setup into one protected `createClient(): ClientInterface` method. Use it both for initial Hub construction and reload. Guard canonical `HubInterface`, mutate only the framework `Hub` through `bindClient()`, preserve the same global `SentrySdk` Hub, and forget BacktraceHelper after the client swap. Its log-channel defaults are already recomputed during config mutation replay. Listener/decorator topology and package enablement remain restart-owned. |
| Socialite | If resolved, clear `SocialiteManager` drivers while preserving custom creators. |
| Inertia | Forget resolved `inertia.view-finder`. Do not flush request-scoped gateway state from a worker hook. |
| Telescope | Preserve repository objects retained by watchers, controllers, and `Telescope::$store`. Guard and mutate only `EntriesRepository`, `ClearableRepository`, and `PrunableRepository`; update connection and chunk size. Never guard or resolve `DatabaseEntriesRepository::class`, which can construct an unused fourth instance. Convert its two contextual config callbacks to typed getters. |

No configuration hook is added to these discovered providers:

- Sanctum, whose cache validation remains on `AfterWorkerStart`;
- Fortify, whose derived Passkeys values are handled during config mutation replay while route and action topology remains restart-owned;
- gRPC, whose dedicated listener configuration and captured request/response limits form one master-installed server topology and require restart together;
- Horizon, whose derived default name is handled during config mutation replay while static options and supervisor topology live in separate long-running processes;
- Nested Set and WebSocket Server, which register config-independent macros or event listeners;
- Passkeys, whose package config merge and any Fortify derivation are replayed while routes and bindings remain restart-owned;
- Tinker, Watcher, and Wayfinder, which register console-only services;
- Testbench and Testing, which are development-only providers and own no server-worker configuration snapshot.

## Provider Source Cleanup Required by the Changes

- Convert all three Queue provider `$app[...]` reads to `make()` inline.
- Convert all three View provider `$app[...]` reads to `make()` inline.
- Do not add `@var` annotations or restructure the closures. These canonical string keys still return `mixed`; the conversion is consistency with the container convention, not a PHPStan improvement.
- Add `ext-posix` to `src/foundation/composer.json`, because Foundation's reload trait directly calls `posix_kill`. Root and Server metadata already require it.

## Restart Boundary

Worker configuration refresh updates inherited config-backed state for replacement event and task workers. It does not rebuild master-owned topology:

- listening ports and Swoole settings;
- event/task worker counts and callback registration;
- routes, middleware, event listeners, package enablement, and config-gated bindings or boot registrations;
- custom server-process definitions;
- Cache and Rate Limiter Swoole table definitions;
- gRPC, Reverb, Sentry, Telescope watcher, Fortify, and Horizon topology;
- preloaded or changed PHP code.

Changing those requires a full server or process restart. Queue workers, the scheduler, Horizon, and custom server processes are not signaled by `ServerReloader` and must be restarted through their own lifecycle controls.

## Documentation

Update `src/boost/docs/providers.md` in Laravel-docs prose:

- Correct all three claims that providers register or boot at worker startup. Explain that the server application registers and boots before forking, and workers inherit that state.
- Preserve the accurate conclusion that deferred providers provide no useful optimization because registration cost is amortized, while correcting the lifecycle explanation.
- Add a concise `ReloadsConfiguration` section with an application-provider example. State that application providers run after framework and discovered package providers.
- Explain the difference between master bootstrap, worker configuration refresh, and per-worker startup events.
- Link to the deployment reload section with `/docs/{{version}}/...` syntax.

Update `src/boost/docs/deployment.md` in the same style:

- Document injecting `ServerReloader` and calling `reload()`.
- Explain that `server:reload` and the service replace event workers and configured task workers.
- Explain which config-derived services refresh, which topology changes need a restart, and which other long-running process types must be restarted separately.
- Name Cache and Rate Limiter Swoole table definitions as restart-owned, and explain that the first use of a newly configured table after reload fails explicitly because shared tables can only be created before the server fork.
- Explain the invalid-config restart-loop behavior and recovery: fix configuration, then reload again.

Remove the completed service-provider reload item from `docs/todo.md`. Do not duplicate these explanations in package READMEs.

## Tests

### Server and lifecycle orchestration

- Add `tests/Server/ServerReloaderTest.php` for PID-file reads, invalid PID contents, event-only reload, event-plus-task reload, each signal failure, exception messages, and the protected signal seam.
- Simplify `ServerReloadCommandTest` to adapter behavior: start/success output, service call, and failure status/rendering.
- Extend `ReloadDotenvAndConfigTest` to prove the exact sequence: dotenv/config rebuild, config mutation replay, then ordered provider hooks.
- Cover provider filtering, ordered calls, fail-fast behavior, and no resolution of unrelated services.
- Prove Fortify, Sentry, and Horizon replay their full derivations from rebuilt config without captures; include Sentry's explicit-channel preservation and current-level fallback branches.
- Prove an invalid refreshed configuration prevents the worker-start path from completing.
- Extend `WorkerStartCallbackTest` to prove stdout configuration is provider-owned and the event order remains correct.

### Shared APIs and identity

- Add focused unit coverage for every new plural reset method, including preservation of custom creators and callbacks.
- Add the container alias-forget regression.
- Add Cache table-manager coverage proving known tables remain available after sealing, unknown tables fail with the fork-specific exception, and `CreateSwooleTable` initializes then seals safely across two `BeforeServerStart` dispatches.
- Add URL generator tests for refreshed request, asset root, and both `forceHttps(true)` and `forceHttps(false)` paths while retaining identity.
- Prove Session refresh preserves Redirector and ResponseFactory identities while replacing the session store.
- Prove Cookie refresh mutates the retained CookieJar used by existing middleware/session handler objects.
- Prove Translation construction creates no coroutine override, base refresh changes the fallback value, an explicit request override still wins, both setters reject invalid locales, loaded groups clear, and extensions/callbacks remain.
- Strengthen the existing Translation coroutine isolation assertion and extend the existing invalid-locale test rather than creating duplicate test files.
- Prove View preserves Factory, finder, compiler, EngineResolver, and resolved engine identities while updating finder paths and compiler configuration. Preserve finder hints/extensions and Blade registrations. Seed the compile-check map with a pre-fork render, change `view.cache` or enable timestamp checks, and prove the next render uses the refreshed policy; do not use a changed compiled path or relative hash because a missing target file self-heals without proving the reset.
- Prove Telescope updates the three retained repository views without constructing a direct DatabaseEntriesRepository auto-singleton.
- Prove Sentry preserves Hub identity and binds a fresh client.
- Prove JWT refresh order and state: ClaimFactory update, Parser/Blacklist replacement, manager driver/validation reset, blacklist enablement, and creator preservation.

### Provider behavior

Add or extend provider tests for every matrix row. Headline regressions must include:

- cache resolved in the master then changed before worker start;
- database resolver default changed after master resolution;
- Queue callbacks restored on the refreshed background/deferred connections;
- Object Pool flushes before fork and no worker-start flush is added;
- Log refreshes the canonical stdout logger before later hooks can log;
- Auth and Sanctum validation still run only after all configuration hooks;
- optional-provider resolved guards do not instantiate unused services.

Run each changed test file immediately. After each coherent package slice, run its focused test group. At the final checkpoint run `composer fix`, then trace all changed callers/callees and retained-object relationships during self-review.

## Implementation Order

1. Add and test the Foundation contract and container alias correction.
2. Add and test `ServerReloader`, then reduce the command to an adapter.
3. Add shared manager reset/mutation APIs and their focused tests one file at a time.
4. Correct Translator base-locale ownership and tests before adding the Translation provider hook.
5. Correct derived-config mutation recording, then extend `ReloadDotenvAndConfig` to call provider hooks and prove ordering/failure semantics.
6. Implement core/default provider hooks in provider order. Seal Cache's Swoole table manager in the Cache slice. Add the Log hook and remove `WorkerStartCallback`'s stdout special case in the same slice, then verify its event-order test immediately.
7. Implement optional provider hooks, preserving resolved guards and object identity.
8. Complete the Queue/View container-access cleanup.
9. Update Foundation package metadata.
10. Update provider/deployment docs, Translation README, and remove the completed todo entry.
11. Run focused cross-provider regressions, then `composer fix` once.
12. Perform a full self-review for stale paths, object-retention mistakes, accidental eager resolution, Laravel API compatibility, request-path overhead, and overengineering before code review.

## Completion Criteria

- Every replacement event/task worker rebuilds dotenv/config, replays tracked mutations, and refreshes every registered provider implementing `ReloadsConfiguration` before readiness.
- Programmatic and CLI reloads share one strict `ServerReloader` implementation.
- Config-derived objects resolved before fork either rebuild lazily or update in place according to their real retainers.
- Cache and Rate Limiter reject Swoole table definitions that were not initialized before the server fork instead of creating process-private state.
- Pure derived config is reevaluated from current worker inputs; recorded snapshots remain only for master-installed provider and server topology.
- No optional hook constructs a service merely to refresh it.
- Request-scoped state, custom manager extensions, routes, callbacks, and retained object identities remain intact where required.
- Auth/Sanctum validation and cache policy finalization run after configuration refresh.
- Reload failures are explicit and fail fast; no retry, rollback, polling, registry, or readiness state machine is added.
- No supported Laravel API is removed or narrowed. New APIs use Laravel-style names and boot-only warnings.
- The feature adds work only during worker startup and explicit reload commands, with no request hot-path overhead.
- Documentation accurately distinguishes reloadable worker configuration from restart-owned process topology.
- Changed test files, focused package suites, `composer fix`, self-review, and peer code review are green.
