# JSON Schema Correctness, Current Parity, and Bounded Reconstruction

## Scope and outcome

Bring `hypervel/json-schema` to the current Laravel 13.x API while fixing the verified serializer and deserializer defects in both implementations. The final package must:

- retain its operation-local, allocation-only design with no container, coroutine, cache, lock, or worker-reset machinery;
- expose current `unique`, reversible flags, `fromArray`, union, and any-of APIs;
- reconstruct only schemas the builder can preserve, throwing instead of silently weakening recognized assertions;
- bound aggregate reference expansion and active reference paths without recursive reference-frame copying;
- emit correct JSON object shapes for numeric property names and object defaults;
- preserve valid empty enums and scalar or array-form null-only schemas while rejecting malformed or finally-empty compositions;
- preserve explicit null defaults without confusing them with an unset default;
- fail with the original JSON encoding exception rather than returning a blank schema;
- document the package, its supported reconstruction subset, and its deliberate Laravel-facing differences in Laravel-docs prose.

References verified for this design:

- Hypervel `de04fad613a8158750d6f14af5677c03587f5170`: the complete JSON Schema package, contract, tests, Composer metadata, documentation index, and repository callers;
- Laravel framework `deac04fbdcd7443aff45a7bc6767a6729169bba3`: current `Illuminate\JsonSchema` source and tests;
- originating Laravel changes #58922, #59688, #60149, #60239, #60384, #60455, #60509, #60517, and #60524;
- JSON Schema 2020-12 validation metaschema and Opis behavior for property maps, compositions, defaults, and enums;
- focused reference-chain, lossy-reconstruction, wire-shape, and encoding-failure probes.

The current package has no provider, binding, configuration, callback, resource, external service, mutable static runtime state, or runtime consumer. `JsonSchema::__callStatic()` creates a fresh factory, every factory method creates a fresh builder, and deserialization remains per call. That lifetime is already coroutine-safe and must remain unchanged.

## What this audit is not

The following wording is retained verbatim from the core audit plan. Its principle numbering is also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this plan” refers to that plan's **Established remediation vocabulary** section.

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

## Findings and final decisions

| ID | Category / severity | Final decision |
|---|---|---|
| `json-schema-01` | Parity defect / Major | Port current Laravel `unique`, reversible flags, `fromArray`, union, and any-of surfaces with Hypervel typing. |
| `json-schema-02` | Upstream availability defect / Critical (High confidence) | Replace recursive `$ref` following with a local loop; count each build and ref follow; cap one active root-to-node path at 256 distinct refs. |
| `json-schema-03` | Upstream reconstruction defect / Major | Reject recognized malformed or unrepresentable assertions rather than returning a weaker schema; keep unknown annotations ignored. |
| `json-schema-04` | Upstream wire-shape defect / Minor | Encode list-shaped property maps and top-level object defaults as JSON objects, with exact round-trip normalization. |
| `json-schema-05` | Upstream diagnostic defect / Minor | Use `JSON_THROW_ON_ERROR` so invalid values raise `JsonException` instead of producing `''`. |
| `json-schema-06` | Upstream composition defect / Minor | Reject only finally-empty union/any-of output and empty input `type` arrays; reconstruct null-only type arrays before inference. |
| `json-schema-07` | Documentation improvement | Add package provenance, documentation link, Boost guide, and navigation. |
| `json-schema-08` | Documentation defect / Minor | Describe `nullable()` as permitting null, not making a property optional. |
| `json-schema-09` | Approved API improvement | Widen each concrete default setter with `null` while preserving its existing non-null domain. |
| `json-schema-10` | Approved API improvement | Add fluent `default(mixed)` to union and any-of without validating annotation values against branches. |
| `json-schema-11` | Upstream reconstruction defect / Major | Reject unsupported JSON Schema 2020-12 assertions instead of silently weakening them; only a bare null branch may collapse into nullability. |
| `json-schema-12` | Upstream reconstruction defect / Major | Reject recognized keyword values that would be coerced, dropped, or deferred; preserve permissive `items: true`; reject surviving nested compositions and empty input compositions. |
| `json-schema-13` | Upstream builder defect / Major | Reject non-string direct union members instead of coercing them, warning, or raising an undocumented PHP `Error`. |

No accepted item adds request hot-path work. Builder serialization gains constant-time state checks and one property/default shape test. Deserialization work occurs only when `fromArray()` is explicitly called and is bounded more tightly than upstream.

## Implementation

### 1. Port the complete current Laravel surface

Port the current source and tests rather than historical snapshots:

- add `Deserializer`, `Types\UnionType`, and `Types\AnyOfType`;
- add `JsonSchema::fromArray()` and magic annotations;
- add `union()` and `anyOf()` to `JsonSchemaTypeFactory` and `Hypervel\Contracts\JsonSchema\JsonSchema`, with native concrete returns;
- add `ArrayType::$uniqueItems` and reversible `unique(bool $unique = true)`;
- make `required(false)` and `nullable(false)` clear their serialized flags;
- stringify numeric required property names;
- preserve Hypervel's truthful `array $arguments` on `__callStatic()`, strict types, native returns, exact-class serializer dispatch, and existing tests;
- validate direct union members in one pass before null normalization or supported-name checks; never coerce them with `strval`.

Representative factory surface:

```php
public function union(array $types): Types\UnionType
{
    return new Types\UnionType($types);
}

public function anyOf(Closure|array $schemas): Types\AnyOfType
{
    if ($schemas instanceof Closure) {
        $schemas = $schemas($this);
    }

    return new Types\AnyOfType($schemas);
}
```

`fromArray()` remains a concrete static reconstruction helper rather than a factory-contract method:

```php
public static function fromArray(array $schema): Type
{
    return Deserializer::deserialize($schema);
}
```

### 2. Model explicit defaults once

Separate the value from whether it was supplied:

```php
protected mixed $default = null;

protected bool $hasDefault = false;

protected function setDefault(mixed $value): static
{
    $this->default = $value;
    $this->hasDefault = true;

    return $this;
}
```

Each concrete setter retains its domain and adds only null, for example:

```php
public function default(string|null $value): static
{
    return $this->setDefault($value);
}
```

Use `array|null`, `bool|null`, `int|null`, `int|float|null`, and `string|null` on the existing six types.

`default(mixed $value): static` on `UnionType` and `AnyOfType` is an approved Laravel API widening. Both methods delegate to `setDefault()` because a sum schema can admit any JSON value. Do not validate defaults against member types or branches: JSON Schema treats defaults as annotations, and doing so would require a validator.

The deserializer must assign through the same helper so the value and flag cannot diverge:

```php
(fn (mixed $value) => $this->setDefault($value))->call($type, $default);
```

Use one serializer filter for both AnyOf's early path and ordinary types. It removes internal fields and retains `default => null` only when `$hasDefault` is true:

```php
protected static function filterAttributes(array $attributes): array
{
    $hasDefault = $attributes['hasDefault'];

    return array_filter($attributes, static function (mixed $value, string $key) use ($hasDefault): bool {
        if (in_array($key, static::$ignore, true)) {
            return false;
        }

        return $value !== null || ($key === 'default' && $hasDefault);
    }, ARRAY_FILTER_USE_BOTH);
}
```

Include `hasDefault` in the ignored internal fields. Do not add a sentinel object or widen the existing concrete setters to `mixed`.

### 3. Bound reference work and remove recursive copying

`Deserializer` remains one per call, with promoted root state, its existing target cache, and an active reference list passed by value between schema branches. Add:

```php
protected const MAX_NODES = 20000;

protected const MAX_REFERENCE_DEPTH = 256;

protected function countNode(): void
{
    if (++$this->nodes > static::MAX_NODES) {
        throw new InvalidArgumentException(/* existing expansion message */);
    }
}
```

Call `countNode()` once at each `build()` and once immediately before every actual `$ref` follow. `buildAnyOfComposition()` and `normalizeUnions()` resolve branches before `build()`, so ref accounting belongs inside `resolveRef()`, not only at build entry.

Replace the tail recursion with a local loop:

```php
while (array_key_exists('$ref', $schema)) {
    if (! is_string($schema['$ref'])) {
        throw new InvalidArgumentException('The JSON Schema [$ref] keyword must be a string.');
    }

    $ref = $schema['$ref'];

    if (in_array($ref, $refs, true)) {
        throw new InvalidArgumentException("Circular JSON Schema \$ref [{$ref}] detected.");
    }

    if (count($refs) >= static::MAX_REFERENCE_DEPTH) {
        throw new InvalidArgumentException(/* active-path depth message */);
    }

    $this->countNode();
    $refs[] = $ref;

    $resolved = $this->lookupRef($ref);
    unset($schema['$ref']);
    $schema = array_merge($resolved, $schema);
}
```

The depth cap means at most 256 distinct references on one active root-to-node reference path. It is not a general schema-nesting limit. The total node budget separately bounds aggregate work across wide schemas and repeated sibling refs. The loop eliminates recursive stack frames and quadratic path copying; the two guards remain necessary because they constrain different axes.

Preserve circular-reference diagnostics and the per-call lookup cache. Do not add a shared registry, graph engine, config knob, or worker cache. A memory-limit fatal is not catchable and would kill the Swoole worker and its concurrent requests, so both guards are correctness boundaries rather than speculative hardening. The recursive implementation expanded about 130 KiB of chained input to 192 MiB and died near 200 KiB at a 256 MiB limit; the iterative loop handled 6,000 links in about 8 MiB and 79 ms.

### 4. Make reconstruction truthful

Port Laravel's supported-subset deserializer, then close every recognized-loss path at that boundary.

#### Recognized keyword values

At each existing keyword read, distinguish absence with `array_key_exists()` and require the PHP type that can be preserved without coercion. Validate only representation: do not add range checks, regex compilation, cross-keyword validation, or a keyword-type table.

- `$ref`, `type`, `title`, `description`, `pattern`, and `format` must have their expected string/array shape; `type` arrays contain strings.
- `anyOf` and `oneOf` must be non-empty arrays, and every branch must be a schema array.
- `properties` accepts `array|stdClass`; `required` must be an array of strings.
- integer-valued count/length constraints pass through `toNumber()` and a range-safe `toInteger()`; numeric strings remain accepted, while fractional, nonnumeric, and out-of-range values throw.
- numeric constraints use presence checks and retain their existing number normalization.
- `uniqueItems` must be boolean.
- `items: true` and `items: []` are representable permissive forms; `false`, tuple/list schemas, and malformed values throw.

Values such as negative lengths, zero `multipleOf`, or invalid regex syntax remain unchanged because they are preserved exactly; evaluating them would turn the deserializer into a partial validator.

Type inference uses keyword-presence checks so null-valued recognized keywords reach their owning guard instead of disappearing. Out-of-range integer constraints receive a distinct, non-lossy diagnostic.

#### Object properties and required names

Accept property maps emitted by this serializer as arrays or `stdClass`, normalize them once, and build each property normally. Require string-valued required names; keep property-key normalization because PHP converts numeric property names to integer keys. Reject any required name absent from the normalized property keys. Do not silently turn a required-only object into an unconstrained object.

#### `additionalProperties`

Use `array_key_exists('additionalProperties', $schema)` so absence remains distinct from an explicitly invalid null value:

- absent, `true`, and `[]` mean the representable permissive default;
- `false` calls `withoutAdditionalProperties()`;
- a non-empty schema array, any object, `null`, or another scalar throws because the builder cannot preserve it.

Do not add schema-valued additional-property support or retain raw keywords beside the type model.

#### General `anyOf`

Extract the existing recognized type-specific keyword list to one protected constant:

```php
protected const TYPE_SPECIFIC_KEYWORDS = [
    'minLength', 'maxLength', 'pattern', 'format',
    'minimum', 'maximum', 'multipleOf',
    'items', 'minItems', 'maxItems', 'uniqueItems',
    'properties', 'required', 'additionalProperties',
];
```

The multi-type union guard uses this constant. In `buildAnyOfComposition()`, first identify and remove null branches. Preserve the existing nullable-single-schema path by returning `null` before applying the general-composition guard; `normalizeUnions()` owns those collapsed forms and must continue accepting their type-specific siblings. Only the path that will construct a real `AnyOfType` rejects the constant's keys plus `type` and `oneOf`. After nullable collapse, reject any `anyOf` or `oneOf` keyword still present in the merged fragment so siblings, inline branches, and resolved references cannot silently replace one another. Continue preserving `title`, `description`, `enum`, `default`, and nullability. Continue ignoring unknown annotations such as `$schema`, `$comment`, `readOnly`, and `contentEncoding`; this package is not a general validator.

#### Enum shape

Use `array_key_exists('enum', $schema)` and require the present value to be an array. This rejects malformed strings, objects, scalars, and null instead of dropping them. Preserve `enum([])`: JSON Schema 2020-12 intentionally permits an empty enum as an unsatisfiable schema, even though Opis imposes a stricter non-empty rule. Preserve enum order, duplicates, and complex values without recursive validation.

#### Unsupported 2020-12 assertions

Reject unsupported standard assertions before they can be dropped. Keep one protected constant containing `const`, `not`, `allOf`, `if`, `dependentSchemas`, `dependentRequired`, `prefixItems`, `contains`, `patternProperties`, `propertyNames`, `unevaluatedItems`, `unevaluatedProperties`, `exclusiveMinimum`, `exclusiveMaximum`, `minProperties`, `maxProperties`, and `$dynamicRef`. Guard the merged fragment once after `resolveRef()` and again after `normalizeUnions()`, whose collapsed branch can introduce new keys.

The presence-based guard is deliberately conservative: value-level no-ops such as a bare `if` or empty `patternProperties` also throw. Do not add keyword-specific evaluation or a general validator. Standalone `then`, `else`, `minContains`, and `maxContains` remain ignored because they have no effect without the guarded owning assertion. Standard annotations and vendor extensions remain ignored.

Only a branch whose sole key is `type` with value `"null"` or `["null"]` may collapse into outer nullability. A non-bare null branch in `anyOf` remains a real branch so supported assertions and annotations are preserved; the equivalent general `oneOf` is rejected because there is no `OneOfType`. The strict classifier and assertion guard must land together: otherwise a constrained null branch is discarded before the guard can see it. This intentionally rejects type-specific keywords on a null branch, even though those keywords are no-ops for null, for consistency with the existing null-only union constraint boundary.

### 5. Preserve valid composition semantics

An empty union or any-of builder is a legitimate intermediate state before `nullable()` adds the null member. For `UnionType`, resolve and append nullability first, then validate the final serialized member list:

```php
if ($attributes['type'] === []) {
    throw new InvalidArgumentException('A JSON Schema union must contain at least one type.');
}
```

For `AnyOfType`, serialize the branches, append its null branch when nullable, and only then apply the equivalent check. Add the composition exception to `toArray()`, `toString()`, and `__toString()` documentation.

In `resolveType()`:

- reject an explicitly present non-string/non-array `type` before inference;
- reject `type: []` explicitly before inference;
- detect scalar `"type": "null"` and null-only type arrays before inference and return an empty `UnionType` member list with nullability enabled;
- return the null-only result as an array so `build()`'s existing pre-construction `ensureUnionConstraintsAreSupported()` call also covers it; this makes `type: ["null"]` plus a recognized type-specific assertion throw rather than fabricate another type;
- preserve bare scalar and array-form null-only schemas, `union(['null'])`, `union([])->nullable()`, `anyOf([])->nullable()`, and input null-only AnyOf.

Change the deserializer's constraint diagnostic to say that type-specific keywords are unsupported on a JSON Schema union, since it also covers null-only forms. Keep `UnionType`'s unsupported-member diagnostic unchanged. The union diagnostic remains slightly abstract for scalar null input; do not add a special branch for that pathological error case. Require a “bare null branch” in the nullable `oneOf` diagnostic.

Do not add a `NullType`; the existing union model represents the valid result without another public type.

### 6. Emit JSON object shapes at the known boundaries

PHP coerces numeric-string keys, so a property map containing `"0"` or sequential `"0"`/`"1"` keys becomes list-shaped and would encode as a JSON array. After serializing object properties:

```php
$properties = array_map(
    static fn (Types\Type $property) => static::serialize($property),
    $attributes['properties'],
);

$attributes['properties'] = array_is_list($properties)
    ? (object) $properties
    : $properties;
```

Apply the same top-level shape rule to a provided `ObjectType` default only when `is_array($attributes['default'])`. A list-shaped array becomes `stdClass`; an associative map remains an array; explicit null remains null. The attribute filter runs first, so an unset default is already absent. Keep this conversion outside the properties-count branch so `object()->default([])` is corrected even when the object has no declared properties. During reconstruction, normalize only serializer-emitted `stdClass` property maps and `ObjectType` defaults back to PHP arrays.

Do not recursively reinterpret nested arrays inside object defaults. The `array<string, mixed>` signature establishes only the top-level object shape; nested values do not carry enough information to distinguish JSON arrays from objects.

### 7. Preserve JSON encoding failures

Replace the false-to-empty-string fallback:

```php
return json_encode(
    $this->toArray(),
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
);
```

Document `JsonException` on `toString()` and `__toString()`. Do not pre-scan default or enum values; the JSON encoder is the authoritative boundary and already reports malformed UTF-8, non-finite numbers, resources, and other unencodable values.

### 8. Complete documentation and provenance

Update `src/json-schema/README.md` in repository order:

1. package title;
2. `Documentation: https://hypervel.org/docs/json-schema`;
3. a concise `Differences From Laravel` section;
4. `Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/JsonSchema`.

Group the public differences, rather than writing a changelog:

- Hypervel accepts explicit null defaults and provides fluent defaults on union and any-of schemas;
- invalid JSON values raise `JsonException`, and finally-empty compositions throw instead of producing unusable output;
- `fromArray()` rejects malformed or unsupported 2020-12 assertions it cannot preserve, accepts scalar and array-form null-only schemas and permissive `items: true`, and bounds active-reference depth as well as total expansion.

Add `src/boost/docs/json-schema.md` in Laravel-docs prose and link it under **Digging Deeper**, between HTTP Client and Localization. Cover practical primitive, object, and array construction; `required()` versus `nullable()`; metadata and constraints; `unique()`, `union()`, and `anyOf()`; array/string output; `fromArray()`; local references; and the supported-subset failure boundary. State only user-actionable behavior, not serializer/deserializer internals.

Document the backed-enum form of `enum()` and distinguish `JsonException` encoding failures from the `InvalidArgumentException` raised by finally-empty compositions in both array and string output.

Correct the `nullable()` title to say that the type may be null. Omission remains controlled by `required()`.

### 9. Keep types and tests consistent

- promote `Deserializer::$root` through its protected constructor;
- add native `Types\IntegerType|Types\NumberType` to `applyNumericBounds()` while retaining its generic docblock;
- add `: void` to every JSON Schema test method;
- preserve all existing Hypervel tests while porting current Laravel `AnyOfTypeTest`, `DeserializerTest`, and `UnionTypeTest` plus current additions to existing test classes.

No package base test case, integration service, coroutine interleaving, subprocess, or static cleanup hook is warranted.

## Regression coverage

Run each changed or new test file immediately. The final package suite must cover:

1. current factory/contract/magic APIs, `unique()`, numeric required-name normalization, and all reversible flags;
2. union and any-of construction, closures, metadata, nullable forms, final-empty rejection, and fluent defaults;
3. `fromArray()` for every primitive/object/array/sum type, numeric bounds, local refs, escaped pointer segments, nullable unions, and round trips; pin a multi-link `$ref` chain where outer siblings override intermediate/target values while non-conflicting keys accumulate from every level;
4. direct and nested active-ref depth rejection, refs consuming the total budget, repeated sibling refs remaining valid, and unchanged circular-reference errors using low-limit test subclasses;
5. missing or malformed required/properties values; schema-valued/invalid `additionalProperties`; absent, `true`, and `[]` permissive `additionalProperties`; recognized assertion siblings rejected only on real general AnyOf while nullable single-schema AnyOf/OneOf siblings remain supported; malformed non-array/null enums; and ignored unknown annotations;
6. `type: []` rejection; scalar and array-form null-only reconstruction at roots, properties, items, and refs; null-only annotations; strict bare-null branch classification; and null-only type-specific assertion rejection;
7. numeric `"0"`, sequential numeric, and non-list property names producing the right PHP/JSON shapes and validating through Opis;
8. empty and numeric-list top-level object defaults, exact array/object distinction, and builder-to-array reconstruction;
9. explicit null defaults and unset-default omission for all six concrete types plus union and any-of;
10. invalid UTF-8, non-finite numbers, and resources raising `JsonException` at string conversion;
11. direct and reconstructed `enum([])` preservation without Opis validation, with one short comment explaining that Opis's non-empty rule is stricter than JSON Schema 2020-12.
12. every unsupported 2020-12 assertion keyword rejected, while standalone `then`, `else`, `minContains`, `maxContains`, standard annotations, and vendor extensions remain accepted.
13. PHP integer endpoints preserved; out-of-range integral floats, fractional/nonnumeric count and length values, and malformed recognized keyword types rejected while numeric strings remain accepted.
14. permissive `items: true` and `items: []`; rejected `items: false`; eager empty-input composition rejection; boolean `oneOf` branch rejection; and every sibling/branch/ref path that could leave a second composition after nullable collapse.
15. explicit null `type` and direct or reference-revealed non-string `$ref` values rejected at their owning boundaries rather than inferred or dropped.
16. direct union construction rejects every non-string member without warnings or PHP errors; null-valued inferred keywords reach their owning guards; range failures have an exact diagnostic; and unsupported assertions or surviving same-keyword compositions are rejected inside composition branches.

After focused files pass, run the complete `tests/JsonSchema` suite, targeted source PHPStan if needed, then `composer fix` once at the implementation checkpoint.

## Performance and lifecycle result

- Existing builder creation remains fresh and operation-local; no worker state or cleanup is introduced.
- Existing builder calls add no container lookup, lock, yield, I/O, or retained cache.
- Serialization adds only constant-time flag checks and `array_is_list()` at object-shape boundaries.
- `fromArray()` adds small local type/presence guards, a fixed-size assertion intersection before building and after an actual nullable-branch merge, and one counter per build/ref follow while replacing recursive reference copies with a lower-memory loop.
- The 256 active-path bound and aggregate node budget prevent unbounded CPU/memory amplification; no valid ordinary schema should approach either limit.

## Rejected designs

- No cached static factory, container/scoped binding, `CoroutineContext`, static reset, lock, registry, or worker cache: no shared mutable state exists.
- No general or value-aware schema validator, keyword-type table, semantic range/regex validation, recursive default/enum scan, raw keyword bag, schema-valued `additionalProperties`, or default-vs-branch validation: each would duplicate a validator or create competing state.
- No serializer cycle registry: a direct self-cycle is developer-created, fails during development, and cannot be constructed through `fromArray()`, whose circular refs already throw.
- No subtype dispatch redesign: exact-class serialization truthfully rejects unknown types and there is no documented subtype contract.
- No `NullType`: null-only output is representable through the existing union/any-of nullability model.
- No recursive object-default shape conversion: only the top-level object intent is known.
- No rejection or Opis validation of empty enums: the 2020-12 contract permits them.
- No runtime dependency change: Opis remains test-only.

## Records and completion

After implementation, validation, self-review, and code-review sign-off:

- add the final JSON Schema work unit to the companion audit ledger, including the upstream-shared defects, worker-fatal reference risk, accepted API widenings, and rejected machinery;
- set the core audit routing index to the active/completed JSON Schema work unit, add only genuine cross-package dependency rows if implementation discovers one, and check `json-schema` complete;
- record the Laravel-facing result: current APIs restored; explicit-null and sum-type defaults intentionally widened; malformed or unsupported lossy reconstruction and final-invalid output rejected; no useful Laravel API removed;
- name the unchanged Laravel 13.x defects in the owner summary so the owner can decide whether to upstream them: direct union-member coercion, unbounded recursive reference following, lossy reconstruction (including scalar null, constrained null branches, unsupported 2020-12 assertions, coerced/dropped keyword values, nested composition loss, and boolean `oneOf` branches), over-rejection of representable `items: true`, numeric object-map encoding, blank-string JSON failures, and invalid empty compositions;
- leave no TODO or deferred accepted finding.

Then provide the owner with the complete pre-commit summary required by the core audit workflow and wait for explicit approval. Do not create any source, test, documentation, ledger, or bookkeeping commit before that approval.
