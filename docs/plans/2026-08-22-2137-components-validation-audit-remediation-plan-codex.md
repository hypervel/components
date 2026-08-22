# Validation audit remediation plan

Status: Signed off by `claude-fixes` on 2026-08-22; ready for implementation

Branch: `audit/validation-remediation` from `0.4` at `7741eaad0435450e500304f173dde4f4a5488646`

Scope: master-audit findings 15–20 plus three validation defects exposed while reviewing their shared execution and compilation boundaries

## Goal

Fix the validation optimizer's correctness gaps without giving back the architecture's principal performance gains. Preserve Laravel's supported validation API and ordered behavior, while retaining Hypervel's O(n) wildcard expansion, worker-lifetime immutable plan cache, single compiled execution loop, inline predicates, exclusion prepass, and wildcard database-presence batching.

The finished code must be the simplest design that is correct under Hypervel's long-lived concurrent workers. It must add no external request/coroutine-context state, locks, worker-global mutable results, shadow validator, resumable executor, database-specific SQL, or maintenance registry of Laravel rule names. Execution-local facts may live only on the validator-owned verifier that is already installed for one `passes()` call.

## Scope and findings

- **15 — eager presence batching violates rule order:** raw wildcard values are queried before preceding rules can reject them. This can send invalid values to typed PostgreSQL columns, execute SQL for excluded/absent attributes, and bypass ordinary `exists` / `unique` skip behavior.
- **16 — bytewise precomputed presence results do not model database equality:** a case-insensitive database match can be treated as absent, allowing a duplicate through `unique`. Array-valued `exists` additionally needs database `DISTINCT` semantics rather than a count of distinct PHP strings.
- **17 — exclusion pre-evaluation ignores rule order:** an `exclude_if` / `exclude_unless` later in the rule list currently erases failures from earlier rules.
- **18 — exclusion wildcard substitution mistakes literal numeric path segments for wildcard captures.**
- **19 — delegated comparison rules leak transient numeric-message state into later inline size failures.**
- **20 — compiled plans retain unused fields and a duplicated implicit-rule registry.**
- **Additional verified defect — `date_format` uses loose numeric-string comparison:** padded formats such as `m` accept unpadded strings such as `'1'`. Laravel 13.x shares the bug, but its documented contract says the value must match the selected PHP format.
- **Additional verified defect — `json` throws on resource input:** both Hypervel and Laravel call `method_exists()` with a resource. Hypervel also carries a duplicate inline implementation that can drift from the delegated predicate.
- **Additional verified defect — closure-backed exclusion rules are parsed twice:** `RuleCompiler::compile()` parses every rule during its context scan and again during compilation, so `ExcludeIf` / `ExcludeUnless` conditions run twice and the first result is discarded.

Out of scope: unrelated validation behavior, new validation APIs, removing pipe-delimited rules, or reverting the compiled validator.

## Research and settled decisions

### Hypervel architecture remains the right base

The defects are optimizer-boundary mistakes, not flaws in the overall refactor. Current source has the intended shape:

1. `ValidationRuleParser` performs O(n) wildcard expansion and normalizes pipe and array syntax into ordered rule arrays.
2. `RuleCompiler` emits immutable `AttributePlan` instances containing inline or delegated checks.
3. `RulePlanCache` shares those plans between attributes and requests for the worker lifetime.
4. `PlanExecutor` owns the one real validation loop; delegated rules still call the established `validateAttribute()` path.
5. Exclusion and database batching are pre-execution optimizations guarded to the exact base `Validator` with no mutating extension surface.

Baseline focused tests are green: 264 tests / 604 assertions across the six optimizer/compiler test files, and 18 tests / 38 assertions in the existing database batching integration file. Previous representative benchmarks found material wins for nested and conditional validation and a smaller but real inline-execution win. The implementation must preserve those gains.

### Laravel API and reference behavior

Local references were checked at:

- `examples/laravel/framework`, branch `13.x`, commit `bd71b45fbb7e`;
- `examples/laravel/docs`, branch `13.x`, commit `8939b76399f8`.

Relevant conclusions:

- Laravel's current documentation presents rule arrays as the preferred form, but the framework still explicitly accepts and centrally parses string rules with `explode('|', $rule)`. The string form is not deprecated. Hypervel must keep both forms; neither finding is caused by pipe syntax because both forms have already become the same ordered rule array before compilation.
- Laravel validates rules in declaration order and skips `Exists` / `Unique` after any prior failure on the attribute. The batch planner must preserve that behavior rather than eagerly submitting every raw value.
- Laravel uses `getExplicitKeys()` and `replaceAsterisksInParameters()` for dependent wildcard fields. Hypervel should reuse that authority.
- Laravel's database `getMultiCount()` is `distinct()->count($column)`. PHP bytewise uniqueness is not an equivalent substitute under collations or database coercion.
- Laravel 13.x still uses loose comparison in `validateDateFormat()`. This is an upstream bug: PHP has separate padded and unpadded format tokens, and the docs say the value must match the requested format. Hypervel will fix the shared boundary and can offer the change upstream separately.
- Laravel 13.x shares the resource-unsafe `validateJson()` predicate. PHP 8 exposes `Stringable` for the supported object boundary, while resources are neither scalar nor `Stringable`; fix that predicate rather than guarding an optimizer around it.
- Laravel parses each rule once during ordinary execution. Hypervel's second parse is introduced solely by the compiled plan's context pre-scan and is unnecessary.

No Swoole defect was exposed by this investigation.

### Optimizer invariants

These invariants govern every change in this slice:

- Actual validation, messages, exclusion, bail, and stop behavior remain owned by the existing execution loop. Preflight is a side-effect-free predicate pass only.
- A batch may omit a concrete value only when shared execution gates or safely repeated preceding checks prove that its presence rule cannot execute.
- A value with any unsupported or unsafe preceding check is **uncertain**, not failed. It is not submitted eagerly; if normal execution later reaches presence validation, the execution-local verifier delegates that probe to the original verifier.
- One uncertain value must not disable batching for safe siblings. Declining an entire group would let one unusual value turn 999 safe values back into 1,000 queries.
- Preflight writes nothing to `AttributePlan`. The same plan instance can be shared by multiple wildcard attributes and concurrent requests.
- Inline preflight fails closed. A positive `CheckType` allowlist with `default => false` means a future inline rule is correct by default and merely forgoes batching until explicitly reviewed.
- Optimizer disqualification follows the ability to mutate this `Validator`'s data, not a wrapper interface that never reaches the wrapped rule.
- The precomputed verifier stores only facts a database query proved. Anything unqueried or ambiguous delegates.
- All verifier facts and fallback memoization live only for the current `passes()` execution. No `CoroutineContext`, static map, or worker cache is permitted.

## Implementation plan

### 1. Share the non-implicit execution gates

Files:

- `src/validation/src/PlanExecutor.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`

Extract two small protected predicates used by both normal inline execution and batch preflight:

```php
protected function shouldSkipNonImplicitCheck(
    AttributePlan $plan,
    mixed $value,
    bool $exists,
): bool {
    return ! $exists
        || (is_string($value) && trim($value) === '')
        || ($plan->nullable && $value === null);
}

protected function shouldFailInvalidUpload(string $attribute, mixed $value): bool
{
    return $value instanceof UploadedFile
        && ! $value->isValid()
        && $this->hasRule($attribute, array_merge($this->fileRules, $this->implicitRules));
}
```

Keep `addFailure($attribute, 'uploaded', [])` solely in the real executor. Preflight may use the exact predicate to prove that presence cannot run, but it must never create a message.

The file/implicit-rule condition is load-bearing. An invalid upload with only an `exists` rule does not take the uploaded-file failure branch and must remain capable of reaching the real presence verifier.

Replace the duplicated inline gates with these predicates and remove comments that describe the old inline-only implementation.

Tests:

- absent, trimmed-empty, and nullable-null values skip non-implicit inline and presence checks;
- an invalid upload with a file or implicit rule produces the existing `uploaded` failure and no database probe;
- an invalid upload without either condition is not falsely classified as a proven failure.

### 2. Add a conservative inline-preflight boundary

Files:

- `src/validation/src/PlanExecutor.php`
- `src/validation/src/Concerns/ValidatesAttributes.php`
- `src/validation/src/Enums/CheckType.php`
- `tests/Validation/ValidationPlanExecutorTest.php`
- `tests/Validation/ValidationValidatorTest.php`

Place `canPreflightInline(InlineCheck $check, mixed $value): bool` immediately beside `executeInline()` so the safety classification and implementation are reviewed together.

Reject all object and resource values first. Objects can invoke user magic methods, `Countable::count()`, overridable file methods, and configurable object behavior. Resources are not legitimate presence candidates. Do **not** reject arrays: array-valued `exists` is supported, and native array/type/size predicates are safe.

Repair JSON validation at its actual shared boundary before relying on that classification:

```php
if (! is_scalar($value) && ! $value instanceof Stringable) {
    return false;
}
```

PHP 8 automatically implements `Stringable` for classes declaring `__toString()`, while the check is safely false for a resource. Change the `Json` inline arm to call `$this->validateJson($attribute, $value)`, then delete the byte-identical `executeInlineJson()` helper and its unused `Json` import. This direct call has no parameter parsing, rule lookup, dispatch, or state overhead because `validateJson()` does not use the attribute. Do not generalize the pattern to inline rules whose delegated methods do more work.

Use a positive match with `default => false`. The 41 currently safe cases are:

```text
TypeString, TypeNumeric, TypeInteger, TypeIntegerStrict, TypeBoolean, TypeArray,
Email, Url, Ip, Ipv4, Ipv6, Uuid, Ulid, Json, Ascii, HexColor, MacAddress,
Alpha, AlphaAscii, AlphaDash, AlphaDashAscii, AlphaNum, AlphaNumAscii,
Lowercase, Uppercase,
SizeMin, SizeMax, SizeBetween, SizeExact,
Digits, DigitsBetween, MinDigits, MaxDigits,
StartsWith, EndsWith, DoesntStartWith, DoesntEndWith,
In, NotIn, IsDate, DateFormat
```

The size cases are safe only when they will not reach the user-configurable exponent guard:

```php
! (
    $check->param['mode'] === SizeMode::Numeric
    && is_numeric($value)
    && Str::contains((string) $value, 'e', ignoreCase: true)
)
```

Objects are already rejected, so this also avoids file stat calls and magic string casts. Arrays continue through native `count()`.

Leave these eight cases unlisted:

- `Regex`, `NotRegex`: malformed patterns can emit warnings;
- `MultipleOf`: Brick Math throws for values such as `INF` and `NAN`;
- `DateAfter`, `DateBefore`, `DateAfterOrEq`, `DateBeforeOrEq`, `DateEquals`: they can reach the configurable `DateFactory` callback.

`IsDate` and `DateFormat` are safe scalar/native predicates and do not use the `Date` facade. Bare `Email` is also allowed after the object guard; Hypervel auto-singletons the stateless Egulias validator, and a hypothetical stateful concrete rebinding is not a supported behavior worth turning common `email|exists` lists into N queries.

Add an optional fourth step to `CheckType`'s existing maintenance docblock: an inline case may be added to the preflight allowlist only after proving repeat evaluation is free of user callbacks, I/O, warnings, and reachable exceptions; omission is safe and only disables batching across that prefix. Do not add behavior or a second registry to the enum.

Tests:

- assert every allowed and disallowed case, the object/resource guard, array support, and the size exponent exception;
- prove a Stringable object is not cast during preflight;
- prove exponent callbacks and file methods execute only once and in normal order;
- prove resource-valued `json` fails rather than throwing in both base inline and all-delegated execution, while valid scalar and `Stringable` JSON retain their behavior;
- keep `required|integer|min:1|exists` batchable for ordinary scalar values;
- keep `required|array|exists` batchable.

### 3. Build presence candidates from active compiled plans in order

Files:

- `src/validation/src/Validator.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`
- validation database integration tests described in step 9

Rewrite the candidate half of `maybeBatchDatabaseChecks()` around the already filtered `compiledPlans`, not raw `$rules`:

1. Retain the current wildcard-only optimization boundary.
2. Skip plans whose `sometimes` flag is set when the concrete key is absent.
3. Locate each `Exists` / `Unique` `DelegatedCheck` in the concrete plan and extract metadata from its `originalRule`, preserving string, array, and rule-object forms.
4. Apply the shared non-implicit and invalid-upload predicates to the current value.
5. Walk only the checks preceding that presence check, in declaration order:
   - an `InlineCheck` may be evaluated only when `canPreflightInline()` returns true;
   - ordinary `Required` may call `validateRequired()` only for non-object values;
   - another `DelegatedCheck` makes this concrete value uncertain;
   - a safely evaluated false result proves failure and omits the value;
   - reaching the presence check after all safe passes makes the value batchable.
6. Preserve an active uncertain query shape for collision detection, but add no value for it. If all candidates are uncertain, no batch query or verifier swap occurs.

Conceptually there are three outcomes, but do not introduce an enum, result object, plan cursor, phased executor, or mutable plan field. A small private helper/local state is enough:

```text
proven failure or shared skip -> no group value; presence cannot run
fully safe prefix            -> group and submit value
uncertain prefix             -> retain active shape, do not submit; runtime fallback if reached
```

Critical examples:

- `multiple_of:5|exists` with `'abc'`: normal validation fails `multiple_of` and performs no SQL. Because `MultipleOf` is unsafe to preflight, the candidate is uncertain and must not be eagerly submitted.
- `min:1|integer|exists` with `'abc'`: numeric mode is compiled from the sibling `integer`, but value-first size dispatch treats `'abc'` as length 3, so `min` passes and `integer` fails. Stopping at the unsafe size rule and submitting the raw value would be wrong.
- one exponent-form, file, or custom-prefix value must not disable batching for safe siblings.

Building from `compiledPlans` automatically excludes plans removed by exclusion pre-evaluation. The plan-level `sometimes` gate prevents absent attributes from contributing values. Continue using the existing full-query-shape key and conservative table/column collision guard; a possibly executable uncertain shape must still prevent another shape on the same table/column from intercepting its runtime probe.

`stopOnFirstFailure` can still make an already-issued batch query unnecessary when an earlier attribute later fails. This is an existing consequence of pre-execution batching and does not justify stateful or phased machinery.

Tests:

- all-existing 1,000 `required|integer|exists` values issue one query; 1,001 issue two chunks;
- mixed valid/invalid integers submit only valid values, preserve ordered messages, and keep safe siblings batched;
- PostgreSQL `integer|exists` and `date|exists` / `date_format:Y-m-d|exists` reject invalid typed values without `QueryException` or presence SQL;
- a preceding safe failure, `bail`, nullable, empty, absent, `sometimes`, and pre-excluded attributes issue no inappropriate query;
- a preceding custom/delegated rule makes only that concrete value uncertain;
- an uncertain prefix that later fails performs no fallback; one that reaches presence performs one fallback;
- an all-uncertain group performs no batch query;
- two attributes sharing one cached `AttributePlan` can make different candidate decisions without cross-request/attribute state;
- string, array-tuple, `Exists`, and `Unique` object forms retain their metadata and messages.

### 4. Track the real optimizer mutation surface

Files:

- `src/validation/src/Validator.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`

Correct `compiledPlansContainValidatorAwareRules()` by unwrapping `InvokableValidationRule`:

```php
if ($check->ruleObject instanceof InvokableValidationRule) {
    if ($check->ruleObject->invokable() instanceof ValidatorAwareRule) {
        return true;
    }

    continue;
}
```

The wrapper always implements `ValidatorAwareRule`, but forwards the validator only when the inner rule implements it. A normal modern `ValidationRule` / `InvokableRule` cannot mutate this validator and must not globally disable exclusion or presence optimization.

Keep `ClosureValidationRule` as a blocker because it passes the live validator as the fourth callback argument. Keep an actual inner `ValidatorAwareRule` as a blocker. Regardless of the global decision, a custom rule preceding presence remains locally uncertain under step 3.

Tests:

- an unrelated plain modern validation rule does not prevent wildcard batching;
- an inner `ValidatorAwareRule` still disables precomputation;
- a closure rule still disables precomputation;
- mutation before presence retains ordinary execution semantics.

### 5. Make precomputed presence facts database-semantic

Files:

- `src/validation/src/DatabasePresenceVerifier.php`
- `src/validation/src/BatchDatabaseChecker.php`
- `src/validation/src/PrecomputedPresenceVerifier.php`
- `tests/Validation/ValidationDatabasePresenceVerifierTest.php`
- `tests/Validation/ValidationBatchDatabaseCheckerTest.php`
- `tests/Validation/ValidationPrecomputedPresenceVerifierTest.php`
- validation database integration tests described in step 9

#### 5.1 Query only normalizable candidates

Normalize each concrete candidate independently. Strings, integers, floats, `Stringable` values, and one-dimensional arrays containing only those types remain supported. Booleans, null, and other unsupported candidates are skipped without declining safe siblings. Unsupported runtime probes must delegate before consulting any stored fact.

Keep query binding semantics separate from lookup keys. Do not string-cast every submitted SQL value: retain the raw string, integer, or float query value so the connection performs the same driver-specific binding as the ordinary verifier; cast a supported `Stringable` once. Alongside it, build the same type-insensitive `(string)` lookup key that `PrecomputedPresenceVerifier` uses today. This prevents the batch path from changing a typed PostgreSQL probe merely to deduplicate it without assuming that PDO will return the same PHP type it received.

Do not batch booleans. `Connection::prepareBindings()` converts them to integers, while `PostgresConnection` with emulated prepares converts them to `'true'` / `'false'`, and returned column representations also vary by driver/PDO mode. Delegating this marginal presence-rule shape is simpler and guarantees parity with the real verifier. It also removes the existing `false` to `''` corruption without adding a two-representation boolean scheme.

Deduplicate candidates by that string key and retain the first raw value as the representative SQL binding. Use the identical key function for submitted candidates, runtime probes, and every value fetched by both query stages before comparing results or populating `exactHits`, `knownPresent`, and `provenAbsent`. Integer/float candidates may be returned as strings because of the column type, driver, or PDO options; equal string keys must remain fast-path hits rather than becoming ambiguous misses. Booleans are excluded before normalization, so `false` cannot collide with an empty string. Keep the representation as plain arrays/maps rather than introducing a value object.

Do not partially submit an array containing an unsupported nested item. Its eventual `getMultiCount()` must remain one coherent fallback.

#### 5.2 Use two grouped stages

For every collision-free query shape:

1. Query all distinct submitted representative values in chunks of 1,000.
2. Normalize every fetched value through the shared string-key function, then build the exact-key hit map and submitted-key misses.
3. If stage 1 fetched nothing, every submitted value is proven absent.
4. If the whole group has one distinct submitted value and stage 1 returned a nonexact representation, that sole value is already known present for scalar presence semantics; no isolation query is needed.
5. Otherwise, if stage 1 has hits and misses, query only the misses with the identical connection, table, column, where conditions, ignore value, ID column, write PDO, and chunking.
6. If stage 2 fetched nothing, every submitted miss is proven absent.
7. Every miss returned with the same normalized key by stage 2 is known present for scalar semantics, even when other misses remain ambiguous. Keep these in the scalar-only `knownPresent` map rather than merging facts from two queries into the stage-1 exact map.
8. If stage 2 is non-empty and there was exactly one distinct miss, that isolated miss is known present even when the stored representation differs.
9. Other misses from a non-empty multi-miss stage remain unresolved and each runtime scalar probe delegates, memoized per normalized value for this execution.

This uses the database itself to isolate collation/coercion matches. It avoids both an attacker-controlled one-query-per-ordinary-miss regression and non-portable collation emulation. Do not add recursive partitioning, thresholds, derived tables, `CASE`, or driver-specific equality logic.

Store three plain maps per lookup:

```text
exactHits       stage 1 returned this exact normalized string key
knownPresent    a single-input query or stage 2 proved a scalar miss is present
provenAbsent    a stage returned no row for this submitted value
```

Anything in no map was unqueried or ambiguous and delegates. Cache only actual fallback scalar counts inside this verifier instance. Return the real cached integer count, not a synthesized boolean.

Expected scalar query costs:

- all exact-existing: one grouped pass (one query per 1,000 values);
- all absent / all-new unique: one grouped pass;
- mixed exact hits and true misses: two grouped passes;
- a standalone representation mismatch: one grouped pass; a mismatch mixed with exact hits: two grouped passes;
- multiple ambiguous representation mismatches: two grouped passes plus one fallback per distinct unresolved scalar value.

The extra stage creates no new consistency model: Laravel's unbatched probes already occur at different instants, and both grouped stages use the write PDO.

#### 5.3 Preserve `getMultiCount()` database DISTINCT semantics

Change `getExistingValues()` to select distinct column values and update its docblock to promise distinct stored values. This reduces duplicate transfer and makes one-chunk exact-hit facts usable for array-valued `exists` under the same database equality semantics as `distinct()->count($column)`.

On a case-insensitive or otherwise normalizing column, SQL `DISTINCT` can collapse multiple stored string representations to one representative. A requested value can therefore have a row with the same normalized string key in the table without that representation being returned by stage 1; here, an "exact hit" means only that stage 1 returned the submitted string key as its distinct representative. The omitted submitted key enters the miss set and may require stage 2. This is why stage-2 key hits can establish scalar facts, and why the second grouped pass is expected more often on columns containing equivalent representations.

There is one additional chunk boundary: SQL `DISTINCT` is authoritative within a query, not across independently chunked queries. Record whether stage 1 fit in one chunk. After normalizing and deduplicating the requested array, `getMultiCount()` follows these rules:

- any unknown value delegates the whole multi-count;
- a `knownPresent` value is usable only when it is the array's sole distinct input; otherwise delegate because it may map to the same stored value as another input;
- proven-absent values contribute zero;
- exact hits can be counted only when stage 1 fit in one distinct query; otherwise delegate to avoid double-counting database-equivalent representations returned from separate chunks.

Otherwise delegate the whole multi-count. In particular, a stage-2-known-present value cannot be counted as a separate stored value: under a case-insensitive collation, inputs `['foo', 'Foo']` can both match one stored distinct value and must yield count 1, not 2.

This small boolean is necessary; without it, database-equivalent exact representations returned from separate chunks could be double-counted. It is preferable to always delegating arrays, which would discard the existing wildcard array batching feature.

Tests:

- exact, absent, known-present, unresolved, unsupported, and unregistered scalar paths;
- boolean false/true candidates are never submitted to a batch and fall back only if normal execution reaches their presence rule, without disabling safe siblings;
- fallback count memoization is execution-local and keyed by distinct normalized scalar probe;
- all-new unique values do not produce N fallbacks;
- a case-insensitive differently-cased duplicate fails `unique`;
- exists honors case, accent, trailing-space, and numeric coercion according to each real driver rather than PHP guesses;
- a mixed exact/true-miss group uses exactly two grouped passes;
- an ambiguous multi-miss group falls back only for unresolved distinct scalar probes;
- `getMultiCount()` delegates for unknown, multi-input `knownPresent`, and cross-chunk-unsafe facts while retaining the safe single-input cases;
- stage-2 exact-key hits in a multi-miss group become scalar-known facts without contaminating stage-1 multi-count facts;
- canonical exact array values still use the precomputed distinct result;
- a MySQL/MariaDB case-insensitive table containing collation-equivalent representations matches the real verifier's distinct count;
- mocked and real-driver integer/float candidates remain precomputed hits without fallback queries when PDO returns the same values as strings, including integer candidates against text columns and numeric/decimal column results;
- a distinct representative can omit a requested string representation from stage 1 without producing an incorrect scalar or multi-count fact;
- duplicate input elements preserve `count(array_unique($value))` / database distinct behavior;
- verifier restoration after success and exception remains covered.

### 6. Restore exclusion order and wildcard authority

Files:

- `src/validation/src/Validator.php`
- `tests/Validation/ValidationPreEvaluatedExclusionsTest.php`

Drive `preEvaluateExclusions()` from `compiledPlans`. Only pre-evaluate when `checks[0]` is a delegated `ExcludeIf` or `ExcludeUnless`. `nullable`, `bail`, and `sometimes` are plan flags rather than executable checks, so they do not occupy position zero.

Use the check's already parsed parameters. Apply the same dependent-field normalization as `validateAttribute()`:

```php
$parameters = $this->replaceDotInParameters($check->parameters);

if ($keys = $this->getExplicitKeys($attribute)) {
    $parameters = $this->replaceAsterisksInParameters($parameters, $keys);
}
```

Retain the existing safety skips for boolean/null-dependent coercion and non-scalar condition values. Delete `parseExcludeRule()` and the numeric-segment regex `resolveWildcardConditionField()` once they have no caller.

Tests:

- `integer|exclude_if:foo,bar` retains the integer error even though the attribute is later excluded;
- exclusion-first still removes data before execution;
- `bail`, `nullable`, and `sometimes` flags before exclusion do not prevent the fast path;
- literal numeric segment case: `data.5.items.0.value` resolves `data.5.items.*.type` with capture `0`, not literal segment `5`;
- one/multiple wildcard captures, mismatched counts, nested arrays, and escaped-dot field names match normal dependent-rule execution;
- parent pre-exclusion still suppresses descendants.

### 7. Reset transient numeric state, parse once, and remove dead plan metadata

Files:

- `src/validation/src/PlanExecutor.php`
- `src/validation/src/AttributePlan.php`
- `src/validation/src/RuleCompiler.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`
- `tests/Validation/ValidationRuleCompilerTest.php`

Immediately before every real inline check, assign:

```php
$this->numericRules = $this->defaultNumericRules;
```

Delegated checks already reset inside `validateAttribute()`. Do not add a dirty bit or conditional reset; assigning a three-element array is a cheap refcount operation, and extra state/branching would be worse.

Regression: data `['field' => '123456', 'other' => 2]` with `string|gt:other|max:5`. `Gt` temporarily adds itself to `numericRules`; inline `max` must select `validation.max.string`, not `validation.max.numeric`.

In `RuleCompiler::compile()`, parse each input rule exactly once into a temporary list of parsed name/parameter pairs. Pass those pairs to `collectContext()` and pass the corresponding pair into `compileRule()` rather than calling `ValidationRuleParser::parse()` again. Do not cache object parse results beyond this compile: `ExcludeIf` and `ExcludeUnless` deliberately evaluate their closures while stringifying, so a worker-global object cache would be incorrect. This removes duplicate parsing work and ensures a closure-backed exclusion condition is evaluated once per compile, with the result that is actually compiled.

Keep the data flow explicit and local:

```php
$parsedRules = array_map(
    static fn (mixed $rule): array => ValidationRuleParser::parse($rule),
    $rules,
);
$context = self::collectContext($parsedRules);

foreach ($rules as $index => $rule) {
    self::compileRule($rule, $parsedRules[$index], $plan, $context);
}
```

Change `collectContext()` to consume parsed pairs and `compileRule()` to accept its pair. The temporary list is linear in one attribute's rule count, exists only during a cache miss, and replaces repeated parsing; it adds no worker-lifetime or per-validation execution state.

The parsed pair's first element is `mixed`, not always a rule-name string: `ValidationRuleParser::parse()` returns the original object for a `RuleContract`. Keep `compileRule()`'s `RuleContract` branch ahead of any pair-consuming path, keep `collectContext()`'s `is_string($parsedName)` guard, and consume the parsed name/parameters only for the remaining string, array, and `Stringable` forms, including `Exists` / `Unique`.

`compileAllDelegated()` does not need parsed context after the dead size-mode metadata is removed. Let `compileRuleDelegated()` continue parsing each rule once as it emits the delegated check; do not add a second generalized compilation abstraction solely to share a short control flow.

Then remove from `AttributePlan`:

- `$required`;
- `$hasImplicitRule`;
- `$sizeMode` and its `SizeMode` import.

Remove every compiler write and delete `RuleCompiler::isImplicitRule()` with its duplicate list. Keep size mode only in `compile()`'s local context and in the `InlineCheck` parameters that consume it. `compileAllDelegated()` no longer needs the context pre-scan, which also removes needless work for validator subclasses.

Strengthen `AttributePlan`'s existing immutability documentation: no execution or optimizer state may be attached because cached plans are shared across attributes, requests, and concurrent coroutines.

Replace compiler tests that pin dead fields with behavior or consumed-output assertions:

- `Required` and implicit rules still execute on absent/empty attributes;
- nullable/bail/sometimes flags remain;
- size comparison and message mode come from the emitted inline check;
- closure-backed `ExcludeIf` and `ExcludeUnless` conditions are invoked exactly once per `compile()` call, and their single result determines the emitted check;
- all-delegated subclass plans retain correct ordered behavior without stored context.

### 8. Fix strict `date_format` round trips

Files:

- `src/validation/src/Concerns/ValidatesAttributes.php`
- `tests/Validation/ValidationValidatorTest.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`

Normalize once and compare strictly in the existing shared helper:

```php
$stringValue = (string) $value;
$date = DateTime::createFromFormat('!' . $format, $stringValue, new DateTimeZone('UTC'));

return $date !== false && $date->format($format) === $stringValue;
```

Keep the current type guard and `ValueError` handling. Both delegated `validateDateFormat()` and inline `DateFormat` already call this helper, so there is one behavior change site. Do not alter `before`, `after`, or other date comparison arms; they use a different parsing boundary.

The change only affects noncanonical numeric-string round trips for all-numeric formats. Existing canonical/composite cases remain unchanged.

Tests in both base and all-delegated execution paths:

- `m` rejects `'1'` and accepts `'01'`;
- `Y` rejects `'24'` and accepts `'0024'`;
- canonical `Ymd` remains valid;
- numeric input follows its normalized string form, including a canonical `U` value;
- malformed formats remain false without leaking `ValueError`.

Mark this as an upstream Laravel issue/PR candidate after the Hypervel fix; do not make upstream coordination part of this implementation slice.

### 9. Put database validation coverage on the existing matrix

Files:

- move `tests/Integration/Validation/ValidationBatchDatabaseCheckerTest.php` to `tests/Integration/Validation/Database/ValidationBatchDatabaseCheckerTestCase.php` and make it abstract;
- add these exact thin concrete wrappers:
  - `tests/Integration/Validation/Database/MySql/ValidationBatchDatabaseCheckerTest.php` in namespace `Hypervel\Tests\Integration\Validation\Database\MySql` with `#[RequiresDatabase('mysql')]`;
  - `tests/Integration/Validation/Database/MariaDb/ValidationBatchDatabaseCheckerTest.php` in namespace `Hypervel\Tests\Integration\Validation\Database\MariaDb` with `#[RequiresDatabase('mariadb')]`;
  - `tests/Integration/Validation/Database/Postgres/ValidationBatchDatabaseCheckerTest.php` in namespace `Hypervel\Tests\Integration\Validation\Database\Postgres` with `#[RequiresDatabase('pgsql')]`;
  - `tests/Integration/Validation/Database/Sqlite/ValidationBatchDatabaseCheckerTest.php` in namespace `Hypervel\Tests\Integration\Validation\Database\Sqlite` with `#[RequiresDatabase('sqlite')]`.

Each wrapper has the established empty inherited-suite shape:

```php
#[RequiresDatabase('driver')]
class ValidationBatchDatabaseCheckerTest extends ValidationBatchDatabaseCheckerTestCase
{
}
```

This is the same inherited-test pattern used by RateLimiter, NestedSet, Passkeys, and Session. Shared validation test bodies are written once in the abstract case; the four wrappers contain no duplicated tests and exist only for database-workflow discovery.

Keep driver-neutral correctness and query-count tests in the shared abstract case. Use method-level `#[RequiresDatabase(['mysql', 'mariadb'])]` for case-insensitive collation cases and `#[RequiresDatabase('pgsql')]` for typed-column safety cases. Do not move validation tests into `tests/Integration/Database` and do not duplicate the suite per driver.

Extend the shared fixture with a non-unique text column for `getMultiCount()` collation tests; the existing unique email column cannot hold two database-equivalent representations on MySQL/MariaDB. Keep schema creation and all shared assertions in the abstract case.

Update `tests/Validation/ValidationDatabasePresenceVerifierTest.php::testGetExistingValuesUsesRequestedConnectionAndQueryShape()` to expect the fluent `distinct()` call before `pluck()`, rename it to state the distinct return contract, and preserve an exact result assertion. The real database matrix must insert duplicate/equivalent stored values and verify that `getExistingValues()` returns one value per database-distinct equivalence class; do not merely relax the existing mock assertion.

The current `bin/run-database-tests.sh` already discovers `tests/Integration/Validation/Database/<DriverDirectory>`; no workflow or runner edit is needed.

## Test and verification sequence

Implement each section with its focused tests, running the touched file immediately. At coherent checkpoints run:

```bash
vendor/bin/phpunit tests/Validation/ValidationPlanExecutorTest.php \
  tests/Validation/ValidationCompiledExecutionTest.php \
  tests/Validation/ValidationBatchDatabaseCheckerTest.php \
  tests/Validation/ValidationPrecomputedPresenceVerifierTest.php \
  tests/Validation/ValidationDatabasePresenceVerifierTest.php \
  tests/Validation/ValidationPreEvaluatedExclusionsTest.php \
  tests/Validation/ValidationRuleCompilerTest.php

vendor/bin/phpunit tests/Validation

bin/run-database-tests.sh sqlite --filter=ValidationBatchDatabaseCheckerTest
bin/run-database-tests.sh mysql --filter=ValidationBatchDatabaseCheckerTest
bin/run-database-tests.sh mariadb --filter=ValidationBatchDatabaseCheckerTest
bin/run-database-tests.sh pgsql --filter=ValidationBatchDatabaseCheckerTest
```

Run the existing benchmark before source changes and after the final implementation, using multiple runs rather than one noisy sample:

```bash
php src/testbench/bin/testbench validation:benchmark --scenarios=all --iterations=15
```

Compare three runs by median. Investigate any repeatable optimized-path regression above normal measurement noise, especially the flat and simple cases affected by the inline numeric-state reset. Presence performance is pinned primarily by query counts because avoided database round trips dominate predicate CPU.

Final verification:

```bash
composer fix
```

Do not weaken assertions to accommodate the implementation. Any failure must be traced to the shared gate, ordered planner, database fact model, or intended strict date-format correction.

## Acceptance checklist

- [ ] Laravel rule syntax, public APIs, rule/message order, and extension points remain compatible except for the verified upstream `date_format` and resource-valued `json` bug fixes.
- [ ] Pipe-delimited and array rule forms compile to the same correct behavior.
- [ ] O(n) wildcard expansion, immutable worker-lifetime plan caching, and the single execution loop remain intact.
- [ ] Common `required|integer|exists`, `email|exists`, `date|exists`, and `required|array|exists` wildcard shapes remain batched.
- [ ] No invalid value is submitted merely because preflight could not prove its prefix; uncertain probes fall back only if execution reaches presence.
- [ ] Boolean presence candidates use the ordinary verifier path; driver-specific binding is never approximated by the batch optimizer.
- [ ] SQL bindings retain their raw supported types while candidates, runtime probes, and fetched values share one PDO-type-insensitive string lookup key.
- [ ] Pre-excluded, absent-sometimes, empty, nullable, and proven-failing values issue no presence query.
- [ ] Case-insensitive/collation-equivalent `unique` values cannot false-pass.
- [ ] Array-valued `exists` agrees with database `DISTINCT` semantics, including chunk boundaries.
- [ ] No optimizer result is stored in a shared plan, static property, or coroutine context.
- [ ] Exclusion pre-evaluation preserves earlier failures and resolves wildcard captures through the established authority.
- [ ] Inline messages cannot inherit transient numeric state.
- [ ] Resource-valued JSON fails cleanly in inline and delegated execution, with no duplicate JSON predicate.
- [ ] Closure-backed exclusion rules are parsed/evaluated once per compile.
- [ ] Dead plan fields, compiler writes, duplicate implicit-rule knowledge, and obsolete helpers/tests/comments are removed.
- [ ] Every retained source comment and docblock describes the final design; no superseded optimizer explanation remains.
- [ ] The validation database suite runs through the existing MySQL, MariaDB, PostgreSQL, and SQLite workflow discovery.
- [ ] Focused, full validation, database-matrix, benchmark, static-analysis, formatting, and final repository checks pass.
