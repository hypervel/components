# Lightweight Support Data Objects

## Goal

Add a small `Hypervel\Support\DataObject` for trusted internal message envelopes and per-item typed values where full Hypervel Data behavior is unnecessary. It must retain the useful outcomes of the removed mapper while fixing its correctness, API, worker-state, and maintenance problems.

This is a complement to `Hypervel\Data`, not a second version of it:

- `DataObject` maps one array into public promoted constructor properties and converts common PHP value types.
- `Data`, `Dto`, and `Resource` remain the choices for object-owned validation, input or output name mapping, contextual values, custom casts and transformers, lazy values, partials, collections, HTTP resources, persistence, Inertia, and Saloon integration.
- `DataObject` may receive input already validated by a FormRequest through Foundation's generic request-cast contract. It does not add validation or a Foundation special case.
- There is no public fast mode, feature flag, configuration, container service, provider, or bespoke integration layer.

The API is new for Hypervel 0.4. Do not preserve earlier Hypervel-specific methods or behavior merely for compatibility.

## Evidence

The existing `tests/Benchmarks/Data/compare-data-object.php` harness compares current Data with a frozen copy of the removed Support mapper. A fresh internally repeated run on the same machine produced these representative medians:

| Operation | Removed `DataObject` | Current `Data` | Ratio |
| --- | ---: | ---: | ---: |
| Flat construction, five scalars | 1.735 us | 3.869 us | 2.23x |
| Construction with defaults | 1.137 us | 3.434 us | 3.02x |
| Scalar coercion | 1.853 us | 4.987 us | 2.69x |
| One nested level | 3.788 us | 5.912 us | 1.56x |
| Three nested levels | 5.258 us | 7.390 us | 1.41x |
| Backed enum | 1.387 us | 3.769 us | 2.72x |
| 1,000 constructions | 1.747 ms | 4.041 ms | 2.31x |
| Flat uncached transformation | 0.653 us | 1.831 us | 2.81x |
| 1,000 transformations | 0.633 ms | 1.678 ms | 2.65x |

Data is highly optimized for its larger contract. These differences still matter in tight loops that need only typed mapping. The new mapper therefore has a separate narrow execution path rather than weakening Data or duplicating its feature engine.

The removed implementation also demonstrates what not to restore:

1. Its pre-expanded dependency map suppresses valid recursive types after the same class appears twice.
2. `autoResolve` and worker-global auto-casting switches change both conversion and key conventions, while retained dependency metadata is not invalidated consistently.
3. Int-backed enums reject valid numeric strings under strict types.
4. Raw scalar casts silently turn invalid values into `0`, `false`, truthy booleans, strings, or invented arrays.
5. Per-instance output caching becomes stale after ordinary public-property mutation.
6. `update()` accepts unknown keys unsafely and bypasses construction conversion.
7. Object unions arbitrarily select one arm for array hydration.
8. Numeric date strings are interpreted as Unix timestamps.
9. Nested objects require callers to know about and enable a separate resolution mode.
10. Public caches, global format settings, conversion hooks, and serializer maps expose implementation state and create a second extensible data engine.

## Public Contract

Create `src/support/src/DataObject.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
abstract class DataObject implements Arrayable, Jsonable, JsonSerializable, RequestCastable, Transient
{
    public static function from(array $data): static;

    public static function castRequestUsing(array $arguments): CastsRequestInput;

    public function toArray(): array;

    public function jsonSerialize(): array;

    public function toJson(int $options = 0): string;

    public static function flushState(): void;
}
```

The real methods receive the repository's standard concise docblocks and precise PHPStan shapes where native PHP cannot express them.

The `Transient` marker is inherited by every subclass. A DataObject is a mutable value object rather than a service and must never enter the container's implicit worker-singleton cache if application code resolves one accidentally. The marker is an existing zero-cost lifetime declaration; it does not add container construction as a supported creation path.

### Construction declarations

A supported subclass has a public or protected constructor whose parameters are public promoted properties:

```php
final class MessageEnvelope extends DataObject
{
    public function __construct(
        public readonly string $id,
        public readonly MessageType $type,
        public readonly MessagePayload $payload,
        public readonly CarbonImmutable $receivedAt,
        public readonly ?string $traceId = null,
    ) {
    }
}
```

Also support a class with no constructor and no public instance properties, inherited public promoted properties, and individual promoted `readonly` properties.

Compile-time declaration checks are limited to reachable ambiguity or data loss:

- reject any constructor parameter that is not a public promoted property;
- reject any other public non-static instance property because it would otherwise be visible data omitted from `toArray()`;
- allow protected and private instance properties as internal implementation state; they are never read, written, or serialized by `DataObject`;
- allow static properties;
- do not add a variadic guard because PHP rejects variadic promoted properties when the subclass is declared;
- do not add a readonly-class guard because PHP rejects a readonly child of this non-readonly cached base. Applications may use promoted `public readonly` properties.

Throw `LogicException` for an invalid subclass declaration and name the class, parameter or property, and required declaration shape.

A child with no constructor inherits its parent's promoted properties and constructor order. If a child declares its own constructor, every public data property must be promoted by that constructor, including any inherited property that the child redeclares. An unpromoted argument forwarded to `parent::__construct()` is intentionally unsupported because it creates two competing construction shapes. A private constructor remains inaccessible to the base implementation and fails through PHP's native constructor visibility error; do not preflight visibility that PHP already enforces.

### Input keys and presence

- Constructor parameter names are the only input keys and output keys.
- Unknown input keys are ignored, matching Data and allowing compatible message-envelope additions.
- Do not perform implicit snake-case conversion. This matches PHP named arguments and Hypervel Data's default. An application-specific named factory may adapt an external shape; full reusable mapping belongs to Data.
- If an input key exists, including with `null`, it is supplied.
- If an input key is missing and the constructor parameter has a default, omit that named argument. PHP must evaluate the default for every construction. Never retain an evaluated default object in the recipe.
- If a missing parameter allows null and has no default, pass `null`.
- Throw `InvalidArgumentException` naming the class and key for any other missing parameter. PHP's argument-count error cannot identify the source key.
- Let explicit `null` bypass conversion and reach the constructor. PHP's native type error enforces non-nullable declarations.

`from()` is the only base construction method. Do not add the old `make()` alias, `autoResolve` argument, `update()`, `refresh()`, or `ArrayAccess`. Direct public-property access is the normal PHP API, while application classes may define meaningful named factories.

### Form request casting

Implement Foundation's existing `RequestCastable` extension contract directly on `DataObject`. `castRequestUsing()` rejects declaration arguments and returns a fresh `Hypervel\Support\Http\DataObjectRequestCast` configured for `static::class`. The Support-owned caster implements only `CastsRequestInput`, preserves `null`, passes an array to the concrete class's `from()` method, and fails clearly with the input key when a present value is not an array. It contains no cache or mutable state.

This keeps Foundation generic and adds no Support dependency on Foundation or Data. A FormRequest can cast one validated object or each member of a validated list through its existing exact and wildcard paths:

```php
protected function casts(): array
{
    return [
        'contact' => Contact::class,
        'contacts.*' => Contact::class,
    ];
}

$contact = $request->validated('contact');
$contacts = $request->validated('contacts');
```

The first result is a `Contact`; the second is an array whose members are `Contact` objects. Validation still runs on submitted arrays before request casting. Do not restore `casted()`, Foundation DataObject detection, `AsDataObjectArray`, or `AsDataObjectCollection`; the current `validated()` / `safe()` APIs and wildcard cast walker provide the same outcomes through one general extension point.

## Worker-Cached Recipes

Store one private static recipe map keyed by concrete class:

```php
/**
 * @var array<class-string, list<array{
 *     name: string,
 *     kind: int,
 *     target: null|class-string,
 *     allowsNull: bool,
 *     hasDefault: bool
 * }>>
 */
private static array $recipes = [];
```

Private integer constants identify pass-through, array, boolean, float, integer, string, backed-enum, nested-data-object, and date conversion. A compact list in constructor order is both the construction recipe and the serialized-property list. Do not add recipe, property, repository, resolver, or factory classes.

Base methods access the private cache and kind constants with `self`, while `static::class` selects the concrete recipe key and `new static` constructs the requested subtype. This keeps the cache owned by DataObject even when an application subclass declares an unrelated static property with the same name.

The compiler performs one reflection pass for a class and stores only declaration facts. Named class targets are resolved through `Reflector::getParameterClassName()` so `self` and `parent` become declaring-scope class-strings before classification. It must not retain `ReflectionClass`, `ReflectionParameter`, input values, constructed objects, services, callbacks, or evaluated defaults. The key set is naturally bounded by the DataObject classes loaded and used by the application.

Compilation may autoload a referenced declaration while classifying it. The fully computed immutable recipe is published with one assignment only after classification completes. Concurrent first use may therefore compute the same recipe twice, but cannot observe a partial value or share request state; no lock or coroutine context is needed.

`flushState()` clears the recipe map as required for framework static caches and has only the standard `Flush all static state.` title docblock. Do not register it in `AfterEachTestSubscriber`: recipes contain immutable declarations and cannot leak application or request behavior between tests.

## Construction

`from()` obtains the concrete recipe, builds an associative argument array in constructor order, and invokes:

```php
return new static(...$arguments);
```

Named arguments are intentional. Omitting a missing defaulted parameter lets PHP create a fresh object default on every call; caching `ReflectionParameter::getDefaultValue()` would share one mutable default object between all instances. The measured difference between positional and named construction is about 80 ns for five parameters and does not justify incorrect or reflection-retaining machinery.

For a present non-null value, dispatch directly on the precompiled integer kind. Do not preprocess the payload, recursively compile dependency trees, allocate a context, resolve the container, or run a pipeline.

### Built-in types

Keep strict built-in conversion private to DataObject. Current Data has a separately designed and tested permissive contract, including scalar-to-array conversion. `InteractsWithData` retains Laravel's typed-accessor behavior until its already identified coherent accessor audit is implemented. A shared helper now would either change those public contracts or be a speculative one-consumer abstraction.

Already-typed values pass through before filtering. Apply these rules:

| Declared type | Accepted values | Conversion |
| --- | --- | --- |
| `int` | `int`, or a string or whole float accepted by `FILTER_VALIDATE_INT` | validated integer |
| `float` | `float`, `int`, or a string accepted by `FILTER_VALIDATE_FLOAT` | float; widen native integers |
| `bool` | `bool`, or a value accepted by `FILTER_VALIDATE_BOOLEAN` with `FILTER_NULL_ON_FAILURE` | boolean |
| `string` | `string`, another scalar, or native `Stringable` | string cast |
| `array` | `array` | unchanged |

The boolean vocabulary follows Laravel's request accessor and PHP's filter: `1`, `0`, `true`, `false`, `on`, `off`, `yes`, `no`, and the empty string, including case-insensitive string forms where PHP supports them.

Boolean values are not accepted for numeric targets. Numeric conversion accepts only the types named in the table, so objects such as numeric `Stringable` values are also rejected.

Reject all other built-in conversions with `InvalidArgumentException` naming the DataObject class, property, expected type, and supplied value or type. In particular, do not silently turn arbitrary text into zero, accept fractional integers, stringify arrays, or wrap a scalar as an array. This is conversion, not validation: it supports a fixed set of ordinary typed representations and has no rule system, field policy, or configurable behavior.

Record the intentional difference in the owner-facing summary and user documentation: invalid built-in input throws in Support DataObject, while current Data retains its tested permissive casts. Add a self-contained Framework-wide entry to `docs/todo.md` for the full typed-input accessor audit. When that audit makes `InteractsWithData` and DataObject share exact strict scalar semantics, extract their common int, float, and boolean conversion into a neutral `Hypervel\Support` primitive; separately decide whether Data should adopt it rather than changing Data as a side effect here.

### Backed enums

For a concrete backed-enum declaration:

- return an existing instance of the declared enum unchanged;
- otherwise call the existing neutral `Hypervel\Support\enum_from()` helper;
- let its `ValueError` propagate for an invalid backing value;
- preserve valid numeric-string support for integer-backed enums.

Unit enums and enum interfaces have no scalar construction rule. They use pass-through behavior and PHP enforces their declared type.

### Nested DataObjects

For a DataObject declaration:

- return an existing instance of the declared type unchanged;
- pass an array to the nested type's `from()` method;
- pass any other value through to PHP's constructor type check rather than inventing another conversion.

Each object resolves its own immediate parameters. This naturally supports arbitrary depth and recursive declarations such as a nullable linked node without a global visited set, dependency map, double construction, or retained nested input.

Relative `self` and `parent` declarations are supported and retain PHP's declaration-scope meaning, including when a constructor is inherited by a child class.

### Dates

Classify a named type as a date when it is `DateTimeInterface`, `CarbonInterface`, or a compatible implementation. For a non-null value:

1. Return it unchanged when it already satisfies the declared target.
2. For `DateTimeInterface` input, adapt it directly to a concrete target with `$target::instance($value)` for Carbon or `$target::createFromInterface($value)` for native DateTime classes. Use `Date::instance($value)` only for an interface target, where the configured date factory determines the result.
3. For actual `int` or `float` input, call `Date::createFromTimestamp($value, date_default_timezone_get())`, preserving the removed mapper and Eloquent's timestamp timezone semantics under Carbon 3. Parse string input with `Date::parse()`.
4. Return that configured factory result for the `DateTimeInterface` and `CarbonInterface` declarations.
5. Adapt the parsed or timestamp result with `$target::instance($date)` for a concrete Carbon implementation or subclass, or `$target::createFromInterface($date)` for native `DateTime`, `DateTimeImmutable`, or their subclasses.

An already-valid subclass may pass through for a base-class declaration; the result satisfies the declared type and follows normal PHP substitution. A conversion into a concrete application subclass returns that subclass.

Only actual numeric values are Unix timestamps. A numeric string such as `"20240101"` is parsed as a date. Invalid date parsing propagates Carbon's native format exception. Do not add a DataObject date-format setting, custom parser, target table, or exception wrapper.

### Unions, intersections, and other objects

Nullable named types use their normal kind after the null bypass. Multi-arm unions and intersections use pass-through behavior only:

- already valid values reach the constructor unchanged;
- an array is never guessed into one object arm;
- PHP enforces the declared union or intersection.

Other named object types also pass through. DataObject never constructs them and never resolves the container. Custom conversion belongs in an application named factory or a Data cast.

## Transformation

`toArray()` reads the instance through `(array) $this`, then iterates only the promoted names in the compiled recipe. This avoids `get_object_vars()` creating a retained per-object property table, excludes protected and private internal state, ignores deprecated dynamic properties, and preserves constructor order. Keep the scalar fast gate in this outer loop so a plain object does not pay one helper call per already-final property; recursive values continue through the single normalizer.

Normalize each value with a scalar-first gate:

1. Return a value immediately when it is neither an array nor an object.
2. Recursively normalize arrays while preserving keys.
3. Convert `DateTimeInterface` to `DATE_ATOM`.
4. Convert `BackedEnum` to its backing value.
5. Convert `DataObject` through `toArray()`.
6. Convert another `Arrayable` through `toArray()`, then normalize that result recursively so collections containing dates, enums, or DataObjects become plain arrays.
7. Leave any other object unchanged for PHP's normal JSON or caller behavior.

Do not cache transformed output on the instance. Public properties may be mutated, so every result must reflect current state. Do not add cycle detection or depth machinery for unsupported cyclic value graphs.

`jsonSerialize()` returns `toArray()`. `toJson()` follows current Support convention:

```php
return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
```

Let `JsonException` propagate. Do not add `__toString()`, pretty-JSON, field exclusions, custom serializers, mapping, partials, or transformers.

## Documentation

Update `src/docs/data-objects.md` in Laravel prose:

- add `Lightweight Data Objects` to the contents and place the section after `Choosing a Base Class`;
- end `Choosing a Base Class` with a link to that section for trusted internal values that need only typed construction and array output;
- show a small internal message-envelope example using `Hypervel\Support\DataObject::from()`;
- show that a FormRequest may declare a DataObject class directly in `casts()`, including the `items.*` wildcard form for lists, when request rules own validation;
- explain that exact constructor names are keys, common PHP scalar/enum/date conversions and properties typed as a DataObject subclass are automatic, invalid scalar forms throw, and `toArray()`/JSON recursively normalize supported values;
- state that an `array` property does not infer or hydrate an item type, and show a named factory using `array_map(Item::from(...), $data['items'])` when an envelope needs a one-off list conversion; direct reusable typed collections to Data;
- direct object-owned validation, reusable mapping, custom casts, partials, resources, collection abstractions, and persistence to `Data`, `Dto`, or `Resource` as appropriate;
- do not document recipe caching, integer kind tags, reflection layout, rejected APIs, benchmarks, or implementation history.

Do not add a Support README difference or porting-guide entry. This is an additive Hypervel API with canonical user documentation, not an existing Laravel API that porters must adapt.

Update `docs/todo.md` with the self-contained typed-input accessor work described above. Do not reference scratch-plan numbers or make Data adoption automatic.

## Tests

Add `tests/Support/DataObjectTest.php`, using a test-specific namespace for its helper classes and `Hypervel\Tests\TestCase`. Keep fixtures inline because only this test uses them.

### Public behavior

- `from()` constructs exact native values and ignores unknown input keys.
- An instance created through its public constructor transforms correctly before `from()` has compiled its recipe.
- Input and output use exact property names; snake-case aliases are not silently accepted.
- Constructor defaults, nullable missing values, missing required values, and explicit null follow the specified precedence.
- Two constructions with a promoted `new` object default receive distinct objects.
- Public promoted `readonly` properties work.
- Every subclass inherits the `Transient` lifetime marker.
- Every subclass is request-castable without arguments and returns a caster for that concrete subclass; cast arguments fail clearly.
- Direct mutation is visible in the next `toArray()` and JSON result.
- Nested DataObjects, arrays of DataObjects, associative keys, dates, enums, and another `Arrayable` normalize recursively.
- An array-typed property retains raw array items during construction rather than guessing an item type.
- `jsonSerialize()` equals `toArray()`; `toJson()` honors flags and throws for an unencodable value.

### Conversion matrix

Use data providers to cover every accepted and rejected built-in form, including:

- integer whitespace, zero, negative values, whole native floats, fractional values, decimal strings, and text;
- float integers, decimals, scientific notation, and text;
- boolean native values, `1`/`0`, true/false, yes/no, on/off, case variants, empty string, `2`, and unrelated text;
- scalar and Stringable strings versus arrays and arbitrary objects;
- arrays versus scalar input.

Assert failure messages identify the class, property, expected type, and supplied type or value without testing incidental stack details.

### Object types

- string- and integer-backed enums accept cases and valid backing values, including numeric strings; invalid values preserve `ValueError`.
- nested values accept arrays and existing instances.
- a nullable recursive node hydrates at least four repeated levels, proving no global visited suppression.
- an inherited constructor keeps a `self`-typed nested property bound to the class that declared the constructor.
- a concrete `parent`-typed nested property accepts an array and hydrates its declaring parent class.
- a non-null object union accepts a valid existing arm but does not hydrate an array into an arbitrary arm.
- an unknown object target is not container-resolved.

### Dates

Cover every old useful target category and the generic additions:

- `DateTimeInterface` and `CarbonInterface` follow the configured Date factory;
- native mutable and immutable classes;
- Hypervel and base Carbon mutable and immutable classes;
- application subclasses of native DateTime and Carbon;
- existing date instances, database-formatted strings, date-only strings, actual integer/float timestamps, and the numeric date string `"20240101"`;
- timestamp conversion under a non-UTC PHP default timezone;
- invalid date strings;
- date serialization retains timezone offset through `DATE_ATOM`.

Restore the configured Date factory in test cleanup through the repository's existing global cleanup, not a local duplicate reset.
The non-UTC timestamp test changes PHP's process-wide default timezone and must restore its previous value in a `finally` block; `AfterEachTestSubscriber` does not reset it.

### Declaration boundaries

- protected constructor supported through `from()`;
- non-promoted constructor parameter rejected;
- protected/private promoted parameter rejected;
- extra public instance property rejected;
- private/protected instance state allowed and absent from output;
- public static state allowed and absent from output;
- a child without its own constructor inherits its parent's public promoted parameters in declaration order.

Do not test PHP compile-time failures for variadic promotion or readonly inheritance.

Run `tests/Support/DataObjectTest.php` immediately after creating or changing it.

Extend `tests/Foundation/Http/FormRequestCastingTest.php` with public-behavior coverage that proves a direct `DataObject` declaration converts one already-validated array, `contacts.*` converts every member while preserving list keys, `null` remains null, invalid non-array input fails clearly, and `validated()` / `safe()` expose the converted values through the existing APIs. Keep the fixtures local to that test file and run it immediately after changing it. No Foundation source change is required.

## Benchmarks

Use the frozen legacy fixture only during acceptance:

1. Extend `tests/Benchmarks/Data/compare-data-object.php` temporarily to report three columns: removed mapper, rebuilt Support DataObject, and current Data.
2. Compare equivalent exact-key input and supported behavior. Do not credit the old implementation for stale cached output or permissive invalid conversion.
3. Measure all existing construction and correct uncached transformation shapes, 1,000-item loops, direct property reads, retained instances before and after transformation, retained metadata for one small class, and fresh-process first use.
4. Use the existing coercion and deep-nesting rows for strict scalar conversion and nested resolution, and add one construction and transformation row for arrays of DataObjects because that is the motivating per-item list shape. Defaults and application date subclasses remain correctness tests rather than benchmark scenarios.
5. Run at least three complete alternating samples while the machine is idle. Record median p50 and p95 results in the implementation summary and PR; describe the PHP, OPcache, and JIT conditions.
6. Retain the harness's existing native-constructor floor and current Data as the richer framework comparison.

Acceptance requirements:

- common valid construction and correct uncached transformation meet or improve the removed mapper within normal measurement noise;
- 1,000-item construction and transformation retain the meaningful throughput advantage needed by the motivating hot-loop use case;
- strict conversion does not add enough cost to erase that advantage;
- transformed instances retain no per-object property-table or output-cache allocation;
- recipe memory is naturally bounded per used class and does not materially exceed the removed mapper's metadata;
- first use performs only reflection, ordinary declaration autoloading when necessary, and recipe compilation, with no container, package-owned filesystem discovery, network, or PHPDoc work.

Remove any specialization that does not earn its complexity or materially regresses p95 or retained memory.

### Acceptance Results

Acceptance benchmarks met the requirements above. The historical fixture has been removed, and the retained harness now compares the two supported APIs.

After recording acceptance results:

- delete `tests/Benchmarks/Data/Fixtures/DataObject.php` so known-defective production code is not retained as a permanent fixture;
- leave `tests/Benchmarks/Data/compare-data-object.php` as a two-column Support DataObject versus Data harness and update its class names, headings, cold modes, memory labels, and comments;
- update `tests/Benchmarks/Data/README.md` to describe the ongoing supported comparison with no historical-fixture wording.

Do not add benchmark thresholds to PHPUnit.

## File Changes

| File | Change |
| --- | --- |
| `src/support/src/DataObject.php` | Add the complete lightweight mapper, conversion, transformation, recipe compilation, and cache reset. |
| `src/support/src/Http/DataObjectRequestCast.php` | Adapt one already-validated array to a configured lightweight DataObject class. |
| `tests/Support/DataObjectTest.php` | Add supported behavior, failure, declaration, recursion, date, and serialization coverage. |
| `tests/Foundation/Http/FormRequestCastingTest.php` | Cover direct and wildcard lightweight DataObject request casts. |
| `src/docs/data-objects.md` | Document the lightweight choice and its boundary from Hypervel Data. |
| `src/docs/validation.md` | Document lightweight DataObject declarations in FormRequest casts. |
| `docs/todo.md` | Record the coherent typed-input accessor audit and conditional future scalar extraction. |
| `tests/Benchmarks/Data/compare-data-object.php` | Measure the new supported mapper against Data after the temporary legacy acceptance comparison. |
| `tests/Benchmarks/Data/README.md` | Describe the supported benchmark. |
| `tests/Benchmarks/Data/Fixtures/DataObject.php` | Delete after the legacy acceptance measurements are recorded. |

No Composer, provider, alias, facade, contract, Foundation source, Database, Saloon, Data package, or test-subscriber change is required.

## Verification

1. Run `./vendor/bin/phpunit --no-progress tests/Support/DataObjectTest.php` after every coherent Support test/source change, and run `./vendor/bin/phpunit --no-progress tests/Foundation/Http/FormRequestCastingTest.php` immediately after changing that test.
2. Run `composer lint` to check formatting while iterating, and `composer lint:fix` when changed files need formatting.
3. Run targeted PHPStan for source investigation only if needed; tests are excluded from PHPStan.
4. Run the three-column acceptance benchmarks as specified, remove the legacy fixture, then rerun the final two-column harness.
5. Run `composer lint:fix`, `composer analyse`, and the affected Support test file at the completed implementation checkpoint.
6. Review every changed file and trace `from()`, recipe compilation, scalar/enum/date/nested conversion, transformation, and cache reset through all callers and failure paths. Check API naming, PHPDoc types, worker memory, coroutine safety, invalid declarations, defaults, inherited properties, and JSON behavior.
7. Remove dead branches, redundant comments, obsolete imports, temporary probes, legacy fixtures, and unearned optimizations.
8. Request a final code review and address findings with targeted tests. Repeat the full suite only if the review changes warrant it.

## Rejected Designs

- **Restore the old class:** retains verified bugs, worker-global switches, stale caches, awkward names, and overlapping extension points.
- **Use Data for the hot loop:** preserves one conceptual model but leaves measured per-item work that the use case does not need.
- **Add a Data fast-mode API:** exposes engine selection and still carries Data's broader public contract and state.
- **Reuse Data's `ValueCaster`:** reverses the Support-to-Data dependency and imports Data-specific metadata, context, exceptions, and permissive semantics.
- **Extract a shared scalar helper now:** has one strict consumer and would require partial, behavior-changing edits to Data or `InteractsWithData`.
- **Cache transformed arrays:** is incorrect for mutable public properties and increases retained instance memory.
- **Cache evaluated defaults or retain reflection:** risks shared mutable default objects or pays avoidable worker memory to reproduce PHP behavior.
- **Generate hydration closures or source code:** adds compile machinery and debugging cost for a compact recipe loop without evidence of a net win.
- **Add mapping, hooks, custom conversion, validation, or bespoke integration adapters:** recreates a second Data package rather than the requested internal mapper. Implementing the existing generic `RequestCastable` contract is deliberately narrower: FormRequest owns validation and the adapter only calls `from()`.
- **Add coroutine state or locking:** recipes are immutable, declaration-derived, bounded, and published by one assignment after classification, so a duplicate compile is harmless.
