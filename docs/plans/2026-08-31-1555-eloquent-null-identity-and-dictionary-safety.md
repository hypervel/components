# Eloquent null identity and dictionary safety implementation plan

## Status and authority

This plan owns the `fix/eloquent-null-identity` branch. It covers the complete current-Laravel ports discovered through [laravel/framework#59019](https://github.com/laravel/framework/pull/59019) and [laravel/framework#60954](https://github.com/laravel/framework/pull/60954), the null-identity gaps those changes expose in Hypervel, the verified callback failure in duplicate detection, and the integer morph-alias failures exposed by making the public morph-map types truthful.

The pull requests identify the changed surface only. At implementation start, fetch both `origin/0.4` and Laravel `13.x`, update this branch if its base has advanced, and verify the Laravel reference checkout is at the fetched `13.x` head. Before editing each file, compare the full current Laravel file and tests with Hypervel, then merge the current implementation while preserving Hypervel's native types, `newInstance()` behavior, strict comparison policy, Swoole-safe static-state documentation, and other approved adaptations. Port every source and test file from each pull request; do not copy the historical patch when `13.x` has since changed the same code.

## Outcome

Stop null model and relation keys from becoming PHP array offsets, colliding with valid empty-string or zero keys, or losing float precision during dictionary matching. Raw integer `IN` primitives must discard null before casting so they cannot manufacture an unbound `0` literal. Identity-dependent refresh, aggregate, and collection-query operations must fail with `MissingAttributeException` before constructing an unsafe or incomplete key constraint when the required primary key is absent. Integer morph aliases, including `0`, must round-trip through inverse lookup, model serialization, class-string morph queries, association, and eager loading while the database representation remains a string. Morph association must use the configured owner-key column for every value, reject scalar input that cannot identify a morph type, enforce required maps for class-string queries, and support null as both sides of the `whereMorphedTo()` predicate. Duplicate detection with a callback must compare the callback's mapped values rather than applying Eloquent model comparison to scalars.

The final code keeps Laravel's current dictionary semantics for valid keys and ports its null-key behavior. It does not preserve Hypervel's earlier keyless `duplicates()` output: Laravel treats Eloquent dictionary/set operations as operations on persisted models with keys, and closed [laravel/framework#31141](https://github.com/laravel/framework/issues/31141) with `toBase()` as the path for ordinary collection behavior. No Swoole or coroutine constraint justifies the old Hypervel branch.

## Invariants and design limits

- Null never reaches a PHP array offset or `array_flip()` in the changed dictionary paths.
- Null never reaches the integer cast in `whereIntegerInRaw()`; after null removal, the existing grammar continues to compile empty `IN` lists as `0 = 1` and empty `NOT IN` lists as `1 = 1`.
- `''`, `0`, `'0'`, integer keys, numeric strings, stringable values, and backed/unit enums remain valid. Float keys are normalized to strings so values such as `1.5` and `1.9` cannot both become integer offset `1`.
- Morph-map keys remain `int|string`, but model morph types are serialized as strings because every standard morph schema helper creates a string type column. Numeric PHP array-key normalization must not break eager loading, and the string-key eager path must retain valid foreign keys `0`, `'0'`, and `''`.
- A raw null morph type or `''` means no relation. A null morph type is skipped before reading the foreign key; normalized null foreign keys are skipped independently.
- `MorphTo::associate()` chooses the configured owner-key column from configuration alone. Values `0`, `''`, and null must not switch the write to the model primary key because every lazy and eager read still uses the configured owner key. Non-model scalar input is rejected before either morph column or the loaded relation is mutated.
- Required morph maps apply equally to model instances and model class strings passed to `whereMorphedTo()` or `whereNotMorphedTo()`. Registered map keys, including class-shaped legacy aliases, and unregistered stored alias strings remain valid. The normal non-strict query path adds no class loading or model construction.
- `whereNotMorphedTo($relation, null)` compiles `IS NOT NULL`, the exact inverse of `whereMorphedTo($relation, null)`; its `orWhere` wrapper inherits the same behavior.
- Keyless models may still exist in ordinary Eloquent collections and `modelKeys()` still reports their null key. Do not invent object identity, synthetic dictionary keys, or supported set semantics for them.
- `find()` retains its existing loose compatibility for two non-null keys, but neither the requested key nor a model key may be null. Keep a short comment because the deliberate loose comparison is an exception to the repository's strict-comparison rule.
- `fresh()` ignores unsaved models, including unsaved models with manually assigned keys. It validates every existing model key before issuing a query and performs no query when no existing models remain.
- `loadAggregate()` requires a key for every model because it cannot load an aggregate without row identity. This is based on key presence, not `exists`: the existing soft-deleted-model test uses models with `exists === false` and retained keys and must keep passing.
- `toQuery()` retains its existing empty-collection and mixed-model validation order, then requires a key for every model. A null key must never reach the integer-key `whereKey()` path, where it is cast to `0` and can target a legitimate zero-keyed row during a bulk mutation.
- Null requested keys in both `only()` and `except()` are discarded. This removes `only()`'s `array_flip()` warning and prevents `except([null])` from deleting a valid `''`-keyed model.
- No new shared/static state, cache, registry, I/O fallback, query, compatibility switch, exception class, or generic identity abstraction is added. `fresh()` removes a pointless query for all-unsaved input.
- Routine null guards and casts need no comments. Remove the obsolete keyless-comparator comment and tests. Keep only documentation that describes a real public requirement.

## Upstream inventory and current-source findings

### Laravel #59019

The pull request changed six source files and added no tests:

1. `Eloquent/Collection.php`
2. `Relations/BelongsTo.php`
3. `Relations/BelongsToMany.php`
4. `Relations/Concerns/InteractsWithDictionary.php`
5. `Relations/HasOneOrMany.php`
6. `Relations/MorphTo.php`

All six current `13.x` files remain authoritative. Later source changes matter:

- `InteractsWithDictionary` now handles `UnitEnum` through `enum_value()` as well as the original null/string/integer and stringable cases. Hypervel already has the enum branch, so merge the current ordering and add the truthful native return type rather than replacing it with the historical version.
- Current `HasOneOrMany` also rejects a null parent/local key. Hypervel already has that later guard; add the missing result/foreign-key guard without removing it.
- Current `MorphTo::matchToMorphParents()` normalizes both an explicit owner key and `getKey()` before checking for null. Hypervel already matches this current form; add the missing dictionary-input guards without regressing it.

### Laravel #60954

The pull request changed `Relations/Relation.php` and added `DatabaseEloquentRelationTest::testGetMorphedModel()`. Port both from current `13.x`. Hypervel's current `string` parameter contradicts its own numeric morph-map test and `getMorphAlias(): int|string`: a numeric alias round trip currently throws a `TypeError`, and null from a nullable morph column does the same.

The current Laravel PHPDocs still describe some morph maps as string-keyed despite accepting and testing integer aliases. Hypervel must adapt those declarations to the real `int|string` key shape rather than porting a known-wrong type.

### Hypervel-specific findings

- `Collection::find()` still equates unrelated null identities through `null == null`; a non-null empty-string request can also match a null model key through loose comparison.
- `Collection::fresh()` passes null and unsaved manual keys into bound `whereIn()` and reads a null dictionary offset after the query. Bound null cannot alias integer zero here; the guard is required for parity with individual `Model::fresh()`, a useful missing-identity exception, and removal of the pointless unsaved-key query.
- `Collection::loadAggregate()` passes null keys into `whereKey()`. On integer-key models that first becomes raw `id in (0)` and can aggregate a legitimate zero-keyed row; the method then fails through `null->getAttributes()`, either while inspecting an empty result collection or while hydrating a mixed collection.
- `Collection::toQuery()` passes `modelKeys()` directly to `whereKey()`. For an integer-key model, `whereIntegerInRaw()` casts a null key to `0`, so a collection query can target a row the keyless model does not identify.
- `Query\Builder::whereIntegerInRaw()` is the shared owner of the null-to-zero conversion. Its `whereIntegerNotInRaw()` and both `orWhere` variants delegate to the same method, and the grammar emits its integer values as unbound SQL literals.
- `Collection::only()` warns when `array_flip()` receives null. `except()` is warning-free but treats null as `''` and removes a legitimate empty-string identity.
- `Collections\Collection::duplicates($callback)` chooses its comparator from `$this` after `$items` has been mapped. Eloquent mapping to scalar callback values returns a base collection, but the current code still selects Eloquent's comparator and calls `Model::is()` on an integer.
- The earlier Hypervel comparator branch and three keyless duplicate tests preserve incidental behavior that becomes unreachable once `getDictionary()` skips null. They must be removed rather than left dead or rewritten to pin another unsupported output.
- `Collection::modelKeys()` currently documents `array-key` even though its public behavior includes null for keyless models; correct the PHPDoc while editing the owner.
- `BelongsToMany::buildDictionary()` and `HasOneOrMany::buildDictionary()` document integer-only inner keys even though their associative branches preserve input keys; use the truthful current-upstream array-key shape.
- Integer morph aliases are supported by Laravel's current morph-map PHPDocs and by #60954, but Hypervel's strict `Model::getMorphClass(): string` returns an integer alias directly and throws. `Relation::getMorphAlias()` also loses alias `0` through `?:`.
- `MorphTo::buildDictionary()` drops morph types `0` and `'0'` through a truthiness check. Numeric-string dictionary keys then become integers, which violate four string-only alias parameters during eager loading. Its string-key gather path also filters out valid zero and empty-string foreign keys; the new null foreign-key guard now owns absence.
- The class-string branches of `whereMorphedTo()` and `whereNotMorphedTo()` duplicate inverse lookup without converting an integer alias to the string representation stored in morph columns. This makes class-string and model-instance calls bind different types and can fail or coerce incorrectly across database drivers.
- `MorphTo::associate()` tests the configured owner-key value for truthiness before choosing a column. This writes the primary key for configured owner-key values `0`, `''`, or null, while every read path queries the configured owner-key column. Its inherited scalar native union is required for signature compatibility with `BelongsTo`, but scalar input silently clears both morph columns and leaves the scalar as the loaded relation despite the narrower `MorphTo` PHPDoc.
- Strict morph-map enforcement occurs through `Model::getMorphClass()`, so the class-string branches of `whereMorphedTo()` and `whereNotMorphedTo()` currently bypass it. The negative method also rejects null through its empty-collection path even though the positive method treats null as `IS NULL`.
- A strict query guard must check both morph-map values and keys. A class-shaped registered key is a valid stored morph identity, but checking values alone mistakes it for an unmapped model class. The query call site also holds only a class string; constructing that model merely to create `ClassMorphViolationException` fails for abstract models and can run model boot logic on an exception path.

## Implementation

Work one file at a time. Before each edit, read the complete Hypervel file and complete current Laravel `13.x` counterpart in non-overlapping chunks, plus the relevant tests. Historical pull-request diffs are not implementation input.

### 1. Normalize dictionary keys and port all six #59019 owners

Update `src/database/src/Eloquent/Relations/Concerns/InteractsWithDictionary.php` from current `13.x`, adapted to Hypervel typing:

```php
protected function getDictionaryKey(mixed $attribute): int|string|null
{
    if (is_null($attribute) || is_string($attribute) || is_int($attribute)) {
        return $attribute;
    }

    if (is_object($attribute)) {
        if (method_exists($attribute, '__toString')) {
            return $attribute->__toString();
        }

        if ($attribute instanceof UnitEnum) {
            return enum_value($attribute);
        }

        throw new InvalidArgumentException('Model attribute value is an object but does not have a __toString method.');
    }

    return (string) $attribute;
}
```

Keep the current upstream branch order. If PHPStan cannot infer the enum helper's `int|string` result, narrow that one return locally with `@var`; do not widen `enum_value()`, reorder branches, add a cast that changes enum values, or suppress the error globally.

Merge the current `13.x` guards into:

| Hypervel file | Required result |
|---|---|
| `src/database/src/Eloquent/Collection.php` | `merge()` and `getDictionary()` skip null keys; `diff()` always retains a null-keyed source item; `intersect()` never retains one. Keep `newInstance()` rather than Laravel's `new static` so custom collection state survives. |
| `src/database/src/Eloquent/Relations/BelongsTo.php` | Skip null related keys while building the result dictionary and skip null parent foreign keys while looking up results. Remove Hypervel's `?? ''` fallback. |
| `src/database/src/Eloquent/Relations/BelongsToMany.php` | Skip null parent keys and null pivot foreign keys. Preserve associative result keys and correct the return PHPDoc accordingly. |
| `src/database/src/Eloquent/Relations/HasOneOrMany.php` | Skip null child foreign keys while retaining the already-ported null parent/local-key guard. Correct the dictionary return PHPDoc to allow associative result keys, matching current `13.x`. |
| `src/database/src/Eloquent/Relations/MorphTo.php` | Skip a model when either normalized morph type or foreign key is null. Retain current `13.x` owner-key normalization and null guard already present in Hypervel. |

Do not add comments to these direct guards. Their purpose is clear from the code.

### 2. Complete Collection identity boundaries

Update the same Eloquent `Collection` owner without adding a general identity helper whose callers have different rules.

**`find()`**

For scalar input, return `value($default)` before scanning when the requested key is null. This keeps `Arr::first()`'s lazy closure-default behavior for both `find(null)` and `find(new UnsavedModel)`. Otherwise resolve the candidate model key once inside the predicate, require `$modelKey !== null`, and retain the existing loose comparison.

For array or `Arrayable` input, keep the empty-collection fast path, then resolve the key name from the original first model before filtering. Discard null requested keys and exclude models whose key is null before the existing loose `whereIn()` match. Resolving the key name first avoids dereferencing `first()` after an all-keyless collection has been filtered empty. The two-sided filter prevents `find([null])`, empty-string, and zero requests from returning an unrelated keyless model while preserving valid loose compatibility between non-null keys and the existing return type.

**`fresh()`**

Build a collection-keyed map of existing model keys before the query:

```php
$modelKeys = [];

foreach ($this->items as $collectionKey => $item) {
    if (! $item->exists) {
        continue;
    }

    $key = $item->getKey();

    if ($key === null) {
        throw new MissingAttributeException($item, $item->getKeyName());
    }

    $modelKeys[$collectionKey] = $key;
}
```

Return a new empty collection before querying when the map is empty. Pass only `array_values($modelKeys)` to `whereIn()`. Use the stored keys, normalized through `getDictionaryKey()`, to rebuild the result under the original collection keys, omitting rows that no longer exist. This removes repeated key reads, preserves collection keys and custom `newInstance()` state, removes the current filter/map PHPStan suppression if it is no longer needed, and never queries an unsaved manual key. Add `@throws MissingAttributeException` to this changed public method only.

**`loadAggregate()`**

Resolve each source model key once into a collection-keyed map before the query. Throw `MissingAttributeException($item, $item->getKeyName())` for any null key, regardless of `exists`. Pass the stored values to `whereKey()` and reuse the stored key for aggregate-result lookup and hydration. If every source row has been physically deleted, return the original collection unchanged before inspecting aggregate attributes. If only some rows are missing, skip those source items with a bare closure return while hydrating every surviving row. Do not remove missing source items, synthesize zero aggregate values, clear stale aggregate attributes, throw for a concurrent hard delete, or return `false` from the `each()` callback. Preserve support for soft-deleted models with retained keys, one aggregate query, original attribute/cast synchronization, and the six direct convenience wrappers. The direct method and all six wrappers are also reached through the corresponding model methods; `loadMorphCount()` reaches the same guard on each related-model subcollection. Add `@throws MissingAttributeException` to this changed public method only.

**`toQuery()`**

After the existing empty-collection and mixed-model checks, resolve each model key once while preserving collection keys. Throw `MissingAttributeException($model, $model->getKeyName())` when any key is null, before calling `newModelQuery()` or `whereKey()`. Pass only the validated key array to `whereKey()`. Keep the existing `LogicException` precedence for empty and mixed collections, preserve valid zero keys, and add `@throws MissingAttributeException` alongside the existing exception annotation.

Do not add exception annotations to the older `Model` key helpers or a selected subset of their transitive callers; that would be a separate incomplete audit.

**`only()` and `except()`**

Normalize requested keys and pass them through `Arr::whereNotNull()` before calling `Arr::only()` or `Arr::except()`. This uses an existing primitive, preserves `0` and `''`, and avoids a new helper or duplicated manual callback.

**Types and dead code**

- Correct `modelKeys()` to `@return array<array-key, null|array-key>`; `array_map()` preserves an associative collection's keys, and the public result must continue to include null values.
- Reduce Eloquent `duplicateComparator()` back to the model identity comparison. Delete its keyless fallback and explanatory comment because null keys can no longer enter the no-key `unique()` dictionary.
- Delete the three tests that preserve the former keyless duplicate output. Do not add replacements that promise a new keyless `duplicates()` result.

### 3. Port current #60954 and make morph-map types truthful

Update `src/database/src/Eloquent/Relations/Relation.php`:

- port the current `getMorphedModel()` null early return;
- use the native parameter `int|string|null` and existing `?string` return;
- change the `static::$morphMap` property PHPDoc to `array<int|string, class-string<Model>>`;
- widen both parameter and return PHPDocs on `enforceMorphMap()`, `morphMap()`, and `buildMorphMapFromModels()` to integer-or-string keys. `buildMorphMapFromModels()` must describe integer keys on both sides because its non-list branch returns the supplied map unchanged, while its list branch remains string-keyed by table name;
- preserve list input for table-derived maps, the worker-lifetime warnings, merge behavior, `flushState()`, and `getMorphAlias(): int|string`.

Port the complete current `DatabaseEloquentRelationTest::testGetMorphedModel()` into Hypervel, adding only required Hypervel adaptations such as strict types, namespace, and `: void`. Keep all four upstream assertions: string alias, integer alias, missing alias, and null alias. Remove the stale `Illuminate` test PHPDoc already present in that file while editing it; the local type is evident and the comment is wrong.

No relationship-guide change is needed for this parity/type correction. The public docs already describe the inverse lookup without promising a string-only argument.

### 4. Remove null before raw integer-list casting

Update `src/database/src/Query/Builder.php` at the shared `whereIntegerInRaw()` primitive. After converting `Arrayable` input and flattening nested values, pass the flattened array through `Arr::whereNotNull()` before the existing integer-cast loop:

```php
$values = Arr::whereNotNull(Arr::flatten($values));

foreach ($values as &$value) {
    $value = (int) ($value instanceof BackedEnum ? $value->value : $value);
}
```

Do not add caller-specific filtering to Eloquent `whereKey()`, `whereKeyNot()`, relation eager constraints, or Scout. `whereIntegerNotInRaw()` and both `orWhere` variants already delegate to this one owner. Preserve the existing coercion of non-null numeric/string/enum input and the grammar's established empty-list behavior; no compensating condition or special case is needed.

Keep this as a distinct logical slice from the Eloquent caller guards. The primitive's correct behavior is to remove absent list values, while `Collection::toQuery()` must still reject a keyless model rather than silently build a query for fewer models. Current Laravel `13.x` has the same primitive bug, so this slice can be proposed upstream independently from the Eloquent ports and duplicate-comparator fix.

### 5. Fix duplicate comparison after mapping

Update `src/collections/src/Collection.php` at the owning algorithm:

```php
$compare = $items->duplicateComparator($strict);
```

The mapped collection owns the comparison semantics: scalar callback output is a base collection and needs scalar comparison; identity mapping remains an Eloquent collection and keeps model comparison. Retain `$this->newInstance()` for the return value because the method's `static` return contract requires the caller's collection type. Do not alter `map()`, widen return types, or add a special Eloquent branch.

Add one Eloquent regression for `duplicates('id')`. Existing no-callback tests continue to cover Eloquent model identity, so do not duplicate them.

### 6. Complete integer morph-alias round trips

Update `src/database/src/Eloquent/Relations/Relation.php` so `getMorphAlias()` falls back only when strict `array_search()` returns `false`. A valid alias `0` must remain integer `0` at this inverse-lookup API.

Update `src/database/src/Eloquent/Concerns/HasRelationships.php` at `getMorphClass()`:

- perform one strict `array_search()`;
- return `(string) $alias` only when found, preserving the public string return and every downstream relation/package contract;
- retain the existing pivot and required-map miss behavior;
- restore current Laravel's `@throws ClassMorphViolationException` annotation.

Update `src/database/src/Eloquent/Concerns/QueriesRelationships.php` in the class-string branches of both `whereMorphedTo()` and `whereNotMorphedTo()`:

```php
$model = (string) Relation::getMorphAlias($model);
```

This removes duplicate map searches and makes class-string queries bind the same string value that model association writes. Restore current Laravel's `@throws InvalidArgumentException` annotation on both direct methods; do not add it to the `orWhere` wrappers.

Update `src/database/src/Eloquent/Relations/MorphTo.php`:

- read the raw morph type once and skip only raw `''` immediately;
- normalize the morph type and skip normalized null before reading the foreign key, preserving projected/null-type behavior;
- normalize the foreign key and skip null independently;
- remove `array_filter()` from the string-key branch of `gatherKeysByType()` so zero and empty-string foreign identities survive;
- widen alias-value parameters on `getResultsByType()`, `gatherKeysByType()`, `createModelByType()`, and `matchToMorphParents()` to `int|string`. Column-name parameters and properties remain string.
- in `associate()`, reject non-`Model`, non-null input with `InvalidArgumentException` before changing state, then choose the associated column with `$this->ownerKey ?? $model->getKeyName()`. Do not inspect the owner-key value when choosing between columns.

Do not add a cache, alias helper, downstream union types, or a second serialization format. PHP array keys force numeric strings to integers; the four parameter widenings are the direct representation of that fact.

Update `src/database/src/Eloquent/Concerns/QueriesRelationships.php`:

- resolve class-string morph query values through one protected helper shared by `whereMorphedTo()` and `whereNotMorphedTo()`;
- read the morph map once; when morph maps are required, reject an actual model class absent from both the map values and keys with `ClassMorphViolationException`; check values, then keys with `array_key_exists()`, before `is_a()` so mapped classes avoid autoload work and every stored alias remains valid;
- keep the normal non-strict path as the existing `Relation::getMorphAlias()` lookup and string cast, with no model construction;
- add an early null branch to `whereNotMorphedTo()` that calls `whereNotNull()` with the supplied boolean, and widen its direct and `orWhere` PHPDocs to include null.

Update `ClassMorphViolationException` to accept `object|string`, derive the class name without constructing a model, and pass the class string directly from the helper. Existing object callers remain unchanged. Do not broaden `Relation::getMorphAlias()`, add a public resolver API, or duplicate the strict-map condition in both query methods.

### 7. Document the primary-key requirement at canonical surfaces

Make targeted sentence-level edits at the three canonical sections in two files:

- `src/docs/eloquent.md`, under **Refreshing Models**: state that a persisted model must have its primary key loaded before `fresh()` or `refresh()`, otherwise `MissingAttributeException` is thrown.
- `src/docs/eloquent-collections.md`, under **`fresh($with = [])`**: state the same collection precondition and link to the individual-model refresh section rather than repeating detail.
- `src/docs/eloquent-collections.md`, under **`toQuery()`**: state that every model must have a primary key because the returned builder is constrained by those keys, otherwise `MissingAttributeException` is thrown. Keep this beside the existing canonical `$users->toQuery()->update([...])` example, which demonstrates why incomplete or null-to-zero key constraints are data-safety bugs rather than harmless empty queries.

Do not add a database README difference, Laravel porting-guide entry, or aggregate documentation. The dictionary changes are correctness fixes and Laravel parity; `loadAggregate()` already fails without identity and only gains a useful exception; the refresh and collection-query sections are the places users look for the changed public behavior.

## Test plan

### Red phase on unchanged source

Add or port tests one file at a time and run that file immediately before changing production source. Every new regression must fail on unchanged code through a wrong result, wrong exception, or promoted PHP warning/deprecation. `phpunit.xml.dist` already enables `failOnWarning`; add `--fail-on-deprecation` to promote PHP deprecations. Judge red/green by the process exit status: PHPUnit 13 can print `OK, but there were issues!` while correctly exiting non-zero for a promoted deprecation. Do not add custom error-handler machinery merely to make a test red.

On unchanged source, the initial focused PHP 8.5 files are clean apart from one deprecation triggered only by the three obsolete keyless duplicate tests that this change removes. Treat any other deprecation during implementation as a new finding to investigate rather than suppressing or working around it.

### `tests/Database/DatabaseEloquentCollectionTest.php`

- scalar and array forms of `find()` do not match a null request or an unrelated keyless model and do not let a keyless model shadow legitimate `''` or zero keys. Use a lazy closure default for the scalar null case so the early return proves the existing default semantics are preserved.
- the array `find()` case includes an all-keyless collection, proving the key name is captured from the original first model before filtering and no empty-result dereference occurs.
- `getDictionary()` skips a trailing keyless model instead of overwriting a legitimate `''` entry.
- `merge()` does not let a keyless incoming model overwrite a legitimate `''` entry.
- `diff()` retains a keyless source model when the comparison collection contains a legitimate `''` key.
- `intersect()` does not treat a keyless source model as intersecting a legitimate `''` key.
- `only([null])` returns no model and emits no warning; `except([null])` retains a legitimate `''`-keyed model.
- `duplicates('id')` returns the duplicate scalar values without calling the Eloquent comparator on integers.
- `toQuery()` rejects a null model key with `MissingAttributeException` before invoking `newModelQuery()` or `whereKey()`; retain the existing valid-key and empty-collection tests, and preserve the mixed-model `LogicException` by keeping its source check before the new key loop.
- Remove only the three obsolete keyless duplicate tests. Keep the keyed duplicate and custom-collection-state tests.
- Extend the existing custom-collection-state test with a non-empty `find([1, 2])` result, proving the added filtering preserves the custom collection type and state.

Use real `CollectionModel` instances and `setKeyType('string')` for the empty-string identity; do not add a fixture class solely for that setting.

### `tests/Database/DatabaseQueryBuilderTest.php`

- extend the existing `testWhereIntegerInRaw()` with `[1, null]` and assert the exact SQL is `select * from "users" where "id" in (1)`, not `in (1, 0)`;
- extend the existing `testWhereIntegerNotInRaw()` with `[1, null]` and assert the exact SQL is `select * from "users" where "id" not in (1)`, not `not in (1, 0)`;
- retain the existing ordinary, nested, enum, `orWhere`, and empty-list coverage. Do not duplicate the two assertions through every delegating wrapper: both polarity and all wrappers share `whereIntegerInRaw()`.

### Relation matching tests

- `tests/Database/DatabaseEloquentBelongsToTest.php`
  - cover both guards independently: a null related key cannot match a parent foreign key `''`, and a null parent foreign key cannot match a related key `''`;
  - extend the existing matching coverage with related/foreign values `1.5` and `1.9`, proving current float-to-integer array-key collision and deprecation are gone;
  - type the touched builder/related properties and inline foreign-key fixtures with the narrowest truthful native types, using `mixed` only where the same fixture intentionally receives several forms.
- `tests/Database/DatabaseEloquentBelongsToManyWithCastedAttributesTest.php`
  - prove a null parent key does not match a pivot key `''`;
  - prove a null pivot key does not match a parent key `''`;
  - type the touched fixture property.
- `tests/Database/EloquentHasOneOrManyDeprecationTest.php`
  - add a child with a null foreign key and a parent with local key `''`; assert no match;
  - retain both existing parent-null tests and type the fixture property as `mixed = null`.
- `tests/Database/DatabaseEloquentMorphToTest.php`
  - add a model with a real morph type and null foreign key; assert it is absent from the eager-load dictionary;
  - add one dictionary case proving morph types `0` and `'0'` are retained while `''` and null are skipped; omit the foreign-key property from the null-type fixture so the guard order is observable without custom error handling;
  - widen the existing `AccessibleMorphTo` proxy parameter to `int|string`;
  - retain the current object owner-key normalization test and type the touched builder/related properties.

Do not add a separate boolean-key matrix or direct protected-trait test. The float BelongsTo case proves scalar normalization through a public relation path; existing enum and stringable relation tests cover the object branches.

### Identity-query integration tests

- `tests/Integration/Database/EloquentCollectionFreshTest.php`
  - with missing-attribute strictness disabled, a persisted projection without its primary key throws `MissingAttributeException`; enable the query log immediately before the call, invoke it inside `try`, call `$this->fail()` on the next line, catch only `MissingAttributeException`, assert its message, then assert the failed operation issued no query. Do not use `expectException()`, because it would skip the query-log assertion;
  - a collection containing only an unsaved model with a manually assigned key returns an empty collection without issuing a query;
  - retain the existing deleted-row behavior.
- `tests/Integration/Database/EloquentCollectionLoadCountTest.php`
  - with missing-attribute strictness disabled, a projection without its primary key throws `MissingAttributeException` through `loadCount()`; use the same narrow `try` / fail / catch pattern and assert the query log remains empty after the catch;
  - retain the existing soft-deleted-model test as the control proving that `exists === false` with a retained key remains supported;
  - physically delete one source row through a separate query, assert the aggregate load still issues one query, hydrates the surviving row, and leaves the missing source item without a synthesized aggregate attribute;
  - physically delete every source row through a separate query, assert the aggregate load still issues one query and returns the same collection with its source attributes unchanged.

### Complete upstream test port

- `tests/Database/DatabaseEloquentRelationTest.php`
  - port the complete current `13.x` `testGetMorphedModel()` from #60954 with all four assertions;
  - extend the existing inverse-alias test with a non-list map and strict assertion that alias `0` is returned as integer `0`;
  - retain the existing numeric morph-map and alias tests.

### Integer morph-alias integration and query tests

- `tests/Integration/Database/EloquentMorphToEagerLoadTest.php`
  - add a dedicated plain string-key model/table with no cast, `incrementing = false`, and `keyType = 'string'`;
  - configure a non-list morph map with alias `0`, associate a new comment to a model whose primary key is `'0'`, and assert the in-memory morph type is exactly string `'0'` before saving;
  - eager-load only that comment and assert it resolves the same model. This covers string serialization, integer dictionary aliases, `createModelByType()`, zero preservation in `gatherKeysByType()`, and parent matching in one supported round trip.
- `tests/Database/DatabaseEloquentBuilderTest.php`
  - after the existing morph-map alias test, add one method covering both `whereMorphedTo()` and `whereNotMorphedTo()` class-string calls under alias `0`;
  - assert both bindings are exactly `['0']` with `assertSame()`; do not duplicate the setup in separate tests or repeat the delegating `orWhere` wrappers.

### Morph association and query policy tests

- `tests/Database/DatabaseEloquentMorphToTest.php`
  - use real parent and related models and construct the relation under `Relation::noConstraints()` so strict assertions do not rely on Mockery's loose argument matching;
  - prove a configured owner-key value `0` is written as integer `0` rather than falling back to a different primary key;
  - prove a configured owner-key value null writes null rather than a different primary key; omit a separate `''` case because no value branch remains;
  - prove scalar input throws `InvalidArgumentException` before either stored morph column or the loaded relation changes.
- `tests/Database/DatabaseEloquentBuilderTest.php`
- make the existing mapped class-string query test use `enforceMorphMap()`, proving mapped classes remain valid in strict mode;
- add separate positive- and negative-query regressions proving an unmapped model class throws `ClassMorphViolationException`; both direct methods own a distinct string branch even though resolution is shared;
- add one shared-setup test proving a registered class-shaped alias and an unregistered plain stored alias both bind unchanged under strict maps;
- add one test proving an abstract unmapped model class throws `ClassMorphViolationException` rather than being instantiated;
- add one direct `whereNotMorphedTo(..., null)` assertion for exact `IS NOT NULL` SQL and no bindings. Existing `orWhereNotMorphedTo()` tests already prove boolean forwarding, so do not duplicate the null branch through the wrapper.

Laravel #59019 introduced no tests, and current `13.x` has no later dedicated regressions for those guards. The focused Hypervel tests above supply the missing coverage without inventing unsupported keyless set semantics.

## Verification and review

1. During the red phase, run each changed test file immediately with `./vendor/bin/phpunit --no-progress --fail-on-deprecation <file>` and confirm its new regression fails for the intended old behavior by checking the non-zero exit status, not PHPUnit's summary wording. Warning failure already comes from `phpunit.xml.dist`.
2. After each source file, rerun its focused test method or file. A file that contains regressions for a later source slice may be filtered until that slice is implemented; run the complete file once all of its owners are updated.
3. Run all eleven changed test files together on the local PHP version with the configured warning failure and `--fail-on-deprecation`.
4. Run the same focused files in `ghcr.io/hypervel/components-ci:php8.5-swoole6.2.2` with `--fail-on-deprecation`. This directly verifies the PHP 8.5 failures without reproducing the entire CI matrix.
5. Run targeted PHPStan while finalizing `InteractsWithDictionary` and `Collection` typing. Fix real declarations first; use one local `@var` for `enum_value()` only if needed. Add no global ignore.
6. At the completed checkpoint, run `composer fix` once. It owns formatting, both PHPStan configurations, the parallel component suite, Testbench package tests, and dogfood tests; do not rerun those full commands separately at the same checkpoint.
7. Inspect `git diff --check`, the full branch diff, and every changed method's callers/callees. Confirm current `13.x` parity, valid zero/empty/enum/stringable behavior, raw integer-list behavior through Eloquent `whereKey()` / `whereKeyNot()`, relation eager constraints, and Scout, custom collection state, deleted-model aggregate behavior, all seven direct collection aggregate entry points and their model/morph delegations, `toQuery()`'s empty/mixed/key validation order, integer morph aliases through writes, inverse lookup, eager loading, and both class-string query polarities, morph-map static cleanup, and absence of stale comments, tests, ignores, imports, or documentation.
8. Request peer code review after the full self-review. Apply review fixes one file at a time, rerun affected tests, and repeat the full checkpoint only if the fixes can affect wider checks.

## Completion criteria

- Every file and every test introduced by Laravel #59019 and #60954 is accounted for, with current `13.x` source and tests—not historical patches—used for implementation.
- The changed dictionary paths emit no null-offset, float-key, or `array_flip()` issues on PHP 8.5, and raw integer `IN`/`NOT IN` lists never convert null into literal zero.
- Null cannot alias `''`; valid `''`, zero, enum, stringable, integer, and float identities retain the documented behavior above.
- Missing identity fails before refresh or aggregate SQL, or before a collection query's key constraint, with `MissingAttributeException`; unsaved refresh input performs no query; soft-deleted aggregate input with a retained key remains valid; `toQuery()` cannot turn null into integer key `0`.
- Integer and null morph aliases work and all morph-map types describe integer and string keys truthfully.
- Integer morph aliases serialize to string morph-column values and work through association, eager loading, inverse lookup, and positive/negative class-string morph queries, including alias and foreign key zero.
- Morph association uses the configured owner-key column for zero, empty-string, and null values; scalar input fails before mutation; strict maps reject unmapped model class strings in both query polarities without instantiating them, while stored aliases remain queryable; and null positive/negative morph predicates compile as `IS NULL` / `IS NOT NULL`.
- Callback duplicate detection works for scalar mapped values while no-callback Eloquent comparison remains intact.
- The obsolete comparator branch, its comment, its three tests, stale test typing/docs, and superseded PHPStan suppression are gone.
- Canonical refresh and `toQuery()` documentation is accurate, with no duplicate README or porting-guide entry.
- `composer fix`, focused PHP 8.5 checks, final self-review, and peer review are green.
