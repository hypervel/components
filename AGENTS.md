# Hypervel Components Agent Guide

## Background

Hypervel is a standalone Laravel-style Swoole framework. The public API should stay close to Laravel wherever possible, while the internals are adapted for long-lived Swoole workers, coroutine safety, and high performance.

Laravel is the main API reference. Hyperf is a historical and architectural reference for some lower-level Swoole/coroutine packages, but Hypervel code should follow current Hypervel patterns rather than copying Hyperf structure mechanically.

Most work in this repo today is framework bug fixing and enhancement, or porting Laravel packages. Hyperf-to-Hypervel porting is largely done — the conversion guide lives in `docs/ai/porting-hyperf.md`, read when maintaining previously ported code or doing the occasional remaining port.

This file is intentionally detailed because agents trained on Laravel will otherwise assume Laravel's request lifecycle and miss Hypervel's Swoole/coroutine constraints.

## Mental Model

When working on Hypervel, start from this frame:

- Hypervel is Laravel-shaped at the API level.
- Hypervel is not request-per-process PHP — workers are long-lived, and PHP state does not disappear after each request.
- Singletons, static properties, manager registries, callbacks, config, and cached metadata can persist for the worker lifetime.
- Per-request state must live in coroutine-scoped storage (CoroutineContext), not process-global state.
- Laravel source is the default parity reference, but Laravel internals often assume per-request bootstrap and are not optimized to take advantage of static caching of immutable state.
- Hyperf source can be useful for Swoole/coroutine behavior, but Hyperf container/config/listener patterns are not the target architecture.

## Repository Layout and Commands

Always run framework commands from the repository root. This is the directory that contains this `AGENTS.md`, the root `composer.json`, `phpunit.xml.dist`, `phpstan.neon`, `src/`, and `tests/`.

Key paths:

| Path | Description |
|------|-------------|
| `src/boost/docs/` | Hypervel documentation. |
| `src/testbench/` | Hypervel's testbench package (port of `orchestra/testbench`). Contains `TestCase`, attributes (`WithConfig`, `WithMigration`), and bootstrap logic. Part of the monorepo, not a vendor dependency. |
| `src/testbench/hypervel/` | Committed Hypervel app skeleton. On bootstrap, testbench clones this to a disposable temp directory (`/tmp/hypervel-components-testbench-{token}-{pid}/`) and points `BASE_PATH` at the clone — tests that write files under `BASE_PATH` (generated providers, migrations, fixtures, etc.) hit the temp copy, not this committed path. The clone is deleted on shutdown and stale copies from crashed runs are cleaned up. Testbench also exports `TESTBENCH_BASE_PATH` so subprocesses can locate the active runtime. |
| `src/testbench/workbench/` | Committed shared test fixtures (NOT cloned). Subdirs are psr-4-mapped from the monorepo root as `Workbench\App\*`, `Workbench\Database\Factories\*`, `Workbench\Database\Seeders\*` so multiple tests can reuse the same models/factories/seeders without redefining them. Not the runtime app — that's the disposable clone of `src/testbench/hypervel/`. |
| `docs/ai/` | Supplementary agent guides, including `porting-hyperf.md` (Hyperf conversion mechanics) and `differences-vs-laravel.md` (user-facing Laravel differences). |
| `docs/todo.md` | Tracked gaps and improvements worth doing. |

### Running tests

**Always use `composer test:parallel`** to run the full components test suite. This runs raw ParaTest with Hypervel's parallel testing flag supplied by `phpunit.xml.dist`.

**Always use `composer test:testbench`** after Testbench changes. This runs the scoped Testbench package-mode contract suite through the real `package:test` command.

ParaTest defaults to the machine CPU count when no process count is specified. Redis integration runs need `REDIS_TEST_DB_MIN` / `REDIS_TEST_DB_MAX` to cover the chosen worker count, or an explicit `--processes` / `-p` value that fits the configured range.

`TEST_TOKEN` is runner-owned worker identity. Tests must use the assigned value unless they explicitly exercise token-dependent behavior; such tests must restore every environment source they mutate or run in an isolated subprocess.

To run a single test class: `./vendor/bin/phpunit --no-progress path/to/TestClass.php`.

## Change Workflow

### Classify the change

Before editing, identify the work as one of:

- A framework bug fix.
- A framework enhancement.
- An update from an existing package's upstream.
- A new package port.

This determines which upstream source and tests to compare. Incremental updates from an existing upstream follow the workflow below together with the applicable Porting Policy and rules. New package ports follow the full workflow under Porting Packages. Bug fixes and enhancements follow the workflows below. In every case, read the package README for the upstream reference and the relevant Hypervel source and tests before editing.

### Incremental upstream updates

When bringing an existing upstream feature, fix, or API change into Hypervel:

1. Find the originating implementation pull request and any corresponding documentation pull request. Use them to understand the reason for the change and identify the complete set of files changed when it was introduced.
2. Inspect every file changed by those pull requests, including source, contracts, tests, fixtures, configuration, package metadata, and documentation. Search the current upstream branch for the added symbols as well, since later changes may have introduced additional consumers or coverage.
3. Treat the historical pull-request diffs as discovery and history only. Port the actual source, tests, and documentation from the current checked-out upstream default or development branch. Follow-up fixes and documentation improvements may have changed the final implementation or coverage.
4. Compare that current upstream surface with the Hypervel implementation and apply the approved Hypervel adaptations under Porting Packages. If the upstream feature has no user-facing documentation, add proportionate Hypervel documentation at its natural public surface.

### Audit what you modify

Modifying code is an implicit assessment of it. Whenever you edit a method, move code, copy a file, or port from upstream, check what you touch for:

- Hardcoded values that should be derived (namespaces, defaults)
- Defensive code that masks bugs
- Conventions that diverge from how the framework actually does it
- Deprecated APIs or dated patterns
- Issues in the code right next to what you're changing

Anything found follows When to Stop and Report — "the task didn't ask me to fix that" and "I copied it verbatim" are not reasons to stay silent. The trigger is modification: files read only for context don't need a line-by-line audit.

### Framework bug fixes

1. Reproduce the bug with the smallest useful test.
2. Trace the exact failing code path and identify the root cause before changing source.
3. Compare the matching Laravel or package-upstream behavior when it defines the contract.
4. Explain the root cause and your recommended fix, and wait for approval unless already told to proceed. Even when told to proceed, include a brief root-cause explanation.
5. Fix the underlying defect — never add a workaround for incorrect framework code.
6. Add a regression test for the bug and run that test file immediately.
7. Check types, API parity, coroutine isolation, and worker-lifetime state around the changed code.
8. Complete the verification order below.

### Framework enhancements

1. Read the related Hypervel APIs and tests. Check Laravel or the package upstream for an established public API and behavior.
2. Decide which state is local, coroutine-scoped, or worker-scoped before writing code — see Coroutine and Worker-Lifetime State.
3. Keep Laravel API parity unless Hypervel's runtime requires a deliberate difference. Record intentional differences as described under Porting rules.
4. Test the public behavior, failure paths, coroutine isolation, and cleanup the feature needs.
5. Complete the verification order below.

### Verification order

1. Run each changed or new test file immediately after working on it — never defer.
2. Run the package or focused tests the change affects.
3. Run phpstan on source changes (see Static Analysis).
4. Run php-cs-fixer.
5. Run `composer test:parallel` for the full suite.
6. Run `composer test:testbench` after Testbench changes.

## Development Conventions

The Working rules and the Avoid overengineering rules apply to all work in this repo. The Code conventions apply to newly written code. Laravel package ports preserve upstream naming, structure, and style except for the approved adaptations under Porting Packages. Hyperf ports follow `docs/ai/porting-hyperf.md`.

### Working rules

- **Avoid bulk modification tools** — tools like `sed` and `replace_all` often have unwanted side effects. Never use bulk modification tools without explicit user approval; prefer manual edits. When approved, run them in multiple passes that each target long, exact, case-sensitive strings to avoid accidental changes.
- **One file at a time** — never work on multiple files simultaneously. This governs manual editing; package-manager and formatter runs may touch multiple files.
- **Never use Write to overwrite files** — always use Edit for targeted updates.
- **Always use `cp` to copy files and `mv` to move/rename** — never read → write new version → delete old version.
- **Grep broadly — never assume a subdir** — when searching for any symbol, class, method, or pattern, grep across the whole `src/` (or `tests/`) tree, not a specific package subdir. Assumptions about where something lives produce false negatives.
- **Read the source before describing behavior** — never state how code behaves from memory or Laravel assumptions. Hypervel's coroutine runtime breaks many Laravel assumptions; if you haven't read the relevant source, read it first.
- **Match existing documentation style** — User-facing documentation must match the style and language of nearby Hypervel docs. Use simple, human-friendly prose in Laravel's documentation style, and avoid internal jargon, stiff wording, needless verbosity, and details that do not help readers use the feature correctly.
- **Revert failed attempts immediately** — when a fix doesn't work, revert it before trying another approach. Don't leave experimental code in place.
- **Use `composer require` for root dependencies** — the root `composer.json` has a lockfile, so dependency entries go through Composer, never hand-edits. Direct edits are fine for metadata sections no command can write (`autoload`, `replace`, `extra`, `scripts`) and for the sub-package `src/{package}/composer.json` files, which have no lockfile.

### Avoid overengineering

Build complete, long-term solutions, not MVPs or local workarounds. A broad change is correct when the root cause is in shared code, but every added mechanism must solve a real problem.

- Require a supported, realistic path and meaningful harm before treating a concern as a defect. Rare failures count when they can actually occur in supported production use; merely conceivable states do not.
- Prefer the simplest existing Laravel or Hypervel API, PHP feature, or database constraint. Do not duplicate framework behavior with package-owned machinery.
- Do not add a new mechanism merely because it sounds robust, flexible, or potentially useful — for example, a registry, retry loop, configuration option, or extension point. It must solve a verified problem, meet a clear approved requirement, support a clearly likely need whose shape is understood, or remove greater complexity elsewhere.
- Do not add machinery to preserve invariants across deliberate Laravel-style escape hatches such as `withoutEvents()`, quiet methods, raw builders, raw SQL, disabled middleware, or direct transport access unless the public contract explicitly promises that behavior.
- For coroutine or worker-lifetime concerns, identify the concrete shared state, a realistic interleaving, and the resulting leak or failure before adding isolation, locking, cleanup, or caching machinery.
- Fix related symptoms in the lowest shared layer that owns the behavior instead of adding separate defensive paths. The size of the resulting change is not a reason to patch around the defect.
- A capability may be worth adding before its first use when the need is clearly likely and its design is understood. Do not add generic flexibility for hypothetical consumers.
- Avoiding overengineering never justifies an incomplete fix, weaker correctness, missing security or isolation safeguards, or deferring a worthwhile improvement.

### Code conventions

- **Modern PHP 8.4+ with full typing** — use constructor property promotion, readonly properties, enums, match expressions, named arguments, and attributes where they fit. Every file declares `strict_types=1`; parameters, return types, and properties are natively typed wherever PHP and the inherited API permit (e.g. `resource` cannot be represented as a native PHP type).
- **Newly written classes use dependency injection** — inject contracts (e.g. `Repository $config`, `CacheRepository $cache`) via constructor or method injection rather than helpers, facades, or `new` for framework services. Dependencies become explicit in signatures and tests swap them in directly, without facade-mocking machinery. Fall back to `Container::getInstance()->make(...)` only where injection isn't possible — static contexts and traits, like the testing package's Concerns. Helpers (`config()`, `cache()`) are fine in non-class contexts such as route and config files.
- **Never convert ported code to dependency injection** — ported code keeps its upstream facade, helper, and instantiation style. Converting it restructures classes and breaks 1:1 upstream mergeability.
- **Import classes, don't use FQCNs** — always add a `use` statement and reference the short name. The only exceptions are places where FQCNs genuinely make more sense, such as middleware arrays and similar config-style identifier lists.
- **Group traits in `Concerns/`** — follow the package's existing convention if it already has a `Concerns/` or `Traits/` directory; never mix both in one package. New Hypervel-original packages always use `Concerns/`; a newly ported package keeps its upstream directory name.
- **Use Laravel observer conventions** — place Eloquent observers in a top-level `Observers/` directory. Register model-specific observers with `#[ObservedBy(...)]`; use `observe()` only for dynamic registration or observers supplied automatically by a reusable concern.
- **Use attributed local scopes** — define local Eloquent query scopes as protected methods marked with `#[Scope]`, rather than legacy public `scopeFoo()` methods. Use separate scope classes only for genuine global scopes.
- **No class docblocks unless warranted** — only add a class-level docblock if something unusual or complex needs explanation: purpose, architectural role, usage patterns. Never write one that inventories the class — trait lists, method summaries, "registers X, configures Y" — that duplicates the members' own docblocks and goes stale. Method docblocks (title only, Laravel-style, imperative mood: "Return", not "Returns") are always added. A body can accompany the title for complex methods that need further explanation.
- **Add comments where they're genuinely useful** — a short WHY for logic that isn't obvious from reading the code, the reason behind a bug fix, or logic that's coupled to code in other files and hard to understand in isolation. Don't comment what the code does, and don't annotate framework divergences, routine casts, or type normalizations. Match the surrounding comment density.
- **Don't make classes final by default** — keep classes open; add `final` only when it protects a real invariant or avoids a concrete framework/API problem, e.g. immutability, coroutine-safety, or a security guarantee.
- **Place methods logically, not at the end** — group new methods with related ones (getters with getters, setters with setters). Two exceptions: preserve upstream order when merging ported code (see Porting rules), and `flushState()` has its own placement rule (see Static state and test cleanup).
- **Only extract methods when justified** — extract only when the logic is complex enough to benefit from a name, it's likely to be reused, or two or more methods call it. Don't extract a simple one-liner with a single caller.
- **Never abbreviate variable names** — `$attributes` not `$attrs`, `$connection` not `$conn`.
- **Enum cases use PascalCase by default** — `case Pending` not `case pending`, `case OauthToken` not `case OAUTH_TOKEN`. Applies to both backed and unit enums. **Exception:** when `->name` is used as an external identifier (cache keys, cookie names, filesystem disks, rate limiter names, timezone strings) or appears in serialized output (e.g., `toArray()` returning `'name' => $this->name`), match the consuming system's convention (typically lowercase or snake_case).
- **Strict comparisons only** — always `===` and `!==`, never `==` or `!=`. Loose comparison causes subtle bugs. When converting an upstream loose comparison, match the operand's real type — `$value === 0.0` for a float, not `=== 0`. If upstream relies on loose coercion intentionally, normalize the value explicitly before comparing strictly — don't silently change the contract.
- **Prefer union types over `mixed` when all types are known** — `mixed` is only for truly unconstrained values or cases that cannot be safely narrowed after control-flow analysis.
- **Type decisions must be evidence-based** — check corresponding Laravel/Hyperf signatures and docblocks as a reference, then trace the real control flow through method bodies across all callers and callees to confirm the types are correct.
- **Fail fast with framework and PHP exceptions** — don't add guards, wrapping, or defensive checks unless they handle a reachable invalid case or materially improve the error, and never swallow exceptions. If code would fail anyway (e.g. null passed to a typed parameter), let it fail naturally instead of adding a check that throws a custom exception — the stack trace is enough to diagnose.
- **Use semantic column types in migrations** — `jsonb()` over `json()`, `uuid()` / `foreignUuid()` / `uuidMorphs()` over strings for UUIDs, `ipAddress()` and `macAddress()` over `string()`. PostgreSQL gets the real types (`jsonb`, `uuid`, `inet`, `macaddr`); the other supported databases fall back automatically to compatible types.
- **No arbitrary string lengths** — use `->string('name')`, not `->string('name', 100)`. Don't invent limits; specify a length only when the domain or protocol defines one — exact (UUID: 36, ULID: 26, sha-256 hex token: 64) or a defined maximum (IPv6 address: 45).
- **No database enums** — use string columns plus PHP enums. Adding a value to a database enum requires a migration.
- **Prefer `timestamp` over `timestampTz` in migrations** — store times in UTC with plain `timestamp` columns, matching the normal convention in Laravel and Hypervel first-party migrations. Reserve `timestampTz` for columns that genuinely need database-level timezone semantics, such as integrating with an existing timezone-aware schema or columns written by clients in different session timezones. The schema API supports both — this is a column-choice convention, not an API restriction.
- **Guard optional event dispatches with `hasListeners()`** — before constructing and dispatching framework events, guard them with `hasListeners()` so hot paths skip event overhead when nobody is listening. Do not guard dispatches where dispatching is the side effect, such as jobs, broadcasts, webhooks, or command bus calls.
- **Use `Sleep::usleep()` / `Sleep::sleep()` for delays in source code** — `Sleep` is fakeable in tests. Use raw `sleep()` / `usleep()` only where real time must pass, such as test harnesses and external-process polling.
- **Use immutable dates by default** — Hypervel defaults to `Hypervel\Support\CarbonImmutable`, including where Laravel uses mutable Carbon. Create public or application-configurable dates through the `Date` facade or date helpers, and use exact `CarbonImmutable` for framework-owned internal or held values. Type configurable Carbon boundaries as `CarbonInterface` and native or third-party boundaries as `DateTimeInterface`. Capture the return value of every date modifier whose result must persist. Use `Hypervel\Support\Carbon` only for explicit mutable opt-out or conversion behavior.
- **Use typed config getters, no call-site defaults** — prefer `$config->string()`, `$config->integer()`, `$config->float()`, `$config->boolean()`, `$config->array()` over `$config->get()` for any key that can't legitimately be null. Typed getters fail fast on misconfiguration — an `InvalidArgumentException` naming the key, instead of a wrong type propagating silently — and give phpstan the real type. Defaults live in config files: `LoadConfiguration` merges the framework base config, and package providers must merge their defaults through `mergeConfigFrom()`. A call-site default is then dead code that can drift from the real default. Declare every key in the package's config file and call the getter without a default — a missing key then fails loudly instead of being silently papered over. Exceptions: bootstrap code that runs before or without config merging (e.g. `LoadConfiguration` itself), and genuinely nullable keys, which keep `get()`.
- **Env var naming** — ported config keeps its upstream env var names. New Hypervel-specific keys start with the config file's domain prefix (`SERVER_`, `APP_`) with the clearest name for the rest, e.g. `SERVER_WORKERS` for the `Constant::OPTION_WORKER_NUM` server option. If a config value mirrors another config key, reuse that key's env var instead of defining a duplicate — e.g. the Slack log channel username falls back to `APP_NAME`.
- **Use `resolve...Using` for Hypervel-owned config resolvers** — prefer this naming for callbacks that resolve config-derived values, unless an established Laravel domain convention already exists, such as `redirectUsing()`.
- **Always use American English spelling** — E.g., "behavior" vs "behaviour", "utilize" vs "utilise".

## Container

Hypervel's container keeps Laravel's API surface — `bind()`, `singleton()`, `scoped()`, `instance()`, aliases, contextual bindings — with resolution adapted for long-lived Swoole workers. `make()` and `get()` resolve identically; `get()` is just the PSR-compliant exception wrapper. Use `make()`, and use it instead of array access too: `offsetGet()` always returns `mixed`, while `make()` carries class-string generics phpstan can follow, `make()` can take parameters, and `$app[$key] = $value` is a hidden `bind()`. Converting `$app['...']` in ported code to `make()` is an approved modernization (see Policy under Porting Packages). `Container::getInstance()` auto-creates via `??= new static()`, so it always returns a container.

A user-facing summary of these differences lives in `docs/ai/differences-vs-laravel.md` — keep it consistent with this section when container behavior changes.

### Resolution semantics vs Laravel

The critical difference: **unbound concrete classes are auto-singletoned**. In Laravel, `make()` on a class with no binding builds a fresh instance every call. In Hypervel, the first resolution caches the instance (in `$autoSingletons`) for the worker lifetime — in Swoole's long-running process model services are stateless singletons by design, and re-creating them on every resolution wastes CPU and memory. Explicit bindings override this (bound classes follow their binding type), and `SelfBuilding` classes are excluded.

| Registration | Laravel | Hypervel |
|---|---|---|
| Unbound concrete class | Fresh instance every `make()` | **Auto-singletoned** on first `make()`; cached for the worker lifetime |
| `singleton()` / `#[Singleton]` | Cached in `$instances` | Same — but the worker serves many requests, so the instance is shared across all of them |
| `scoped()` / `#[Scoped]` | A worker-lifetime singleton the runtime flushes between requests (`forgetScopedInstances()`) | Cached per coroutine via `CoroutineContext` — isolated between the concurrent requests in one worker |
| `bind()` | Fresh instance every `make()` | Same |
| `make($abstract, $params)` / `makeWith()` | Contextual build — never cached | Same — parameters bypass the singleton, scoped, and auto-singleton caches |
| `build($concrete)` | Constructs the concrete directly, bypassing bindings and caches for the top-level class | Same; Hypervel adds `buildWith($concrete, $params)`. Nested constructor dependencies still resolve through the container |
| `implements SelfBuilding` | Container calls the class's static `newInstance()` | Same, and it also skips auto-singletoning |

Rules that follow from this:

- **Classes that capture per-request data in their constructor or accumulate mutable state must not be auto-singletoned** — pick the correct lifetime: `scoped()` for one instance per coroutine/request, `bind()` for a fresh instance per resolution, `build()`/`buildWith()` for direct construction at the call site, or `SelfBuilding` for class-controlled construction. An existing class like that being auto-singletoned is a coroutine-safety bug — STOP and report it.
- **Most classes are safe as auto-singletons:** services, middleware, listeners, factories, formatters — stateless or process-global by nature.
- **Do not use `build()` as a drop-in freshness replacement for `make()`** when explicit bindings, test swaps, aliases, or resolving callbacks must be honored — it bypasses top-level binding lookups, aliases, and caches by design.

### Choosing a binding type

- Stateless and shared for the worker lifetime → `singleton()`.
- Fresh mutable object per resolution → `bind()`.
- State isolated per coroutine / request → `scoped()`.
- Concrete class with no separate abstract → don't bind it at all; auto-singletoning covers it.

### Binding patterns

**1. Canonical string key — use a closure with `new`:**

```php
// The concrete (AuthManager) is listed as an alias for 'auth', so
// singleton('auth', AuthManager::class) would create a circular resolution cycle:
// 'auth' -> build AuthManager -> getAlias(AuthManager) -> 'auth' -> infinite loop
$this->app->singleton('auth', fn ($app) => new AuthManager($app));
```

**2. Abstract is not in the alias table — use string concrete:**

```php
// Neither FormatterInterface nor DefaultFormatter are aliases for anything,
// so the container can resolve this directly without cycles.
$this->app->singleton(FormatterInterface::class, DefaultFormatter::class);
```

**3. Abstract and concrete are the same class — do not bind at all.** Hypervel's container auto-singletons unbound concrete classes on first resolution. An explicit `singleton(Foo::class)` is redundant:

```php
// Wrong: redundant; auto-singleton handles this.
$this->app->singleton(BroadcastManager::class);

// Correct: do not bind it. The first make(BroadcastManager::class) auto-singletons it.
```

### Aliases and canonical keys

Before binding core framework services, check `Application::registerCoreContainerAliases()`.

If the abstract or concrete participates in the alias table, choose the binding key carefully. `bind()` and `singleton()` store bindings under the exact abstract key passed; `resolve()` resolves aliases before lookup. Binding an alias instead of the canonical key can orphan the binding.

When adding a core alias, use the string key as the canonical abstract:

```php
// Wrong: contract is canonical.
\Hypervel\Contracts\Auth\Factory::class => [
    'auth',
    \Hypervel\Auth\AuthManager::class,
],

// Correct: string key is canonical.
'auth' => [
    \Hypervel\Auth\AuthManager::class,
    \Hypervel\Contracts\Auth\Factory::class,
],
```

For canonical string keys such as `'auth'`, `'cache'`, `'db'`, `'request'`, and similar framework services, prefer closure bindings:

```php
$this->app->singleton('auth', fn ($app) => new AuthManager($app));
```

Facades for these services should resolve the canonical container key instead of the concrete manager class.

Do not add new core aliases without need. Only add an alias when Hypervel needs it as part of its public container surface.

## Providers and Listeners

### Provider registration

When adding a package provider, register the provider and aliases through the package Composer metadata and the root Composer metadata, as described in the package skeleton workflow under Porting Packages. Add a provider to `DefaultProviders` only if every Hypervel application needs it at framework startup, such as auth, cache, database, session, validation, view, or low-level Swoole infrastructure. Optional packages such as Reverb, Scout, Telescope, Sentry, and Watcher should rely on package discovery instead.

For rare core services that must be available before normal providers are registered, stop and explain why before touching `registerBaseServiceProviders()`. Providers registered there run during the earliest application bootstrap, so this should be reserved for framework infrastructure needed by the boot process itself.

It is safe to have the same provider listed in both `registerBaseServiceProviders()` and `extra.hypervel.providers` when early loading is genuinely needed. `Application::register()` deduplicates providers by class name, and the discovery entry ensures standalone installs still load the provider.

### Listener registration

Register listeners in the service provider's `boot()` method using closures that resolve the listener from the container:

```php
public function boot(): void
{
    $events = $this->app->make('events');

    $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
        $this->app->make(AfterWorkerStartListener::class)->handle($event);
    });
}
```

Resolve from the container (`$this->app->make(...)`) rather than injecting or instantiating directly — this ensures constructor dependencies are resolved and the listener benefits from auto-singleton caching.

## Coroutine and Worker-Lifetime State

Decide where state lives before writing code:

| State | Storage |
|---|---|
| Immutable metadata shared by all requests | Static property cache or worker-lifetime singleton |
| Stateless service shared by all requests | `singleton()` or auto-singleton (see Container) |
| Mutable state for one request, operation, or coroutine | `CoroutineContext` or a `scoped()` binding |
| Fresh mutable object per resolution | `bind()` or contextual parameters |

- **Use `CoroutineContext` for invocation-scoped state** — anything that must not be visible to other concurrent coroutines in the same worker. Static properties and singleton fields leak across coroutines: whatever one coroutine sets becomes visible to all others in the worker. Use the established key-naming convention: `__<package>.<key>` value prefix, `_CONTEXT_KEY` / `_CONTEXT_KEY_PREFIX` constant suffixes, public only when other classes or tests reference the constant.
- **Configure process-global values only during worker boot** — config is a process-global singleton, so `Config::set()` during request handling changes behavior for every concurrent request in the worker. Never mutate config for request-specific behavior; use `CoroutineContext` or middleware instead. Provider boot-time configuration is fine — it runs once per worker.
- **Name static cache properties for what they store** — not with a `Cache` suffix; static properties in Swoole workers are caches by nature. Exception: matching an existing Laravel-ported pattern in the same class (e.g. `$classCastCache`, `$attributeCastCache` on `HasAttributes`).
- **Review worker-lifetime state explicitly** — whenever a change introduces or modifies static properties/caches, singletons or other long-lived state, STOP and report the Swoole persistence impact (memory leaks, cross-request behavior) with a recommendation.
- **Document worker-lifetime mutators** — when adding or touching a public method that mutates static state, singleton-held configuration, manager registries, cached drivers, global callbacks, or other worker-lifetime state, add a short warning to the method docblock if the method is intended only for boot-time configuration or tests. Use the tag-first format so humans and LLMs can recognize it quickly:
  - `Boot-only.` — for startup configuration methods
  - `Tests only.` — for test fakes, swaps, and resolver overrides
  - `Boot or tests only.` — for cache/registry clearing methods used during boot reconfiguration or test cleanup

  The second sentence should name the concrete failure mode, e.g. "The callback persists in a static property for the worker lifetime and affects every subsequent request." Do not add these warnings to methods that are genuinely safe for normal runtime/per-request use. If a method is commonly expected to be used dynamically but mutates shared worker-lifetime state, treat that as a coroutine-safety bug and STOP with a recommendation instead of just documenting it.
- **Flag static caching opportunities with recommendations** — if a path repeatedly computes expensive stable metadata and worker-lifetime static caching would be a clear win, STOP and recommend it (what to cache, expected benefit, and safety constraints).

Classes that use static caching need a `flushState()` method for test cleanup — see "Static state and test cleanup" under Writing Tests.

## When to Stop and Report

These rules apply to all work. "STOP" means: explain the situation, give the root cause and your recommended fix, and wait for approval before proceeding.

- **Stop on anything unusual** — missing dependencies, logic needing special consideration, things that don't make sense for Hypervel, etc. Investigate it, explain what you found, and give your recommendation. Do not proceed without approval. Do not dismiss it as theoretical without this investigation. If investigation finds no supported, realistic path or meaningful harm, report that conclusion briefly instead of presenting it as a defect or proposing machinery for it.
- **Never skip or stub things out** — no removing code, no commenting out with "TODO once X is ported" placeholders. If such a situation arises, STOP and explain with your recommendation.
- **Stop on any source code bug** — if phpstan or tests expose a bug in Hypervel source code (typing, logic, behavior), investigate, explain root cause, and provide a recommended fix for approval. Also STOP and report bugs found in the **upstream** Laravel/Hyperf source being ported (resource leaks, logic errors, missing cleanup, etc.) — explain the issue and recommend a fix. Upstream bugs must be fixed, not ported as-is.
- **Trace upstream differences before calling them bugs** — a difference from Laravel or an upstream package is not proof that Hypervel is wrong, and matching upstream is not proof that Hypervel is correct. A verified Hypervel defect remains a defect when upstream has the same problem.
- **Do not work around incorrect existing code to avoid churn** — if work exposes incorrect types, wrong logic, missing methods/classes, or other real defects in existing Hypervel code, fix the underlying code instead of adding compatibility hacks or local workarounds to sidestep the problem. Prioritize correctness and code quality over keeping the change small. For any non-trivial fix, STOP and explain the root cause and recommended change before proceeding.
- **Never weaken or drop tests to work around source issues** — if a test exposes source-side problems (wrong types, broken logic, missing classes/methods, signatures that diverge from Laravel, missing API parity, etc.), STOP and report the issue with a recommendation for the most correct fix. Never delete, skip, loosen assertions, or alter tests to make them pass against flawed source code. The test is the spec; the source gets fixed. For type errors specifically, "When tests expose source code type errors" under Writing Tests covers how to identify the correct type.
- **Never dismiss issues as "out of scope" or "pre-existing"** — when any issue surfaces (bugs, divergences, missing API parity, incorrect visibility, type inconsistencies, naming mismatches, etc.), always STOP and report it. Never use phrases like "out of scope", "pre-existing", "not part of this work", "separate concern", or "unrelated" to justify not reporting something. You are not permitted to decide what is or isn't worth addressing — only the user makes that call.

Worker-lifetime state has additional stop triggers — static/singleton state changes, unsafe public mutators, static caching opportunities, and per-request state captured by auto-singletons — listed under Container and Coroutine and Worker-Lifetime State.

### Handling failing tests

- **Easy fixes** (namespace typos, missing return types, a missed namespace update, etc.) — fix and continue.
- **Non-trivial failures** (behavioral changes, test logic issues, unclear root causes) — STOP and investigate: identify the root cause (missing feature, source bug, architectural difference), explain what's missing and what adding it would involve, report findings and wait for instructions. Investigate all failures thoroughly — don't assume a failure is caused by your change without confirming it.
- **You do not decide what tests to skip or remove.** Only the user makes that call after reviewing your investigation. Never comment out, skip, or avoid porting a test because the required functionality is missing. If the test covers functionality Hypervel should support, investigate the missing functionality, then STOP and report the root cause with your recommended fix. The one exception: tests for the approved unsupported features listed under Porting Laravel Tests are removed (not commented out) without asking. For any other test removal, STOP and explain what the test covers, why you believe it should not apply to Hypervel, and wait for approval.

## Writing Tests

These rules apply to all tests — new tests for framework work and ported tests alike.

### Avoid overengineered tests

Test supported public behavior, meaningful branches, verified regressions, and realistic coroutine or worker-lifetime failures. Do not add production APIs, branches, or defensive machinery solely to make speculative states testable. Do not require invariants to survive deliberate framework escape hatches unless the public contract promises that behavior.

### Directory layout

All tests live in `tests/{PackageName}/` (PascalCase). Tests that require external services go in `tests/Integration/{PackageName}/` — see Integration tests below.

### Base classes

**Never extend `PHPUnit\Framework\TestCase` directly.** Always use one of these:

| Class | Use When |
|-------|----------|
| `Hypervel\Tests\TestCase` | Unit tests, mocks only, no container needed. Tests that only need an isolated scratch directory stay here — use `ParallelTesting::tempDir()` (see Temp directories below). |
| `Hypervel\Testbench\TestCase` | Integration tests (needs container for facades, config, DB, etc.) **or any test that needs a full app skeleton / writes through `BASE_PATH`** — testbench clones a disposable runtime skeleton per run and exposes its path via `BASE_PATH` (and `TESTBENCH_BASE_PATH` for subprocesses), deleted on shutdown. Committed source is never mutated. |

Always call `parent::setUp()` in your setUp method.

### Test support files

All **standalone** test support files — PHP classes, non-class PHP files, and non-PHP files (JSON, SQL, images, templates, etc.) — go in a single **`Fixtures/`** directory (capital F). This matches Laravel's predominant convention. PHP classes in `Fixtures/` are PSR-4 autoloaded like any other test file. Helper classes used only by a single test file may be defined inline within that file (matching Laravel's convention).

### Helper class namespacing

Tests often define helper classes (models, stubs) with generic names like `User`, `Post`, or `Comment`. When multiple test files use the same namespace and define classes with the same name, PHP throws "Cannot redeclare class" errors.

**Use test-specific namespaces only for collision-prone helper classes** (matching Laravel's pattern):

```php
// WRONG - shared namespace causes conflicts for generic helper names
namespace Hypervel\Tests\Integration\Database;

class EloquentDeleteTest extends DatabaseTestCase { ... }
class Comment extends Model {}  // Conflicts with Comment in other files!

// CORRECT - test-specific namespace isolates generic helper names
namespace Hypervel\Tests\Integration\Database\EloquentDeleteTest;

class EloquentDeleteTest extends DatabaseTestCase { ... }
class Comment extends Model {}  // No conflict - different namespace
```

Use this when helper classes have generic names likely to appear in other test files. Do not add extra namespaces for helper classes whose names already include the tested feature or package context, such as `FailingHorizonInstallCommand` or `MissingProviderTelescopeInstallCommand`.

When a test-specific namespace is needed, the namespace includes the test class name as the final segment. This means:
- Each affected test file has its own namespace
- Generic helper classes can use simple names (`Comment`, `Post`, `User`)
- No `$table` properties needed (Eloquent derives `comments` from `Comment`)
- No explicit foreign keys needed (Eloquent derives `user_id` from `User`)

PHPUnit loads test files directly (not via autoloading), so the namespace doesn't need to match the directory structure.

### Temp directories for file I/O

Tests that write files to disk must never write to the committed `tests/` directory. For tests needing a full app skeleton, `Testbench\TestCase` handles this automatically (see testbench entry in the paths table above). For unit/lightweight tests that just need a scratch directory, use `ParallelTesting::tempDir('TestName')` — store it as a property, create in `setUp`, delete via `Filesystem::deleteDirectory()` in `tearDown`. See `FoundationViteTest` or `OptionTest` for the pattern.

The Testbench skeleton clone is shared for the whole worker, so tests that write under `BASE_PATH` must restore or delete the exact files they touch in `tearDown()`. For `.env` files, prefer `useEnvironmentPath()` with an isolated `ParallelTesting::tempDir()` directory.

### Coroutine support

All tests run inside coroutines by default. The `RunTestsInCoroutine` trait is on both base test cases (`Hypervel\Tests\TestCase` and `Hypervel\Foundation\Testing\TestCase` / Testbench), so each test method automatically runs in a fresh coroutine. Context is destroyed when the coroutine ends — no manual cleanup needed.

**Never add `use RunTestsInCoroutine;` to individual test classes.** It's inherited from the base class. If you encounter a test extending raw `PHPUnit\Framework\TestCase`, change it to extend `Hypervel\Tests\TestCase` instead.

**Opting out of coroutines:** Set `protected bool $runTestsInCoroutine = false;` on the test class. This is needed when:
- Tests call `run()` directly to create their own coroutines (e.g., pool management tests, parallel HTTP tests)
- Tests explicitly verify non-coroutine → coroutine transitions

**PHPUnit constraint:** `setUp()` and `tearDown()` run outside the test method's coroutine (PHPUnit 13's `runBare()` is `final`). For DB operations in setUp/tearDown, Foundation TestCase provides `runInCoroutine()` which creates temporary coroutines and bridges transaction state via `preserveTransactionContext()`.

**Optional hooks** for code that must run inside the test's coroutine:
- `setUpInCoroutine()` — runs inside the coroutine before the test method
- `tearDownInCoroutine()` — runs inside the coroutine after the test method

These are primarily useful for DB operations or external service setup that needs coroutine context. Most ported Laravel tests won't need them.

### Testing coroutine state isolation

To prove state is per-coroutine (not shared on a worker-lifetime singleton), spawn concurrent coroutines via `parallel()` from `Hypervel\Coroutine` and `usleep()` between mutation and read — the sleep forces the runtime to interleave them; without it tasks may complete sequentially and the leak won't reproduce.

```php
use function Hypervel\Coroutine\parallel;

[$a, $b] = parallel([
    function () use ($service) { $service->set('A'); usleep(5000); return $service->get(); },
    function () use ($service) { $service->set('B'); usleep(5000); return $service->get(); },
]);
```

Examples: `tests/Inertia/CoroutineIsolationTest.php`, `tests/Container/CoroutineSafetyTest.php`. Name new tests `CoroutineIsolationTest` / `CoroutineSafetyTest` for discoverability.

### Request context in tests

`request()` resolves from `RequestContext` — when no request exists in context (tests that don't make HTTP requests), each `request()` call creates a throwaway fallback instance. This means `request()->merge()` has no effect on subsequent `request()` calls. Replace `request()->merge(['key' => 'value'])` with `RequestContext::set(Request::create('/?key=value'))` to seed a stable request in context.

### Static state and test cleanup

`AfterEachTestSubscriber` handles framework-global cleanup between tests. It calls `flushState()` on framework classes that hold static state, and resets the container itself — `Container::flushState()` + `setInstance(null)` — plus `CoroutineContext::flush()`, with each test in a fresh coroutine. So container singleton/auto-singleton instance state and coroutine context don't leak between tests; only `static`/process-global state and live external resources need package cleanup, not mutable state on a container-cached instance. Do not duplicate framework-static resets in `tearDown()`; `AfterEachTestSubscriber` is their one authoritative registry. Tests still own resources they create, such as child coroutines, subscribers, processes, sockets, and temporary files, and must close or join them through exception-safe cleanup.

When writing or porting source classes that use static properties for caching (e.g., `$booted`, `$globalScopes`, resolved config values, compiled formats):
1. Add a `public static function flushState(): void` method that resets the static properties to their initial values
2. Check whether the subscriber (`src/testing/src/PHPUnit/AfterEachTestSubscriber.php`) should call it — if the cached state could leak between tests and cause failures, add the call

Framework-owned classes go in `AfterEachTestSubscriber`. First-party optional framework packages may stay in grouped optional methods at the bottom of that subscriber. Third-party packages, private packages, and applications should register their cleanup through `extra.hypervel.test-state` and a `TestState` registrar instead of hardcoding their classes into the framework subscriber.

Do not add `Hypervel\Testing\PHPUnit\AfterEachTestCleanup` itself to `AfterEachTestSubscriber`. Its callbacks are suite-level registrations that must persist for the PHPUnit worker lifetime.

Place `flushState()` at the end of the class. The only exception is when the class has trailing magic dispatch/lifecycle methods (`__call`, `__callStatic`, `__get`, `__set`, `__isset`, `__unset`, `__destruct`) at the end; in that case, place `flushState()` immediately before that trailing magic-method block. `__invoke()` is not a placement anchor.

Use the standard title docblock for `flushState()` methods:

```php
/**
 * Flush all static state.
 */
```

Do not add `Boot-only.`, `Tests only.`, or `Boot or tests only.` warning paragraphs to `flushState()` docblocks. Those warnings belong on public mutators and registrars that userland might call incorrectly, not on this test cleanup hook that is only registered in `AfterEachTestSubscriber`.

Keep the docblock to the title only — no extra paragraphs. If the method body has a non-obvious WHY worth explaining (ordering constraints, late-static-binding subtleties, etc.), put it as an inline comment above the relevant line inside the method, not as an extra paragraph under the title.

When the property's initial value and `flushState()`'s reset value share a literal (a number, string, class name, etc.), extract it to a `DEFAULT_*` class constant and reference it from both sides — this prevents drift if the default ever changes. Make the constant `public` only if tests or external callers reference it; otherwise `protected`. Nullable lazy caches and callback slots are exempt: `null` there is the structural "not yet computed" sentinel required by the `??=` pattern, not a configurable default — initialize and reset with a literal `null`, no constant.

```php
public const DEFAULT_TRUNCATE_AT = 120;

public static false|int $truncateAt = self::DEFAULT_TRUNCATE_AT;

public static function flushState(): void
{
    static::$truncateAt = self::DEFAULT_TRUNCATE_AT;
}
```

### Per-package base test cases

Do **not** create per-package abstract test case classes (e.g., `EngineTestCase`, `CoroutineTestCase`) just for coroutine support — it's already on the base class.

A per-package base class is only justified when there is shared setUp logic — e.g., shared container mock setup, shared helpers, or shared test fixtures that multiple test classes in the package need.

### Mockery

**Always import as `m`:** Use `use Mockery as m;` and call `m::mock()`, `m::spy()`, etc. Never use the full `Mockery::` prefix.

Framework base test cases own Mockery verification in `tearDown()` so unmet
expectations are attributed to the test that created them. The global
`AfterEachTestSubscriber` remains a fallback for tests using another base case
and always resets framework state even when Mockery verification fails. Tests
must not add their own `Mockery::close()` calls.

### Stricter typing

Hypervel uses stricter types than Laravel. Laravel-trained habits produce incomplete test mocks that loose typing silently accepts — Hypervel's types reject them. This applies to new tests and ported tests alike.

**Model properties require type declarations:**
```php
// Laravel
protected $table = 'users';
protected $fillable = ['name'];
public $timestamps = false;

// Hypervel
protected ?string $table = 'users';
protected array $fillable = ['name'];
public bool $timestamps = false;
```

**Mock return types must match:**
```php
// Laravel (loose - stdClass works)
$connection = m::mock(stdClass::class);

// Hypervel (strict - use correct type)
$connection = m::mock(PDO::class);
$query = m::mock(QueryBuilder::class);
```

**Fluent methods need return values:**
```php
// Laravel (null return silently accepted)
$builder->shouldReceive('where')->with(...);

// Hypervel (must return for chaining)
$builder->shouldReceive('where')->with(...)->andReturnSelf();
```

**Mocking methods with `static` return type:**

Methods like `newInstance()` have `static` return type, meaning they must return the same class (or subclass) as the object they're called on. Mockery creates proxy subclasses, so returning the parent class fails:

```php
// FAILS - mock is Mockery_1_MyModel, returning MyModel fails static type
$this->related = m::mock(MyModel::class);
$this->related->shouldReceive('newInstance')->andReturn(new MyModel);

// WORKS - use partial mock and andReturnSelf()
$this->related = m::mock(MyModel::class)->makePartial();
$this->related->shouldReceive('newInstance')->andReturnSelf();

// Test attributes on the mock itself (partial mock has real Model behavior)
$result = $relation->getResults();
$this->assertSame('taylor', $result->username);
```

This is a testing-only issue — the strict types are correct and an improvement. In production code, you never mock Models and call `newInstance()`.

**When `andReturnSelf()` isn't enough:**

If a test needs to verify distinct instances (e.g., `makeMany()` returns different objects), use a concrete test stub instead of mocks:

```php
class EloquentHasManyRelatedStub extends Model
{
    public static bool $saveCalled = false;

    public function newInstance(mixed $attributes = [], mixed $exists = false): static
    {
        $instance = new static;
        $instance->setRawAttributes((array) $attributes, true);
        return $instance;
    }

    public function save(array $options = []): bool
    {
        static::$saveCalled = true;
        return true;
    }
}

// Test verifies real behavior, not mock expectations
$this->assertNotSame($instances[0], $instances[1]);
$this->assertFalse(EloquentHasManyRelatedStub::$saveCalled);
```

Concrete stubs are the correct approach here — they test actual behavior rather than just verifying mocks were called correctly.

### When tests expose source code type errors

If a test fails with a type error, the source code type may be wrong — not the test. Types should be **correct**, not just strict. A narrow type that doesn't cover all valid cases is incorrect.

**How to identify:**
- Test returns/passes a type that the source code should accept but doesn't
- The type is a parent class of what's currently declared (e.g., `Support\Collection` vs `Eloquent\Collection`)

**How to fix:**
1. Identify all valid types the method can accept/return
2. Use the common base type that covers all cases without being unnecessarily loose
3. Fix the source code, not the test

**Example:** A method returns `Eloquent\Collection` normally, but an `afterQuery` callback can return `Support\Collection`. Since `Eloquent\Collection` extends `Support\Collection`, the correct return type is `Support\Collection` — it covers both cases precisely.

**Wrong approach:** Removing types, using `mixed`, or modifying tests to avoid the type check. These hide the real issue.

### Docblocks and types

- Add `declare(strict_types=1);` at the top of every file
- Add `: void` return types to test methods. This keeps tests consistent with the repo's full-typing rule.
- **Use PHPUnit attributes instead of docblock annotations** — prefer `#[DataProvider('...')]`, `#[Depends('...')]`, etc. over their `@dataProvider`/`@depends` docblock equivalents. Do **not** add `@internal`/`@coversNothing` docblocks or `#[CoversNothing]`/`#[CoversClass(…)]` attributes — Hyperf uses both forms but Laravel doesn't, and they serve no purpose outside strict coverage modes

### Integration tests

Tests that require external services (databases, Redis, HTTP servers, search engines) that can't run in every environment go in `tests/Integration/{PackageName}/`. The exception is tests that call freely-available external APIs (e.g., the Guzzle tests hitting the public Pokemon API) — those can stay in regular `tests/` since they need no local service configuration.

#### External service test traits

Integration tests that use an external service must use that service's test trait.

| Trait | Service | Key Env Vars |
|-------|---------|-------------|
| `InteractsWithRedis` | Redis/Valkey | `REDIS_HOST`, `REDIS_PORT` |
| `InteractsWithMeilisearch` | Meilisearch | `MEILISEARCH_HOST`, `MEILISEARCH_PORT`, `MEILISEARCH_KEY` |
| `InteractsWithTypesense` | Typesense | `TYPESENSE_HOST`, `TYPESENSE_PORT`, `TYPESENSE_API_KEY`, `TYPESENSE_PROTOCOL` |
| `InteractsWithAlgolia` | Algolia | `ALGOLIA_APP_ID`, `ALGOLIA_SECRET` |
| `InteractsWithServer` | Engine test servers (HTTP, TCP, WebSocket, HTTP/2) | `TEST_SERVER_HOST` |

This applies whether the test calls the service directly or reaches it through the package code under test.

These traits are required for external-service tests to work under ParaTest. Parallel workers share external services unless the trait isolates them. Tests that bypass the trait will leak state across workers and fail depending on timing.

The traits handle service-specific setup and cleanup. For example, `InteractsWithRedis` assigns each ParaTest worker its own Redis database and flushes it before and after each test. This isolates the test keyspace without changing the Redis behavior being tested.

If a service is not configured, the trait skips the test before connecting. If the service is configured but unreachable or misconfigured, the test fails.

When adding integration tests for a new service type that has no trait yet, create one following this same pattern (per-worker isolation, skip-when-unconfigured, fail-when-unreachable).

#### phpunit.xml.dist

`tests/Integration/` is **not** excluded from `phpunit.xml.dist`. The skip traits handle graceful skipping when service env vars are not configured. When services are explicitly enabled (CI or local with `.env`), the tests run normally.

#### GH workflows

Each integration group has its own workflow file in `.github/workflows/`:

| Workflow | Runs | Directory |
|----------|------|-----------|
| `engine.yml` | HTTP test servers | `tests/Integration/Engine`, `tests/Integration/HttpServer` |
| `databases.yml` | MySQL, MariaDB, PostgreSQL, SQLite | `tests/Integration/Database` |
| `redis.yml` | Redis, Valkey | `tests/Integration/Cache/Redis`, `tests/Redis/Integration` |
| `scout.yml` | Meilisearch, Typesense | `tests/Integration/Scout/*` |

When adding integration tests that need a new service, either add them to an existing workflow or create a new one. The workflow must spin up the service container and set the appropriate env vars.

#### Environment files

Add env vars for new integration tests to **both**:
- **`.env.example`** — commented out, as reference for what's available
- **`.env`** — with sensible local defaults so developers can uncomment and run locally

See the existing entries for database, Redis, Meilisearch, and Typesense as examples.

## Static Analysis

The `tests/` directory is excluded from phpstan. Do not run phpstan on tests.

Run `./vendor/bin/phpstan` and `./vendor/bin/php-cs-fixer fix` without flags — the repository config files control levels and rules.

**When fixing phpstan errors:**

1. **Investigate before coding.** For each error: read the code, check the Laravel equivalent's types (native and docblock), trace through callers and dependents. Report findings with the single, most correct fix.
2. **Don't make the code worse or more convoluted just to satisfy PHPStan.** Fix real issues in the code, but don't add awkward wrappers, fake branches, casts, or wider types just to silence PHPStan. A phpstan fix is a typing change: it must not change runtime behavior, add overhead, or introduce new edge cases — if the only way to satisfy PHPStan would, STOP and explain. If the code is correct and PHPStan cannot understand it, follow the narrowing / suppression order below.
3. **Native types vs docblocks determine what's dead code.** If a native return type makes a guard unreachable, the guard is dead code — remove it. If only a docblock suggests always-true, the guard is legitimate runtime defense — leave it.
4. **Don't change contract/concrete boundaries to fix phpstan.** Swapping a contract for a concrete (or vice versa) to satisfy a type check diverges from Laravel's API. Only do this when Laravel's typing is genuinely incorrect.
5. **Methods can be added to contracts only if they represent behavior any conforming implementation must provide.** Implementation-specific methods, internal helpers, or driver-specific features don't belong on contracts — find another fix even if adding them would satisfy phpstan.
6. **Wrong docblock types should be fixed**, not suppressed. Check the actual runtime behavior (extension docs, reflection, tests) to determine the correct type.
7. **Type decisions must be evidence-based.** See Development Conventions — check Laravel/Hyperf signatures and docblocks, then trace real control flow. Don't guess.
8. **Narrowing / suppression order.** When the code is correct but PHPStan can't follow it, in order: (1) fix the type signature or docblock; (2) `@var` to narrow to the correct runtime type; (3) a line- or identifier-scoped `@phpstan-ignore` (e.g. magic `__call`/`__get` forwarding). Never use `assert()` to narrow types, and never add a neon-wide rule on your own (see #9).
9. **Don't add patterns to `phpstan.neon.dist` on your own.** The neon file's global ignores cover fundamental framework patterns (Eloquent magic, generics, `new static`). Fix new phpstan errors at the source, not by masking them with new neon rules. Under rare circumstances a global suppression genuinely is the best choice — if you think one may be needed, STOP, explain why the error can't be fixed at the source or narrowed locally, and ask for approval before adding it.

## Porting Packages

### Policy

When porting Laravel packages, whether first-party or third-party, keep them as close to 1:1 with upstream as possible so future changes are easy to merge. The exceptions are:
- Modernizing PHP types (PHP 8.4+ features, strict types, strict comparisons)
- Converting mutable Laravel date construction to Hypervel's immutable date conventions, typing configurable factory output as `CarbonInterface`, and capturing date-modifier return values
- Converting container array access (`$app['events']`) to `make()`, and untyped `$config->get()` calls to the typed getters where the key isn't nullable (see Container and the typed-getter rule under Development Conventions)
- Adding Laravel-style title docblocks to methods (not classes — see Development Conventions)
- For ported Laravel packages: making them coroutine-safe, adding Swoole performance enhancements (e.g., static property caching), making them pass PHPStan
- Not porting upstream framework-specific integrations that only make sense in the source framework (for example packages, drivers) unless Hypervel intentionally has an equivalent surface
- Not porting upstream mechanisms that do not make sense in Hypervel's stateful Swoole architecture (for example Laravel's deferred service provider machinery, where the upstream optimization only matters in a per-request bootstrap model)
- Not porting deprecated upstream code or backwards-compatibility shims for versions/features Hypervel does not support — Hypervel is a new framework with no backwards-compatibility burden, so deprecated APIs and compatibility code that exist only to support older versions should be omitted rather than ported. Here, "upstream" means the framework or package being ported, not one of its dependencies — a Symfony deprecation does not make a Laravel API deprecated while Laravel still retains it. If a deprecated upstream surface still contains behavior that Hypervel actively needs, keep the behavior but move it onto the correct non-deprecated Hypervel-owned surface instead of porting the deprecated alias/wrapper as-is
- General performance improvements — but STOP and explain the opportunity to the user first for approval

Hypervel has no obligation to preserve behavior from earlier Hypervel versions, but compatibility with the Laravel public APIs that Hypervel intentionally supports is a current design requirement. Do not use the lack of a backwards-compatibility burden to rename, remove, or change the meaning of those APIs. Propose genuinely different behavior as a distinct Hypervel API; do not hide it inside a Laravel-shaped API.

Approved adaptations take precedence over upstream fidelity. Preserve Laravel upstream naming, structure, and style everywhere else.

Hyperf is a historical reference rather than an ongoing merge target. For the rare Hyperf port, follow `docs/ai/porting-hyperf.md`.

When working on a package, check its README for the upstream reference before making changes. Most Hypervel packages are ports of Laravel first-party or third-party ecosystem packages, such as Spatie packages. Most low-level Swoole infrastructure packages were originally ported from Hyperf, and a few packages are Hypervel-specific.

Before porting Hyperf code or modifying a Hyperf-ported package, read `docs/ai/porting-hyperf.md` — it covers the conversion mechanics: container calls, ConfigProvider migration, listener/event conversion, and Hyperf test porting.

### Source workflow

#### 1. Package skeleton

If the Hypervel version of the package doesn't exist yet, create the skeleton using an existing package as a template:
- **Porting a Laravel first-party package:** Use the `cache` package as reference
- **Porting a Hyperf package:** Use the `pool` package as reference
- **Porting a Laravel-ecosystem third-party package:** Use the `permission` package as a reference

Read the reference package's `composer.json`, `LICENSE.md`, and `README.md` and create equivalents for the new package. Every package must be wired in both places: its own `src/{package}/composer.json` for the subtree split, and the root `composer.json` for monorepo development. Update autoloading, `replace`, and Hypervel provider / alias discovery metadata as needed, and add root dependencies with `composer require` — see Providers and Listeners for where providers should be registered. Add a clear upstream reference to the new package's README:

```md
Ported from: https://github.com/vendor/package
```

#### 2. Port the files one at a time, alphabetically

Check the source package to see what classes exist. Create a comprehensive todo list with a separate entry for each file to port. The porting process is:

1. Copy the file using `cp` — never read the source first and write a new version. Copying first then reading the copy avoids reading the file twice, which wastes context.
2. Read the ENTIRE copied file to understand context. For large files, read them in chunks.
3. Update namespaces and apply the Porting Policy's approved adaptations (modernized types, method docblocks, etc.). For Laravel ports, do not make unrelated naming, structural, or style changes; preserve upstream naming, structure, and style. For Hyperf ports, follow `docs/ai/porting-hyperf.md`.

**For very large files where even reading in chunks is impractical**
Update the file in chunks from top to bottom — read a chunk, update, read next chunk, update. Do NOT try to search for patterns and update scattered bits.

#### 3. Update consumers

Search **both `src/` and `tests/`** for any `use` statements or references to the old namespace (e.g., `Illuminate\Database\`) and update them to the new Hypervel namespace. Verify zero remaining references before proceeding.

#### 4. Run phpstan

After porting is complete, run phpstan on the newly ported package and fix errors. Investigate each error properly — don't reach for ignores without thinking it through. See the Static Analysis section.

#### 5. Run the full test suite

Run `composer test:parallel` (and `composer test:testbench` after Testbench changes), completing the verification order under Change Workflow. For failures, follow When to Stop and Report: straightforward fixes (e.g. a missed namespace update) go ahead; anything more complex gets stopped and explained.

### Provider and listener reminder

When ported code adds a provider or listener, wire providers and aliases in both Composer metadata locations and register listeners in the service provider's `boot()` method through closures that resolve them from the container. Follow the full rules under Providers and Listeners.

### Porting rules

- **Preserve source constant/property/method order in Laravel ports** — when porting or merging methods into an existing Hypervel class, insert them in the same relative order as upstream. This keeps diffs meaningful and makes future merges easier.
- **Preserve existing comments** — use the following rules for upstream code comments and docblocks:
  Do not remove or modify upstream code comments unless they are incorrect.
  Only remove `@param` and `@return` annotations where the description adds nothing beyond what the native type hint and parameter/method name already convey.
  Examples of removable: `@param string $name The name of the cookie` (just restates `string $name`), `@param int $offset Stream offset` (just restates `int $offset`).
  Examples to keep: `@param bool $secure Whether the cookie should only be transmitted over a secure HTTPS connection`, `@param int $whence Specifies how the cursor position will be calculated...`, `@return resource|null` (when the native type is `mixed` because `resource` isn't a valid PHP type hint).
  Keep everything else: behavioral descriptions, `@see` links, `@throws` annotations, warnings, contract explanations, usage notes.
  Modernize the title line to imperative form ("Returns" → "Return", "Retrieves" → "Retrieve") but do not remove or rewrite the body content beneath it.
  Translate non-English comments to English and fix grammar errors.
- **Record intentional Laravel differences where future ports will look** — When a Laravel feature is intentionally not ported because it does not fit Hypervel's Swoole/coroutine architecture, or because Hypervel has a better native equivalent, record it in three places so a future port cannot miss it: (1) the package README under `Differences From Laravel`, with the reason and what to use instead; (2) a concise source comment at the natural insertion point where the skipped method/class would otherwise sit; (3) a concise `REMOVED:` comment at the matching upstream test location when tests are skipped. This is a narrow exception to the "don't annotate divergences" rule: it applies only to intentionally omitted methods or features, never to ordinary ported-and-adapted code. Closed decisions only — real gaps still worth doing go in `docs/todo.md`.
- **Replace framework names in code** — any occurrence of the word `laravel` or `hyperf` in ported code (string literals, comments, prefixes, identifiers, etc.) must be replaced with `hypervel`, preserving the original casing. For example: `laravel_reserved_` → `hypervel_reserved_`, `LaravelExcelExporter` → `HypervelExcelExporter`, `HYPERF_VERSION` → `HYPERVEL_VERSION`. This does not apply to namespaces (which have their own conversion rules) or to references that describe the upstream source (e.g., docblock `@see` links to Laravel/Hyperf source).
- **Don't copy Laravel/Hyperf-specific framework details just to stay 1:1** — keep the behavior the same, but if something only exists because of the upstream framework's own packages, providers, bootstrap system, or architecture, translate it to the Hypervel equivalent or STOP and ask if there isn't one.

### Test porting workflow

Follow the same cp-then-edit process as source files. This workflow applies to both Hyperf and Laravel test porting. Laravel-specific conversions are covered in Porting Laravel Tests below; Hyperf-specific conversions (namespaces, license headers, container and error-handler mocking, NonCoroutine tests) are covered in `docs/ai/porting-hyperf.md`.

Test file names and directory structure should mirror the source for both Laravel and Hyperf ports, providing a 1:1 class-to-test mapping. For Laravel ports, this also enables automated porting of upstream PRs. When both Hyperf and Laravel have tests covering the same class, merge them into one file — take the more comprehensive version as the base and add unique tests from the other.

#### 1. Audit source tests

List all test files in the source package's `tests/` directory. For Laravel packages, also check `tests/Integration/{PackageName}/` — that's where Laravel puts its integration tests for each package. Note what each file covers.

#### 2. Audit existing Hypervel tests (if any)

Read all files in the existing Hypervel test directory for this package. Categorise them:
- **Custom tests** (Hypervel-specific, no Hyperf/Laravel equivalent): Keep as-is
- **Ported tests** (already ported from source): Keep — new source tests must be merged in

#### 3. Create the todo list

One entry per test file. Note the strategy:
- **Copy and update** — no existing Hypervel test for this
- **Merge** — Hypervel already has a test file with custom tests that must be preserved alongside the ported source tests
- **Integration** — needs external service, goes in `tests/Integration/{PackageName}/`
- **Investigate** — exposes missing functionality, an unsupported feature, or an architectural difference. STOP and explain what the test covers, whether Hypervel should support it, and your recommended fix or removal.

#### 4. Port test files one at a time

**For newly copied files (copy and update):**
1. Copy the file using `cp` to the correct location
2. Read the ENTIRE copied file to understand context
3. Update namespaces, base class, imports, types, docblocks, etc.

**For merged files:**
1. Read BOTH the source file AND the existing Hypervel file
2. Merge source tests into the Hypervel file, preserving all Hypervel-specific tests
3. Update namespaces, types, docblocks, etc.

**For stub/helper files:** Copy `Stub/` directory files the same way.

#### 5. Run tests after each file

Use this exact cadence for each test class:
1. Port the test class.
2. Run that test class immediately (`./vendor/bin/phpunit --no-progress path/to/TestClass.php`).
3. Fix all straightforward failures.
4. If any failure exposes a source code bug, missing functionality, or unclear behavioral difference, STOP and report the root cause with your recommended fix.
5. Once the test class is green, move to the next test class. Work serially on one test class at a time.

#### 6. Run the full test suite

After all test files are ported, run the full test suite with `composer test:parallel`. Same rules as the source workflow — straightforward fixes go ahead, anything complex gets stopped and explained.

## Porting Laravel Tests

Most conversion work is applying general rules from Writing Tests — especially "Stricter typing", "When tests expose source code type errors", and "Helper class namespacing", since Laravel's loose typing and shared test namespaces hide problems that Hypervel exposes. The subsections below cover what is specific to Laravel ports.

### Namespace changes

- Change `Illuminate\Tests\{Package}` to `Hypervel\Tests\{Package}`
- Change all `Illuminate\` source imports to `Hypervel\`

If Laravel's namespace includes the test class name, keep it. Stripping it causes "Cannot redeclare class" errors.

### Missing dependencies

Some test files reference classes defined in other test files. Laravel gets away with this due to test suite load order. Make tests self-contained by defining required classes locally.

### Workbench fixtures from upstream packages

Laravel packages sometimes ship a `workbench/` directory with controllers, models, middleware, and a `routes/web.php`. Hypervel's testbench workbench is shared across every package's tests, so port these into the package-scoped pattern:

- **Controllers, models, middleware** → `tests/{Package}/Fixtures/...`, namespace `Hypervel\Tests\{Package}\Fixtures\...`.
- **Routes** → `tests/{Package}/Fixtures/routes.php`. Load only from tests that need them (test setUp, or a small bootstrap script for CLI subprocesses). Never always-load.

Update upstream test imports to point at the new Fixtures namespace.

### Approved unsupported features

Tests for these features should be **removed** (not commented out) without asking — they will never be supported:

- **Databases:** SQL Server, MongoDB, DynamoDB — Hypervel only supports MySQL, MariaDB, PostgreSQL, and SQLite
- **Cache drivers:** Memcached, DynamoDB, MongoDB
- **Dynamic connections:** `DB::build()`, `DB::connectUsing()` — incompatible with Swoole connection pooling

This list is exhaustive. Any other missing functionality requires investigation and reporting per When to Stop and Report.

### Laravel porting quick checklist

1. Update namespace to `Hypervel\Tests\{Package}`
2. Add `declare(strict_types=1);`
3. Change `Illuminate\` imports to `Hypervel\`
4. Extend correct base TestCase (`Hypervel\Tests\TestCase` or `Hypervel\Testbench\TestCase`)
5. Ensure `parent::setUp()` is called
6. Add type declarations to model properties
7. Fix mock types (PDO, QueryBuilder, Grammar, etc.)
8. Add `->andReturnSelf()` to chained method mocks
9. Use a test-specific namespace only when helper classes have generic, collision-prone names — already-specific helper names do not need extra namespace ceremony.
10. Remove tests for unsupported features (SQL Server/MongoDB/DynamoDB databases, Memcached/DynamoDB/MongoDB cache, dynamic connections)
11. Run tests and fix any remaining type errors
