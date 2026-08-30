# JSON Schema and Contracts Remediation Plan

Status: Implemented and verified.

## Objective

Correct audit findings 95–98 without weakening native typing, expanding Hypervel's supported JSON Schema subset dishonestly, or adding stateful machinery. Preserve every public Laravel API. The only API-shape change is an optional parameter on two protected `Deserializer` methods so one prepared composition can flow through the existing pipeline without hidden state or repeated reference expansion.

Finding 94 is rejected. It has no implementation in this plan: every conforming implementation of `Contracts\JsonSchema\JsonSchema` must produce classes owned by `hypervel/json-schema`, so an implementation without that package is not a supported or useful state. Removing the native returns would reverse Hypervel's approved contract typing and create the only untyped interface among many contracts that intentionally reference concrete component types. A reverse dependency from `hypervel/contracts` would create a package cycle without enabling a real use case.

## Verified baseline

- Before this remediation, Hypervel and current Laravel 13.x both dispatched scalar schema types with `match (get_class($type))`, although the same serializer already used `instanceof` for `AnyOfType`, `ObjectType`, and `ArrayType` behavior.
- Every built-in concrete type is open. The audited exact-class dispatch therefore threw `RuntimeException` for a `StringType` subclass despite it satisfying the public type hierarchy.
- The audited `Deserializer::resolveRef()` overlaid sibling arrays with `array_merge($resolved, $schema)`. A conflicting sibling could replace `type`, `enum`, `required`, `properties`, or a scalar constraint and make the reconstructed schema accept values rejected by the referenced schema.
- JSON Schema 2020-12 defines `$ref` as an applicator whose siblings also apply. Replacement is not conjunction. Hypervel's fluent types cannot represent every conjunction, so conflicting represented assertions must fail rather than be weakened.
- The audited `normalizeUnions()` rejected all differing overlapping values, including harmless annotations, while `$ref` permitted every replacement. These were two implementations of the same fragment-merge rule and needed one policy.
- Nullable `anyOf` branches were resolved once by `buildAnyOfComposition()` and again by `normalizeUnions()`. A one-reference nullable schema performed two lookups and charged three nodes; the equivalent inline schema charged one node. With `MAX_NODES = 2`, the referenced schema was falsely rejected.
- Object properties and composition branches rejected every non-array fragment with a message claiming it was a boolean schema. Objects, strings, integers, and null therefore received a false diagnosis.
- Array `items` collapsed boolean schemas, tuple arrays, and every other malformed fragment into one “true or a single object schema” error. It therefore misdiagnosed `false` and gave non-array values no useful type context.
- Laravel retains the exact-class serializer dispatch, replacement-style `$ref` merge, and repeated nullable-branch resolution. Hypervel's repeated resolution becomes a correctness failure because Hypervel adds aggregate reference-expansion accounting.
- `docs/todo.md` contains no related JSON Schema or contracts work.

## Design

### 1. Preserve the contract types and package graph

Do not change `src/contracts/src/JsonSchema/JsonSchema.php`, either package's Composer metadata, service bindings, or providers.

The contract is useful to consumers as a parameter type without resolving its return classes. A factory implementation, however, must construct the declared `Types\*` results and therefore operates with `hypervel/json-schema` installed. The existing dependency direction remains correct:

```text
hypervel/json-schema -> hypervel/contracts
```

Do not add the reverse edge or erase native types to make an unsupported standalone implementation possible.

### 2. Dispatch serializer types polymorphically

In `Serializer::serialize()`, retain the early `AnyOfType` handling, then replace exact-class matching with the existing Laravel-style `match (true)` pattern:

```php
$attributes['type'] = match (true) {
    $type instanceof Types\ArrayType => 'array',
    $type instanceof Types\BooleanType => 'boolean',
    // Existing built-in order continues here...
    default => throw new RuntimeException('Unsupported [' . get_class($type) . '] type.'),
};
```

The built-in hierarchy is flat, so there is no “most specific first” rule to invent. Keep the current order for reviewability and preserve the unknown abstract `Type` failure.

This makes subclassing useful rather than nominal:

- subclasses of every supported type serialize through their parent route;
- subclass-owned JSON Schema keywords exposed through the existing object-attribute serializer are retained;
- a `Serializer` subclass can continue extending protected static `$ignore` to suppress internal subclass properties.

Keep `$ignore` as a protected static extension seam. Converting it to a constant would break subclasses that extend the ignored-property set. Do not mark types final, add a registry, or add an exact-class fast path plus a second fallback dispatch. The bounded `instanceof` chain is the simplest correct extension behavior.

### 3. Prepare composition branches once

Add a protected composition preparer with this shape:

```php
protected function prepareComposition(array $schema, array $refs = []): ?array;
```

It chooses `anyOf` before `oneOf`, preserving current precedence. It returns `null` only when neither keyword exists; a present but malformed composition throws rather than sharing the no-composition state. Otherwise it:

1. requires a non-empty branch array;
2. validates each branch through the shared fragment guard described below;
3. resolves each branch's reference chain exactly once;
4. separates bare-null branches from non-null `[schema, refs]` tuples; and
5. returns a locally typed array containing the keyword, resolved branches, and null-branch count.

Conceptual shape:

```php
array{
    keyword: 'anyOf'|'oneOf',
    branches: array<int, array{0: array<string, mixed>, 1: array<int, string>}>,
    nullBranches: int,
}
```

`build()` computes this value after root reference resolution and unsupported-assertion validation, then passes it to both existing composition stages. When neither composition keyword exists, `build()` skips nullable normalization instead of repeating the no-composition check. Add an optional prepared-composition parameter to:

```php
protected function buildAnyOfComposition(
    array $schema,
    array $refs = [],
    ?array $composition = null,
): ?Types\AnyOfType;

protected function normalizeUnions(
    array $schema,
    array $refs = [],
    ?array $composition = null,
): array;
```

Each method prepares the composition itself when called without the new argument, preserving direct `parent::` calls. `buildAnyOfComposition()` still returns immediately when the schema has no `anyOf`; it cannot consume a prepared `oneOf`. `build()` supplies the shared tuple, so its normal path resolves every branch once.

Use one small `describeBranch()` helper for the article and keyword text shared by fragment diagnostics and merge-conflict contexts.

This protected signature change is deliberate and owner-approved. An existing subclass override must add the optional parameter or PHP fails immediately at class declaration. That loud migration is preferable to hidden handoff state, which can silently combine branches prepared from a subclass-modified schema with the original schema still held by `build()`.

Do not add a per-instance slot, stack, schema hash/cache, second reference-peek traversal, compatibility wrapper that `build()` bypasses, or production instrumentation.

Preserve these current behaviors and ordering rules:

- general `anyOf` builds all prepared non-null branches;
- nullable single-schema `anyOf` and `oneOf` collapse through `normalizeUnions()`;
- duplicate bare-null branches remain allowed for `anyOf` and rejected for `oneOf`;
- branch-local enums and null-overlap rules remain unchanged;
- nullable-composition structural failures follow the merge ordering defined in section 5;
- unsupported assertions merged from a branch still fail through `ensureAssertionsAreSupported()`;
- reference cycle, depth, missing-target, and total-expansion guards keep their existing meaning.

### 4. Validate schema-fragment shapes accurately

Add one protected helper used by `buildObject()`, `buildArray()`, and `prepareComposition()` to validate property, item, and composition fragments:

```php
protected function ensureSchemaFragmentIsArray(mixed $fragment, string $context): array;
```

The context is a human-readable phrase such as `property [name]`, `the [items] keyword`, `an anyOf branch`, or `a oneOf branch`.

- For `true` or `false`, use the existing contextual “boolean schemas are not supported” message form.
- For another non-array, say that the schema fragment must be an array and include `get_debug_type($value)`.
- Return arrays unchanged.

Keep the existing `stdClass` allowance only for the `properties` container. It exists so the deserializer can round-trip the serializer's JSON object representation; it does not make nested schema fragments object-valued. The public `deserialize(array $schema)` root remains array-only.

For `items`, keep `true` and `[]` as the existing unconstrained forms. Pass every other value through the shared guard, then retain the existing tuple/list rejection before building a single associative schema. This gives `false` the boolean-schema error, gives other non-arrays their actual type, and preserves the tuple message.

Do not add partial `stdClass` conversion or broaden the public input contract.

### 5. Merge represented fragments without weakening assertions

Add one protected fragment-merging helper used by `resolveRef()` and `normalizeUnions()`. It takes a base fragment, an outer/sibling overlay, and a context string for errors.

For each overlapping key:

- identical values are accepted;
- represented annotations `title`, `description`, and `default` use the outer/sibling value;
- differing represented assertions throw `InvalidArgumentException` naming the keyword and context;
- unknown or deliberately ignored keywords remain no-ops; this preserves `$ref` behavior and removes nullable composition's false conflicts over unrepresented keywords.

Detect key presence with `array_key_exists()`, never `isset()`. Explicit `null` defaults are supported and must participate in identical-value checks and outer annotation precedence.

The represented assertion set is derived from `TYPE_SPECIFIC_KEYWORDS` plus `type`, `enum`, `anyOf`, and `oneOf`; do not duplicate the type-specific list. Keep `format` in this conflict set even though JSON Schema 2020-12 treats it as an annotation by default: `StringType` can retain only one format, so accepting different values would silently discard represented schema information. `UNSUPPORTED_ASSERTION_KEYWORDS` do not need a second policy because the existing post-merge assertion guard rejects them.

Call direction is load-bearing:

```php
$merged = $this->mergeSchemaFragments(
    base: $resolvedOrBranch,
    overlay: $siblings,
    context: $context,
);
```

This keeps outer annotations authoritative through every reference-chain level and changes nullable normalization's previously unobservable branch-wins `array_merge` direction to the same sibling-wins rule.

Before the nullable-composition merge, inspect the union of sibling and branch keys for a surviving `anyOf` or `oneOf` and throw the existing structural message:

```text
Structural keywords [oneOf] are not supported alongside a nullable "anyOf".
```

The merge helper uses this conflict message shape:

```text
Conflicting [type] between an anyOf branch and its sibling keys.
```

The keyword and supplied context vary. A local reference reports, for example, `Conflicting [type] between the local $ref [#/$defs/name] target and its sibling keys.` This intentionally corrects Laravel's `a "anyOf" branch` grammar while keeping the familiar message shape.

Absent assertions from either side are combined because both still apply. Differing assertions are not combined keyword by keyword: while `required` lists could be unioned, keywords such as `pattern`, `properties`, `enum`, and nested compositions need real conjunction semantics that one fluent `Type` cannot preserve uniformly. Do not add partial `allOf`, keyword-specific arithmetic, a vocabulary registry, or another schema engine.

## Documentation

Update `src/docs/json-schema.md` in both relevant sections:

- **Local References:** explain that annotation siblings may override referenced annotations, absent or identical represented assertions are accepted, and conflicting represented assertions throw because one fluent type cannot safely represent their conjunction. State that overlapping assertion values must match strictly, including array order; normalizing keyword-specific semantics is intentionally not part of fragment merging.
- **Supported Schema Subset:** replace the claim that every differing nullable-composition keyword is rejected. State that outer annotations override branch annotations while conflicting represented constraints remain unsupported.

Add one concise `src/json-schema/README.md` difference because this correction changes accepted input and therefore requires action when porting a Laravel schema:

> Hypervel applies one merge policy to local `$ref` siblings and nullable composition branches: outer annotations override, and conflicting assertions are rejected rather than silently replacing the referenced constraint.

Add one concise `src/docs/porting-from-laravel.md` entry directing porters to make overlapping assertions identical and linking to the canonical JSON Schema documentation. Do not duplicate the detailed merge rules there.

Do not add findings 95, 97, or 98 to the README. They do not change an actionable Laravel application surface. Keep detailed behavior in the canonical documentation rather than duplicating it in the README.

## Tests

### `tests/JsonSchema/SerializerTest.php`

- Prove subclasses of every serializer-supported built-in type use their parent serialization route.
- Use one subclass-owned schema keyword to prove the extension is useful, not merely accepted.
- Prove a serializer subclass extending `$ignore` suppresses that internal property.
- Keep the existing anonymous unknown `Type` exception test.

Run this exact file immediately after editing it:

```bash
./vendor/bin/phpunit --no-progress tests/JsonSchema/SerializerTest.php
```

### `tests/JsonSchema/DeserializerTest.php`

Reference merge coverage:

- missing and identical assertions merge;
- direct and chained annotation siblings use the outer value;
- an outer `default => null` overrides a differing target default, and matching null defaults are identical;
- differing `type`, scalar constraints (including `format`), `enum`, `required`, and `properties` throw with the keyword and `$ref` context;
- a chained reference with different assertions at successive levels throws instead of letting the outermost value weaken every target;
- unsupported assertions and surviving nested compositions still reach their existing guards;
- rename the chained-reference test so it describes annotation precedence rather than universal sibling precedence.

Nullable composition coverage:

- outer `title`, `description`, and `default` override differing branch annotations for both `anyOf` and `oneOf`;
- an outer `default => null` overrides a differing branch default, and matching null defaults are identical;
- absent and identical constraints merge;
- conflicting represented constraints throw with corrected grammar;
- differing unknown or ignored keywords are ignored rather than treated as composition conflicts;
- a composition keyword present on both the branch and its siblings receives the structural-composition error before assertion-conflict handling;
- existing enum ownership, null-overlap, duplicate-null, general-`anyOf`, nested-composition, and structural-sibling tests remain green.

Single-resolution coverage:

- add a test-only deserializer subclass with `MAX_NODES = 2` and a `deserializeWithCounts()` helper that constructs the protected instance and returns the result with lookup/node counts;
- the nullable schema containing one local `$ref` passes, performs one lookup, and charges two nodes (root plus one reference follow);
- the equivalent inline schema passes with no lookup and one node;
- no production counters, widened visibility, static test state, large fixtures, or timing assertions.

Fragment diagnostics:

- actual boolean property, item, and composition fragments use the boolean-schema error;
- representative object and scalar fragments report that an array is required and include their debug type;
- cover property, `items`, `anyOf`, and `oneOf` contexts without multiplying equivalent cases unnecessarily;
- prove malformed `items` still reaches its owning guard when `type` is inferred;
- tuple/list `items` retain the single-object-schema error, while `true` and `[]` remain accepted as unconstrained items;
- valid array fragments and `stdClass` property containers remain accepted.

Run this exact file immediately after editing it:

```bash
./vendor/bin/phpunit --no-progress tests/JsonSchema/DeserializerTest.php
```

Then run the focused package directory:

```bash
./vendor/bin/phpunit --no-progress tests/JsonSchema
```

## Files

- `src/json-schema/src/Serializer.php`
- `src/json-schema/src/Deserializer.php`
- `tests/JsonSchema/SerializerTest.php`
- `tests/JsonSchema/DeserializerTest.php`
- `src/docs/json-schema.md`
- `src/docs/porting-from-laravel.md`
- `src/json-schema/README.md`
- this focused plan

No contract, Composer metadata, service provider, binding, facade, configuration, worker state, or coroutine state changes.

## Implementation order

1. Update `SerializerTest.php`, run it to reproduce #95, change `Serializer.php`, and rerun the file.
2. Add the single-resolution and malformed-fragment tests to `DeserializerTest.php`; run it to reproduce #97/#98.
3. Implement composition preparation, the optional protected parameters, and the fragment guard; rerun `DeserializerTest.php`.
4. Add safe fragment-merge tests, implement the shared merge policy, and rerun `DeserializerTest.php`.
5. Run the complete JSON Schema test directory.
6. Update canonical docs and the concise README difference, then verify every claim against final source.
7. Update the two active plan documents so no superseded or duplicate master-plan work remains.
8. Run `composer fix` once as the repository checkpoint.
9. Review the complete diff, including every changed caller/callee and protected extension point, for stale code, duplication, hidden state, API drift, and avoidable work.

## Performance and compatibility checks

- Confirm nullable referenced compositions perform one lookup and one reference charge rather than two.
- Confirm no new per-instance cache, worker/static state, locks, I/O, hashing, serialization, or unbounded collection is introduced.
- Confirm all public Laravel method signatures remain unchanged.
- Confirm the two protected signature changes remain limited to optional prepared-data parameters and that direct `parent::` calls without them still work.
- Confirm unknown/ignored JSON Schema keywords remain no-ops on both fragment-merge paths and recognized unsupported assertions still fail.

## Completion criteria

- Finding 94 has no code change and is recorded as rejected for the scoped factory-contract reason.
- Supported `Type` subclasses serialize; unrelated `Type` subclasses still fail.
- `$ref` and nullable-composition merges share one clear assertion/annotation policy and cannot silently weaken represented constraints.
- Composition branches resolve once, and the node budget counts real expansion rather than repeated internal work.
- Malformed property, item, and composition fragments receive truthful, contextual errors without partial object-schema support.
- Canonical docs and the one actionable README difference match final behavior.
- No hidden handoff state, cache, registry, partial conjunction engine, duplicated policy, stale plan row, or unnecessary test remains.
- Targeted tests, the JSON Schema suite, and `composer fix` pass.
