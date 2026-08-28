# Search and Request Pipeline Remediation Plan

## Status

Implemented and verified.

## Scope

This plan addresses the remaining search and request-pipeline findings from the components 0.4 audit:

- Scout: findings 36–42
- URI helper: finding 56
- Inertia: findings 71–74
- Saloon: findings 75–77
- API client: findings 99–102
- HTTP client: findings 156–158
- The HTTP test return-type item in `docs/todo.md`

These are framework bug fixes and narrowly scoped enhancements. They do not redesign the packages, add compatibility layers, or change unrelated Laravel APIs.

## Goals

1. Produce correct search expressions, pagination metadata, and database-engine queries.
2. Preserve operation ordering and bound retained work in long-lived workers.
3. Make Inertia's callable and partial-reload contracts consistent without interpreting callable-looking data as executable code.
4. Make Saloon authentication replacement and pagination limits deterministic.
5. Run API middleware before short-circuiting Guzzle middleware while preserving HTTP request data and attributes.
6. Ensure mutable request builders are fresh on unbound container resolution.
7. Correct incomplete native types and the remaining test-method return types.

## Non-goals

- Do not coalesce Scout model operations; save/delete order is observable.
- Do not add a general background queue, queue threshold, retry layer, or worker-global buffer.
- Do not inspect database schemas on each Scout search.
- Do not change custom Scout keys for external search engines.
- Do not add a second Inertia callable-resolution system outside the existing concern.
- Do not add another Saloon pagination counter.
- Do not enable asynchronous API-client requests or custom Guzzle clients.
- Do not rewire the HTTP client's currently unused protected reusable-client methods.
- Do not add README differences or Laravel porting-guide entries for internal fixes that require no application changes. Record the Algolia typed-filter difference because indexed attribute types may need migration.

## References and verified contracts

- Laravel Scout documents that the database engine always uses the Eloquent primary key; `getScoutKey()` and `getScoutKeyName()` do not alter database-engine identity.
- Algolia supports numeric comparison filters, including equality, and applies `NOT` to numeric expressions. Numeric attributes do not require facet configuration:
  - <https://www.algolia.com/doc/api-reference/api-parameters/filters>
  - <https://www.algolia.com/doc/api-reference/api-parameters/numericAttributesForFiltering>
- Typesense documents pages starting at one and a maximum `per_page` of 250. It does not document the inherited `4294967295` page-offset threshold:
  - <https://typesense.org/docs/27.0/api/search.html>
- Typesense response fields retain their native meanings: `found` is the number of matches and `out_of` is the collection population.
- `bin/run-database-tests.sh` discovers `tests/Integration/*/Database/Postgres` automatically. No workflow edit is required.
- A local Swoole runtime probe confirms that a defer registered while another defer is executing runs in the same coroutine settlement sequence. The Scout regression suite will pin this dependency.
- Current Laravel's `PendingRequest::buildClient()` matches Hypervel's implementation. The protected reusable-client methods remain unchanged for subclass compatibility.

## Cross-cutting decisions

### Preserve Laravel APIs unless correctness requires a difference

Existing method names, signatures, named arguments, protected extension points, and fluent return values remain compatible. Intentional behavior changes are limited to verified defects:

- invalid Algolia numeric syntax becomes valid typed syntax;
- invalid Typesense page sizes throw instead of being silently changed;
- Typesense model and Builder options may no longer override Scout-owned `page` and `per_page`; callers select result size through `take()`, `paginate()`, or `max_total_results`;
- database-engine primary-key behavior matches its documented contract;
- excluded Inertia dot props are no longer evaluated;
- Saloon header replacement becomes HTTP-name case-insensitive;
- Saloon's public `currentPage()` value becomes the documented zero-based iterator position, independent of the remote start page;
- API structured-body conversion no longer moves GET/HEAD query data into a body;
- HTTP `RequestSending` events for API-client sends observe the request after API request middleware, matching the request that continues through the HTTP pipeline;
- later Guzzle middleware and `beforeSending` callbacks may overwrite API request middleware changes, so body-dependent work such as signing belongs in those later layers;
- API resources reject mutation instead of partly mutating or creating dynamic properties.

### Keep hot paths direct

- Scout HTTP deferral uses one execution-local `SplQueue` and one `Coroutine::defer()` callback.
- Non-HTTP Scout work executes immediately and retains no buffer.
- Database Scout uses model metadata already in memory; it performs no schema query.
- Typesense pagination performs only the requests required by a fixed page size and may over-fetch only the final page.
- The API bridge replaces the existing `beforeSending` bridge; it does not add a second bridge layer.
- Container freshness uses the existing `Transient` marker rather than provider bindings or factory machinery.

### Documentation boundaries

Update canonical documentation only where users need to choose inputs or understand timing/order. Keep Scout's existing README difference accurate and add one porting-guide note for Algolia's typed-filter contract; other fixes require no README or porting-guide entry.

## 1. Scout filters and indexing lifecycle

### 1.1 Algolia typed filters

Update `Hypervel\Scout\Engines\AlgoliaEngine` so equality and list filters preserve the PHP value category after unwrapping a `BackedEnum`:

| PHP value | Algolia expression |
|---|---|
| `int`, finite `float`, int-backed enum | `field = 42` |
| `string`, string-backed enum | `field:'escaped value'` |
| `bool` | `field:true` / `field:false` |

For inequality:

- numeric values use `NOT field = 42`;
- string and boolean values use `NOT field:'value'` / `NOT field:true`.

For lists:

- numeric `whereIn` uses `(field = 1 OR field = 2)`;
- numeric `whereNotIn` uses `NOT field = 1 AND NOT field = 2`;
- string and boolean lists retain facet syntax.

Do not encode numeric inequality as `field != 1`. Algolia array fields match a comparison when any element satisfies it, so a negated equality is required to express that no element matches. Numeric `whereNotIn` repeats that expression for every excluded value.

Reject `null`, non-scalar values, `NAN`, and infinities before string conversion. Numeric-looking strings remain strings. Preserve the existing strict grammar for string values used with ordering operators.

Format finite floats with JSON number encoding rather than PHP's precision-sensitive string cast. This preserves the shortest round-trip representation; tests pin both ordinary precision and the scientific-notation boundary.

Preserve the protected `formatFilterValue(mixed): string` extension point and call it for every value category, including numerics. A shared equality helper unwraps backed enums only to choose numeric versus facet syntax after formatting. Do not add parallel filter compilers or a formatter value object.

### 1.2 Ordered HTTP deferral

Replace the one-defer-per-job default path in `Searchable::dispatchSearchableJob()` with one execution-local FIFO:

```php
if (! RequestContext::has()) {
    $job();
    return;
}

$jobs = CoroutineContext::get(self::SCOUT_JOBS_CONTEXT_KEY);

if (! $jobs instanceof SplQueue) {
    $jobs = new SplQueue;
    CoroutineContext::set(self::SCOUT_JOBS_CONTEXT_KEY, $jobs);
    Coroutine::defer(/* drain the captured queue */);
}

$jobs->enqueue($job);
```

Declare the implementation-only key as `protected const string SCOUT_JOBS_CONTEXT_KEY = '__scout.jobs'`. Tests verify cleanup through subsequent dispatch behavior rather than widening this detail into public API.

The deferred owner drains until the queue is empty so work enqueued by a running job stays in FIFO order. Each job is wrapped independently: report its exception and continue draining. Always forget the context key in `finally`.

Swoole executes a defer registered while another defer is running. If an earlier user defer performs a searchable mutation after the Scout owner has drained and released its queue, that mutation may therefore create a new queue and owner normally. Pin this runtime contract with a regression test; do not add a second lifecycle flag or an immediate-execution fallback.

The queue's existence is also the owner-registration marker; do not add a second flag. The queue is coroutine-local and is discarded at request completion, so it cannot leak between requests or grow for the worker lifetime.

Keep the existing import path first and unchanged. `ConcurrentImportRunner` continues to own bounded command-import concurrency and propagate child failures. Outside an active `RequestContext`—console commands, seeders, and queue jobs—run non-queued Scout work immediately. There is no response to protect there, and immediate execution avoids retaining model collections across a long process.

Update the Scout config comment and documentation to describe HTTP-only after-response deferral and immediate non-HTTP execution.

## 2. Scout pagination and database identity

### 2.1 Typesense `take()` pagination

Refactor `TypesenseEngine::performPaginatedSearch()` around one target and one fixed request size:

```php
$target = min($builder->limit, $this->maxTotalResults);
$perPage = min($target, $this->maxPerPage);
```

This method is reached only when `Builder::$limit` is non-null and at least the engine maximum, so no null fallback belongs in the target calculation.

Use that `per_page` for every requested page. Continue until:

- the target is covered;
- the known `found` count is exhausted; or
- Typesense returns a short page.

Concatenate hits, then truncate once to the target. This preserves page offsets and limits over-fetch to the final page. Do not shrink the final request because changing `per_page` changes Typesense's page offset.

Retain and reuse the first page's search parameters in the synthetic response instead of rebuilding the same deterministic array after pagination.

Model search parameters and Builder options are merged after the base Typesense parameters. Reassert the method's `page` and `per_page` arguments once at the end of `buildSearchParameters()` so options cannot override engine-owned pagination in the unlimited-search, single-request `take()`, or multi-page `take()` path. Other raw search options remain unchanged. `deleteByFilter()` continues to pass `null` for `per_page` and consume only `filter_by`.

Return a synthetic response with truthful native semantics:

- `hits`: the truncated hits;
- `found`: page-one matched count;
- `out_of`: page-one collection count;
- `page`: `1`;
- `request_params`: page-one parameters containing the actual fixed `per_page`.

### 2.2 Typesense ordinary pagination

In `TypesenseEngine::paginate()`:

- keep `$page = max(1, $page)` because page is request-derived and Builder already follows this convention;
- require `1 <= $perPage <= 250` and throw a descriptive `InvalidArgumentException` before I/O otherwise;
- stop applying `max_total_results` to ordinary paginator page size;
- delete the undocumented `4294967295` clamp entirely.

The authoritative assignment in `buildSearchParameters()` keeps the validated paginator arguments, outgoing query, and paginator metadata in agreement.

`typesense.max_total_results` remains the cap for `take()`, not a silent mutation of paginator page size.

Update the dynamic Typesense parameter documentation to state that Scout owns `page` and `per_page`. Callers choose result size through `take()`, `paginate()`, or `max_total_results`; model and Builder options remain available for every other Typesense search parameter.

### 2.3 Database-engine primary keys

Use Eloquent primary-key metadata consistently throughout `DatabaseEngine`:

- identity column: `getKeyName()`;
- identity type: `getKeyType()`;
- qualified identity/order column: `getQualifiedKeyName()`.

This includes default ordering in length-aware pagination, simple pagination, and model retrieval. Custom Scout keys remain external-engine keys and ordinary searchable columns when explicitly returned by `toSearchableArray()`; they do not replace database identity.

For an integer primary key included in `toSearchableArray()`:

1. Search it only through exact equality.
2. Exclude it from every LIKE/ILIKE, prefix, and full-text path on every supported database, even if the column is named in a text-search attribute.
3. Emit equality only for a decimal query.
4. On PostgreSQL, normalize leading zeroes and validate the query against the native integer range before emitting equality:

```php
$normalized = ltrim((string) $builder->query, '0') ?: '0';
$withinRange = filter_var($normalized, FILTER_VALIDATE_INT) !== false;
```

Do not cast before validation. Huge decimal strings must produce no primary-key predicate rather than saturating to `PHP_INT_MAX`. String, UUID, and ULID primary keys remain in the partial-match path.

There are two exclusion points because the ordinary searchable columns and attribute-derived columns have different sources:

1. Unconditionally skip an integer primary key in the ordinary LIKE/ILIKE loop, whether or not the decimal query was eligible for exact equality.
2. Filter the integer primary key out in the shared attribute-column derivation before caching the `SearchUsingPrefix` or `SearchUsingFullText` columns.

The second exclusion keeps predicates and PostgreSQL relevance ordering on the same column set. If the key was the only full-text column, the existing default primary-key ordering applies because no full-text predicate remains to rank.

Do not inspect schemas for other numeric searchable columns. That would add database I/O to every search for application-controlled input. Document that PostgreSQL users should omit non-text, non-primary columns from the LIKE-based searchable set.

### 2.4 Callback-transformed paginator payloads

In all four non-database paginator paths—`paginate`, `simplePaginate`, `paginateRaw`, and `simplePaginateRaw`—assign the result of `applyAfterRawSearchCallback()` once and use that same value for:

- engine mapping;
- raw paginator items;
- total calculation;
- `hasMorePagesWhen()`.

Use `Builder::getTotalCount()` unconditionally in both simple paginator variants. Its no-query-callback path returns after the engine total; the warmed engine-manager lookup is negligible, and one shared decision avoids duplicating query-callback semantics.

This also fixes the adjacent defect where `simplePaginate()` and `simplePaginateRaw()` ignored an Eloquent `query()` callback while length-aware pagination honored it.

## 3. URI helper

Preserve route action arrays exactly. Cast every non-array input once before route-name/action/string dispatch:

```php
if (! is_array($uri)) {
    $uri = (string) $uri;
}
```

This supports PSR URI implementations and ordinary Stringable objects without passing objects to `str_contains()`. It does not widen the public union or alter array action routing.

## 4. Inertia request and prop resolution

### 4.1 Callable contracts

Keep `ResolvesCallables::resolveCallable()` as the object-only callable gate used by generic data-bearing wrappers. Add one protected method for values already guaranteed to be callables:

```php
protected function invokeCallable(callable $value): mixed
{
    return App::call($value);
}
```

Use `invokeCallable()` only in `DeferProp`, `OptionalProp`, and `OnceProp`, whose constructors require `callable`. Callable arrays and static method strings then receive container injection as their contract promises.

Keep `MergeProp`, `AlwaysProp`, `ScrollProp`, and generic prop data on `resolveCallable()`. They accept `mixed`, so callable-looking arrays and strings remain data. Invokable objects continue to resolve through the container.

### 4.2 Partial dot props

Before resolving or traversing a top-level dot key in `PropsResolver::unpackDotProps()`, call `shouldIncludeInPartialResponse($value, $key, false)`. This is the same outer predicate used by `resolveProps()`, including `AlwaysProp` semantics. If excluded, leave the literal key/value untouched. The normal `resolveProps()` pass will discard it without invoking its closure or resolving an otherwise-excluded intermediate parent. Included dot-keyed `AlwaysProp` values are still unpacked into their nested payload shape.

Correct the upstream nested-`AlwaysProp` defect in that shared predicate. When an ordinary array parent does not match the partial request, retain it if its already-materialized subtree contains an `AlwaysProp`; use one recursive early-exit helper and do not evaluate closures or `Arrayable` values to discover hidden props. This applies equally to ordinary nested arrays and dot-keyed values after unpacking. Because the retained parent remains an array, ordinary siblings continue through normal partial filtering and are not evaluated. Putting this rule in the shared predicate also ensures a dot-keyed array containing an always prop is unpacked rather than emitted as a literal dotted key.

Included descendants still use the existing ancestor matching and `ensurePathIsTraversable()` logic. Do not build a second tree walker.

### 4.3 Reload testing

Add `Header::INERTIA => 'true'` to `ReloadRequest`. An Inertia reload must exercise the JSON response path, not the initial Blade response path.

Update `AssertableInertia::fromTestResponse()`:

- if the response carries the Inertia response header, parse the JSON page;
- otherwise, retain the existing `page` view-data path;
- validate the same required page keys in either case;
- keep the existing concise assertion failure when the response is not a valid Inertia page.

Test through the real middleware so reload, partial headers, version mismatch/location responses, redirect normalization, and legacy view assertions use their actual response formats.

### 4.4 Helper type

Widen only the `inertia()` helper's props union to include `ProvidesInertiaProperties`, matching `ResponseFactory::render()`. Preserve arrays, `Arrayable`, the conditional return type, and the no-component factory behavior.

## 5. Saloon headers and pagination

### 5.1 Header replacement

Implement `HasHeaders::replaceHeaders()` as one case-insensitive fold over existing and incoming headers. Reconstruct the final associative header array from the fold:

- incoming matching names replace existing values regardless of casing;
- duplicate-case incoming names naturally use the last supplied value and casing;
- unrelated headers remain unchanged.

Do not change `withHeaders()`, whose recursive merge remains additive.

Change `HeaderAuthenticator`, `TokenAuthenticator`, and `AccessTokenAuthenticator` to use `replaceHeaders()`. Make `accept()` use the same replacement path because `Accept` is also single-valued. Refreshing credentials or response preferences then leaves exactly one logical header.

Correct the Saloon documentation: `replaceHeaders()` replaces matching names, not the entire header collection.

### 5.2 Page limits

Keep the existing fields but give them one meaning:

- `pageNumber`: remote page number applied to the next request;
- `currentPage`: zero-based iterator position for this iteration.

On rewind, set `pageNumber = startPage` and `currentPage = 0`. `valid()` stops when `currentPage >= maxPages`; `next()` increments both after a response. This handles start pages zero, one, or higher without deriving count from page numbering.

For pooled pagination:

- the first response key is zero;
- remaining keys are `$page - $startPage`;
- remaining request generation must not mutate iterator-position state;
- a non-null `maxPages` value less than or equal to zero sends no request, matching iterator validity without adding a new argument-validation contract;
- `maxPages(N)` returns at most N pages from any start page.

No new counter is required.

## 6. API and HTTP clients

### 6.1 Structured API bodies

Call `ApiRequest::ensureStructuredMutationAllowed()` at the start of `asJson()` and `asForm()`, before decoding or changing a body. GET and HEAD requests then reject structured-body conversion consistently, including when `hypervel_data` currently represents query parameters.

Keep `withBody()` as the explicit raw-body escape hatch for callers that intentionally need a GET/HEAD body. Update API-client documentation to include `asJson()` and `asForm()` in the structured-mutation restriction.

### 6.2 HTTP middleware prepend API

Add an additive Laravel-style method next to `withMiddleware()`:

```php
public function prependMiddleware(callable $middleware): static
{
    $this->middleware->prepend($middleware);

    return $this;
}
```

Do not change `withMiddleware()` ordering. Add `attributes(): array` next to `withAttributes()` so an owning integration can construct the same request wrapper outside `beforeSending()` without reaching into protected state.

Regenerate `Hypervel\Support\Facades\Http` with `composer facade 'Hypervel\Support\Facades\Http'`; do not edit its generated method list manually.

Document middleware ordering and correct the HTTP header prose: `replaceHeaders()` replaces matching array keys while retaining unrelated headers.

### 6.3 API bridge placement

Delete `PendingRequest::$bridgeRegistered` and `prepareClient()`. When `getRequest()` first creates the HTTP pending request, prepend exactly one bridge middleware to it.

For each attempt the bridge:

1. wraps the PSR request as the normal HTTP client request;
2. restores `hypervel_data` and the pending request's attributes;
3. converts it to `ApiRequest` and adds API context;
4. runs API request middleware once;
5. stores the resulting `activeRequest`;
6. forwards its PSR request to the next Guzzle middleware.

The prepared-body tracker remains outside the bridge. The bridge runs before ordinary API/user/global Guzzle middleware, so cache and circuit-breaker short circuits still have a complete `ApiRequest`. `beforeSending` callbacks retain their existing later position.

A caller may explicitly prepend middleware ahead of the bridge. If that middleware returns a response without sending the request, no `ApiRequest` exists. Detect `activeRequest === null` after the HTTP call and throw a `LogicException` explaining that middleware ahead of the API bridge short-circuited the send. Do not allow a null-member fatal.

The API client remains synchronous. Retries run the bridge once per attempt, matching the current `beforeSending` behavior, and the final attempt owns the response context.

Because the bridge now runs before `beforeSending`, the HTTP client's default callback stores and emits the post-API-middleware request through `RequestSending`. This is intentional: observers see the request that continues toward transport rather than the pre-middleware request.

### 6.4 Mutable builder lifetimes

Make both mutable builders implement the existing `Hypervel\Contracts\Container\Transient` marker:

- `Hypervel\Http\Client\PendingRequest`
- `Hypervel\ApiClient\PendingRequest`

Unbound container resolutions then return fresh builders while explicit `bind`, `singleton`, `scoped`, and `instance` registrations retain precedence. Do not add provider bindings, `SelfBuilding`, or construction factories. Existing constructors and fluent APIs remain unchanged.

### 6.5 Read-only API resources

Make resource mutation fail consistently at the resource boundary. Add `ApiResource::__set()` and change `__unset()`, `offsetSet()`, and `offsetUnset()` to throw imported `LogicException` instances with syntax-appropriate messages beginning with `Resource data`.

Reads, serialization, response access, and forwarded response methods remain unchanged. Do not mutate the underlying response and do not permit dynamic shadow properties.

### 6.6 Incomplete HTTP types

Apply the narrow source type corrections:

```php
// ResponseSequence
protected Closure|PromiseInterface|null $emptyResponse = null;

// PendingRequest
protected ?PromiseInterface $promise = null;

// Response
public function cookies(): ?CookieJar
```

Keep `requestsReusableClient()` and `getReusableClient()` untouched. Current Laravel has the same `buildClient()` body, and changing it would alter async client lifetime and protected subclass behavior rather than fix finding 158.

## 7. Test return types and TODO cleanup

Add `: void` to the remaining untyped test methods using targeted, one-file-at-a-time patches:

- 173 methods in `tests/Http/HttpClientTest.php`;
- 30 methods in `tests/Http/HttpRequestTrustedStateTest.php`;
- 4 methods in `tests/Http/HttpRequestTrustedStateCoroutineTest.php`.

The TODO's count of 176 for `HttpClientTest` is stale; the current source has 173. Do not alter method bodies, data providers, setup hooks, or helper methods. Verify each file immediately, then remove the completed TODO item in the same implementation slice.

## Test plan

### Scout

- `tests/Scout/Unit/Engines/AlgoliaEngineTest.php`
  - exact expressions for integers, finite floats, numeric-looking strings, ordinary strings, booleans, backed enums, negatives, escaping, lists, empty lists, invalid scalars, arrays, `NAN`, and infinities;
  - protected formatter overrides still affect numeric equality, ordering, and list filters;
  - numeric scalar inequality and `whereNotIn` use negated equality, including array-valued attributes.
- `tests/Integration/Scout/Algolia/AlgoliaFilteringIntegrationTest.php`
  - real numeric equality/inclusion/exclusion against configured numeric attributes.
- Add focused dispatch tests for:
  - one defer owner for multiple jobs;
  - save/delete and delete/save FIFO ordering;
  - multiple model classes;
  - reentrant enqueue during drain;
  - one failing job is reported and later jobs run;
  - context cleanup;
  - a user defer registered before Scout can enqueue a searchable mutation after the first owner drains, and Swoole runs the newly registered owner;
  - no-request execution is immediate;
  - import execution still uses `ConcurrentImportRunner`.
- `tests/Scout/Unit/Engines/TypesenseEngineTest.php`
  - fixed `per_page`, exact truncation, short pages, known-found exhaustion, one final over-fetch only;
  - native `found`/`out_of` meanings and actual request parameters;
  - model/Builder options cannot override engine-owned page values in unlimited search, single-request `take()`, multi-page `take()`, or paginator calls, while other raw search options remain available;
  - per-page 1 and 250 accepted, 0/negative/251 rejected before I/O;
  - large pages pass through unchanged and page zero normalizes to one;
  - `max_total_results` limits `take()` but not paginator page size.
- `tests/Scout/Unit/BuilderTest.php`
  - transformed raw payload is shared by mapping and metadata across all four paginator methods;
  - Eloquent query callbacks affect both simple paginator variants;
  - unchanged raw callback remains a single invocation.
- `tests/Scout/Feature/DatabaseEngineTest.php`
  - configure the package fixture in `ScoutTestCase::defineEnvironment()` and select the database driver in the test class's environment hook, before providers consume configuration;
  - primary-key metadata is used consistently;
  - custom Scout keys do not replace database identity;
  - integer key exact matching and LIKE exclusion;
  - a dedicated integer-key-annotated fixture uses the existing invoker pattern to verify that the key is removed from cached prefix and full-text attribute columns, without repurposing or weakening the existing cache assertions;
  - string/UUID key partial matching and default-order behavior.
- Add `tests/Integration/Scout/Database/Postgres/DatabaseEnginePostgresIntegrationTest.php` with a minimal table and `RefreshDatabase`:
  - a non-numeric search with the default searchable primary key does not emit integer ILIKE and still matches text;
  - a model that annotates its integer key for full-text search neither emits an integer full-text predicate nor builds integer relevance vectors, and falls back to default key ordering when no full-text column remains;
  - maximum valid integer equality works;
  - leading-zero equality works;
  - an overflow decimal produces no cast/query error and no false primary-key match.

### URI and Inertia

- `tests/Support/SupportUriTest.php`: plain string, route string, action string, array action, global Stringable, PSR URI, and unsupported object type enforcement.
- Prop wrapper tests: callable arrays/static method strings execute for Defer/Optional/Once; callable-looking arrays/strings remain data for Merge/Always/Scroll/generic props; invokable objects still inject dependencies.
- `tests/Inertia/PropsResolverTest.php`: excluded dot closures and otherwise-excluded intermediate closures never run; included descendants run once; unrequested nested and dot-keyed `AlwaysProp` values remain included at arbitrary depth; dot-keyed arrays retain nested shape; ordinary siblings under a retained ancestor remain excluded without evaluation; only/except, multiple descendants, collisions, Arrayable intermediates, and metadata remain correct.
- `tests/Inertia/Testing/AssertableInertiaTest.php`: real middleware reload, partial only/except headers, JSON page parsing, version/location and redirect behavior, deferred reloads, and legacy view parsing.
- `tests/Inertia/HelperTest.php`: `ProvidesInertiaProperties`, array, `Arrayable`, and no-component factory behavior.

### Saloon

- Header repository/request tests: mixed casing, unrelated preservation, duplicate incoming casing last-wins, additive `withHeaders()` unchanged.
- Repeated `accept()` / `acceptJson()` calls leave one logical `Accept` value.
- Authenticator tests: applying and refreshing credentials leaves one logical authorization header.
- `tests/Saloon/Pagination/PaginatorTest.php`: iterator and pool parity for start pages 0, 1, and 5; negative, zero, one, and N max-page values; public `currentPage()` and response keys use zero-based iterator positions; repeated iteration reset; page-one response retained for `startPage(0)`; no extra requests.

### API and HTTP

- `tests/ApiClient/ApiRequestTest.php`: GET/HEAD `asJson` and `asForm` reject with empty or query-derived data; POST/PUT conversion remains valid; raw GET/HEAD `withBody` remains valid.
- `tests/Http/HttpClientTest.php`: prepend order versus appended/global/request middleware; request attributes accessor; source typing regressions; existing middleware behavior unchanged.
- `tests/FacadeDocumenter/FacadeDocblocksTest.php`: generated HTTP facade methods remain current and parseable.
- `tests/ApiClient/PendingRequestTest.php`: API bridge precedes ordinary Guzzle middleware; short-circuiting cache/circuit middleware retains request context; explicit ahead-of-bridge short circuit throws the descriptive guard; body/data/attributes/context survive; `RequestSending` observes the post-API-middleware request; API and HTTP middleware each run once per attempt; retry and fake paths remain correct.
- Container lifetime assertions: two unbound resolutions of each pending-request class are distinct and do not share mutable options, middleware, callbacks, fakes, context, promises, or active request state; explicit binding precedence remains governed by existing container tests.
- `tests/ApiClient/ApiResourceTest.php`: property/offset assignment and unset all throw exact `LogicException` messages; reads and serialization remain unchanged.
- Response-sequence, promise, and cookie tests cover default, closure, promise, unsent, recorded, and populated paths.
- Run each of the three HTTP test files after its return-type patch and verify the exact untyped method count becomes zero.

## Documentation plan

- `src/docs/scout.md`
  - typed Algolia filters;
  - HTTP-only deferred non-queued indexing and immediate non-HTTP behavior;
  - exact integer primary-key database searches and PostgreSQL non-text guidance;
  - corrected `SearchUsingPrefix` example;
  - Typesense-owned `page`/`per_page`, result-size APIs, `take()` cap, and paginator `perPage` validation.
- `src/scout/README.md`: keep the existing HTTP deferral difference accurate and record Algolia's typed numeric-filter behavior.
- `src/docs/porting-from-laravel.md`: tell Algolia ports to align indexed attribute types with the PHP values passed to Scout filters.
- `src/scout/config/scout.php`: align the queue/default-mode comment with HTTP-only deferral.
- `src/docs/saloon.md`: explain matching-name header replacement.
- `src/docs/api-client.md`: structured mutation restrictions and API/Guzzle middleware order.
- `src/docs/http-client.md`: document `prependMiddleware()` and correct matching-header replacement prose.
- `docs/todo.md`: remove only the completed HTTP test return-type item.

No other package README or porting-guide update is warranted. The remaining changes either fix defects without requiring migration or add an optional additive method whose ordinary Laravel-compatible path remains unchanged.

## Implementation order and verification

1. Implement and immediately test each coherent Scout slice: filters, dispatch lifecycle, Typesense, Builder pagination, then database engine/PostgreSQL.
2. Implement and test URI.
3. Implement and test Inertia callable, dot-prop, reload, and helper slices.
4. Implement and test Saloon headers and pagination.
5. Implement and test API/HTTP structured bodies, middleware bridge/order, lifetimes, resource immutability, and type corrections.
6. Apply and verify the three HTTP test return-type patches, then remove the TODO.
7. Update canonical documentation in the same order as its owning source.
8. Run the affected focused test suites and configured external-service tests.
9. Run `composer fix` once at the final checkpoint.
10. Perform a full self-review across every caller/callee and changed test, checking API parity, coroutine isolation, request ordering, hot-path allocations, unbounded state, stale code/comments, and documentation accuracy.

## Completion criteria

- Every scoped finding and the HTTP TODO item has a direct source/test resolution.
- No Scout job ordering change, cross-request state, or worker-lifetime retained queue remains.
- Search requests and paginator metadata describe the same payload.
- Database Scout follows its documented primary-key contract on PostgreSQL and other supported databases.
- Inertia executes only values whose wrapper contracts declare them callable.
- Saloon authentication produces one logical authorization header and page limits are independent of remote numbering.
- API middleware runs before ordinary Guzzle short circuits without duplicating middleware layers.
- Mutable request builders are fresh through the existing container lifetime marker.
- Laravel public APIs and protected HTTP extension points remain intact except for the approved correctness differences.
- Focused tests and `composer fix` pass.
