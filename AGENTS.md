# Hypervel Components Agent Guide

## Background

Hypervel is a standalone Laravel-style Swoole framework. The public API should stay close to Laravel wherever possible, while the internals are adapted for long-lived Swoole workers, coroutine safety, and high performance.

Laravel is the main API reference. Hyperf is a historical and architectural reference for some lower-level Swoole/coroutine packages, but Hypervel code should follow current Hypervel patterns rather than copying Hyperf structure mechanically.

This file is intentionally detailed because agents trained on Laravel will otherwise assume Laravel's request lifecycle and miss Hypervel's Swoole/coroutine constraints.

## Mental Model

When working on Hypervel, start from this frame:

- Hypervel is Laravel-shaped at the API level.
- Hypervel is not request-per-process PHP; workers are long-lived.
- Singletons, static properties, manager registries, callbacks, config, and cached metadata can persist for the worker lifetime.
- Per-request state must live in coroutine-scoped storage (CoroutineContext), not process-global state.
- Laravel source is the default parity reference, but Laravel internals often assume per-request bootstrap and are not optimized to take advantage of static caching of immutable state.
- Hyperf source can be useful for Swoole/coroutine behavior, but Hyperf container/config/listener patterns are not the target architecture.

When porting, we keep packages as close to 1:1 with the originals as possible so merging upstream changes is easy later. The exceptions are:
- Modernizing PHP types (PHP 8.4+ features, strict types)
- Adding Laravel-style title docblocks to methods (not classes — see rules below)
- For ported Laravel packages: making them coroutine-safe, adding Swoole performance enhancements (e.g., static property caching), making them pass PHPStan
- Not porting upstream framework-specific integrations that only make sense in the source framework (for example packages, drivers) unless Hypervel intentionally has an equivalent surface
- Not porting upstream mechanisms that do not make sense in Hypervel's stateful Swoole architecture (for example Laravel's deferred service provider machinery, where the upstream optimization only matters in a per-request bootstrap model)
- Not porting deprecated upstream code or backwards-compatibility shims for versions/features Hypervel does not support — Hypervel is a new framework with no backwards-compatibility burden, so deprecated APIs and compatibility code that exist only to support older versions should be omitted rather than ported. If a deprecated upstream surface still contains behavior that Hypervel actively needs, keep the behavior but move it onto the correct non-deprecated Hypervel-owned surface instead of porting the deprecated alias/wrapper as-is
- General performance improvements — but STOP and explain the opportunity to the user first for approval

When working on a package, check its README for the upstream reference before making changes. Most Hypervel packages are Laravel ports, while most low-level Swoole infrastructure packages are Hyperf ports. Some packages are ports of third-party packages such as Spatie packages, and a few are Hypervel-specific.

## Directory Reference

Always run framework commands from the repository root. This is the directory that contains this `AGENTS.md`, the root `composer.json`, `phpunit.xml.dist`, `phpstan.neon`, `src/`, and `tests/`.

Testbench and workbench:

| Path | Description |
|------|-------------|
| `src/testbench/` | Hypervel's testbench package (port of `orchestra/testbench`). Contains `TestCase`, attributes (`WithConfig`, `WithMigration`), and bootstrap logic. Part of the monorepo, not a vendor dependency. |
| `src/testbench/hypervel/` | Committed Hypervel app skeleton. On bootstrap, testbench clones this to a disposable temp directory (`/tmp/hypervel-components-testbench-{token}-{pid}/`) and points `BASE_PATH` at the clone — tests that write files under `BASE_PATH` (generated providers, migrations, fixtures, etc.) hit the temp copy, not this committed path. The clone is deleted on shutdown and stale copies from crashed runs are cleaned up. Testbench also exports `TESTBENCH_BASE_PATH` so subprocesses can locate the active runtime. |
| `src/testbench/workbench/` | Committed shared test fixtures (NOT cloned). Subdirs are psr-4-mapped from the monorepo root as `Workbench\App\*`, `Workbench\Database\Factories\*`, `Workbench\Database\Seeders\*` so multiple tests can reuse the same models/factories/seeders without redefining them. Not the runtime app — that's the disposable clone of `src/testbench/hypervel/`. |

## Porting Packages

### Workflow

#### 1. Package skeleton

If the Hypervel version of the package doesn't exist yet, create the skeleton using an existing package as a template:
- **Porting a Laravel package:** Use the `cache` package as reference
- **Porting a Hyperf package:** Use the `pool` package as reference
- **Porting a third-party package:** Use the `permission` package as a reference

Read the reference package's `composer.json`, `LICENSE.md`, and `README.md` and create equivalents for the new package. Every package must be wired in both places: its own `src/{package}/composer.json` for the subtree split, and the root `composer.json` for monorepo development. Update autoloading, dependencies, `replace`, and Hypervel provider / alias discovery metadata as needed. Add a clear upstream reference to the new package's README:

```md
Ported from: https://github.com/vendor/package
```

#### 2. Port the files one at a time, alphabetically

Check the source package to see what classes exist. Create a comprehensive todo list with a separate entry for each file to port. The porting process is:

1. Copy the file using `cp` (never read first → write new version)
2. Read the ENTIRE copied file to understand context. For large files, read them in chunks.
3. Update namespaces, modernize types, add method docblocks etc. as per the rules in this file.

**For very large files where even reading in chunks is impractical**
Update the file in chunks from top to bottom — read a chunk, update, read next chunk, update. Do NOT try to search for patterns and update scattered bits.

#### 3. Update consumers

Search **both `src/` and `tests/`** for any `use` statements or references to the old namespace (e.g., `Illuminate\Database\`) and update them to the new Hypervel namespace. Verify zero remaining references before proceeding.

#### 4. Run phpstan

After porting is complete, run phpstan on the newly ported package and fix errors. Investigate each error properly — don't reach for ignores without thinking it through. See the static analysis section.

#### 5. Run the full test suite

**Always use `composer test:parallel`** to run the test suite. This invokes `bin/paratest` via a custom wrapper that configures per-worker isolation. Running `vendor/bin/paratest` directly bypasses this setup and will cause failures.

Investigate all failures thoroughly — don't assume a failure is caused by the porting work without confirming it. For straightforward fixes (e.g. a missed namespace update), fix and continue. For anything more complex (behavioral changes, test logic issues, unclear root causes), STOP and explain the cause along with your recommended fix for approval.


### Rules

- **Avoid bulk modification tools** — tools like `sed` and `replace_all` often have unwanted side effects. Prefer manual edits whenever possible. When this is impractical and bulk tools have to be used, do it in multiple runs that each target long, specific, case-sensitive strings to avoid accidental changes.
- **One file at a time** — never work on multiple files simultaneously.
- **Never use Write to overwrite files** — always use Edit for targeted updates.
- **Always use `cp` to copy files and `mv` to move/rename** — never read → write new version → delete old version.
- **`cp` files THEN read them** — when porting a new file, copy it first, then read the copied file in full. Reading the source before copying wastes context because you end up reading it twice.
- **Preserve source constant/property/method order when merging** — when porting/merging methods into an existing Hypervel class, insert them at the same relative order as they appear in the upstream source. This keeps diffs against upstream meaningful and makes future merges easier.
- **Import classes, don't use FQCNs** — always add a `use` statement and reference the short name. The only exceptions are places where FQCNs genuinely make more sense, such as middleware arrays and similar config-style identifier lists.
- **No class docblocks unless warranted** — only add a class-level docblock if something unusual or complex needs explanation. Method docblocks (title only, Laravel-style) are always added. A body can accompany the title for complex methods that need further explanation.
- **Preserve existing comments** - use the following rules for upstream code comments and docblocks:
  Do not remove or modify upstream code comments unless they are incorrect. 
  Only remove `@param` and `@return` annotations where the description adds nothing beyond what the native type hint and parameter/method name already convey.
  Examples of removable: `@param string $name The name of the cookie` (just restates `string $name`), `@param int $offset Stream offset` (just restates `int $offset`). 
  Examples to keep: `@param bool $secure Whether the cookie should only be transmitted over a secure HTTPS connection`, `@param int $whence Specifies how the cursor position will be calculated...`, `@return resource|null` (when the native type is `mixed` because `resource` isn't a valid PHP type hint).
  Keep everything else: behavioral descriptions, `@see` links, `@throws` annotations, warnings, contract explanations, usage notes.
  Modernize the title line to imperative form ("Returns" → "Return", "Retrieves" → "Retrieve") but do not remove or rewrite the body content beneath it.
  Translate non-English comments to English and fix grammar errors.
- **Don't comment self-evident code** — Add inline comments only for a non-obvious WHY. Don't annotate framework divergences, routine casts, or type normalizations; match the surrounding comment density.
- **Record intentional Laravel differences where future ports will look** — When a Laravel feature is intentionally not ported because it does not fit Hypervel's Swoole/coroutine architecture, or because Hypervel has a better native equivalent, record it in three places so a future port cannot miss it: (1) the package README under `Differences From Laravel`, with the reason and what to use instead; (2) a concise source comment at the natural insertion point where the skipped method/class would otherwise sit; (3) a concise `REMOVED:` comment at the matching upstream test location when tests are skipped. This is a narrow exception to the "don't annotate divergences" rule: it applies only to intentionally omitted methods or features, never to ordinary ported-and-adapted code. Closed decisions only — real gaps still worth doing go in `src/boost/todo.md`.
- **Replace framework names in code** — any occurrence of the word `laravel` or `hyperf` in ported code (string literals, comments, prefixes, identifiers, etc.) must be replaced with `hypervel`, preserving the original casing. For example: `laravel_reserved_` → `hypervel_reserved_`, `LaravelExcelExporter` → `HypervelExcelExporter`, `HYPERF_VERSION` → `HYPERVEL_VERSION`. This does not apply to namespaces (which have their own conversion rules) or to references that describe the upstream source (e.g., docblock `@see` links to Laravel/Hyperf source).
- **Always use American English spelling** — E.g., "behavior" vs "behaviour", "utilize" vs "utilise".
- **Don't copy Laravel/Hyperf-specific framework details just to stay 1:1** — keep the behavior the same, but if something only exists because of the upstream framework's own packages, providers, bootstrap system, or architecture, translate it to the Hypervel equivalent or STOP and ask if there isn't one.
- **Grep broadly — never assume a subdir.** - when searching for any symbol, class, method, or pattern, grep across the whole `src/` (or `tests/`) tree, not a specific package subdir. Assumptions about where something lives produce false negatives.
- **Stop on anything unusual** — missing dependencies, logic needing special consideration, things that don't make sense for Hypervel, etc. Explain the situation and your recommended solution. Do not proceed without approval.
- **Never skip or stub things out** — no removing code, no commenting out with "TODO once X is ported" placeholders. If such a situation arises, STOP and explain with your recommendation.
- **Stop on any source code bug** — if phpstan or tests expose a bug in Hypervel source code (typing, logic, behavior), investigate, explain root cause, and provide a recommended fix for approval. Also STOP and report bugs found in the **upstream** Laravel/Hyperf source being ported (resource leaks, logic errors, missing cleanup, etc.) — explain the issue and recommend a fix. Upstream bugs must be fixed, not ported as-is.
- **Do not work around incorrect existing code to avoid churn** — if porting exposes incorrect types, wrong logic, missing methods/classes, or other real defects in existing Hypervel code, fix the underlying code instead of adding compatibility hacks or local workarounds to sidestep the problem. Prioritize correctness and code quality over minimizing blast radius. For any non-trivial fix, STOP and explain the root cause and recommended change before proceeding.
- **Never weaken or drop tests to work around source issues** — if a ported test exposes source-side problems (wrong types, broken logic, missing classes/methods, signatures that diverge from Laravel, missing API parity, etc.), STOP and report the issue with a recommendation for the most correct fix. Never delete, skip, loosen assertions, or alter tests to make them pass against flawed source code. The test is the spec; the source gets fixed.
- **Never dismiss issues as "out of scope" or "pre-existing"** — when porting exposes any issue (bugs, divergences, missing API parity, incorrect visibility, type inconsistencies, naming mismatches, etc.), always STOP and report it. Never use phrases like "out of scope", "pre-existing", "not part of this work", "separate concern", or "unrelated" to justify not reporting something. You are not permitted to decide what is or isn't worth addressing — only the user makes that call.
- **Prefer union types over `mixed` when all types are known** — `mixed` is only for truly unconstrained values or cases that cannot be safely narrowed after control-flow analysis.
- **Type decisions must be evidence-based** — check corresponding Laravel/Hyperf signatures and docblocks as a reference, then trace the real control flow through method bodies across all callers and callees to confirm the types are correct.
- **Review worker-lifetime state explicitly** — whenever a change introduces or modifies static properties/caches, singletons or other long-lived state, STOP and report the Swoole persistence impact (memory leaks, cross-request behavior) with a recommendation.
- **Document worker-lifetime mutators** — when adding or touching a public method that mutates static state, singleton-held configuration, manager registries, cached drivers, global callbacks, or other worker-lifetime state, add a short warning to the method docblock if the method is intended only for boot-time configuration or tests. Use the tag-first format so humans and LLMs can recognize it quickly:
  - `Boot-only.` — for startup configuration methods
  - `Tests only.` — for test fakes, swaps, and resolver overrides
  - `Boot or tests only.` — for cache/registry clearing methods used during boot reconfiguration or test cleanup

  The second sentence should name the concrete failure mode, e.g. "The callback persists in a static property for the worker lifetime and affects every subsequent request." Do not add these warnings to methods that are genuinely safe for normal runtime/per-request use. If a method is commonly expected to be used dynamically but mutates shared worker-lifetime state, treat that as a coroutine-safety bug and STOP with a recommendation instead of just documenting it.
- **Flag static caching opportunities with recommendations** — if a ported path repeatedly computes expensive stable metadata and worker-lifetime static caching would be a clear win, STOP and recommend it (what to cache, expected benefit, and safety constraints).
- **Enum cases use PascalCase by default** — `case Pending` not `case pending`, `case OauthToken` not `case OAUTH_TOKEN`. Applies to both backed and unit enums. **Exception:** when `->name` is used as an external identifier (cache keys, cookie names, filesystem disks, rate limiter names, timezone strings) or appears in serialized output (e.g., `toArray()` returning `'name' => $this->name`), match the consuming system's convention (typically lowercase or snake_case).

## Porting Hyperf code to Hypervel

### Container Usage

Hyperf and Hypervel have fundamentally different container semantics. Every ported file that touches the container needs these updates.

#### Background: How the containers differ

**Hyperf container:**
- `get($id)` — returns a singleton. Caches the result in `$resolvedEntries`; subsequent calls return the cached instance.
- `make($name)` — always returns a fresh instance. No caching. This is how Hyperf code gets non-shared objects.
- `ApplicationContext::getContainer()` — static access to the container. Returns `Psr\Container\ContainerInterface` (PSR — only exposes `get()` and `has()`).
- Everything resolved via `get()` is implicitly a singleton. There is no `singleton()`, `scoped()`, or `bind()`.

**Hypervel container (Laravel-style):**
- `make($abstract)` and `get($id)` both call the same internal `resolve()` method. `get()` is just a PSR-compliant exception wrapper around it.
- Resolution behavior depends on how the abstract was registered:
  - `singleton()` — cached for the worker lifetime (in `$instances`)
  - `scoped()` — cached per-request via coroutine Context
  - `bind()` — fresh instance every time (no caching)
  - **Unbound concrete classes** — auto-singletoned for Swoole performance (cached in `$autoSingletons`). This is the key behavioral difference from Hyperf's `make()`.
- `Container::getInstance()` — static access. Uses `??= new static()`, so it always returns a container (never null).
- `build($class)` / `buildWith($class, $params)` — always constructs the given concrete directly. These bypass binding lookups, aliases, singleton/scoped caches, and auto-singletoning for the top-level class being built. Nested constructor dependencies are still resolved through the container. Do not use `build()` as a drop-in freshness replacement for `make()` when explicit bindings, test swaps, aliases, or resolving callbacks must be honored.

#### What to change when porting

**1. `ApplicationContext` → `Container::getInstance()`**

```php
// Hyperf
use Hyperf\Context\ApplicationContext;
$container = ApplicationContext::getContainer();

// Hypervel
use Hypervel\Container\Container;
$container = Container::getInstance();
```

Remove `ApplicationContext::hasContainer()` guards — `Container::getInstance()` auto-creates via `??= new static()`, so it always returns a container instance.

Replace `ApplicationContext::setContainer($c)` with `Container::setInstance($c)` (tests only).

**2. `->get()` → `->make()` on the container**

All `$container->get()` / `$this->container->get()` calls become `->make()`. In Hypervel both methods resolve identically, but `make()` is the Laravel convention (internal API, not PSR wrapper). Use `make()` consistently.

```php
// Hyperf
$this->container->get(ConfigInterface::class);

// Hypervel
$this->container->make(ConfigInterface::class);
```

**3. Audit `make()` calls for auto-singleton safety**

In Hyperf, `$container->make(Foo::class)` always returns a fresh `Foo`. In Hypervel, if `Foo` has no explicit binding, it will be auto-singletoned (cached for the worker lifetime). This is usually desirable for Swoole performance, but needs verification:

- **Safe as auto-singleton (most cases):** Services, middleware, listeners, factories, formatters — stateless or process-global by nature. Leave as `make()`.
- **Needs fresh instances:** Mutable request-scoped DTOs, builders that accumulate state, objects that capture per-request data in their constructor. **STOP and report** with a recommendation (typically: register with `bind()` so the container returns fresh instances).
- **`make()` with parameters always returns fresh:** `$container->make(Foo::class, ['bar' => $baz])` bypasses all caching (singleton, scoped, and auto-singleton) because parameters trigger a contextual build. No action needed for these calls.

#### Quick reference

| Hyperf | Hypervel | Behavior change? |
|---|---|---|
| `ApplicationContext::getContainer()->get(Foo::class)` | `Container::getInstance()->make(Foo::class)` | No — both return singletons |
| `$this->container->get(Foo::class)` | `$this->container->make(Foo::class)` | No — convention change only |
| `$this->container->make(Foo::class)` | `$this->container->make(Foo::class)` | **Yes** — Hyperf: fresh each time. Hypervel: auto-singletoned if unbound. Verify safe. |
| `$this->container->make(Foo::class)` when freshness is needed but bindings/swaps must still apply | `$this->container->make(Foo::class, [...])`, explicit `bind()`, or clone a resolved prototype depending on the use case | `build(Foo::class)` is only correct when you intentionally want to bypass top-level bindings/aliases/caches. |
| `ApplicationContext::hasContainer()` | Remove guard | `getInstance()` always returns a container |
| `ApplicationContext::setContainer($c)` | `Container::setInstance($c)` | Tests only |

### Migrating Hyperf ConfigProviders

Hyperf packages use `ConfigProvider` classes to register bindings, listeners, commands, publishable files, and aspects. Hypervel packages use normal service providers. When porting a Hyperf package, treat the Hyperf `ConfigProvider` as source input and translate each entry into Hypervel's documented provider APIs.

#### Read the Hypervel provider APIs first

Before migrating a ConfigProvider, read:

- `src/boost/docs/providers.md`
- `src/boost/docs/aop.md` if the ConfigProvider has aspects
- `src/boost/docs/packages.md#class-map-overrides` if the package uses class map replacement
- `Hypervel\Support\ServiceProvider`

Use existing Hypervel packages as pattern references. For low-level Swoole / Hyperf-style infrastructure, useful references include `pool`, `object-pool`, `engine`, `server`, `signal`, and `sentry`.

#### Categorize the ConfigProvider entries

Read the Hyperf package's ConfigProvider and categorize each entry:

- **`dependencies`** — container bindings. Move these to the service provider's `register()` method.
- **`listeners`** — Hyperf `ListenerInterface` listeners. Convert them to Hypervel listener classes and register them in the service provider's `boot()` method. See "Converting Hyperf Listeners and Events" below.
- **`commands`** — console commands. Move these to the service provider's `register()` method via `$this->commands([...])`. Commands must have `#[AsCommand(name: '...')]` so Hypervel can resolve them lazily through `ContainerCommandLoader`.
- **`publish`** — publishable files. Move these to the service provider's `boot()` method via `$this->publishes([...])`.
- **`aspects`** — AOP aspects. Move these to the service provider's `register()` method via `$this->aspects([...])`. Hypervel does not support Hyperf annotation-based aspect targeting. Hypervel aspects extend `Hypervel\Di\Aop\AbstractAspect`, target classes with the public `$classes` property, and should stay stateless because aspect instances are usually reused for the worker lifetime.

Once the entries have been migrated, delete the ConfigProvider. Do not keep Hyperf `extra.hyperf.config` metadata in Hypervel package composer files.

#### Translate bindings into Hypervel container patterns

Do not copy Hyperf dependency registrations mechanically. Hyperf dependency entries often rely on Hyperf container behavior, while Hypervel has `bind()`, `singleton()`, `scoped()`, aliases, and auto-singletoning.

Use these rules:

- If the service is stateless and should be shared for the worker lifetime, use `singleton()`.
- If each resolution needs a fresh mutable object, use `bind()`.
- If state should be isolated per coroutine / request, use `scoped()`.
- If the class can safely be auto-singletoned and needs no explicit abstract, do not bind it at all.
- If a Hyperf factory class only wraps simple construction logic, replace it with an inline closure and delete the factory.
- If a Hyperf resolver class only wraps a one-line callback, replace it with an inline closure and delete the resolver.

#### Binding patterns

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

#### Check aliases before choosing binding keys

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

Do not add new core aliases just because Hyperf had a dependency key. Only add aliases when Hypervel needs that alias as part of its public container surface.

#### Register the provider

Register providers and aliases through the package Composer metadata and the root Composer metadata as described in the package skeleton workflow. Add a provider to `DefaultProviders` only if every Hypervel application needs it at framework startup, such as auth, cache, database, session, validation, view, or low-level Swoole infrastructure. Optional packages such as Reverb, Scout, Telescope, Sentry, and Watcher should rely on package discovery instead.

For rare core services that must be available before normal providers are registered, stop and explain why before touching `registerBaseServiceProviders()`. Providers registered there run during the earliest application bootstrap, so this should be reserved for framework infrastructure needed by the boot process itself.

It is safe to have the same provider listed in both `registerBaseServiceProviders()` and `extra.hypervel.providers` when early loading is genuinely needed. `Application::register()` deduplicates providers by class name, and the discovery entry ensures standalone installs still load the provider.

#### BootApplication listeners

Some Hyperf ConfigProviders register listeners for `BootApplication`. These are usually setup hooks that need to run during framework boot, not real runtime events. Convert them to direct calls in the service provider's `boot()` method instead of dispatching a synthetic event:

```php
public function boot(): void
{
    $this->app->make(ExceptionHandlerListener::class)->handle(new BootApplication());
}
```

If the setup can be expressed directly without keeping the listener class, prefer the direct framework call. For example, a listener that only sets a global model resolver should usually become an explicit call in the provider's `boot()` method.

#### Quick checklist

1. Read Hypervel provider docs and relevant existing Hypervel package patterns.
2. Read the Hyperf ConfigProvider and categorize entries.
3. Translate dependencies into Hypervel bindings.
4. Convert listeners, commands, publish entries, and aspects to provider APIs.
5. Check aliases before choosing binding keys.
6. Delete unnecessary Hyperf factories / resolvers.
7. Delete the ConfigProvider.
8. Register providers and aliases in both Composer metadata locations.
9. Run phpstan and tests.

Circular dependency errors or "not found" errors usually indicate:

- A binding key mismatch.
- A missing provider registration.
- A string concrete binding that should be a closure.

#### Example: database provider migration

The database package is a good reference for translating Hyperf provider patterns into Hypervel provider code:

- Hyperf factory classes that only wrapped simple construction were deleted and replaced with inline provider closures.
- Core aliases were made string-key canonical, such as `'db'`, `'db.schema'`, and `'db.transactions'`.
- Boot-time listeners were replaced with direct provider boot logic, such as `Model::setConnectionResolver(...)` and `Model::setEventDispatcher(...)`.
- Facades resolve the canonical container key instead of the concrete manager class.

### Converting Hyperf Listeners and Events

When porting Hyperf packages, their `ListenerInterface` listeners and event classes must be converted to Hypervel listener patterns.

#### Converting listeners

**Hyperf pattern** — `ListenerInterface` with `listen()` returning event class names, `process(object $event)` as the handler:

```php
use Hyperf\Event\Contract\ListenerInterface;

class AfterWorkerStartListener implements ListenerInterface
{
    public function listen(): array
    {
        return [AfterWorkerStart::class];
    }

    public function process(object $event): void
    {
        /** @var AfterWorkerStart $event */
        // ... logic
    }
}
```

**Hypervel pattern** — plain class with typed `handle()` method:

```php
class AfterWorkerStartListener
{
    public function handle(AfterWorkerStart $event): void
    {
        // ... same logic, typed parameter instead of docblock
    }
}
```

**Steps:**
1. Remove `implements ListenerInterface` and the `use` import
2. Delete the `listen()` method entirely
3. Rename `process(object $event)` → `handle(SpecificEvent $event)` with the typed parameter
4. Remove the `@var` docblock cast — the type hint replaces it

**Multi-event listeners:** When a Hyperf listener handles multiple event types (returns multiple classes from `listen()`), use a union type parameter:

```php
// Hyperf: listen() returns [OnStart::class, OnManagerStart::class, AfterWorkerStart::class, BeforeProcessHandle::class]
// Hypervel:
public function handle(AfterWorkerStart|OnStart|OnManagerStart|BeforeProcessHandle $event): void
```

The service provider registers separate `$events->listen()` calls for each event type, all pointing to the same listener.

#### Registering listeners in service providers

Hyperf auto-discovered listeners via the ConfigProvider `listeners` array. In Hypervel, register them in the service provider's `boot()` method using closures that resolve from the container:

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

#### `BootApplication` listeners

Some Hyperf listeners listen for `BootApplication`, which fires during kernel bootstrap (before the app is fully booted). These are not true event-driven listeners — they run setup logic that needs to happen early.

Convert these to **direct calls** in the service provider's `boot()` method. No event dispatch:

```php
// Hyperf ConfigProvider: listeners => [ExceptionHandlerListener::class]
// (with ExceptionHandlerListener::listen() returning [BootApplication::class])

// Hypervel service provider:
public function boot(): void
{
    $this->app->make(ExceptionHandlerListener::class)->handle(new BootApplication());
}
```

If the listener only existed to run once at boot time and has no reason to be event-driven, calling it directly is simpler and more explicit than dispatching a synthetic event.

#### Converting event classes

Hyperf events are plain PHP classes — the conversion is minimal:

1. **Namespace:** `Hyperf\{Package}\Event` → `Hypervel\{Package}\Events` (singular → plural, matching Laravel)
2. **Modernize properties:** Add `readonly` to constructor-promoted properties where appropriate
3. **Remove PSR interfaces:** Drop `StoppableEventInterface` and the `Stoppable` trait. Laravel handles propagation stopping via listener `return false` and the `until()` dispatch method — no interface needed.
4. **Remove boilerplate:** Delete the Hyperf license header

Event classes are just data carriers. Their structure is fundamentally the same in both systems — the differences are namespace and type modernization, not architectural.

## Porting Tests

### Test Porting Workflow

Follow the same cp-then-edit process as source files. This workflow applies to both Hyperf and Laravel test porting.

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

After all test files are ported, run the full test suite with `composer test:parallel`. Same rules as the source porting workflow — straightforward fixes go ahead, anything complex gets stopped and explained.

### General Rules

These apply to all test porting, regardless of whether the source is Hyperf or Laravel.

#### Base Classes

**Never extend `PHPUnit\Framework\TestCase` directly.** Always use one of these:

| Class | Use When |
|-------|----------|
| `Hypervel\Tests\TestCase` | Unit tests, mocks only, no container needed |
| `Hypervel\Testbench\TestCase` | Integration tests (needs container for facades, config, DB, etc.) **or any test that writes files to disk** — testbench clones a disposable runtime skeleton per run and exposes its path via `BASE_PATH` (and `TESTBENCH_BASE_PATH` for subprocesses), deleted on shutdown. Committed source is never mutated. |

Always call `parent::setUp()` in your setUp method.

#### Test Support Files

All **standalone** test support files — PHP classes, non-class PHP files, and non-PHP files (JSON, SQL, images, templates, etc.) — go in a single **`Fixtures/`** directory (capital F). This matches Laravel's predominant convention. PHP classes in `Fixtures/` are PSR-4 autoloaded like any other test file. Helper classes used only by a single test file may be defined inline within that file (matching Laravel's convention).

#### Workbench Fixtures from Upstream Packages

Laravel packages sometimes ship a `workbench/` directory with controllers, models, middleware, and a `routes/web.php`. Hypervel's testbench workbench is shared across every package's tests, so port these into the package-scoped pattern:

- **Controllers, models, middleware** → `tests/{Package}/Fixtures/...`, namespace `Hypervel\Tests\{Package}\Fixtures\...`.
- **Routes** → `tests/{Package}/Fixtures/routes.php`. Load only from tests that need them (test setUp, or a small bootstrap script for CLI subprocesses). Never always-load.

Update upstream test imports to point at the new Fixtures namespace.

#### Temp Directories for File I/O

Tests that write files to disk must never write to the committed `tests/` directory. For tests needing a full app skeleton, `Testbench\TestCase` handles this automatically (see testbench entry in the directory table above). For unit/lightweight tests that just need a scratch directory, use `ParallelTesting::tempDir('TestName')` — store it as a property, create in `setUp`, delete via `Filesystem::deleteDirectory()` in `tearDown`. See `FoundationViteTest` or `OptionTest` for the pattern.

#### Coroutine Support

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

#### Testing coroutine state isolation

To prove state is per-coroutine (not shared on a worker-lifetime singleton), spawn concurrent coroutines via `parallel()` from `Hypervel\Coroutine` and `usleep()` between mutation and read — the sleep forces the runtime to interleave them; without it tasks may complete sequentially and the leak won't reproduce.

```php
use function Hypervel\Coroutine\parallel;

[$a, $b] = parallel([
    function () use ($service) { $service->set('A'); usleep(5000); return $service->get(); },
    function () use ($service) { $service->set('B'); usleep(5000); return $service->get(); },
]);
```

Examples: `tests/Inertia/CoroutineIsolationTest.php`, `tests/Container/CoroutineSafetyTest.php`. Name new tests `CoroutineIsolationTest` / `CoroutineSafetyTest` for discoverability.

#### Request Context in Tests

`request()` resolves from `RequestContext` — when no request exists in context (tests that don't make HTTP requests), each `request()` call creates a throwaway fallback instance. This means `request()->merge()` has no effect on subsequent `request()` calls. Replace `request()->merge(['key' => 'value'])` with `RequestContext::set(Request::create('/?key=value'))` to seed a stable request in context.

#### Static State and Test Cleanup

`AfterEachTestSubscriber` handles global static state cleanup between tests. It calls `flushState()` on framework classes that accumulate static state (Mockery, HandleExceptions, Carbon, Number, Eloquent Model, Paginator, etc.). **Never add cleanup for these in `tearDown()`** — it's already handled. Testbench-specific flush helpers are not the global cleanup registry.

When porting source classes that use static properties for caching (e.g., `$booted`, `$globalScopes`, resolved config values, compiled formats):
1. Add a `public static function flushState(): void` method that resets the static properties to their initial values
2. Check whether the subscriber (`tests/AfterEachTestSubscriber.php`) should call it — if the cached state could leak between tests and cause failures, add the call

Place `flushState()` at the end of the class. The only exception is when the class has trailing magic dispatch/lifecycle methods (`__call`, `__callStatic`, `__get`, `__set`, `__isset`, `__unset`, `__destruct`) at the end; in that case, place `flushState()` immediately before that trailing magic-method block. `__invoke()` is not a placement anchor.

Use the standard title docblock for `flushState()` methods:

```php
/**
 * Flush all static state.
 */
```

Do not add `Boot-only.`, `Tests only.`, or `Boot or tests only.` warning paragraphs to `flushState()` docblocks. Those warnings belong on public mutators and registrars that userland might call incorrectly, not on this test cleanup hook that is only registered in `AfterEachTestSubscriber`.

Keep the docblock to the title only — no extra paragraphs. If the method body has a non-obvious WHY worth explaining (ordering constraints, late-static-binding subtleties, etc.), put it as an inline comment above the relevant line inside the method, not as an extra paragraph under the title.

When the property's initial value and `flushState()`'s reset value share a literal (a number, string, class name, etc.), extract it to a `DEFAULT_*` class constant and reference it from both sides — this prevents drift if the default ever changes. Make the constant `public` only if tests or external callers reference it; otherwise `protected`.

```php
public const DEFAULT_TRUNCATE_AT = 120;

public static false|int $truncateAt = self::DEFAULT_TRUNCATE_AT;

public static function flushState(): void
{
    static::$truncateAt = self::DEFAULT_TRUNCATE_AT;
}
```

#### Per-Package Base Test Cases

Do **not** create per-package abstract test case classes (e.g., `EngineTestCase`, `CoroutineTestCase`) just for coroutine support — it's already on the base class.

A per-package base class is only justified when there is shared setUp logic — e.g., shared container mock setup, shared helpers, or shared test fixtures that multiple test classes in the package need.

#### Mockery

**Always import as `m`:** Use `use Mockery as m;` and call `m::mock()`, `m::spy()`, etc. Never use the full `Mockery::` prefix.

**Never add `Mockery::close()` to tearDown.** It's handled globally by `AfterEachTestSubscriber` for all tests.

#### Docblocks and Types

- Add `declare(strict_types=1);` at the top of every file
- Add `: void` return types to test methods. This keeps tests consistent with the repo's full-typing rule.
- **Use PHPUnit attributes instead of docblock annotations** — prefer `#[DataProvider('...')]`, `#[Depends('...')]`, etc. over their `@dataProvider`/`@depends` docblock equivalents. Do **not** add `@internal`/`@coversNothing` docblocks or `#[CoversNothing]`/`#[CoversClass(…)]` attributes — Hyperf uses both forms but Laravel doesn't, and they serve no purpose outside strict coverage modes

#### phpstan

The `tests/` directory is excluded from phpstan. Do not run phpstan on tests.

**When fixing phpstan errors:**

1. **Investigate before coding.** For each error: read the code, check the Laravel equivalent's types (native and docblock), trace through callers and dependents. Report findings with the single, most correct fix.
2. **Don't make the code worse or more convoluted just to satisfy PHPStan.** Fix real issues in the code, but don't add awkward wrappers, fake branches, casts, or wider types just to silence PHPStan. A phpstan fix is a typing change: it must not change runtime behavior, add overhead, or introduce new edge cases — if the only way to satisfy PHPStan would, STOP and explain. If the code is correct and PHPStan cannot understand it, follow the narrowing / suppression order below.
3. **Native types vs docblocks determine what's dead code.** If a native return type makes a guard unreachable, the guard is dead code — remove it. If only a docblock suggests always-true, the guard is legitimate runtime defense — leave it.
4. **Don't change contract/concrete boundaries to fix phpstan.** Swapping a contract for a concrete (or vice versa) to satisfy a type check diverges from Laravel's API. Only do this when Laravel's typing is genuinely incorrect.
5. **Methods can be added to contracts only if they represent behavior any conforming implementation must provide.** Implementation-specific methods, internal helpers, or driver-specific features don't belong on contracts — find another fix even if adding them would satisfy phpstan.
6. **Wrong docblock types should be fixed**, not suppressed. Check the actual runtime behavior (extension docs, reflection, tests) to determine the correct type.
7. **Type decisions must be evidence-based.** Check Laravel/Hyperf signatures and docblocks, then trace real control flow. Don't guess.
8. **Narrowing / suppression order.** When the code is correct but PHPStan can't follow it, in order: (1) fix the type signature or docblock; (2) `@var` to narrow to the correct runtime type; (3) a line- or identifier-scoped `@phpstan-ignore` (e.g. magic `__call`/`__get` forwarding). Never use `assert()` to narrow types, and never add a neon-wide rule on your own (see #9).
9. **Don't add patterns to `phpstan.neon.dist` on your own.** The neon file's global ignores cover fundamental framework patterns (Eloquent magic, generics, `new static`). Fix new phpstan errors at the source, not by masking them with new neon rules. Under rare circumstances a global suppression genuinely is the best choice — if you think one may be needed, STOP, explain why the error can't be fixed at the source or narrowed locally, and ask for approval before adding it.

#### Handling Failing Tests

For tests that fail after conversion:

1. **Easy fixes** (namespace typos, missing return types, etc.) — fix and continue
2. **Non-trivial failures** — STOP and investigate:
   - Identify the root cause (missing feature, source bug, architectural difference)
   - Explain what's missing and what adding it would involve
   - Report findings and wait for instructions

**You do not decide what tests to skip or remove.** Only the user makes that call after reviewing your investigation.

Never comment out, skip, or avoid porting a test because the required functionality is missing. If the test covers functionality Hypervel should support, investigate the missing functionality, then STOP and report the root cause with your recommended fix. If the test covers functionality Hypervel intentionally does not support, such as an unsupported database or cache driver, STOP and report that before removing the test unless the unsupported-feature rules below explicitly allow removal.

#### Removed Tests

Only remove tests for functionality Hypervel intentionally does not support, such as the unsupported features listed below. For any other test removal, STOP and explain what the test covers, why you believe it should not apply to Hypervel, and wait for approval.

### Porting Hyperf Tests

#### Directory Structure

All tests live in `tests/{PackageName}/` (PascalCase), regardless of whether they originate from Hyperf or Laravel. File names and directory structure should mirror the source (Laravel or Hyperf) for 1:1 mapping — this enables automated porting of upstream PRs.

When both Hyperf and Laravel have tests covering the same class, merge them into one file — take the more comprehensive version as the base and add unique tests from the other.

#### Namespace Changes

- `HyperfTest\{Package}` → `Hypervel\Tests\{Package}`
- All `Hyperf\` source imports → `Hypervel\`

#### Boilerplate Removal

- Remove the Hyperf license header block (`@link`, `@document`, `@contact`, `@license`)
- Remove `#[CoversNothing]` and `#[CoversClass(…)]` attributes entirely — we don't use them (see "Docblocks and Types" above)

#### Container Mocking

Hyperf tests use `Psr\Container\ContainerInterface`. Change to `Hypervel\Contracts\Container\Container`. Also change all `->get()` to `->make()` — both mock expectations AND direct calls on the container in test setup code (see "Container Usage" section above for why):

```php
// Hyperf
use Psr\Container\ContainerInterface;
$container = Mockery::mock(ContainerInterface::class);
$container->shouldReceive('get')->with(Foo::class)->andReturn(new Foo());
$result = $container->get(Foo::class);  // test setup call

// Hypervel
use Hypervel\Contracts\Container\Container as ContainerContract;
$container = m::mock(ContainerContract::class);
$container->shouldReceive('make')->with(Foo::class)->andReturn(new Foo());
$result = $container->make(Foo::class);  // test setup call
```

#### Error Handler Mocking

Hyperf tests use `StdoutLoggerInterface` + `FormatterInterface` for error reporting in coroutines. Hypervel uses `ExceptionHandler`:

```php
// Hyperf
$container->shouldReceive('has')->withAnyArgs()->andReturnTrue();
$container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn($logger);
$logger->shouldReceive('warning')->with('unit')->twice();
$container->shouldReceive('get')->with(FormatterInterface::class)->andReturn($formatter);
$formatter->shouldReceive('format')->with($exception)->twice()->andReturn('unit');

// Hypervel
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
$container->shouldReceive('has')->with(ExceptionHandlerContract::class)->andReturnTrue();
$container->shouldReceive('make')->with(ExceptionHandlerContract::class)
    ->andReturn($handler = m::mock(ExceptionHandlerContract::class));
$handler->shouldReceive('report')->with($exception)->twice();
```

#### NonCoroutine Tests

Hyperf uses `#[Group('NonCoroutine')]` on individual test methods to mark tests that must run outside a coroutine. In Hypervel, extract those methods to a separate test class with `protected bool $runTestsInCoroutine = false;`.

#### Hyperf Quick Checklist

1. Update namespace from `HyperfTest\{Package}` to `Hypervel\Tests\{Package}`
2. Add `declare(strict_types=1);`
3. Change `Hyperf\` imports to `Hypervel\`
4. Remove Hyperf license header; remove `#[CoversNothing]` and `#[CoversClass(…)]` attributes
5. Extend `Hypervel\Tests\TestCase` (not `PHPUnit\Framework\TestCase`)
6. Change container mock to `Hypervel\Contracts\Container\Container`, all `->get()` to `->make()` (expectations AND direct calls)
7. Change error handler mock to `Hypervel\Contracts\Debug\ExceptionHandler`
8. Extract `#[Group('NonCoroutine')]` methods to separate class with `$runTestsInCoroutine = false`
9. Ensure `parent::setUp()` is called
10. Run tests and fix any remaining type errors

### Porting Laravel Tests

#### Directory Structure

Laravel tests go in `tests/{PackageName}/` — the same directory as Hyperf-ported tests. File names should mirror Laravel's test layout for 1:1 mapping.

**Also check Laravel's `tests/Integration/{PackageName}/` directory** — that's where Laravel puts integration tests for each package. Those go in our `tests/Integration/{PackageName}/`.

#### Namespace Changes

- Change `Illuminate\Tests\{Package}` to `Hypervel\Tests\{Package}`
- Change all `Illuminate\` source imports to `Hypervel\`

If Laravel's namespace includes the test class name, keep it. Stripping it causes "Cannot redeclare class" errors.

#### Stricter Typing

Hypervel uses stricter types than Laravel. This exposes incomplete test mocks that Laravel's loose typing silently accepts.

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

#### When Tests Expose Source Code Type Errors

If a Laravel test fails with a type error, the source code type may be wrong — not the test. Types should be **correct**, not just strict. A narrow type that doesn't cover all valid cases is incorrect.

**How to identify:**
- Test returns/passes a type that the source code should accept but doesn't
- The type is a parent class of what's currently declared (e.g., `Support\Collection` vs `Eloquent\Collection`)

**How to fix:**
1. Identify all valid types the method can accept/return
2. Use the common base type that covers all cases without being unnecessarily loose
3. Fix the source code, not the test

**Example:** A method returns `Eloquent\Collection` normally, but an `afterQuery` callback can return `Support\Collection`. Since `Eloquent\Collection` extends `Support\Collection`, the correct return type is `Support\Collection` — it covers both cases precisely.

**Wrong approach:** Removing types, using `mixed`, or modifying tests to avoid the type check. These hide the real issue.

#### Missing Dependencies

Some test files reference classes defined in other test files. Laravel gets away with this due to test suite load order. Make tests self-contained by defining required classes locally.

#### Helper Class Namespacing

Laravel tests often define helper classes (models, stubs) with generic names like `User`, `Post`, or `Comment`. When multiple test files use the same namespace and define classes with the same name, PHP throws "Cannot redeclare class" errors.

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

#### Unsupported Features

Tests for these features should be **removed** (not commented out) without asking — they will never be supported:

- **Databases:** SQL Server, MongoDB, DynamoDB — Hypervel only supports MySQL, MariaDB, PostgreSQL, and SQLite
- **Cache drivers:** Memcached, DynamoDB, MongoDB
- **Dynamic connections:** `DB::build()`, `DB::connectUsing()` — incompatible with Swoole connection pooling

This list is exhaustive. Any other missing functionality requires investigation and reporting.

#### Laravel Quick Checklist

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

### Integration Tests

This applies to tests ported from **both** Hyperf and Laravel.

#### Definition

Tests that require external services (databases, Redis, HTTP servers, search engines) that can't run in every environment go in `tests/Integration/{PackageName}/`. The exception is tests that call freely-available external APIs (e.g., the Guzzle tests hitting the public Pokemon API) — those can stay in regular `tests/` since they work everywhere.

#### Skip Traits

Each external service has a corresponding trait that auto-skips tests when the service isn't reachable:

| Trait | Service | Key Env Vars |
|-------|---------|-------------|
| `InteractsWithRedis` | Redis/Valkey | `REDIS_HOST`, `REDIS_PORT` |
| `InteractsWithServer` | Engine test servers (HTTP, TCP, WebSocket, HTTP/2) | `ENGINE_TEST_SERVER_HOST` |

These traits follow a consistent pattern: try to connect, skip with defaults if unavailable, fail if explicit config is set but unreachable (misconfiguration). When porting integration tests for a new service type, create a new trait following this same pattern.

#### phpunit.xml.dist

`tests/Integration/` is **not** excluded from `phpunit.xml.dist`. The skip traits handle graceful skipping when services aren't available. When services are available (CI or local with `.env`), the tests run normally.

#### GH Workflows

Each integration group has its own workflow file in `.github/workflows/`:

| Workflow | Runs | Directory |
|----------|------|-----------|
| `engine.yml` | HTTP test servers | `tests/Integration/Engine`, `tests/Integration/Guzzle` |
| `databases.yml` | MySQL, MariaDB, PostgreSQL, SQLite | `tests/Integration/Database` |
| `redis.yml` | Redis, Valkey | `tests/Integration/Cache/Redis`, `tests/Redis/Integration` |
| `scout.yml` | Meilisearch, Typesense | `tests/Integration/Scout/*` |

When porting integration tests that need a new service, either add them to an existing workflow or create a new one. The workflow must spin up the service container and set the appropriate env vars.

#### Environment Files

Add env vars for new integration tests to **both**:
- **`.env.example`** — commented out, as reference for what's available
- **`.env`** — with sensible local defaults so developers can uncomment and run locally

See the existing entries for database, Redis, Meilisearch, and Typesense as examples.
