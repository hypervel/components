# Translation correctness, current parity, and worker lifecycles

## Objective

Bring Translation to current Laravel behavior where that behavior is correct, fix the verified
selector, locale-path, JSON, type, and event defects, and document the state model required by
Hypervel's long-lived workers. The result keeps one worker-lifetime Translator and loader, shared
loaded definitions, coroutine-local current locale, and boot-only fallback and callback
configuration.

This is a corrections plan, not a fresh package-wide audit. It incorporates every verified item
from the prior Translation audit and every same-family issue found while validating those items.
Supported Laravel APIs, named arguments, protected extension points, and conventional package
behavior remain compatible unless an approved correction below explicitly changes them.

## Evidence baseline

- Hypervel baseline: `30ba7559a4` on `audit/translation-correctness-lifecycle-parity`.
- Laravel reference: local `examples/laravel/framework`, branch `13.x`, at `1a7816b370`.
- Baseline focused verification is green for `tests/Translation`,
  `tests/Integration/Translation`, and `tests/Foundation/FoundationApplicationTest.php`.
- `Hypervel\Translation\Translator` is the only implementation of
  `Hypervel\Contracts\Translation\Translator` in `src/`.
- The public `trans()` helper returns that contract when called without a key, so concrete-only
  methods do not provide a statically usable helper API.
- The adjacent `__()`, `redirect()`, `session()`, and `view()` helpers have runtime branches matching
  current Laravel's conditional return metadata. Hypervel omits three annotations and gives
  `session()` an information-free annotation naming the Store contract instead of SessionManager.
- Direct and facade caller traces found twelve string-only framework uses of `get()`: eight in
  Translation, Validation, and Foundation, plus four Auth notification subject/action values.
  Five notification line values and six Validation/Translator lookups deliberately remain
  array-capable.
- `translator`, `translation.loader`, `events`, `config`, and `files` already have canonical
  contract or concrete aliases in `Foundation\Application`; the planned `make()` calls resolve
  the existing singletons rather than creating parallel instances.
- Broad searches found no repository or Laravel use of `[*,*]`. Its present catch-all behavior is
  accidental PHP string comparison, not a documented plural condition.
- Broad searches likewise found no repository or Laravel condition with mismatched `{...]` or
  `[...]}` delimiters, although both currently select as live conditions.
- The seven Translation mutators listed below change state retained on the shared Translator or
  loader. `setLocale()` is different: Hypervel already stores its effective value in
  `CoroutineContext`.

| Reference | Current surface checked | Decision |
|---|---|---|
| Laravel #60443 | typed Translator accessors and tests | Port `string()` / `array()` and make Hypervel's public contract statically complete. |
| Laravel #58367 / #58648 | plural-condition grammar and tests | Port the intended numeric grammar, then close the verified residual invalid-label defect. |
| Laravel #59174 / #59268 | PHP 8.4 float modulo handling | Port only the final operand-level casts; do not cast the whole plural count. |
| Laravel locale hardening commits `c248521f5` / `e78d24f3` | Translator locale validation | Preserve dot-bearing locale names while rejecting exact dot segments at the filesystem boundary. |
| Laravel #59913 / #59688 | loader namespace shapes and empty-array comparison | Port the metadata and strict comparison, then distinguish an empty loaded group from an explicitly keyed empty value. |
| Hypervel runtime traces | JSON values, protected replacements, Application events, worker state | Fix at the existing owning methods without a new service, cache, registry, parser, or lock. |

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is
also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this
plan” refers to that plan's **Established remediation vocabulary** section.

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

## Architecture and retained boundaries

- Translator and FileLoader remain worker-lifetime singletons. Loaded translation definitions,
  selector, fallback, callbacks, namespaces, and loader paths remain shared for the worker
  generation.
- Current locale remains coroutine-local through `CoroutineContext`. No request-scoped Translator,
  config mutation, or locale service is introduced.
- Configuration supplies construction defaults. `app.locale` and `app.fallback_locale` are not
  live mirrors of effective Translator state.
- Two coroutines may perform the same first immutable group read before either caches it. Their
  identical writes are benign; no lock or per-request loaded cache is warranted.
- Language files are deployment-time inputs. Worker reload remains their invalidation boundary;
  no watcher, LRU, realpath cache, or invalidation registry is added.
- `Loader::addPath()` remains a Hypervel contract extension implemented by every loader.
- `translation-01` namespace `"0"` behavior and `support-02` replacement-value enum handling are
  preserved. Translation identifiers remain string APIs.
- `docs/ai/differences-vs-laravel.md` remains untouched because its first line forbids new entries
  while it awaits deletion. Its stale references in `AGENTS.md` remain unchanged because their
  correct replacement depends on the owner's pending keep-or-delete decision.

### Reported observations outside this work unit

Preserve these in the owner handoff without changing the cited references or fixture here:

- `AGENTS.md` still describes `docs/ai/differences-vs-laravel.md` as current and instructs keeping
  it consistent, contradicting the guide's first-line deletion banner added by `83f7ad8a7`.
- `tests/Support/SupportUriTest.php` contains `https://hypervel.org/docs/11.x/...` as arbitrary URI
  parser inputs from past mechanical de-branding. They have no functional effect and do not belong
  in a Translation work unit.

## Findings and final decisions

| ID | Category | Severity | Final decision |
|---|---|---:|---|
| `translation-02` | Laravel API / contract parity | Improvement | Add typed `string()` / `array()` retrieval, make the Translator contract's existing `get()` signature truthful and complete, route string-only framework consumers through the typed boundary, and restore conditional `__()` metadata. |
| `routing-26` | Helper type parity | Minor | Describe `redirect()`'s Redirector-or-response result conditionally. |
| `session-25` | Helper type defect | Minor | Replace the information-free, wrong-contract `session()` metadata with its Manager/value/null conditional. |
| `view-42` | Helper type parity | Minor | Describe `view()`'s supported Factory-or-View result conditionally. |
| `translation-03` | Plural-condition defect | Major | Use one exact numeric grammar with paired delimiters for extraction and stripping; preserve invalid bracketed content and reject accidental `[*,*]`. |
| `translation-04` | PHP 8.4 pluralization defect | Major | Cast only modulo operands, retain float exact/range behavior, and replace the vacuous deprecation test. |
| `translation-05` | Filesystem boundary defect | Major | Reject separators and exact `.` / `..` locales before filesystem access while allowing dot-bearing locale names. |
| `translation-06` | Falsey value defect | Minor | Treat only `null` as a missing JSON translation so `''`, `'0'`, and `[]` remain real values. |
| `translation-07` | JSON diagnostics defect | Minor | Reject non-array JSON roots and non-null scalar top-level translation values at the file boundary with path-bearing diagnostics. |
| `translation-08` | Worker-lifecycle documentation | Major | Mark shared mutators Boot-only and document effective locale and fallback ownership. |
| `translation-09` | Optional-event overhead | Improvement | Guard `LocaleUpdated` construction and dispatch with `hasListeners()` and use typed canonical resolution. |
| `translation-10` | Public callable type defect | Minor | Narrow `stringable()` to its real closure-or-class-string surface and reject an inert class registration immediately. |
| `translation-11` | Type / upstream metadata parity | Minor | Complete loader map shapes and the fallback type. |
| `translation-12` | Provider / package ownership cleanup | Minor | Use typed `make()` resolution, one config resolution, no duplicate defaults or promoted assignment, and remove the unused direct Container dependency. |
| `translation-13` | Test ownership and isolation | Minor | Replace process-global fixture communication, restore inherited coroutine execution, correct bases/types, and remove stale fixture code. |
| `translation-14` | Array translation defect | Major | Make the replacement boundary array-capable so JSON and PHP-file arrays preserve mixed leaves, explicitly keyed empty arrays remain values, and `get(): array|string` is honored consistently. |

## Approval surface before implementation

The implementation proceeds only after the owner has reviewed these intentional public or
behavioral corrections:

1. Add `string()`, `array()`, `getFallback()`, and `setFallback()` to the public Translator
   contract; correct `get()` to return `array|string` and accept `$fallback`; migrate direct and
   facade consumers whose own API requires a string; regenerate Lang. An array at those sites will
   throw the accessor's key-specific `InvalidArgumentException` instead of a later, call-site-
   dependent `TypeError`.
2. Widen protected `Translator::makeReplacements()` from `string` to `array|string`, including its
   conditional return type. Preserve non-string values inside arrays instead of following
   Laravel's inconsistent PHP-file path, which coerces values and emits a PHP deprecation for
   null. This is more permissive and matches `array<array-key, mixed>`, but it is still a protected
   extension-point and observable behavior change.
3. Narrow `Translator::stringable()` from `callable|string` to `Closure|string` and throw when a
   class string is registered without a handler. Invokable objects and callable arrays were
   accepted by the native type but failed inside the method; function strings registered an inert
   null entry.
4. Reject unsupported plural labels `[*]`, `[1,]`, punctuation labels, and `[*,*]` as literal text,
   and require paired condition delimiters. `[*,*]`, `{1]`, and `[1}` currently select despite
   having no repository or Laravel consumer; `[*,*]` does so only because PHP compares every
   tested number as greater than the string `'*'`.
5. Correct the locale traversal and scalar-JSON defects shared by current Laravel rather than
   preserving them for parity.
6. Record the intentional Laravel locale/config difference: effective current locale is
   request-local, fallback is a boot-only shared Translator setting, and neither setter mutates
   process-global config.

## Implementation

### 1. Correct selector grammar, float handling, and return types

Change `src/translation/src/MessageSelector.php` first because Translator's conditional
replacement return depends on `choose(): string`.

Compose one compile-time pattern for the four supported condition forms: numeric,
numeric-to-numeric, numeric-to-wildcard, and wildcard-to-numeric. Signed integers and decimals
include `.5` and `1.`:

```php
private const string NUMERIC = '-?(?:\d+(?:\.\d*)?|\.\d+)';

private const string CONDITION = '(?:' . self::NUMERIC
    . '|(?:' . self::NUMERIC . '|\*),' . self::NUMERIC
    . '|' . self::NUMERIC . ',\*)';

private const string CONDITION_PATTERN = '/^(?|\{(' . self::CONDITION . ')\}|\[(' . self::CONDITION . ')\])(.*)/s';
```

- Use the composed constant in `extractFromString()` and `stripConditions()`. The branch-reset
  group keeps the condition in capture 1 and value in capture 2 for either paired delimiter;
  stripping returns capture 2 only when the full leading condition matches.
- Normalize admitted numeric operands explicitly and use strict comparisons. No `is_numeric()`
  guard remains because the pattern makes it unreachable.
- Reject `[*,*]`; allowing both wildcards would require a special branch and would convert today's
  accidental all-number match into a non-negative match after numeric casting. Preserve paired
  cross-style forms such as `[1]` and `{1,2}`; delimiter style remains a documentation convention,
  not a new compatibility restriction.
- Narrow `choose(): string`, `extract(): ?string`, and `extractFromString(): ?string`.
- Apply Laravel's final operand-level `(int)` casts only around `%`; keep the original float for
  exact and relational comparisons. Convert loose equality to evidence-based strict comparison:
  original numeric values compare as floats, modulo results as integers.
- Re-evaluate the Arabic `@phpstan-ignore smallerOrEqual.alwaysTrue` after the strict conversion and
  remove it if it no longer suppresses a current finding.
- Do not add a parser object, secondary regex, precision branch above 2^53, locale rule service, or
  whole-input cast.

Update `tests/Translation/TranslationMessageSelectorTest.php` in the same slice:

- extend `Hypervel\Tests\TestCase`, type every revised method and provider, and keep the current
  matrix;
- port Laravel's Markdown and signed-range cases;
- prove valid decimals, signed endpoints, and one-sided wildcard ranges;
- prove `[?]`, `[-]`, `[.]`, `[*]`, `[,]`, `[1,]`, `[*,*]`, `{1]`, and `[1}` remain literal text,
  asserting the complete malformed segment survives condition stripping;
- replace the vacuous `[2,*]` float-deprecation test with a plain Polish plural string that reaches
  a modulo branch while deprecations are promoted, and retain an English `1.5` plural regression.

Run this test file immediately.

### 2. Complete the Translator contract and centralize array replacements

Update `src/contracts/src/Translation/Translator.php` and
`src/translation/src/Translator.php` together.

The contract gains the universal surface actually used by the framework and helper:

```php
public function get(
    string $key,
    array $replace = [],
    ?string $locale = null,
    bool $fallback = true
): array|string;

public function string(
    string $key,
    array $replace = [],
    ?string $locale = null,
    bool $fallback = true
): string;

public function array(
    string $key,
    array $replace = [],
    ?string $locale = null,
    bool $fallback = true
): array;

public function getFallback(): string;

public function setFallback(string $fallback): void;
```

`setFallback()` carries the same Boot-only shared-state warning on the contract and implementation.
Port Laravel's current typed accessor behavior and exception messages immediately after `get()` in
upstream order.

Complete the conditional metadata in `src/foundation/src/helpers.php` for `__()`, `redirect()`,
`session()`, and `view()`. Use the real Manager on `session()`'s null branch and null for its array
branch. Add imports for `Redirector` and `SessionManager`, remove the stale Session Store-contract
import, use existing View and RedirectResponse imports, and normalize the redirect native union to
the same short names. Also use the already-imported `CookieJar` in its conditional annotation. Do
not change runtime branches or normalize correct FQCN annotations on unrelated helpers.

Use the typed accessor everywhere the framework itself promises a string:

- call `string()` from `Translator::choice()` and
  `PotentiallyTranslatedString::translate()`;
- call `string()` for the four leaf-message lookups in
  `src/validation/src/Concerns/FormatsMessages.php` and for the zero-error summary in
  `src/validation/src/ValidationException.php`;
- call `string()` for FormRequest's unknown-field message and remove its now-redundant
  `@var string` assertion;
- change only the subject and action text in Auth's `ResetPassword` and `VerifyEmail`
  notifications to `Lang::string()`.

Keep `get()` where arrays are supported: `Translator::has()`, Validation's bulk custom-message and
attribute lookups, the `AnyOf`, `Can`, and `Enum` rule messages, and all five Auth notification
lines. `SimpleMessage::line()` deliberately formats array values, while its `subject()` and
`action()` parameters are strings. If an exact custom validation-message key resolves to an array,
fail through `string()` rather than skipping it and hiding the invalid configuration. Do not
mechanically convert helpers or other `array|string` consumers.

Make one existing protected method own both string and array replacement:

```php
/**
 * Make the place-holder replacements on a line.
 *
 * @return ($line is array ? array : string)
 */
protected function makeReplacements(array|string $line, array $replace): array|string
{
    if (empty($replace)) {
        return $line;
    }

    if (is_array($line)) {
        foreach ($line as $key => $value) {
            if (is_array($value) || is_string($value)) {
                $line[$key] = $this->makeReplacements($value, $replace);
            }
        }

        return $line;
    }

    // Existing string replacement path.
}
```

This preserves integer and string keys, order, non-string scalar types, object identity, and the
caller's original array. Only strings can contain placeholders, so no scalar coercion or leaf
validation pass is added.

- Keep `empty($replace)` first so arrays without replacements return untouched and incur no walk.
- Replace the JSON terminal truthiness fallback with `makeReplacements($line ?? $key, $replace)`.
- Collapse `getLine()`'s string and non-empty-array branches into one call to
  `makeReplacements()` and remove its separate `array_walk_recursive()` implementation. Keep an
  empty loaded group as no line, while an explicitly keyed empty PHP or JSON array remains a real
  `[]` translation and does not fall through to the fallback locale.
- Keep string-only consumers fail-fast for every array-valued key through `string()`, including
  empty arrays.
- Keep a top-level non-null, non-string JSON value invalid: nested mixed values are covered by the
  promised array result, while `get(): array|string` cannot represent a top-level scalar. Preserve
  `null` as the missing-translation sentinel. Do not widen the return, coerce the value, or
  special-case `has()`.
- Narrow `$fallback` from `?string` to `string`.
- Change `stringable()` to `Closure|string`, document the string as `class-string`, and throw
  `InvalidArgumentException` at registration when a class string has no handler. Use
  `@param class-string|Closure $class`. Do not check class existence or normalize other callable
  shapes.
- Document `$stringableHandlers` as `array<class-string, callable>` after the null registration path
  is removed.
- Add Boot-only warnings to `handleMissingKeysUsing()`, `determineLocalesUsing()`, `stringable()`,
  `addLines()`, `addNamespace()`, `addPath()`, and `addJsonPath()`, each naming the shared worker
  state it changes. Use the same shared-instance wording for `setSelector()` and `setLoaded()`, and
  tell `setFallback()` callers to use `setLocale()` for request-local overrides. Do not warn
  coroutine-local `setLocale()` or directly instantiated loader mutators.

Update `tests/Translation/TranslationTranslatorTest.php`:

- port the four typed accessor success/failure tests;
- cover `''`, `'0'`, empty JSON and PHP arrays, fallback suppression, and `has()` agreement;
- prove `choice()` on an array-valued key fails through the typed string accessor;
- exercise the same nested mixed-leaf array through JSON and normal groups, with and without
  replacements, proving string replacement plus preservation of integer, float, boolean, null,
  object identity, keys, order, and unchanged caller input;
- cover valid closure registration, class-string plus handler, immediate missing-handler failure,
  and native rejection of callable arrays and invokable objects;
- type every test method and `getLoader(): Loader`;
- prove the real Translator's fallback defaults to `''` and round-trips through its public getter
  and setter;
- remove the inert third Translator constructor argument;
- de-brand the enum and URL fixture prose without inventing Hypervel version claims:
  `The release shipped in :month 2025`, `Stay tuned for version :version`,
  `:person gets excited about every new release`, and `https://hypervel.org/docs`.

Run this test file immediately after the contract and Translator slice.

Update only the affected strict test doubles:

- allow `string()` on the shared translator mock in
  `tests/Foundation/FoundationFormRequestTest.php` while retaining `get()` for array-capable
  Validator metadata;
- add `string()` stubs beside the `get()` stubs in the two failing current-password cases in
  `tests/Validation/ValidationValidatorTest.php`. The two passing cases render no message and
  remain unchanged. Add `: void` to the revised test method.

Do not add matching expectations to the three `get()->never()` cases or bare Validation Factory
mocks. Unstubbed calls already fail on those strict mocks. Existing real-Translator tests in
Validation own valid `FormatsMessages`, `ValidationException`, and
`PotentiallyTranslatedString` behavior; do not duplicate the accessor's wrong-type matrix at every
consumer.

`ResetPasswordNotificationTest` already builds the mail message and exercises both migrated Auth
calls. Add one direct mail-message case to `VerifyEmailNotificationTest` that asserts its subject
and action text, covering the corresponding valid path without another wrong-type test.

### 3. Protect FileLoader's path and JSON boundaries

Update `src/translation/src/FileLoader.php`:

- at the beginning of public `load()`, before any filesystem call, reject a locale containing `/`
  or `\`, or exactly equal to `.` or `..`, with the existing
  `InvalidArgumentException('Invalid characters present in locale.')` message;
- use the identical literal predicate in `Translator::setLocale()` for eager feedback while
  continuing to allow `en.UTF-8`;
- replace the JSON decode guard with only `! is_array($decoded)`, which subsumes decoded null and
  syntax failures and retains the existing path-bearing RuntimeException;
- reject each decoded top-level value that is neither null, a string, nor an array with a path- and
  key-bearing RuntimeException; nested arrays may retain mixed leaves, while null remains the
  missing-translation sentinel;
- remove the redundant assignment to the promoted `$files` property;
- document `$hints` and `namespaces()` as `array<string, string>`.
- Link the identical locale predicates in `FileLoader::load()` and `Translator::setLocale()` with
  reciprocal comments rather than extracting a validator for a two-condition check.

Update `src/contracts/src/Translation/Loader.php` and `src/translation/src/ArrayLoader.php` with the
same namespace-map return shape. Do not add locale or JSON schema abstractions.

Update `tests/Translation/TranslationFileLoaderTest.php`:

- extend `Hypervel\Tests\TestCase`, type revised methods and scalar JSON providers, and use the
  imported `RuntimeException`;
- assert separator and exact-dot locales throw before `exists()`, `get()`, or `getRequire()`;
- assert `en.UTF-8` reaches the expected JSON and PHP paths;
- drive valid scalar JSON values (`1`, `true`, `false`, string, and `null`) plus malformed JSON
  through the existing diagnostic, while arrays including numeric keys remain accepted.
- prove a non-null scalar top-level translation value reports its file and key before reaching
  Translator;
- prove a null value loads successfully, then crosses the real FileLoader-to-Translator boundary
  as a missing key for both `get()` and `has()`.

Add Translator-level coverage using FileLoader plus a filesystem mock for explicit, fallback, and
custom-resolver invalid locales, and cover both constructor/configured and direct setter validation.
This proves every supported entry path reaches the trust-boundary check without duplicating
validation in `get()` or `choice()`.

Run FileLoader and Translator tests after the slice.

### 4. Correct Application locale resolution and optional events

Update the locale block in `src/foundation/src/Application.php` to resolve the canonical contracts:

```php
$translator = $this->make(TranslatorContract::class);

$previous = $translator->getLocale();
$translator->setLocale($locale);

$events = $this->make(DispatcherContract::class);

if ($events->hasListeners(LocaleUpdated::class)) {
    $events->dispatch(new LocaleUpdated($locale, $previous));
}
```

- Resolve each dependency once per method.
- Apply `make(TranslatorContract::class)` to `getLocale()`, `getFallbackLocale()`, `setLocale()`, and
  `setFallbackLocale()`; use `make(DispatcherContract::class)` only in `setLocale()`.
- Keep locale assignment before event-dispatcher resolution, listener detection, and event
  dispatch. This preserves the existing mutation ordering and synchronous event when a listener
  exists.
- Add a concise source WHY at the Laravel config-mutation omission: process-global config supplies
  boot defaults, while effective current locale is request-local and fallback belongs to the
  shared Translator.
- Do not skip same-locale events, defer dispatch, cache listener state in Application, or introduce
  another locale service.

Update the locale tests in `tests/Foundation/FoundationApplicationTest.php`:

- mock `Contracts\Translation\Translator` and `Contracts\Events\Dispatcher`, not `stdClass`;
- expect `hasListeners(LocaleUpdated::class)` before dispatch in the listener case;
- add the no-listener case and prove no dispatch occurs;
- prove both setters change Translator state without changing `app.locale` or
  `app.fallback_locale`, with concise matching `REMOVED:` comments for Laravel's omitted config
  writes;
- use global expectation ordering to pin previous-locale lookup and mutation before listener
  detection and dispatch;
- type every revised test method.

Run the focused Foundation locale tests, then the whole FoundationApplicationTest file.

### 5. Clean provider ownership, metadata, and generated facade

Update `src/translation/src/TranslationServiceProvider.php`:

- resolve `ConfigRepository`, Translation `Loader`, and `Filesystem` through `make()`;
- resolve config once, use `string('app.locale')` and `string('app.fallback_locale')` without
  duplicate `'en'` defaults, and keep config as the construction seed to avoid recursive
  Translator resolution;
- retain the existing canonical singleton keys and path precedence.

Remove only the unused direct `hypervel/container` requirement from
`src/translation/composer.json`. Support continues to own the provider parent's Container
dependency.

Require ParaTest `^7.24` in the root and dogfood manifests and restore PHPUnit `^13.0.3` in the
root, Testbench, and dogfood manifests. ParaTest 7.24 is the first released version that supports
and requires PHPUnit 13.3, so raising the runner floor structurally excludes the incompatible
ParaTest 7.23 / PHPUnit 13.3 pair without a PHPUnit ceiling. Keep the wider PHPUnit constraint
truthful for standalone Testbench installs, where ParaTest does not own the minimum version. Use
`composer require --dev` for the root manifest and local ignored lock; edit the two lockless
manifests directly. Do not patch vendor code, require an unreleased branch, add runtime
compatibility checks, or commit an ignored lockfile.

Because PHPUnit 13.3 is a new minor release and Hypervel integrates with PHPUnit internals, verify
the resolved versions and rerun the full checkpoint with the new runner. Run dogfood from a fresh
unlocked install to prove its standalone dependency graph selects the same compatible pair.

After source signatures and annotations are final, run
`composer facade "Hypervel\\Support\\Facades\\Lang"`. Inspect the generated
`src/support/src/Facades/Lang.php` and retain only the expected Translation API changes, including
typed accessors and `string|\Closure` `stringable()` metadata. Investigate any other generated diff
instead of accepting it mechanically. Run
`./vendor/bin/phpunit --no-progress tests/FacadeDocumenter/FacadeDocblocksTest.php`; its repository-
wide discovery and non-empty assertion own facade drift detection.

### 6. Restore normal test ownership and coroutine coverage

Update `tests/Integration/Translation/TranslatorTest.php`:

- replace `$_SERVER` callback communication with local variables captured by reference;
- remove the now-unneeded setup/teardown cleanup and redundant `handleMissingKeysUsing(null)` calls;
- type revised methods and provider shapes;
- replace `Taylor | Laravel` with `Taylor | Hypervel`.

Create `tests/Translation/CoroutineIsolationTest.php` and move only the two concurrency tests plus
`YieldingTranslationLoader` from TranslationTranslatorTest:

- inherit the normal test coroutine; do not opt out or call `run()`;
- call `parallel()` directly for both tests;
- run two locale tasks concurrently, yield after each mutation, and assert both isolated child
  locales plus the unchanged parent locale. Never assert inside a bare `Coroutine::create()` child,
  because it reports and discards throwables instead of propagating them to PHPUnit;
- retain the yielding loader delays that force the missing-key interleaving;
- prove child locale mutation does not change the parent locale and missing-key suppression does
  not hide a concurrent callback.

Do not add a package TestCase, per-test cleanup registry, `RunTestsInCoroutine` trait, or source
cleanup for container-owned test state.

Clarify the full-typing convention in `AGENTS.md`: PHP does not allow return types on
`__construct()` or `__destruct()`.

Run the new isolation file immediately, then the complete Translation and Integration Translation
focused suites, including one random-order integration run.

### 7. Make localization documentation canonical and READMEs thin

Update `src/boost/docs/localization.md` in Laravel-docs prose:

- add a short typed-values subsection under Retrieving Translation Strings using
  `trans()->string()` and `trans()->array()`, explaining that the methods throw when a key has the
  wrong value type;
- explain in Configuring the Locale that `app.locale` and `app.fallback_locale` provide each
  worker's initial values, `App::setLocale()` changes the locale for the current request, and
  `App::currentLocale()` reads the effective value;
- add an important warning that fallback locale is shared by a worker and
  `App::setFallbackLocale()` is intended for application boot;
- keep examples task-oriented and avoid implementation terms where they do not help users.

Rewrite `src/translation/README.md` as the required thin package surface: header, existing badge,
`Documentation: https://hypervel.org/docs/localization`, a concise `Differences From Laravel`
section describing `Translator::setLocale()` as coroutine-local, `Translator::setFallback()` as
boot-only worker-shared state, and JSON top-level values as strings, arrays, or the null
missing-translation sentinel, then `Ported from: https://github.com/laravel/framework`.

Add the matching concise locale/config difference to `src/foundation/README.md`, move its existing
upstream line after `Differences From Laravel` to satisfy README ordering, and do not duplicate the
localization guide's usage detail.

Do not add anything to `docs/ai/differences-vs-laravel.md`.

### 8. Finish audit bookkeeping only after verification and review

Update `docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md` after code review:

- mark Translation complete and link this detail plan;
- add concise ledger rows for `translation-02` through `translation-14`, merging rows only where
  they have the same owner and final implementation;
- record on `translation-14` that mixed values are valid inside the promised array result while a
  top-level non-null, non-string JSON value remains invalid under `get(): array|string`;
- record Auth, Foundation, Validation, and Contracts as revalidated consumers where appropriate;
- carry the two report-only observations above into the owner handoff without editing their files;
- preserve concurrently landed audit entries and expected ledger ordering.

Do not copy the pre-consensus `.tmp` findings file into tracked documentation. This plan is the
final design record.

## File ownership

| Owner | Files |
|---|---|
| Translation source | `src/translation/src/{Translator,MessageSelector,FileLoader,ArrayLoader,TranslationServiceProvider,PotentiallyTranslatedString}.php` |
| Contracts | `src/contracts/src/Translation/{Translator,Loader}.php` |
| Foundation | `src/foundation/src/Application.php`, `src/foundation/src/Http/FormRequest.php`, `src/foundation/src/helpers.php` |
| Validation consumers | `src/validation/src/Concerns/FormatsMessages.php`, `src/validation/src/ValidationException.php` |
| Auth facade consumers | `src/auth/src/Notifications/{ResetPassword,VerifyEmail}.php` |
| Generated facade | `src/support/src/Facades/Lang.php` |
| Standards | `AGENTS.md` |
| Package metadata | `composer.json`, `src/testbench/composer.json`, `src/translation/composer.json`, `dogfood/testbench-package/composer.json` |
| Tests | `tests/Translation/{TranslationTranslatorTest,TranslationMessageSelectorTest,TranslationFileLoaderTest,CoroutineIsolationTest}.php`, `tests/Integration/Translation/TranslatorTest.php`, `tests/Auth/VerifyEmailNotificationTest.php`, `tests/Foundation/{FoundationApplication,FoundationFormRequest}Test.php`, `tests/Validation/ValidationValidatorTest.php` |
| User documentation | `src/boost/docs/localization.md` |
| Thin package records | `src/translation/README.md`, `src/foundation/README.md` |
| Audit record | this plan and the core audit plan |

## Verification

### Targeted cadence

Run each new or changed test file immediately after its owning source slice:

```shell
./vendor/bin/phpunit --no-progress tests/Translation/TranslationMessageSelectorTest.php
./vendor/bin/phpunit --no-progress tests/Translation/TranslationTranslatorTest.php
./vendor/bin/phpunit --no-progress tests/Translation/TranslationFileLoaderTest.php
./vendor/bin/phpunit --no-progress tests/Translation/CoroutineIsolationTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Translation/TranslatorTest.php
./vendor/bin/phpunit --no-progress tests/FacadeDocumenter/FacadeDocblocksTest.php
./vendor/bin/phpunit --no-progress tests/Auth/VerifyEmailNotificationTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/FoundationApplicationTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/FoundationFormRequestTest.php
./vendor/bin/phpunit --no-progress tests/Validation/ValidationValidatorTest.php
```

Then run the focused package and random-order checks:

```shell
./vendor/bin/phpunit --no-progress tests/Translation tests/Integration/Translation
./vendor/bin/phpunit --no-progress --order-by=random tests/Integration/Translation/TranslatorTest.php
./vendor/bin/phpunit --no-progress tests/Auth
./vendor/bin/phpunit --no-progress tests/Foundation
./vendor/bin/phpunit --no-progress tests/Validation
```

### Static public-contract proof

Create a temporary ignored `.tmp` PHP file that calls both methods through the helper contract:

```php
$message = trans()->string('messages.welcome');
$options = trans()->array('messages.options');
$value = trans()->get('messages.welcome', [], null, false);
$fallback = trans()->getFallback();
trans()->setFallback('en');

function dumpTranslationHelperTypes(?string $maybeNullKey): void
{
    \PHPStan\dumpType(__('messages.welcome'));
    \PHPStan\dumpType(__(null));
    \PHPStan\dumpType(__($maybeNullKey));
    \PHPStan\dumpType(redirect());
    \PHPStan\dumpType(redirect('/home'));
    \PHPStan\dumpType(session());
    \PHPStan\dumpType(session('key'));
    \PHPStan\dumpType(session(['key' => 'value']));
    \PHPStan\dumpType(view());
    \PHPStan\dumpType(view('welcome'));
}
```

Run targeted PHPStan on that file and confirm every dumped type, then delete it. Also run a targeted
php-cs-fixer dry-run on `src/foundation/src/helpers.php`. Do not add a committed test fixture or
change PHPStan paths; `tests/` is intentionally excluded.

### Checkpoint gate

After the coherent implementation and documentation slices are complete, run `composer fix` once.
It owns the full formatter, PHPStan, parallel suite, and Testbench checks. If it fails, correct with
targeted checks and resume from the failed script entry as described in `AGENTS.md`.

Before review, also:

- regenerate the explicitly named Lang facade after its source signatures and annotations are
  final, inspect its diff, and run the repository-wide facade drift test;
- search all `src/` for Translator contract implementers and extensions again;
- search all `src/` for direct and facade `get()` consumers again and confirm every remaining use
  is intentionally array-capable;
- search Translation source/tests for stale Laravel-branded fixture strings;
- verify the two locale predicates are textually identical;
- verify every affected worker-lifetime mutator has exactly one useful Boot-only warning;
- verify no `docs/ai/differences-vs-laravel.md` edit exists;
- inspect every changed file and `git diff --check`.

## Performance accounting

- Existing cached translation hits keep the same filesystem and cache behavior. No lock, context
  slot, cache layer, or per-request Translator is added.
- Selector work retains the same regex operations per inspected segment while replacing two
  drifting patterns with one compile-time constant. Numeric casts replace implicit coercions in
  existing branches.
- FileLoader validation adds constant-time string checks only when a group is loaded; cached
  Translator hits still return before FileLoader. `setLocale()` adds only two strict dot-segment
  comparisons to its existing separator check. JSON value validation walks each decoded top-level
  entry once while the file is loaded, never on cached translation hits.
- Array recursion runs only for array-valued translations with non-empty replacements. Arrays with
  no replacements now return earlier than the existing recursive walk.
- Each migrated string-only lookup adds one `is_string()` check after the same underlying `get()`.
  It adds no filesystem access, container resolution, cache entry, or success-path allocation.
- The no-listener locale path adds one cached `hasListeners()` lookup and removes event allocation
  and dispatch. The listener path retains its event and ordering.
- Provider construction resolves config once instead of twice. All provider changes are worker
  startup work.
- Remaining type declarations, tests, dependency constraints, metadata, and documentation add no runtime
  overhead. ParaTest and PHPUnit changes affect development and test execution only.

## Completion criteria

- All `translation-02` through `translation-14` decisions are implemented at their owning boundary.
- Typed helper access works through the contract and generated facade, not only the concrete class.
- `__()`, `redirect()`, `session()`, and `view()` expose their truthful conditional result types to
  static analysis without changing runtime behavior.
- Framework-owned string-only consumers use the typed accessor; array-capable Validation, rule,
  and notification-line consumers retain `get()` deliberately.
- Valid plural conditions retain current behavior; invalid bracketed content remains intact;
  float modulo branches emit no PHP 8.4 deprecation and English `1.5` stays plural.
- No invalid locale reaches a filesystem method through direct loader, explicit locale, fallback,
  resolver, configured locale, or setter paths; `en.UTF-8` remains valid.
- JSON falsey string values and arrays round-trip consistently, explicitly keyed empty PHP arrays
  do not fall through to another locale, null remains the missing-translation sentinel, invalid
  roots and non-null scalar top-level values get named diagnostics, and array replacement has one
  implementation.
- Effective current locale is request-local; fallback and documented mutators are clearly boot-only;
  config remains unchanged by effective locale setters.
- Optional locale events allocate and dispatch only for active listeners.
- Translation tests use repository base cases, normal coroutine execution, local fixture state,
  current typing, and Hypervel-branded data.
- User guidance lives in localization.md; both READMEs remain thin and correctly ordered.
- The full-typing rule explicitly excludes return types on constructors and destructors.
- Root and dogfood resolve released ParaTest 7.24 with PHPUnit 13.3 or later; standalone Testbench
  retains its truthful PHPUnit 13 support floor without a vendor patch or committed dogfood
  lockfile.
- The owner handoff retains the stale AGENTS references and Support URI fixture observations as
  report-only items.
- No stale code, duplicate replacement path, compatibility shim, dead guard, rejected mechanism,
  misleading comment, or superseded documentation remains.
- Targeted tests, the static contract probe, facade drift detection, `composer fix`, self-review, and
  peer code review are green before the audit ledger is closed.
