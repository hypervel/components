# View Correctness, Lifecycle, and Current Parity

## Status and scope

**Status:** Complete; implementation, validation, self-review, and code review signed off.

Complete the View audit against:

- Hypervel `0.4` at this branch's base;
- current Laravel framework source, View tests, and full-app View integration fixtures under `examples/laravel/framework`;
- the completed `view-01` request-shared-data and `reflection-02` callable-shape decisions;
- current View, Foundation configuration and console commands, Support facade metadata, Boost documentation, split-package metadata, Mail factory clones, and framework test-state cleanup.

This is a correction and parity pass, not a redesign. Preserve Hypervel's worker-singleton Factory/compiler architecture, coroutine-local render and compile state, worker-lived immutable metadata and freshness caches, lock-free component creation, alias-first `Blade::component()` registration, strict string compiler paths, and request-shared-data overlay. No accepted change adds a lock, watcher, retry, registry, request-scoped Factory/compiler, render-state snapshot, eviction policy, or compatibility shim.

No useful Laravel API is removed or narrowed. The restored provider, compiler, layout, dynamic-component, named-argument, and PHPDoc surfaces improve parity. The intentional alias-first API and strict path model remain documented Hypervel differences. The `@elsePushIf` repair and failed-render loop cleanup correct defects that current Laravel also carries.

### Approved tradeoffs

| Findings | Benefit | Cost | Rejected machinery |
|---|---|---|---|
| `view-04`, `view-26`, `view-27` | Publish complete inline templates, bound retained key bytes, and keep the reserved namespace bounded. | One xxh128 digest per cache lookup; filesystem replacement only on first/incomplete publication. | Locks, retries, polling, a publication registry, LRU/TTL, or a second render path. |
| `view-07`, `view-08`, `view-29`, `view-33`, `view-35` | Isolate transient compiler/render state and clean it deterministically. | Constant-time coroutine-context reads/writes at existing compile, render, or cleanup boundaries. | Compiler/Factory cloning, request-scoped services, or full-state transactions. |
| `view-24` | Avoid duplicate deployment-time compilation below nested roots. | A bounded in-memory path comparison in `view:cache`; no request-path cost. | Persistent path indexes or filesystem watchers. |
| `view-25` | Prove provider/facade/full-app interactions currently absent from package tests. | Test-only fixtures and execution. | A View-specific harness or one oversized integration test. |

## Post-compaction and design rules

After compaction, re-read `AGENTS.md` and this plan in full before editing. Re-open the active source and tests; summaries are navigation only.

## What this audit is not

This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.

Complexity must pay for itself with at least one of:

- a demonstrated failure;
- a complete source trace proving a realistic vulnerable schedule;
- a clear general capability with real consumers and owner approval;
- deletion of greater or riskier complexity elsewhere.

Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.

Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.

Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.

The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

## Audit principles

### 1. Verify before changing

A suspicious pattern is not an actionable finding until the audit establishes:

- the exact file and symbol;
- every relevant caller and callee across `src/` and `tests/`;
- the state or resource owner;
- the initialization, commit, use, and cleanup boundaries;
- a realistic production or test failure schedule;
- why current guards and tests do not prevent it;
- sibling implementations and same-family sites;
- relevant upstream behavior;
- the lowest correct fix boundary;
- a regression strategy;
- the performance and complexity effect of the proposed fix.

Use a focused probe when source reasoning cannot settle native or scheduler behavior. Do not repeatedly run the full suite hoping to reproduce a rare flake.

### 2. Fix the lowest inconsistent contract

Do not add local compensation when a shared lower-level contract is wrong. A caller catch is not enough when a typed filesystem method can return `false`; a per-consumer spawn catch is not enough when Engine exposes an ambiguous spawn contract; a proxy workaround is not enough when pool ownership is undefined.

After changing a lower-level contract, re-audit every affected caller and revisit completed packages that depend on it. Record cross-references in both the owning package and each affected package ledger entry.

### 3. Make ownership explicit

The component that acquires or registers a resource records the exact handle and releases that exact handle. Cleanup must not reconstruct identity from mutable state when the original handle can be retained.

Examples include coroutine IDs, timer IDs, process IDs plus incarnation checks, listener callbacks, pool leases, subscriber objects, stream handles, temporary filenames, signal watcher IDs, and channel tokens.

### 4. Make creation transactional

If code reserves capacity or publishes state before a later operation can fail, it must either finish creation or roll back every earlier change. Do not expose half-initialized objects, registered-but-dead pools, leaked wait-group counts, or published runtime paths without their cleanup owner.

### 5. Make cleanup exhaustive

Independent cleanup steps run even when an earlier step fails. The earliest operation or cleanup failure remains primary. Cleanup failures must not corrupt bookkeeping, skip unrelated cleanup, or turn a successful ownership transfer into a reported failure.

### 6. Bound only external progress

Use deadlines where progress depends on a process, socket peer, lock owner, IPC child, or external service that can disappear. Do not add arbitrary timeouts to ordinary internal coroutine joins once successful creation and ownership guarantee completion.

### 7. Preserve hot-path quality

For every fix, inspect:

- additional allocations;
- container or facade resolutions;
- locking and atomics;
- hashing and serialization;
- new yields or sleeps;
- retries and polling;
- logging or exception construction;
- retained worker memory;
- cache invalidation and eviction.

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Research and final decisions

### State and ownership

`ViewServiceProvider` creates worker-singleton Factory, Blade compiler, and EngineResolver instances. Boot configuration, resolved engines, finder hints, component metadata, inline-template names, and verified compiled-view freshness are intentionally worker-lived. Sections, stacks, components, slots, loops, fragments, translations, compiled paths, and transient compiler state are coroutine-local. Component instances remain fresh per render through `buildWith()`.

`Coroutine::fork()` copies ordinary objects by reference. A `View` stored as section content must therefore render before entering `CoroutineContext`; a default passed to `yieldContent()` is only a local value and must render only when the section is absent.

The package owns filesystem publication and output-buffer cleanup, but no persistent socket, process, timer, channel, or lease. Filesystem replacement is the existing transaction boundary for inline templates. `PhpEngine` unwinds output buffers and formats exceptions before `CompilerEngine::get()` returns, so a surrounding `finally` can always pop the compiled path without losing diagnostics.

### Upstream and compatibility

The accepted maintenance set was checked against current Laravel source, unit tests, `tests/Integration/View`, and the originating changes listed in the audit evidence. Preserve Hypervel's worker-lived freshness/component metadata, coroutine-local request-shared-data overlay (`view-01`), alias-first component registration, and strict string compiler paths. The intentional implementation divergences relevant to this work are exact-size inline publication (`view-04`), corrected `@elsePushIf` parsing (`view-05`), loop cleanup (`view-08`), direct placeholder hashing without Laravel's memo map (`view-15`), directory-boundary cache-root comparison (`view-24`), reserved-namespace replacement (`view-27`), split worker/coroutine echo formats (`view-29`), verified-fresh-only memoization (`view-30`), render-before-store section content (`view-33`), exhaustive compiled-path cleanup (`view-35`), and strict loop/token identity (`view-36`). `view-34` removes a divergence by restoring Laravel's lazy default rendering. These are internal correctness or lifecycle differences and do not remove a useful Laravel-facing API.

The inherited compiled Factory FQCN means overriding `parentPlaceholderSalt()` on a Factory subclass is not complete for cached `@parent` output. Do not claim otherwise and do not invent a dynamic compiler indirection in this work.

### Findings

| ID | Result |
|---|---|
| `view-01` | Revalidate the completed coroutine-local request-data overlay; no source change. |
| `view-02` | Flush slot and slot-stack state with component state. |
| `view-03` | Terminate a nonempty default `style` before concatenation. |
| `view-04` | Publish missing, empty, or partial inline templates with `Filesystem::replace()`. |
| `view-05` | Parse comma-bearing `@pushIf` and `@elsePushIf` expressions like Laravel while preserving ordinary two-argument output. |
| `view-06` | Resolve `Namespace\Accordion\Accordion` for nested default components. |
| `view-07` | Scope the compiler's current section name to one coroutine and compile pass, and define standalone `@parent` as the empty-section placeholder. |
| `view-08` | Flush loop frames through `Factory::flushState()`. |
| `view-09` | Put the four shipped compiler defaults in Foundation's View config and remove duplicate provider fallbacks. |
| `view-10` | Declare direct Symfony dependencies and View package provenance. |
| `view-11` | Delete dead `ValidationExceptionHandle` and remove the unused Validation dependency. |
| `view-12` | Port the bounded current-Laravel readability/reflection maintenance and missing loop coverage. |
| `view-13` | Restore named-argument and PHPDoc accuracy. |
| `view-14` | Accept `?string $expression` on the three protected end-directive compilers. |
| `view-15` | Restore static `parentPlaceholder()` and protected static salt; remove `getParentPlaceholder()`, context map, and stale compiled output via marker `v3`. |
| `view-16` | Restore one-argument protected `addFooters()` using context-owned footer state. |
| `view-17` | Restore public visibility on seven provider registration methods. |
| `view-18` | Document worker-lived mutators at their concrete boundaries. |
| `view-19` | Record the approved alias-first component API as a Laravel difference. |
| `view-20` | Document first-use-per-worker compiled-view freshness accurately. |
| `view-21` | Accept `BackedEnum|string` dynamic component names. |
| `view-22` | Default unnamed slots to `slot`. |
| `view-23` | Catch Xdebug-originated `ParseError` in `hasEvenNumberOfParentheses()`. |
| `view-24` | Deduplicate nested `view:cache` roots without conflating path-prefix siblings. |
| `view-25` | Port the current full-app View integration surface and fixtures. |
| `view-26` | Hash inline-template cache keys to bound retained bytes. |
| `view-27` | Replace, rather than append, the reserved `__components` namespace. |
| `view-28` | Narrow `stringable()` to its actually supported `Closure|string` domain; route the Translation twin separately. |
| `view-29` | Make boot echo format worker-lived and callback overrides coroutine-local and nest-safe. |
| `view-30` | Memoize only verified-fresh compiled paths, restoring `view.cache=false` and first-render deletion recovery. |
| `view-31` | Delete the superseded footer property and type/relocate `pushFooter()`. |
| `view-32` | Remove the View→Foundation cycle; suggest Foundation for optional Vite/fonts directives. |
| `view-33` | Preserve and document render-before-store for View-valued section content copied across coroutine contexts. |
| `view-34` | Do not render a default View when `yieldContent()` finds an existing section. |
| `view-35` | Pop the compiled-path stack in `finally` across success and failure. |
| `view-36` | Use strict identity for the six loose loop/token comparisons in View source. |

## Implementation design

### 1. Complete render-state cleanup (`view-02`, `view-08`)

Keep cleanup with the traits that own the context keys:

```php
protected function flushComponents(): void
{
    CoroutineContext::set(static::COMPONENT_STACK_CONTEXT_KEY, []);
    CoroutineContext::set(static::COMPONENT_DATA_CONTEXT_KEY, []);
    CoroutineContext::set(static::CURRENT_COMPONENT_DATA_CONTEXT_KEY, []);
    CoroutineContext::set(static::SLOTS_CONTEXT_KEY, []);
    CoroutineContext::set(static::SLOT_STACK_CONTEXT_KEY, []);
}

protected function flushLoops(): void
{
    CoroutineContext::set(static::LOOPS_STACK_CONTEXT_KEY, []);
}
```

Call `flushLoops()` from the existing `Factory::flushState()` sequence. Add one real failed-render regression for loop cleanup and the upstream slot reset regression. Do not snapshot state around every render.

Loop cleanup prevents stale depth and parent metadata from affecting a later render. Slot cleanup instead bounds retained request memory: every component creation overwrites its readable slot entry, but without the flush, rendered slot HTML and `ComponentSlot` objects remain in coroutine context for the rest of the request.

### 2. Correct attributes, directives, component resolution, and public types (`view-03`, `view-05`, `view-06`, `view-13`, `view-14`, `view-21`, `view-22`)

Normalize both sides of a style merge without changing non-string appendable defaults:

```php
if ($key === 'style') {
    $value = Str::finish($value, ';');

    if (is_string($defaultsValue) && $defaultsValue !== '') {
        $defaultsValue = Str::finish($defaultsValue, ';');
    }
}
```

Share only the parsing rule genuinely used twice:

```php
protected function parseConditionalStackExpression(string $expression): array
{
    $segments = explode(',', $this->stripParentheses($expression));

    if (count($segments) > 2) {
        $stack = array_pop($segments);

        return [implode(',', $segments), trim($stack)];
    }

    return $segments;
}
```

Use it in both conditional stack compilers. This retains the leading whitespace in Laravel's ordinary two-argument compiled output and preserves fail-loud behavior for malformed one-argument directives. Port Laravel's two multi-comma `@pushIf` regressions and add the equivalent `@elsePushIf` case. Add the nested conventional candidate after the direct class candidate misses:

```php
if (class_exists($class = $class . '\\' . Str::afterLast($class, '\\'))) {
    return $class;
}

return null;
```

Port the upstream unnamed-slot fallback and enum boundary without a resolver:

```php
$name = $this->stripQuotes(
    $matches['inlineName'] ?: $matches['name'] ?: $matches['boundName']
) ?: "'slot'";

public string $component;

public function __construct(BackedEnum|string $component)
{
    $this->component = (string) enum_value($component);
}
```

Rename `FileViewFinder::find()` to `$view` and View's ArrayAccess parameters to `$offset`; add the conditional `ComponentAttributeBag::data()` return PHPDoc and `ComponentSlot::hasActualContent()` exception PHPDoc. Give `compileEndsession()`, `compileEnderror()`, and `compileEndcontext()` the nullable expression argument their dispatcher already supplies. Add direct output, reflection, and subclass compatibility tests.

### 3. Make inline component templates bounded and transactional (`view-04`, `view-26`, `view-27`)

Use a fixed-size cache key and keep cache cardinality/values unchanged:

```php
$key = hash('xxh128', sprintf('%s::%s', static::class, $contents));
```

At publication, replace the reserved hint and accept a file only when its exact size matches the content:

```php
$container = Container::getInstance();
$files = $container->make(Filesystem::class);
$directory = $container->make('config')->string('view.compiled');
$viewFile = $directory . '/' . hash('xxh128', $contents) . '.blade.php';

$factory->replaceNamespace('__components', $directory);

if (! $files->exists($viewFile) || $files->size($viewFile) !== strlen($contents)) {
    $files->ensureDirectoryExists($directory);
    $files->replace($viewFile, $contents);
}
```

This recognizes legitimate empty templates and repairs zero-byte or partial artifacts. Cover an empty template, a truncated template, atomic publication, fixed-size keys without raw contents, repeated reserved-namespace replacement, and normal cache hits. Do not add locks or eviction.

### 4. Isolate compile-pass state and restore compiler extension contracts (`view-07`, `view-12`, `view-16`, `view-23`, `view-28`, `view-29`, `view-31`, `view-36`)

Declare `protected const LAST_SECTION_CONTEXT_KEY = '__view.last_section';` on `CompilesLayouts`, which exclusively owns the transient section state like the other compiler concerns own their context keys. Replace `$lastSection` with that context entry and define a standalone `@parent` as the empty-section placeholder instead of an incidental `TypeError`:

```php
CoroutineContext::set(static::LAST_SECTION_CONTEXT_KEY, '');

CoroutineContext::set(static::LAST_SECTION_CONTEXT_KEY, trim($expression, "()'\" "));

$lastSection = CoroutineContext::get(static::LAST_SECTION_CONTEXT_KEY, '');
$escapedLastSection = strtr($lastSection, ['\\' => '\\\\', "'" => "\\'"]);
```

Initialize the key at the start of every `compileString()` call, beside the footer reset. This prevents one sequential compile, including a compile on another compiler instance in the same coroutine, from supplying the section for a later standalone `@parent`. Prove deterministic interleaving with a yielding custom directive and strengthen the standalone regression by compiling a named section first on the same compiler. Preserve the existing single-slot compile-pass semantics within one coroutine; this finding isolates sibling compilations and compile-pass ownership rather than adding a reentrant compiler stack. No lock, instance discriminator, compiler clone, or reset hook is permitted.

An `@parent` inside an included partial previously depended on whichever section happened to be current when that partial was first compiled. Precompilation or a different cache order could therefore freeze a different placeholder. This unsupported, cache-order-dependent pattern is not preserved with extra state machinery; each separately compiled template owns its own section context.

Restore footer ownership and delete the now-dead property:

```php
protected function addFooters(string $result): string
{
    $footers = CoroutineContext::get(static::FOOTER_CONTEXT_KEY, []);

    return ltrim($result, "\n") . "\n" . implode("\n", array_reverse($footers));
}

/**
 * Push a footer onto the stack.
 */
protected function pushFooter(string $footer): void
{
    $footers = CoroutineContext::get(static::FOOTER_CONTEXT_KEY, []);
    $footers[] = $footer;
    CoroutineContext::set(static::FOOTER_CONTEXT_KEY, $footers);
}
```

Place `pushFooter()` immediately after `addFooters()` so the ownership stays together.

Update `BladeCompiler::compileString()`'s existing guarded call site to use the restored one-argument contract while retaining the local footer guard:

```php
$footers = CoroutineContext::get(static::FOOTER_CONTEXT_KEY, []);

if (count($footers) > 0) {
    $result = $this->addFooters($result);
}
```

The echo format has a worker default and optional coroutine override:

```php
protected string $echoFormat = 'e(%s)';

public function setEchoFormat(string $format): void
{
    $this->echoFormat = $format;
}

protected function getEchoFormat(): string
{
    return CoroutineContext::get(static::ECHO_FORMAT_CONTEXT_KEY, $this->echoFormat);
}

public function usingEchoFormat(string $format, callable $callback): string
{
    $hadOverride = CoroutineContext::has(static::ECHO_FORMAT_CONTEXT_KEY);
    $previous = CoroutineContext::get(static::ECHO_FORMAT_CONTEXT_KEY);

    CoroutineContext::set(static::ECHO_FORMAT_CONTEXT_KEY, $format);

    try {
        return call_user_func($callback);
    } finally {
        if ($hadOverride) {
            CoroutineContext::set(static::ECHO_FORMAT_CONTEXT_KEY, $previous);
        } else {
            CoroutineContext::forget(static::ECHO_FORMAT_CONTEXT_KEY);
        }
    }
}
```

`usingEchoFormat()` must not call `setEchoFormat()`: it owns only the temporary coroutine override. `withDoubleEncoding()` and `withoutDoubleEncoding()` update the worker default through `setEchoFormat()`. The only format read is in `CompilesEchos`, so this adds one context lookup per escaped echo at compile time, not per render. Mail's `Markdown` is the only external consumer and requires the override across nested compilation. `$echoFormat` needs no static cleanup because the test container owns and discards the singleton instance. Add boot-outside/request-inside, nested, exceptional, Mail nesting, and deterministic sibling-coroutine tests.

Narrow `stringable()` to its implementable contract:

```php
public function stringable(Closure|string $class, ?callable $handler = null): void
```

Keep the two supported registration forms and regenerate facade metadata from the corrected concrete method; do not hand-edit generated signatures. Revalidate and route the byte-identical Translation issue as `translation-10`; do not edit the concurrent Translation worktree from this branch.

Port the bounded upstream maintenance in place: direct empty-array checks, explicit `implode('', ...)`, direct `Stringable` use, current component reflection/filtering, alias derivation, and the two missing uncountable-loop tests. Make the complete loose-comparison inventory strict: `ManagesLoops` uses `===` for initial `last`, incremented `first`, and incremented `last`; `BladeCompiler::parseToken()` uses `=== T_INLINE_HTML`; and both parenthesis-token comparisons use `===`. Catch only `ParseError` around `token_get_all()` with a concise WHY naming Xdebug. Do not add a synthetic runtime seam.

### 5. Restore the parent-placeholder and section-content model (`view-15`, `view-33`, `view-34`)

Use one immutable worker salt and no section map:

```php
protected static ?string $parentPlaceholderSalt = null;

public static function parentPlaceholder(string $section = ''): string
{
    // Deliberately not memoized: this is a pure function of one immutable salt.
    return '##parent-placeholder-' . hash('xxh128', static::parentPlaceholderSalt() . $section) . '##';
}

protected static function parentPlaceholderSalt(): string
{
    return static::$parentPlaceholderSalt ??= Str::random(40);
}
```

Remove `PARENT_PLACEHOLDER_CONTEXT_KEY` and `getParentPlaceholder()`. Use `static::parentPlaceholder()` in the layout trait and emit `\Hypervel\View\Factory::parentPlaceholder(...)` from compiled output. Regenerate the View facade and update tests. Bump the Compiler marker from `v2` to `v3` so existing compiled files cannot call the removed method, and update every marker-dependent expectation in `ViewBladeCompilerTest`; add no alias or manual-clear instructions. The salt needs no reset because it is opaque, immutable worker configuration and all stored sections are already reset.

Keep the existing eager conversion only where a mutable View becomes stored section content, and add a concise WHY at that ownership boundary:

```php
$this->extendSection($section, $content instanceof View ? $content->render() : e($content));
```

Avoid rendering an unused yield default:

```php
$sections = CoroutineContext::get(static::SECTIONS_CONTEXT_KEY, []);
$sectionContent = isset($sections[$section])
    ? $sections[$section]
    : ($default instanceof View ? $default->render() : e($default));
```

Keep `isset` semantics. Prove that stored section content does not share a mutable View across copied contexts, an absent default still renders, and a present section does not render the default or produce its stack side effects. Document only the section-storage timing difference.

### 6. Preserve compiled-view cache semantics and diagnostics (`view-30`, `view-35`)

Memoize only a path verified fresh before evaluation:

```php
if (! isset(static::$compiledOrNotExpired[$path])) {
    if ($this->compiler->isExpired($path)) {
        $this->compiler->compile($path);
    } else {
        static::$compiledOrNotExpired[$path] = true;
    }
}
```

Remove the unconditional memo after evaluation. This makes `view.cache=false` compile every time, retains the one-check-per-worker hot path for fresh/precompiled views, pays one extra freshness check only after a first stale compile, and lets the existing missing-file recovery handle deletion after the first fresh check.

Wrap the entire path ownership in `finally`:

```php
$this->pushCompiledPath($path);

try {
    // freshness, evaluation, and missing-file recovery
    return $results;
} finally {
    $this->popCompiledPath();
}
```

Add cache-enabled hot-path, cache-disabled repeated compilation, first-render deletion recovery, successful stack cleanup, and caught-failure stack cleanup tests. Keep exception formatting inside `PhpEngine`, where the current path remains visible before `finally` runs.

### 7. Make configuration and provider contracts explicit (`view-09`, `view-17`, `view-18`)

Add the canonical Foundation defaults:

```php
'relative_hash' => false,
'cache' => true,
'compiled_extension' => 'php',
'check_cache_timestamps' => true,
```

Read them without duplicate fallbacks in `ViewServiceProvider`. Make `registerFactory()`, `registerViewFinder()`, `registerBladeCompiler()`, `registerEngineResolver()`, `registerFileEngine()`, `registerPhpEngine()`, and `registerBladeEngine()` public; keep `createFactory()` protected.

Add concise boot/test lifecycle warnings to `EngineResolver::register()`/`forget()` and `BladeCompiler::withoutComponentTags()`, `stringable()`, `setEchoFormat()`, `withDoubleEncoding()`, and `withoutDoubleEncoding()`. Do not warn on the `Factory::getFinder()` getter and do not make boot registries coroutine-local.

### 8. Correct package boundaries and public documentation (`view-10`, `view-11`, `view-19`, `view-20`, `view-32`)

In `src/view/composer.json`:

```json
"require": {
    "symfony/http-foundation": "^8.1",
    "symfony/http-kernel": "^8.1"
},
"suggest": {
    "hypervel/foundation": "Required for the @vite, @viteReactRefresh, and @fonts directives."
}
```

Remove `hypervel/foundation` and `hypervel/validation` from `require`. View references `Vite::class` only to emit generated code; Foundation remains an optional integration, matching Laravel's split boundary. Delete only `Middleware/ValidationExceptionHandle.php`; retain `ShareErrorsFromSession`, which Foundation registers.

Add a View package metadata test pinning both Symfony constraints, both deliberate Hypervel omissions, and View provider discovery in root/split manifests. Keep the README minimal and in the prescribed order: package header and badge; `Documentation: https://hypervel.org/docs/views`; `Differences From Laravel` for alias-first registration and eager rendering only when a View becomes stored section content; then `Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/View`. Record optional Foundation integration only through Composer's `suggest` metadata, not as a public difference. Update Boost View docs to state that compiled freshness is checked on first use per worker, `view:cache` belongs in deployment, and `view:clear` is needed after local edits with live workers. Use Laravel-style user prose and do not expose internal context/cache implementation beyond what users need.

### 9. Deduplicate cache roots correctly and port integration coverage (`view-24`, `view-25`)

Canonicalize roots where possible, remove trailing separators before deduplication while preserving filesystem roots, and reject only a true descendant with a directory boundary:

```php
$paths = $paths
    ->map(function (string $path): string {
        $path = realpath($path) ?: $path;

        return dirname($path) === $path ? $path : rtrim($path, DIRECTORY_SEPARATOR);
    })
    ->unique();

return $paths->reject(function (string $path) use ($paths): bool {
    // Trimming before appending preserves one boundary separator for filesystem roots.
    $boundary = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return $paths->contains(
        fn (string $existing): bool => $existing !== $path
            && str_starts_with(
                $boundary,
                rtrim($existing, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            )
    );
})->values();
```

Preserve siblings such as `/views` and `/views-admin`, collapse trailing-separator duplicates even when `realpath()` fails, and retain `/` itself. Port Laravel's current `BladeTest`, `BladeAnonymousComponentTest`, `RenderableViewExceptionTest`, and fixtures into package-scoped Hypervel tests. Keep the classes separate. Rename the upstream `tested_...` method only for clear naming/TestDox; PHPUnit 13 already executes it, so do not claim the rename enables coverage. Adapt only framework bootstrap/namespaces and intentional Hypervel differences.

## Testing and validation

### Focused tests

- `tests/View/ViewFactoryTest.php`: slot/slot-stack and loop cleanup, placeholder parity, stored View isolation, and unused yield-default side effects. Use a closure bound to the Factory only to prove both protected slot context entries are empty after `flushState()`; no public behavior exposes the retained slot-stack state.
- `tests/View/ViewComponentAttributeBagTest.php`: default/current style termination and PHPDoc-preserving behavior.
- `tests/View/ComponentTest.php`: digest keys, empty/partial publication recovery, namespace replacement, and cache hits. Protected cache inspection is explicitly allowed here because bounded retained key bytes are the load-bearing `view-26` invariant and no public output exposes the cache key.
- `tests/View/Blade/BladePushTest.php`: comma-bearing `@pushIf` and `@elsePushIf`.
- `tests/View/Blade/BladeComponentTagCompilerTest.php`: nested custom namespace, unnamed slot, and backed-enum paths where owned.
- `tests/View/ViewBladeCompilerTest.php` and focused Blade suites: compiler marker `v3`, coroutine section isolation, echo-format ownership, footer signature, end-directive overrides, Xdebug-only `ParseError` source behavior without a synthetic seam, supported stringable forms, strict maintenance, and loop cases.
- `tests/View/ViewCompilerEngineTest.php`: cache true/false, deletion recovery, and compiled-path cleanup.
- `tests/View/ViewEngineResolverTest.php`, provider/config tests, and new `tests/View/PackageMetadataTest.php`: visibility, canonical defaults, lifecycle docs, and split metadata.
- `tests/Integration/View`: nested-root, path-prefix sibling, trailing-separator duplicate, filesystem-root, and current upstream full-app View scenarios.
- Mail View tests: cloned Factory/finder isolation remains intact.

Tests must be deterministic. Concurrency tests use explicit channels/barriers, not sleeps. Publication tests use owned temporary directories, prove View delegates to the existing replacement boundary, and do not duplicate Filesystem's atomicity tests. Avoid Reflection and closure-bound protected-state inspection where a public/protected path can prove the behavior. The only approved closure-bound state assertions are the slot/slot-stack cleanup invariant and the bounded component-cache key, neither of which has observable public output.

### Validation sequence

1. Regenerate facades with `composer facade` after concrete API changes.
2. During implementation, run PHP CS Fixer on touched files and focused View, Foundation View command/config, Testbench View, Mail View, and package metadata tests.
3. Run `composer fix` once as the authoritative full formatter, both-PHPStan, parallel, Testbench, and dogfood checkpoint.
4. Confirm the regenerated View facade contains `@method static string parentPlaceholder(string $section = '')` and no `getParentPlaceholder` entry.
5. Review the full diff against current Laravel and every caller/callee; check API names, coroutine ownership, cleanup, stale symbols/comments/docs, and hot-path cost.
6. Request independent code review and resolve every finding before completion.

## Records and completion

Before implementation, set all three core routing-index bullets to this View work and name the carried `view-01` and `reflection-02` entries. During implementation:

- replace the “later full `view` audit” marker inside the `view-01` and `reflection-02` rows of the cross-package dependency index with complete revalidation;
- tick the core package checklist's `view` entry only in the final bookkeeping commit;
- add one complete View ledger entry covering `view-02` through `view-36`, rejected machinery, performance, validation, and the detail-plan link;
- amend the earlier `view-01` and `reflection-02` entries with View revalidation;
- add only genuine cross-package rows: Foundation-owned config/command changes and the separately routed `translation-10` twin;
- record the inherited parent-placeholder subclass limitation without presenting speculative machinery as unresolved work;
- leave no TODO, deferral, compatibility alias, dead middleware/property/helper, or superseded documentation.

The View package is complete only after focused and full gates are green, fresh self-review finds no unresolved issue, independent code review signs off, and all records describe the final code rather than decision history.
