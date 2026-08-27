# Validation audit remediation plan

Status: Complete

Branch: `audit/validation-remediation` from `0.4`

Scope: master-audit findings 15–20 plus validation defects exposed while reviewing their shared parsing, compilation, execution, and batching boundaries

## Goal

Fix the validation optimizer's correctness gaps without giving back the architecture's principal performance gains. Preserve Laravel's supported validation API and ordered behavior, while retaining Hypervel's O(n) wildcard expansion, worker-lifetime immutable plan cache, branch-free exact-base execution loop, inline predicates, exclusion prepass, and wildcard database-presence batching. Validator subclasses retain a small Laravel-shaped delegated loop so extension behavior never depends on base-class optimizer assumptions. Restore upstream-safe rule-object canonicalization so modern fluent rules benefit from the same compiled path instead of becoming a second, slower architecture.

The finished code must be the simplest design that is correct under Hypervel's long-lived concurrent workers. It must add no external request/coroutine-context state, locks, worker-global mutable results, shadow validator, resumable executor, database-specific SQL, or duplicate maintenance registry of Laravel rule names. Execution-local facts may live only on the validator and the verifier already installed for one `passes()` call.

## Scope and findings

- **15 — eager presence batching violates rule order:** raw wildcard values are queried before preceding rules can reject them. This can send invalid values to typed PostgreSQL columns, execute SQL for excluded/absent attributes, and bypass ordinary `exists` / `unique` skip behavior.
- **16 — bytewise precomputed presence results do not model database equality:** a case-insensitive database match can be treated as absent, allowing a duplicate through `unique`. Array-valued `exists` additionally needs database `DISTINCT` semantics rather than a count of distinct PHP strings.
- **17 — exclusion pre-evaluation ignores rule order:** an `exclude_if` / `exclude_unless` later in the rule list currently erases failures from earlier rules.
- **18 — exclusion wildcard substitution mistakes literal numeric path segments for wildcard captures.**
- **19 — delegated comparison rules leak transient numeric-message state into later inline size failures.**
- **20 — compiled plans retain unused fields and a duplicated implicit-rule registry.**
- **Additional verified defect — `date_format` uses loose numeric-string comparison:** padded formats such as `m` accept unpadded strings such as `'1'`. Laravel 13.x shares the bug, but its documented contract says the value must match the selected PHP format.
- **Additional verified defect — `json` throws on resource input:** both Hypervel and Laravel call `method_exists()` with a resource. Hypervel also carries a duplicate inline implementation that can drift from the delegated predicate.
- **Additional verified defect — safe fluent rules miss canonicalization, caching, and inlining:** Hypervel's `ValidationRuleParser::prepareRule()` returns ordinary `Stringable` rule objects where Laravel returns their canonical string. Common modern forms such as `Rule::in()` therefore remain uncacheable and delegated, and Hypervel-only presence-object machinery compensates for a one-line upstream omission.
- **Additional verified defect — falsey database-rule values serialize incorrectly:** `Unique::ignore(0)` / `ignore('0')` become the no-ignore `NULL` sentinel, while `DatabaseRule::where(..., false)` and `whereNot(..., false)` become empty-string constraints that PostgreSQL rejects for typed columns. The current object-metadata batch path happens to preserve an ignored zero while ordinary validation does not, so deleting that workaround requires fixing the owning serializers in the same change.
- **Additional verified defect — common typeless size rules are needlessly delegated:** `max`, `min`, `size`, and `between` inline only when a sibling type rule selects one of four modes. Laravel's actual rule is simpler: only numeric semantics come from sibling rules; arrays, files, and strings are selected from the runtime value. The duplicate compiler mapping also omits `decimal`.
- **Additional verified defect — compiled stop checks reparse rules after failures:** the plan already owns `bail`, and uploaded/implicit failures already exist in `failedRules`, but the compiled loop calls `shouldStopValidating()` and rescans the attribute's rules after every failure.
- **Additional verified defect — global early-stop can be preempted by speculative presence SQL:** `stopOnFirstFailure()` can make ordinary execution return after an earlier failure without reaching a later presence rule, while eager batching submits that later value first. A PostgreSQL type error then replaces the clean validation failure and aborts any surrounding transaction.
- **Additional verified defect — presence facts erase PDO binding identity:** string `'1'` and integer `1` currently collapse to one candidate and fact key even though the connection binds them as `PDO::PARAM_STR` and `PDO::PARAM_INT`. PostgreSQL can hide the integer binding error when the string wins deduplication. MySQL silently gives both candidates the string result or both the integer result against stored `'01'`, producing order-dependent false failures and false passes.
- **Implementation-review defect — date/time candidates bypass grammar-owned binding conversion:** batching string-casts `Stringable` date objects, while ordinary validation formats every `DateTimeInterface` through `Connection::prepareBindings()`. The same value can therefore query with different strings and silently produce different results.
- **Implementation-review defect — resolved leading exclusions block their own presence batch:** the exclusion prepass proves a first-position exclusion is non-excluding, but the prefix walk still treats that delegated check as uncertain and turns a common wildcard form back into one query per item.
- **Post-implementation audit defect — validator subclasses lose Laravel extension hooks:** all-delegated plans discard `nullable`, `bail`, and `sometimes` as executable rules, apply the base-class `sometimes` shortcut, and bypass overridden `shouldStopValidating()` methods.
- **Post-implementation audit defect — escaped-dot attributes cross internal and public key domains:** prior presence failures are queried with placeholder-containing keys while exclusions are recorded with cleaned keys, causing extra presence queries, later-rule execution, and retained excluded data.
- **Post-implementation audit defect — presence batching invokes arbitrary `Stringable` code early:** candidate and condition normalization executes user code during preflight and again at runtime rather than preserving ordinary rule order.
- **Post-implementation audit defect — non-scalar array-tuple parameters enter compilation:** compiler context and inline checks can invoke user objects before bail or exclusion would reach the rule.
- **Post-implementation audit defect — exclusion hints are applied outside Laravel attribute order:** future parent exclusions suppress earlier descendants, global early-stop still applies later hints, stale original-data outcomes survive descendant removal, and execution-time parent exclusions fail to skip later descendants.
- **Post-implementation audit improvement — validators without exclusions scan every plan for mutators:** the mutation gate is useful only after an exclusion is found and should be resolved lazily.
- **Post-implementation audit performance defect — active exclusions remain quadratic:** Laravel's protected exclusion list is scanned at every attribute/check boundary and deduplicated after every activation. Large wildcard exclusion sets therefore retain O(e²) work after the ordered prepass.
- **Final-review defect — absent `sometimes` attributes skip later exclusions:** the exact-base loop's plan-level shortcut bypasses exclusions even though Laravel deliberately runs them before its optional-presence gate, allowing descendant rules to execute after their absent parent should have been excluded.
- **Final-review performance defect — non-presence wildcards pay presence-planner work:** the planner reads values and walks exclusion sets for every expanded wildcard attribute before discovering that its plan has no `exists` / `unique` check.

## Research and settled decisions

### Hypervel architecture remains the right base

The defects are optimizer-boundary mistakes, not flaws in the overall refactor. Current source has the intended shape:

1. `ValidationRuleParser` performs O(n) wildcard expansion and normalizes pipe and array syntax into ordered rule arrays.
2. `RuleCompiler` emits immutable `AttributePlan` instances containing inline or delegated checks.
3. `RulePlanCache` shares those plans between attributes and requests for the worker lifetime.
4. `PlanExecutor` owns the branch-free exact-base loop and a small Laravel-shaped subclass loop; every delegated rule still calls the established `validateAttribute()` path.
5. Exclusion and database batching are pre-execution optimizations guarded to the exact base `Validator` with no mutating extension surface.

Baseline focused and database-batching integration tests are green. Previous representative benchmarks found material wins for nested and conditional validation and a smaller but real inline-execution win. The implementation must preserve those gains.

### Laravel API and reference behavior

Local references were checked at:

- `examples/laravel/framework`, branch `13.x`, commit `bd71b45fbb7e`;
- `examples/laravel/docs`, branch `13.x`, commit `8939b76399f8`.

Relevant conclusions:

- Laravel's current documentation presents rule arrays as the preferred form, but the framework still explicitly accepts and centrally parses string rules with `explode('|', $rule)`. The string form is not deprecated. Hypervel must keep both forms; neither finding is caused by pipe syntax because both forms have already become the same ordered rule array before compilation.
- Laravel calls `validateAttribute()` for every declared subclass rule, checks active exclusions at each attribute entry, and dynamically calls protected `shouldStopValidating()` after each rule. Hypervel may optimize the exact base class, but its subclass path must retain that extension behavior.
- Laravel validates rules in declaration order and skips `Exists` / `Unique` after any prior failure on the attribute. The batch planner must preserve that behavior rather than eagerly submitting every raw value.
- Laravel's `ValidationRuleParser::prepareRule()` preserves closures, `RuleContract` instances, callback-bearing `Exists` / `Unique`, and `CompilableRules`, then stringifies every other object. Hypervel is missing only the final `(string)` cast. Restoring it is the generic parity fix: it evaluates conditional fluent rules once at parse time, makes pure fluent rules cacheable, and avoids a brittle class allowlist.
- Callback-free `Exists` / `Unique` strings contain all metadata used by ordinary validation and batching. Callback-bearing objects remain objects and delegated. Hypervel's internal `DatabasePresenceRule`, `presenceMetadata()`, and special compiler/planner branches become dead after upstream canonicalization and should be removed.
- Laravel's fluent `Unique` serializer also shares a truthiness bug: zero-valued ignored IDs become `NULL`. The matching database-rule serializer loses `false` conditions as an empty string. `null` is the only no-ignore sentinel; normalize boolean where values to integers before formatting, matching ordinary query-builder binding across supported drivers.
- Laravel's `getSize()` asks one semantic question of the sibling rules: whether numeric semantics are active. It then dispatches on the actual value for array count, file kilobytes, or string length. Hypervel's four-way `SizeMode` duplicates rule categories, fails to inline common `required|max:255`, and misses `Decimal` despite `Validator::$defaultNumericRules` already being the authority.
- Laravel uses `getExplicitKeys()` and `replaceAsterisksInParameters()` for dependent wildcard fields. Hypervel should reuse that authority.
- Laravel's database `getMultiCount()` is `distinct()->count($column)`. PHP bytewise uniqueness is not an equivalent substitute under collations or database coercion.
- Laravel 13.x still uses loose comparison in `validateDateFormat()`. This is an upstream bug: PHP has separate padded and unpadded format tokens, and the docs say the value must match the requested format. Hypervel will fix the shared boundary and can offer the change upstream separately.
- Laravel 13.x shares the resource-unsafe `validateJson()` predicate. PHP 8 exposes `Stringable` for the supported object boundary, while resources are neither scalar nor `Stringable`; fix that predicate rather than guarding an optimizer around it.
- Laravel parses safe fluent conditions once while exploding rules. Hypervel must restore that timing. The compiler still has a context pre-scan and emission pass, so one compile-local list of parsed pairs should feed both passes rather than parsing tokens twice on every cache miss.

No Swoole defect was exposed by this investigation.

### Optimizer invariants

These invariants govern every change in this slice:

- Actual validation, messages, exclusion, bail, and stop behavior remain owned by the existing execution loop. Preflight is a side-effect-free predicate pass only.
- A batch may omit a concrete value only when shared execution gates or safely repeated preceding checks prove that its presence rule cannot execute.
- A value with any unsupported or unsafe preceding check is **uncertain**, not failed. It is not submitted eagerly; if normal execution later reaches presence validation, the execution-local verifier delegates that probe to the original verifier.
- One uncertain value must not disable batching for safe siblings. Declining an entire group would let one unusual value turn 999 safe values back into 1,000 queries.
- Preflight writes nothing to `AttributePlan`. The same plan instance can be shared by multiple wildcard attributes and concurrent requests.
- Inline preflight fails closed. A positive `CheckType` allowlist with `default => false` means a future inline rule is correct by default and merely forgoes batching until explicitly reviewed.
- The allowlist is safety metadata, not a second rule registry. An exhaustive test must partition every `CheckType` into reviewed-safe or reviewed-unsafe cases so adding an enum case forces an explicit decision.
- Exclusion pre-evaluation is disabled only by mutation-capable behavior actually used by the compiled plans, not by an unused registered extension or a wrapper interface that never reaches its wrapped rule.
- Presence facts are keyed by database query shape and PDO binding identity, not by attribute. Request-data mutation cannot stale them: a new binding identity is unknown and delegates; an identity queried elsewhere retains a database-proven fact. Presence batching therefore does not share the exclusion prepass's data-mutation gate.
- Query-shape identity is enforced by the precomputed verifier itself, not by a separate table/column collision census. A runtime probe can consume facts only when its connection, table, column, scalar where conditions, and effective unique exclusion exactly match the grouped query.
- Validation predicates may read the database, but must not write database state and depend on later presence-query ordering. Detecting arbitrary database side effects in user code is impossible, and globally disabling batching for every custom rule would destroy the optimization without providing a coherent guarantee.
- The precomputed verifier stores only facts a database query proved. Anything unqueried or ambiguous delegates.
- Presence batching and fact keys accept only native strings, integers, and floats. Objects and other values capable of user code or connection-owned conversion remain on the ordinary verifier path.
- Compiler-owned inline checks contain scalar parameters only. Any attribute containing a rule with a non-scalar parsed parameter uses the all-delegated path; correctness therefore does not depend on a second per-rule parameter-safety registry.
- The exact base validator owns the optimized execution loop. Validator subclasses use a separate Laravel-shaped loop over delegated checks so protected and public extension hooks cannot be bypassed by base-class shortcuts.
- Pre-evaluated exclusions are ordered hints, not globally active state. A resolved exclusion becomes active only when execution reaches its exact attribute, and only exclusions actually reached by execution affect final data removal.
- All verifier facts and fallback memoization live only for the current `passes()` execution. No `CoroutineContext`, static map, or worker cache is permitted.
- `executeCompiledPlans()` iterates the compiled-plan array captured at the start of the call. A rule may mutate validation data, but it cannot introduce an uncatalogued query shape into that execution; rules added during execution take effect only on a later `passes()` compilation.

## Implementation plan

### 1. Restore Laravel's generic rule-object canonicalization

Files:

- `src/validation/src/ValidationRuleParser.php`
- `src/validation/src/Contracts/DatabasePresenceRule.php`
- `src/validation/src/Rules/DatabaseRule.php`
- `src/validation/src/Rules/Exists.php`
- `src/validation/src/Rules/Unique.php`
- `src/validation/src/DelegatedCheck.php`
- `src/validation/src/RuleCompiler.php`
- `src/validation/src/Validator.php`
- `tests/Validation/ValidationRuleParserTest.php`
- `tests/Validation/ValidationRulePlanCacheTest.php`
- `tests/Validation/ValidationRuleCompilerTest.php`
- presence tests named below

Restore the one missing upstream line at the end of `ValidationRuleParser::prepareRule()`:

```php
return (string) $rule;
```

Keep the preceding upstream guards exactly as the semantic boundary:

- non-objects and `RuleContract` instances remain unchanged;
- closures and modern `ValidationRule` / `InvokableRule` objects remain wrapped rule contracts;
- callback-bearing `Exists` / `Unique` remain objects so their query callbacks survive;
- `CompilableRules` still compile against the current attribute and data;
- every other object is a pure Laravel-style string rule and is canonicalized once during rule explosion.

Do not replace this with an `In` / `NotIn` / `Dimensions` / presence-rule class list. The generic upstream boundary already handles future stringable rules without another registry. It also means `Rule::requiredIf()`, `Rule::excludeIf()`, and `Rule::excludeUnless()` evaluate their closure once during parsing, exactly when Laravel does, rather than entering the compiler as stateful objects.

After canonicalization, remove the compensating Hypervel-only presence-object layer:

- delete the internal `Contracts\DatabasePresenceRule` interface;
- remove `implements DatabasePresenceRule` and its imports from `Exists` / `Unique`;
- delete `DatabaseRule::presenceMetadata()` and `Unique::presenceMetadata()`;
- delete the special `Exists` / `Unique` branches in `RuleCompiler`; callback-bearing objects can use the ordinary non-string delegated branch while retaining the object in `originalRule`;
- delete `DelegatedCheck::$ruleObject`; `originalRule` already carries the same custom `RuleContract` object used by execution and mutation analysis, so retaining both references has no consumer;
- make presence metadata extraction consume each `DelegatedCheck`'s parsed `ruleName` / `parameters`. Use `originalRule` only to reject callback-bearing `Exists` / `Unique`; do not stringify or parse the same check again;
- delete `extractObjectPresenceRuleMeta()` and all `presenceMetadata()` branches after confirming no caller remains.

Fix the database-rule serializers at their owning boundary before removing that layer:

- serialize an ignored ID whenever it is non-null, so integer zero, string zero, and float zero become `"0"` while `null` remains the no-ignore `NULL` sentinel;
- normalize boolean values to integers in one small helper used by both `DatabaseRule::where()` and `whereNot()`, so `false` serializes as `"0"` / `"!0"` rather than an empty string. Keep `enum_value()` normalization in that helper and add no extra sentinels or guards for unsupported array/object key values.

Callback-free `Rule::exists()` / `Rule::unique()` now follow the same string path as Laravel, including inferred columns. Callback-bearing forms remain uncacheable, are rejected from batching via `queryCallbacks()`, and execute through the ordinary verifier with their original object as `currentRule`. This preserves the public fluent API while deleting internal machinery.

Tests:

- safe fluent `In`, `NotIn`, `Dimensions`, `Exists`, and `Unique` objects appear in `getRules()` as the same canonical strings Laravel produces;
- `Rule::in()` / `Rule::notIn()` compile to inline checks and attributes containing them hit `RulePlanCache` on later validators;
- callback-free presence objects are cacheable and batchable, including inferred-column behavior matching the ordinary string path;
- callback-bearing presence objects remain objects, retain callbacks, never enter a batch, and produce no runtime lookup key so they always delegate;
- integer zero, string zero, and float zero ignored IDs canonicalize as `"0"`, reach `getCount()` as a non-null exclusion, and work through ordinary, fallback, and batched wildcard paths; `ignore(null)` and an unsaved model's null key retain the `NULL` sentinel;
- boolean `where()` / `whereNot()` constraints canonicalize as `"0"` / `"!0"` and validate against typed columns without changing true or non-boolean values;
- `RuleContract`, `ValidationRule`, `InvokableRule`, `CompilableRules`, and closure rules retain their established object/wrapper behavior and remain uncacheable;
- conditional fluent-rule closures execute once during parser explosion and their one result determines the exploded rule; do not add a compiler-only double-evaluation test for a stateful object that no longer reaches the compiler.

Pin the ignored-zero behavior end to end with a mocked verifier assertion and one shared real-database case inherited by all four driver wrappers. Treat both serializer defects as upstream Laravel issue/PR candidates after the Hypervel fix; upstream coordination is not part of this implementation.

### 2. Share the non-implicit execution gates

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

### 3. Add a conservative inline-preflight boundary

Files:

- `src/validation/src/PlanExecutor.php`
- `src/validation/src/DelegatedCheck.php`
- `src/validation/src/RuleCompiler.php`
- `src/validation/src/Concerns/ValidatesAttributes.php`
- `src/validation/src/Enums/CheckType.php`
- `tests/Validation/ValidationPlanExecutorTest.php`
- `tests/Validation/ValidationRuleCompilerTest.php`
- `tests/Validation/ValidationValidatorTest.php`

Compiler-owned inline checks must contain scalar parameters only. After parsing all rules in `RuleCompiler::compile()`, inspect every parsed parameter list; if any parameter is non-scalar, return `compileAllDelegated($rules)` for that whole attribute. Explicit array tuples with objects, nested arrays, resources, or null are already uncacheable, and delegating their siblings preserves Laravel timing without adding a third date-format context state or a per-rule object allowlist. String rules and canonicalized fluent rules parse to scalar strings and retain their optimized path.

Delegation preserves the ordinary parameter contract; it does not make every position coercible. Hypervel keeps table, column, ID-column, and where-key identifiers string-typed so invalid identifiers fail natively. Supported value positions, such as database where values, retain their ordinary one-time conversion only if ordered execution reaches them.

Derive one readonly `DelegatedCheck::$parametersAreScalar` boolean in its constructor. This is immutable rule metadata, not execution state: exclusion and presence optimizers use it to reject a delegated check before serializing, casting, or otherwise inspecting non-scalar parameters. Computing it once avoids rescanning cached presence-rule parameters for every wildcard candidate and cannot drift from the check's actual parameter array.

Remove `Stringable` parameter casting from the `In` / `NotIn` compiler arms. The attribute-level boundary makes scalar parameters an invariant before `tryInline()` runs, so preflight does not need another parameter-safety check.

Place `canPreflightInline(InlineCheck $check, mixed $value): bool` immediately beside `executeInline()` so the safety classification and implementation are reviewed together.

Reject all object and resource values first. Objects can invoke user magic methods, `Countable::count()`, overridable file methods, and configurable object behavior. Resources are not legitimate presence candidates. Do **not** reject arrays: array-valued `exists` is supported, and native array/type/size predicates are safe.

Repair JSON validation at its actual shared boundary before relying on that classification, replacing both existing guards with one exhaustive type boundary:

```php
if (! is_scalar($value) && ! $value instanceof Stringable) {
    return false;
}
```

Null and arrays are neither scalar nor `Stringable`, so no preceding special case remains. PHP 8 automatically implements `Stringable` for classes declaring `__toString()`, while the check is safely false for a resource. Change the `Json` inline arm to call `$this->validateJson($attribute, $value)`, then delete the byte-identical `executeInlineJson()` helper and its unused `Json` import. This direct call has no parameter parsing, rule lookup, dispatch, or state overhead because `validateJson()` does not use the attribute. Do not generalize the pattern to inline rules whose delegated methods do more work.

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

The size cases are safe only when they cannot reach user code or a reachable Brick Math exception:

```php
if ($check->param['numeric'] && is_numeric($value)) {
    return ! Str::contains((string) $value, 'e', ignoreCase: true)
        && (! is_float($value) || is_finite($value));
}
```

Exponent values can invoke the configurable exponent-range callback. Non-finite floats pass PHP's `is_numeric()` but Brick Math rejects `INF` / `NAN`; treating them as unsafe preserves normal rule order if execution would throw. Objects are already rejected, so preflight also avoids file stat calls and magic string casts. Arrays continue through native `count()`.

Leave these eight cases unlisted:

- `Regex`, `NotRegex`: malformed patterns can emit warnings;
- `MultipleOf`: Brick Math throws for values such as `INF` and `NAN`;
- `DateAfter`, `DateBefore`, `DateAfterOrEq`, `DateBeforeOrEq`, `DateEquals`: they can reach the configurable `DateFactory` callback.

`IsDate` and `DateFormat` are safe scalar/native predicates and do not use the `Date` facade. Bare `Email` is also allowed after the object guard; Hypervel auto-singletons the stateless Egulias validator, and a hypothetical stateful concrete rebinding is not a supported behavior worth turning common `email|exists` lists into N queries.

Update both maintenance docblocks to state the four review points for a new inline rule: `CheckType`, `RuleCompiler::tryInline()`, `executeInline()`, and the explicit preflight-safety decision. An inline case may enter the preflight allowlist only after proving repeat evaluation is free of user callbacks, I/O, warnings, and reachable exceptions; omission is correctness-safe and only disables batching across that prefix. Do not add behavior or another rule-name registry to the enum.

Tests:

- define the reviewed-safe and reviewed-unsafe case lists in the test, assert their union equals `CheckType::cases()` with no duplicates, and exercise the object/resource guard, array support, exponent callback, and non-finite-size exceptions;
- prove a Stringable object is not cast during preflight;
- prove a non-scalar parameter anywhere in an attribute selects the all-delegated plan, executes user code only if ordered validation reaches it, and does not affect scalar string, array-tuple, or fluent forms;
- prove exponent callbacks and file methods execute only once and in normal order;
- prove resource-valued `json` fails rather than throwing in both base inline and all-delegated execution, while valid scalar and `Stringable` JSON retain their behavior;
- keep `required|integer|min:1|exists` batchable for ordinary scalar values;
- keep `required|array|exists` batchable.

### 4. Replace four-way size modes with canonical numeric semantics

Files:

- `src/validation/src/Enums/SizeMode.php`
- `src/validation/src/AttributePlan.php`
- `src/validation/src/RuleCompiler.php`
- `src/validation/src/PlanExecutor.php`
- `src/validation/src/Concerns/ValidatesAttributes.php`
- `src/validation/src/Validator.php`
- `tests/Validation/ValidationRuleCompilerTest.php`
- `tests/Validation/ValidationPlanExecutorTest.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`

Delete `SizeMode`. It encodes four compile-time modes that Laravel does not have and cannot determine the runtime value's actual shape. Compile each valid `min`, `max`, `size`, or `between` rule with one boolean: whether a sibling rule activates numeric semantics. The emitted check retains the original message parameters and its raw numeric threshold strings for precision.

There must be one authority for numeric-size rules. Pass the exact base `Validator`'s existing `$defaultNumericRules` into `RuleCompiler::compile()` and let `collectContext()` derive the boolean with a strict membership check. Do not hard-code `Numeric`, `Integer`, and `Decimal` in the compiler, move a duplicate list into the enum, or infer the category from class names. `compileAllDelegated()` needs no numeric context. Because only the exact base class is cached and its default list is stable, the existing rule-array cache key remains sufficient.

Use value-first size dispatch matching `getSize()`:

```php
protected function sizeOf(string $attribute, mixed $value, bool $numeric): float|int|string
{
    if ($numeric && is_numeric($value)) {
        return $this->ensureExponentWithinAllowedRange($attribute, $this->trim($value));
    }

    if (is_array($value)) {
        return count($value);
    }

    if ($value instanceof SplFileInfo) {
        return $value->getSize() / 1024;
    }

    return mb_strlen((string) $value);
}
```

Make `getSize()` delegate to this one body with `$this->hasRule($attribute, $this->numericRules)` as the boolean, then delete `sizeOfWithExponentCheck()`. Both inline and delegated execution therefore share value dispatch and exponent enforcement instead of maintaining parallel implementations.

Preserve precision-safe Brick Math comparison for numeric and file sizes. Classify every threshold at compile time by storing its `FILTER_VALIDATE_INT` result (or `null`) beside its raw string in the immutable `InlineCheck`. At execution, use the parsed integer only when the runtime size resolves to an array count or string length; otherwise use the raw threshold with Brick Math. Execution therefore performs only type/null checks. Decimal, exponent, and out-of-range thresholds are never rounded through `(float)`. Common `max:255` remains on the fast path without per-value parsing or a precision divergence. Do not choose value behavior solely from the sibling rule: `min:1|numeric` with `'abc'` must measure string length before the later `numeric` failure, just as Laravel does.

With this representation, typeless rules such as `required|max:255` inline correctly and keep a following presence rule batchable. `Decimal` automatically activates numeric semantics because it already belongs to `$defaultNumericRules`; future changes to that canonical list cannot silently drift from compilation.

Tests:

- typeless `min`, `max`, `size`, and `between` inline and use runtime string, array, and file semantics with the same messages as delegated Laravel behavior;
- `numeric`, `integer`, and `decimal` siblings activate numeric semantics, including when the size rule appears first;
- mixed/contradictory type siblings follow Laravel's `hasRule($numericRules)` behavior rather than delegating;
- non-numeric values with numeric siblings fall through to runtime value shape and preserve rule order;
- exponent callbacks, precision-sensitive decimal thresholds, file kilobytes, invalid uploads, and non-finite numeric values retain delegated behavior;
- string/array size comparisons with decimal or integer-overflow thresholds remain exact while ordinary integer thresholds use native comparison;
- threshold integer classification happens once at compilation, not per checked value;
- delegated `getSize()` and inline comparisons share `sizeOf()` as the only value-dispatch/exponent implementation;
- no `SizeMode`, four-way mapping, or duplicated numeric-rule list remains.

### 5. Build presence candidates from active compiled plans in order

Files:

- `src/validation/src/Validator.php`
- `src/validation/src/Concerns/ValidatesAttributes.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`
- validation database integration tests described in step 11

Rewrite the candidate half of `maybeBatchDatabaseChecks()` around the already filtered `compiledPlans`, not raw `$rules`:

1. Retain the current wildcard-only optimization boundary.
2. Find the first compiled `Exists` / `Unique` check before reading the attribute value or applying exclusion gates, and skip plans with no presence check. Continue scanning from that index after the shared gates; do not add a cached plan flag, registry, or per-plan allocation.
3. Skip plans whose `sometimes` flag is set when the concrete key is absent.
4. Apply the shared non-implicit and invalid-upload predicates once per attribute, before the check loop; neither depends on the current check or index.
5. Locate each `Exists` / `Unique` `DelegatedCheck` by its already parsed rule name. Walk only its preceding checks, in declaration order, before resolving presence metadata:
   - an `InlineCheck` may be evaluated only when `canPreflightInline()` returns true;
   - ordinary `Required` may call `validateRequired()` only for non-object values;
   - another `DelegatedCheck` makes this concrete value uncertain;
   - a safely evaluated false result proves failure and omits the value;
   - reaching the presence check after all safe passes makes the value batchable.
   Use an indexed walk over the plan's list rather than allocating an `array_slice()` for every candidate.
6. Only after the prefix passes, extract metadata from the check's parsed name/parameters. Inspect `originalRule` only to reject callback-bearing presence objects. Retain the existing rejection when a unique rule's raw ignore parameter contains `[` or `*`: a wildcard field reference resolves to a different ignored value for each concrete item and would turn batching into one grouped query per item plus planning overhead. Do not add a special case for the rare non-wildcard field-reference form; one simple conservative guard is easier to maintain and ordinary validation already handles both forms correctly.
   Return unknown before any metadata work when `DelegatedCheck::$parametersAreScalar` is false. Array-tuple non-scalar parameters must reach ordinary validation untouched: string-typed identifier positions still fail natively, while supported value positions retain their ordinary conversion. Planning must not invoke `__serialize()`, `__toString()`, or other conversion behavior.
7. Memoize only `parseTable()` results in a validator-owned map keyed by the raw table parameter and reset at the start of every `passes()` call. Make `parseTable()` the one authority so planning and real presence execution share the same model resolution; do not thread a by-reference planner accumulator. Model-class table resolution is stable within one validation execution, and developer-authored rule strings naturally bound the map. Do not memoize full metadata because inferred columns can depend on the concrete attribute.
8. Add no value for an uncertain candidate. If all candidates are uncertain, no batch query or verifier swap occurs. A lookup installed for safe siblings remains correct because unknown values delegate and runtime query-shape keys prevent a different presence query from consuming its facts.

Conceptually there are three outcomes, but do not introduce an enum, result object, plan cursor, phased executor, or mutable plan field. A small private helper/local state is enough:

```text
proven failure or shared skip -> no group value; presence cannot run
fully safe prefix            -> group and submit value
uncertain prefix             -> do not submit; runtime fallback if reached
```

Critical examples:

- `multiple_of:5|exists` with `'abc'`: normal validation fails `multiple_of` and performs no SQL. Because `MultipleOf` is unsafe to preflight, the candidate is uncertain and must not be eagerly submitted.
- `min:1|integer|exists` with `'abc'`: numeric semantics are compiled from the sibling `integer`, but value-first size dispatch treats `'abc'` as length 3, so `min` passes and `integer` fails. Stopping at an unsafe size rule and submitting the raw value would be wrong.
- one exponent-form, file, or custom-prefix value must not disable batching for safe siblings.

Building from `compiledPlans` automatically excludes plans removed by exclusion pre-evaluation. The plan-level `sometimes` gate prevents absent attributes from contributing values. Build every group with `PrecomputedPresenceVerifier::lookupKey()` from step 7 rather than retaining `buildPresenceGroupKey()`. Do not rebuild the current table/column collision census: query-shape-keyed lookups make different where, ignore, connection, and column shapes independent, while an unregistered or unknown runtime shape delegates. This also lets an ordinary non-wildcard presence rule share proven facts with an identical wildcard group without turning the wildcard batch back into N queries.

Parent exclusions need an additional conservative boundary. A child presence rule can otherwise be queried before a parent plan excludes the subtree; on a typed PostgreSQL column, a bare invalid child value can throw even though normal execution never reaches it. During exclusion analysis, return one execution-local set of attributes whose exclusion outcome could not be safely resolved. Do not batch a strict descendant of one of those prefixes; same-attribute order is already handled by the check-prefix walk. Execution order need not be stored: if a parent normally runs after its child, declining the child's batch is only conservative, whereas an order map adds state for a rare optimization. Reuse the existing descendant-prefix walk, do not add state to cached plans, and do not globally disable unrelated groups. A safely resolved non-excluding parent adds no prefix, and a pre-excluded parent has already been removed.

`stopOnFirstFailure` disables presence batching entirely under step 6. Global early-stop is the only cross-attribute execution break; a speculative PostgreSQL statement error can otherwise replace an earlier clean validation failure and abort the caller's transaction. Do not catch `QueryException`: catching it in PHP cannot repair PostgreSQL's aborted transaction state. Phased execution, savepoints, or schema/type probing would add disproportionate machinery, so validators that request global early-stop use the ordinary verifier while exclusion pre-evaluation remains enabled.

Tests:

- all-existing 1,000 `required|integer|exists` values issue one query; 1,001 issue two chunks;
- mixed valid/invalid integers submit only valid values, preserve ordered messages, and keep safe siblings batched;
- PostgreSQL `integer|exists` and `date|exists` / `date_format:Y-m-d|exists` reject invalid typed values without `QueryException` or presence SQL;
- a preceding safe failure, `bail`, nullable, empty, absent, `sometimes`, and pre-excluded attributes issue no inappropriate query;
- a child below any unresolved parent exclusion is not submitted early, while unrelated wildcard groups remain batchable;
- a preceding custom/delegated rule makes only that concrete value uncertain;
- a `Stringable` array-tuple where value is not converted during planning, then is converted exactly once by the ordinary verifier when ordered execution reaches it;
- an uncertain prefix that later fails performs no fallback; one that reaches presence performs one fallback;
- an all-uncertain group performs no batch query;
- different query shapes on the same table/column remain independent and correct rather than disabling one another;
- a non-wildcard presence rule with the same query shape does not disable wildcard batching and delegates only when its value is unknown;
- a data mutator that activates a previously skipped presence rule cannot consume facts from another query shape;
- two attributes sharing one cached `AttributePlan` can make different candidate decisions without cross-request/attribute state;
- string and array-tuple forms plus canonicalized callback-free presence objects retain their metadata/messages; callback-bearing objects remain delegated.

### 6. Split optimizer gates at the real mutation boundary

Files:

- `src/validation/src/Validator.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`

Use separate gates for the two optimizations:

```text
presence batching      exact base Validator + exact DatabasePresenceVerifier + not stopOnFirstFailure
exclusion prepass      exact base Validator + no used data-mutating extension/rule
```

Keep the early-stop condition on the inner presence-verifier gate. Exclusion pre-evaluation is a pure data pass that cannot produce a failure and remains safe and useful under `stopOnFirstFailure`. Add a concise source comment at that gate explaining that a failed speculative PostgreSQL query aborts the caller's transaction even when PHP catches the exception; do not add an exception catch, savepoint, transaction-state check, or deferred-query executor.

Do not retain the current `$this->extensions === []` gate. Extension registration normally happens at application boot and an unused registered extension cannot affect this validator. For exclusion pre-evaluation, scan compiled delegated checks and block only when dispatch would actually reach a custom extension (the normalized name exists in `$extensions` and no concrete `validate*()` method handles it), is a `ClosureValidationRule`, or carries an actual `ValidatorAwareRule`.

Unwrap `InvokableValidationRule` before classifying it:

```php
if ($check->originalRule instanceof InvokableValidationRule) {
    if ($check->originalRule->invokable() instanceof ValidatorAwareRule) {
        return true;
    }

    continue;
}
```

The wrapper always implements `ValidatorAwareRule`, but forwards the validator only when the inner rule implements it. A normal modern `ValidationRule` / `InvokableRule` receives data by value at most and cannot mutate this validator. It must not disable exclusion pre-evaluation.

Keep `ClosureValidationRule` as an exclusion blocker because it passes the live validator as the fourth callback argument. Keep a direct or wrapped `ValidatorAwareRule` as a blocker. Regardless of these global exclusion decisions, a custom rule preceding presence remains locally uncertain under step 5.

Presence batching must not use this data-mutation gate. Its maps store facts about a query shape and concrete value. If an earlier custom rule changes a value to one never submitted, runtime lookup is unknown and delegates. If it changes to a value submitted by another attribute, the fact remains database truth. Callback-bearing presence shapes remain unbatchable and their null lookup key always delegates.

The unresolved-parent exclusion boundary in step 5 still applies when the exclusion prepass is disabled: data mutation can make an exclusion outcome unknowable, so descendants of that potential exclusion are not submitted. This preserves typed-database safety without forfeiting unrelated groups.

Tests:

- an unused registered extension disables neither optimization;
- a used extension, `ClosureValidationRule`, and direct/wrapped `ValidatorAwareRule` disable exclusion pre-evaluation;
- a plain modern `ValidationRule` / `InvokableRule` does not disable exclusion pre-evaluation merely because Hypervel wraps it in `InvokableValidationRule`;
- presence batching remains enabled with those mutation-capable rules, while changed values use database-proven facts or delegate when unknown;
- changes from one submitted presence value to another submitted value use the correct fact; changes to an unsubmitted value fall back;
- parent exclusions made unresolved by a used mutator suppress only affected descendant batches.
- with `stopOnFirstFailure`, an earlier required failure prevents a later wildcard integer-to-text presence probe on every driver, reports only the earlier failure, and issues zero presence queries;
- without `stopOnFirstFailure`, the same earlier failure does not disable one grouped query for safe later text probes. Document this as the narrowness converse of the early-stop regression rather than a duplicate general batching test;
- on PostgreSQL, ordinary and wildcard/batched integer-to-text probes without early-stop both raise `QueryException`, proving that batching preserves the ordinary verifier's raw binding types.

### 7. Make precomputed presence facts database-semantic

Files:

- `src/validation/src/DatabasePresenceVerifier.php`
- `src/validation/src/BatchDatabaseChecker.php`
- `src/validation/src/PrecomputedPresenceVerifier.php`
- `tests/Validation/ValidationDatabasePresenceVerifierTest.php`
- `tests/Validation/ValidationBatchDatabaseCheckerTest.php`
- `tests/Validation/ValidationPrecomputedPresenceVerifierTest.php`
- validation database integration tests described in step 11

#### 7.1 Key facts by the complete database query shape

Move query-shape identity to the verifier that owns the facts. Add one shared public static `PrecomputedPresenceVerifier::lookupKey()` used by both `Validator` group construction and runtime `getCount()` / `getMultiCount()` lookup. The key contains:

- the current connection recorded by `setConnection()`;
- table and column;
- scalar where conditions in their established order, normalized exactly as `DatabasePresenceVerifier::addWhere()` consumes them;
- the ignored ID and effective ID column only when the ignored ID is neither null nor the `NULL` sentinel.

Do not include rule type. `exists` and `unique` without an ignored ID issue the same verifier query, and runtime verifier calls cannot distinguish them. `getMultiCount()` has no ignored-ID arguments, so its key correctly matches the no-exclusion scalar shape. Return null when any extra condition is a `Closure`; callback-bearing rules remain unbatchable and runtime calls delegate.

Use a collision-free serialized plain-string representation rather than delimiters or a lossy hash. This state exists only for one validation execution, query shapes are small, and correctness is more important than shortening the key. Require the original `DatabasePresenceVerifierInterface` in `PrecomputedPresenceVerifier`'s constructor and forward `setConnection()` unconditionally; a connection-less fallback cannot honor connection-keyed query shapes. `Validator::getPresenceVerifier()` already keeps the connection selection and probe in one synchronous chain. Unknown facts must always delegate; a nullable fallback and synthesized zero count would incorrectly pass `unique`.

Change `addLookup()` to accept the query key, and key all fact maps by it. A mismatched or null runtime key delegates. Delete the compensating table/column machinery completely:

- `Validator::collectUnsafeTableColumns()` and `extractTableColumnForUnsafeCheck()`;
- `Validator::buildPresenceGroupKey()` after its callers use `lookupKey()`;
- the `$unsafeTableColumns` parameter through `BatchDatabaseChecker`;
- `BatchDatabaseChecker`'s table/column count census and stale limitation docs.

Do not keep both collision mechanisms. Query-key ownership is smaller, supports separate batched shapes on the same column, and makes non-wildcard identical-shape probes safe once unknown values delegate under the fact model below.

Tests:

- build-time and runtime keys agree for exists, unique with/without ignore, connection-qualified tables, ordered wheres, and the `NULL` sentinel;
- exists and unique without an effective exclusion share a key; ignored IDs and effective ID columns change it;
- closure conditions return null and delegate;
- two different shapes on one table/column can each register and consume only their own facts;
- connection changes select the matching lookup while still reaching the fallback for unknown keys.

#### 7.2 Query only normalizable candidates

Normalize each concrete candidate independently. Only native strings, integers, floats, and one-dimensional arrays containing only those types are supported. Booleans, null, objects, resources, and other unsupported candidates are skipped without declining safe siblings. Date/time and `Stringable` objects must delegate unchanged: the connection owns date formatting, and the optimizer must never invoke arbitrary user code before ordered validation reaches the presence rule. Unsupported runtime probes delegate before consulting any stored fact.

Apply the same boundary to query-shape conditions. `lookupKey()` returns null for closures and every non-scalar value other than null; it must not cast a `Stringable` condition while planning or probing. Canonical callback-free database rules already serialize supported conditions to scalar strings, while callback-bearing rules remain delegated.

Keep database comparison normalization separate from PDO binding identity. Do not string-cast submitted SQL values: retain the raw string, integer, or float so the connection uses the same binding as the ordinary verifier. `Connection::bindValues()` binds integers as `PDO::PARAM_INT` and the other supported values as `PDO::PARAM_STR`, so use one collision-free binding key with a positional prefix:

```php
public static function bindingKey(mixed $value): ?string
{
    $normalized = self::normalizeValue($value);

    return $normalized === null ? null : (is_int($value) ? 'i' : 's') . $normalized;
}
```

Keep `normalizeValue()` as the shared string comparison form for supported candidates and fetched database values. The binding key delegates to it, so the native-type boundary remains in one place. Floats and strings intentionally share the `s` form because PDO binds each as a string representation. Prefixed keys also prevent PHP from silently converting numeric-string fact-map keys to integers.

Do not batch booleans. `Connection::prepareBindings()` converts them to integers, while `PostgresConnection` with emulated prepares converts them to `'true'` / `'false'`, and returned column representations also vary by driver/PDO mode. Delegating this marginal presence-rule shape is simpler and guarantees parity with the real verifier. It also removes the existing `false` to `''` corruption without adding a two-representation boolean scheme.

Deduplicate candidates by binding key and retain the first raw value as its representative SQL binding. Build one comparison-string-to-binding-keys index from `substr($bindingKey, 1)`; do not allocate a tuple or value object per candidate. A successful grouped query proves every retained raw binding was accepted. A fetched equal comparison string can therefore establish an exact fact for every matching submitted binding key while still allowing PDO to return integer/numeric columns as strings. An equal-looking runtime value with an unsubmitted binding key is unknown and delegates. Objects, booleans, and date/time values are excluded before normalization, so no binding-conversion approximation is needed.

Do not partially submit an array containing an unsupported nested item. Its eventual `getMultiCount()` must remain one coherent fallback.

#### 7.3 Use two grouped stages

For every grouped query shape:

1. Query all distinct submitted binding representatives in chunks of 1,000.
2. Normalize every fetched value to its comparison string, use the comparison index to build binding-keyed exact hits, then derive binding-keyed misses.
3. If stage 1 fetched nothing, every submitted value is proven absent.
4. If the whole group has one submitted binding and stage 1 returned a nonexact representation, that sole value is already known present for scalar presence semantics; no isolation query is needed. This count must be binding-based: collapsing string `'1'` and integer `1` here caused the same order-dependent MySQL defect as candidate deduplication.
5. If stage 1 has no exact hits and multiple submitted bindings, register no facts and let runtime probes delegate. The miss set is identical to the original grouped query, so rerunning it cannot isolate a value.
6. Otherwise, if stage 1 has hits and misses, query only the misses with the identical connection, table, column, where conditions, ignore value, ID column, write PDO, and chunking.
7. If stage 2 fetched nothing, every submitted miss is proven absent.
8. Every missed binding whose comparison string is returned by stage 2 is known present for scalar semantics, even when other misses remain ambiguous. Keep these in the scalar-only `knownPresent` map rather than merging facts from two queries into the stage-1 exact map.
9. If stage 2 is non-empty and there was exactly one missed binding, that isolated miss is known present even when the stored representation differs.
10. Other misses from a non-empty multi-miss stage remain unresolved and each runtime scalar probe delegates, memoized by full query key and binding key for this execution.

This uses the database itself to isolate collation/coercion matches. It avoids both an attacker-controlled one-query-per-ordinary-miss regression and non-portable collation emulation. Do not add recursive partitioning, thresholds, derived tables, `CASE`, or driver-specific equality logic.

Store three plain maps per query-keyed lookup:

```text
exactHits       stage 1 returned this submitted binding's comparison string
knownPresent    a single-binding query or stage 2 proved a scalar miss is present
provenAbsent    a stage returned no row for this submitted binding
```

Anything in no map was unqueried or ambiguous and delegates. Cache only actual fallback scalar counts inside this verifier instance, nested by the same full query key as the fact maps and then by binding key. Return the real cached integer count, not a synthesized boolean.

Expected scalar query costs:

- all exact-existing: one grouped pass (one query per 1,000 values);
- all absent / all-new unique: one grouped pass;
- mixed exact hits and true misses: two grouped passes;
- a standalone representation mismatch: one grouped pass; a mismatch mixed with exact hits: two grouped passes;
- multiple ambiguous representation mismatches: two grouped passes plus one fallback per distinct unresolved scalar value.

The extra stage creates no new consistency model: Laravel's unbatched probes already occur at different instants, and both grouped stages use the write PDO.

#### 7.4 Preserve `getMultiCount()` database DISTINCT semantics

Change `getExistingValues()` to select distinct column values and update its docblock to promise distinct stored values. This reduces duplicate transfer and makes one-chunk exact-hit facts usable for array-valued `exists` under the same database equality semantics as `distinct()->count($column)`.

On a case-insensitive or otherwise normalizing column, SQL `DISTINCT` can collapse multiple stored string representations to one representative. A requested value can therefore have a row with the same normalized string key in the table without that representation being returned by stage 1; here, an "exact hit" means only that stage 1 returned the submitted string key as its distinct representative. The omitted submitted key enters the miss set and may require stage 2. This is why stage-2 key hits can establish scalar facts, and why the second grouped pass is expected more often on columns containing equivalent representations.

There is one additional chunk boundary: SQL `DISTINCT` is authoritative within a query, not across independently chunked queries. Record whether stage 1 fit in one chunk. For `getMultiCount()`, derive each input's binding key and recover its comparison string from that key. Require a fact for every distinct binding key, but count present comparison strings once. This exactly matches `validateExists()`'s `count(array_unique($value))`, whose default `SORT_STRING` comparison collapses the same inputs. The existing rules then apply:

- any unknown value delegates the whole multi-count;
- a `knownPresent` binding is usable only when the array has one distinct comparison string; otherwise delegate because it may map to the same stored value as another input;
- proven-absent values contribute zero;
- exact hits can be counted only when stage 1 fit in one distinct query; otherwise delegate to avoid double-counting database-equivalent representations returned from separate chunks.

Otherwise delegate the whole multi-count. In particular, a stage-2-known-present value cannot be counted as a separate stored value: under a case-insensitive collation, inputs `['foo', 'Foo']` can both match one stored distinct value and must yield count 1, not 2.

This small boolean is necessary; without it, database-equivalent exact representations returned from separate chunks could be double-counted. It is preferable to always delegating arrays, which would discard the existing wildcard array batching feature.

Tests:

- exact, absent, known-present, unresolved, unsupported, and unregistered scalar paths;
- `Stringable` candidates and conditions are never cast by planning or lookup; they delegate unchanged only if ordered validation reaches them, while safe scalar siblings remain batched;
- an array containing a `Stringable` delegates as a whole;
- boolean false/true candidates are never submitted to a batch and fall back only if normal execution reaches their presence rule, without disabling safe siblings;
- `DateTimeInterface` candidates are never string-cast or submitted to a batch and match ordinary grammar-formatted validation on every driver;
- fallback count memoization is execution-local and keyed by the full query shape plus binding key; prove isolation between equal-looking string/integer probes, two shapes on one table/column, and two tables sharing the same probe value;
- all-new unique values do not produce N fallbacks;
- a case-insensitive differently-cased duplicate fails `unique`;
- exists honors case, accent, trailing-space, and numeric coercion according to each real driver rather than PHP guesses;
- a mixed exact/true-miss group uses exactly two grouped passes;
- an ambiguous multi-miss group falls back only for unresolved distinct scalar probes;
- `getMultiCount()` delegates for unknown, multi-input `knownPresent`, and cross-chunk-unsafe facts while retaining the safe single-input cases;
- stage-2 exact-key hits in a multi-miss group become scalar-known facts without contaminating stage-1 multi-count facts;
- canonical exact array values still use the precomputed distinct result;
- a MySQL/MariaDB case-insensitive table containing collation-equivalent representations matches the real verifier's distinct count;
- mocked and real-driver integer/float candidates remain precomputed hits without fallback queries when PDO returns the same values as strings, including numeric/decimal column results on every driver;
- on MySQL, MariaDB, and SQLite, mixed string `'1'` and integer `1` against stored text `'1'` submit both raw bindings in one query and both pass;
- on MySQL and MariaDB, stored text `'01'` with mixed candidates in both orders preserves ordinary per-value semantics: string `'1'` is absent and integer `1` is present, with neither fact consuming the other;
- on PostgreSQL, integer-to-text probes and both orders of mixed string/integer batches raise `QueryException` rather than being deduplicated or string-cast by the optimizer;
- a distinct representative can omit a requested string representation from stage 1 without producing an incorrect scalar or multi-count fact;
- duplicate input elements preserve `count(array_unique($value))` / database distinct behavior;
- verifier restoration after success and exception remains covered.

### 8. Restore exclusion order and wildcard authority

Files:

- `src/validation/src/Validator.php`
- `tests/Validation/ValidationPreEvaluatedExclusionsTest.php`

Drive exclusion analysis from `compiledPlans`. Only pre-evaluate when `checks[0]` is a delegated exclusion rule. `nullable`, `bail`, and `sometimes` are plan flags for the exact base validator, so they do not occupy position zero. Support all five built-in exclusion rules through their existing shared predicates: unconditional `Exclude`, `ExcludeIf`, `ExcludeUnless`, `ExcludeWith`, and `ExcludeWithout`. A resolved exclusion is an ordered hint; it must not suppress an earlier attribute or affect final cleanup unless execution reaches its exact plan position.

Use the check's already parsed parameters. Apply the same dependent-field normalization as `validateAttribute()`:

```php
$parameters = $this->replaceDotInParameters($check->parameters);

if ($keys = $this->getExplicitKeys($attribute)) {
    $parameters = $this->replaceAsterisksInParameters($parameters, $keys);
}
```

After parameter normalization, call the corresponding existing `validateExclude*()` predicate without adding a failure; a false result records the exact attribute as pre-resolved excluding and a true result records no exclusion. This reuses `parseDependentRuleParameters()` and therefore handles boolean/null coercion and non-scalar values exactly like execution instead of maintaining the current manual approximation.

Resolve the used-mutator gate lazily when the scan finds its first exclusion. Validators without exclusions must not run `compiledPlansUseDataMutatingRules()` or perform a second full-plan scan. A used mutator makes every exclusion unresolved; an unused registered extension still has no effect.

Scan each plan's checks once to find its first exclusion index and whether another exclusion appears later. Do not allocate `array_slice()` or repeat the same exclusion-name scan after a first-position predicate passes.

Memoize successful boolean predicate outcomes inside this one prepass with a collision-free `serialize([$ruleName, $originalParameters, $explicitKeys])` key. The five built-in predicates read only their normalized parameters, `$this->data`, and `$this->rules`; their target attribute/value arguments are unused. Original parameters retain the dependent wildcard pattern, while the exact explicit-capture list completes the normalized identity even when different target primary patterns share captures. The map is `array<string, bool>` local to one pre-execution call and adds no plan, coroutine, or worker state. Keep calling `getValue($attribute)` on misses to match normal invocation. Do not cache exception deferrals or add plan-identity metadata.

The original-data snapshot stops being authoritative after ordered execution may remove a descendant rule key. While scanning plans in declaration order, keep one local possible-exclusion-prefix set containing both resolved-excluding and unresolved/potential attributes. If the current rule-key attribute is a strict descendant of an earlier prefix, Laravel will or may remove that path at the attribute's loop entry; set one local `$dataMayDiffer = true` and skip scanning that descendant plan. Every possible prefix is also inserted into either the pre-excluded or unresolved result set, so the presence planner's ancestor checks already decline that plan and every deeper descendant. State this subset invariant in the source comment because the short-circuit depends on paired insertion. From that point, leave every later exclusion unresolved rather than evaluating it against stale original data. A resolved non-excluding result adds no prefix. Guard the ancestor walk until the prefix set is non-empty so validators without an earlier exclusion pay no dotted-path scan. Sibling leaf exclusions do not cross this boundary, so the common wildcard conditional path retains memoized pre-evaluation. Do not copy validation data, emulate a second mutable view, or build a dependency graph.

Before the lazy mutator scan, outcome-key serialization, parameter normalization, or predicate call, mark a first-position exclusion unresolved when its delegated check has non-scalar parameters. This preserves ordinary timing for array-tuple objects and resources and avoids running user `__serialize()` / `__toString()` methods during preflight. Do not widen the exception catch or probe the value for behavior.

Normalize dependent parameters only when the existing `dependsOnOtherFields($ruleName)` authority says to do so. Wrap that normalization and the speculative predicate call in one `try`. Catch exactly `InvalidArgumentException|ValueError` and classify the exclusion unresolved so normal ordered execution remains the authority: parameter-count checks throw the former, while too few wildcard captures make `vsprintf()` throw the latter. Do not catch `Throwable` or plain `Error`; that would hide bugs in the prepass, and unsupported non-stringable array-form field parameters have no realistic fluent or documented path. Do not duplicate the five predicates' parameter-count tables. Deferring is observable and required with `stopOnFirstFailure`: an earlier attribute failure can legitimately stop before Laravel ever reaches a malformed later exclusion, whereas throwing from the prepass would change that result. If execution does reach it, the original predicate still throws normally. Delete `parseExcludeRule()` and the numeric-segment regex `resolveWildcardConditionField()` once they have no caller.

Return `[$preExcludedAttributes, $unresolvedExclusionAttributes]` as local sets. Delete the validator's `$preExcludedAttributes` property, its reset, the eager `array_filter()` of compiled plans, and `isPreExcludedOrDescendant()`. Pass resolved hints to the executor and both sets to the presence planner; neither belongs on a cached plan or worker/global state.

Do not use Laravel's protected `$excludeAttributes` list as the exact-base executor's active index. The conditional benchmark activates about 3,100 leaf exclusions, so repeated list deduplication and full scans make both activation and lookup quadratic. Add one private execution-local `$activeExclusions` set for the exact base `Validator`, reset it at the start of `passes()`, and make it the sole authority for that path. Exact-base `excludeAttribute()` inserts into the set; exact-base `shouldBeExcluded()` checks the exact key and strict ancestors through `hasAttributeAncestorInSet()`. Subclasses continue to use the protected Laravel list and its existing deduplication/scan behavior. Do not dual-write: Laravel-shaped subclass `passes()` implementations can reset the protected list directly, and a private set must never gate or stale that path. Add a short property comment explaining this split.

The exact-base executor must mirror Laravel's observable attribute order:

1. At attribute entry, if an already active exclusion covers the attribute, remove that rule/data path and continue.
2. Apply `stopOnFirstFailure`; a later pre-resolved exclusion is not activated when execution stops before it.
3. If the exact current attribute is pre-resolved excluding, call `excludeAttribute($attribute)` and continue. Do not remove the current value yet: Laravel marks it during the exclusion rule and removes it in the final sweep, while later descendant rule keys are removed at their own entry.
4. Execute the normal optimized checks. Later-position, malformed, mutation-dependent, or stale-snapshot exclusions remain delegated and activate through `addFailure()` in declaration order.

The final sweep uses only `shouldBeExcluded()`. Every pre-resolved exclusion actually reached by execution has been activated through `excludeAttribute()`; future hints skipped by global early-stop must not affect data.

Presence batching stays deliberately more conservative than execution. Skip exact or descendant candidates found in the complete pre-excluded set and descendants of unresolved exclusions; a future parent may therefore forgo batching for an earlier child, but ordered execution still uses the ordinary verifier and remains correct. Same-attribute unresolved exclusions remain governed by the existing prefix walk. Do not duplicate executor-order activation inside the planner merely to recover this unusual batching opportunity.

Tests:

- `integer|exclude_if:foo,bar` retains the integer error even though the attribute is later excluded;
- exclusion-first still removes data before execution;
- exclusion-first supports top-level numeric attribute keys without crossing an integer/string type boundary;
- first-position `exclude`, `exclude_with`, and `exclude_without` use the same fast path and semantics as `exclude_if` / `exclude_unless`;
- a resolved non-excluding first-position exclusion keeps a following wildcard presence rule grouped, while a mutation-gated unresolved exclusion keeps that rule delegated;
- `bail`, `nullable`, and `sometimes` flags before exclusion do not prevent the fast path;
- malformed, later-position, or mutation-dependent exclusions remain in normal execution and mark only descendant batches uncertain;
- a malformed later exclusion still throws if reached, but an earlier `stopOnFirstFailure` result is not replaced by a prepass exception;
- a malformed wildcard exclusion remains an unresolved ancestor and suppresses early batching only for its descendants;
- literal numeric segment case: `data.5.items.0.value` resolves `data.5.items.*.type` with capture `0`, not literal segment `5`;
- two target fields sharing one capture reuse the same correct outcome while different captures remain isolated; one/multiple wildcard captures, mismatched counts, nested arrays, and escaped-dot field names match normal dependent-rule execution;
- parent pre-exclusion still suppresses descendants.
- an execution-time parent exclusion suppresses later descendants in exact-base and subclass validators;
- a descendant listed before its excluding parent retains its earlier failure while final data still removes the parent;
- `stopOnFirstFailure` does not activate or remove a later pre-resolved exclusion;
- two `passes()` calls on the same base validator, with restored rules/data and opposite exclusion outcomes, prove the exact-base active set resets between executions;
- when an excluded descendant rule key is removed, a later exclusion condition runs against the changed data instead of a stale prepass outcome;
- a later predicate reading the excluded attribute itself still sees it until final cleanup, while a later descendant rule sees its path removed at entry;
- a descendant presence candidate under a pre-excluded parent issues no SQL, while an unrelated wildcard group remains batched;
- a future parent exclusion may conservatively forgo an earlier descendant batch without changing its ordinary validation result.
- a first-position exclusion with a non-scalar array-tuple parameter invokes no conversion when an earlier global failure stops execution, and invokes ordinary conversion exactly once when execution reaches it.

### 9. Reset transient state, parse once, and streamline stop checks

Files:

- `src/validation/src/PlanExecutor.php`
- `src/validation/src/AttributePlan.php`
- `src/validation/src/RuleCompiler.php`
- `src/validation/src/Validator.php`
- `tests/Validation/ValidationCompiledExecutionTest.php`
- `tests/Validation/ValidationRuleCompilerTest.php`

Immediately before every real inline check, assign:

```php
$this->numericRules = $this->defaultNumericRules;
```

Delegated checks already reset inside `validateAttribute()`. Do not add a dirty bit or conditional reset; assigning a three-element array is a cheap refcount operation, and extra state/branching would be worse.

Regression: data `['field' => '123456', 'other' => 2]` with `string|gt:other|max:5`. `Gt` temporarily adds itself to `numericRules`; inline `max` must select `validation.max.string`, not `validation.max.numeric`.

In `RuleCompiler::compile()`, parse each exploded input rule exactly once into a temporary list of parsed name/parameter pairs. Pass those pairs to `collectContext()` and pass the corresponding pair into `compileRule()` rather than calling `ValidationRuleParser::parse()` again. Safe fluent conditions were already canonicalized once by step 1; this local intermediate representation removes duplicate token parsing and stringification from the compiler's context and emission passes.

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

The parsed pair's first element is `mixed`, not always a rule-name string: `ValidationRuleParser::parse()` returns the original object for a `RuleContract`. Keep `compileRule()`'s `RuleContract` branch ahead of any string-only path and keep `collectContext()`'s `is_string($parsedName)` guard. Before collecting inline context, build an all-delegated plan from the existing parsed pairs when any parameter is non-scalar, as required by step 3.

When a non-scalar parameter makes the base compiler fall back to an all-delegated plan, feed its existing parsed pairs directly into delegated check emission instead of parsing the uncacheable rules again. Standalone `compileAllDelegated()` calls for validator subclasses continue parsing each rule once as they emit it; keep its public signature unchanged rather than exposing the compiler's aligned parsed-pair intermediate. For `nullable`, `bail`, and `sometimes`, set the plan flag and also emit the original delegated check. Subclass helpers consume the flags, while Laravel-compatible execution still reaches overridden `validateNullable()`, `validateBail()`, and `validateSometimes()` methods.

Remove from `AttributePlan`:

- `$required`;
- `$hasImplicitRule`;
- `$sizeMode` and its `SizeMode` import as part of step 4.

Remove every compiler write and delete `RuleCompiler::isImplicitRule()` with its duplicate list. Numeric semantics remain only in `compile()`'s local context and in size `InlineCheck` parameters. `compileAllDelegated()` needs no context pre-scan, which also removes needless work for validator subclasses.

Strengthen `AttributePlan`'s existing immutability documentation: no execution or optimizer state may be attached because cached plans are shared across attributes, requests, and concurrent coroutines.

Keep two execution loops with separate contracts rather than branching on the validator class for every check:

- the exact base validator retains the optimized inline/delegated loop and stops from state its plan already owns;
- validator subclasses run a small Laravel-shaped loop over delegated checks: active-exclusion entry guard, global early stop, `validateAttribute()`, post-check exclusion, then dynamic `shouldStopValidating()`.

Neither execution loop applies a plan-level absent-`sometimes` shortcut. Inline checks already skip absent values through the shared non-implicit gate, while delegated checks retain `validateAttribute()` / `isValidatable()` as the authority. This is required because Laravel exclusion rules deliberately bypass `passesOptionalCheck()` even when `sometimes` is present. The subclass loop also preserves custom `validate*()`, `passesOptionalCheck()`, `shouldStopValidating()`, `shouldBeExcluded()`, and `removeAttribute()` behavior without adding branches to the exact-base hot loop.

The exact-base loop stops without calling the parsing-based `shouldStopValidating()` after a message:

1. Compute `$cleanedAttribute = $this->replacePlaceholderInString($attribute)` once for the plan.
2. Use `$plan->bail && $this->messages->has($cleanedAttribute)`. The current raw-attribute check is wrong for escaped-dot keys and is accidentally rescued by the later legacy helper.
3. Stop when `failedRules[$cleanedAttribute]` contains `uploaded`.
4. Stop when the names already recorded in `failedRules[$cleanedAttribute]` intersect the canonical validator `$implicitRules` list. The failed rule's presence proves the attribute has that implicit rule, so a preliminary `hasRule()` scan and a stored `hasImplicitRule` flag are both redundant.

Keep Laravel's protected `shouldStopValidating()` for subclasses and the legacy benchmark loop; only the exact-base executor bypasses its repeated parsing.

Correct escaped-dot key ownership at the same execution boundary:

- `hasNotFailedPreviousRuleIfPresenceRule()` queries messages with `replacePlaceholderInString($attribute)`, because messages and `failedRules` use public cleaned keys;
- `addFailure()` passes `$attributeWithPlaceholders` to `excludeAttribute()`, because exclusions, rules, and validation data use internal placeholder-containing keys.

Laravel 13.x shares both mistakes, but literal-dot attributes are supported. Keep cleaned keys for messages/failures and placeholder keys for rules/data; do not add a second conversion layer.

Replace tests that pin dead fields with behavior or consumed-output assertions:

- `Required` and implicit rules still execute on absent/empty attributes;
- nullable/bail/sometimes flags remain;
- size comparison and message selection come from the emitted numeric-semantics boolean plus runtime value shape;
- compiler context and emission consume one parsed-pair list without a second parse;
- parser tests, not compiler tests, prove conditional fluent closures are invoked once;
- bail, uploaded failures, and failed implicit rules stop without reparsing the attribute's rules;
- bail and implicit stopping use placeholder-cleaned escaped-dot attributes;
- subclass overrides of `shouldStopValidating()`, `passesOptionalCheck()`, `validateNullable()`, `validateBail()`, and `validateSometimes()` are reached exactly where Laravel reaches them, while the base validator never reparses through `shouldStopValidating()`;
- literal dotted and nested literal-dotted attributes do not issue `exists` / `unique` queries after an earlier failure;
- later-position exclusion on a literal-dotted attribute stops following rules, removes data through the internal key, and preserves earlier errors;
- all-delegated plans retain correct ordered behavior without stored context.

### 10. Fix strict `date_format` round trips

Files:

- `src/validation/src/Concerns/ValidatesAttributes.php`
- `src/docs/validation.md`
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

Document the exact-match contract beside `date_format` in the canonical validation docs. Do not add this correctness fix to the porting guide or package README: it does not change a normal porting decision, and those surfaces must not become exhaustive bug-fix diffs.

### 11. Put database validation coverage on the existing matrix

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

### 12. Cover the newly optimized common forms in benchmarks

Files:

- `src/validation/src/Console/BenchmarkValidationCommand.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `tests/Validation/BenchmarkValidationCommandTest.php`

Repair and verify the benchmark harness before changing performance-sensitive validation source, then capture the trusted baseline. The current command has valid optimized and historical execution paths, but its option handling, cache setup, workload checks, and reporting need these corrections:

- replace the mutable static description map with one typed `SCENARIO_DESCRIPTIONS` constant; derive the `all` list from its keys, validate requested names against it with a console error and `self::FAILURE`, and remove the `buildScenario()` fallback so a description/builder mismatch fails natively;
- keep the iteration count local to `handle()` and pass it to `benchmark()`. Delete the static property, `flushState()`, and its `AfterEachTestSubscriber` call so normal test cleanup no longer autoloads the benchmark-only legacy classes;
- declare both valued options with `InputOption::VALUE_REQUIRED`; validate iterations with `FILTER_VALIDATE_INT`, reject values below one instead of silently clamping them, and keep the default when the option is omitted;
- replace scenario `rand()` calls with deterministic arithmetic that preserves the same representative value shapes without mutating the process-global random generator;
- install one concrete `DatabasePresenceVerifier` from the command application's database resolver on both benchmark validators. Production validators receive this verifier even when their rules contain no presence check, so the optimized timings must include the planner's cheap no-presence gate; no benchmark scenario should issue a database query;
- before timing each path, flush `RulePlanCache` and `ValidationRuleParser`, then run one untimed warmup. Require the optimized and legacy warmup booleans to agree; report disagreement as a console error and `self::FAILURE`. Time each path only after its own warmup so both measure long-lived-worker steady state with the caches they actually use;
- calculate the true median for odd and even iteration counts inline in `benchmark()`. Do not extract a helper solely for testing;
- divide the nonzero real validator timings directly rather than guarding the numerator while dividing by the unguarded denominator;
- report that timings are medians of the requested number of measured iterations and return `self::SUCCESS` on completion.

Use one focused command test with `flat` and one iteration to keep it cheap. Assert a known scenario succeeds and names itself, an unknown scenario fails without silently running `simple`, and zero/non-integer iteration values fail. Do not expose private timing machinery, inject fake production scenarios, or test Symfony's own required-value error merely to reach otherwise private branches.

After steps 1–11, add two focused scenarios. The current scenarios use only pipe-delimited string rules and all size rules have explicit sibling types:

- a fluent-rules scenario with a representative wildcard form such as `['required', Rule::in([...]), 'max:...']`, proving parser canonicalization, plan-cache reuse, and `In` inlining are measured across fresh validators;
- a typeless-size scenario dominated by common `required|max:255` / `min` / `between` string and array values, proving the value-dispatched size path rather than the old delegated path is measured.

Keep benchmark data and rules valid and deterministic, reuse the existing optimized-versus-legacy harness, and do not add database timing to this command. Presence batching remains pinned by deterministic integration-test query counts; mixing live database latency into the CPU benchmark would add noise rather than useful coverage. Keep the existing conditional scenario shape unchanged because its first-position wildcard `exclude_unless` directly exercises step 8.

## Test and verification sequence

Implement each section with its focused tests, running the touched file immediately. At coherent checkpoints run:

```bash
vendor/bin/phpunit tests/Validation/BenchmarkValidationCommandTest.php

vendor/bin/phpunit tests/Validation/ValidationPlanExecutorTest.php \
  tests/Validation/ValidationCompiledExecutionTest.php \
  tests/Validation/ValidationBatchDatabaseCheckerTest.php \
  tests/Validation/ValidationPrecomputedPresenceVerifierTest.php \
  tests/Validation/ValidationDatabasePresenceVerifierTest.php \
  tests/Validation/ValidationPreEvaluatedExclusionsTest.php \
  tests/Validation/ValidationRuleCompilerTest.php \
  tests/Validation/ValidationRuleParserTest.php \
  tests/Validation/ValidationRulePlanCacheTest.php

vendor/bin/phpunit tests/Validation

DB_CONNECTION=sqlite DB_DATABASE=/tmp/testing.sqlite \
  bin/run-database-tests.sh sqlite --filter=ValidationBatchDatabaseCheckerTest
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=password \
  bin/run-database-tests.sh mysql --filter=ValidationBatchDatabaseCheckerTest
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=password \
  bin/run-database-tests.sh mariadb --filter=ValidationBatchDatabaseCheckerTest
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing DB_USERNAME=postgres DB_PASSWORD=password \
  bin/run-database-tests.sh pgsql --filter=ValidationBatchDatabaseCheckerTest
```

After repairing its integrity and before changing validation runtime behavior, run the existing benchmark three times. Run the final expanded benchmark three times after implementation:

```bash
php src/testbench/bin/testbench validation:benchmark --scenarios=all --iterations=15
```

Compare three runs by median. Investigate any repeatable optimized-path regression above normal measurement noise, especially flat/simple scenarios affected by the inline numeric-state reset. Confirm that fluent-object and typeless-size scenarios improve as intended. Presence performance is pinned primarily by query counts because avoided database round trips dominate predicate CPU.

Final verification:

```bash
composer fix
```

Do not weaken assertions to accommodate the implementation. Any failure must be traced to the shared gate, ordered planner, database fact model, or intended strict date-format correction.

## Acceptance checklist

- [x] Laravel rule syntax, public APIs, rule/message order, and extension points remain compatible except for the verified upstream `date_format`, resource-valued `json`, falsey ignored-ID, and boolean database-condition bug fixes.
- [x] Pipe-delimited and array rule forms compile to the same correct behavior.
- [x] Laravel's generic safe-object canonicalization is restored; fluent `in` / `not_in` and callback-free presence rules use caching/inlining without a class allowlist.
- [x] Callback-bearing presence rules retain their objects and query callbacks, remain delegated, and produce no lookup key.
- [x] O(n) wildcard expansion, immutable worker-lifetime plan caching, and the branch-free exact-base execution loop remain intact.
- [x] Validator subclasses execute every declared rule through Laravel's delegated loop and preserve protected/public extension hooks without slowing the exact base path.
- [x] Any attribute containing a non-scalar parsed parameter uses the all-delegated path; compiler-owned inline parameters are scalar by construction.
- [x] Typeless `min` / `max` / `size` / `between` inline with runtime value dispatch; numeric semantics come only from the canonical `$defaultNumericRules`, including `Decimal`.
- [x] Common `required|integer|exists`, `required|max:255|exists`, `email|exists`, `date|exists`, and `required|array|exists` wildcard shapes remain batched.
- [x] `stopOnFirstFailure` uses ordinary presence execution so speculative SQL cannot replace the first validation failure or poison a PostgreSQL transaction; exclusion pre-evaluation remains enabled.
- [x] No invalid value is submitted merely because preflight could not prove its prefix; uncertain probes fall back only if execution reaches presence.
- [x] Boolean, object, resource, and `Stringable` presence candidates use the ordinary verifier path; the optimizer invokes no user conversion code and approximates no driver-owned binding.
- [x] SQL bindings retain their raw supported types; facts and fallback memos require the submitted PDO binding identity while fetched values use a separate string comparison form.
- [x] Precomputed facts are keyed by complete effective query shape; different connections, wheres, or unique exclusions on one table/column cannot consume one another's facts or disable each other's batches.
- [x] Pre-resolved exclusions activate only when execution reaches their exact attribute; attribute order, global early-stop, descendant removal, later dependent rules, and final cleanup match Laravel.
- [x] Pre-excluded, unresolved-parent-excluded, absent-sometimes, empty, nullable, and proven-failing values issue no presence query.
- [x] Case-insensitive/collation-equivalent `unique` values cannot false-pass.
- [x] Array-valued `exists` agrees with database `DISTINCT` semantics, including chunk boundaries.
- [x] No optimizer result is stored in a shared plan, static property, or coroutine context.
- [x] Exclusion pre-evaluation covers the five built-in exclusion rules only at a safe first position, preserves earlier failures, and resolves wildcard captures through the established authority.
- [x] Unused extensions do not suppress optimization; used validator mutators suppress only exclusion pre-evaluation and affected descendant batches.
- [x] Inline messages cannot inherit transient numeric state.
- [x] Resource-valued JSON fails cleanly in inline and delegated execution, with no duplicate JSON predicate.
- [x] Conditional fluent closures are evaluated once during parser explosion, matching Laravel, and compiler context/emission share one local parsed-pair list.
- [x] Compiled bail/uploaded/implicit stopping uses existing plan/failure state and placeholder-cleaned attribute keys without reparsing rules.
- [x] Escaped-dot message/failure keys and internal rule/data keys remain in their correct domains for presence and exclusion behavior.
- [x] `SizeMode`, `DatabasePresenceRule`, presence-metadata methods, dead plan fields/compiler writes, duplicate implicit-rule knowledge, and obsolete helpers/tests/comments are removed.
- [x] Every retained source comment and docblock describes the final design; no superseded optimizer explanation remains.
- [x] The validation database suite runs through the existing MySQL, MariaDB, PostgreSQL, and SQLite workflow discovery.
- [x] The benchmark rejects invalid input, verifies optimized/legacy result agreement, measures deterministic warm-cache workloads, and reports a correct median without process-global command state.
- [x] Focused, full validation, database-matrix, benchmark, static-analysis, formatting, and final repository checks pass.
