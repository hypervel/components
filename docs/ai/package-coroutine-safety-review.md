# Package State / Coroutine Saftey Review — Operating Plan

This document is the operating tool for an ongoing audit of Hypervel (`contrib/hypervel/components/`) for coroutine-safety and state issues. It describes how to audit; **§5** tracks which packages are done.

The audit looks for **any** state-correctness issue under Swoole's stateful worker model — shared mutable state on singletons, race conditions between coroutines, derived state going stale, test-isolation gaps, etc. The classes of bug we've already caught are examples in §1's stop rule; new shapes appear regularly.

---

## 1. Stop rule (non-negotiable)

**STOP the audit and flag the moment you notice anything that might be a state issue, however minor.** Do not continue reading other files to "complete the audit first." Report and wait.

The patterns below are **examples** to prime your pattern recognition. They are **not** an exhaustive list. If you notice something not in the list that smells like state-leakage, race risk, or design fragility — stop and flag it. The user decides what's worth fixing; you decide what's worth surfacing.

Examples of things that always warrant a stop:

- **Public mutator on an auto-singleton without a boot-only docblock.** Setters / `always*` methods on Manager classes, on `Logger`, on `Markdown`, on transport classes. If documented boot-only, fine; if not, flag.
- **Mutated static state without `flushState()` + `AfterEachTestSubscriber` registration.** Any static property that's written to anywhere (boot, runtime, or tests) needs `public static function flushState(): void` resetting it AND that method must be called in `tests/AfterEachTestSubscriber.php`. Both must be present. This includes state brought in by traits — `Macroable::$macros`, etc. — not just statics declared in the package's own files.
- **Chain-mutator pattern** — `$singleton->setX($value)->doSomethingThatReadsX(...)` or `tap(make($singleton), fn ($x) => $x->setX($value))`. Mutates a shared singleton then immediately reads from it; concurrent coroutines race.
- **`resolving()` / `afterResolving()` callback populating per-request state on a singleton.** Fires only on the first resolution under auto-singleton; subsequent coroutines see the frozen state. This is the FormRequest bug shape.
- **Container or factory lookups hardcoded in compiled blade/template output.** E.g., `$__env->getContainer()->make(Factory::class)->make(...)` instead of `$__env->make(...)`. Bypasses cloned factories.
- **Derived state in `__construct` with setters that don't recompute.** Constructor precomputes `$this->signer` from `$this->algo`, but `setAlgo()` updates `$algo` without touching `$signer`. Setters silently broken.
- **Singleton service whose binding closure resolves transient deps.** A `singleton()` binding with `function ($app) { return new X($app->make(SomePerRequestThing)); }` freezes the per-request thing into the singleton.
- **Global SDK/static state mutated inside a lazy binding closure.** Calls like `SomeSdk::setHttpClient(...)` or `SomeSdkAgent::add(...)` inside a `singleton()` closure run on first resolution, not at worker boot. Hidden first-resolve side effects don't appear on the boot timeline.
- **`clone` on a class with object-typed properties** that need isolation. Default PHP clone is shallow; if the class is going to be cloned, `__clone()` must explicitly clone any property whose mutation must not bleed back to the original.
- **Mutating shared state during render/process flow** (not at boot). `Markdown::render()` was calling `flushFinderCache()` and `replaceNamespace()` on the singleton finder mid-render — that's the wrong layer regardless of whether the data is correct.
- **Save/restore scope on an instance property of a shared object.** `$old = $this->x; $this->x = $new; try { ... } finally { $this->x = $old; }` looks scoped, but mutates shared state while the operation runs. If the object is a container singleton, registered observer/listener, cached driver, or any other worker-lifetime instance, concurrent coroutines can see the temporary value. Move the per-call value to `CoroutineContext`.
- **Per-render/per-call handoff through singleton state.** Observer writes → decorator reads, middleware writes → later pipeline code reads, command listener writes → command cleanup reads, etc. Even if today's flow is synchronous, singleton properties/shared data are the wrong storage layer for per-operation handoff.
- **Temporary listener cleanup with `Dispatcher::forget(EventClass)`.** If an operation registers a one-off listener and then calls `forget(EventClass)`, it removes every listener for that event, including app/provider listeners it did not add.
- **Consume-once static state.** Boot sets a static value, runtime reads it and nulls/resets it after first use. This still needs `flushState()` because tests after the first consumer see the consumed value.
- **Repeated derivation from a value that may already be derived.** Helpers that read a current value, append a token/suffix/prefix/namespace, then write it back can accumulate the same marker when called again later in the worker or after cached config/state is restored. Example shape: `prefix . "test_{$token}_"` becomes `prefix.test_1_.test_1_`. Normalize the input first, then append idempotently.
- **Public mutator on an object held by a cached manager/channel.** A handler, transport, driver, or adapter stored inside a manager-cached channel is effectively worker-lifetime state, even if the object itself is not a container singleton.
- **Repeated registration inside per-item wrapper/setup loops.** Registering the same callback inside a helper that runs once per engine/driver/connection creates duplicate worker-lifetime callbacks. Register once at the broader lifecycle point.
- **Anything you'd describe as "probably fine" or "shouldn't matter."** That's exactly when you stop.

When you stop, report:
1. The file and line.
2. The concrete failure mode (what visibly breaks under concurrent load or test cross-pollination).
3. The recommended fix shape (see §3).

---

## 2. Methodology

Run **every step** for every package, no matter how trivial. The "read major files" step is not optional.

### Step 0 — Scope discovery (progressive disclosure)

```bash
cd contrib/hypervel/components
tree -L 1 src/{pkg}
tree -L 2 src/{pkg}/src
find src/{pkg}/src -name "*.php" | wc -l
```

Understand the package shape before drilling. Don't dump a full recursive tree for a large package — bury the signal in noise.

### Step 1 — Read the service provider in full

If `src/{pkg}/src/*ServiceProvider.php` exists, read it end-to-end before grepping. Tells you:

- **What's bound and how** (`singleton` / `bind` / `scoped` / `instance` / `alias`). The choice is load-bearing for per-call vs worker-lifetime semantics.
- **Closure bodies on those bindings** — what gets captured at first-resolve time.
- **Whether `resolving()` / `afterResolving()` callbacks are registered.**
- **Whether the provider does anything at boot that mutates worker state.**

If there's no provider, that's information — the package is either pure utility, alias-based, or wired via something else.

### Step 2 — Resolving-callback scan

```bash
grep -rn -e "->resolving(" src/{pkg}/src --include="*.php"
grep -rn -e "->afterResolving(" src/{pkg}/src --include="*.php"
```

**Run separately.** Combined `\|`-style patterns sometimes silently fail because the system's `grep` is `ugrep`. Use `-e` per pattern. If you get "invalid option" errors on `->`, that's why.

Any `resolving()` callback that populates per-request state on an auto-singletoned class fires only on the **first** resolution. Always flag.

### Step 3 — Binding scan

```bash
grep -rn -e "->bind\b" -e "->singleton\b" -e "->scoped\b" -e "->instance\b" src/{pkg}/src --include="*.php"
```

| Pattern | Semantics | Risk |
|---|---|---|
| `bind(Foo, ...)` | Fresh instance per `make()` | Generally safe |
| `singleton(Foo, ...)` | One instance per worker | Per-request state captured here leaks |
| `scoped(Foo, ...)` | One instance per coroutine | Correct for per-request services |
| `instance(Foo, $obj)` | Always return that exact object | Per-request state captured leaks |

Flag any case where a class with per-request state in its constructor was bound `singleton` or `instance`. Cross-reference with Step 6.

### Step 4 — Container-lookup scan

```bash
grep -rn -e "->make(" -e "->get(" -e "getInstance()->" \
    -e "\$app\[" -e "\$this->container->" \
    src/{pkg}/src --include="*.php"
```

Classify every hit:

1. **Inside a per-call method body, result used locally** → fresh resolve every call, fine.
2. **Inside `__construct`, result stored on `$this->X`** → constructor capture. If auto-singletoned and the captured thing is per-request, leak.
3. **Inside a service-provider closure** → first-resolve capture. Same trap.
4. **Inside any method, result stored on `$this->X` of a long-lived singleton** → deferred capture. Same trap.
5. **Inside a `tap()` followed by chained reads** → chain-mutator. Same trap.
6. **Inside compiled output / generated code** (`$__env->getContainer()->make(...)`, etc.) → bypasses cloned factories. Flag.

### Step 5 — Static-state scan

```bash
grep -rn "protected static\|private static\|public static" \
    src/{pkg}/src --include="*.php" | grep -v function
```

| Category | Verdict |
|---|---|
| Constants disguised as `static $x` (`$primitiveCastTypes = [...]`) | Fine — never mutated |
| Boot-time config via static method (`EncryptCookies::$neverEncrypt`, `Horizon::$databases`) | Fine — written once at boot |
| Worker-lifetime metadata cache (`ReflectionManager::$container`, `Vite::$manifests`) | Fine — stable values |
| **Per-call state mutated per request** | **Bug** |

**For every static that's mutated anywhere — at boot, at runtime, or in tests** — verify both:

- The class has `public static function flushState(): void` resetting it.
- That `flushState()` is called in `tests/AfterEachTestSubscriber.php`.

Truly-immutable constants disguised as `static $x` (e.g., a fixed lookup array assigned only inline at declaration and never reassigned) don't need `flushState()`. Everything else does.

If either of the two checks above is missing for a mutated static, **stop and flag**. The Mailable bug (static `$viewDataCallback` with no `flushState` wiring) showed this can slip through basic state-categorization.

If `flushState()` already exists but is not wired in `AfterEachTestSubscriber`, treat that as strong evidence the cleanup was intended and the subscriber entry was missed.

### Step 6 — Trait audit

Static-state grepping covers the package's own files but misses state brought in by traits. List every trait used by the package's main classes:

```bash
grep -rn "use Macroable\|use HasEvents\|use [A-Z][a-zA-Z]*;" src/{pkg}/src --include="*.php" | grep -v "^use "
```

For each trait the package uses (including ones from other packages — `Macroable`, `HasEvents`, `HasGlobalScopes`, relation traits, etc.):

- Read the trait file.
- If it declares any `static` property that's mutated (e.g., `Macroable::$macros`), the using class needs its own `flushState()` that resets the trait's state (e.g., calls `flushMacros()`).
- If the using class has a `flushState()` but doesn't reset the trait's state, **stop and flag**.

This is exactly how the Mailable bug got past the per-package static scan — `$viewDataCallback` was visible in `src/mail/src/Mailable.php`, but the inherited `$macros` from `Macroable` (`src/macroable/src/Traits/Macroable.php`) was not. Both needed resetting.

### Step 7 — Suspicious-constructor-param scan

```bash
grep -rn -e "Request \$" -e "Session \$" -e "Authenticatable \$" \
    -e "Guard \$" -e "Auth \$" \
    src/{pkg}/src --include="*.php"
```

A class taking any of these in `__construct` and storing on `$this->X` is the **FormRequest shape**: under auto-singleton the captured per-request thing freezes at first construction.

Exception: a thin coroutine-aware proxy that reads from `CoroutineContext` on every method call is safe to capture even on a singleton (e.g., Hypervel's `Session\Store`).

### Step 8 — Read the major files (mandatory)

**This step is not optional.** Greps catch known patterns; reading code catches unknown ones. A package is not audited until you've read its central files in full:

- The Application / Kernel / Manager class.
- The main worker / dispatcher.
- Anything named `Bootstrap*`.
- The package's facade in `src/support/src/Facades/{Package}.php` — the `@method` docblock declarations are the public API surface; a mutator you missed in the package source might be exposed there.
- Middleware classes.
- Transports / adapters / drivers (anything that wraps an external SDK or transport).
- Compilers and any code that produces generated output (`*Compiler.php`, blade directive handlers, etc.).
- Traits that declare state — read in addition to the main class that uses them, so you see the full inherited surface.
- Any file with the package's main public-facing surface (Markdown, Mailable, Logger, etc.).

Bugs caught only by reading code (not greps):

- `Kernel::$requestStartedAt` cross-coroutine race — visible only when reading `handle()` and `terminate()` in flow.
- `Markdown::render()` namespace mutation — visible only when tracing the full render path.
- `Mailable::$viewDataCallback` missing flushState wiring — caught only by reading the file and noticing what wasn't there.
- `<x-mail::...>` compiled-output bug — visible only by reading `ComponentTagCompiler` and noticing the generated code bypasses `$__env`.

Read in 500-line chunks if the file is large. For every mutation you see, ask:

**When does this mutation happen?**

- At boot (service provider, registration, config wiring)?
- Per request (controller, middleware, handler)?
- During render / send / handle / process flow (mid-operation on a shared service)?
- In tests only?

The boundary between "boot" and "render flow" is the load-bearing distinction. `MailManager` setting `alwaysFrom()` at boot is fine; `Markdown::render()` mutating the singleton finder mid-render is a bug regardless of whether the data is correct.

Also pay attention to:
- What state lives where.
- Whether derived state in the constructor goes stale when sources change.

### Step 9 — Classify findings

For each issue:

- **Intentional and safe** — explain why (e.g., "boot-time cache, has `flushState`, doc-blocked").
- **Userland-pattern footgun** — public API that's safe when used correctly but unsafe if misused. Needs boot-only docblock warning.
- **Real bug** — needs a code fix.

**Never close a finding as "out of scope" or "pre-existing."** Every concern surfaces; the user decides what to act on.

---

## 3. Established fix patterns

When a finding falls into one of these shapes, the established fix is:

| Finding | Fix |
|---|---|
| Public mutator on a singleton, **genuinely intended for boot/test-time only** (config wiring, never legitimately called per-request) | Boot-only docblock: `Boot-only. Mutates the shared X; per-request use races across coroutines.` This is documentation, not a code fix — the method works correctly when used as intended. |
| Public mutator on a singleton **that callers reasonably need at runtime / per-request** (a per-render theme, a per-mail subject, etc.) | **Real bug. A docblock is not enough.** Redesign: take as per-call parameter, move state to `CoroutineContext`, or use factory cloning. Adding a "boot-only" warning to a method that callers actually need per-request just deflects the bug. |
| Per-request state stored on a long-lived singleton instance | Move to `CoroutineContext` with key `__{package}.{key}`; provide getter that reads from context |
| Render-time mutation of a shared finder/factory | Add `__clone()` to the shared class; have the caller clone, mutate the clone, render through the clone (`Markdown` / `Factory` pattern) |
| Boot-time hook misused at render time (e.g., `encodeUsing`) | Add a scoped `withX(callable $factory, callable $callback)` helper using `CoroutineContext`. Keep the boot-time setter as-is |
| Static state mutated anywhere | `public static function flushState(): void` resetting it; register in `tests/AfterEachTestSubscriber.php` (alphabetical position). Place `flushState()` at the end of the class, except before a trailing magic dispatch/lifecycle block (`__call`, `__callStatic`, `__get`, `__set`, `__isset`, `__unset`, `__destruct`). `__invoke()` is not a placement anchor. Use the standard `Flush all static state.` title docblock; do not add `Boot-only.`, `Tests only.`, or `Boot or tests only.` warning paragraphs to this test cleanup hook. |
| Consume-once static state | Add `flushState()` and `AfterEachTestSubscriber` wiring. If runtime "consume once" behavior is intentionally best-effort, document that near the consume/reset point. |
| Derived state in constructor that setters don't update | Remove the setters (constructor-only configuration), or add a recompute hook that setters call |
| `singleton()` binding whose closure resolves per-request deps | Restructure deps so the per-request thing is resolved per-call, or use `bind()` if the wrapper is cheap |
| Global SDK/static state mutation in a lazy binding closure | Move the SDK/static setup out of the closure and into provider `boot()` (guard optional dependencies with `class_exists()` when needed). The binding closure should construct the bound object, not configure process-global SDK state. |
| Chain-mutator (`$mgr->setX($v)->do(...)` on a singleton) | Convert to per-call parameter: `$mgr->do(..., x: $v)`. Drop the setter or document boot-only |
| Temporary per-operation event listener cleaned up with `Dispatcher::forget(EventClass)` | Register a long-lived listener at boot; have it dispatch to a per-coroutine callable stored in `CoroutineContext`. Wrap the operation in a `whileX(callable $callback, callable $action)` helper that saves/restores the callable. |
| Per-render/per-call handoff between two code paths | Both writer and reader use `CoroutineContext::set()` / `get()` with a `__{package}.{key}` key. Do not route the handoff through singleton instance state or global shared data. |
| Manager static driver/engine cache | Convert to an instance property on the manager. The manager is already the worker-lifetime singleton, and its instance cache is reset by `Container::setInstance(null)` between tests. |
| Repeated derivation from an already-derived value | Make the derivation idempotent: strip the marker/suffix/prefix/namespace if it is already present, then append the desired value once. |
| Compiled output hardcoding singleton lookup | Change to use `$__env->make(...)` (or equivalent context-aware reference) — diverges from upstream Laravel, document the divergence |

When applying a code fix for a real bug, also add:

- A regression test using `parallel()` + `usleep(5000)` to force interleaving (see §4 below).
- A `Boot-only.` / `Tests-only.` docblock if relevant.

---

## 4. Gotchas and conventions

### `grep` is `ugrep`

`->resolving(` patterns inside `\|`-style alternatives can silently fail. **Use `-e` per pattern.** If greps return empty unexpectedly, suspect this first.

### CoroutineContext key naming

- Constants: `{TOPIC}_CONTEXT_KEY` (e.g., `RENDER_COUNT_CONTEXT_KEY`).
- Key strings: `__{package}.{key}` (e.g., `__view.render_count`).
- Prefix-style: `{TOPIC}_CONTEXT_KEY_PREFIX` with values like `__log.channel_context.` (trailing dot).
- Visibility: `protected const` for class-internal use, `public const` when other code references the key.

Examples:
- `src/translation/src/Translator.php` — `LOCALE_CONTEXT_KEY = '__translation.locale'`
- `src/log/src/Logger.php` — `CONTEXT_KEY_PREFIX = '__log.channel_context.'`

### Coroutine-isolation regression tests

```php
use function Hypervel\Coroutine\parallel;

public function testIsolation(): void
{
    [$resultA, $resultB] = parallel([
        function () { /* mutate A */ usleep(5000); /* read A */ },
        function () { usleep(2500); /* mutate B */ /* read B */ },
    ]);
    // Assert each saw its own state
}
```

The `usleep()` forces interleaving (Swoole yields on sleep). Without it, each closure runs to completion before the other starts. Name new isolation tests `CoroutineIsolationTest` or `CoroutineSafetyTest` for discoverability. For actual coroutine-safety fixes, shape the test so it fails against the old implementation and passes only when the state is isolated correctly.

### Don't put `CoroutineContext::forget` in `flushState()`

`flushState()` runs from `AfterEachTestSubscriber` after the test coroutine ended, and `CoroutineContext::flush()` at the top of the subscriber has already wiped the non-coroutine bucket. `CoroutineContext::forget($key)` from there is a no-op.

`flushState()` is for **worker-lifetime static state only** (class statics, trait statics like `Macroable::$macros`, static caches). If a class's only state lives in CoroutineContext, it doesn't need a `flushState()` and shouldn't be in the subscriber.

### Container singleton instance state vs static state

`Container::setInstance(null)` runs between tests in `AfterEachTestSubscriber`. That gives instance properties on container singletons test isolation for free: the old container and its singleton instances are discarded, and the next test builds a fresh container.

Do not reflexively convert "instance property on a singleton" into "static + `flushState()`" for test cleanup. Static state survives container resets and needs explicit cleanup wiring, so it is usually worse for test isolation. Use static state only when the value genuinely needs class-level/process-level lifetime beyond the current container.

Instance properties on singletons still persist for the worker lifetime in production. If a public API mutates them, either document that API as boot-only or redesign it if callers reasonably need per-request behavior.

### Hypervel test base classes

- `Hypervel\Tests\TestCase` — unit tests. Includes `RunTestsInCoroutine` so each test runs in its own coroutine.
- `Hypervel\Testbench\TestCase` — integration tests. Full app boot per test.
- `Hypervel\Foundation\Testing\TestCase` — when the test writes files / needs a cloned app skeleton.

### Reading Symfony source

When tracing per-request behavior through Symfony-inherited methods, read the Symfony source at `contrib/hypervel/components/vendor/symfony/`. Specifically:

- `private` Symfony methods can't be overridden via subclass dispatch.
- `private const` Symfony constants aren't visible to subclasses via `self::`.
- Methods that read `self::$staticProperty` directly require overriding the *reading* method too — property access isn't late-bound the way method calls are.

### Plan-doc convention

Plans go in monorepo root with `-PLAN.md` suffix.

---

## 5. Progress

**Next:** `cache` (round 2)

| # | Package | Status |
|---|---|---|
| 1 | `api-client` | ✓ |
| 2 | `auth` | ✓ |
| 3 | `boost` | ✓ (docs only) |
| 4 | `broadcasting` | ✓ |
| 5 | `bus` | ✓ |
| 6 | `cache` |   |
| 7 | `collections` |   |
| 8 | `concurrency` |   |
| 9 | `conditionable` |   |
| 10 | `config` |   |
| 11 | `console` |   |
| 12 | `container` |   |
| 13 | `context` |   |
| 14 | `contracts` |   |
| 15 | `cookie` |   |
| 16 | `coordinator` |   |
| 17 | `core` |   |
| 18 | `coroutine` |   |
| 19 | `database` |   |
| 20 | `di` |   |
| 21 | `encryption` |   |
| 22 | `engine` |   |
| 23 | `events` |   |
| 24 | `facade-documenter` |   |
| 25 | `filesystem` |   |
| 26 | `foundation` |   |
| 27 | `hashing` |   |
| 28 | `horizon` |   |
| 29 | `http` |   |
| 30 | `http-server` |   |
| 31 | `inertia` |   |
| 32 | `json-schema` |   |
| 33 | `jwt` |   |
| 34 | `log` |   |
| 35 | `macroable` |   |
| 36 | `mail` |   |
| 37 | `nested-set` |   |
| 38 | `notifications` |   |
| 39 | `object-pool` |   |
| 40 | `pagination` |   |
| 41 | `permission` |   |
| 42 | `pipeline` |   |
| 43 | `pool` |   |
| 44 | `process` |   |
| 45 | `prompts` |   |
| 46 | `queue` |   |
| 47 | `redis` |   |
| 48 | `reflection` |   |
| 49 | `reverb` |   |
| 50 | `routing` |   |
| 51 | `sanctum` |   |
| 52 | `scout` |   |
| 53 | `sentry` |   |
| 54 | `server` |   |
| 55 | `server-process` |   |
| 56 | `session` |   |
| 57 | `signal` |   |
| 58 | `socialite` |   |
| 59 | `support` |   |
| 60 | `telescope` |   |
| 61 | `testbench` |   |
| 62 | `testing` |   |
| 63 | `tinker` |   |
| 64 | `translation` |   |
| 65 | `validation` |   |
| 66 | `view` |   |
| 67 | `watcher` |   |
| 68 | `wayfinder` |   |
| 69 | `websocket-server` |   |

---

## 6. Resume

1. Re-read this doc.
2. Re-read `contrib/hypervel/components/docs/ai/porting.md` if doing any code changes in the components repo.
3. Look at the "Next" pointer in §5 and start that package with §2 Step 0.
4. Apply §1's stop rule strictly.
5. After completing a package, mark its row `✓` and update the "Next" pointer.
