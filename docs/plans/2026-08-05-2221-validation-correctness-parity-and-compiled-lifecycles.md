# Validation Correctness, Parity, and Compiled Lifecycles

## Status and scope

**Status:** Complete. Investigation, owner approval, implementation, verification, fresh self-review, independent code review, and bookkeeping are complete.

Complete the Validation audit against:

- Hypervel `0.4` at `4223bc81df63eb961a422278d54b0c2b578ed4e6`;
- current Laravel framework source at `2c410561c21452de2f164caea64ab0fcac692a5d`;
- the completed `validation-01` and `support-29` decisions;
- Validation source, unit/integration tests, Contracts, Support facade generation, Foundation consumers, Boost documentation, split metadata, and audit records.

Preserve Hypervel's performance architecture:

- `Factory` remains a worker singleton and each `make()` returns a fresh `Validator`;
- `ValidationRuleParser`, `RuleCompiler`, `RulePlanCache`, and `PlanExecutor` retain bounded compiled execution;
- subclasses, extensions, validator-aware rules, callback-bearing database rules, and ambiguous database shapes retain delegated execution;
- only the exact built-in `DatabasePresenceVerifier` is eligible for SQL batching;
- configured Email, File, and Password defaults remain isolated worker-lifetime prototypes under completed finding `validation-01`.

No accepted change removes a supported Laravel API. `validation-15` deliberately makes scalar `in` / `not_in` literal matching strict, and `validation-12` deliberately makes malformed date comparisons deterministic rather than inheriting Laravel's false passes and native failures. The owner approved both correctness divergences. Earlier unreleased Hypervel behavior and churn are not constraints.

### Owner-approved additions and differences

| Findings | Benefit | Cost and parity effect | Rejected alternative |
|---|---|---|---|
| `validation-12` | Invalid operands fail predictably; optional referenced fields remain optional; supported numerics and date objects never escape native errors. | Constant-time type/classification branches; deliberately stricter than Laravel for malformed or present-invalid operands. | Preserve operator-dependent false passes or add a date-policy abstraction. |
| `validation-34` | Restore Laravel-compatible date comparisons for hyphenated and digit-leading field paths. | Syntactically indistinguishable alphanumeric date literals delegate at roughly 5.7 microseconds per affected check; common ISO and pure-numeric literals remain inline. | Leave supported field names broken, retain only a partial letter-anchored fix, or make cached compilation depend on mutable Date-factory parsing. |
| `validation-15` | Scalar membership represents the literal strings written in the rule and removes PHP magic numeric equality. | Strict comparison over the existing small parameter list; documented Laravel-facing difference. | Retain coercion or add a comparison-policy object. |
| `validation-16`, `validation-17`, `validation-26` | Restore current Base64, HTML password-rules, and DNS-testing APIs. | Delegated/on-demand/test-only work; DNS validation adds one worker-local boolean branch before the existing lookup. | Omit current APIs or add speculative compiler support. |
| `validation-24` | Make provenance, conditional types, throws, and intentional differences truthful. | Documentation and static-analysis metadata only. | Leave public surfaces incomplete or change runtime values to mimic untyped upstream code. |

## Post-compaction rules

After compaction, re-read `AGENTS.md` and this plan in full before editing. Re-open the active source, tests, relevant ledger entries, and current upstream source; summaries are navigation only.

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

### Architecture and performance evidence

`ValidationRuleParser` normalizes rules, `RuleCompiler` creates immutable-by-convention plans, and `RulePlanCache` retains at most 2048 plans. `PlanExecutor` inlines only known rule shapes; its delegated fallback preserves extension and subclass behavior. Neither cache yields while mutating, so cooperative coroutines cannot interleave inside a cache operation and no lock is warranted.

Current Laravel source is authoritative. Originating PRs `#60808` / `#60813` (Base64), `#60070` (HTML password rules), `#59644` (breach-hash comparison), `#60151` (email line breaks), `#60290` (image ratios), `#60393` (date equality), `#59339` (message lookup), and `#59717` / `#59739` / `#60024` / `#60120` (accepted domains) identify the complete related source, test, translation, and documentation surfaces. PR `#59339` is evidence only because its final branch is incomplete for Hypervel's preserved size-message behavior.

The Factory and its extension state are intentional worker-lifetime configuration. Request mutation belongs to each fresh Validator. Database batching is a conservative optimization over wildcard `exists` / `unique`: it must use the configured concrete verifier and abandon the whole candidate group when a shape cannot be represented exactly.

The cache-key correction adds `serialize()` only for cacheable string-rule arrays. Against the replaced `implode()`, a four-rule probe measured about 0.08 microseconds of additional key work per lookup—roughly 2% of a warmed 3.59-microsecond compilation—so the bounded cache remains a large net win. No accepted change adds a query, network round trip, lock, yield, retry, polling loop, unbounded cache, or per-request container-resolution loop.

### Findings and dispositions

| ID | Category | Severity | Final result |
|---|---|---:|---|
| `validation-01` | Revalidated defect | Major | Keep configured default-rule clone isolation and cleanup unchanged. |
| `validation-02` | Worker-cache defect | Major | Replace delimiter keys with collision-free serialized normalized parts. |
| `validation-03` | Tests-only API defect | Minor | Reject cache capacities below one. |
| `validation-04` | Resolver-ownership defect | Major | Run batch SQL through the configured concrete verifier and explicit connection. |
| `validation-05` | Batched-value defect | Major | Flatten supported one-dimensional values and decline unsupported groups. |
| `validation-06` | Contract defect | Minor | Accept `mixed` values in every presence verifier. |
| `validation-07` | Return-contract defect | Minor | Make the Factory's unconfigured verifier result nullable. |
| `validation-08` | Message-resolution defect | Major | Resolve array messages only where a size-rule type selects one; otherwise fall back. |
| `validation-09` | Password-check defect | Minor | Compare canonical breach hashes strictly. |
| `validation-10` | Email-input defect | Major | Reject CR/LF in compiled and delegated email validation. |
| `validation-11` | Image-ratio defect | Major | Correct min/max direction and port resolution-aware tolerance. |
| `validation-12` | Date-comparison defect | Major | Make all five comparison rules deterministic across literals, fields, formats, and supported value types. |
| `validation-13` | Accepted-domain defect | Major | Apply current upstream type guards to compiled and delegated rule families. |
| `validation-14` | Compiled-parity defect | Major | Make inline `not_in` the logical inverse of inline `in`. |
| `validation-15` | Improvement / intentional difference | Improvement | Compare scalar membership against literal string allowlists strictly. |
| `validation-16` | Current API parity | Improvement | Port the delegated `base64` rule, translation, docs, and tests. |
| `validation-17` | Current API parity | Improvement | Port `Password::toPasswordRulesString()` and its public guidance. |
| `validation-18` | Facade metadata defect | Major | Generate only the Factory surface exposed by the Validator facade. |
| `validation-19` | Lifecycle documentation | Minor | Mark Factory mutators boot-only with concrete worker-wide effects. |
| `validation-20` | Test lifecycle defect | Minor | Move the exact seventeen raw PHPUnit classes onto the framework base. |
| `validation-21` | Test global-state defect | Minor | Restore the exact prior PHP timezone in the two owning test classes. |
| `validation-22` | Split metadata defect | Major | Declare direct Context, Console, HttpFoundation, and Foundation-suggestion boundaries. |
| `validation-23` | Authorization contract defect | Major | Make the raw trait's default authorization result boolean while preserving FormRequest's richer override. |
| `validation-24` | Documentation and typing | Improvement | Add provenance, accurate conditional PHPDocs, throws, and concise public differences. |
| `validation-25` | Bounded strict cleanup | Minor | Tighten only proven same-domain comparisons; preserve intentional coercion. |
| `validation-26` | Current test API parity | Improvement | Port Laravel's bounded DNS faking surface and reuse existing Validator cleanup. |
| `validation-27` | Direct-construction defect | Minor | Make precomputed presence lookups fall back for unsupported values. |
| `validation-28` | Optimizer integrity defect | Major | Disable pre-evaluation and batching together when validator-aware rules can mutate data. |
| `validation-29` | Compiler-parity defect | Minor | Delegate malformed/fractional digit parameters instead of truncating them. |
| `validation-30` | Date-format context defect | Major | Normalize castable raw formats at both compiler and behavioral context readers. |
| `validation-31` | Message-replacement defect | Major | Render documented integer parameters at the nine exact scalar replacer owners. |
| `validation-32` | Dependent-rule defect | Major | Give `date_equals` the same parameter preprocessing as the other date comparisons. |
| `validation-33` | Compiled date-field defect | Major | Delegate escaped-dot date field references instead of treating them as literals. |
| `validation-34` | Date-field classification defect | Major | Recognize hyphenated and digit-leading named field paths without deoptimizing common ISO or pure-numeric literals. |
| `validation-35` | Membership value defect | Major | Reject non-stringable submitted objects without throwing, with `not_in` remaining the exact inverse. |
| `validation-36` | Fallback-message regression | Minor | Preserve Laravel's translation-key fallback for empty and string-zero fallback messages. |

## Implementation design

### 1. Correct compiled-plan identity and capacity (`validation-02`, `validation-03`)

Keep the existing all-string eligibility gate and serialize the normalized list, not the caller's array keys:

```php
$parts = [];

foreach ($rules as $rule) {
    if (! is_string($rule)) {
        return null;
    }

    $parts[] = $rule;
}

return serialize($parts);
```

Reject invalid test capacity once at mutation:

```php
if ($size < 1) {
    throw new InvalidArgumentException('The rule plan cache size must be at least 1.');
}

self::$maxSize = $size;
```

Import the global `InvalidArgumentException`. Regress both a direct cache collision and a cross-Validator collision using a regex containing `|`; cover zero, negative, and positive capacities. Do not hash the serialized key, keep a collision registry, or add a disabled-cache mode.

### 2. Restore presence-verifier ownership and supported value shapes (`validation-04`–`validation-06`, `validation-27`)

Add one concrete batch method to `DatabasePresenceVerifier`, taking the connection per call so no mutable verifier selection spans a yield:

```php
public function getExistingValues(
    string $collection,
    string $column,
    array $values,
    ?string $connection,
    mixed $excludeId = null,
    ?string $idColumn = null,
    array $extra = [],
): array {
    $query = $this->db->connection($connection)
        ->table($collection)
        ->useWritePdo()
        ->whereIn($column, $values);

    // Apply the existing ignore and extra-condition semantics, then pluck.
}
```

The `mixed` ignore parameter is deliberate: object-form `Unique::ignore()` is publicly typed `mixed` and `presenceMetadata()` exposes that raw value before string-rule parsing or `prepareUniqueId()` narrows it. Keep the truthful existing batch boundary instead of claiming an `int|string|null` domain the object form does not enforce.

`BatchDatabaseChecker` must call the exact verifier it was given. Delete the duplicate facade query and condition construction. Preserve named/default connection selection, write-PDO use, chunking, ignore IDs, and existing condition operators.

Normalize candidate group values at one boundary:

```php
private static function uniqueStringValues(array $values): ?array
{
    $normalized = [];

    foreach ($values as $value) {
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (! is_scalar($item) && ! $item instanceof Stringable) {
                return null;
            }

            $normalized[] = (string) $item;
        }
    }

    return array_values(array_unique($normalized, SORT_STRING));
}
```

Import PHP's global `Stringable`. This intentionally supports one-dimensional scalar/Stringable data only. In `registerLookups()`, test `=== null` first to decline the entire group, then retain the existing `=== []` empty-group skip. Nested arrays and unsupported objects are not recursively flattened.

Widen `PresenceVerifierInterface::getCount()` to `mixed`, which matches both built-ins and the Validator's real input domain. `PrecomputedPresenceVerifier` normalizes supported scalar/Stringable values; unsupported `getCount()` values delegate to its fallback, and unsupported `getMultiCount()` input delegates as a whole. No batching capability is added to the public contract.

Tests must prove injected-resolver ownership without a DB facade, named/default connections, conditions, ignore IDs, chunking, scalar and one-dimensional array values, duplicates, empty arrays, unsupported fallback, custom-verifier numeric values, query counts, and verifier restoration in `finally`.

### 3. Close the validator-aware optimizer hole (`validation-28`)

Compute the optimization eligibility once and use it for both exclusion pre-evaluation and database batching:

```php
$canOptimize = static::class === self::class
    && $this->extensions === []
    && ! $this->compiledPlansContainValidatorAwareRules();

if ($canOptimize) {
    $this->preEvaluateExclusions();
}

if ($canOptimize
    && $this->presenceVerifier !== null
    && $this->presenceVerifier::class === DatabasePresenceVerifier::class
) {
    $this->maybeBatchDatabaseChecks();
}
```

The existing plan scan is already paid; this adds no normal-path second scan. A validator-aware rule can mutate wildcard data before `unique` / `exists`, so both precomputed decisions must be disabled together. Regress a rule that changes a unique value after planning and prove the ordinary verifier observes the changed value.

Delete the stale `Validator::passes()` comment that says Exists/Unique objects are not a mutation threat. It asks whether the batched rule mutates when the actual invariant is whether any earlier validator-aware rule can mutate data before the batched rule executes.

### 4. Make message lookup type-safe without losing size maps (`validation-08`)

Do not port Laravel PR `#59339`'s wildcard-only lookup: it drops valid wildcard type-specific size messages and leaves non-wildcard arrays unsafe. Resolve at both consumers:

```php
$message = $this->getFromLocalArray($attribute, Str::snake($rule));

if (is_array($message)) {
    return in_array($rule, $this->sizeRules, true)
        ? ($message[$this->getAttributeType($attribute)] ?? null)
        : null;
}

return $message;
```

The final fallback in `getMessage()` returns only strings; otherwise it returns the translation key. Cover wildcard and exact keys, size maps, absent rule entries, and translator fallback.

Preserve Laravel's falsey fallback semantics after narrowing the type: empty and string-zero fallback messages return the translation key rather than becoming user-visible empty or `0` errors. Use strict membership for all three proven string-only `$sizeRules` checks.

### 5. Correct built-in rule behavior in compiled and delegated paths (`validation-09`–`validation-15`, `validation-29`)

Apply these bounded changes at both execution owners:

- `NotPwnedVerifier`: compare the already-canonical full SHA-1 hash with `===`; keep `$count > $threshold`.
- Email: return false when a string contains `\r` or `\n` before Egulias construction in `validateEmail()` and `isValidEmail()`.
- Dimensions: use current Laravel's tolerance and direction:

```php
$precision = 1 / (max(($width + $height) / 2, $height) + 1);

return ($minimum - $actual) > $precision;
// maximum: return ($actual - $maximum) > $precision;
```

  Port the current fluent image-rule regressions for valid, exact-boundary, and invalid minimum/maximum ratios. Correct the three remaining builder/message fixtures that specify an impossible minimum greater than their maximum, even though those fixtures do not execute ratio comparison. Omit Laravel's ignored third argument on the successful max-ratio assertion and record that upstream test defect rather than copying it.

- ASCII/lowercase/uppercase/hex require strings; digit families and starts/ends families require strings or numeric values before casting. Remove the unused `$parameters` argument from `validateLowercase()` and `validateUppercase()` to match current Laravel's extension-point signatures, then regenerate facade metadata. `validateDecimal()` already matches the accepted-domain fix and needs no source change.
- Inline `not_in` is exactly the inverse of inline scalar `in`; the sibling `array` rule continues to delegate list membership.
- Scalar `in` / `not_in` compare normalized string domains strictly. Array-form rules preserve parameter types, so normalize stringable inline execution parameters once during compilation while retaining raw parameters for message replacement:

```php
'In' => $context['hasSiblingArrayRule']
    ? null
    : new InlineCheck(
        CheckType::In,
        array_map(
            static fn (mixed $parameter): mixed => is_scalar($parameter) || $parameter instanceof Stringable
                ? (string) $parameter
                : $parameter,
            $parameters,
        ),
        parameters: $parameters,
    ),
```

  Apply the same compile-time normalization to `NotIn`. In delegated `validateIn()`, keep the existing array-plus-`array` branch unchanged, then apply the same conditional normalization before strict scalar membership. Import PHP's global `Stringable` in both owners. Non-stringable raw parameters remain untouched and therefore remain no-matches against the normalized string value; do not add enum handling. Raw array-form enum parameters are an unsupported spelling: `[['in', Status::Active]]` fails and message rendering throws even when the submitted value is the enum's backing value. Fluent `Rule::in()` / `Rule::notIn()` already convert enum cases through `enum_value()` before parsing. Do not guard message rendering alone: rendering the enum's backing value would claim that the permanently failing rule permits the submitted value, while rendering a generic object label would hide the configuration error. Supporting raw enums coherently would require changing both matching and rendering and is unrelated new behavior. The delegated per-call allocation belongs only to the already-heavier subclass/extension path; the common compiled path uses cached normalized parameters. Cover raw `['in', 1, 2]` / `['not_in', 1, 2]` and `Rule::in()` / `Rule::notIn()`; assert the fluent enum rule's rendered allowed value as well as its validation outcome. Do not strictify `array_diff()`.

  Submitted values participate only when scalar or `Stringable`; arbitrary objects and enum instances fail `in` without throwing, and `not_in` remains its exact inverse. This guard preserves existing booleans, numerics, and Stringable values without adding enum normalization or changing the supported parameter domain. Arbitrary-object size rules and `distinct:ignore_case` retain their configuration/input errors because no truthful size or case-insensitive identity exists for those values; do not add a false zero/default interpretation.
- `RuleCompiler` emits digit inline checks only when every required parameter is an exact integer string. `2abc`, `2.9`, `2.0`, and `abc` delegate instead of being truncated with `(int)`.

Message replacement must accept the same documented integer parameters as validation. Cast the first parameter at the nine exact `str_replace()` owners: `replaceDateFormat()`, the single-parameter branch of `replaceDecimal()`, `replaceDigits()`, `replaceMin()`, `replaceMinDigits()`, `replaceMax()`, `replaceMaxDigits()`, `replaceMultipleOf()`, and `replaceSize()`. Give those methods truthful `array<int, int|string>` PHPDocs and type `replaceMultipleOf()`'s message as `string`. Correct `replaceIn()` and `replaceNotIn()` to the same parameter PHPDoc because their retained raw message parameters legitimately contain integers; they already render those values correctly and need no cast. Do not normalize all replacer parameters centrally: membership, dependent, and dimensions replacers retain typed parameters for their own semantics.

Use one base/subclass matrix for supported and unsupported values, including string, int, float, null, bool, array, Stringable, and non-Stringable objects. Add direct cases for magic-hash strings, magic numeric membership, array `not_in`, every guarded rule family, canonical ratios, and malformed digit parameters. No generic value normalizer or comparison-policy object is introduced.

### 6. Make all date comparison rules deterministic (`validation-12`)

Preserve literal-first behavioral resolution: delegated comparison first attempts to parse the rule argument as a literal. Only an unparseable string that `ValidationRuleParser::looksLikeDateFieldReference()` recognizes may be resolved as a field. This keeps parseable identifiers such as `march` and `monday` as literals.

```php
$current = $this->getDateTimestamp($value);

if ($current === null) {
    return false;
}

$target = $this->getDateTimestamp($argument);

if ($target === null && ValidationRuleParser::looksLikeDateFieldReference($argument)) {
    $fieldValue = $this->getValue($argument);

    if ($fieldValue === null) {
        return true;
    }

    $target = $this->getDateTimestamp($fieldValue);
}

if ($target === null) {
    return false;
}

return $this->compare($current, $target, $operator);
```

Move the compiler's existing syntactic predicate to the shared parser; do not make the compiler parse dates merely to select an execution path. Recognize both ordinary dots and documented escaped dots so `after:a\.b` delegates to the existing placeholder-aware field-resolution path. Array-form rules are always delegated, so the compiler calls the predicate only with parsed string-rule parameters; the `mixed` boundary is retained for the behavioral caller. It returns false for non-strings and carries the narrowing assertion needed by `getValue()` and `getDateFormat()`:

```php
/**
 * Determine if a rule argument names a field rather than stating a date literal.
 *
 * @phpstan-assert-if-true string $argument
 */
public static function looksLikeDateFieldReference(mixed $argument): bool
```

Comparison-rule parameters are documented as `int|string` and are string-normalized by the existing dependent-rule pipeline before validation. Add `DateEquals` to that pipeline beside the other four comparisons; this also restores escaped-dot decoding and wildcard substitution for `date_equals` field references. `DateFormat` is not a dependent rule, so normalize its first scalar/Stringable parameter independently at `RuleCompiler::collectContext()` and `getDateFormat()`. Unsupported arrays and non-Stringable objects remain malformed rule configuration; do not change shared parameter preprocessing to support them.

Laravel's dependent-rule preprocessing stringifies an array parameter with a warning. The resulting `Array` token may be interpreted as an absent field and pass; this malformed configuration is outside the uniform submitted-value policy above. Do not add parser machinery or a renderer-only guard for it.

The classifier must accept ordinary hyphenated and digit-leading named paths while preserving inlining for the dominant ISO and pure-numeric literal shapes:

```php
return preg_match(
    '/^(?=.*[A-Za-z_])[A-Za-z0-9_-]+(\\\?\.[A-Za-z0-9_*-]+)*$/',
    $argument,
) === 1;
```

This fixes both defects inherited from the earlier restrictive compiler predicate: compiled execution previously failed open for these fields by comparing a timestamp with `null`, while delegated execution resolved them correctly; the new operand guards closed that fail-open path, but sharing the restrictive predicate regressed delegated execution into the same hard failure. Prove both comparison directions and nested wildcard substitution.

The broader syntax necessarily delegates indistinguishable alphanumeric literals such as `20250102T120000Z`. Common `2025-01-02`, `20250102`, and timestamp targets remain inline. The affected literal shapes pay the existing delegated-call overhead, measured at roughly 5.7 microseconds per check (about 30% of a minimal one-rule validation and a much smaller share of a realistic request). The owner approved that bounded trade for correct Laravel-compatible field resolution. Do not parse dates during compilation: ambiguous-only parsing would add Date-factory-dependent cached decisions and 26–44 microseconds of failed parsing to ordinary field-reference cache misses merely to optimize uncommon literal shapes.

```php
$format = $parsedParams[0] ?? null;

if ($dateFormat === null && (is_scalar($format) || $format instanceof Stringable)) {
    $dateFormat = (string) $format;
}
```

The behavioral reader applies the same predicate to `$result[1][0] ?? null` and returns null otherwise. This prevents malformed formats on absent attributes from becoming unconditional compilation failures while keeping the owning `date_format` rule responsible for invalid configuration.

The classifier's true branch retains its required string type without widening either consumer. A conservative compiler match only delegates, where literal parsing still wins. The behavioral caller invokes the predicate only after literal parsing fails. The replacement docblock must also state both ordering invariants: validation outcomes require parse-first ordering, while compile-time false positives are safe because they only select delegated execution. This avoids an extra parse on every non-cached compilation while keeping one authoritative field-reference predicate.

Keep the two boundaries fed by validation data or resolved field values defensive and the actual parser narrow:

```php
protected function getDateTimestamp(mixed $value): ?int
{
    if (! $value instanceof DateTimeInterface
        && ! is_float($value)
        && ! is_int($value)
        && ! is_string($value)) {
        return null;
    }

    return $this->getDateTime($value)?->getTimestamp();
}

protected function getDateTimeWithOptionalFormat(string $format, mixed $value): ?DateTimeInterface
{
    if (! $value instanceof DateTimeInterface
        && ! is_float($value)
        && ! is_int($value)
        && ! is_string($value)) {
        return null;
    }

    if (! $value instanceof DateTimeInterface
        && ($date = DateTime::createFromFormat('!' . $format, (string) $value))) {
        return $date;
    }

    return $this->getDateTime($value);
}
```

`getDateTime()` takes only `DateTimeInterface|float|int|string` and is reachable only after that predicate. `checkDateTimeOrder()` widens both value operands to `mixed`, and the compiled formatted path passes the original value into `getDateTimeWithOptionalFormat()` rather than casting before the boundary; update its inline parameter PHPDoc to `target: mixed`. Remove the now-duplicated front-door type guard from `compareDates()`, whose delegated operand checks replace it. For no-format validation data and referenced-field values, pass numeric values to `Date::parse()` unchanged so integers retain Unix-timestamp meaning and numeric strings retain date-string meaning. Comparison-rule parameters retain Laravel's string-rule/array-rule equivalence because dependent-rule preprocessing normalizes both to strings. For formatted rules, cast only at `createFromFormat()` and give the supported original value to fallback parsing. Arrays and unsupported objects therefore return null at both data-fed conversion boundaries.

The compiled executor has no field-resolution branch, so replace its front-door guard with explicit operands in the unformatted branch rather than deleting it without replacement:

```php
if ($param['format'] !== null) {
    $current = $this->getDateTimeWithOptionalFormat($param['format'], $value);
    $target = $this->getDateTimeWithOptionalFormat($param['format'], $param['target']);

    return $current !== null
        && $target !== null
        && $this->compare($current, $target, $operator);
}

$current = $this->getDateTimestamp($value);
$target = $this->getDateTimestamp($param['target']);

if ($current === null || $target === null) {
    return false;
}

return $this->compare($current, $target, $operator);
```

This preserves the compiled path's existing rejection of unsupported current values and also rejects invalid literal targets uniformly before `compare()`.

`checkDateTimeOrder()` follows the same policy without treating an unclassified literal as an attribute:

```php
$current = $this->getDateTimeWithOptionalFormat($format, $value);

if ($current === null) {
    return false;
}

$target = $this->getDateTimeWithOptionalFormat($format, $argument);

if ($target !== null) {
    return $this->compare($current, $target, $operator);
}

if (! ValidationRuleParser::looksLikeDateFieldReference($argument)) {
    return false;
}

$fieldValue = $this->getValue($argument);

if ($fieldValue === null) {
    return true;
}

$targetFormat = $this->getDateFormat($argument) ?: $format;
$target = $this->getDateTimeWithOptionalFormat($targetFormat, $fieldValue);

return $target !== null && $this->compare($current, $target, $operator);
```

The target attribute's own date-format lookup therefore occurs only after syntactic classification. Literal targets use the current attribute's format and never trigger an attribute-rule lookup. This reordering is behavior-neutral: a referenced field still uses its own format, while applying that format to the preceding failed literal attempt never affected an outcome.

Port Laravel's guarded equality arm on the protected comparison extension point:

```php
'=' => ($first === $second)
    || ($first == $second && $first !== null && $second !== null),
```

The new operand guards are what correct the reachable null-equality defect. Once they hold, the guarded arm has the same result as loose equality for reachable non-null operands; do not claim or test the arm alone as the defect fix.

The owner-approved missing/invalid policy is uniform across before, before-or-equal, after, after-or-equal, and date-equals:

- invalid current value fails;
- invalid non-field literal fails;
- an absent or null referenced field passes because presence belongs to `required`;
- a present parseable field compares;
- a present invalid/unsupported field fails;
- numeric and DateTime values compare without a native error;
- arrays and unsupported objects fail validation rather than throw.

Cover all five operators in base and subclass Validators, formatted and unformatted numeric values, literals/fields, escaped-dot fields, wildcard fields for `date_equals`, absent/null/empty/invalid/array field values, a formatted referenced field whose value is an array, DateTime values, epoch equality, the explicit `!!!` counterexample, and invalid current data paired with an absent referenced field. Cover integer and Stringable array-form `date_format` context, a malformed format on an absent attribute, and failed integer-format message rendering. Do not add a generic Laravel differential harness or remove date compilation.

### 7. Port bounded current APIs (`validation-16`, `validation-17`, `validation-26`)

Port `validateBase64()` in current upstream order and keep it delegated:

```php
if (! is_string($value) || $value === '') {
    return false;
}

$decoded = base64_decode($value, true);

return $decoded !== false && base64_encode($decoded) === $value;
```

Add the English message and concise Boost entry. Do not add a compiler enum/arm for an unproven hot rule.

Port `Password::toPasswordRulesString()` in upstream order, preserving its fluent state and exact HTML `passwordrules` output. Cover minimum, maximum, mixed case, letters, numbers, and symbols, and add the concise public example before default-password guidance.

Port Laravel's full DNS-faking test API:

```php
protected static bool $fakeDnsLookups = false;

public static function fakeDnsLookups(bool $value = true): void
{
    static::$fakeDnsLookups = $value;
}
```

Add Factory/facade forwarding, `FakeDnsGetRecordWrapper`, the `active_url` branch, and the Egulias `email:dns` wrapper. Reset the flag inside the existing `Validator::flushState()` already registered with test cleanup; do not add another cleanup registry. Port focused active URL, email DNS, toggle, Factory, facade, and reset coverage.

### 8. Correct Factory, facade, and public lifecycle metadata (`validation-07`, `validation-18`, `validation-19`)

Return `?PresenceVerifierInterface` from `Factory::getPresenceVerifier()` and update generated facade metadata. Do not synthesize a verifier for a directly constructed Factory.

Remove the concrete `Validator` `@see` from `Hypervel\Support\Facades\Validator` and regenerate it through the facade documenter. The resulting facade exposes Factory methods—including DNS faking—but not `passes()`, `safe()`, or concrete `validate*` methods. Add a documenter regression proving both presence and absence; do not add Factory forwarding.

Add concise boot-only warnings to:

```text
Factory::includeUnvalidatedArrayKeys()
Factory::excludeUnvalidatedArrayKeys()
Factory::resolver()
Factory::setPresenceVerifier()
```

Each warning must say the change persists for the worker lifetime and affects subsequent requests. Factory remains a singleton; no request-scoped clone, context slot, or lock is added.

### 9. Correct split ownership and public typing (`validation-22`–`validation-25`)

In `src/validation/composer.json`, declare the direct dependencies actually loaded by Validation:

```json
"hypervel/context": "^0.4",
"hypervel/console": "^0.4",
"symfony/http-foundation": "^8.1"
```

Keep `hypervel/database` required. `symfony/console` is already direct. Do not require Foundation and create a cycle; add:

```json
"suggest": {
    "hypervel/foundation": "Required to use ValidatesWhenResolvedTrait with precognitive requests."
}
```

Pin the manifest with the conventional Validation package metadata test.

`ValidatesWhenResolvedTrait::passesAuthorization()` returns `bool` and defaults to `true`; remove the Auth `Response` dependency. Foundation's `FormRequest` override remains `bool|Response` and performs the response authorization, so Laravel's FormRequest API and messages/status stay intact. Search every trait user and cover raw trait denial plus existing FormRequest behavior.

Give `src/validation/README.md` the canonical package shape: retain its title and badge, add `Documentation: https://hypervel.org/docs/validation`, create `## Differences From Laravel`, add the approved strict scalar-membership difference, finish with `Ported from: https://github.com/laravel/framework`, and restore the trailing newline. Add truthful conditional PHPDocs for `safe()` and the configured Email/File/Password default setters, retaining Hypervel's real nullable return behavior and relevant `@throws` declarations. Restore current Laravel's exact parameter PHPDocs on `validateBoolean()`, `validateInteger()`, `validateNumeric()`, `validateImage()`, `validateUrl()`, `validateTimezone()`, `validateInArrayKeys()`, and `validateEncoding()`, plus Encoding's `InvalidArgumentException` declaration; retain their correct native `array` parameters.

Apply strict comparison only where operand domains are already proven:

- presence counts against integer zero;
- `gettype()` and message-key strings;
- size results after explicit numeric normalization;
- the accepted hash, equality, and membership fixes above.

Retain intentional parameter coercion in dimensions, decimal, and digit APIs; non-strict exclusion/dependent/distinct behavior; and nullable MIME/extension domains until a separate trace proves a defect. In particular, keep `Validator`'s `in_array('array', $rules) || in_array('list', $rules)` non-strict: `$rules` may contain a `Rules\ArrayRule` object whose `__toString()` is `array`, and strict membership would stop `excludeUnvalidatedArrayKeys` from recognizing it. No mechanical package rewrite.

### 10. Restore test lifecycle ownership (`validation-20`, `validation-21`)

Change exactly these direct PHPUnit classes to `Hypervel\Tests\TestCase`:

```text
ValidationAddFailureTest, ValidationArrayRuleTest,
ValidationContainsRuleTest, ValidationDatabasePresenceVerifierTest,
ValidationDimensionsRuleTest, ValidationDoesntContainRuleTest,
ValidationExceptionTest, ValidationExcludeIfTest, ValidationFactoryTest,
ValidationForEachTest, ValidationInRuleTest, ValidationInvokableRuleTest,
ValidationMacroTest, ValidationNotInRuleTest, ValidationNumericRuleTest,
ValidationProhibitedIfTest, ValidatorAfterRuleTest
```

Call `parent::setUp()` in touched custom setups and add `void` to touched test methods. Do not create a Validation-specific base or duplicate the global Rule-macro reset.

In `DateFormatValidationTest` and `ValidationValidatorTest`, own PHP's process-global timezone directly:

```php
private string $originalTimezone;

protected function setUp(): void
{
    $this->originalTimezone = date_default_timezone_get();
    parent::setUp();
}

protected function tearDown(): void
{
    try {
        date_default_timezone_set($this->originalTimezone);
    } finally {
        parent::tearDown();
    }
}
```

Use unconditional restoration and prove the prior value returns. Do not add timezone to global framework cleanup or hardcode UTC as a reset value.

### 11. Documentation and audit records (`validation-15`–`validation-18`, `validation-24`)

Keep public documentation concise and Laravel-style:

- add `base64` to the Strings category list and add its anchored entry between `bail` and `before`; add the matching `'base64'` line to the English validation translation;
- explain `Password::toPasswordRulesString()` at the existing Password rule surface;
- port current Laravel's concise DNS-lookup testing paragraph at the `active_url` rule for `active_url` and `email:dns`;
- record under `Differences From Laravel` that scalar `in` / `not_in` compares literal strings strictly;
- record under `Differences From Laravel` the uniform missing/null and invalid date-comparison policy;
- do not publish internal compiler, cache, or batching details.

Update the core plan routing to Validation while active. After implementation, add one compact ledger work unit, amend the completed Support entry for the regenerated Validator facade, and add only genuine cross-package dependency-index rows. Record that current Laravel's PR `#59339` remains incomplete at two array-message escape paths and regresses wildcard type-specific size messages, that Laravel omits `DateEquals` from dependent-rule preprocessing, and that its max-ratio regression passes an ignored third argument to a two-parameter method. Recommend upstream reports without making them part of this branch. Revalidate `validation-01` and `support-29`; do not mark Validation complete until implementation, full gates, fresh self-review, code-review sign-off, owner checkpoint, and bookkeeping commit are complete.

## Regression matrix

| Area | Required proof |
|---|---|
| Cache | Direct and cross-Validator collision; LRU capacity and invalid sizes; static reset unchanged. |
| Presence batching | Injected resolver, connections, write PDO, conditions, ignores, chunks, arrays, unsupported fallback, query count, verifier restoration. |
| Optimizer | Validator-aware mutation defeats neither `unique` nor `exists`; ordinary eligible batches remain batched. |
| Messages | Exact/wildcard scalar messages, type-specific size maps, missing rule entries, falsey fallback semantics, date-field reference rendering, translation fallback, and all nine documented integer-parameter replacers. |
| Password/email/images | Magic hash; CR/LF in every execution path; min/max ratios around tolerance and valid fluent range fixtures. |
| Compiled parity | Guarded type matrix, current lowercase/uppercase override arity, array `not_in`, object-safe raw-array and fluent-rule strict scalar membership, fluent enum message rendering, malformed digit parameters, base/subclass equivalence. |
| Dates | Five operators, literals/fields, escaped-dot, hyphenated, digit-leading, and wildcard fields, both comparison directions, absent/null/invalid/empty/unsupported field values, invalid-current plus absent-field ordering, castable format context, numerics, DateTime, epoch equality, and preserved inlining for common ISO/pure-numeric literals. |
| APIs | Base64 canonicality; passwordrules output; DNS faking through Validator, Factory, facade, active URL, email DNS, and reset. |
| Public metadata | Nullable verifier; generated facade exposes only Factory; README/docs/PHPDocs; split manifest. |
| Trait ownership | Raw trait boolean authorization and Foundation FormRequest Response behavior. |
| Test lifecycle | Framework base adoption and exact timezone restoration. |
| Prior decisions | Configured default prototype isolation and single-label URL validation remain green. |

Run each changed test file immediately. Then run focused Validation unit/integration tests, facade-documenter and affected Support/Contracts/Foundation coverage, package metadata tests, relevant Boost checks, and `composer fix`.

## Rejected concerns and prohibited designs

- No cache lock, collision registry, secondary comparison, hash layer, zero-capacity mode, or unbounded cache.
- No recursive batch-value flattener, arbitrary custom-verifier batching, batch method on the public verifier contract, mutable per-query connection state, resolver registry, or extra query.
- No generic value/date/comparison normalizer, comparison policy, differential harness, or new parser class/trait.
- No parser-wide or replacer-wide parameter stringification, message-renderer guard, or machinery for unsupported raw array-form enum/object rule parameters.
- No compile-time date parsing or Date-factory-dependent plan selection to optimize uncommon ambiguous literal shapes.
- No port of Laravel PR `#59339`'s incomplete message branch.
- No removal of compiled date checks, no Base64 compiler arm, and no widening of optimization to ambiguous rules.
- No request-local Factory, lock, context slot, synthetic verifier, or facade forwarding shim.
- No Foundation dependency cycle, trait relocation, or `class_exists()` guard.
- No global timezone cleanup or Validation-specific test base.
- No mechanical strict-comparison sweep through domains whose coercion is intentional or unproven.
- No recursive default-rule graph cloning; completed `validation-01` remains bounded to executable nested rule objects.

## Verification and fresh self-review

Before review request:

1. run every changed test file as it is completed;
2. run the complete Validation unit and integration groups plus every affected cross-package group;
3. regenerate and verify the Validator facade;
4. run metadata and documentation checks;
5. run `composer fix` and inspect every skip/failure normally reported;
6. run `git diff --check` and stale-symbol/comment/dependency scans;
7. freshly trace every changed caller/callee, compiled/delegated equivalence, resolver owner, fallback path, static reset, public/named-argument surface, and test teardown;
8. assess allocations, serialization, DB calls, retained memory, locks, yields, retries, container resolutions, and branch frequency;
9. remove superseded helpers, imports, annotations, tests, comments, and docs;
10. request independent code review and loop until sign-off.

The final result must contain no unresolved accepted finding, TODO, compatibility shim, stale path, workaround, speculative abstraction, unintended Laravel API break, or meaningful hot-path regression.
