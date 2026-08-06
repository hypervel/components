# Complete Pagination correctness, current parity, and query contracts

## Objective

Correct malformed cursor handling, truthful cursor/query value types, explicit page resolution,
JSON failures, paginator reset coverage, and current Laravel paginator parity. Keep paginator
instances operation-local, the seven configuration slots worker-static, and request values owned
by `RequestContext`. Preserve Laravel APIs unless a verified defect or Hypervel's coroutine model
requires the approved correction.

Hypervel 0.4 is greenfield: churn and compatibility with prior Hypervel behavior do not justify
retaining flawed code. Current Laravel APIs, named arguments, extension points, and documented
behavior remain compatible except for the approved invalid-page correction: explicit zero no
longer consults ambient state, and Scout no longer forwards zero or negative pages to engines.

## Evidence baseline

- Hypervel baseline: `28769ce5a` on `audit/pagination-correctness-parity`.
- Current Laravel source: local `examples/laravel/framework` `13.x` at `1a7816b370`.
- Historical changes were used for discovery; current Laravel is the implementation reference:
  - #59699 (`5937afc`): `Cursor` and `CursorTest` malformed-payload handling.
  - #60586 (`4eef2ca`): 18 files across Collections, Pagination contracts/abstracts, Foundation,
    Routing, Support, Validation, View, and type fixtures.
  - #60968 (`dc1b82b`): `CursorPaginator`, `LengthAwarePaginator`, and their tests.
- Hypervel history confirms the relevant owner decisions: `3af18f750` widened ordinary append
  values, `c0befa549` removed Bootstrap selectors, `12fc9ceba` added length-aware
  `current_page_url`, and `3cf34fbec` widened Eloquent's cursor result only to silence PHPStan.
- Hypervel's two Tailwind views are byte-identical to current Laravel.
- Existing ledger findings `pagination-01` and `pagination-02` own missing cursor-order values and
  mixed pivot values. Revalidate them; new Pagination findings begin at `pagination-03`.
- Probes reproduced non-string/malformed cursors, float cursor failures, numeric query-key
  renumbering, explicit page-zero resolver fallback, invalid UTF-8 `TypeError`s, a zero-per-page
  length-aware result, keyed cursor items, relation cursor type rejection, and the `UrlWindow`
  contract/mockability boundary.

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is
also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this
plan” refers to that plan's **Established remediation vocabulary** section.

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

## Architecture and retained boundaries

- `Paginator`, `LengthAwarePaginator`, and `CursorPaginator` are fresh operation-local values.
  Their query, page/cursor, path, fragment, options, and items need no service binding, context
  slot, lock, or teardown.
- Four resolvers, two default view names, and the cursor resolver are boot-owned worker-static
  configuration. Public mutators remain boot/test only; `AfterEachTestSubscriber` remains the
  sole test reset owner.
- Request-facing resolver closures remain worker-static but read the current request from
  `RequestContext` on each invocation. They never capture a request.
- The view factory remains lazily resolved from the canonical `view` service so application and
  test rebindings work.
- URL/query serialization remains a single `Arr::query()` pass; JSON serialization remains one
  native `json_encode()` pass.
- Relation and builder corrections state existing runtime behavior; they do not add wrappers or
  change query execution.

## Findings and final decisions

| ID | Category | Severity | Confidence | Final decision |
|---|---|---:|---:|---|
| `pagination-01` | Cursor-order correctness | Major | High | Revalidate the completed null/missing-order-value rejection. |
| `pagination-02` | Pivot cursor typing | Major | High | Revalidate the completed mixed pivot-value return. |
| `pagination-03` | Malformed cursor defect/parity | Major | High | Return `null` for non-string, undecodable, non-array, incomplete, or non-boolean-direction cursor payloads. |
| `pagination-04` | Cursor value contract | Major | High | Return mixed cursor parameters and preserve bool/float database order values. |
| `pagination-05` | Query append contract | Major | High | Accept Laravel-supported query values and preserve integer keys without renumbering. |
| `pagination-06` | Explicit page defect | Minor | High | Treat only `null` as omitted at page-based boundaries; normalize Scout pages to at least one before engine dispatch. |
| `pagination-07` | Resolver hot-path improvement | Minor | High | Read `RequestContext` once per request-facing resolver and remove container request resolution. |
| `pagination-08` | View contract | Minor | High | Return the existing View Factory contract without changing lazy resolution. |
| `pagination-09` | `UrlWindow` type contract | Minor | High | Replace the broad suppression with a local contract-and-structural intersection; keep Laravel's public contract mockable. |
| `pagination-10` | JSON failure semantics | Minor | High | Use one throwing JSON encode at all four Pagination-owned boundaries. |
| `pagination-11` | Current Laravel type parity | Minor | High | Port conditional fragment and precise iterator types from current source. |
| `pagination-12` | Static reset integrity | Minor | High | Share Tailwind default constants and pin all seven static slots. |
| `pagination-13` | Intentional/public differences | Minor | High | Record Tailwind-only views and `current_page_url`; keep both approved behaviors. |
| `pagination-14` | Current Laravel runtime parity | Major | High | Port zero-per-page last-page safety and unconditional cursor item reindexing. |
| `pagination-15` | Package metadata/hygiene | Minor | High | Complete root provider discovery, option docs, strict comparison, guide grammar, and test typing. |
| `collections-15` | JSON failure semantics | Minor | High | Make `EnumeratesValues::toJson()` throw `JsonException`. |
| `support-32` | JSON failure semantics | Minor | High | Make `Fluent` and `MessageBag` JSON boundaries throw `JsonException`. |
| `support-33` | Current Laravel type parity | Minor | High | Port `Lottery::choose()` conditional PHPDoc. |
| `sanctum-02` | JSON failure semantics | Minor | High | Make `NewAccessToken::toJson()` throw `JsonException`. |
| `api-client-01` | JSON failure semantics | Minor | High | Make `ApiResource::toJson()` throw `JsonException`; do not expand into the deferred package audit. |
| `database-24` | Cursor pagination contracts | Major | High | Accept cursor objects in both relation families and restore concrete Eloquent cursor results. |
| `database-25` | Paginator type contracts | Minor | High | Restore all three Eloquent result generics and make Query cursor per-page nullability truthful. |
| `scout-41` | Search pagination defect | Major | High | Clamp all four public Scout page producers to page one before any engine sees zero or a negative page. |
| `routing-25` | Current Laravel type parity | Minor | High | Add `Route::domain()`'s conditional get/set PHPDoc and the missing current Routing type fixture. |

View's `ComponentAttributeBag::data()` type is complete in its owning audit. Translation's `__()`
conditional type remains routed to its active owning work. Record the complete #60586 file-set
review and durable route in the ledger/dependency index.

## Implementation

### 1. Classify malformed cursors at the boundary

Widen only the decoder input and validate the decoded envelope before construction:

```php
public static function fromEncoded(mixed $encodedString): ?static
{
    if (! is_string($encodedString)) {
        return null;
    }

    $parameters = json_decode(
        base64_decode(str_replace(['-', '_'], ['+', '/'], $encodedString)),
        true,
    );

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    // Validate the complete envelope here so strict construction cannot raise a TypeError for its direction.
    if (! is_array($parameters)
        || ! array_key_exists('_pointsToNextItems', $parameters)
        || ! is_bool($parameters['_pointsToNextItems'])) {
        return null;
    }

    $pointsToNextItems = $parameters['_pointsToNextItems'];
    unset($parameters['_pointsToNextItems']);

    return new static($parameters, $pointsToNextItems);
}
```

Keep lenient base64 decoding. Do not add signing, encryption, recursive schema validation, or
exception swallowing. Update constructor/map docs to `array<array-key, mixed>`. Test all current
upstream malformed shapes, Hypervel's additional non-boolean direction, and `/?cursor[]=x` through
the request resolver.

### 2. Preserve truthful cursor and query values

`Cursor::parameter()` returns `mixed`; `parameters()` documents `array<int, mixed>`. Keep
`getPivotParameterForItem(): mixed` from `pagination-02` and pin both bool/float unit values and a
real SQLite second-page float-order query.

Align both abstract paginator implementations and both public contracts:

```php
public function appends(
    array|int|string|null $key,
    array|bool|float|int|string|null $value = null,
): static;

protected function addQuery(int|string $key, mixed $value): static;
```

Document stored query maps as `array<array-key, mixed>`. In both URL builders, overlay caller
parameters without renumbering nonzero integer keys:

```php
$parameters = array_replace($this->query, $parameters);
```

Test nonzero/nonsequential integer keys plus scalar and nested-array values. Do not advertise
objects or resources.

### 3. Distinguish omitted pages from explicit zero

In both paginator `setCurrentPage()` methods, distinguish explicit zero from omission with the
method's existing variable and resolver shape:

```php
// Paginator
$currentPage = $currentPage ?? static::resolveCurrentPage();

// LengthAwarePaginator
$currentPage = $currentPage ?? static::resolveCurrentPage($pageName);
```

At the four Query/Eloquent producer sites, use null coalescing before their already-clamped SQL
offset calculation:

```php
$page = $page ?? Paginator::resolveCurrentPage($pageName);
```

At Scout's four public page producers, normalize once before dispatch to every first-party or
third-party engine:

```php
$page = max(1, $page ?? Paginator::resolveCurrentPage($pageName));
```

This closes the existing negative-page hole and prevents explicit zero reaching Algolia as
`page => -1`. Query/Eloquent `offset()` and the database-backed Scout engines already clamp their
arithmetic; `TypesenseEngine` also clamps, while Meilisearch only dropped zero incidentally and
Algolia did not clamp. The shared Scout boundary is the lowest complete owner.

Leave every `$perPage ?: ...` expression unchanged. Eloquent/Scout intentionally treat zero as
“use the model/default value”; Query Builder preserves Laravel's caller-supplied zero and
`LengthAwarePaginator` handles it safely in step 8. Test a non-one ambient resolver across direct,
Query, Eloquent, and Scout producers, plus explicit zero and negative pages through Scout's raw
engine path so database-side clamping cannot make the regression vacuous.

### 4. Remove duplicate request resolution and type rendering

Each request resolver reads context once and uses its existing no-request default:

```php
static function (string $pageName = 'page'): int {
    $request = RequestContext::getOrNull();

    if ($request === null) {
        return 1;
    }

    $page = $request->input($pageName);

    return filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1
        ? (int) $page
        : 1;
}
```

Use the equivalent direct logic for path (`'/'`), query (`[]`), and cursor (`null`). Do not call
`has()`, resolve `$app['request']`, or capture `$app` in request closures. Static closures merely
signal no binding; the performance gain comes from removing the second context lookup and
container request resolution.

Keep the view resolver lazy and resolve `$app->make('view')`. Type both `viewFactory()` methods
and their resolver as `Hypervel\Contracts\View\Factory`. Laravel's factory contract returns
`View`, while paginator `render()` promises `Htmlable`; the concrete framework view implements
both. State that local invariant immediately above each of the three render returns as
`Htmlable&View`. Do not widen either public contract, add a runtime guard, or return the concrete
factory. The View contract also serves plain-text mail and TypeScript generation, so making every
view `Htmlable` would be semantically false as well as Laravel-incompatible. Tests pin
request/no-request behavior, container-request rebinding not overriding `RequestContext`,
coroutine isolation, and lazy view factory rebinding. Document the request-binding difference in
the Pagination README only.

Rewrite `PaginationState::resolveUsing()`'s method warning so it states the final ownership:
the four request resolvers read `RequestContext` without capturing the container, while only the
worker-static lazy view resolver captures it. Remove the stale claim that all request resolvers
capture `$app`.

### 5. State `UrlWindow`'s local structural requirement

Do not add a native property to Laravel's public contract. At the only consumer:

```php
/**
 * The Laravel contract omits the readable window-size property required by UrlWindow. Keep the
 * structural expectation local so the public contract remains compatible and mockable.
 *
 * @var object{onEachSide: int}&PaginatorContract $paginator
 */
$paginator = $this->paginator;

$onEachSide = $paginator->onEachSide;
```

Use the concise repository-style WHY form during implementation; both intersection halves are
load-bearing. Remove the bare `property.notFound` suppression.

Record the rejected interface-property alternative and its evidence:

1. current Laravel does not declare `onEachSide`, so Hypervel would make the public contract
   stricter;
2. pinned Mockery cannot mock an interface with the abstract property (`contains 1 abstract
   method`), although concrete paginator mocks work;
3. runtime failure requires a custom implementation that also reaches `UrlWindow`, supporting
   Minor severity; and
4. narrowing the stored property PHPDoc instead of the local read rejects normal
   `LengthAwarePaginator` assignments at higher analysis levels.

This disposition is complete, not open. No README difference is created because the public
contract stays Laravel-shaped.

### 6. Make strict JSON boundaries fail with `JsonException`

Use one encoding pass and preserve caller flags:

```php
return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
```

`Cursor::encode()` has no option parameter, so use `JSON_THROW_ON_ERROR` directly before its
existing base64 transformation. Add `@throws JsonException` and invalid UTF-8 regressions at all
nine boundaries across five package owners:

- Pagination: `Cursor`, `Paginator`, `LengthAwarePaginator`, `CursorPaginator`;
- Collections: `EnumeratesValues`;
- Support: `Fluent`, `MessageBag`;
- Sanctum: `NewAccessToken`;
- API Client: `ApiResource`.

Keep `JSON_PARTIAL_OUTPUT_ON_ERROR` and `JSON_INVALID_UTF8_SUBSTITUTE` effective when callers pass
them. Do not add a helper, recursive preflight, wrapper exception, or second encode.

Document the propagated exception on the six delegating `toPrettyJson()` methods in
`Paginator`, `LengthAwarePaginator`, `CursorPaginator`, `EnumeratesValues`, `Fluent`, and
`MessageBag`, and on `Enumerable::toJson()` / `Enumerable::toPrettyJson()`. Do not annotate the
shared `Jsonable` contract: Model and JSON Resource implementations translate `JsonException`
into `JsonEncodingException`, so that contract cannot truthfully promise one exception family.

### 7. Port current static types without changing runtime behavior

Replace the existing fragment union docs on both contracts and both abstracts with current
conditional return types:

```php
/** @return ($fragment is null ? null|string : $this) */
public function fragment(?string $fragment = null);
```

Use current `ArrayIterator`/`Traversable` docs and assertions exactly, preserving stronger native
types. Port `Lottery::choose()`'s current conditional PHPDoc and add `types/Support/Lottery.php`
to pin both branches; no upstream fixture exists. Record View's completed
`ComponentAttributeBag::data()` correction, route Translation's `__()` conditional type to its
active owning work, and record the complete #60586 file inventory so neither is lost.

Add the one remaining unowned source correction from that inventory:

```php
/**
 * Get or set the domain for the route.
 *
 * @return ($domain is null ? null|string : $this)
 */
```

Record the file-set result, not just its names: Collections `Arr::random()`,
`Enumerable::random()`, and `LazyCollection::random()` are already current; Routing
`Route::getMetadata()` is current while `Route::domain()` needs the line above; Validation's
Email/File/Password defaults are already truthfully stronger; Hypervel's real mapped fixtures
`types/Collections/Arr.php` and `types/Collections/Collection.php` already pin the two Support
type files; Pagination contracts, abstracts, and type fixture plus Support Lottery land here;
View's `ComponentAttributeBag::data()` correction is complete, and Translation remains the
durable active-owner route.

Hypervel has no Routing type fixture. Create `types/Routing/Route.php` by porting the complete
current seven-assertion upstream file rather than only the historical PR additions. Add the
repository-standard `declare(strict_types=1);` header and Hypervel imports, matching sibling type
fixtures:

```php
assertType('array', RouteFacade::get('/')->middleware());
assertType(Route::class, RouteFacade::get('/')->middleware('auth'));
assertType(Route::class, RouteFacade::get('/')->middleware(['auth']));

assertType('string|null', RouteFacade::get('/')->domain());
assertType(Route::class, RouteFacade::get('/')->domain('example.com'));

assertType('array<mixed>', RouteFacade::get('/')->getMetadata());
assertType('mixed', RouteFacade::get('/')->getMetadata('key'));
```

The four domain/metadata assertions pin #60586; the three middleware assertions keep the new
fixture equal to current Laravel instead of creating a partial test file.

For `AbstractCursorPaginator::setCollection()`, keep only the line-scoped assignment suppression
and explain that assignment precedes `@phpstan-this-out` rebinding the receiver from
`TKey/TValue` to `TSetKey/TSetValue`. `AbstractPaginator` takes the existing templates and needs no
suppression. Add no cast, runtime branch, or global PHPStan ignore.

### 8. Port current runtime fixes from Laravel #60968

Avoid division by zero while preserving caller-supplied `perPage`:

```php
$this->lastPage = max((int) ceil($total / max($this->perPage, 1)), 1);
```

For `total: 4, perPage: 0`, the public values remain `perPage = 0`, `lastPage = 4`. This is the
current Laravel contract; do not invent shared per-page normalization.

Normalize cursor page keys after forward/previous handling:

```php
if (! is_null($this->cursor) && $this->cursor->pointsToPreviousItems()) {
    $this->items = $this->items->reverse();
}

$this->items = $this->items->values();
```

Test keyed forward and previous collections. The unconditional `values()` cost was measured at
roughly 0.2–0.5 microseconds for normal page sizes; it is the only recurring added work and is
required by the public indexed-item invariant. Reject a conditional reindex branch.

### 9. Restore builder and relation cursor contracts

Both relation families accept the same cursor forms as the forwarded builder:

```php
public function cursorPaginate(
    ?int $perPage = null,
    array $columns = ['*'],
    string $cursorName = 'cursor',
    Cursor|string|null $cursor = null,
): mixed;
```

Keep Laravel's native `mixed` return surface on all six relation pagination methods, with precise
generic PHPDocs and max-level type assertions. Import `Cursor` in each relation.

Restore the concrete Eloquent result by correcting the owning trait:

```php
protected function paginateUsingCursor(
    int $perPage,
    array|string $columns = ['*'],
    string $cursorName = 'cursor',
    Cursor|string|null $cursor = null,
): CursorPaginator;
```

Drop its redundant return PHPDoc and do not add generics to the Query/Eloquent shared trait.
Add current Laravel's local generic return PHPDocs to all three public Eloquent Builder methods:
`LengthAwarePaginator<int, TModel>`, `Paginator<int, TModel>`, and
`CursorPaginator<int, TModel>`. `Eloquent\Builder::cursorPaginate()` returns concrete
`Hypervel\Pagination\CursorPaginator`; Query Builder keeps the three existing bare public
paginator results. Make `Query\Builder::cursorPaginate()`'s `$perPage` an `int`: the shared
callee already requires one, explicit null currently fatals inside the trait, and no caller uses
null. Pin that native contract with the repository's bounded reflection-test pattern because
level-five source analysis does not detect a nullable-to-non-nullable hop; do not couple the test
to PHP's TypeError wording.

Type assertions pin both relation families, all three Eloquent and Query Builder results,
`Model::query()->cursorPaginate()->toJson()`, the two corrected `ArrayIterator` results, and
current Laravel's four Paginator/CursorPaginator fragment results. Leave Scout's four results
bare: raw variants contain engine payloads, model variants depend on non-generic engine
contracts, and current upstream Scout deliberately retains the same surface.

### 10. Make worker-static reset exhaustive but explicit

Define protected defaults and reuse them for initialization, `useTailwind()`, and `flushState()`:

```php
protected const DEFAULT_VIEW = 'pagination::tailwind';
protected const DEFAULT_SIMPLE_VIEW = 'pagination::simple-tailwind';
```

Add one bounded reflection regression for the six `AbstractPaginator` slots and one
`AbstractCursorPaginator` slot. Mutate through public APIs, flush both classes, and assert the
exact defaults. Production reset remains explicit; add no registry or generic reset framework.

### 11. Record real public differences and complete package metadata

Keep Bootstrap selector methods/views removed. Current Laravel tests have no matching selectors,
so add no synthetic `REMOVED:` test marker. Add one source comment at the natural insertion point
after `useTailwind()` and a README `Differences From Laravel` entry explaining that Hypervel ships
Tailwind views only and that `Paginator::defaultView()` / `defaultSimpleView()` configure custom
views.

Keep `LengthAwarePaginator`'s Hypervel-owned `current_page_url` JSON field and document it as a
public difference. The Boost guide already demonstrates the field; do not duplicate usage prose.

Final README order:

1. package header/badge;
2. `Documentation: https://hypervel.org/docs/pagination`;
3. approved `Differences From Laravel`;
4. `Ported from: https://github.com/laravel/framework`.

Add `PaginationServiceProvider` to root `extra.hypervel.providers`; split metadata and
`DefaultProviders` already contain it. Add `tests/Pagination/PackageMetadataTest.php` to pin root
and split declarations.

Bring `src/database/README.md` into the repository README order: add the existing Database guide
link, retain its approved differences, and move the bare Laravel upstream line to the end. The
corrected concrete Eloquent cursor result is parity, so do not invent a difference entry for it.

Correct `CursorPaginator` option docs to `cursorName`/`parameters`, use strict `!==` in
`hasPages()`, fix the guide's article grammar, add `: void` to all 78 untyped Pagination test
methods, and type `CursorPaginatorTest::getCursor(array $params, bool $isNext = true): string`.
Do not type constructors or change already-correct helpers.

## Test plan

### Pagination unit/package tests

- `CursorTest`: malformed encoding plus scalar/array/direction payloads, bool/float parameter
  values, throwing JSON, and escape-hatch JSON flags where applicable.
- `PaginationResolverTest`: request array cursor, no-context defaults, exact one-context ownership,
  container request rebinding, view rebinding, and existing concurrent isolation.
- Abstract/concrete paginator tests: append value families, integer-key preservation, explicit
  zero, fragment/iterator assertions, JSON exceptions, keyed cursor normalization, zero per-page,
  strict `hasPages()`, and all seven static reset slots.
- `UrlWindowTest` keeps existing runtime behavior covered; the `src` analysis gate pins the local
  intersection.
- `PackageMetadataTest`: root/split provider discovery.

### Cross-package tests

- Existing SQLite `EloquentCursorPaginateTest`: float second page.
- Query/Eloquent producer tests: explicit page zero with a conflicting resolver. Scout producer
  tests: zero and negative pages through the raw-engine path, plus null resolver behavior.
- Relation runtime/type fixtures: `Cursor` object inputs and six relation return types.
- Eloquent/Query Builder type fixtures: three result surfaces each, concrete cursor JSON access,
  and the non-null Query cursor per-page reflection contract.
- Collections, Support, Sanctum, and API Client owner tests: invalid UTF-8, successful output,
  caller option precedence, and delegated `toPrettyJson()` exceptions where the owner exposes it.
- Run max-level type fixtures for Pagination, Routing, Support Lottery, Eloquent Builder, Query
  Builder, and both relation families.

### Verification cadence

Edit and test one file/class at a time. Run changed test files immediately, then affected focused
package/type suites. At the implementation checkpoint run `composer fix` once. After self-review
and review corrections, rerun focused tests and repeat `composer fix` only if warranted.

## Performance and compatibility budget

- Removed work: four request resolvers lose one context check and one container/request
  resolution per call.
- Unchanged work: URL serialization and every JSON boundary remain one native pass; view factory
  resolution remains lazy; its resolver adds one negligible return-type verification before the
  existing `viewFactory(): Factory` verification; no locks, yields, retries, registries, scoped
  services, or retained request objects are added.
- Added recurring work: one tiny `Collection::values()` normalization per cursor page, measured in
  sub-microsecond range at representative sizes, plus one integer `max()` per Scout pagination
  call. Both enforce public invariants at their lowest shared boundary.
- Cold/error-only checks: malformed cursor envelope validation, JSON exception construction, and
  static reset tests do not affect valid hot paths materially.
- Public API changes are widenings or truthful types, except invalid page handling. That approved
  correction eliminates ambient resolver capture for explicit zero and normalizes invalid Scout
  pages before third-party engine dispatch.
- Query Builder's cursor per-page type is narrowed from nullable to its already-required `int`;
  explicit null already throws, so valid calls and runtime work are unchanged.

## Rejected concerns

- No scoped paginator service, resolver registry, per-request registration, lock, cleanup hook,
  cursor signing/encryption, strict base64 mode, recursive payload validation, global JSON helper,
  JSON domain exception, or generic static-reset framework.
- Do not restore Bootstrap, narrow nullable path contracts, validate speculative per-page or
  `onEachSide` inputs, add `getCursorName()` to contracts, eagerly capture the view factory, or
  rewrite small Collection pipelines as loops.
- Do not change `call_user_func()` to direct closure invocation: a microprobe found only benchmark
  noise and current Laravel retains the existing shape.
- Do not add a native `onEachSide` interface property, `@property-read`, duplicate getter, concrete
  `UrlWindow` dependency, or broad suppression. The local intersection is the smallest complete
  fix and preserves Laravel compatibility and Mockery interface mocks.
- Do not make `Contracts\View\View` extend `Htmlable`, narrow `Factory::make()` or
  `viewFactory()` to concrete implementations, wrap rendered output, or add runtime type guards.
  The three local `Htmlable&View` annotations express the concrete paginator requirement without
  changing Laravel-compatible extension points or adding render-path work.
- Do not normalize all per-page zero values. Producer semantics differ deliberately; only the
  owning length-aware division boundary needs protection.
- Do not expand the API Client, Foundation, Translation, or View work beyond exact routed family
  corrections while their full/active audits retain ownership.
- Do not generify Scout's paginator results: two carry raw engine payloads, while the model paths
  would require unsupported generics across every engine contract and implementation.

## Records and completion

After implementation and review:

- add the accepted findings and important rejected alternatives to the core ledger using the IDs
  above, including the complete #59699/#60586/#60968 file inventories and current-source pin;
- record the `onEachSide` rejected alternative with all three evidence points, not merely the
  residual structural fact;
- revalidate `pagination-01`/`pagination-02` and update their dependency-index rows from “later
  full pagination audit” to complete;
- retain View's completed `ComponentAttributeBag::data()` owner record and a durable dependency
  route for Translation's `__()` conditional type;
- explicitly revalidate the completed `collections`, `support`, `database`, `routing`, and
  `scout` packages and amend their ledger entries for `collections-15`, `support-32`,
  `support-33`, `database-24`, `database-25`, `routing-25`, and `scout-41`;
- record `sanctum-02` and `api-client-01` as “targeted correction complete; later full
  `sanctum`/`api-client` audit” because those package checklists remain incomplete;
- add an explicit dependency-index row for `scout-41` with `scout` as owner and `pagination` and
  `scout` as revalidation targets, plus owner/consumer rows for the other cross-package findings;
- mark the core Pagination checklist complete; and
- leave no stale audit wording, obsolete suppression, duplicate docs, or superseded design.

Completion requires targeted tests, `composer fix`, a full caller/callee and hot-path self-review,
code review signoff, and a final records review.
