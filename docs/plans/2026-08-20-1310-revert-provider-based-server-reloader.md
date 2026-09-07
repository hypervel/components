# Revert Provider-Based Server Reloading

## Goal

Remove the provider-owned configuration reload mechanism. A reload is only correct when every framework, application, and third-party service that copied configuration participates; one missing hook silently leaves stale state. Hypervel cannot make that contract reliable, so `server:reload` must not claim to rebuild the application.

Keep the existing Swoole worker-replacement command and its pre-worker dotenv/config repository rebuild. Replacement workers still inherit the master application's booted providers, objects, PHP state, routes, and callbacks. Documentation must therefore require a full process restart for application code, configuration, environment, certificates, or boot-time state changes.

This is a selective reversion against the branch's merge base with `0.4`, not an inverse merge. Later `0.4` changes in the same files must remain intact. Independently useful fixes discovered during the original work must remain, with tests that describe their standalone contracts.

## Synchronization Boundary

Preserve Sentry's complete root-aware configuration design while removing Sentry's reload hook, client rebinding, and mutation replay.

When synchronizing later `0.4` work:

1. Intersect files changed since the merge base with files touched by this reversion, then inspect every later edit in that intersection before writing its slice.
2. Diff every deletion target across the same range. If later non-reload coverage was added, edit the file instead of deleting it.
3. Before restoring any file to its pre-feature form, confirm its complete base-to-current delta is feature-only.

## Design Rules

- Delete rejected mechanisms completely: contracts, services, provider hooks, hook-only reset APIs, facade tags, tests, docs, comments, and the completed feature plan.
- Retain a change only when it fixes a verified problem or clearly improves the code without relying on provider reload hooks.
- Do not add a replacement registry, watchdog, application-generation layer, lifecycle event, compatibility shim, or automatic reset mechanism. Clean application reload needs a separately designed process/bootstrap boundary.
- Do not alter request hot paths. Retained fork-safety work runs before the fork, queue callback setup runs only when a connection is built, and translation pending data is consumed on first group load.
- Remove or rewrite every stale test. No test may reference a deleted API or assert the rejected lifecycle.
- Preserve supported Laravel APIs and current `0.4` improvements. Remove only Hypervel APIs introduced for the rejected feature.

## Final Reload Contract

### Keep

- `server:reload` directly reads the configured PID and sends Swoole's event-worker and configured task-worker reload signals.
- `ReloadDotenvAndConfig` reloads dotenv, rebuilds the existing config repository, and replays normal `ConfigMutationTracker` entries before worker startup.
- `WorkerStartCallback` reloads the default `StdoutLogger` after `BeforeWorkerStart`, then emits worker-type events, startup logging, `AfterWorkerStart`, and readiness in the established order.

### Remove

- `Hypervel\Contracts\Foundation\ReloadsConfiguration`.
- `Hypervel\Server\ServerReloader` and its programmatic API.
- Provider enumeration from `ReloadDotenvAndConfig`.
- Every provider `implements ReloadsConfiguration` declaration and `reloadConfiguration()` method.
- Hook-specific sequencing, topology comments, failure claims, and public guidance.

Restore `ServerReloadCommand` as the direct command implementation, including PID validation, event/task signals, truthful failures, and task-worker progress output. Delete `ServerReloaderTest`; restore command tests that exercise the direct implementation rather than a mocked adapter.

## Independently Retained Improvements

### Container alias invalidation

Keep `Container::forgetInstance()` canonicalizing aliases before clearing instance, scoped, and auto-singleton caches. Without this, forgetting through an alias leaves the canonical object cached. Keep the regression proving a new canonical instance is built after an alias is forgotten.

### Pre-fork shared-resource safety

Keep these independent fixes:

- Cache `SwooleTableManager` keeps its sealed state. Known tables remain available, but an unknown table requested after `BeforeServerStart` fails instead of creating worker-private state. `CreateSwooleTable` resolves one manager, initializes configured tables, and seals it even when no Swoole cache store exists. Keep repeated-start and no-store coverage.
- `ObjectPoolServiceProvider` flushes an already-resolved `PoolManager` on `BeforeServerFork`, then starts the recycler on `AfterWorkerStart`. It must not resolve an unused manager or add a second worker-start flush.
- HTTP `Factory::forgetConnectionHandlers()` clears inherited transport handlers while preserving named connection presets and all other factory state. `HttpServiceProvider` invokes it on `BeforeServerFork` only when the unbound concrete factory was resolved. Keep handler-rebuild, preset-preservation, and no-eager-resolution tests and the generated HTTP facade tag.

Remove Cache's provider reload hook and `CacheManager::forgetDrivers()`; those are not needed for table sealing. Remove their facade tags and hook tests.

### Queue connection construction

Keep the connector-owned exception callback design:

- `BackgroundConnector` and `DeferredConnector` accept an optional `Closure` and install it on the queue they construct.
- `QueueServiceProvider::exceptionReporter()` returns `null` when no exception handler is bound; otherwise the callback resolves the current handler when reporting.
- Connector registration passes that callback without constructing either connection while the queue manager is built.
- Keep the three provider container-array conversions to `make()`.
- Keep the queue guide's statement that uncaught background/deferred exceptions are reported through the application exception handler.

Rewrite `QueueServiceProviderTest` so standalone tests prove manager construction is lazy, both connections receive callable reporters, both reporters reach the exception handler, and both accept a null reporter when no exception handler is bound. Remove reload assertions. Remove `QueueManager::forgetConnections()`, its facade tag/tests, and the hook-only reset methods on `QueueFake`, `MailFake`, and `NotificationFake`.

### Foundation correctness and cleanup

Keep:

- `ext-posix` in the Foundation package metadata because `DownCommand` and `UpCommand` already call `posix_kill()` through `ReloadsWorkers`.
- Native `string` parameters on CLI/HTML dumper `register()` methods, but restore their `void` return type.
- `CliDumper`'s narrowed `OutputInterface` and `string` properties. Keep `$output` as a separate property with its WHY comment because promotion collides with Symfony's inherited `$output` PHPDoc in PHPStan.
- Treat every non-null `VAR_DUMPER_FORMAT` value as a Symfony handler-registration guard: temporarily remove it around the complete format dispatch and restore it in `finally`. Hypervel installs its source-aware handler for `cli`, `html`, blank, and unknown values while leaving `server` and TCP formats to Symfony. Keep PHPDBG on the CLI path and do not retain a dumper instance on the provider.
- `ResolvesSourceHref` as the editor-link-only concern. `ResolvesDumpSource` composes it; exception `Frame` uses it directly and no longer carries an unused compiled-view property or dump-source static API. Keep the corrected static cleanup owner and focused Frame/CLI dumper tests.

Document the property contract on each concern: `ResolvesSourceHref` requires `$basePath`; `ResolvesDumpSource` requires `$basePath` and `$compiledViewPath`. Keep `CliDumper::supportsColors()` documented with a Laravel-style method title.

Keep `AfterEachTestSubscriber` without its former `Frame::flushState()` call. `Frame` no longer owns static state or exposes that method after moving to the href-only concern.

Remove `ResolvesDumpSource::setCompiledViewPath()`, dumper instance retention, Foundation's reload hook, and reload-only dumper tests. Restore the default stdout logger refresh in `WorkerStartCallback`; keep useful lifecycle-order coverage without provider-hook assumptions.

Keep the boot-safety docblocks added to `Redirector::setSession()` and the shared `FileViewFinder` path, hint, extension, and lookup-cache mutators. Restore `UrlGenerator::setRequest()` to `Tests only.` and remove `setAssetRoot()`. Restore `WorkerCachedMaintenanceMode::flushCache()` to a title-only docblock because `activate()` and `deactivate()` legitimately call it during maintenance transitions; a `Boot or tests only.` warning would be false.

### Translation corrections

Keep three independent fixes while removing reload replay:

1. The constructor validates and assigns the worker's base locale without writing a coroutine-local override. Keep the Laravel-style boot-only `setBaseLocale()` API so boot code can change the shared default without impersonating a request, and keep its facade tag and README explanation. Retain the constructor's delegation to `setBaseLocale()`; unlike the JWT and Telescope constructor delegation being removed, it centralizes locale validation and is not tied to reload hooks.
2. One `assertValidLocale()` predicate validates constructor/base locale, request locale, and fallback locale. Keep `FileLoader`'s matching trust-boundary predicate and cross-reference.
3. `addLines()` must not make an unloaded group appear loaded and suppress its language file.

Implement the third fix with transient pending operations:

```php
if ($this->isLoaded($namespace, $group, $locale)) {
    Arr::set($this->loaded, "{$namespace}.{$group}.{$locale}.{$item}", $value);

    continue;
}

$this->pendingLines[$namespace][$group][$locale][] = [
    'item' => $item,
    'value' => $value,
];
```

`load()` must load the file first, replay pending operations in call order, publish the loaded group, then remove that locale's pending operations. Empty namespace/group buckets are harmless bounded metadata and do not justify extra cleanup branches. If loading throws, pending operations remain for a later attempt. `setLoaded()` replaces all loaded state, so it also clears pending operations; this matches its existing replacement semantics and prevents unreachable pending data. Do not retain permanent replay data, deduplicate parent/child writes, load eagerly, or add another public cache-reset API.

Do not load eagerly from `addLines()`: providers can add namespace hints or loader paths later in the boot sequence, and an early load would permanently cache an incomplete group. `FileLoader::loadNamespaced()` returning an empty array for an unregistered namespace lets pending package lines wait safely for a later hint. Clearing pending operations in `setLoaded()` is required because groups supplied through that method are already loaded and `load()` will never replay those operations.

Keep focused tests for base-locale ownership/coroutine isolation, immediate invalid fallback rejection, pre-load file preservation and override behavior, and ordered parent/child writes. Remove refresh/replay tests, `forgetLoadedGroups()`, its facade tag, and `TranslationServiceProviderTest`. Keep `TranslationServiceProvider` responsible only for constructing the translator from current config.

### General source quality

Keep all three `ViewServiceProvider` container-array conversions to `make()`. Remove its reload hook, `Compiler::reloadConfiguration()`, Blade facade tag, and reload tests while preserving later `0.4` edits.

Resolve Fortify configuration consistently through the configuration contract. In Sentry's rewritten log-channel registration, use typed getters without duplicate defaults for keys supplied by framework/package configuration. Cache's worker-start comments must name the `BeforeWorkerStart` dotenv/config rebuild rather than claim ordering after every worker-start listener.

Keep unrelated native test typing improvements made to `FrameTest`. Do not manufacture edits to generated Saloon facade metadata; it has no API change to retain.

## Remove Provider Refresh Surfaces

Remove hooks and their imports/docblocks from these providers:

- Auth and Password Reset; Broadcasting; Bus; Cache; Concurrency; Cookie; Database; Encryption; Filesystem; Foundation; Hashing; Inertia; JWT; Log; Mail; Notifications; Permission; Queue; Rate Limiter; Reverb; Routing; Saloon; Scout; Sentry; Session; Socialite; Telescope; Translation; View.

Remove APIs added only to support those hooks:

| Owner | Remove |
|---|---|
| Password broker manager | `forgetBrokers()` |
| Cache manager | `forgetDrivers()` |
| Filesystem manager | `forgetDisks()` |
| Log manager | `forgetChannels()` |
| Queue manager | `forgetConnections()` |
| Multiple-instance manager | `forgetInstances()` |
| URL generator | `setAssetRoot()` |
| Blade compiler | `reloadConfiguration()` and its reload-only normal-property reshaping |
| Dump-source concern | `setCompiledViewPath()` |
| JWT claim factory and manager | `reloadConfiguration()` methods and constructor delegation added for them |
| Telescope database repository | reload-only setters and constructor delegation; keep `DEFAULT_CHUNK_SIZE` as the canonical fallback for optional storage config |
| Translator | `forgetLoadedGroups()` and permanent registered-line replay state |
| Queue, Mail, Notification fakes | hook-only reset methods |

Restore one-pass provider implementations where reload caused an otherwise unjustified extraction, including Saloon connection registration and Sentry client construction. Preserve Sentry's later typed-config changes while removing client rebinding, `BacktraceHelper` replacement, and reload-only config mutation tracking.

Restore ordinary config mutation in Fortify, Horizon, and Sentry. Remove `applyAndRecord()` closures and comments introduced solely to recompute derived values during provider reload. Remove the master-snapshot comments from `GrpcServiceProvider` and `RegisterProviders`; they only contrast against the rejected worker-hook design.

Regenerate or lint first-party facades after source cleanup. The final generated surface must retain only real APIs: HTTP handler clearing and Lang base-locale setting remain; reload/reset tags for Blade, Cache, Concurrency, JWT, Lang loaded groups, Log, Password, Queue, Rate Limiter, Storage, and URL disappear.

## Tests and Fixtures

Delete these hook-only test files entirely after re-verifying each target against the synchronized tree:

- `tests/Broadcasting/BroadcastServiceProviderTest.php`
- `tests/Bus/BusServiceProviderTest.php`
- `tests/Concurrency/ConcurrencyServiceProviderTest.php`
- `tests/Cookie/CookieServiceProviderTest.php`
- `tests/Filesystem/FilesystemServiceProviderTest.php`
- `tests/Log/LogServiceProviderTest.php`
- `tests/Notifications/NotificationServiceProviderTest.php`
- `tests/Server/ServerReloaderTest.php`
- `tests/Session/SessionServiceProviderTest.php`
- `tests/Translation/TranslationServiceProviderTest.php`

In modified pre-existing test files, remove only hook/reset/replay cases and now-unused imports/helpers. Preserve all unrelated coverage and later `0.4` changes. This includes Auth, Password, Cache, Core worker startup, Database, Encryption, Hashing, Horizon, Inertia, JWT, Mail, Permission, Queue, Rate Limiter, Reverb, Routing, Saloon, Scout, Sentry, Socialite, Telescope, Translation, View, manager, fake, and facade tests. In `HorizonConfigTest`, restore the normal `'config'` resolution expectation when `normalizeConfig()` stops using `ConfigMutationTracker`.

Retained fixes keep or gain focused regressions in their natural existing files:

- Container alias invalidation.
- Cache table sealing and server-start initialization.
- Object Pool and HTTP pre-fork cleanup.
- Lazy Queue background/deferred construction and exception reporting.
- Explicit dumper format registration for known, blank, and unknown values; CLI output typing; href concern ownership; and stdout logger startup refresh. Dumper selection assertions must not depend on captured local variable names.
- Translation base-locale isolation, fallback validation, and pre-load `addLines()` behavior.
- JWT certificate command output requiring process restarts.

No fixture or helper that exists only for a deleted test may remain.

## Documentation

- In `providers.md`, keep the corrected explanation that providers register and boot before Swoole forks workers and that workers inherit this state. Keep the corrected deferred-provider explanation. Remove the reload contract section, TOC entry, sample, and cross-link.
- In `deployment.md`, remove `ServerReloader` injection and every claim that provider-owned services refresh. Explain in Laravel-docs prose that `reload` coordinates its listed commands and `server:reload` replaces event/task workers. Each replacement worker re-reads dotenv and rebuilds the config repository, but services that already copied configuration retain their inherited state, so a full server/process restart is required for new code, environment, configuration, certificates, routes, listeners, or other boot-time application state. Preserve Swoole table restart guidance. Explain that invalid configuration causes immediately respawned workers to fail repeatedly until corrected, after which the next worker starts normally. Keep accurate PID/signal failure guidance and process boundaries without presenting worker replacement as an application reload.
- In `reverb.md`, replace the claim that `server:reload` loads code changes with truthful full-restart guidance. Keep the explanation that exiting Reverb workers drain connections and clean up their worker-owned resources.
- Remove the reload-hook paragraph from `http-client.md`.
- Keep the background/deferred exception-reporting sentence in `queues.md`.
- Keep the Translation README's concise `setBaseLocale()`/fallback explanation.
- Change the JWT certificate command warning, its test, and `jwt.md` warning to require restarting every long-running application process; remove `server:reload` as a supported certificate-rotation step.
- Insert the original stale config-derived services entry into the current `docs/todo.md` verbatim. Do not restore the file or other historical hunks, and do not add decision history or a proposed replacement architecture there.
- Delete `docs/plans/2026-08-08-1956-server-reloader-and-worker-configuration-refresh.md`. This reversion plan remains the authoritative implementation record.

## Implementation Order

1. Remove the contract, provider dispatch, programmatic service, and command adapter; restore direct server command and worker-start logger behavior with their tests.
2. Remove provider hooks package by package. In the same package slice, remove hook-only reset APIs, facade tags, tests, fixtures, imports, and comments so no temporary dead surface remains.
3. Reshape and verify each retained fix: Container, Cache table sealing, Object Pool, HTTP pre-fork cleanup, Queue connector callbacks, Foundation dumper/href cleanup, Translation, View/Queue container calls, and Foundation metadata.
4. Regenerate/lint facades and perform broad stale-symbol searches.
5. Update user docs, README, JWT command output/test, restore the todo entry, and delete the completed feature plan.
6. Run every changed test file immediately after its edit. After each coherent package slice, run the affected package tests.
7. Run `composer fix` once at the final checkpoint, then self-review every changed source/test/doc file against current callers, retained behavior, later `0.4` changes, Laravel API compatibility, hot-path cost, and stale-code searches.

## Verification

### Focused checks

Run the directly affected files as they change, including:

- Container, Cache manager/provider/Swoole table, Object Pool provider, HTTP connection/provider, Queue manager/provider, Core worker callback, Foundation provider/listener/static/frame, Server command, Translation translator/coroutine, View compiler/provider, JWT certificate command, and facade docblock tests.
- Every remaining pre-existing provider test edited to remove reload cases.

Run focused package directories after their slices where several files interact.

### Structural checks

- No `ReloadsConfiguration` or `ServerReloader` reference remains in `src/`, `tests/`, or user documentation.
- No provider declares `reloadConfiguration()`; `StdoutLogger::reloadConfiguration()` remains because worker startup still calls it.
- No removed manager/fake/compiler/translator reset API or generated facade tag remains.
- No test name, helper, fixture, comment, or documentation paragraph describes provider-owned worker refresh.
- Current `0.4` container `make()` conversions, typed config changes, and other later edits remain intact.
- Facade lint reports no generated-docblock drift.

### Final checkpoint

Run `composer fix` once. It covers formatting, PHPStan, the parallel suite, Testbench, and dogfood checks. Then perform a clean-tree diff review and peer code review.

## Completion Criteria

- Provider-based configuration reload machinery is absent with no stale code, tests, facade metadata, docs, comments, or fixtures.
- `server:reload` truthfully provides Swoole event/task worker replacement and does not promise a fresh application.
- Deployment and JWT guidance require full process restart where inherited application state must change.
- Every retained fix has an independent rationale and focused regression coverage.
- Translation fixes the pre-load `addLines()` defect without permanent replay storage or a public cache-reset API.
- No Laravel API is removed; only feature-specific Hypervel APIs are deleted.
- No request hot path gains work, retained startup/fork work is bounded, and no speculative replacement architecture is introduced.
- Targeted tests, facade lint, `composer fix`, self-review, and peer review are green.
