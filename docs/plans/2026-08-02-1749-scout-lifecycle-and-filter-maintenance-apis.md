# Scout Lifecycle and Filter Maintenance APIs

## Status and scope

**Status:** Complete; implementation, validation, self-review, and independent code review are signed off.

Add the narrow Scout lifecycle boundaries and engine capabilities required by framework integrations without wrapping engines or duplicating driver filter syntax. Preserve every current Laravel Scout call shape. The only intentional additive signature difference is `Searchable::removeAllFromSearch(bool $force = false)`, whose default preserves existing behavior.

This work owns:

- Builder, document, index-settings, and model-flush lifecycle callbacks;
- external-engine composition of raw application filters with Builder filters;
- completion-aware filter deletion on engines that support it;
- explicit local Meilisearch tenant-token signing;
- Foundation's Algolia cleanup completion check;
- unit, feature, real-engine integration coverage, and public Scout documentation.

Typesense custom-`within()` recovery is already correct and needs no change.

## Design rules

- Keep Laravel public APIs, names, named arguments, and extension points unless the approved additive force argument applies.
- Store each lifecycle callback in one nullable static `Closure` slot on `Scout`; registration is boot-only and replacement-based, never cumulative.
- Reset every slot through `Scout::flushState()`, which the test-state subscriber already owns.
- Keep filter compilers and SDK/task semantics inside their engines.
- Add no callback registry, policy object, engine wrapper, shared filter compiler, retry, lock, cache, or per-tenant engine state.
- Direct Engine/SDK access and low-level search callbacks remain raw boundaries, except for the explicit `DeletesByFilter` capability, which is a prepared Builder terminal.

## Lifecycle callbacks

`Scout` exposes four boot-only registration methods and public lifecycle invokers:

```php
Scout::prepareBuilderUsing(callable $callback): void;
Scout::prepareBuilder(Builder $builder, Engine $engine): void;

Scout::prepareSearchableDocumentUsing(callable $callback): void;
Scout::prepareSearchableDocument(array $document, Model $model, Engine $engine): array;

Scout::prepareIndexSettingsUsing(callable $callback): void;
Scout::prepareIndexSettings(array $settings, ?Model $model, Engine $engine, string $index): array;

Scout::guardModelFlushUsing(callable $callback): void;
Scout::guardModelFlush(Model $model, Engine $engine, bool $force): void;
```

The callback contracts are:

- `(Builder, Engine): void`;
- `(array, Model, Engine): array`;
- `(array, ?Model, Engine, string): array`;
- `(Model, Engine, bool): void`, where throwing vetoes the flush.

Registration methods warn that captured state persists for the worker lifetime. Registering again replaces the prior callback. Null slots preserve stock behavior. `Scout`'s class documentation distinguishes stable job-class state from boot-configured callback objects, and its repeated job defaults use protected constants shared by property initialization and `flushState()`.

### Builder boundary

Protected `Builder::preparedEngine()` resolves the selected engine, invokes `Scout::prepareBuilder()` once, and returns the engine. It is the first engine-resolution statement in every outer terminal:

- `raw()`;
- `keys()`;
- `get()` and therefore `first()`;
- `cursor()`;
- `simplePaginate()`;
- `paginate()`;
- `paginateRaw()`;
- `simplePaginateRaw()`.

Pagination preparation precedes all engine-capability branches. `getTotalCount()` keeps pure `engine()` resolution so pagination does not prepare twice; its cloned Builder inherits the already-prepared constraints. Constructing or configuring a Builder performs no preparation.

`deleteByFilter()` is also a prepared Builder terminal. Each implementation invokes `Scout::prepareBuilder()` before target resolution, compilation, validation, or I/O. The capability contract requires custom implementations to do the same.

### Document boundary

Algolia, Meilisearch, and Typesense pass each non-empty final external document through `Scout::prepareSearchableDocument()` after application data, Scout soft-delete metadata, and engine key metadata are assembled. Custom engines may invoke the same public boundary. Database, Collection, and Null engines remain unchanged.

### Settings boundary

`scout:index` (`IndexCommand`) invokes `Scout::prepareIndexSettings()` after application and soft-delete contributions and before its empty-settings decision. `scout:sync-index-settings` (`SyncIndexSettingsCommand`) invokes it after soft-delete contribution and physical index-name resolution, before the unconditional settings update. A raw named settings entry supplies `null` for the model. A callback may prepare an enumerated empty settings entry but does not invent indexes absent from the settings command's map.

Typesense invokes the same boundary after the model schema is assembled during lazy collection creation. The callback receives settings without target identity plus the resolved index name separately; Scout writes the authoritative schema `name` afterward.

### Flush boundary

`Searchable::removeAllFromSearch(bool $force = false)` invokes the model-flush guard before `Engine::flush()`. Existing callers remain unforced. Only `scout:flush` passes `force: true`; `scout:import --fresh` remains unforced. Direct `Engine::flush()` and whole-index commands remain explicitly global raw operations.

The Scout README records this actionable Laravel difference; Boost documentation explains the public behavior.

## External filter composition

External engines preserve both application/model raw filters and Builder constraints:

- Algolia's search and pagination paths no longer add an engine-derived `filters` value to their merge input. `performSearch()` merges the remaining options, reads the surviving application `filters`, composes it once with the Builder expression as `(application) AND (builder)`, and writes the key only when the result is non-empty.
- Meilisearch follows the same single-owner shape for `filter`. String sides use `(application) AND (builder)`. A documented array application filter is top-level-merged with the Builder string so outer AND and nested OR semantics remain intact.
- Typesense initializes `filter_by` empty, applies model and Builder option merges, then composes the winning raw value with Builder constraints as `(application) && (builder)`.

An empty side returns the other side. Exact-output tests prove every empty/non-empty combination is well formed, with no dangling or doubled conjunction. Caller-authored duplicate predicates remain legal; Scout adds no equality-deduplication branch. Search callbacks receive the final composed options. Existing engine boundaries remain responsible for invalid raw option types.

## Filter deletion

Add optional `Contracts\DeletesByFilter`:

```php
public function deleteByFilter(Builder $builder): void;
```

Algolia, Meilisearch, and Typesense implement it. Database, Collection, Null, and the base `Engine` do not pretend to support it.

Each implementation:

1. prepares the Builder;
2. targets explicit `within()` when present, otherwise the writable `indexableAs()` target;
3. compiles through the engine's existing Builder filter path and composition rules;
4. rejects an empty effective filter before SDK I/O; and
5. returns only after deletion succeeds or throws.

An absent target index is a successful no-op. Algolia and Typesense recognize their driver's exact missing-index exception around the delete request. Meilisearch recognizes `index_not_found` only from the terminal task returned by its asynchronous delete; every other failed task retains the ordinary failure path. Whole-model `flush()` keeps each engine's existing semantics, including Meilisearch's asynchronous enqueue behavior.

Completion semantics remain driver-owned:

- Algolia calls `deleteBy()` and waits for the returned task. Its SDK can swallow a polling exception and return `null`, so Scout locally corrects the inaccurate vendor return contract and requires a non-null `published` task; an ordinary null/other-status return becomes `ScoutException`, while SDK-thrown failures retain their type;
- Meilisearch calls filtered document deletion and waits up to 500 seconds through protected engine timeout and interval constants with a short bulk-operation WHY comment. A five-second interval matches Algolia's steady-state cadence, polls immediately once, and caps a full wait at 100 service requests instead of inheriting the SDK's interactive 50 ms cadence and issuing up to 10,000. A returned terminal task whose status is not `succeeded` becomes `ScoutException`; SDK timeout/transport exceptions propagate with their original type and cause;
- Typesense performs synchronous filtered deletion.

The contract returns `void`; the three services expose no truthful common deleted-count result. `Builder::within()` documentation describes its exact explicit target for searches and filter deletions, while the capability documents the default write target.

## Meilisearch tenant tokens

Correct the Hypervel-owned helper to sign locally with one explicitly matching parent-key identity:

```php
public function generateTenantToken(
    array $searchRules,
    string $apiKeyUid,
    string $apiKey,
    ?DateTimeInterface $expiresAt = null,
): string;
```

Remove network key discovery. Reject empty UID and key before calling the SDK; the key guard is load-bearing because the SDK replaces an empty option with the client key. Delegate rule and past-expiry validation to the SDK. Pass both UID and key explicitly so the token cannot name one key while being signed by another.

This method is not a Laravel API, had no callers or documentation, and its former optional discovery path could create invalid credentials. No compatibility shim remains.

## Verification

Focused tests cover:

- every callback boundary, stock-null behavior, replacement, reset, and no invocation at Builder construction;
- one Builder preparation per terminal, including all pagination capability branches and filter deletion;
- document preparation after reserved metadata for all external engines;
- settings preparation in both commands and Typesense lazy creation, including nullable model entries and authoritative Typesense names;
- default/unforced model flush, forced command flush, and unforced import-fresh behavior;
- string and Meilisearch-array filter composition across search, pagination, callbacks, escaping, option precedence, and every empty/non-empty side;
- filter-delete target selection, empty refusal, missing-target no-op, SDK request shape, Algolia null/failed completion, Meilisearch failed/timeout completion, bounded polling, and Typesense synchronous completion;
- explicit token inputs, no keys API call, empty guards, expiry delegation, and local signature construction.

Existing external-service suites under `tests/Integration/Scout/{Algolia,Meilisearch,Typesense}` prove real composed searches and filter deletion. Meilisearch integration additionally creates a matching child key/token, proves matching credentials work, and proves a mismatched signer is rejected. Tests use the existing service traits and skip only when the service is unconfigured; a configured broken service fails.

Meilisearch's shared integration-test task waiter considers only pending tasks whose index UID starts with the current worker's test prefix. This preserves the existing exact-task completion checks without allowing one ParaTest worker to adopt another worker's deliberately failed missing-index deletion.

Foundation's shared `InteractsWithAlgolia` cleanup applies the same truthful completion check to every exact index-deletion task it already owns. A nullable/non-`published` normal return throws `RuntimeException` during setup instead of allowing the next test to race a pending deletion; teardown retains its existing exception-safe cleanup policy. Deterministic harness coverage pins the swallowed-null path. This completes the existing `foundation-18` contract without adding a Scout-local waiter.

Update `src/boost/docs/scout.md` for the callbacks, filter deletion, explicit force, composed options, and tenant-token helper. Keep the package README concise and limited to actionable differences from Laravel, including the additive lifecycle/filter-deletion surface and explicit-force flush behavior. Removing tenant-token key discovery also removes its now-unused `RuntimeException` import.

Run changed tests immediately, then Scout unit/feature and configured real-engine suites, followed by the authoritative `composer fix`, `git diff --check`, fresh caller/callee self-review, and independent code review through signoff.

## Expected result

Normal external searches add constant local filter composition and one null-checked lifecycle call before each terminal. Indexing/settings add one null-checked callback at their existing assembly boundary. Filter deletion adds only its requested service operation and required task wait. No per-document query, extra network lookup, per-tenant client, retained map, or request-time registration is introduced.

Laravel search APIs continue to work unchanged. Framework integrations can enforce ambient search isolation, reserved document/settings metadata, global-flush policy, and tenant-selective purge through first-class Scout boundaries instead of engine wrappers or duplicated SDK logic.
