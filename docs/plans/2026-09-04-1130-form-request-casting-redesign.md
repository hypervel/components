# Form Request Casting Redesign

## Goal

Rebuild Hypervel's FormRequest casting as a small, Laravel-shaped post-validation feature. Cast declarations belong on the request, while the normal `validated()` and `safe()` APIs return the typed result. Validation must continue to run against submitted values through Hypervel's optimized validation engine; raw request access must remain raw.

This is a framework enhancement plus fixes to defects exposed by typed validated input. Hypervel 0.4 does not need to retain the earlier Hypervel-only API when a cleaner API provides the useful behavior.

## Verified Starting Point

- `FormRequest` builds the validator through `Hypervel\Contracts\Validation\Factory`. The concrete validator expands wildcard rules with a direct tree walk, compiles them with `RuleCompiler`, reuses immutable plans through `RulePlanCache`, and executes them through `PlanExecutor`.
- Casting currently runs separately in `Foundation\Http\Traits\HasCasts`. It only visits top-level input, so dotted and wildcard cast declarations do not work.
- The current code copies Eloquent concepts that do not fit one-way request conversion: `$casts`, `casted($key, $validate)`, `get()` / `castUsing()`, database exception wording, database date formats, and an object cache intended for Eloquent write-back.
- The object cache returns mutable cast objects by identity on later reads. FormRequest has no write-back stage that would justify retaining those objects.
- JSON casts reject request values that Symfony has already decoded to arrays. Decimal casting first converts through float and can lose precision. Undefined casts throw the Eloquent exception with model/column wording.
- `validated()` and `safe()` do not apply casts. `casted(validate: false)` can declaratively transform fields that validation did not accept.
- `InteractsWithData::isEmptyString()` string-casts arbitrary objects. A backed enum therefore makes `ValidatedInput::filled()`, `enum()`, and `whenEnum()` throw. `normalizeEnumValue()` also rejects an already-cast matching enum, and `enums()` drops it.
- Strict types expose a shared backed-enum conversion defect beyond FormRequest: an integer-backed enum rejects the numeric strings produced by normal HTTP and JSON boundaries. The validation enum rule therefore diverges from Laravel, integer-backed implicit route binding is impossible because Routing explicitly string-casts the parameter, and Collections, Eloquent, and Data repeat the same strict `from()` / `tryFrom()` calls. `InteractsWithData` already contains the intended scalar normalization, but only for its own helpers.
- The Data package adapters use the old contracts. Data objects are still a separate endpoint-object feature with their own `from()` / `collect()` creation and optional validation pipeline.
- Framework docs describe the old API in `validation.md` and `data-objects.md`.
- No FormRequest casting consumers exist in `packages/hypervel` or `packages/hypervel-dev`; their `casts()` methods are Eloquent model casts.

The local Laravel 13 reference was checked at `src/Illuminate/Foundation/Http/FormRequest.php`, `src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`, `src/Illuminate/Validation/Rules/Enum.php`, `src/Illuminate/Collections/Traits/EnumeratesValues.php`, `src/Illuminate/Routing/ImplicitRouteBinding.php`, and the Eloquent cast contracts/exceptions. Laravel has no FormRequest cast layer to copy. This design therefore preserves the exact `safe()` / `validated()` extraction shape, uses the modern protected `casts()` convention, and gives the new one-way context its own vocabulary instead of pretending it is an Eloquent read/write cast. Laravel's generic enum consumers rely on non-strict scalar coercion, so Hypervel must make that contract explicit rather than reject normal wire-format strings under mandatory strict types.

## Final Public API

### Request declarations and extraction

Use one protected extension point, matching modern Laravel model ergonomics:

```php
use App\Data\ContactData;
use App\Data\PostMetadataData;
use App\Enums\PostStatus;
use App\ValueObjects\Money;
use Hypervel\Data\Http\Casts\AsDataCollection;
use Hypervel\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'rating' => 'decimal:2',
            'status' => PostStatus::class,
            'history.*.status' => PostStatus::class,
            'metadata' => PostMetadataData::class,
            'contacts' => AsDataCollection::of(ContactData::class),
            'price' => Money::class,
        ];
    }
}
```

The existing Laravel extraction methods become the only read API:

```php
$input = $request->validated();
$status = $request->validated('status');
$safe = $request->safe();
$dates = $request->safe(['published_at']);
```

Invariants:

- Validation and authorization complete before extraction, as they do now.
- `validated()` and `safe()` apply casts to the validator's validated result.
- `validated($key, $default)` reads from the cast result. `safe($keys)` returns the selected cast values; `safe()` returns `ValidatedInput` containing cast values.
- `input()`, `all()`, request properties, and the validator's own data remain unchanged and raw.
- Fields without casts retain their validated values and shape. Missing cast paths are not added. Primitive and enum casts preserve `null`; present `null` reaches custom casters so the caster controls its own nullable behavior.
- Cast definitions are evaluated once per extraction. An empty declaration returns the validator result immediately, before key encoding or traversal. Casters receive the original decoded validated array, not partially cast output, so sibling access is stable and declaration order does not change a caster's context.
- Parse each cast declaration once per extraction and identify its present direct or wildcard matches. A declaration with no present match is skipped without resolving a custom caster. Otherwise, resolve one fresh declaration-local caster immediately before the match loop, use it for every match, and then discard it; another declaration or extraction resolves independently. Do not keep a request-property, static, container, or output cache. This avoids unused and per-element constructor / resolver work without leaking a mutable caster or result into a later extraction. A `RequestCastable` implementation remains responsible for the object it returns, and native casts may intentionally preserve an already-typed input object such as an enum case or Collection.

Remove the copied Eloquent request surface:

- `casted()` and its raw-input bypass
- public `hasCast()`, `getCasts()`, `fromJson()`, `fromFloat()`, `fromDateTime()`, and `getDateFormat()` helpers
- protected `$casts`, `$classCastCache`, and `$dateFormat` properties
- undocumented `custom_datetime`; `date:<format>` and `datetime:<format>` replace it coherently

Raw primitives remain available through Laravel-style Request helpers. A raw custom value object can be built explicitly from `input()`. Declarative FormRequest casts only describe validated output.

### Custom contracts

Add these framework contracts under `Hypervel\Contracts\Http`:

```php
interface CastsRequestInput
{
    public function cast(string $key, mixed $value, array $input): mixed;
}

interface RequestCastable
{
    public static function castRequestUsing(array $arguments): CastsRequestInput|string;
}
```

- `CastsRequestInput` describes a one-way request transformation; Eloquent's `get()` name implies a read/write attribute caster and is not used.
- `RequestCastable::castRequestUsing()` avoids colliding with Eloquent `Castable::castUsing()`, allowing a class to support both contexts.
- Both contracts use strict types and Laravel-style method docs. `RequestCastable` documents `string[]` arguments and a caster object or caster class-string return.
- Direct caster declarations retain Eloquent's familiar positional syntax: `MoneyCast::class . ':USD'`. A request-castable class may return a caster object or class-string.
- Instantiate caster classes directly with declaration arguments. These objects are value transformers, not container services. Container resolution would interpret positional cast arguments as DI and would auto-singleton unbound casters for the worker lifetime, making mutable application casters unsafe across requests.

Delete the old `Hypervel\Foundation\Http\Contracts\CastInputs` and `Castable` contracts after all consumers move.

### Invalid casts

Add `Hypervel\Foundation\Http\InvalidCastException`, following Eloquent's namespaced exception shape but using request language:

- store the request class, input key, and cast type in typed public `$request`, `$input`, and `$castType` properties;
- report an undefined cast on an input in a request, never a column in a model;
- throw it only when the declared primitive/enum/custom cast class cannot be resolved. Let invalid supported-class behavior fail through its native typed contract rather than wrapping every failure.

## Cast Behavior

| Declaration | Result and accepted validated input |
|---|---|
| `int`, `integer` | PHP integer cast |
| `real`, `float`, `double` | Preserve an existing float; otherwise cast normally while retaining the `Infinity`, `-Infinity`, and `NaN` string-token handling without coercing float `NAN` to a string |
| `string` | PHP string cast |
| `bool`, `boolean` | Request-native `filter_var($value, FILTER_VALIDATE_BOOLEAN)` conversion, matching `Request::boolean()` / `ValidatedInput::boolean()` for values such as `"1"`, `"true"`, `"on"`, `"yes"`, `"0"`, `"false"`, `"off"`, and `"no"` |
| `decimal:<scale>` | Decimal string produced by `BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HalfUp)`; translate Brick's math exception to `Hypervel\Support\Exceptions\MathException` as Eloquent does |
| `array`, `json` | Decode a JSON string through `Hypervel\Support\Json`; preserve an already-decoded array |
| `collection` | Decode a JSON string or wrap an array in `Hypervel\Support\Collection`; preserve an existing Collection |
| `object` | Decode a JSON string with `assoc: false`; preserve an object; convert an already-decoded array with a `Json::encode()` / `Json::decode(..., assoc: false)` round trip so nested objects retain JSON shape |
| `date` | Request date instance at start of day |
| `datetime` | Request date-time instance |
| `date:<format>`, `datetime:<format>` | Parse the field with `Date::createFromFormat()` using the exact case-sensitive PHP format, then apply start of day for `date` |
| `timestamp` | Unix timestamp integer |
| backed enum class | Matching case through the shared strict-types-safe backed-enum conversion; preserve an existing matching case |
| unit enum class | Matching case by name; preserve an existing matching case |
| `CastsRequestInput` class | Fresh caster constructed with declaration arguments, then `cast($key, $value, $input)` |
| `RequestCastable` class | Resolve its request caster with the parsed argument array; if it returns a class-string, construct that class with the same positional declaration arguments before calling `cast()` |

Normalize only the primitive cast name. Preserve class names, positional arguments, and date format text exactly; PHP date format characters are case-sensitive.

Date conversion order:

1. `DateTimeInterface` values use `Date::instance()` so the configured mutable/immutable Date class is honored.
2. An explicit field format uses `Date::createFromFormat()` before generic numeric handling, so a numeric-looking formatted value such as `datetime:Y` is interpreted by its declaration.
3. Integers and numeric strings without an explicit field format use `Date::createFromTimestamp()` in the application/default timezone. Numeric strings need this explicit branch because Carbon's normal parser does not treat them as Unix timestamps.
4. Other strings use `Date::parse()`.
5. `date` calls `startOfDay()` on the returned instance; `timestamp` calls `getTimestamp()`.

Add `brick/math:^0.17` to the Foundation split package because Foundation will import it directly. The root already requires the same supported version.

## Shared Backed Enum Conversion

Fix integer-backed enum normalization at its existing cross-package ownership point rather than adding a FormRequest-only workaround. Add two `@internal` functions beside `enum_value()` in `src/collections/src/functions.php`, which already defines the `Hypervel\Support` enum helper namespace and is a direct dependency of every affected package:

```php
/**
 * Attempt to create a backed enum from the given value.
 *
 * @internal
 *
 * @template TEnum of \BackedEnum
 *
 * @param class-string<TEnum> $enum
 * @return null|TEnum
 */
function enum_try_from(string $enum, mixed $value): ?BackedEnum;

/**
 * Create a backed enum from the given value.
 *
 * @internal
 *
 * @template TEnum of \BackedEnum
 *
 * @param class-string<TEnum> $enum
 * @return TEnum
 */
function enum_from(string $enum, mixed $value): BackedEnum;
```

`enum_try_from()` owns the expected-invalid, exception-free path:

1. Verify that the target exists and is a backed enum; otherwise return `null`.
2. Preserve a matching enum instance by identity.
3. Resolve the target backing type through a function-local static cache keyed by enum class. This immutable metadata cache is naturally bounded by the enum classes loaded in the worker and replaces the narrower cache currently hidden in `InteractsWithData`.
4. For an integer-backed enum, accept integers, convert floats and booleans to integers, and trim and convert non-empty numeric strings. Reject every other value as `null`.
5. For a string-backed enum, preserve strings and explicitly stringify integers, floats, booleans, and global `Stringable` objects. Reject every other value as `null`.
6. Call the target's native `tryFrom()` with the normalized backing value and return its case or `null`.

`enum_from()` delegates to `enum_try_from()`. If no case is returned, it throws one `ValueError` with native-style invalid-backing-value wording that includes both the rejected value and target enum class. The throwing helper is for construction paths; validation, routing, and conditional lookup use the non-throwing helper directly, so invalid input never pays an exception-driven-control-flow cost.

Use the helpers consistently at every generic dynamic backed-enum conversion site:

- Validation's `Rules\Enum` and Routing's `ImplicitRouteBinding` use `enum_try_from()`; Routing removes its unconditional string cast but retains `BackedEnumCaseNotFoundException` for a missing case.
- `InteractsWithData::{enum,enums,whenEnum}` use `enum_try_from()` and delete `isBackedEnum()`, `normalizeEnumValue()`, `enumBackingType()`, and their trait-local reflection cache.
- Collections' `EnumeratesValues::mapInto()`, Eloquent's `HasAttributes::getEnumCaseFromValue()`, database `AsEnumCollection` / `AsEnumArrayObject`, and the rebuilt Foundation `HasCasts` / retained request `AsEnumCollection` use `enum_from()` for backed enums. Unit-enum name/constant behavior is unchanged.
- Data's `EnumCast` uses `enum_from()` inside its existing domain-exception boundary. It retains the Spatie-compatible step that converts a different `BackedEnum` instance to its backing value before conversion; the shared helper does not invent cross-enum coercion for other consumers.
- Data's `DataCreator` morph-discriminator lookup uses `enum_try_from()`.
- Do not update the old Foundation request `AsEnumArrayObject`; this plan deletes it. Concrete string-enum calls elsewhere remain untouched when their input is intentionally and statically string-valued rather than a generic backed-enum boundary.

This is the first coherent implementation slice because FormRequest's natural `Rule::enum()` workflow must accept a browser-submitted numeric string before post-validation casting can run. It restores Laravel behavior under Hypervel's mandatory strict types and removes duplicate normalization machinery; it is not a new user-facing enum API. No package dependency changes are needed because Validation, Routing, Support, Foundation, Database, and Data already require Collections.

## Wildcard and Placeholder Architecture

Casts stay after validation. Do not add them to `RuleCompiler`, `RulePlanCache`, or `PlanExecutor`: rules must inspect submitted values, cast behavior belongs to FormRequest, and cached validation plans must remain independent of request data.

Reuse the validation package's existing placeholder and direct-walk implementation:

1. Move the placeholder hash from `Validator` to `ValidationData`. `ValidationData` lazily initializes its own hash with `??= Str::random()` so its methods have no hidden construction-order dependency.
2. Move the underlying operations to public static methods on `ValidationData`, except for the protected hash owner and the private recursive wildcard traversal:
   - `encodeAttribute()` maps escaped `\.` / `\*` in an attribute declaration to placeholders.
   - `decodeAttribute()` maps placeholders back to escaped attribute notation.
   - `encodeKeys()` recursively maps literal dots/asterisks in data keys to placeholders.
   - `decodeKeys()` recursively restores literal data keys.
   - `replacePlaceholderInString()` restores literal characters for messages and concrete data paths.
3. Keep all six Laravel-compatible `Validator` wrappers/extension points, but make their placeholder work thin `ValidationData` delegates: public `parseData()`; protected `replacePlaceholders()`, `replacePlaceholderInString()`, and `replaceDotPlaceholderInParameters()`; and protected static `encodeAttributeWithPlaceholder()` and `decodeAttributeWithPlaceholder()`. Preserve Hypervel's existing literal-asterisk handling in addition to Laravel's literal-dot behavior. The hash and algorithms have one owner.
4. Move `expandWildcardKeys()` and `traverseWildcardSegments()` unchanged from `ValidationRuleParser` to `ValidationData`. `expandWildcardKeys()` is the public static entry point shared with Foundation; the recursive traversal remains an implementation detail. The parser calls the shared method. Preserve direct branch-only traversal, partial-segment wildcard matching, top-level wildcards, associative/numeric keys, and emission of missing fixed leaves below a matched wildcard for `required` rules.
5. `ValidationData` adds `flushState()` at the end of the class. `AfterEachTestSubscriber` registers it directly beside the other validation state owners. `Validator::flushState()` resets only Validator's DNS-test flag; it does not reset another class's state.

The placeholder hash is immutable worker-lifetime state after lazy initialization, exactly as it is today. It is one bounded string, safe for concurrent reads, and is reset between tests by the authoritative subscriber. No cast declaration, caster, or cast output is stored in static or worker-shared state.

FormRequest extraction uses placeholder space only internally:

```text
decoded validated input
  -> encode literal data keys once
  -> encode each cast attribute
  -> direct key or shared wildcard expansion
  -> read present value with an identity sentinel
  -> cast using decoded key + original decoded validated input
  -> write to encoded output
  -> decode output keys once
```

This prevents a wildcard match such as `settings.*` from turning a literal child key `theme.dark` into false nested output when writing the cast result. Placeholder values never cross the public custom-caster contract.

## Data Package Integration

- Make `Hypervel\Data\Contracts\BaseData` extend `RequestCastable`. This applies uniformly to `Data`, `Dto`, and `Resource`; `Dto` remains distinct from the Eloquent-cast capability implemented only by `Data` and `Resource`.
- Add `Hypervel\Data\Concerns\RequestCastableData`, parallel to `EloquentCastableData`. Its single method returns a fresh `Hypervel\Data\Http\DataRequestCast` for `static::class`.
- Compose the request-cast concern into the existing `Concerns\BaseData`. `Data`, `Dto`, `Resource`, and modular implementations that use the base concern then satisfy the expanded `BaseData` contract without repeating the concern on each class.
- Move and reshape the old `Http\Casts\AsData` implementation to `Http\DataRequestCast`. It implements only `CastsRequestInput`, converts a present non-null value with the target class's existing `from()`, and has no `of()` or old `castUsing()` declaration API.
- Keep `Http\Casts\AsDataCollection::of()` because a single data class name cannot express the collection target. Port it to `RequestCastable` and `CastsRequestInput`; `castRequestUsing()` returns a fresh configured instance, and `cast()` preserves `null` or calls the data class's existing `collect()`.
- Data creation is not folded into FormRequest validation. The request validator accepts the raw payload first; conversion then enters the existing Data factory, whose own configured creation/validation behavior remains authoritative.

Remove only Foundation's request `AsEnumArrayObject`. Keep the Eloquent database class of the same name. A normal enum array uses a wildcard declaration (`'statuses.*' => Status::class`); keep Foundation's `AsEnumCollection::of()` when a Support Collection is wanted.

## ValidatedInput Compatibility

Fix `Hypervel\Support\Traits\InteractsWithData` at the shared owner:

- `isEmptyString()` treats `null`, blank strings, and blank `Stringable` values as empty. It treats booleans, arrays, non-string scalars, enums, and arbitrary non-Stringable objects as filled without trying to stringify them. This preserves normal Laravel behavior while making typed object input safe.
- `enum()`, `enums()`, and `whenEnum()` delegate to `enum_try_from()`. This makes them idempotent on matching cast values while sharing the same strict scalar normalization with Validation, Routing, Collections, Foundation, Database, and Data.

Do not add a Laravel-difference note: this is a correctness fix that preserves the helper contracts.

## Documentation Result

Edit only the existing canonical sections in `src/docs/validation.md` and `src/docs/data-objects.md`; do not rewrite either file.

The final docs must show:

- protected `casts()` declarations only;
- `validated()` / `safe()` returning cast values and `input()` / `all()` remaining raw;
- the complete primitive table, per-field date formats, null behavior, and dotted/wildcard enum examples;
- natural enum arrays through wildcard casts and `AsEnumCollection` for Collection output;
- the new custom contracts, method names, positional arguments, and sibling-input argument;
- direct `Data`, `Dto`, and `Resource` class declarations plus `AsDataCollection::of()`;
- a concise persistence warning: scalar, enum, and date results pass through normal database normalization, while arrays and object results—including Data, Collections, decoded JSON objects, and custom value objects—need a compatible model serialization boundary such as Data/DataCollection Eloquent casts, JSON-family casts, or a custom cast/mutator.

Delete every stale example or statement for `$casts`, `casted()`, raw casting, old Foundation contracts, `AsData::of()`, Foundation `AsEnumArrayObject`, the object cache, and the global request date format.

Do not change `porting-from-laravel.md`. Its existing immutable request-date guidance remains true, and this additive Hypervel feature does not require a porter action.

## Implementation Todo

Before each item, re-read the named source/tests and any linked package README. Edit one file at a time. When a test file is created or changed, run that file immediately before continuing.

1. **Centralize strict-types-safe backed-enum conversion.**
   - Update `src/collections/src/functions.php` with `enum_try_from()` and `enum_from()` beside `enum_value()`. Keep their generic PHPDocs and `@internal` status, normalize by reflected backing type, cache only immutable backing-type metadata in a function-local static array, and keep expected-invalid lookup exception-free.
   - Move `tests/Support/SupportEnumValueFunctionTest.php` to `tests/Support/SupportEnumFunctionsTest.php`; retain the complete `enum_value()` coverage and add focused helper coverage for matching instances, integer and string backing normalization, numeric strings, `Stringable` input, missing/non-backed targets, unnormalizable values, absent cases, and `enum_from()`'s successful and native-style `ValueError` paths. Run the renamed file immediately.
   - Update `src/validation/src/Rules/Enum.php` to use `enum_try_from()` and remove its now-redundant `TypeError` control flow. Update `tests/Validation/ValidationEnumRuleTest.php` so an integer-backed enum accepts numeric string `'1'` as Laravel does while retaining non-numeric, missing-case, null, unit-enum, `only`, and `except` coverage. Run the file immediately.
   - Update `src/support/src/Traits/InteractsWithData.php` once: use `enum_try_from()`, remove its three protected enum-normalization helpers and reflection import, and apply the independent object-safe `isEmptyString()` correction described above. Update `tests/Support/Traits/InteractsWithDataTest.php` with numeric strings, matching instances, and the non-Stringable object regression for `filled()`. Run the file immediately.
   - Update `src/collections/src/Traits/EnumeratesValues.php` to use `enum_from()` for backed enums. Extend `tests/Support/SupportCollectionTest.php::testMapIntoWithIntBackedEnums()` with a numeric string while retaining native-integer coverage, then run the file immediately.
   - Update `src/routing/src/ImplicitRouteBinding.php` to use `enum_try_from()` without coercing every route value to string. Extend `tests/Routing/ImplicitRouteBindingTest.php` with the existing `Fixtures/IntegerEnum`: `/test/1` resolves to `IntegerEnum::One`, while invalid text still throws `BackedEnumCaseNotFoundException`. Run the file immediately.
   - Update Eloquent's `src/database/src/Eloquent/Concerns/HasAttributes.php`, `Casts/AsEnumCollection.php`, and `Casts/AsEnumArrayObject.php` to use `enum_from()` at their dynamic backed-enum boundaries. Extend `tests/Integration/Database/EloquentModelEnumCastingTest.php` with a string/varchar-backed integer-enum attribute and JSON collection/array-object payloads containing numeric strings, avoiding reliance on database integer-column coercion. Run the integration file immediately on the configured database test environment.
   - Update `src/data/src/Casts/EnumCast.php` to use `enum_from()` after its existing different-enum-to-backing-value conversion. Add an integer-backed fixture and numeric-string assertion to `tests/Data/Casts/EnumCastTest.php`, retaining the package-specific `CannotCastEnum` failure. Run the file immediately.
   - Update the backed-enum morph lookup in `src/data/src/Support/Creation/DataCreator.php` to use `enum_try_from()`. Add an integer-backed morph discriminator fixture and numeric-string resolution case to `tests/Data/Support/Creation/DataCreatorTest.php` without changing the existing string-backed morph fixture. Run the file immediately.

2. **Add the HTTP cast contracts.**
   - Copy `src/foundation/src/Http/Contracts/CastInputs.php` to `src/contracts/src/Http/CastsRequestInput.php`, then edit it to the final namespace, name, `cast()` signature, types, and docs.
   - Copy `src/foundation/src/Http/Contracts/Castable.php` to `src/contracts/src/Http/RequestCastable.php`, then edit it to the final name and `castRequestUsing(array $arguments)` contract.
   - Do not delete the old contracts until all framework and Data consumers have moved.

3. **Extract validation placeholder and wildcard ownership.**
   - Update `src/validation/src/ValidationData.php` first: copy in the hash/placeholder algorithms from `Validator` and the direct walker from `ValidationRuleParser`; add lazy self-initialization and `flushState()`.
   - Update `src/validation/src/Validator.php`: remove hash ownership and constructor initialization; delegate existing placeholder wrappers to `ValidationData`; leave `flushState()` responsible only for Validator state.
   - Update `src/validation/src/ValidationRuleParser.php`: call `ValidationData::expandWildcardKeys()` and remove the copied walker methods.
   - Update `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`: register `ValidationData::flushState()` directly with the validation state owners.
   - Add `tests/Validation/ValidationDataTest.php` for attribute/key encode-decode round trips, lazy initialization, explicit flush, direct wildcard expansion, partial wildcards, required-style missing leaves, and literal dot/asterisk placeholder paths. Run `./vendor/bin/phpunit --no-progress tests/Validation/ValidationDataTest.php`.
   - Update `tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php` with a focused assertion that framework cleanup resets `ValidationData`'s hash owner. Run that file immediately.
   - Run existing `tests/Validation/ValidationWildcardExpansionTest.php` and `tests/Validation/ValidationValidatorTest.php` after the extraction. The direct-walk algorithm is moved unchanged, so no benchmark or new validation behavior is introduced.

4. **Rebuild Foundation casting.**
   - Move `src/foundation/src/Http/Traits/HasCasts.php` to `Http/Concerns/HasCasts.php`, then replace the copied Eloquent surface with the post-validation cast engine and primitive/custom helpers described above. Use a constant for primitive names and no mutable cast cache.
   - Copy `src/database/src/Eloquent/InvalidCastException.php` to `src/foundation/src/Http/InvalidCastException.php`, then edit it to request/input semantics.
   - Update `src/foundation/src/Http/FormRequest.php` to import the concern and apply it inside `validated()` and `safe()` without changing raw Request data.
   - Update `src/foundation/src/Http/Casts/AsEnumCollection.php` to the new contracts and `cast()` method. Use `enum_from()` for its backed-enum items while retaining unit-enum names.
   - Delete `src/foundation/src/Http/Casts/AsEnumArrayObject.php` after replacing all request consumers.
   - Add `brick/math:^0.17` to `src/foundation/composer.json`, and add `brick/math` to the hardcoded third-party parity list in `tests/Foundation/PackageMetadataTest.php::testDirectFrameworkDependenciesAreDeclared()`. Run the metadata test immediately.
   - Move `tests/Foundation/Http/CustomCastingTest.php` to `tests/Foundation/Http/FormRequestCastingTest.php`, use a test-specific namespace for inline helpers, and replace old-API assertions with this public-behavior matrix:
     - `validated()`, keyed/default access, `safe()`, and safe subsets return cast values; raw request access remains raw; untouched values and missing paths retain their expected shape.
     - Every primitive/alias branch, null behavior, request-native boolean tokens including `yes` / `no` and `on` / `off`, float special values, precise decimal rounding/failure, JSON strings and decoded arrays, Support JSON nesting limits and malformed/over-depth failures, object shape, Collections, timestamps, backed enums, unit enums, existing enum instances, and `AsEnumCollection` output including integer-backed numeric-string items.
     - Through a real request whose rules use `Rule::enum()` and whose casts declare the same integer-backed enum, prove browser-style numeric string input validates first and is then returned as the enum from `validated()` / `safe()`.
     - DateTime inputs, free-form strings, integers and numeric strings, explicitly formatted numeric-looking strings, PHP format modifiers/trailing-data syntax, timezones, the configured Date class, and `date` start-of-day behavior.
     - Deep and associative wildcards, partial-segment wildcards, exact escaped dotted keys, literal asterisk keys, and a dotted child key beneath a wildcard whose output key must remain literal.
     - Direct custom caster with positional arguments, full decoded sibling input, a `RequestCastable` returning an argument-constructed class-string, one returning an object, present-null handling, and undefined-cast exception properties/message.
     - Prove a missing declaration does not construct or resolve its custom caster. For one wildcard declaration with multiple concrete matches, use counting casters to assert one constructor / `castRequestUsing()` resolution and one `cast()` call per match. Extract a second time and assert one new declaration-local resolution, proving both hot-path reuse and the absence of a persistent caster cache.
     - Mutate a custom caster's first mutable result, extract again, and assert the second result is rebuilt from validated input rather than a cached output object.
     - Through a real FormRequest `safe()` result, assert `filled()`, `enum()`, `enums()`, and `whenEnum()` all accept already-cast cases.
   - Run the replacement Foundation test file immediately.

5. **Integrate Data objects.**
   - Copy `src/data/src/Concerns/EloquentCastableData.php` to `RequestCastableData.php`, then edit it to return `DataRequestCast` through `castRequestUsing()`.
   - Update `src/data/src/Contracts/BaseData.php` to extend `RequestCastable`.
   - Update `src/data/src/Concerns/BaseData.php` to compose the request-cast concern, so every implementation using the standard base concern fulfills the expanded contract.
   - Move `src/data/src/Http/Casts/AsData.php` to `src/data/src/Http/DataRequestCast.php`, then reshape it as the internal single-data caster.
   - Update `src/data/src/Http/Casts/AsDataCollection.php` to the new contracts while preserving supported collection targets and validation of its user-supplied data class.
   - Audit `src/data/composer.json` after moving the cast contracts. Keep its direct `hypervel/contracts` dependency and do not remove `hypervel/foundation`: `DataValidator` still imports `Hypervel\Foundation\Precognition`, and `DataClassFactory` still imports Foundation's FormRequest attributes.
   - Update `tests/Data/CapabilityTest.php` to assert `Data`, `Dto`, and `Resource` implement `RequestCastable` while DataCollection types do not claim request castability without a target data class, and retain all existing distinct Eloquent persistence capabilities. Run the file immediately.
   - Update `tests/Data/Http/Casts/FormRequestCastTest.php` for direct Data/Dto/Resource declarations, default and explicit DataCollection targets, null/missing inputs, and invalid collection declarations. Run the file immediately.

6. **Remove superseded source only after consumers move.**
   - Delete old `src/foundation/src/Http/Contracts/CastInputs.php` and `Castable.php`.
   - Remove the now-empty `src/foundation/src/Http/Contracts` and `src/foundation/src/Http/Traits` directories.
   - Run Composer autoload generation and grep all `src/`, `tests/`, and `src/docs/` for old namespaces, symbols, methods, helpers, and request-only `AsEnumArrayObject` references. Database Eloquent symbols of the same name must remain. Also grep all `src/` for remaining generic dynamic backed-enum `::from()` / `::tryFrom()` calls; only verified concrete, intentionally typed conversions may remain outside the shared helpers.

7. **Rewrite the canonical documentation sections.**
   - Edit the FormRequest casting section in `src/docs/validation.md` to the final API and behavior.
   - Edit the Form Request Casting section in `src/docs/data-objects.md` to direct single-object declarations and the retained collection helper.
   - Search both documents for stale API names after each targeted edit.

8. **Focused and full verification.**
   - Re-run the changed Foundation, Data, Support enum/collection/data-helper, Validation enum/ValidationData/wildcard, Routing implicit-binding, Database enum integration, cleanup-subscriber, and package-metadata tests together from the worktree root.
   - Run targeted PHPStan only if implementation exposes a type question; investigate types from source and Laravel references rather than widening them for the analyzer.
   - Run `composer fix` once at the completed checkpoint. If it fails, inspect the `fix` script, diagnose the exact root cause, and run the failed command plus every remaining script entry after correction as required by `AGENTS.md`.
   - Finish with `git status --short`, `git diff --check`, and broad stale-reference greps. Review every changed file for Laravel-style naming, public surface size, direct hot-path cost, static state ownership, coroutine safety, dead code/comments, and documentation consistency.

## Completion Criteria

- Application code declares casts only through `casts()` and receives typed validated values through `validated()` / `safe()`.
- Raw request input is unchanged; validation still uses the optimized Hypervel validator before any cast runs.
- Nested, wildcard, partial-wildcard, and escaped literal keys cast without flattening or output-shape corruption.
- Custom casters have request-specific names, resolve once per declaration per extraction without a persistent framework cache, receive positional arguments and stable full-input context, and report request-specific failures.
- All documented primitive, enum, value-object, Data, and collection conversions work for normal form and decoded JSON input.
- Integer-backed numeric strings work consistently through Validation, Routing, Collections, FormRequest, Eloquent JSON/scalar casts, Data casts/morphs, and typed `ValidatedInput`; invalid conditional lookups remain exception-free and throwing construction paths retain useful `ValueError` failures.
- Typed `ValidatedInput` helpers are safe and idempotent for enum/object values, with no duplicate enum normalization or metadata cache in the trait.
- Placeholder static state has one owner, self-initializes, and is reset directly by test cleanup; no request cast cache or worker-shared mutable caster exists.
- Foundation's split package declares every directly used dependency.
- Old request-casting contracts, helpers, files, tests, docs, and empty directories are gone, while Eloquent casting remains untouched.
- Targeted tests and `composer fix` pass with no stale references or formatting errors.
