# Data Automatic Lean Execution

## Status

- Target repository: `contrib/hypervel/components-data-fix`.
- Target branch: `feature/data-lean-tier`, created from `0.4`.
- Implementation is in progress.
- This follow-up supersedes the completed package plan where its measured hot-path decisions differ, including the old monolithic `directArrayCreation` and `plainTransform` predicates. Unrelated package design remains unchanged.
- The existing `Data`, `Dto`, and `Resource` APIs remain the only public data-object model. Do not restore `Hypervel\Support\DataObject`, add a fast-mode API, or expose recipe selection to applications.

## Outcome

Make ordinary `Data::from()` and `toArray()` work proportional to the declared values they actually need to process, while preserving the complete package contract. Immutable metadata selects a lean internal recipe automatically. Unsupported declarations, customized operations, validation, and runtime misses continue through the existing fixed engine.

The result must:

- materially reduce construction of scalar, coercing, enum, date, and nested data objects;
- retain the current fast bulk-copy transformation for plain scalar objects and extend automatic lean transformation to mapped, enum, date, and nested properties;
- resolve the fixed Data service graph after application providers boot and before the production server forks workers, so the first request does not pay its initialization cost without slowing unit-test application boot;
- avoid PHPDoc parsing when native declarations prove iterable item metadata cannot be used;
- preserve every current API, exception, mapping, factory, hook, lazy, partial, validation, resource, and persistence behavior;
- remain one construction and transformation engine with one general fallback, not two implementations that can drift.

## Constraints

1. Follow the root `CLAUDE.md` and components `AGENTS.md` in full.
2. Public Laravel and Spatie-style ergonomics are unchanged. `from()`, `factory()`, `collect()`, `transform()`, `all()`, and `toArray()` keep their signatures and extension boundaries.
3. Recipes are immutable worker-owned metadata. Payloads, constructed objects, operation state, and request state must never enter them.
4. Each root creation keeps one operation memo. Nested lean and general nodes share it and never redispatch through a public `from()` method.
5. A recipe miss falls into the current general path before a constructor runs. Never construct speculatively and retry.
6. Do not duplicate casts, date parsing, enum errors, nested partial handling, finalization, or instantiation. Extract the lowest shared primitive when both paths need the same behavior.
7. Retain a specialization only when same-machine p50 and p95 measurements show a repeatable material improvement. Do not trade clarity or correctness for benchmark noise.
8. Worker memory remains naturally bounded by used Data classes and their declared properties. Do not add eviction, discovery, generated metadata, cache commands, or deploy files.
9. If implementation requires a second construction engine, a public mode, or duplicated feature logic, stop and notify the owner before proceeding rather than forcing this design or restoring `DataObject` silently.

## Research Baseline

### Current architecture

The package already follows the fixed-flow direction of the Spatie v5 draft at `/home/binaryfire/workspace/references/spatie/laravel-data` (`origin/v5` at `927870922b`): one `ConstructionState`, one immutable `CreationContext`, direct action calls, and no configurable resolver pipeline. The proposed work specializes that engine; it does not replace its architecture or copy Spatie's request-lifetime cache machinery.

Relevant current boundaries:

- `BaseData::from()` calls `static::factory()->from()`. Every call resolves the worker-shared `DataCreator`, allocates a `CreationContextFactory`, and builds a new 18-field `CreationContext`. The late-static factory dispatch is an existing extension boundary and must remain intact.
- `DataCreator::execute()` decides validation, then allocates `ConstructionState` before `fillNode()` can attempt the exact-array exit.
- `fillNode()` matches a named factory and `tryCreateDirectArrayNode()` accepts only already-correct values. The first value requiring a fixed built-in, enum, date, or nested Data conversion sends the complete node through normalization, state population, casting, and bottom-up construction.
- `createUnvalidatedNode()` currently serves deferred collection items and `LazyCollection` mapping, and allocates state before factory matching or the direct attempt. This plan also makes it the shared nested-value boundary.
- `DataTransformer::transformData()` has a safe `plainTransform` bulk-copy exit. Mapped, enum, date, and nested declarations use the general property loop even when no partial, lazy, or custom transformer can apply.
- `DataIterableAnnotationReader` constructs the PHPStan lexer/parser with the Data service graph. `DataClassFactory` asks it to inspect constructor, class, and property PHPDoc even when native types make iterable item metadata unusable.
- `DataServiceProvider::boot()` warms `DataConfig`, but not `DataCreator` or `DataTransformer`. Package discovery loads this provider only in applications that install `hypervel/data`; the monorepo root registers it so the component test environment exercises the complete package graph.

### Reproduced comparison

The scratch baseline used the exact removed `DataObject` source from `2454bbabb^`, the current branch, PHP 8.4.23 on Linux x86-64 with CLI OPcache and JIT disabled, nine repeated batches, and fresh PHP processes for cold boundaries. Each implementation received a valid configured date format. These are planning measurements from one machine, not release claims.

Construction p50:

| Scenario | Old `DataObject` | Current `Data` | Ratio |
| --- | ---: | ---: | ---: |
| Flat, five scalars | 1.812 us | 5.961 us | 3.29x |
| Defaults | 1.161 us | 5.133 us | 4.42x |
| Wide, twenty scalars | 6.258 us | 13.553 us | 2.17x |
| Scalar coercion | 1.967 us | 32.934 us | 16.74x |
| One nested level | 4.032 us | 28.664 us | 7.11x |
| Three nested levels | 5.077 us | 42.861 us | 8.44x |
| Backed enum | 1.388 us | 18.632 us | 13.42x |
| Date | 5.448 us | 23.122 us | 4.24x |
| Mixed API payload | 9.179 us | 69.354 us | 7.56x |
| 1,000-item `from()` loop, per item | 1.859 us | 6.051 us | 3.25x |

Transformation p50:

| Scenario | Old `DataObject` | Current `Data` | Ratio |
| --- | ---: | ---: | ---: |
| Flat, uncached | 0.659 us | 1.736 us | 2.64x |
| Wide, uncached | 1.797 us | 2.436 us | 1.36x |
| Nested tree | 1.427 us | 5.738 us | 4.02x |
| Deep tree | 1.876 us | 8.227 us | 4.39x |
| Nested `json_encode()` | 2.042 us | 6.654 us | 3.26x |
| 1,000 objects, per item | 0.617 us | 1.715 us | 2.78x |
| Property read | 29.7 ns | 30.8 ns | 1.04x |

The comparison confirms the need, but does not define the implementation. `Data` performs much more work and must keep its richer semantics. The goal is to remove work proven unnecessary for each declaration, not to copy `DataObject`'s behavior or stale per-instance array cache.

### Isolated costs

| Default creation layer | p50 |
| --- | ---: |
| Native constructor | 175 ns |
| `Data::from()` | 6.012 us |
| Prepared `CreationContextFactory::from()` | 5.099 us |
| `DataCreator::create()` with retained context | 3.858 us |
| `CreationContextFactory::get()` | 857 ns |
| `Data::factory()` | 554 ns |

A cached immutable default context removes the measured 857 ns `get()` layer while retaining fresh factories and late-static dispatch. The remaining gap requires attempting the recipe before `ConstructionState` allocation.

| Fresh fixed service boundary | p50 |
| --- | ---: |
| Annotation reader | 2.687 ms |
| Class factory | 4.613 ms |
| Class repository | 4.975 ms |
| Validator | 13.594 ms |
| Creator | 15.714 ms |
| Transformer | 6.150 ms |
| Creator and transformer sequentially | 16.413 ms |

Fresh-process first use measured 17.951 ms for the first Data class and 170.7 us for another class after the services were warm. The fixed dependency graph, especially Validation, dominates first use; parser construction is a separate measurable part.

| Transformation layer | p50 |
| --- | ---: |
| Manual flat array | 101 ns |
| Transformer, flat | 1.127 us |
| Public flat `toArray()` | 1.727 us |
| Manual nested array | 188 ns |
| Transformer, nested | 4.520 us |
| Public nested `toArray()` | 5.535 us |

The current flat bulk-copy path is already compact. It is a regression guard, not code to replace with an unconditional per-property recipe loop.

## Design

### One automatic engine

There is no new public concept. The same declaration may contain lean and general nodes:

1. Public `from()` retains `static::factory()` dispatch; the default implementation creates a fresh factory seeded with a worker-cached immutable default context.
2. The creator determines validation and named-factory behavior exactly once.
3. An eligible array node runs its immutable construction recipe.
4. A child whose declaration is not eligible enters the existing general Fill path, while eligible siblings remain lean.
5. Transformation independently selects bulk copy, a fixed output recipe, or the current general loop.

Do not specialize `collect()` separately. Albert's collection construction figure is a 1,000-item `from()` loop, and current eager/lazy collection operations deliberately share one root state and validation graph. Per-node recipes improve ordinary collection casting through the existing internal boundary without another collection engine.

### Recipe metadata

Replace `DataClass::$directArrayCreation` with a nullable typed creation recipe. Replace `DataClass::$plainTransform` with a `bulkCopyTransformation` boolean and a nullable fixed transformation recipe. Keep `directConstructorInstantiation`; it proves a distinct constructor invariant used after a creation recipe succeeds.

Add these internal immutable types:

- `Enums\DataPropertyOperation`: `Copy`, `Builtin`, `Enum`, `Date`, and `Data` cases.
- `Support\Creation\DataCreationRecipe`: an ordered list of eligible `DataProperty` metadata.
- `Support\Transformation\DataTransformationRecipe`: an ordered list of visible `DataProperty` metadata for fixed per-property operations.

Store nullable `constructionOperation`, `constructionTarget`, and `transformationOperation` fields directly on the immutable `DataProperty` that owns the rest of the per-property metadata. Do not allocate a forwarding recipe wrapper around every property.

Recipes retain only ordered references to existing class/property metadata. They must not retain closures, contexts, payloads, application instances, constructed Data objects, or mutable extensions. A nested property's target stores the child class string and resolves that child's already-cached metadata only when the value is reached; metadata construction must not recurse across the class graph.

`DataClassFactory` owns recipe compilation:

- A construction recipe is unavailable for abstract/property-morphable classes, class or configured normalizers, contextual parameters, AutoLazy, `LoadRelation`, property/configured casts, Data collectables, or typed iterables.
- Construction classification follows the general engine's conversion priority: Data, declared `Castable`, date, enum, then built-in. Stop at the first family present. Compile its fixed operation only when that family has one unambiguous target; otherwise compile `Copy`. Any declared `Castable` arm also compiles `Copy` because its behavior belongs to the general cast boundary. Every operation first accepts a value already valid for the complete declared union, so accepted values remain lean while values needing an ambiguous, custom, or unsupported conversion miss to the general path before side effects.
- Pin the four order-sensitive mixed declarations: ambiguous Data before date, `Castable` before date, ambiguous date before enum, and ambiguous enum before built-in. Two change success or failure: `Castable|DateTimeImmutable` must retain the general path's successful cast, while `StatusA|StatusB|int` must raise the general path's enum error instead of accepting a built-in integer. The other two must retain the general path's exact exception class and message.
- Construction recipes retain computed properties as `Copy` entries regardless of their declared type, but never execute that operation: absence emits no constructor value and presence throws the existing supplied-value exception. This avoids making an otherwise eligible class general merely because a class-owned value has an object type.
- A transformation recipe is unavailable for lazy, Optional, mixed, custom-transformer, Data-collectable, typed-iterable, or arbitrary object behavior. Hidden properties are omitted. Static output mappings, scalar/array copies, one enum, one date, and one nested Data class are supported.
- Resolve that complete eligibility classifier before checking the narrower bulk-copy proof. A class uses bulk copy only when the eligible recipe exists and every property is visible, unmapped, and `Copy`; store the boolean and discard the recipe so bulk classes retain no unused ordered property array. Do not consult the narrow bulk proof independently because `Copy` types can still have a property transformer or Data iterable annotation.
- The boolean and nullable recipe form three mutually exclusive states: bulk copy (`true`, `null`), fixed property operations (`false`, recipe), or the general loop (`false`, `null`). Document this invariant on `DataClass`.
- Unlike `0.4`'s plain predicate, an unannotated array property may use bulk copy because the complete classifier proves it has no iterable item metadata or transformer and the no-partials path copies it unchanged. Annotated Data arrays remain general.

Update metadata tests to assert the three transformation states plus ordered recipe properties and operation/target fields. Measure retained memory for a representative 500-class graph before accepting the object shape. Expected growth is bounded to the low single-digit megabytes for an intentionally large graph; if the two class-level property lists are materially wasteful, measure an existing-metadata alternative rather than add cache eviction or compact untyped arrays.

### Default factory entry

Keep `BaseData::from()` routing through `static::factory()->from()`. This preserves an existing late-static extension boundary and the familiar Spatie call chain.

`DataCreator` caches one immutable default Create context per used Data class. `DataCreator::factory()` still returns a newly constructed `CreationContextFactory`, preserving its existing fresh-instance semantics, and seeds it with the cached context.

```php
public function factory(string $class): CreationContextFactory
{
    $factory = new CreationContextFactory(
        $this,
        $this->config,
        $class,
        $this->defaultContexts[$class] ?? null,
    );

    $this->defaultContexts[$class] ??= $factory->get();

    return $factory;
}
```

On the first factory for a class, `get()` builds and memoizes the default Create context on that factory before the creator records it; later factories receive the cached context directly. Every fluent mutator clears the factory's nullable Create-context field at the same point it changes state. `get(CreationMode::Create)` returns the cached immutable context or rebuilds it once after a mutation; Validate and Rules modes continue building their mode-specific contexts. Repeated use of an unchanged customized factory may reuse its rebuilt immutable context, because all operation state lives below that boundary.

The default-context cache is a normal property on the auto-singleton creator. It has one entry per used Data class, the same bounded key space as `DataClassRepository`, and retains no payload, operation, request, validator, or resolved extension state. It needs no static cleanup or eviction. Do not add a factory-prototype cache unless later measurements prove cloning materially beats ordinary factory construction.

`execute()` retains the existing supplied-instance exit and authoritative validation decision. Only Create mode with one payload and no validation/rule compilation delegates to the enhanced `createUnvalidatedNode()`. Multiple payloads, Request/Always validation, Validate mode, and Rules mode keep the current stateful flow.

### Lean node construction

Refactor named-factory handling so it cannot run twice:

- `fillNode()` continues matching factories for validation and multi-payload graphs, then delegates the post-factory work to one internal helper.
- `createUnvalidatedNode()` gets class metadata and matches the factory before allocating state. A returned target object exits. An array result may use the recipe. Any other result goes to the post-factory general helper without rematching.
- On a recipe miss, allocate `ConstructionState` once and continue through that same helper. Constructors are never invoked during eligibility checks or misses.

Attempt the recipe only when all of these runtime facts hold:

- `CreationMode::Create`;
- validation and rule compilation are authoritatively false;
- the post-factory value is one array;
- class metadata has a construction recipe;
- operation `casts`, `normalizers`, `prepareDataHooks`, `beforeCreationHooks`, and `afterCreationHooks` are empty.

Do not gate on `beforeValidationHooks`, `beforeRulesHooks`, `afterRulesHooks`, `withValidatorHooks`, or `afterValidationHooks` after validation/rule compilation is known to be false; those hooks cannot execute in that operation. Both property-name mapping modes remain eligible. For mapping enabled, use the compiled mapped path first and the raw property path as the existing fallback. For mapping disabled, read only the raw property path.

For every property:

1. Missing computed properties are omitted; a supplied computed/virtual value immediately throws the existing `CannotSetComputedValue` exception at the same property-order boundary as general Fill.
2. Missing declared defaults are omitted so PHP supplies the exact constructor/property default.
3. Missing Optional properties receive `Optional::create()`.
4. Missing nullable properties receive `null`.
5. A missing required property is omitted and forces ordinary `instantiate()` for that node, even when direct construction was otherwise eligible. Conversions run before instantiation exactly as in the general path, then the ordinary instantiator owns the established missing-constructor/property exception.
6. Explicit `null` and `Optional` remain unchanged.
7. Already-correct values remain unchanged.
8. Built-in, enum, date, and nested Data operations use their fixed conversion.
9. Any unsupported runtime value misses before construction.

The recipe reads raw keys and one-segment mapped keys directly from the proven array payload with `array_key_exists()`, preserving explicit `null`. Only multi-segment mapped paths use `SourceReader`; a mapped miss still falls back to the raw property name when the names differ. After explicit `null` and `Optional` values have exited, runtime acceptance calls the underlying `Type::acceptsValue()` directly because `DataType`'s nullable and mixed wrapper checks are then redundant. Keep a short comment at each bypass naming the local precondition; these shortcuts must not be copied into source paths that also accept `Normalized` values or have not handled nullable values.

Recipe execution preflights the complete node before any conversion with side effects or exceptions. The first pass reads every property, applies presence/default/Optional/null and runtime-shape checks, records raw values, and records only values that still require conversion. If that conversion list is empty, the exact-value case instantiates after the single property pass. Otherwise, only the recorded conversions run after the node is known not to miss; nested creation runs at this point, and cast exceptions propagate directly rather than retrying. This prevents an early nested factory, hook, or constructor from running twice when a later property requires the general path without adding a second traversal to the current exact-value fast case.

Nested `Data` operations call `createUnvalidatedNode()` with the same context and operation memo. This allows each child to choose its own recipe or general fallback and preserves custom extension resolution once per root. Deferred AutoLazy replay must consume the state-owned post-Fill value at the property's compiled input path, not the stale closure argument. This preserves the nested source while ensuring directly finished nested and collectable values, including named-factory results, are constructed exactly once.

Successful properties use `instantiateDirect()` only when `directConstructorInstantiation` is true. All other recipe successes use the ordinary `DataInstantiator::instantiate()`, retaining constructor visibility, variadic, missing-parameter, contextual, non-promoted assignment, and property-default behavior.

### Shared fixed conversions

Add one internal `Support\Creation\ValueCaster` containing the pure low-level built-in, backed-enum, and date conversions. `BuiltinTypeCast`, `EnumCast`, and `DateTimeInterfaceCast` delegate to it, and the recipe calls the same methods directly.

The extraction must preserve exactly:

- case-insensitive string `true`/`false` handling and PHP coercion for other built-ins;
- already-correct and other-backed-enum handling, using current `0.4`'s shared `enum_from()` coercion semantics;
- `CannotCastEnum` and `CannotCastDate` types and messages;
- ordered date formats, fractional-second trimming, source timezone, target timezone, concrete mutable/immutable targets, and the Hypervel Date factory boundary;
- `Uncastable` when a cast has no applicable target.

Do not store Cast instances in worker metadata or duplicate reduced conversion code inside `DataCreator`.

### Lean transformation

Keep two focused execution methods: `transformBulkCopy()` owns the existing plain array operations, while `transformUsingRecipe()` owns fixed per-property operations. Choosing between them at the call site removes unused arguments and a branch from bulk copy without duplicating transformation behavior. `transformData()` still checks maximum depth before metadata or recipe execution and always routes the result through `finalizeTransformation()`.

The recipe gate is structural, not dependent on identity of a cached context. Test the class discriminator before context work so general-only classes do not pay partial-tree checks:

```php
if ($dataClass->bulkCopyTransformation) {
    if (! $context->constructable
        && $context->transformers === []
        && ! $context->hasPartials()
    ) {
        // Execute bulk copy and finalize.
    }
} elseif (($recipe = $dataClass->transformationRecipe) !== null) {
    if ($context->transformValues
        && ! $context->constructable
        && $context->transformers === []
        && ! $context->hasPartials()
    ) {
        // Execute fixed property operations and finalize.
    }
}
```

Keep the repeated context guards inline. A helper call would cost more than the dispatch work it removes and would obscure the distinct non-transforming bulk boundary.

The fixed property loop uses the same value-read rules as the general loop: public get hooks own their logical value, declared backing storage is read without exposing runtime properties, and uninitialized properties are omitted. It selects the mapped or raw output name from `context->mapPropertyNames`.

- `Copy` writes the value unchanged.
- `Enum` and `Date` call the existing shared fixed transformation behavior.
- `Data` creates the child context with `child($property->name, resolveWrapExecutionType(...))`, then calls `transformNested()`. That method remains the sole boundary that merges instance partials before `transformData()`.
- `null` is copied before the operation.

Bulk copy remains available to non-transforming contexts such as `all()` because it emits declared values unchanged. Fixed output recipes require value transformation because they convert enum, date, and nested values. Both paths remain unavailable for Eloquent persistence (`constructable` is true), partial selections, runtime transformer overrides, lazies, collectables, and unsupported property shapes. Wrapping and additional data remain in `finalizeTransformation()`. `transform()` remains the only overridable public transformation boundary, so `all()`, resources, and both Eloquent casts retain existing override behavior.

### PHPDoc and process boot

Make `DataIterableAnnotationReader` construct its lexer/parser lazily on the first eligible non-empty PHPDoc comment. It remains an auto-singleton; nullable instance fields are sufficient and require no static cache or cleanup.

Add a native-type predicate used before each read:

- skip a constructor docblock only when none of its parameter types can carry iterable item metadata;
- skip an individual property docblock only when its native type cannot carry it;
- skip the class-level inheritance walk only when no reflected property can carry it;
- keep current constructor, inline property, nearest class, and parent precedence unchanged.

Parsing is required for no type, `mixed`, `object`, arrays, `iterable`, Traversable/Enumerable/paginator/Data-collectable families, and any union or intersection containing a possible arm. A type is impossible only when every arm is a scalar built-in, null, backed enum, Data class, date class, `Optional`, or the package-owned `Lazy` family. Keep conservative parsing for other class types so user collection implementations are not silently reclassified.

The measured fixed graph is request-visible today. `DataServiceProvider::boot()` continues resolving `DataConfig`, then, unless `Application::runningUnitTests()` is true, registers an application `booted` callback that resolves `DataCreator` and `DataTransformer` in that order. Waiting until the application is booted lets later providers finish configuring shared services before the graph is retained. During production server startup this runs once in the booted master process before Swoole forks workers; the workers inherit the resolved auto-singletons. Applications pay this fixed CPU cost only when they install and discover `hypervel/data`. This moves roughly 16 ms out of the first request without slowing each Testbench application, class discovery, I/O, generated cache files, or deployment configuration. Class metadata remains demand-built because discovering application Data classes would add filesystem work and deployment machinery.

Provider tests must prove unit-test application boot resolves only stable `DataConfig`, while an isolated non-testing application runs the post-provider-boot callback and retains stable `DataCreator` and `DataTransformer` instances. The lazy parser test must inspect the reader's private nullable parser fields through reflection: simple scalar metadata leaves them uninitialized, while an eligible generic declaration initializes one parser pair and preserves annotation precedence. Do not add a production inspection API for this test.

## File Map

### Source

- Modify `src/data/src/DataServiceProvider.php` to warm the fixed creator/transformer graph.
- Modify `src/data/src/Support/DataClass.php` to hold nullable recipes instead of the two old booleans.
- Add `src/data/src/Enums/DataPropertyOperation.php`; modify `src/data/src/Support/DataProperty.php` to store its construction/transformation operation metadata.
- Add `src/data/src/Support/Creation/DataCreationRecipe.php`.
- Modify `src/data/src/Support/Creation/CreationContextFactory.php` for immutable Create-context reuse with explicit invalidation by fluent mutators.
- Modify `src/data/src/Support/Creation/DataCreator.php` for cached default contexts, pre-state factory/recipe selection, shared fallback, and recursive recipe execution.
- Add `src/data/src/Support/Transformation/DataTransformationRecipe.php`.
- Modify `src/data/src/Support/Transformation/DataTransformer.php` for bulk-copy/fixed recipe execution.
- Modify `src/data/src/Support/Factories/DataClassFactory.php` for recipe compilation and annotation screening.
- Modify `src/data/src/Support/Annotations/DataIterableAnnotationReader.php` for lazy parser construction and native-type eligibility.
- Add `src/data/src/Support/Creation/ValueCaster.php`; modify the three built-in cast adapters to delegate.

No changes are currently warranted in Validation, Container, HTTP, Foundation, Database, Inertia, or Saloon: the required extension points and first-party integrations already exist. Any defect exposed while tracing implementation still follows the normal stop, investigation, and root-fix workflow.

### Tests and benchmarks

- Update `tests/Data/DataServiceProviderTest.php`.
- Update `tests/Data/Support/DataClassTest.php` and its fixtures for recipe metadata.
- Update `tests/Data/Support/Creation/DataCreatorTest.php` for fresh factory/default-context reuse, recipe success/fallback, and equivalence.
- Update `tests/Data/Support/Transformation/DataTransformerTest.php` for output recipes and boundary guards.
- Update `tests/Data/Support/DataIterableAnnotationReaderTest.php` and annotation fixtures for screening and lazy construction.
- Update the built-in, enum, and date cast tests to pin shared conversion behavior.
- Add the historical mapper under `tests/Benchmarks/Data/Fixtures/DataObject.php`, adapting only its namespace to the test PSR-4 root, and require it only from the dedicated comparison harness.
- Add `tests/Benchmarks/Data/compare-data-object.php`; update `benchmark.php` with missing Data-only scenarios and update the benchmark README.

No user documentation or Laravel porting-guide change is required because the public contract is unchanged. Keep the existing Spatie v5 reconciliation item in `docs/todo.md` unchanged.

## Implementation Order

1. Add the reproducible historical comparison harness and record the pre-change JSON/CSV reports outside the repository.
2. Add typed recipe metadata and replace the old eligibility booleans. Run `DataClassTest` and memory measurements before changing execution.
3. Extract and test `ValueCaster`; run the three cast test files and verify exact exceptions.
4. Add cached default contexts and the pre-state node recipe/fallback flow. Run creator, factory, named-factory, collection, lazy, and contextual tests after each coherent change.
5. Add transformation recipe execution while retaining bulk copy. Run transformer, partial, Eloquent cast, resource, and response tests.
6. Add PHPDoc screening/lazy parser and run annotation/type metadata tests.
7. Register post-provider application-boot warming for creator and transformer outside unit tests, then run provider and package integration tests.
8. After an owner-authorized checkpoint commit, merge current `0.4` into the branch. Preserve its shared enum coercion in `ValueCaster` and morph resolution, then run `EnumCastTest`, `DataCreatorTest`, `FormRequestCastTest`, and `CapabilityTest` before benchmarking.
9. Rerun the comparison and standard benchmark harnesses. Remove any specialization that does not earn its code or regresses p95/memory materially.
10. Run `composer fix`, perform a complete caller/callee and edge-case self-review, then obtain code-review signoff.

## Test Plan

### Metadata

- Recipe present for flat built-ins, defaults, static mappings, enums, dates, nested Data, non-promoted public properties, inheritance, readonly promotion, computed output, and supported property hooks.
- Construction recipe absent for abstract/morphable, contextual, AutoLazy, `LoadRelation`, custom/configured casts, configured/class normalizers, Data collections, and typed iterables. An ambiguous conversion family or declared `Castable` arm keeps the class recipe but compiles that property as `Copy`, allowing accepted values to stay lean while conversion-required values fall back. Transformation recipe absent for custom/configured transformers, lazy, Optional, mixed, Data collection, typed iterable, ambiguous transform unions, and arbitrary objects.
- Transformation metadata distinguishes plain unannotated arrays (`true`, `null`), property-transformed and Data-annotated arrays (`false`, `null`), fixed non-bulk declarations (`false`, recipe), and unsupported arbitrary objects (`false`, `null`). Functional tests prove the first copies unchanged and the rejected forms still transform through the general loop.
- Construction and transformation targets are exact; nested recipe compilation does not build child metadata recursively.
- `directConstructorInstantiation` remains independently true/false for the existing proven shapes.

### Construction equivalence

For each supported shape, compare default lean creation with a factory forced through the general path by an identity `beforeCreation` hook:

- exact scalars and scalar coercion, including string booleans;
- explicit null, missing nullable, missing default, Optional, missing required, and supplied computed/virtual values;
- mapped path, raw fallback, and `withoutPropertyNameMapping()`;
- already-correct enum/date/Data instances and raw enum/date/nested values;
- nested and deep mixed lean/general graphs;
- inherited, readonly, non-promoted, uninitialized, and property-hook declarations;
- public, protected/private, variadic, and incomplete constructors;
- exception class and message equality for enum/date/constructor failures.

Pin the two exceptional property states independently: a supplied computed/virtual value throws directly during recipe traversal without allocating general state, while a missing required value completes fixed conversions and delegates to ordinary `instantiate()` without calling `instantiateDirect()` or restarting through general Fill.

Also cover:

- distinct fresh default factories sharing one immutable context per class, distinct classes, every fluent mutator invalidating the cached context and applying its change, repeated customized-factory reuse, and no customized state leaking into later factories;
- direct raw and one-segment mapped reads preserving explicit `null`, multi-segment mapping, mapped-first precedence, and raw-name fallback;
- a Data class overriding `factory()` still controls `from()`;
- named factory called once when it returns an object, eligible array, or non-array general source;
- Request and Always validation, validation/rules modes, multiple/zero payloads, custom normalizer/cast, and all creation hooks using the general path;
- validation-only hooks not blocking an otherwise unvalidated array operation;
- nested AutoLazy/general fallback receiving the nested source and resolving later, with exact and coercing child values constructed once;
- AutoLazy collectables preserving keyed paginator metadata while plain children construct once and named factories run once per item;
- mapped and unmapped AutoLazy nested values, recipe-eligible `AutoWhenLoadedLazy` relations, and non-replay AutoLazy values retaining their closure-resolved input;
- one operation memo shared when a lean parent reaches multiple general children;
- eager/lazy collections retaining keys, source shapes, validation batching, and one root operation.

### Transformation equivalence

Compare recipe output with a structurally equivalent forced-general context that carries an irrelevant runtime transformer mapping, disabling the recipe without changing any tested value, for:

- plain flat and wide objects, including inherited ordering and runtime-key filtering;
- mapped/unmapped names, hidden and computed values, backed/virtual get hooks read once;
- enum, date/timezone, nested and deep Data, null, and mixed lean/general child graphs;
- wrapping and additional data finalization;
- runtime transformer overrides, custom transformers, typed iterables, Data collections, paginators, and arbitrary Arrayable values falling back;
- temporary/permanent include, exclude, only, and except selections;
- bulk-copy classes using the bulk method under `all()` while retaining output and key order; the test uses a bound test-local transformer subclass because output equality alone cannot distinguish the general path;
- the existing mapped date, enum, and nested Data `all()` assertion keeping fixed recipes disabled when values are not transformed;
- resource responses, constructable persistence, and both Eloquent cast paths retaining their current semantics;
- maximum depth checked at every nested node and nested instance partials merged before transformation.

### PHPDoc and boot

- Scalar-only constructor/property/class comments never initialize the parser.
- `Lazy|string` and `Optional|int` declarations do not initialize the parser, while either package type unioned with an array/iterable-capable arm still parses that arm.
- Array, iterable, collection, paginator, Data-collectable, untyped, mixed, object, union, intersection, and custom possible types remain parsed.
- Constructor beats property, property beats nearest class, and child class beats parent exactly as today.
- One reader instance initializes one parser pair at most once.
- Unit-test application boot resolves stable `DataConfig` without resolving `DataCreator` or `DataTransformer`; isolated non-testing application boot resolves both services from the application `booted` callback and subsequent resolutions return the retained instances.

### Verification

Run targeted files throughout, then the package-focused suite. At the final checkpoint run `composer fix` once. After review corrections, rerun targeted checks and repeat the full command only when a correction can affect the wider repository.

## Performance Acceptance

The committed harness must report p50, p95, operations per second, peak/retained memory, PHP/OS/extensions, OPcache/JIT state, commit, operation counts, and checksums. Run alternating before/after measurements on an idle machine. Acceptance comparisons use at least three alternating fresh processes, enough samples to measure the tail, and the actual 95th-percentile sample at index `ceil(0.95 * count) - 1`; the slowest sample is not labeled p95. Attribute dispatch changes through an in-tree A/B that varies only the gate; use clean `0.4` versus the branch to accept the complete result, not to assign every small difference to one line.

Required comparisons:

- current branch before/after for every changed hot path;
- native constructor and explicit manual mapper floors;
- historical `DataObject` for the applicable Albert matrix only;
- exact recipe, coerced recipe, nested/deep mixed graph, named factory, Request validation, eager/lazy collection, plain/mapped/nested transformation, resource, and persistence cases;
- first package use, next class after warm services, repository hit, parser initialization, and provider boot;
- isolated unit-test/non-testing application boot and the Testbench suite wall time;
- retained metadata for 1, 100, and 500 representative classes plus retained flat/wide instance memory.

Acceptance rules:

- Flat and wide plain transformation, including `all()` on bulk-copy shapes, may not regress beyond measurement noise.
- General validation, collection, resource, persistence, and customized factory paths may not regress materially.
- Default-context caching and each construction/output recipe must show a repeatable material p50 and p95 win in the cases it claims to optimize.
- First request after server worker start must not resolve the fixed creator/transformer service graph.
- Unit-test application boot and the full Testbench suite must remain within measurement noise of the unwarmed baseline; record suite wall time before and after.
- Metadata growth must remain proportional to used classes/properties, with no payload-derived key space or retained runtime objects. An intentionally large 500-class graph should add no more than a few megabytes for recipes and default contexts.
- Old `DataObject` parity is a useful target, not permission to weaken Data semantics or add duplicate machinery.
- Apply the material-win stopping rule to the direct array read. The underlying `Type::acceptsValue()` call removes a redundant wrapper without adding machinery and remains regardless of benchmark noise.
- After the direct read is measured, treat the remaining flat-creation cost from container resolution, late-static factory dispatch, variadic forwarding, recipe gating, and instantiation as the price of the supported contract rather than grounds for another execution engine.

## Rejected Designs

- Restoring `Hypervel\Support\DataObject`: duplicates concepts and casting semantics, forces users to choose up front, and creates two engines to maintain.
- Public `fast()`/attribute/config modes: expose an implementation decision and allow callers to select an unsafe path.
- Bypassing `static::factory()` inside `BaseData::from()`: saves one call by breaking the existing factory extension path. Cache the default implementation's immutable state instead.
- Generated metadata or warm-class lists: require discovery, filesystem I/O, invalidation, and deployment ceremony for work already cached per worker.
- Recursive recipe compilation: risks cycles and eagerly builds unused class graphs.
- Stored closures or Cast/Transformer instances in metadata: retain mutable extension state across requests.
- A second built-in acceptance table on `ValueCaster`: duplicates `NamedType` semantics without improving on calling the compiled type directly.
- A class-target `instanceof` flag on `DataProperty`: saves at most about 2% in the measured worst case while adding metadata and a union-sensitive hot-path branch.
- Mirroring the general path's first declared date or enum target in recipe metadata: duplicates a subtle declaration-order tie-break for rare ambiguous unions and cannot optimize the important ambiguous-Data or `Castable` cases. Compile `Copy` and delegate conversion to the authoritative general path instead.
- Cached `CreationContextFactory` prototypes: cloning saved under 2% of the whole creation path while adding retained mutable factory state and an invisible clone-safety invariant. Keep immutable default contexts instead.
- Caching `DataCreator` statically in `BaseData`: would retain a creator across container resets and swaps. Resolve it through the current container to preserve the extension boundary.
- Extending non-transforming recipe execution to Copy-only mapped or hidden shapes: requires more metadata for behavior already handled correctly by the general loop and has no measured hot caller.
- Collection-wide specialization: duplicates the established one-root validation and source-shape machinery without evidence.
- Caching transformed instance arrays: becomes stale after ordinary public property mutation.
- Lazy container resolution of Validation to improve first use: moves fixed service work between requests and complicates the creator; post-provider application boot before the server forks is the correct production boundary.

## Completion Checklist

- [ ] Reproducible baseline and historical fixture exist only under benchmarks.
- [ ] One public Data family remains; no fast-mode surface exists.
- [ ] Recipes are immutable, bounded, non-recursive, and contain no runtime state.
- [ ] Named factories, validation, operation memos, and constructors execute once at their owning boundary.
- [ ] Lean/general equivalence and fallback tests cover every supported operation and failure.
- [ ] First-request service initialization is moved to post-provider application boot without slowing unit-test apps.
- [ ] Benchmark wins and memory bounds are recorded; unearned machinery is removed.
- [ ] Targeted tests, `composer fix`, self-review, and peer code review are green.
