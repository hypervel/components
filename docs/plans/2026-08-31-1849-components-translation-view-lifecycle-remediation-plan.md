# Translation and View Lifecycle Audit Remediation Plan

## Outcome

Resolve audit findings #2, #3, and #5–8 without adding request-time filesystem polling, arbitrary cache limits, or cross-process coordination. The finished code must:

- stop automatically retaining request-derived translation keys and missing locale/group combinations for the worker lifetime;
- preserve positive translation caching and exact Laravel-shaped translation output while fixing plural missing-key callback input;
- forward the full exception-rendering result Laravel permits and expose the view engine's real nullable initial state;
- preserve content-addressed reuse for stable and finite inline-view variants while repairing stale mappings after explicit deletion or clearing;
- keep warmed inline and named views on their existing paths with no new filesystem, context, or cache-classification work;
- preserve Laravel's public APIs and Factory substitution seam while correcting overly narrow return declarations;
- document only the practical inline-template performance guidance, with no README or Laravel porting-guide entry;
- remove findings #2, #3, and #5–8 from the master audit ledger after this plan owns their completed implementation.

## Settled decisions and evidence

| Area | Decision |
|---|---|
| Parsed translation keys | `NamespacedItemResolver::parseKey()` no longer writes every key to `$parsed`. Explicit `setParsedKey()` and `flushParsedKeys()` remain unchanged. The measured warmed parse cost is about 0.25 microseconds per lookup, while 100,000 unique cached keys retained roughly 38 MiB. High-cardinality validation keys are predominantly misses, so positive-only framework seeding adds coupling without helping the harmful path. |
| Missing groups | Non-empty groups remain in worker-held `Translator::$loaded`; `[]` results are retained only for the current execution. FileLoader and ArrayLoader both use `[]` for a missing or legitimately empty group, so execution ownership avoids permanent negative state while allowing a later execution to discover new translations. |
| Missing-group state shape | Each Translator receives a worker-unique context key from a monotonic identity counter. A small mutable `ReplicableContext` state avoids quadratic copy-on-write growth: measured 1,000/5,000/10,000 misses were about 23x/123x/263x faster than rewriting a nested context array. The counter is identity, is never reset, and receives the same warning as `Logger::$nextFamilyId`. |
| Missing-group hot path | Positive groups retain the existing first `isset()` return. A local Swoole-coroutine microbenchmark measured the repeated-empty-group guard at a median additional 152 ns per lookup, or about 0.046 ms for 300 translations. This is noise-level even for translation-heavy requests. Do not worker-cache default/fallback misses: locale alone does not bound request-derived namespace/group keys, and permanent negative state would hide later custom-loader results. |
| Choice lookup | Lookup substitutions and missing-callback replacements are separate inputs. `get()` supplies the caller's replacements to both. `choice()` supplies no lookup substitutions, supplies the caller's exact replacements to the missing callback, selects the plural segment, then applies replacements once. Automatic `count` remains post-lookup. |
| Translation extension shape | `choice()` no longer happens to invoke an override of public `get()`. That internal call chain is not a documented Laravel extension point; preserving it would require duplicate lookup or execution-context plumbing. Public signatures and observable translation behavior remain compatible. |
| View exception | `ViewException::render()` returns `mixed` because Handler passes truthy results through `Router::toResponse()`, which supports strings, arrays, views, Responsable values, and responses. `report(): ?bool` remains correct: Hypervel documents void/bool report methods and only `false` has framework meaning. |
| Inline cache policy | Content-addressed caching stays unchanged. Stable source and finite variants converge to a bounded set and benefit from worker reuse. Continually unique Blade source grows both per-worker indexes and shared disk artifacts, so a RAM-only cap would add substantial machinery, degrade finite-variant reuse, and leave the root pattern intact. Documentation is the proportionate safeguard. |
| Inline deletion | `BladeCompiler::render(..., deleteCachedView: true)` forgets only the anonymous component's exact content mapping after unlinking its source. The normalized name, finder path, path-engine entry, and compiled marker remain valid because republication uses the same content-addressed name/path and byte-identical source. A global Component flush here would regress a supported per-call API by discarding unrelated view and reflection caches. |
| Inline source ownership | `Blade::render()` always treats its input as raw Blade source, even when the same string is an existing view name. Its private anonymous Component overrides `resolveView()` to use the existing content-addressed cache and creator directly. Normal Components retain Laravel's named-view resolution seam and protected method signatures. |
| View clearing | In-process `view:clear` flushes all Component view/reflection caches and all compiler markers after deletion attempts, before rethrowing an accumulated deletion failure. Those coarse resets are appropriate for a rare administrative operation. Active worker processes still require a reload; changing `server:reload` is not part of this slice. |
| Compiler coupling | The exact inline deletion fix and compiler mtime correction ship together. Once a missing source correctly reports expired, a stale Component mapping would otherwise turn the second default-cache render from an accidental stale-compiled success into `FileNotFoundException`. |
| Inline disk artifacts | Content-addressed source and compiled files remain shared across workers and are owned by the existing explicit `view:clear` lifecycle. No hidden eviction, retention policy, or automatic cleanup is added. |
| Compiler expiry | `Filesystem::lastModified()` returns `int|false`; its suppression means the existing `ErrorException` catch is unreachable. Explicit value checks make a missing source/compiled file expired and let `compile()` report the precise source error. When an unchanged compiled file disappears before `compile()` reads its mtime, `compile()` republishes the contents it already produced instead of recreating an empty file with `touch()`. |
| Documentation | Add one concise Blade warning recommending stable inline component source with changing values passed as data or slots. Dynamic source remains supported. This is usage/performance guidance, not a Laravel API difference. |

## Reference baseline

- Current `examples/laravel/framework` still automatically caches every parsed namespaced key, stores empty translation groups on the request-owned Translator, and routes `choice()` through `get($key, [])`. Hypervel keeps the public API but adapts ownership for a shared worker singleton.
- Current Laravel `Component` deliberately holds `Illuminate\Contracts\View\Factory`, even though Laravel's concrete View holds the concrete Factory. Hypervel must preserve that substitution seam.
- Current Laravel Component, Factory, and FileViewFinder use the same content-addressed name, path-engine, and located-view caches. Hypervel preserves that reuse for stable and finite variants; automatic disk retention is also unchanged.
- Current Laravel `ViewException::render()` is untyped and forwards the previous exception result; Hypervel's `?Response` was an incorrect narrowing.
- Current Laravel `Engine::$lastRendered` is initially null despite the documented string return. Nothing in either framework extends this abstract class: first-party engines implement the View Engine contract directly, so this is dormant public extension scaffolding rather than an active runtime base. Hypervel's native return must still describe its actual state. A third-party subclass that mutates this property would share it through the worker-lifetime EngineResolver singleton; no current consumer or supported failure justifies adding coroutine state.
- Current Laravel `Compiler::isExpired()` retains the exception catch, but Hypervel's concrete Filesystem suppresses `filemtime()` warnings and returns `false`, making value checks the correct local implementation.
- No related `docs/todo.md` item belongs in this slice. Server reload behavior, cross-process invalidation, and repository-wide PHPStan suppression work remain separate owner-approved work.

## Implementation

### 1. Stop automatic parsed-key retention (#2)

Files:

- `src/support/src/NamespacedItemResolver.php`
- `tests/Support/SupportNamespacedItemResolverTest.php`

Keep the existing explicit-cache fast path, then return a newly parsed value without storing it:

```php
if (isset($this->parsed[$key])) {
    return $this->parsed[$key];
}

return ! str_contains($key, '::')
    ? $this->parseBasicSegments(explode('.', $key))
    : $this->parseNamespacedSegments($key);
```

Update `$parsed` and the surrounding comments to describe explicitly seeded values. Add a conditional sentence to `setParsedKey()` explaining that entries remain for the resolver instance's lifetime and therefore for the worker lifetime when called on the shared Translator; do not incorrectly label every resolver instance Boot-only.

Tests must prove:

- ordinary basic and namespaced parsing remains identical;
- repeated ordinary parses invoke parsing again and leave `$parsed` empty;
- validation-shaped/arbitrary keys do not accumulate;
- an explicitly seeded value bypasses parsing;
- `flushParsedKeys()` removes only explicit entries and restores direct parsing.

Do not add Translator-side positive seeding, a context lookup, an eviction cap, or a replacement cache.

### 2. Keep empty translation groups execution-local (#3)

Files:

- `src/translation/src/MissingTranslationGroups.php` (new)
- `src/translation/src/Translator.php`
- `tests/Translation/TranslationTranslatorTest.php`
- `tests/Translation/CoroutineIsolationTest.php`

Add a final package-internal `MissingTranslationGroups implements ReplicableContext`. Finality protects the replication invariant: no subclass can add state that `replicate()` fails to copy. It owns only a nested boolean map and the operations Translator needs: `has`, `mark`, `forget`, and `replicate`. `forget` unsets only the requested leaf; empty parents are unobservable and disappear with the execution-scoped object. Replication returns an independent object with the same recorded misses.

Give each Translator a unique context key:

```php
protected const string MISSING_GROUPS_CONTEXT_KEY_PREFIX = '__translation.missing_groups.';

// Identity generator: never reset in flushState().
protected static int $nextTranslatorId = 0;

protected readonly string $missingGroupsContextKey;
```

Assign it in the constructor. Mirror Logger's full warning that resetting the counter can alias a new Translator to state retained under a destroyed instance's key. `Translator::flushState()` continues to flush macros only and must not reset this counter.

Do not register `MissingTranslationGroups` with `AfterEachTestSubscriber`. It exists only inside `CoroutineContext`, whose existing subscriber cleanup already flushes it between tests.

Keep the positive path first in `load()`:

```php
if ($this->isLoaded($namespace, $group, $locale)) {
    return;
}

if ($this->missingTranslationGroups()?->has($namespace, $group, $locale)) {
    return;
}
```

Create the state lazily only when marking the first empty result. After a successful loader call and pending-line replay:

- non-empty lines go to worker-held `$loaded` exactly as today;
- empty lines mark the execution state and do not enter `$loaded`;
- loader exceptions leave pending lines and state unchanged.

Use `$this->loaded[$namespace][$group][$locale] ?? []` in `getLine()`. Keep `isLoaded()` as the exact positive worker-cache predicate. Before `addLines()` queues a line for a group not positively loaded, clear that group's execution miss so the next lookup reloads and replays the pending operation. `setLoaded()` replaces positives, clears pending lines, and forgets this Translator's whole missing-groups context key.

Tests must prove:

- a missing and a legitimately empty group are loaded once within one execution;
- a later execution probes again and can discover newly available lines;
- thousands of arbitrary locale/group misses leave `$loaded` unchanged and produce exactly the same number of distinct execution-owned markers;
- positive groups remain worker-cached across executions;
- two Translator instances do not suppress one another;
- copied coroutine contexts receive independent state objects;
- `addLines()` after a miss becomes visible and preserves call order;
- `setLoaded()` clears both pending operations and current execution misses;
- exceptions do not consume pending operations or install a negative marker;
- the identity counter survives `flushState()`.

This is Translator lifecycle state, not the cache package's negative-caching feature.

### 3. Separate lookup and plural substitutions (#5)

Files:

- `src/translation/src/Translator.php`
- `tests/Translation/TranslationTranslatorTest.php`
- `tests/Integration/Translation/TranslatorTest.php`

Extract the current `get()` lookup body into one protected internal method with separate arrays for line substitutions and missing-callback replacements. Keep every public signature unchanged:

```php
public function get(...): array|string
{
    $locale = $locale ?: $this->getLocale();

    return $this->getTranslation($key, $replace, $replace, $locale, $fallback);
}

public function choice(...): string
{
    $locale = $this->localeForChoice($key, $locale);

    $line = $this->ensureStringTranslation(
        $key,
        $this->getTranslation($key, [], $replace, $locale, true),
    );

    if (is_countable($number)) {
        $number = count($number);
    }

    $replace['count'] ??= $number;

    return $this->makeReplacements($this->getSelector()->choose($line, $number, $locale), $replace);
}
```

`localeForChoice()` must normalize a missing locale to the current locale and use that locale when no fallback was configured:

```php
$locale = $locale ?: $this->getLocale();

return $this->hasForLocale($key, $locale) ? $locale : ($this->fallback ?: $locale);
```

This preserves the callback locale and avoids an empty-locale loader probe. It also lets implicit `singular|plural` lines select correctly when a missing-key callback supplies them and no fallback locale was configured.

The exact helper names may follow the surrounding Laravel naming, but there must be one lookup implementation and one shared protected string assertion used by `string()` and `choice()`. Declare the new internal `getTranslation()` locale as `string` because both public paths normalize caller input before reaching it. Keep protected `handleMissingTranslationKey(?string)` and `localeArray(?string)` unchanged: Laravel documents both nullable extension contracts, and subclasses may call them directly even though first-party lookup paths supply a concrete locale. Preserve the current exception text for non-string values.

Expand `handleMissingKeysUsing()`'s method docblock with the precise `null|callable(string, array, ?string, bool): ?string` shape and state that the callback receives key, caller-supplied replacements, locale, and fallback flag. Normal `get()` and `choice()` calls supply a concrete locale; null remains possible only through the preserved protected extension path. State that `choice()` supplies only caller replacements and applies automatic `count` to the selected result afterward.

Replace the five implementation-mocking choice tests with behavior tests. Cover:

- exact key, locale, fallback flag, and caller replacement array seen by a missing callback;
- no implicit `count` in callback input unless the caller supplied it;
- automatic and custom count output;
- a replacement containing `|` cannot alter plural segment selection;
- replacements occur only after selection and exactly once;
- Countable, array, integer, and float inputs;
- fallback-locale selection;
- array translations still throw the existing string error;
- `get()` continues to provide the same replacement set to lookup and callback.
- `has()` still suppresses the missing-key callback through the extracted lookup.
- an unset fallback keeps the current locale for the callback and implicit plural selection.

### 4. Correct view exception and engine types (#6, #8)

Files:

- `src/view/src/ViewException.php`
- `src/view/src/Engines/Engine.php`
- `tests/View/ViewExceptionTest.php` (new)
- `tests/View/ViewEngineTest.php` (new)
- `tests/Integration/View/RenderableViewExceptionTest.php`

Change only `ViewException::render(Request): mixed` and remove the now-unused Response import. Forward the previous exception's render result unchanged and retain null when no render method exists. Keep `report(): ?bool` and its false default.

Direct tests must cover string, array, View contract, Responsable, Symfony/Hypervel response, false, and null render values; report true, false, null, and absent report/render methods. Keep one integration test proving Handler and Router still normalize a renderable view exception end to end.

Change `Engines\Engine::getLastRendered()` to `?string`. Test an anonymous concrete subclass returning null before it records a path and the path afterward. Do not add lifecycle state or change unrelated engine classes.

Both new direct test files extend `Hypervel\Tests\TestCase`. `ViewException::report()` does not require Testbench because `Container::getInstance()` creates the minimal container it needs; do not boot a full application for either unit test.

### 5. Repair inline-view invalidation without replacing cache ownership (#7)

Files:

- `src/view/src/Component.php`
- `src/view/src/Compilers/BladeCompiler.php`
- `src/foundation/src/Console/ViewClearCommand.php`
- `tests/View/ComponentTest.php`
- `tests/Integration/View/BladeTest.php`
- `tests/Integration/Foundation/Console/ViewClearCommandTest.php`

#### Exact runtime invalidation

Expand `$bladeViewCache`'s property docblock with only this concise WHY: content-addressed mappings intentionally remain worker-cached so stable and finite variants stay warm. Keep storage guidance in the user documentation rather than growing an audit essay in source.

Centralize the existing content key in a protected static Component helper so lookup and eviction cannot drift:

```php
protected static function bladeViewCacheKey(string $contents): string
{
    return hash('xxh128', sprintf('%s::%s', static::class, $contents));
}

public static function forgetBladeView(string $contents): void
{
    unset(static::$bladeViewCache[static::bladeViewCacheKey($contents)]);
}
```

Use the helper from `extractBladeViewFromString()`. Mark the forgetter `@internal`: public visibility is required only for the BladeCompiler call across classes, not to create an application-facing extension API. It is a normal runtime operation and therefore receives no Boot-only warning.

The private anonymous Component inside `BladeCompiler::render()` must type its promoted template property and override `resolveView()` to resolve raw source directly through the existing content-addressed cache and creator:

```php
public function __construct(protected string $template)
{
}

public function render(): string
{
    return $this->template;
}

public function resolveView(): string
{
    return self::$bladeViewCache[self::bladeViewCacheKey($this->template)]
        ??= $this->createBladeViewFromString($this->factory(), $this->template);
}
```

Do not change `Component::extractBladeViewFromString()`'s protected signature. Normal components may return a named view and must retain that Laravel extension contract. `Blade::render()` accepts raw source, so its private component must not reinterpret a literal template as a named application view or delete that application's template when `deleteCachedView` is enabled.

Place `bladeViewCacheKey()` beside `extractBladeViewFromString()` and `createBladeViewFromString()`. Place `forgetBladeView()` with the existing `flushCache()`, `forgetFactory()`, and `forgetComponentsResolver()` static lifecycle group rather than appending either method to the class.

`BladeCompiler::render()` retains the anonymous Component and original string in its completion closure. When `deleteCachedView` is true, unlink the source and call `$component::forgetBladeView($string)`. Do not clear unrelated Component caches or the compiler marker:

- the next render misses only this Component mapping and republishes the same source path;
- Factory normalization, finder, and engine caches still point to that correct path;
- an existing compiled file was produced from identical source and remains valid.

Add one short WHY beside the exact forget: the other view caches remain valid because the source is republished at the same content-addressed path. Do not repeat the full lifecycle explanation in code.

This exact forget must ship with the compiler mtime correction in step 6. With caching enabled, the current `false >= int` comparison accidentally serves compiled output after source deletion. Once a missing source correctly reports expired, retaining the stale Component mapping would instead make the second render attempt to compile a missing file.

#### Administrative invalidation

Move `CompilerEngine::forgetCompiledOrNotExpired()` in `ViewClearCommand` from before enumeration to after all deletion attempts. Call `Component::flushCache()` beside it before rethrowing any accumulated deletion exception. Flushing after the loop covers cache entries repopulated during deletion as far as an in-process command can; it does not claim to invalidate separate worker processes. The missing-path and glob-failure guards still throw before invalidation because no deletion occurred and no cache can have become stale. `view:cache` already calls `view:clear` in the same process and inherits this behavior.

Tests must prove:

- stable and finite A/B inline variants retain their existing cache reuse;
- two consecutive `Blade::render(..., deleteCachedView: true)` calls succeed with both `view.cache` enabled and disabled, using two test methods with `#[DefineEnvironment]` callbacks so each mode is configured before the Blade compiler singleton resolves;
- after two distinct strings have populated the anonymous class's shared Component cache, deleting one forgets only its entry and leaves the other's mapping intact; inspect the cache directly so the case discriminates against a later replacement with `Component::flushCache()`;
- in-process `view:clear` followed by the same component render recreates the source and compiled output;
- a deletion failure still flushes Component and compiler caches before the command rethrows;
- `Component::flushCache()` continues to force recreation;
- named views, creator/composer dispatch, observers, and custom Factory substitution remain unchanged.
- raw inline source that equals a named view renders literally and leaves the application view file intact when deletion is enabled.

Do not add a direct Factory path, custom capability, execution state, generation, reverse index, compiler classifier, filesystem poll, lock, LRU, automatic disk pruning, or arbitrary cap.

### 6. Make compiler expiry failures precise

Files:

- `src/view/src/Compilers/Compiler.php`
- `tests/View/ViewBladeCompilerTest.php`

After the existing compiled-file and timestamp-check gates, read the source and compiled mtimes explicitly:

```php
$sourceModified = $this->files->lastModified($path);

if ($sourceModified === false) {
    return true;
}

$compiledModified = $this->files->lastModified($compiled);

return $compiledModified === false || $sourceModified >= $compiledModified;
```

Remove the unreachable `ErrorException` catch, import, `@throws ErrorException` tag, and race comment. A missing source is expired so `compile()` reaches `Filesystem::get()` and throws the precise `FileNotFoundException`; a compiled file disappearing after the first existence check is also expired.

Cover source `false`, compiled `false`, source newer/equal/older, disabled caching, disabled timestamp checks, and the compile boundary's precise missing-source exception.

Also correct `BladeCompiler::compile()` after an unchanged compiled hash. Read both mtimes, handle the compiled side first, and atomically republish `$contents` when the compiled file disappeared. If only the source disappeared, leave the valid compiled file unchanged. Compare and `touch()` only when both mtimes are integers. Cover source-only `false`, compiled-only `false`, and both `false`; the last case proves `view:clear` cannot leave the current inline render without compiled output.

### 7. Document stable inline templates and retire duplicate audit text

Files:

- `src/docs/blade.md`
- `docs/plans/2026-08-22-0604-components-04-audit-remediation-plan-codex.md`

In **Rendering Inline Blade Templates**, after the `deleteCachedView` example, add one short warning that also covers components returning raw Blade source:

> Keep inline Blade templates stable and pass changing values as view data or slots. Hypervel caches and stores each distinct template string separately for reuse, so continually generating unique Blade source grows worker memory and stored view artifacts.

Do not claim dynamic source is unsupported or uncached. Do not add a README differences section or porting-guide entry: all public Laravel APIs remain available and the changes are correctness, typing, ownership, and performance fixes.

After implementation and verification, remove findings #2, #3, and #5–8 plus their completed slice references from the master audit plan. This plan becomes the single detailed record.

## Explicit exclusions

- Do not change `Translator::addLines()`'s existing dotted-key precondition.
- Do not change the `view.finder` binding lifetime.
- Do not delete compiled output when `Blade::render(..., deleteCachedView: true)` deletes its source; compiled artifacts remain under `view:clear` ownership.
- Do not add cross-process invalidation, reload detection, deployment watchers, IPC, leases, or filesystem polling.
- Do not add an arbitrary LRU/TTL/configuration option or RAM-only cache bound for inline sources.
- Do not expand this slice into the repository-wide PHPStan suppression audit.

## Verification

Run each changed test file immediately while implementing, then run the focused groups:

```shell
./vendor/bin/phpunit --no-progress tests/Support/SupportNamespacedItemResolverTest.php
./vendor/bin/phpunit --no-progress tests/Translation/TranslationTranslatorTest.php
./vendor/bin/phpunit --no-progress tests/Translation/CoroutineIsolationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Translation/TranslatorTest.php
./vendor/bin/phpunit --no-progress tests/View/ComponentTest.php
./vendor/bin/phpunit --no-progress tests/View/ViewBladeCompilerTest.php
./vendor/bin/phpunit --no-progress tests/View/ViewCompilerEngineTest.php
./vendor/bin/phpunit --no-progress tests/View/ViewExceptionTest.php
./vendor/bin/phpunit --no-progress tests/View/ViewEngineTest.php
./vendor/bin/phpunit --no-progress tests/Integration/View/BladeTest.php
./vendor/bin/phpunit --no-progress tests/Integration/View/RenderableViewExceptionTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Foundation/Console/ViewClearCommandTest.php
```

At the implementation checkpoint run `composer fix` once. It owns formatting, PHPStan, the full parallel suite, and Testbench verification. If it fails, correct with targeted checks, inspect the `fix` script, then run the failed and remaining stages as required by `AGENTS.md`.

Final review must trace:

- every static/singleton/context state transition and cleanup hook;
- parent/child coroutine replication for translation miss state;
- exact versus coarse inline-view invalidation and all unaffected Factory/finder/compiler caches;
- the coupled missing-source mtime and `deleteCachedView` behavior under both cache modes;
- Laravel API signatures, protected extension behavior, and documentation classification;
- warmed named/inline render paths for new I/O, allocations, context access, or cache scans.
