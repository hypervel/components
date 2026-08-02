# Scout Current Parity, Queue, and Search Lifecycles

## Status and scope

**Status:** Complete; implementation, validation, self-review, and independent code review are signed off.

Complete the Scout audit against current local sources:

- Hypervel `0.4` at this branch's base;
- Laravel Scout `11.x` at `b197b78`;
- Laravel framework and documentation under `examples/laravel/framework` and `examples/laravel/docs`;
- installed Algolia, Meilisearch, Typesense, Guzzle, and PSR HTTP packages under `vendor/`;
- completed `support-02`, `database-10`, `database-15`–`20`, `queue-41`, `scout-01`, and `scout-02` decisions.

The work covers Scout source, configuration, split metadata, public Boost documentation, unit and external-service integration tests, plus three defects owned by Database. It must preserve Hypervel's coroutine-aware and higher-scale additions:

- `collection` as the default engine and `app_id().'_'` as the default prefix;
- structured queue configuration for `enabled`, `connection`, and `queue`;
- coroutine-deferred nonqueued indexing and bounded concurrent command imports;
- worker-shared engines, SDK clients, HTTP transports, and connection reuse;
- explicit Swoole-safe Guzzle clients, bounded Meilisearch retries, and request-context Algolia identify headers;
- Meilisearch tenant-token helpers, transformed collections, `int|string` range keys, rich Typesense configuration, distinct read/write index names, prefix-aware delete-all safety, and configurable job classes.

Do not port Algolia 3 or replace these adaptations with Laravel's request-per-process internals.

## Post-compaction and design rules

After compaction, re-read `AGENTS.md` and this plan in full before editing. Re-open the active source and tests; summaries are navigation only.

- Require a supported path and meaningful harm before adding machinery. Complexity must pay for itself through a demonstrated failure, a complete trace of a realistic schedule, an approved capability with real consumers, or removal of greater complexity; a merely conceivable state is not enough.
- Fix the lowest owner completely and update every affected caller, contract, test, comment, config key, and document in the same coherent change. This applies to any genuine issue discovered during the work, including another package, once it meets the evidence threshold; do not add consumer workarounds or defer it to preserve package order.
- Hypervel 0.4 has no prior-version compatibility burden, but current Laravel public APIs, configuration, named arguments, and conventional extension points remain the default contract. Do not retain an inferior implementation solely for compatibility: a necessary divergence must have a concrete benefit, owner approval, and a cleaner result than a shim or workaround.
- Do not infer correctness from difference or parity. Trace both implementations and fix a verified defect even when Laravel, an SDK, or another upstream shares it.
- Preserve coroutine isolation and worker-lifetime client reuse. Per-operation mutable state belongs in arguments, fresh objects, or `CoroutineContext`; stable clients and metadata may remain worker-shared.
- Make ownership and failure handoff explicit. Creation is transactional, cleanup is exhaustive, the earliest failure stays primary, and waits are bounded only where progress depends on a peer that can disappear.
- Audit every added allocation, container resolution, lock, hash, serialization, yield, retry, log, network call, and retained worker object. A source-proven or measured hot-path regression requires owner approval. A proposed optimization must have a meaningful practical benefit after complexity and upstream divergence; style churn and benchmark noise are not findings.
- Prefer existing PHP, Laravel, and Hypervel primitives. Treat established remediation patterns as candidates, not automatic answers. Do not add speculative abstractions, registries, locks, caches, retries, timeouts, state machines, factories, context slots, extension points, parsers, compatibility APIs, or configuration.
- Do not add machinery for deliberate framework escape hatches such as raw queries, disabled events, or direct SDK access unless the public contract promises coverage.
- Do not make source code worse for PHPStan. Fix real types first, then use a truthful local `@var`, then a narrow identifier-scoped ignore for magic that analysis cannot model. Never add a global ignore for this work.
- Delete superseded helpers, branches, config keys, writes, imports, comments, tests, and documentation. Do not leave compatibility aliases, temporary wording, deferred TODOs, or rejected designs.
- Tests reproduce externally meaningful old failures, use deterministic scheduling where needed, preserve external-service isolation, and release every resource they own.
- Record low-confidence concerns as rejected analysis rather than implementing them. Surface a meaningful non-defect improvement with its benefit, cost, alternatives, and parity impact, then obtain owner approval before changing code.
- Avoiding overengineering never justifies an incomplete correctness, security, isolation, cleanup, or public-contract fix.

## Research and fixed decisions

### Current upstream surface

Port the final current Scout source and tests, using these originating changes only as history and completeness checks:

| Upstream change | Final surface |
|---|---|
| #416 and #505 | `HasManyThrough` searchable and unsearchable relation macros |
| #728 | database-capability raw pagination branches |
| #759 | backed-enum filter support, adapted so Hypervel escaping is never bypassed |
| #839 | Algolia `numericFilters` array to `filters` expression |
| #852 | model-selected, container-resolved Scout Builder |
| #969 | two/three-argument comparisons and structured where entries |
| #984 | one force-delete removal |
| #1003 | queue-import ordering |
| #1005 | Algolia escaping, boolean/inequality rendering, and filter grouping |

Later current source remains authoritative. Do not port empty constructors, `Scout::VERSION`, Algolia 3, or the unused `DatabaseEngine::simplePaginate()` convenience method.

### Worker and operation ownership

- `EngineManager`, engines, SDK clients, and reusable HTTP transports remain worker-lifetime services.
- Each search creates a fresh mutable `Builder` through parameterized container resolution; it is never cached.
- Import progress and the concurrent import runner remain coroutine-operation state and are removed in `finally`.
- Observer suppression remains coroutine-local and nesting restores the prior enabled/disabled behavior.
- Typesense collection wrappers are operation-local handles detached from the SDK's unbounded name registry; the client and transport stay shared.
- Existing `AfterEachTestSubscriber` owners remain sufficient. Add no Scout static reset or second cleanup registry.

### Implementation gates

The implementation decisions are owner-approved:

| Gate | Status, decision, and cost |
|---|---|
| `scout-08`, `scout-09` | Use `Container::makeWith()` for the model-selected Builder and four generic paginator paths. It honors Laravel substitutions and stays fresh through Hypervel's parameterized-resolution path. Cost is one cached build-recipe lookup and override push/pop per search or generic pagination, beside required DB/network work. |
| `scout-18` | Consolidate on top-level `scout.after_commit` / `SCOUT_AFTER_COMMIT`; remove nested `queue.after_commit` / `SCOUT_QUEUE_AFTER_COMMIT`. Observer and manual queued dispatch use the same concept. |
| `scout-31` | Add the three methods directly required by `ModelObserver` to `SearchableInterface`. Every conforming implementation must provide them. |
| `scout-32` | Database raw pagination honors the declared model-pagination capabilities. It returns model paginators and therefore has no engine-raw result for `withRawResults()` to transform, matching current Scout. |
| `scout-34` | Typesense rejects unknown comparison operators instead of silently rewriting them to equality. Validation remains engine-local so custom engines may support other operators. |
| `scout-37` | Detach Typesense wrappers through public ArrayAccess. This trades four short-lived wrapper objects and one local `unset()` per remote operation for bounded worker memory; it adds no network call and retains client/transport reuse. |
| `database-21` | Reject nonpositive `chunk()` / `orderedChunkById()` counts with the same `InvalidArgumentException` already used by Laravel's lazy siblings. Laravel currently returns true for zero and can issue an unbounded fetch for a negative count; names and signatures remain unchanged. |

Apart from the approved `database-21` behavior correction, no accepted item adds a source-proven hot-path regression or changes a Laravel-facing contract.

## Findings

| ID | Category | Severity | Owning boundary and final result |
|---|---|---:|---|
| `scout-03` | Defect and parity | Major | `Builder` stores ordered `{field, operator, value}` constraints; Collection, Database, Meili, Typesense, and every soft-delete consumer use that shape. |
| `scout-04` | Defect and parity | Major | Algolia uses escaped `filters`; Meili escapes IN values; Algolia, Meili, and Typesense preserve native backed-enum scalar types at their formatter boundaries. |
| `scout-05` | Defect | Major | A force-deleted soft-delete model issues one remote removal. |
| `scout-06` | Coroutine lifecycle defect | Major | Nested `withoutSyncingToSearch()` restores the prior enabled/disabled behavior. |
| `scout-07` | Relation correctness defect | Major | `HasManyThrough` macros use the relation's through-key-aware chunk query. |
| `scout-08` | Current API parity | Major | Models may select a custom Scout Builder and container substitutions are honored without caching mutable builders. |
| `scout-09` | Container integration | Minor | Four generic pagination paths construct fresh substitutable paginators and expose truthful paginator contracts. |
| `scout-10` | Repository-convention and performance defect | Minor | Optional bulk events are constructed and dispatched only when listeners or fakes require them. |
| `scout-11` | Current queue API parity | Major | Search/removal jobs read configured tries, backoff, and max exceptions; all three jobs fail on timeout. |
| `scout-12` | Current queue API parity | Major | Opt-in unique jobs use sorted Scout keys, model class, one-hour uniqueness, and a SHA-256 JSON identity. |
| `scout-13` | Current command API and correctness | Major | Queue imports support validated ascending/descending ordering for integer and string keys and both `int`/`integer` key-type spellings. |
| `scout-14` | Error-classification defect | Major | Typesense creates only after `ObjectNotFound` and accepts only `ObjectAlreadyExists` create races. |
| `scout-15` | Error-classification defect | Major | Meilisearch creates only after 404 and accepts only `index_already_exists` races. |
| `scout-16` | Queue restoration defect | Major | Every queued removal restores a `RemoveableScoutCollection` of exact synthetic Scout identities without a DB query; all remote engines delete the serialized key. |
| `scout-17` | Package metadata defect | Major | The split manifest declares Guzzle and PSR HTTP Message directly. |
| `scout-18` | Transaction/config defect | Major | One top-level after-commit setting governs observer and queued manual dispatch paths. |
| `scout-19` | Provenance/documentation defect | Minor | README gains upstream provenance and concise actionable differences, retaining its setext heading. |
| `scout-20` | Public docs/CLI defect | Minor | Boost docs cover accepted APIs in existing Laravel-style sections; spelling and CLI language are corrected. |
| `scout-21` | Typesense target defect | Major | Search uses explicit `within()` or `searchableAs()`; update/delete/flush use `indexableAs()`; creation uses the already-resolved target. |
| `scout-22` | Typesense remote-I/O defect | Minor | Document deletion is one DELETE and treats only `ObjectNotFound` as idempotent success. |
| `scout-23` | Typesense flush defect | Minor | Flush deletes directly, treats only `ObjectNotFound` as already flushed, and never creates first. |
| `scout-24` | Queue range defect | Major | String bounds are ordered by the database query, never by PHP comparison. |
| `scout-25` | Queue validation defect | Major | Integer bounds use `FILTER_VALIDATE_INT`; malformed, leading-zero, fractional, exponent, overflow, or reversed input fails the command. |
| `scout-26` | Command capability defect | Major | `scout:index` ignores only `NotSupportedException` from create and still applies settings. |
| `scout-27` | Settings resolution defect | Major | `scout:index` checks logical/model settings before physical prefixed-name fallback. |
| `scout-28` | String-zero defect | Minor | `scout:index --key=0` reaches the engine; only null/empty means absent. |
| `scout-29` | String-zero defect | Major | Algolia preserves `within('0')` through null-aware index selection. |
| `database-21` | Shared Database defect | Major | `chunk()` and `orderedChunkById()` reject counts below one at their common public owners. |
| `scout-30` | Typesense performance/correctness defect | Major | Update transforms first, imports optimistically, creates only after missing, and retries that import once. |
| `scout-31` | Contract defect | Major | `SearchableInterface` declares the observer-required update/delete state methods. |
| `scout-32` | Pagination defect | Major | Raw database pagination uses the engine's declared model-pagination capabilities rather than paginating its transport envelope. |
| `scout-33` | String-zero defect | Major | Collection search treats only `''` as an empty query. |
| `scout-34` | Typesense correctness divergence | Minor | Unsupported operators throw `InvalidArgumentException` at the Typesense boundary. |
| `scout-35` | Import lifecycle defect | Major | A child import failure aborts scanning, drains active children, reaches the command, and cannot contaminate later imports. |
| `scout-36` | Test typing | Minor | Scout test methods and the identified fixture parameters/return are fully typed. |
| `scout-37` | Worker-memory defect | Major | Engine-owned Typesense handles no longer accumulate per collection name or collide with SDK magic-property names. |
| `scout-38` | Attribute defect and upstream defect | Major | DatabaseEngine instantiates search attributes so named and positional columns/options behave identically. |
| `scout-39` | Pagination defect and parity | Major | All four pagination paths treat zero per-page input as the model default; length-aware pagination no longer divides by zero. |
| `database-22` | Attribute defect and upstream defect | Major | `CollectedBy` is instantiated so its named `collectionClass` is honored before worker caching. |
| `database-23` | Descending cursor type defect | Major | `forPageBeforeId()` accepts string keys so descending chunk and lazy traversal work beyond the first page. |
| `scout-40` | Integer range defect and upstream defect | Major | Descending queue-import ranges use overflow-safe remaining-distance arithmetic; the ascending branch uses the same exact formulation instead of relying on mixed numeric tie-breaking. |

## Implementation design

### 1. Comparison and filter contracts (`scout-03`, `04`, `29`, `33`, `34`)

Change `Builder::$wheres` to `array<int, array{field: string, operator: string, value: mixed}>` and preserve Laravel's two-argument equality form:

```php
$this->wheres[] = [
    'field' => $field,
    'operator' => func_num_args() === 2 ? '=' : $operator,
    'value' => func_num_args() === 2 ? $operator : $value,
];
```

The public overload keeps `mixed $operator` because its two-argument form stores that parameter as the value. The selected operator is the literal `=` or the supported three-argument string operator; narrow it locally for analysis rather than casting invalid input or adding runtime machinery.

Move the constructor soft-delete constraint and `onlyTrashed()` to this form; `withTrashed()` removes entries by literal `field`. DatabaseEngine and CollectionEngine iterate entries and use `firstWhere('field', '__soft_deleted')`; Typesense forwards the entry operator. Revalidate normal, `withTrashed()`, and `onlyTrashed()` behavior so the representation change cannot silently drop the constraint.

Port Algolia's complete current filter path, not only operators:

- replace `numericFilters` with the single `filters` expression;
- escape backslashes and quotes, render booleans correctly, use `NOT` for `!=`, group OR terms, join NOT-IN terms with AND, and retain the `0:1` empty-set sentinel;
- unwrap a scalar `BackedEnum` to its native backing value before branch dispatch, and unwrap IN/NOT-IN values inside `formatFilterValue()`;
- use null-aware index selection so literal index `"0"` remains valid.

Meilisearch unwraps a `BackedEnum` before the existing bool/null/numeric/escaped-string path rather than copying upstream's escaping-bypassing early return. Its IN/NOT-IN mapper applies the same unwrapping and `addcslashes()` escaping. Typesense accepts `BackedEnum` at `parseFilterValue()`, unwraps before recursion/boolean handling, and throws for any operator outside `=`, `!=`, `<`, `>`, `<=`, `>=`. Database/Collection leave raw enum values to Database's `castBinding()`.

CollectionEngine checks `$builder->query === ''`; it must not treat `"0"` as empty.

### 2. Model lifecycle, relations, builders, pagination, and events (`scout-05`–`10`, `31`, `32`, `39`, `database-21`)

In `ModelObserver::deleted()`, skip the ordinary deleted path while a SoftDeletes model is force deleting so `forceDeleted()` remains the sole removal owner.

Make `Searchable::withoutSyncingToSearch()` nesting-safe without adding another API. Absence and explicit `false` are indistinguishable because `ModelObserver::syncingDisabledFor()` is the only reader and casts the context value with a false default:

```php
$wasDisabled = ! static::isSearchSyncingEnabled();

static::disableSearchSyncing();

try {
    return $callback();
} finally {
    $wasDisabled ? static::disableSearchSyncing() : static::enableSearchSyncing();
}
```

Do not expose the context key, add a counter or observer helper, or change `enableSyncingFor()` semantics. Only the observable nested state needs restoration.

Register `HasManyThrough::searchable()` and `unsearchable()` alongside the existing collection macros in `registerSearchableMacros()`. The macro calls the relation's own `chunkById()` so `prepareQueryBuilder()` selects qualified far-model columns and the through key. Read both chunk defaults with the same upstream-shaped `config()` calls used by the two Eloquent Builder macros, and delete `SearchableScope::getScoutConfig()`. Keep `??` rather than upstream's `?:`: null means unspecified, while an explicit zero is invalid programmer input that must reach Database's shared chunk guard. Cover configured defaults for both Eloquent and HasManyThrough macro pairs. Registration remains boot-time, idempotent, and self-contained; add the standard `Boot-only.` warning because the touched public registrar mutates worker-global macros.

`Searchable::search()` selects `static::$scoutBuilder ?? Builder::class` and calls `Container::makeWith()`. Keep `$scoutBuilder` undeclared: a trait property would conflict fatally with the documented model declaration. Use one narrow PHPStan ignore at the magic access and a concise docblock note.

Use `makeWith()` for generic `Paginator` and `LengthAwarePaginator` creation in `simplePaginate()`, `paginate()`, and both raw variants. The raw variants return paginator contracts and first honor `PaginatesEloquentModels` and `PaginatesEloquentModelsUsingDatabase`. Database capability returns happen before raw callbacks because the result is already a model paginator; do not invent a synthetic raw envelope.

In all four paths, resolve `$perPage` with `?:` rather than `??`, matching current Scout and Hypervel Eloquent. This makes zero per-page input use the model default and prevents the length-aware variants from dividing by zero. Page-number zero already self-corrects in the paginator and needs no extra branch.

Add exactly these observer requirements to `SearchableInterface` and remove the corresponding method-not-found suppressions:

```php
public function searchIndexShouldBeUpdated(): bool;
public function wasSearchableBeforeUpdate(): bool;
public function wasSearchableBeforeDelete(): bool;
```

Guard `ModelsImported` and `ModelsFlushed` construction/dispatch with `Dispatcher::hasListeners()`, as required for optional observational events by the repository convention. Resolve the dispatcher only at that boundary; progress reporting stays unconditional. Do not cache listener state or claim the required cached container resolution disappears.

At Database's shared owner, add the same `InvalidArgumentException('The chunk size should be at least 1')` guard to both `chunk()` and `orderedChunkById()`. Zero currently returns false success; a negative limit can fetch the whole table. Scout adds no duplicate guard.

### 3. Queue configuration, jobs, restoration, and import failure (`scout-11`–`18`, `35`)

Port `ConfiguresJobOptions` to `src/scout/src/Traits/` and use it from MakeSearchable and RemoveFromSearch, configuring only properties the selected subclass has not set. Keep its public properties untyped with exact docblocks because `Scout::makeSearchableUsing()` and `removeFromSearchUsing()` explicitly support Laravel-style subclasses that redeclare them untyped. `tries` and `maxExceptions` are `int|null`; `backoff` is `int|list<int>|null`; `failOnTimeout` is boolean. Publish nullable `jobs.tries`, `jobs.backoff`, and `jobs.max_exceptions` keys without inventing environment variables. MakeRangeSearchable receives only `failOnTimeout = true`.

Port `MakeSearchableUniquely` and `RemoveFromSearchUniquely` to `src/scout/src/Jobs/`, and `UniqueByScoutKeys` to the upstream-compatible `src/scout/src/Traits/`. Use `ShouldBeUniqueUntilProcessing`, `uniqueFor = 3600`, the model class, and sorted Scout keys. Use:

```php
return hash('sha256', json_encode($tuple, JSON_THROW_ON_ERROR));
```

SHA-256 is intentional because application/user-influenced Scout keys form a uniqueness-lock trust boundary: a forged collision suppresses an index update. The work is opt-in and uses the existing Queue unique-lock owner.

`RemoveFromSearch::restoreCollection()` consumes `ModelIdentifier::getClass()`, creates synthetic models, applies the serialized connection, sets string/int key type, and force-fills the exact stored Scout-key attribute. It performs no query or relationship restoration. Algolia, Meilisearch, and Typesense read `getScoutKeyName()` from `RemoveableScoutCollection`; ordinary collections keep `getScoutKey()`. Delete tests use `CustomScoutKeyModel` so double transformation cannot pass unnoticed.

Move after-commit outside the queue group:

```php
'queue' => [
    'enabled' => env('SCOUT_QUEUE', false),
    'connection' => env('SCOUT_QUEUE_CONNECTION'),
    'queue' => env('SCOUT_QUEUE_NAME'),
],
'after_commit' => env('SCOUT_AFTER_COMMIT', false),
```

The observer and both queued manual collection paths read `scout.after_commit`. Automatic nonqueued model events are deferred at the observer; direct manual nonqueued collection calls retain their existing coroutine-defer semantics without a new transaction layer.

Add `Hypervel\Scout\Console\ConcurrentImportRunner` at `src/scout/src/Console/ConcurrentImportRunner.php`. It composes `WaitConcurrent`, wraps children, and retains only the first Throwable in shared object state. Before accepting another child, rethrow the recorded failure so `chunkById()` aborts its scan. `wait()` always drains active children and rethrows the original failure. `waitForSearchableJobs()` forgets the runner in `finally`. Keep ImportCommand's existing `finally`; PHP exception chaining preserves a simultaneous body failure without a second quiet-wait API. Do not change generic `WaitConcurrent`, retain collections, aggregate failures, cancel children, retry, or add a registry.

### 4. Queue-import and index commands (`scout-13`, `24`–`28`, `database-23`)

Add `--order=asc` and accept only exact `asc`/`desc`. Change `QueueImportCommand::handle()` to return `int` and propagate statuses from its range dispatchers. Return `Command::FAILURE` for invalid direction or invalid/reversed integer bounds; successful/no-record paths return `Command::SUCCESS` consistently. `ImportCommand::handle()` and `FlushCommand::handle()` remain `void`: their child/engine failures propagate as exceptions and the console kernel supplies the nonzero status, while QueueImport has handled validation and no-record status paths.

- Treat `getScoutKeyType()` values `int` and `integer` as integer ranges.
- Validate supplied/resolved integer bounds with `filter_var($value, FILTER_VALIDATE_INT)` and distinguish valid zero from `false`.
- Reject leading-zero spellings, fractions, exponents, malformed values, platform overflow, and reversed integer ranges.
- Dispatch descending integer chunks high to low.
- Compute integer range ends from the remaining distance. The previous descending expression could underflow a platform-valid lower bound to float before constructing the typed job; use the same exact calculation in both directions rather than relying on `min()` to recover the ascending integer type.
- For string keys, remove the PHP `$min > $max` check and let the bounded database query define collation and emptiness. Use `chunkById()` for ascending and `chunkByIdDesc()` for descending. A descending chunk arrives high-to-low, so dispatch the job with its last key as the low bound and first key as the high bound; report the low boundary as “down to key.” This honors ordering while keeping `MakeRangeSearchable::whereBetween()` payloads valid.
- Preserve queue and connection selection and `int|string` MakeRangeSearchable payloads.

At Database's shared cursor owner, widen `Query\Builder::forPageBeforeId()`'s `$lastId` from `?int` to `string|int|null`, exactly matching `forPageAfterId()` and the actual values supplied by `chunkByIdDesc()` and `lazyByIdDesc()`. The current narrow type rejects string IDs on their second page; Scout adds no cast or local workaround.

The Database regressions must execute the real method rather than mock `forPageBeforeId()`: add string-cursor SQL/binding cases to the existing `testForPageBeforeId()` and `testForPageAfterId()` pair in `DatabaseQueryBuilderTest`, and a real SQLite multi-page `chunkByIdDesc()` UUID walk to the already typed `HasUuidsTest`. The Scout UUID command regression independently proves the complete descending import and normalized range payload.

In `IndexCommand`:

- treat only null/empty `--key` as absent, preserving `"0"`;
- catch `NotSupportedException` only around `createIndex()` and continue settings synchronization;
- check settings by the original model/logical key, then fall back to the resolved physical index name;
- preserve all operational exceptions;
- change user-facing `Synchronised` to `Synchronized`.

Do not extract the three identical `indexName()` methods: their algorithm is not changing, so a trait would solve no active duplication drift.

### 5. Typesense lifecycle and bounded memory (`scout-14`, `21`–`23`, `30`, `34`, `37`)

Replace `getOrCreateCollectionFromModel()`'s conflated target/create boolean with explicit operations:

- resolve search to `within()` or `searchableAs()`;
- resolve update, delete, and flush to `indexableAs()`;
- replace any schema `name` with the already-resolved operation target; the schema owns fields and options, while `searchableAs()`, `indexableAs()`, or `within()` owns the collection name;
- create only for update recovery and missing-search recovery;
- catch only `ObjectNotFound` on retrieve/import/delete and only `ObjectAlreadyExists` on the create race.

Update transforms and filters documents before touching the service. If none remain, return. Otherwise import through a detached handle; on `ObjectNotFound`, create the exact target schema, accept the one create race, and retry the same single-request import once. No generic retry or metadata preflight.

Delete documents directly and catch only `ObjectNotFound`. Flush deletes the target collection directly and catches only `ObjectNotFound`; it never creates. Remove dead `setExists(true)` writes.

Centralize every engine-owned collection handle through public ArrayAccess:

```php
$collections = $this->typesense->getCollections();
$collection = $collections[$name];
unset($collections[$name]);

return $collection;
```

Use the helper for search, update, delete, flush, and explicit delete-index paths. It prevents worker-lifetime growth across tenant/dynamic names and avoids `__get` property-name collisions. Direct application calls through `getTypesenseClient()` retain native SDK behavior.

### 6. Meilisearch absence classification (`scout-15`)

`getOrCreateIndex()` and create-index handling interpret only HTTP 404 as absence. Accept only the SDK error code `index_already_exists` from the create race and propagate authentication, authorization, validation, transport, server, and other conflict failures. Keep Hypervel's bounded HTTP retry policy unchanged; do not add a second retry/lock/error wrapper.

### 7. Named reflection attributes (`scout-38`, `database-22`)

Stop indexing `ReflectionAttribute::getArguments()` by position. Existing attribute constructors own normalization:

```php
$attribute = $reflectionAttribute->newInstance();
$columns = array_merge($columns, $attribute->columns);
$options = array_merge($options, $attribute->options);
```

DatabaseEngine does this for SearchUsingPrefix and SearchUsingFullText, including named columns/options, then keeps its existing per-model caches. Remove redundant `Arr::wrap()` imports/uses only if no other call remains.

`HasCollection::resolveCollectionFromAttribute()` instantiates the attribute and locally narrows its `collectionClass` to the trait's `class-string<TCollection>` contract before returning it, retaining parent traversal and the existing worker cache. Cover both positional and named attributes. A malformed no-argument attribute now raises `ArgumentCountError` rather than silently falling through and caching the default; that is the truthful failure for a required constructor parameter. `HasEvents` and `HasGlobalScopes` flatten whole argument arrays and remain unchanged; the repository positional-index sweep is otherwise clean.

### 8. Metadata, docs, and test typing (`scout-17`, `19`, `20`, `36`)

Add `guzzlehttp/guzzle` `^7.15.1` and `psr/http-message` `^2.0` to `src/scout/composer.json`; the root already declares them. Add `tests/Scout/PackageMetadataTest.php` following the existing Reverb manifest test. It checks all direct runtime dependencies and pins these two exact constraints. Algolia, Meilisearch, and Typesense remain optional suggestions; Symfony Console is already declared.

Keep the README's house-style setext heading. Add `Ported from: https://github.com/laravel/scout` and a concise `Differences From Laravel` section covering only actionable differences: Algolia 4 only, structured queue selection, coroutine-deferred nonqueue mode, command concurrency, Meilisearch retries/tenant tokens, and prefix-gated delete-all behavior.

Update `src/boost/docs/scout.md` in the existing natural sections with short Laravel-style examples for comparisons, custom Builder selection, job options/unique job selection in the existing job section, and queue-import ordering. State concisely that Scout derives a Typesense collection name from the model index and ignores a schema `name` key. Correct `comparsion`; avoid internal runner, context, SDK-wrapper, or lifecycle detail.

Add `: void` to the identified 218 public test methods in `CollectionEngineTest`, `CoroutineSafetyTest`, `SearchableModelTest`, `BuilderTest`, `EngineManagerTest`, `AlgoliaEngineTest`, `MeilisearchEngineTest`, `MeilisearchRetryMiddlewareTest`, `MeilisearchRetryPolicyTest`, `NullEngineTest`, and `ScoutServiceProviderTest`. Type the seven promoted `$engine` parameters in `RemoveFromSearchTest` and `MakeSearchableUsingTest`, plus the anonymous Typesense constructor parameter and `__get` parameter/return in `TypesenseEngineTest`. Do not change data providers or helpers whose returned values are consumed.

The two existing `DatabaseQueryBuilderTest` methods retain their current signatures; typing only those methods would leave that legacy file inconsistent. The new `HasUuidsTest` regression uses `: void`, matching its class and the rule for new tests.

## Test plan

### Focused unit and feature regressions

- Builder two/three-argument constraints, duplicate-field comparisons, exact soft-delete entry shape, and normal/with/only trashed behavior in Builder, Database, and Collection.
- Exact Algolia filter strings for quoted/backslashed values, booleans, inequality, empty IN, grouping, string/int backed enums, and `within('0')`.
- Exact Meilisearch escaping and backed enums in scalar, IN, and NOT-IN forms; Typesense enum and unsupported-operator behavior.
- One force-delete removal; nested normal/exceptional suppression and sibling-coroutine isolation.
- HasManyThrough search/removal with colliding far/through `id` or timestamp columns, custom chunks, and far-model Scout keys.
- Custom/default Builder resolution, all four paginator substitution paths, and zero per-page fallback; Database raw model pagination and raw-callback consequence.
- No-listener, real-listener, and EventFake bulk event behavior with independent progress.
- Configured/absent/subclass-overridden job options; timeout properties; unique ordering/class/key identity and JSON failure.
- RemoveFromSearch serialization round trips for persisted, deleted, custom-key, morph-alias, and nondefault-connection models; no DB query; exact Algolia/Meili/Typesense delete keys.
- Queue-import asc/desc for integer and string keys, multiple descending string chunks, normalized string job bounds, `int`/`integer`, string collation, zero/signs, malformed/leading-zero/fraction/exponent/overflow/reversed bounds, queue/connection pass-through, and exit status.
- Strict platform-extreme integer range assertions for both directions; descending reproduces the prior underflow and ascending protects the symmetric exact formulation.
- Index unsupported-create/settings continuation, logical and physical settings lookup, primary key `"0"`, and operational error propagation.
- Typesense exact targets/schemas, including stale explicit schema names and prefixed indexes, one-request delete, no-create flush, transform-before-I/O, optimistic import, one missing-create retry, create race, error propagation, detached-handle reuse, name collision, and bounded registry size.
- Meilisearch 404/create race versus every non-absence failure.
- Import child failure preserves the original Throwable, returns failure without success output, stops further scanning, drains active children, clears context, and permits a later successful import.
- Named SearchUsingPrefix/SearchUsingFullText columns/options and cache reuse; named CollectedBy inheritance/override; malformed attributes fail fast.
- Database `chunk()` and `orderedChunkById()` reject zero/negative counts; `forPageBeforeId()` accepts a string cursor; Scout macro and command consumers inherit the corrected contracts without local checks.
- Split metadata, README provenance/differences, config shape/env removal, contract completeness, command text, and test typing.

### External-service integration

Use the existing isolation traits and workflow groups:

- Algolia: comparison/string filters, escaping, backed enums, soft-delete modes, and string-zero index where safe;
- Meilisearch: comparison/enum/escaping, absence classification, custom-key removal, soft deletes, and settings;
- Typesense: target separation, comparison/enum/operator behavior, empty/update recovery, delete/flush request semantics, detached-handle usability, custom-key removal, and soft deletes.

Tests requiring unconfigured Algolia remain trait-skipped; configured but broken services must fail. Do not add a service, environment variable, or workflow unless a real regression cannot run under the existing Scout workflows.

### Validation order

1. Run each changed/new test file immediately after its source slice.
2. Run `tests/Scout` and the changed Database test files.
3. Run configured Meilisearch and Typesense integration groups; run Algolia when credentials are available.
4. Run `./vendor/bin/phpstan` and `./vendor/bin/php-cs-fixer fix` during focused work only when useful; the authoritative final gate is `composer fix`.
5. Run `composer fix` once the complete implementation is ready. It owns formatting, both PHPStan configs, the full parallel suite, and both Testbench suites.
6. Run `git diff --check`, validate the split manifest, verify zero stale config/env/helper/import references, and inspect skipped integration tests normally.

## Fresh self-review

After green gates, ignore the prior discussion and trace the full diff through:

- every Builder where consumer and soft-delete mode;
- every custom Builder/paginator/contract implementer and named-argument surface;
- observer, manual collection, queue, transaction, serialization, and import-failure paths;
- every Typesense/Meili/Algolia target, request, error class, filter value, and retained SDK object;
- every Database chunk and attribute consumer;
- API/config/docs/facade/static-analysis metadata;
- worker/coroutine lifetime, allocations, I/O counts, retained memory, and test teardown;
- stale code, comments, imports, settings, env vars, and rejected mechanisms.

If an unexpected defect or design contradiction appears, stop that edit, complete the caller/callee and same-family investigation, obtain focused second-opinion consensus, replace superseded plan wording, then implement the settled result.

## Expected result

Normal searches gain only the approved fresh container construction and small local filter-shape checks. Queued job creation gains three config reads. Typesense remote operations gain four temporary wrappers plus one array removal while eliminating unbounded per-name retention. In return, no-listener imports avoid event construction and dispatch traversal; Typesense removes per-delete, per-flush, and per-update network calls; empty transformed batches do no I/O. No lock, additional serialization layer, polling, logging, broad retry, per-request client, or worker registry is introduced.

Laravel public names and signatures are restored or preserved. Nonpositive Database chunk sizes become an owner-approved behavior correction: they throw consistently with Laravel's lazy chunk APIs instead of returning success or issuing an unbounded fetch. Other intentional differences are limited to the approved Hypervel configuration structure, coroutine/nonqueue execution, bounded command concurrency, Algolia 4-only support, stronger escaping/error truthfulness, Swoole-safe clients/retries, exact delete-all safety, and Typesense operator behavior. No compatibility shim or obscure-API workaround remains.

## Completion criteria

- all accepted Scout and Database findings are implemented at their lowest owners;
- current upstream source, tests, metadata, and documentation surfaces in scope are accounted for;
- Hypervel-specific queue, coroutine, client reuse, retries, tenant-token, range-key, and index-target enhancements remain intact;
- no accepted defect, stale config/env key, dead write/helper, TODO, workaround, or superseded documentation remains;
- focused, integration, static-analysis, formatting, full-suite, and Testbench gates are green;
- fresh self-review and independent code review are signed off;
- the companion ledger, routing index, dependency index, and completed Database entry record the final implementation and revalidation.

The final bookkeeping pass changes all three Database dependency rows from pending to complete: `database-21` and `database-23` revalidate Database and Scout, while `database-22` revalidates Database alone.
