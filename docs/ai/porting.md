# Porting Guide

## Background

Hypervel is a Laravel-style Swoole framework originally built on top of Hyperf. We are decoupling from Hyperf and making Hypervel as close to 1:1 with Laravel as possible. This involves porting packages from both Hyperf (Swoole/coroutine infrastructure) and Laravel (application-level features).

When porting, we keep packages as close to 1:1 with the originals as possible so merging upstream changes is easy later. The exceptions are:
- Modernising PHP types (PHP 8.4+ features, strict types)
- Adding Laravel-style title docblocks to methods (not classes — see rules below)
- For ported Laravel packages: making them coroutine-safe and adding Swoole performance enhancements (e.g., static property caching)
- General performance improvements — but stop and explain the opportunity first for approval

## Directory Reference

**Working directory for ALL operations (porting, commits, tests, phpstan, etc.):**

`/home/binaryfire/workspace/monorepo/contrib/hypervel/components/`

This is the Hypervel repo. Always `cd` into it before doing anything.

Source references (read-only, for copying from):

| Path | Description |
|------|-------------|
| `/home/binaryfire/workspace/monorepo/examples/laravel/framework/` | Laravel source reference |
| `/home/binaryfire/workspace/monorepo/examples/hyperf/hyperf/` | Hyperf source reference |

## Porting Packages

### Workflow

#### 1. Package skeleton

If the Hypervel version of the package doesn't exist yet, create the skeleton using an existing package as a template:
- **Porting a Hyperf package:** Use the `pool` package as reference
- **Porting a Laravel package:** Use the `cache` package as reference

Read the reference package's `composer.json`, `LICENSE.md`, and `README.md`. Then read the components repo's root `composer.json` and add the new package following existing patterns.

#### 2. Audit existing Hypervel package (if it exists)

Read all files in the existing Hypervel package and categorise them:
- **Empty extensions** (class just extends Hyperf, no overrides/additions/properties): Delete these — they'll be replaced by ported versions
- **Custom classes** (don't extend Hyperf): Keep as-is
- **Extended classes with additions** (extend Hyperf + add overrides, methods, properties): Keep — the Hyperf parent's code must be merged into these

#### 3. Create the todo list

Check the source package (Hyperf or Laravel) to see what classes exist. Create a comprehensive todo list with a separate entry for each file to port. Each entry must clearly state the strategy:
- **Copy and update** — new file, no existing Hypervel equivalent
- **Merge** — existing Hypervel file with additions that must be preserved

#### 4. Work through files one at a time, alphabetically

**For newly copied files (copy and update):**
1. Copy the file using `cp` (never read → write)
2. Read the ENTIRE copied file (if small enough for one read) to understand context
3. Update namespaces, modernise types, add method docblocks, etc.

**For merged files:**
1. Read BOTH the Hyperf/Laravel file AND the existing Hypervel file
2. Carefully merge the source file into the Hypervel file, preserving all Hypervel additions
3. Update namespaces, modernise types, add method docblocks, etc.

**For large files that can't be read in one go:**
Work through in chunks from top to bottom — read a chunk, update, read next chunk, update. Do NOT try to search for patterns and update scattered bits.

#### 5. Update consumers

Search **both `src/` and `tests/`** for any `use` statements or references to the old namespace (e.g., `Hyperf\Coordinator\`) and update them to the new Hypervel namespace. Verify zero remaining references before proceeding.

#### 6. Run phpstan

After porting is complete, run phpstan on the newly ported package and fix errors. Investigate each error properly — don't reach for ignores without thinking it through.

#### 7. Run full phpunit

Run the full test suite (`./vendor/bin/phpunit`). Investigate all failures thoroughly — don't assume a failure is caused by the porting without confirming. For straightforward fixes (e.g., a missed namespace update), fix and continue. For anything more complex (behavioural changes, test logic issues, unclear root causes), stop and explain the cause along with your recommended fix for approval.

### Rules

- **Never use bulk modification tools** — no `sed`, `replace_all`, scripted loops, etc. All edits must be manual and targeted.
- **One file at a time** — never work on multiple files simultaneously.
- **Never use Write to overwrite files** — always use Edit for targeted updates.
- **Always use `cp` to copy files and `mv` to move/rename** — never read → write → delete.
- **No class docblocks unless warranted** — only add a class docblock if something unusual or complex needs explanation. Method docblocks (title only, Laravel-style) are always added.
- **Preserve existing comments — do not remove them.** Only remove:
  - Source package boilerplate (e.g., the Hyperf license header block)
  - `@param` and `@return` annotations where the description adds nothing beyond what the native type hint and parameter/method name already convey. Examples of removable: `@param string $name The name of the cookie` (just restates `string $name`), `@return int Position of the file pointer` (just restates `tell(): int`), `@param int $offset Stream offset` (just restates `int $offset`). Examples to keep: `@param bool $secure Whether the cookie should only be transmitted over a secure HTTPS connection`, `@param int $whence Specifies how the cursor position will be calculated...`, `@return resource|null` (when the native type is `mixed` because `resource` isn't a valid PHP type hint).

  Keep everything else: behavioral descriptions, `@see` links, `@throws` annotations, warnings, contract explanations, usage notes. Modernize the title line to imperative form ("Returns" → "Return", "Retrieves" → "Retrieve") but do not remove or rewrite the body content beneath it. Translate non-English comments to English and fix grammar errors in place.
- **Stop on anything unusual** — missing dependencies, logic needing special consideration, things that don't make sense for Hypervel, etc. Explain the situation and your recommended solution. Do not proceed without approval.
- **Never skip or stub things out** — no removing code, no commenting out with "TODO once X is ported" placeholders. If such a situation arises, stop and explain with your recommendation.
- **Mark temporary compatibility paths with `@TODO:`** — when you add a real transitional fallback/shim during porting, add an inline `@TODO:` with the removal condition. Do not use `@TODO` to avoid implementing behavior now.
- **Stop on any source code bug** — if phpstan or tests expose a source bug (typing, logic, behavior), investigate, explain root cause, and provide a recommended fix for approval.
- **Use unions over `mixed` when types are known** — `mixed` is only for truly unconstrained values or cases that cannot be safely narrowed after control-flow analysis.
- **Type decisions must be evidence-based** — check corresponding Laravel/Hyperf signatures and docblocks as reference, then trace real control flow in method bodies and callers/callees.
- **Modernize types only in touched code** — do not refactor unrelated files unless required by confirmed control flow or a failing test.
- **Review worker-lifetime state explicitly** — whenever a change introduces or modifies static properties/caches/singletons, STOP and report the Swoole persistence impact (memory growth, cross-request behavior) with a recommendation: keep as-is for performance parity, or adapt for worker safety.
- **Flag cache opportunities with recommendation** — if a ported path repeatedly computes expensive stable metadata and worker-lifetime static caching would be a clear win, STOP and recommend it (what to cache, expected benefit, and safety constraints).

### Container Usage (Hyperf → Hypervel)

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
| `ApplicationContext::hasContainer()` | Remove guard | `getInstance()` always returns a container |
| `ApplicationContext::setContainer($c)` | `Container::setInstance($c)` | Tests only |

### Migrating ConfigProviders to Service Providers

Hyperf uses `ConfigProvider` classes to register dependencies, listeners, commands, and publishable assets. Hypervel is replacing these with Laravel-style service providers. Each package's ConfigProvider dependencies must be migrated to a proper service provider that matches how the equivalent Laravel package does it.

#### Why not a mechanical move

You cannot simply copy the ConfigProvider's `dependencies` array into a service provider's `register()` method. The ConfigProvider system used Hyperf patterns (interface-keyed singletons, factory classes with `__invoke`, string concrete bindings) that don't match Laravel's conventions and can cause circular dependency issues with Hypervel's container aliases. Each package must be matched against its Laravel equivalent.

#### Workflow

##### 1. Read the Laravel service provider

Find the equivalent Laravel service provider in the Laravel source reference. For example, `Illuminate\Auth\AuthServiceProvider` for the auth package. Read it completely — understand the binding keys, binding types (`singleton` vs `bind`), and how it constructs services.

For packages without a Laravel equivalent (e.g., Hyperf-only packages like engine, object-pool, serializer), create a straightforward service provider that registers the same bindings as the ConfigProvider's `dependencies` array. Use the binding patterns described in section 5 below. Match the naming convention (`{Package}ServiceProvider`) and the same `register()`/`boot()` structure.

##### 2. Read the Hypervel ConfigProvider

Read the existing ConfigProvider to understand what's currently registered. Categorise each entry:
- **`dependencies`** — container bindings. These move to the service provider's `register()` method.
- **`listeners`** — Hyperf-style `ListenerInterface` classes. These move to the service provider's `boot()` method (see "Listener registration" below).
- **`commands`** — artisan commands. These move to the service provider's `register()` method via `$this->commands([...])`. All commands must have `#[AsCommand(name: '...')]` (Symfony attribute) for lazy resolution via `ContainerCommandLoader`.
- **`publish`** — publishable files. These move to the service provider's `boot()` method via `$this->publishes([source => destination])`. `VendorPublishCommand` reads from both systems, so the service provider approach works.
- **`aspects`** — Hyperf DI AOP aspects. Leave as-is.

Since everything except `aspects` moves to the service provider, the ConfigProvider should be **deleted entirely** once migrated (unless it still has `aspects`).

##### 3. Check registerCoreContainerAliases

Look up the package's entries in `Application::registerCoreContainerAliases()`. This is critical — these aliases determine what can be used as a binding key without causing circular dependencies.

**Laravel convention:** The canonical abstract is a string shorthand (`'auth'`, `'cache'`, `'db'`). The contract/interface AND the concrete class are aliases pointing to that shorthand.

**Hypervel's current state:** Some entries still use the contract as canonical with the shorthand as an alias. These must be flipped to match Laravel:

```php
// BEFORE (Hyperf-style — contract is canonical)
\Hypervel\Contracts\Auth\Factory::class => [
    'auth',
    \Hypervel\Auth\AuthManager::class,
],

// AFTER (Laravel-style — string shorthand is canonical)
'auth' => [
    \Hypervel\Auth\AuthManager::class,
    \Hypervel\Contracts\Auth\Factory::class,
],
```

If any of the package's concrete classes appear as aliases for their own abstract, you **must** use closure-based bindings in the service provider (see "Binding patterns" below). Otherwise `singleton(Interface::class, Concrete::class)` creates a circular resolution cycle: the container tries to resolve the concrete, the alias redirects back to the interface, infinite loop.

**If Laravel has no entry for this package in `registerCoreContainerAliases`:** Remove the entry from Hypervel's aliases entirely. Use `$this->app->alias()` in the service provider instead, matching how Laravel handles it. For example, Laravel's `BusServiceProvider` uses `$this->app->alias(Dispatcher::class, DispatcherContract::class)` rather than having a bus entry in `registerCoreContainerAliases`.

##### 4. Create the service provider

Create the service provider in the package's `src/` directory, matching the Laravel equivalent's structure. Use the same method decomposition (e.g., `registerAuthenticator()`, `registerUserResolver()`, etc.) for readability and 1:1 parity.

##### 5. Binding patterns

Match the binding pattern from the Laravel service provider. Key rules:

**Choose the right binding form based on whether the abstract/concrete participate in `registerCoreContainerAliases()`:**

The container's `bind()`/`singleton()` stores bindings under the exact abstract key passed — it does NOT resolve aliases first. But `resolve()` DOES call `getAlias()` before looking up bindings. So if the abstract you pass is an alias for a canonical key, your binding is orphaned (never found). The `registerConfigProviderDependencies()` method works around this by explicitly calling `getAlias()` before storing, but regular service providers don't.

**1. Abstract is a canonical alias key (e.g., `'auth'`, `'cache'`, `'hash'`, `'request'`) — use closure with `new`:**
```php
// The concrete (AuthManager) is listed as an alias for 'auth', so
// singleton('auth', AuthManager::class) would create a circular resolution cycle:
// 'auth' → build AuthManager → getAlias(AuthManager) → 'auth' → infinite loop
$this->app->singleton('auth', fn ($app) => new AuthManager($app));
```

**2. Abstract is NOT in the alias table — use string concrete:**
```php
// Neither FormatterInterface nor DefaultFormatter are aliases for anything,
// so the container can resolve this directly without cycles.
$this->app->singleton(FormatterInterface::class, DefaultFormatter::class);
```

**3. Abstract and concrete are the same class — don't bind at all.** Hypervel's container auto-singletons unbound concrete classes on first resolution. An explicit `singleton(Foo::class)` is redundant:
```php
// WRONG — redundant, auto-singleton handles this
$this->app->singleton(BroadcastManager::class);

// CORRECT — just don't bind it. First make(BroadcastManager::class) auto-singletons it.
```

**Use the same binding type as Laravel** — `singleton()` vs `bind()` matters:
```php
// Laravel uses bind() for Authenticatable — user can change per-request
$this->app->bind(AuthenticatableContract::class, fn ($app) => ...);

// Laravel uses singleton() for AuthManager — one instance per worker
$this->app->singleton('auth', fn ($app) => new AuthManager($app));
```

**Delete Hyperf factory classes that are replaced by inline closures.** If the ConfigProvider used a factory class with `__invoke` (e.g., `GateFactory`) and the Laravel equivalent creates the object inline in a closure, delete the factory class — it's unnecessary indirection.

**Delete Hyperf resolver classes that are replaced by inline closures.** Same principle — if a class like `UserResolver` just wraps a one-liner that Laravel does inline, delete it.

##### 6. Register the service provider

Four places need updating:

1. **Root `composer.json` `extra.hypervel.providers`** — Add the service provider. This is how apps discover providers from the components monorepo.

2. **Package `composer.json` `extra.hypervel.providers`** — Replace `extra.hyperf.config` with `extra.hypervel.providers` listing the new service provider. This is how apps discover providers when the package is installed as a standalone dependency.

3. **`DefaultProviders`** (`src/support/src/DefaultProviders.php`) — Add to the providers list (alphabetical order). This is how the testbench and default app configs load the provider. Without this, tests using `Testbench\TestCase` won't have the bindings available.

4. **Remove from `extra.hyperf.config`** — Remove the ConfigProvider from both the root `composer.json` and the package `composer.json` `extra.hyperf.config` entries (since the ConfigProvider is deleted).

##### 7. Delete the ConfigProvider

Since all entries (dependencies, listeners, commands, publish) move to the service provider, the ConfigProvider should be deleted entirely. The only exception is if the ConfigProvider still has `aspects` entries — in that case, remove everything else and keep only the `aspects` array until those are migrated separately.

##### 8. Run tests

Run the package's tests first, then the full suite. Circular dependency errors or "not found" errors indicate:
- A binding key mismatch (check alias direction in `registerCoreContainerAliases`)
- A missing entry in `DefaultProviders` (testbench can't find the provider)
- A string concrete binding that should be a closure (alias cycle)

#### Special cases

**Early-bootstrap packages (config, framework):** The `config` and `framework` packages have their dependencies loaded in `Application::registerConfigProviderDependencies()` — which runs in the Application constructor, before service providers are registered. These cannot be migrated to service providers without first refactoring the bootstrap sequence. Leave them for a dedicated task.

**Early-loading service providers (`registerBaseServiceProviders`):** When a service provider's bindings are needed before `registerConfigProviderDependencies()` runs (e.g., the event dispatcher, which `bootstrapWith()` requires), register it in `Application::registerBaseServiceProviders()` — called in the constructor immediately after `registerBaseBindings()`. This mirrors Laravel's pattern.

It's safe to have the same provider listed in **both** `registerBaseServiceProviders()` and `extra.hypervel.providers` in `composer.json`. `Application::register()` deduplicates — `getProvider()` checks `$serviceProviders` by class name and returns the existing instance without re-registering. The `extra.hypervel.providers` entry ensures apps that install the package standalone (outside the components monorepo) still auto-discover the provider.

**Packages with existing service providers:** Some packages already have service providers (e.g., `MailServiceProvider`, `NotificationServiceProvider`, `PermissionServiceProvider`). Add the ConfigProvider's dependency bindings to the existing provider's `register()` method rather than creating a new one.

**Packages with listeners:** Listeners registered via the `listeners` config key must be converted from Hyperf's `ListenerInterface` pattern to Laravel-style and registered in the service provider's `boot()` method. See "Converting Hyperf Listeners and Events" below for the full conversion process.

**`BootApplication` listeners:** Some ConfigProviders registered listeners on `BootApplication` (which fires in the Kernel constructor, before bootstrap). These should be replaced with direct calls in the service provider's `boot()` — e.g., the database package's `RegisterConnectionResolverListener` became a direct `Model::setConnectionResolver()` call in `DatabaseServiceProvider::boot()`. See "BootApplication listeners" under "Converting Hyperf Listeners and Events" for the pattern.

#### Completed example

**database** — alias flip, factory deletion, `BootApplication` listener replacement, commands:

- **Deleted:** `DatabaseMigrationRepositoryFactory` (replaced by inline closure), `ConfigProvider`
- **Alias flip:** `DatabaseManager::class => ['db']` became `'db' => [DatabaseManager::class]` (also `db.schema`, `db.transactions`)
- **Listener replacement:** `RegisterConnectionResolverListener` (fired on `BootApplication`) replaced by direct `Model::setConnectionResolver()` and `Model::setEventDispatcher()` in `boot()`
- **DB facade:** Changed accessor from `DatabaseManager::class` to `'db'`

#### Quick checklist

1. Read the Laravel service provider for the package (or design one if no Laravel equivalent)
2. Read the Hypervel ConfigProvider — categorise all entries
3. Check `registerCoreContainerAliases` — flip to string-canonical if needed, or remove if Laravel doesn't have it
4. Create service provider matching Laravel's binding keys, types, and closures
5. Move commands to `register()` via `$this->commands([...])`
6. Move listeners to `boot()` via closure-based `$events->listen()`
7. Move publish entries to `boot()` via `$this->publishes([...])`
8. Delete any Hyperf factory/resolver classes replaced by inline closures
9. Delete the ConfigProvider (unless it still has `aspects`)
10. Add provider to root `composer.json` `extra.hypervel.providers`
11. Update package `composer.json` — replace `extra.hyperf.config` with `extra.hypervel.providers`
12. Add to `DefaultProviders` (alphabetical order)
13. Remove ConfigProvider from root `composer.json` `extra.hyperf.config`
14. Run phpstan, then full test suite
15. Investigate any circular dependency errors — usually an alias direction issue

### Converting Hyperf Listeners and Events

When porting Hyperf packages, their `ListenerInterface` listeners and event classes must be converted to Laravel-style patterns.

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
- **Blocked** — depends on unported code. STOP and explain what's blocked and why. Prefer adapting the test to work with the current codebase over commenting it out. Only comment out individual test methods (not whole files) as a last resort, with user approval.

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
4. If any failure exposes a source code bug (typing, logic, behavior), STOP and report root cause + recommended fix for approval.
5. Once green, commit that test class (and any approved source fixes) before moving to the next class.

#### 6. Run full phpunit

After all test files are ported, run the full test suite. Same rules as the source porting workflow — straightforward fixes go ahead, anything complex gets stopped and explained.

### General Rules

These apply to all test porting, regardless of whether the source is Hyperf or Laravel.

#### Base Classes

**Never extend `PHPUnit\Framework\TestCase` directly.** Always use one of these:

| Class | Use When |
|-------|----------|
| `Hypervel\Tests\TestCase` | Unit tests, mocks only, no container needed |
| `Hypervel\Testbench\TestCase` | Integration tests, needs container (facades, config, DB, etc.) |

Always call `parent::setUp()` in your setUp method.

#### Coroutine Support

Code that uses `Context` for state (like `DatabaseTransactionsManager`) requires tests to run in coroutines. Without this, Context state persists across tests since they share the non-coroutine context.

**Add the `RunTestsInCoroutine` trait** to individual test classes that need it:
```php
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

class MyTest extends TestCase
{
    use RunTestsInCoroutine;
}
```

Each test runs in a fresh coroutine. Context is automatically destroyed when the coroutine ends — no manual cleanup needed.

**Optional hooks** (define if needed):
- `setUpInCoroutine()` — runs inside the coroutine before the test
- `tearDownInCoroutine()` — runs inside the coroutine after the test

**Disabling coroutines:** If a base class uses `RunTestsInCoroutine` but a specific test class needs to run outside coroutine context, set `protected bool $enableCoroutine = false;` on that class. This is only relevant when the trait is present (via the class itself or a parent).

#### Per-Package Base Test Cases

Do **not** create per-package abstract test case classes (e.g., `EngineTestCase`, `CoroutineTestCase`) just for coroutine support. Use `Hypervel\Tests\TestCase` + trait directly.

A per-package base class is only justified when there is shared setUp logic beyond just coroutines — e.g., shared container mock setup, shared helpers, or shared test fixtures that multiple test classes in the package need.

#### Mockery

**Always import as `m`:** Use `use Mockery as m;` and call `m::mock()`, `m::spy()`, etc. Never use the full `Mockery::` prefix.

**Never add `Mockery::close()` to tearDown.** It's handled globally by `AfterEachTestExtension` for all tests.

#### Docblocks and Types

- Add `declare(strict_types=1);` at the top of every file
- Add `@internal` and `@coversNothing` docblock to every test class
- Do **not** add `: void` return type to test methods — existing tests don't use them, stay consistent

#### phpstan

The `tests/` directory is excluded from phpstan. Do not run phpstan on tests.

#### Handling Failing Tests

For tests that fail after conversion:

1. **Easy fixes** (namespace typos, missing return types, etc.) — fix and continue
2. **Non-trivial failures** — STOP and investigate:
   - Identify the root cause (missing feature, source bug, architectural difference)
   - Explain what's missing and what adding it would involve
   - Report findings and wait for instructions

**You do not decide what tests to skip or remove.** Only the user makes that call after reviewing your investigation.

#### Commenting Out Tests

**Commenting out tests should be extremely rare.** Before proposing to comment out a test, first investigate whether small adaptations can make it work with the current Hypervel codebase (it can be updated again later when more things are ported).

When commenting out is genuinely unavoidable (e.g., depends on a completely unported subsystem), **STOP and explain** what's blocked and why, and wait for approval. Never silently comment out tests.

When approved, comment out **individual test methods** (not whole files) with a `@TODO` explaining what needs to happen:

```php
// @TODO Enable once {package} is ported - depends on {SpecificClass} which doesn't exist yet
// public function testSomething(): void
// {
//     ...
// }
```

This keeps the test visible for future searchability (`@TODO` grep) rather than requiring diffs against the source to discover what's missing.

#### Removed Tests

Removing tests is **incredibly rare** and should almost never happen. Always **STOP and explain** why you believe a test should be removed, and wait for approval.

When the user approves removing a test, replace it with a comment **in the same position**:

```php
// REMOVED: testMethodName - Reason for removal
```

This preserves the test's location so future diffs against Hyperf/Laravel show intentional removals rather than tests that look like they need porting.

### Porting Hyperf Tests

#### Directory Structure

All tests live in `tests/{PackageName}/` (PascalCase), regardless of whether they originate from Hyperf or Laravel. File names and directory structure should mirror the source (Laravel or Hyperf) for 1:1 mapping — this enables automated porting of upstream PRs.

When both Hyperf and Laravel have tests covering the same class, merge them into one file — take the more comprehensive version as the base and add unique tests from the other.

#### Namespace Changes

- `HyperfTest\{Package}` → `Hypervel\Tests\{Package}`
- All `Hyperf\` source imports → `Hypervel\`

#### Boilerplate Removal

- Remove the Hyperf license header block (`@link`, `@document`, `@contact`, `@license`)
- Remove PHPUnit attributes: `#[CoversNothing]`, `#[CoversClass(…)]`, `#[Group(…)]` — use PHPDoc annotations only

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

Hyperf uses `#[Group('NonCoroutine')]` on individual test methods to mark tests that must run outside a coroutine. In Hypervel, extract those methods to a separate test class. If the base class uses `RunTestsInCoroutine`, set `protected bool $enableCoroutine = false;` on the new class.

#### Hyperf Quick Checklist

1. Update namespace from `HyperfTest\{Package}` to `Hypervel\Tests\{Package}`
2. Add `declare(strict_types=1);`
3. Change `Hyperf\` imports to `Hypervel\`
4. Remove Hyperf license header and PHPUnit attributes
5. Extend `Hypervel\Tests\TestCase` (not `PHPUnit\Framework\TestCase`)
6. Add `use RunTestsInCoroutine;` if tests need coroutine context
7. Add `@internal` and `@coversNothing` docblock
8. Do **not** add `: void` return types to test methods
9. Change container mock to `Hypervel\Contracts\Container\Container`, all `->get()` to `->make()` (expectations AND direct calls)
10. Change error handler mock to `Hypervel\Contracts\Debug\ExceptionHandler`
11. Extract `#[Group('NonCoroutine')]` methods to separate class
12. Ensure `parent::setUp()` is called
13. Run tests and fix any remaining type errors

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

Laravel tests define helper classes (models, stubs) with generic names like `User`, `Post`, `Comment`. When multiple test files use the same namespace and define classes with the same name, PHP throws "Cannot redeclare class" errors.

**Use test-specific namespaces** (matching Laravel's pattern):

```php
// WRONG - shared namespace causes conflicts
namespace Hypervel\Tests\Integration\Database;

class EloquentDeleteTest extends DatabaseTestCase { ... }
class Comment extends Model {}  // Conflicts with Comment in other files!

// CORRECT - test-specific namespace isolates classes
namespace Hypervel\Tests\Integration\Database\EloquentDeleteTest;

class EloquentDeleteTest extends DatabaseTestCase { ... }
class Comment extends Model {}  // No conflict - different namespace
```

The namespace includes the test class name as the final segment. This means:
- Each test file has its own namespace
- Helper classes can use simple names (`Comment`, `Post`, `User`)
- No `$table` properties needed (Eloquent derives `comments` from `Comment`)
- No explicit foreign keys needed (Eloquent derives `user_id` from `User`)

PHPUnit loads test files directly (not via autoloading), so the namespace doesn't need to match the directory structure.

#### Unsupported Features

Tests for these features should be **removed** (not commented out) without asking — they will never be supported:

- **Databases:** SQL Server, MongoDB, DynamoDB — Hypervel only supports MySQL, MariaDB, PostgreSQL, and SQLite
- **Cache drivers:** Memcached, DynamoDB, MongoDB
- **Dynamic connections:** `DB::build()`, `DB::connectUsing()` — incompatible with Swoole connection pooling

This list is exhaustive. Any other missing functionality is "not yet ported" and requires investigation and reporting.

#### Temporary Workarounds (Until illuminate/events Is Ported)

Hypervel currently uses Hyperf's event system, which has some differences from Laravel's. These workarounds apply until `illuminate/events` is ported. Once ported, search for `@TODO.*illuminate/events` to find tests that need updating.

**Pattern A: `Event::fake()` + `assertDispatched()` — Works as-is**

Hypervel's `EventFake` supports `assertDispatched()`, `assertDispatchedTimes()`, etc. No changes needed:

```php
Event::fake();
// ... test code ...
Event::assertDispatched(ModelsPruned::class, 2);
```

**Pattern B: Mockery mock of Dispatcher — Convert to Event::fake()**

Laravel tests that mock the Dispatcher directly (e.g., `app('events')->shouldReceive('dispatch')->times(2)`) should be converted to use `Event::fake()` + `assertDispatched()`:

```php
// Laravel original using Mockery
app('events')->shouldReceive('dispatch')->times(2)->with(m::type(ModelsPruned::class));
$count = (new MassPrunableTestModel())->pruneAll();

// Hypervel - convert to Event::fake()
Event::fake();
$count = (new MassPrunableTestModel())->pruneAll();
Event::assertDispatched(ModelsPruned::class, 2);
```

**Pattern C: Wildcard listeners — Spread vs array payload**

Hypervel spreads wildcard listener payload as separate arguments; Laravel passes them as an array. Create a working version and comment out the original:

```php
/**
 * @TODO Replace with testOriginalName once illuminate/events is ported.
 *       Hypervel's event dispatcher spreads wildcard listener payload instead of passing array.
 */
public function testWorkingVersion()
{
    // Hypervel version: receives spread arguments ($event, $model)
    User::getEventDispatcher()->listen('eloquent.retrieved:*', function ($event, $model) {
        if ($model instanceof Login) {
            // ...
        }
    });
}

// @TODO Restore this test once illuminate/events package is ported (wildcard listeners receive array payload)
// public function testOriginalName()
// {
//     // Laravel version: receives array ($event, $models)
//     User::getEventDispatcher()->listen('eloquent.retrieved:*', function ($event, $models) {
//         foreach ($models as $model) {
//             // ...
//         }
//     });
// }
```

#### Laravel Quick Checklist

1. Update namespace to `Hypervel\Tests\{Package}`
2. Add `declare(strict_types=1);`
3. Change `Illuminate\` imports to `Hypervel\`
4. Add `@internal` and `@coversNothing` docblock to test classes
5. Extend correct base TestCase (`Hypervel\Tests\TestCase` or `Hypervel\Testbench\TestCase`)
6. Ensure `parent::setUp()` is called
7. Do **not** add `: void` return types to test methods
8. Add type declarations to model properties
9. Fix mock types (PDO, QueryBuilder, Grammar, etc.)
10. Add `->andReturnSelf()` to chained method mocks
11. Use test-specific namespace if file defines helper classes — avoids "Cannot redeclare class" errors when multiple test files define classes with the same name (e.g., `...Database\EloquentDeleteTest`)
12. Remove tests for unsupported features (SQL Server/MongoDB/DynamoDB databases, Memcached/DynamoDB/MongoDB cache, dynamic connections)
13. Run tests and fix any remaining type errors

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
