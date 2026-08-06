# Complete Routing correctness, current parity, and cache lifecycles

## Objective

Bring Routing to current Laravel behavior, fix verified URL, route-collection, middleware,
Redis-throttle, and coroutine-state defects, and preserve Hypervel's worker-lifetime route
compilation and cache advantages. The result must keep the compiled Symfony matcher and
immutable route metadata shared, isolate invocation state by route and coroutine, and add no
speculative lifecycle machinery.

Supported Laravel APIs, named arguments, protected extension points, route ordering, and
configuration remain compatible by default. The one approved intentional difference removes
Laravel's protected request-rebinder hook: Hypervel resolves requests through `RequestContext`,
and retaining the hook would preserve dead wiring whose only reachable runtime effect is a
cross-coroutine race.

## Evidence baseline

- Hypervel baseline: `219bb2d91` on `audit/routing-correctness-parity-lifecycle`.
- Laravel framework reference: local `examples/laravel/framework`, branch `13.x`, current source
  at `1a7816b370`. Historical pull requests identify the originating file sets; implementation
  comes from this current branch.
- Existing completed assumptions revalidated here: `contracts-05`, `reflection-02`,
  `container-08`, `support-02`, and `routing-01` in the core audit ledger.
- Existing `routing-01` remains the URL forwarding/signing contract completed during HTTP; new
  Routing findings begin at `routing-02`.

| Upstream change | Originating surface checked | Current result |
|---|---|---|
| Laravel #60709 | `WithoutMiddleware`, `Route`, integration test | Port the repeatable controller/method attribute and exclusions. |
| Laravel #60530 | Route, groups, registrars, resources, facade, tests | Port the complete metadata API and recursive associative merge contract. |
| Laravel #60391 | Three route-closure hydration sites and tests | Port the exact allowed-class list; apply it to Wayfinder's same-family route hydration. |
| Laravel #59778 / #59860 | URL signature validation and tests | Reject non-string `signature` and non-null non-string `expires`. |
| Laravel #59159 | `UrlGenerator::previousPath()` and tests | Parse and normalize a local path, including applications hosted below a base path. |
| Laravel #60002 | middleware-group parsing | Port the direct-cycle guard and add a bounded indirect-cycle validator without changing the protected signature. |
| Laravel #59793 | all RouteCollection domain write/read sites | Split domain routes into dedicated buckets and merge domain-first on every read/index refresh. |
| Laravel #58990 | Redis throttle handling | Honor `Limit::after()` while preserving Hypervel's atomic ordinary acquire path. |
| Laravel #60909 | `CompiledRouteCollection`, `RouteCollectionTest` | Port the complete current indexed lookup design and tests, retaining Hypervel-only matcher/port behavior. |

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

- `Router`, `UrlGenerator`, and route collections remain worker-lifetime services. Route
  registration and route-cache loading are boot-time operations.
- The compiled Symfony matcher, compiled route attributes, warmed regexes, computed controller
  middleware, and immutable lookup maps remain reusable across requests. No request flush or
  rebinding may invalidate them.
- Request and current-route selection remain coroutine-local. A route's bound parameters and
  transient controller instance are keyed by the exact framework-bound `Route` object.
- Framework dispatch retains each exact `Route` object while its coroutine-local invocation
  state may be read, and never reads those context slots after releasing the object. Public
  `CompiledRouteCollection::getRoutes()` deliberately creates non-canonical inspection objects;
  those objects are not bound by framework dispatch.
- `UrlGenerator::getRequest()` continues to prefer `RequestContext`; replacing the container's
  `'request'` binding is not a request-lifecycle mechanism in Hypervel.
- Normal Redis throttles keep one atomic acquire round trip. Only limits using `after()` need a
  nonmutating precheck before the response and a qualifying acquire after it.
- Route middleware groups and route metadata are boot-stable arrays. Validation and merge work
  stays local; no context slot, registry, lock, or worker-global traversal state is added.

## Findings and final decisions

| ID | Category | Severity | Final decision |
|---|---|---:|---|
| `routing-02` | Laravel API parity | Minor | Port `#[WithoutMiddleware]` and current inherited class/method exclusion behavior. |
| `routing-03` | Laravel API parity | Minor | Port route metadata across routes, action arrays, groups, resources, singleton resources, pending registrations, facade metadata, tests, and user docs. |
| `routing-04` | Closure hydration defect | Major | Restrict Routing's three serialized route-closure hydration sites and Wayfinder's sibling site to Laravel's exact five allowed classes. |
| `routing-05` | Signed URL defect | Major | Fail closed for invalid `signature` and `expires` shapes; the public expiry check currently accepts an array. |
| `routing-06` | URL path/security defect | Major | Make `previousPath()` return a normalized local path rather than an attacker-controlled absolute referrer. |
| `routing-07` | Middleware liveness defect | Major | Preserve Laravel's direct-cycle guard and reject indirect active-path cycles before recursive parsing. |
| `routing-08` | Route collection defect/performance | Major | Split domain buckets, permit duplicate replacement, and merge them at all five read/index-refresh sites. |
| `routing-09` | Redis throttle defect | Major | Honor `Limit::after()` without replacing the ordinary atomic acquire path. |
| `routing-10` | Coroutine/state-lifetime defect | Major | Bind `ThrottleRequestsWithRedis` transiently so its per-attempt maps cannot race or grow for the worker lifetime. |
| `redis-24` | Redis limiter defect/upstream defect | Major | Correct the fresh/expired `tooManyAttempts()` Lua tuple to `(decaysAt, remaining)`, clamp over-limit remaining counts to zero, and recommend—but do not open—an upstream Laravel correction. |
| `routing-11` | Coroutine route-identity defect | Major | Key parameters, original parameters, and transient controllers by `spl_object_id($route)` within coroutine context. |
| `routing-12` | Configuration/provider defect | Major | Use typed owning config boundaries, require complete config after merge opt-out, and remove Laravel's unsafe dead request rebinder. |
| `routing-13` | Split metadata defect | Minor | Declare the seven direct runtime dependencies absent from the Routing split package. |
| `routing-14` | Hot-path cache defect | Major | Make `Route::setContainer()` a no-op for the identical container so dispatch caches survive ordinary matching, while a different container clears both computed and resolved middleware. |
| `routing-15` | Worker-lifetime API footgun | Major | Add concise boot/test warnings to the verified worker-state mutators only. |
| `routing-16` | Laravel API parity defect | Minor | Make `ResourceRegistrar::verbs()` return `null` when setting and an array when reading. |
| `routing-17` | Approved intentional difference | Minor | Remove protected `requestRebinder()` and document coroutine-local request ownership. |
| `routing-18` | Current parity/cached-route performance defect | Major | Port Laravel #60909's indexed compiled lookups, including correct no-argument `get()`. |
| `routing-19` | Documentation/provenance | Minor | Add the canonical Routing guide and upstream provenance; document only the approved request-rebinding difference. |
| `routing-20` | Static type-contract defect | Minor | Declare all 15 magic fluent `RouteRegistrar` attributes as returning `$this`, including Hypervel's supported `port(int)` attribute. |
| `routing-21` | Route-key coercion defect | Major | Preserve numeric-string route names across uncached lookups, compiled indexes, alternate-verb scans, and server warmup, and expose truthful numeric-string URI map contracts. |
| `routing-22` | Middleware truthiness defect | Major | Preserve falsy middleware names in controller attributes and `:0` parameters while expanding middleware groups. |
| `routing-23` | URL base-path defect | Major | Strip only complete base-path segments in `previousPath()` and encode plus quote request bases before relative route removal. |
| `collections-14` | Collection type-contract defect | Minor | Declare the existing multi-group `groupBy()` callback contract once on `Enumerable` and inherit it in both implementations. |

The rejected `APP_FORCE_HTTPS` rename receives no finding. `FORCE_HTTPS` is deliberate and
unambiguous; Routing has no established environment prefix, and renaming it provides no concrete
benefit. The repository rule is clarified without prescribing names from aggregate config files:

```text
Ported config keeps upstream names. New Hypervel-specific settings should use the established
prefix for the package or subsystem that owns the value (`SERVER_`, `CACHE_`, `REDIS_`, etc.).
Determine ownership semantically, not from the config filename: aggregate files such as
`app.php` contain multiple domains, and `APP_` is for genuinely application-wide settings. If a
value mirrors another config key, reuse that key's environment variable instead of defining a
duplicate.
```

## Implementation

### 1. Preserve hot caches before adding middleware validation

`Router::findRoute()` installs the same container on every matched collection-owned route.
Return early before clearing dispatcher, controller, middleware, and controller-lifetime caches:

```php
public function setContainer(Container $container): static
{
    if ($this->container === $container) {
        return $this;
    }

    $this->container = $container;
    // Existing complete invalidation for a genuinely different container.

    return $this;
}
```

This does not change or flush the compiled Symfony matcher. It stops repeated middleware name
resolution and repeated dispatcher/controller cache misses on every normal request. Keep the
existing different-container counterfactual and add identity-retention/resolution-count tests.
A genuinely different container clears both the resolved middleware and the controller-derived
computed middleware that feeds it.

After that, keep the protected three-argument parser and recursive `static::` dispatch. Port
Laravel's direct guard verbatim inside it. Call the private validator only inside public
`resolve()`'s existing group branch, immediately before parsing:

```php
if (isset($middlewareGroups[$name])) {
    self::validateMiddlewareGroup($name, $middlewareGroups);

    return static::parseMiddlewareGroup($name, $map, $middlewareGroups);
}
```

The parser retains Laravel's direct guard:

```php
if ($name === $middleware) {
    throw new LogicException("[$name] middleware group is referencing itself.");
}
```

When rebuilding middleware strings inside a group, test the parsed parameter for `null`, matching
the direct resolver. A string parameter of `"0"` is valid and must retain its colon; a truthiness
test silently changes `throttle:0` into the middleware's default limit.

The validator tracks an active path by value, rejects only indirect cycles with the complete
cycle in its message, and lets a repeated group equal to its immediate predecessor reach the
parser's direct guard:

```php
private static function validateMiddlewareGroup(
    string $name,
    array $middlewareGroups,
    array $activePath = [],
): void {
    $activePath[] = $name;

    foreach ($middlewareGroups[$name] as $middleware) {
        if (! isset($middlewareGroups[$middleware]) || $middleware === $name) {
            continue;
        }

        if (($start = array_search($middleware, $activePath, true)) !== false) {
            $cycle = [...array_slice($activePath, $start), $middleware];

            throw new LogicException('Middleware group cycle detected: [' . implode(' -> ', $cycle) . '].');
        }

        self::validateMiddlewareGroup($middleware, $middlewareGroups, $activePath);
    }
}
```

Sibling reuse is valid. The validator checks the supplied graph; a subclass that implements a
different traversal owns that behavior. Add no fourth protected parameter, static path, context
state, graph cache, or generic cycle framework.

### 2. Port current route declaration APIs

Copy current `Attributes/Controllers/WithoutMiddleware.php`, preserving its repeatable class and
method target and `only` / `except` filtering. Integrate it alongside the existing controller
middleware attribute reflection in `Route`.

Port current metadata ownership and upstream order:

```php
public static function mergeMetadata(array $old, array $new): array
{
    foreach ($new as $key => $value) {
        if (isset($old[$key]) && static::mergableMetadata($old[$key], $value)) {
            $value = static::mergeMetadata($old[$key], $value);
        }

        $old[$key] = $value;
    }

    return $old;
}

protected static function mergableMetadata(mixed $old, mixed $new): bool
{
    return is_array($old) && is_array($new)
        && Arr::isAssoc($old) && Arr::isAssoc($new);
}
```

Associative arrays merge recursively; lists and empty arrays replace. Wire direct routes, groups,
array group syntax, resources, API resources, singleton/API singleton resources, pending
registrations, `Route::metadata()/getMetadata()/setMetadata()`, registrar attributes, facade
metadata, cached-route serialization, and the Routing guide. Metadata remains in the route action
array; add no DTO, registry, schema, or render integration. Declare all 15 magic fluent
`RouteRegistrar` attributes with the precise `@method $this` return form, including Hypervel's
`port(int $port)` attribute. This lets the shared constraint trait preserve its `static` return
contract without wrappers or suppression. Regenerate facade documentation through the repository's
facade documenter.

In `ResourceRegistrar::getResourceAction()`, the fresh local action cannot already contain
metadata. Pass `[]` directly as the old value to `mergeMetadata()` and retain a short WHY comment:
the otherwise identity merge keeps the resource option on the array-typed metadata boundary;
direct assignment would store malformed metadata that `getMetadata()` can silently return or
mask. Do not change the five genuine accumulation sites or add validation to raw action arrays.

Align resource verb setter/getter behavior:

```php
public static function verbs(array $verbs = []): ?array
{
    if ($verbs === []) {
        return static::$verbs;
    }

    static::$verbs = array_merge(static::$verbs, $verbs);

    return null;
}
```

This matches Laravel and the sibling `resourceParameters()` setter convention.

When `RouteRegistrar::compileAction()` receives both registrar metadata and action-array
metadata, recursively merge them before the ordinary action merge and restore the merged result
afterwards. This preserves unrelated registrar keys and nested associative values while keeping
action metadata authoritative.

Both controller-attribute collectors use `null` as the sole marker for an attribute excluded by
`only` / `except`. Filter with an exact null predicate rather than a bare collection filter so
string middleware names such as `"0"` and `""` match the direct route and `HasMiddleware` paths.
Recommend the same two attribute-filter and middleware-group parameter corrections upstream
without opening an issue or pull request.

### 3. Harden only serialized route-action hydration

At `Route::runCallable()`, `Route::getMissing()`,
`RouteSignatureParameters::fromAction()`, and `Wayfinder\Route::closure()`, use the exact current
Laravel five-class allowlist:

```php
unserialize($serialized, ['allowed_classes' => [
    SerializableClosure::class,
    UnsignedSerializableClosure::class,
    Native::class,
    Signed::class,
    SelfReference::class,
]]);
```

Keep every surrounding validation and error contract. This is deserialization hardening, not a
claim that executable route-cache PHP is an untrusted boundary. Do not add recursive preflight,
a global unserialize policy, or a wrapper abstraction. Add exact allowed/disallowed regressions at
each owning public behavior; add a focused Wayfinder test rather than hiding the sibling fix in a
Routing-only fixture.

### 4. Correct signed URLs and previous paths

Reject unsupported query shapes at their public owners:

```php
$signature = $request->query('signature');

if (! is_string($signature)) {
    return false;
}

$expires = $request->query('expires');

if ($expires !== null && ! is_string($expires)) {
    return false;
}
```

Test both full signature validation and direct `signatureHasNotExpired()`. Keep valid string
timestamps and signatures unchanged.

Port current `previousPath()` parsing:

```php
$previousPath = parse_url($this->previous($fallback), PHP_URL_PATH);

if (! is_string($previousPath) || $previousPath === '') {
    return '/';
}

$basePath = parse_url($this->to('/'), PHP_URL_PATH) ?: '';
$previousPath = $basePath !== '/'
    ? preg_replace('#^' . preg_quote($basePath, '#') . '(?=/|$)#', '', $previousPath)
    : $previousPath;

return rtrim($previousPath, '/') ?: '/';
```

Cover an off-site referrer becoming a local path, query removal, root/fallback, an application
base path, and a path that merely shares the base prefix. Do not add a second URL parser or host
policy.

Relative named-route generation removes the request base after the URI has already been encoded.
Normalize that base through the existing encoding map, then quote it before the existing
case-insensitive regex:

```php
if ($base = $this->url->getRequest()->getBaseUrl()) {
    $encodedBase = strtr(rawurlencode($base), $this->dontEncode);

    $uri = preg_replace('#^' . preg_quote($encodedBase, '#') . '#i', '', $uri);
}
```

One regression with parentheses pins the encoding and one with a literal plus pins regex quoting;
each half is independently required. Do not move stripping before URI encoding, replace the
case-insensitive regex, or add an unproven segment guard at this site. Recommend both current
Laravel corrections without opening an issue or pull request.

### 5. Make route collections replaceable and compiled lookups indexed

Split `RouteCollection` into domain and non-domain buckets. Write with normal assignment so the
latest route replaces the same method/domain/URI key. Merge domain-first at all five read sites:

```php
return ($this->domainRoutes[$method] ?? []) + ($this->routes[$method] ?? []);

return array_values($this->allDomainRoutes + $this->allRoutes);
```

Use the flat `allDomainRoutes + allRoutes` union in `refreshNameLookups()` and
`refreshActionLookups()`. Build the nested method map per method so non-domain routes are not
dropped when the same method also has domain routes:

```php
$result = $this->domainRoutes;

foreach ($this->routes as $method => $routes) {
    $result[$method] = ($result[$method] ?? []) + $routes;
}

return $result;
```

Pin duplicate replacement, domain-first matching, mixed domain/non-domain method maps, and
named/action domain routes after lookup refresh. Preserve Hypervel's existing rejection of
same-path routes on different ports by checking the applicable bucket before assignment. Do not
half-port only `get()`. Treat every non-null, non-empty route name as named in both lookup-write
paths; PHP's falsy `"0"` is a supported name, while an empty name retains the existing generated-name
behavior.

Then port the complete current `CompiledRouteCollection` indexing design from Laravel #60909:

```php
protected array $nameCache = [];
protected ?array $routeNamesByMethod = null;
protected ?array $routeNameByAction = null;

public function get(?string $method = null): array
{
    if ($method === null) {
        return $this->getRoutes();
    }

    $routes = (new Collection($this->routeNamesByMethod()[$method] ?? []))
        ->mapWithKeys(function (string $name): array {
            $route = $this->getByName($name);

            return [$route->getDomain() . $route->uri => $route];
        })->all();

    return $this->routes->get($method) + $routes;
}
```

`getByAction()` resolves an indexed name through `getByName()`, preserving canonical identity and
first-registered action selection. `getRoutesByMethod()` enumerates methods from both cached and
dynamic collections and builds each map from name indexes instead of materializing the whole
route table. Rename the existing three `cachedRoutesByName` references to upstream `nameCache`,
including only the `AbstractRouteCollection` prose reference; `getWarmableRoutes()` needs no
other logic change. PHP coerces canonical numeric-string array keys to integers, so cast every
attribute key back to its string route name at the three key-reading boundaries: the method-name
index, the action-name index, and `getWarmableRoutes()`. Use an exact null test in `getByAction()`.
Correct the public `getRoutesByName()` contract on the interface and both implementations to
`array<array-key, Route>` because PHP cannot retain a numeric-string array key. Do not widen
consumer closures, reject numeric names, change the route-cache format, or add a normalization
helper. Recommend the shared Laravel `getByAction()` null-test correction without opening an
upstream issue or pull request. Do not memoize `getRoutes()` or retain the entire materialized
route table.

The same coercion applies to numeric-string URI keys. Declare the inner map returned by
`getRoutesByMethod()` as `array<array-key, Route>` on the interface and both implementations, and
accept `array<array-key, Route>` in `AbstractRouteCollection::matchAgainstRoutes()`.

Preserve Hypervel's port conflict check in `add()`, port-aware matcher, RequestContext handling,
`getWarmableRoutes()`, and needed static-analysis narrowing. Port the current test matrix for
no-argument/method/action lookup, first duplicate action, routes by method, dynamic precedence,
method-not-allowed, and not-found. Add `assertSame()` between action/name lookup and revalidate
Auth's `RedirectIfAuthenticated` consumer. Correct `RouteCollectionInterface::get()` to
`@return array<array-key, Route>` and remove the consumer's now-unnecessary `isset.offset`
suppression rather than retaining an ignore over a false contract.

The indexed lookup exposes a Support PHPDoc defect: `groupBy()` already accepts an array of group
keys from one callback, but its declared callback type accepts only one key. Make `Enumerable` the
single authoritative generic contract, include `TGroupKey|array<array-key, TGroupKey>`, and remove
the duplicated generic tags from `Collection` and `LazyCollection` so they inherit it while
retaining their Laravel-style title docblocks. Remove their local ignores first and restore only
identifiers that PHPStan proves necessary. Add one assertion to the existing collection types
fixture; runtime multi-group behavior is already covered.

### 6. Isolate route-bound invocation state by route identity

Replace fixed parameter/original/controller context slots with small private key helpers:

```php
private const PARAMS_CONTEXT_KEY_PREFIX = '__routing.parameters.';
private const ORIGINAL_PARAMS_CONTEXT_KEY_PREFIX = '__routing.original_parameters.';
private const CONTROLLER_CONTEXT_KEY_PREFIX = '__routing.controller.';

private function parametersContextKey(): string
{
    // Framework dispatch never reads a route's Context slots after releasing that route.
    return self::PARAMS_CONTEXT_KEY_PREFIX . spl_object_id($this);
}
```

Use analogous helpers for original parameters and transient controllers. `flushController()`
forgets only this route's coroutine-local controller slot, then retains its existing
class-scoped `$this->container?->forgetInstance($class)` call so the next resolution creates a
fresh controller. The lifetime comment is load-bearing: object IDs may be reused after
destruction, but this cannot alias live invocation state because framework dispatch never reads a
route's context slots after releasing the exact route object. `CompiledRouteCollection::getRoutes()`
remains a deliberate source of non-canonical, non-dispatched inspection objects.

This differs correctly from the package's worker-static `WeakMap` reflection caches: those accept
arbitrary ephemeral callables across requests, while these values exist only in one coroutine and
belong to retained route objects. Do not add a route stack, registry, global allocator, or WeakMap.
Regression coverage must include nested `respondWithRoute()` and show that `Router::current()`,
the Route container binding, outer parameters, inner parameters, originals, and sibling routes do
not alias.

### 7. Correct Redis throttling ownership and protocol

First fix `DurationLimiter::tooManyAttemptsLuaScript()` fresh and expired branches:

```lua
if redis.call('EXISTS', KEYS[1]) == 0 then
    return {ARGV[2] + ARGV[3], ARGV[4]}
end

-- Existing occupied-window tuple remains unchanged.

return {ARGV[2] + ARGV[3], ARGV[4]}
```

This returns `(decaysAt, remaining)` as PHP expects. Add real-Redis fresh-key and expired-key
assertions, including `decaysAt` near now-plus-decay and exact `remaining === maxLocks`. Record
that current Laravel shares this defect and recommend an upstream fix without opening an issue or
PR absent separate authorization.

Match `acquire()` by clamping the public remaining count returned by `tooManyAttempts()`:

```php
$this->remaining = max(0, (int) $results[1]);
```

An occupied window can contain a count above its maximum after rejected acquire attempts. The
boolean result is unchanged, while headers and nested-limit comparisons no longer receive a
negative remaining value. Pin fresh and expired tuple order, occupied-window nonmutation,
over-limit clamping, and the acquire decay timestamp with realistic limiter-owned timestamp
shapes.

For `ThrottleRequestsWithRedis`, preserve `acquire()` for ordinary limits. For an after-based
limit, call the nonmutating `tooManyAttempts()` before `$next`; only when its callback accepts the
response call `acquire()` afterwards. Reuse the captured limiter metadata for headers/exceptions.
An excluded response costs one Redis precheck; a qualifying response costs precheck plus acquire.
That extra round trip is required because response status is unknown before the downstream call.
Do not reserve/rollback, use a transaction, or add cross-key atomic machinery.

Register the mutable middleware transiently:

```php
$this->app->bind(ThrottleRequestsWithRedis::class);
```

Keep its public maps and protected extension methods for Laravel compatibility. One middleware
allocation per pipeline resolution replaces an unbounded worker map and cross-coroutine writes;
Redis I/O remains the dominant cost.

### 8. Align provider and configuration ownership

Use typed required configuration and normal container resolution:

```php
return new UrlGenerator(
    $routes,
    $app->make('request'),
    $app->make('config')->get('app.asset_url'),
);

if ($app->make('config')->boolean('app.force_https')) {
    $url->forceHttps();
}

return [
    $config->string('app.key'),
    ...$config->array('app.previous_keys'),
];

$config = $app->make('config');
$appConfig = $config->array('app');

return (new Encrypter(
    $this->parseKey($appConfig),
    $config->string('app.cipher'),
))->previousKeys(array_map(
    fn (#[SensitiveParameter] string $key) => $this->parseKey(['key' => $key]),
    $config->array('app.previous_keys')
));
```

Remove the request rebinding registration, protected `requestRebinder()`, and now-unused
`Closure` import. Keep the live routes rebinder. Add a concise source comment at the natural
omission point and README difference: rebinding the container's request does not update the URL
generator because requests resolve coroutine-locally. Do not retain a no-op or dead protected
compatibility method.

Use `make()` instead of provider array access, including bound/made session handling and the
nullable asset URL. In Encryption's encrypter singleton closure, retain the complete `app` array
for `parseKey()`, read `app.cipher` and `app.previous_keys` through typed getters, and let `key()`
coalesce an absent key to `null` so its documented `MissingAppKeyException` owns both absent and
empty keys. Applications using `dontMergeFrameworkConfiguration()` own a complete config file and
now receive named failures for every required setting instead of an undefined-key error or silent
loss of previous keys. Add separate counterfactual regressions for absent `key`, `cipher`, and
`previous_keys`; retain and tighten the present-null key regression; do not duplicate Config's
wrong-type contract tests here. The typed reads run only when the encrypter singleton is first
resolved and add no request-path work. Applications likewise receive a named configuration failure
during URL-generator resolution if the Hypervel-specific `force_https` key is absent. Add
merge-opt-out coverage for required Routing keys, keep key-rotation coverage, and revalidate
Encryption.

### 9. Complete lifecycle warnings, split metadata, and package docs

Add standard warnings only where the verified worker-lifetime state requires them:

- `UrlGenerator`: `formatHostUsing`, `formatPathUsing`, `setRoutes`, `setSessionResolver`,
  `setKeyResolver`, `resolveMissingNamedRoutesUsing`, `setRootControllerNamespace`;
- `Router`: `substituteImplicitBindingsUsing`, `removeMiddlewareFromGroup`,
  `resourceParameters`, `resourceVerbs`, `setRoutes`, `matched`;
- `Route::flushController()`: `Boot or tests only.` because it clears route caches and container
  singleton/auto-singleton/scoped state.

Each warning names the concrete worker-wide effect. Do not warn ordinary fluent registration or
request-local APIs and do not add runtime guards.

Declare only the proven Routing split dependencies:

```json
{
  "require": {
    "ext-filter": "*",
    "ext-hash": "*",
    "hypervel/auth": "^0.4",
    "hypervel/prompts": "^0.4",
    "hypervel/redis": "^0.4",
    "laravel/serializable-closure": "^2.0.10",
    "psr/http-message": "^2.0"
  }
}
```

Merge these into the existing manifest without removing valid dependencies. Pin exact entries in
a package metadata regression; do not add a generic dependency scanner.

In `src/routing/README.md`, keep the package format: header, existing DeepWiki badge, canonical
`https://hypervel.org/docs/routing` link, the approved request-rebinding difference, and
`Ported from: https://github.com/laravel/framework` last. Do not inventory ordinary bug fixes or
internal cache design. Document public route metadata in `src/boost/docs/routing.md` and port the
current `#[WithoutMiddleware]` guidance to `src/boost/docs/controllers.md`.

## File and record scope

The `AGENTS.md` environment-variable rule quoted above is already applied, but uncommitted, in
this worktree. Do not apply it a second time.

Expected source/config/docs owners include:

- `AGENTS.md`;
- `src/routing/{composer.json,README.md}` and the affected files under `src/routing/src/` named
  above;
- `src/auth/src/Middleware/RedirectIfAuthenticated.php`;
- `src/collections/src/{Enumerable,Collection,LazyCollection}.php` and the focused collection
  types fixture;
- `src/redis/src/Limiters/DurationLimiter.php`;
- `src/wayfinder/src/Route.php`;
- `src/encryption/src/EncryptionServiceProvider.php`;
- `src/support/src/Facades/Route.php` through facade generation;
- `src/boost/docs/controllers.md` and `src/boost/docs/routing.md`;
- focused Routing, Redis integration, Wayfinder, Encryption, Auth, metadata, and split-package
  tests;
- the core audit plan/ledger routing, dependency, checklist, implementation, and validation
  records after implementation and review.

Record the work under the ledger heading **Complete Routing correctness, current parity, and cache
lifecycles**. Add these exact cross-package dependency-index rows and matching bidirectional
ledger references:

| Finding | Owning package | Affected / revalidation packages | Ledger entry |
|---|---|---|---|
| `routing-04` | `routing` | `wayfinder` (targeted correction complete); later full `wayfinder` audit | `Complete Routing correctness, current parity, and cache lifecycles`; finding `routing-04` |
| `redis-24` | `redis` | `redis` and `routing` (revalidation complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `redis-24` |
| `routing-12` | `routing` | `encryption` (targeted correction complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `routing-12` |
| `routing-18` | `routing` | `auth` (revalidation complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `routing-18` |
| `collections-14` | `collections` | `collections` and `routing` (revalidation complete) | `Complete Routing correctness, current parity, and cache lifecycles`; finding `collections-14` |

Do not force an exhaustive predeclared file list. If a changed upstream method has a current
consumer, fixture, facade, contract, or test outside this list, update that owner in the same
coherent slice.

## Validation

Run each changed test file immediately. The focused matrix must cover:

1. controller/method/inherited `WithoutMiddleware` exclusions;
2. every metadata registration path, recursive associative merging, list/empty replacement,
   action-key noncollision, cached-route preservation, and facade metadata;
3. allowed and disallowed serialized route closures at all four hydration owners;
4. malformed signed query shapes and valid signatures/expiry strings;
5. off-site/base-path/root/fallback `previousPath()` behavior;
6. direct parser self-reference, indirect complete-cycle diagnostics, nested direct cycles,
   valid sibling reuse, and subclass override participation;
7. duplicate domain replacement and all five domain read/refresh surfaces;
8. compiled no-argument/method/action/method-map/dynamic/exception behavior and action/name
   identity;
9. nested route dispatch, sibling routes, parameter/original/controller isolation, and cleanup;
10. Redis fresh/occupied/expired limiter tuples, ordinary atomic throttles, qualifying/excluded
    `after()` responses, headers, and middleware instance isolation;
11. identical-container cache retention and different-container invalidation;
12. merged and merge-disabled configuration, key rotation, coroutine-local request behavior,
    lifecycle docs, resource verbs, exact split metadata, and the multi-group Collection type
    contract;
13. the complete magic fluent `RouteRegistrar` surface through source-level static analysis.
14. uncached and compiled numeric-string route names across name/action/method indexes, warmup,
    refresh, and attacker-reachable alternate-verb handling.
15. falsy middleware names through inclusion/exclusion attributes and `:0` parameters through
    middleware-group expansion.
16. previous-path base-segment boundaries and relative route generation under encoded and
    regex-significant request base URLs.
17. registrar metadata merged with action-array metadata.

Use `InteractsWithRedis` for all real Redis tests. Then run focused Routing, Redis, Wayfinder,
Encryption, Auth, Support/facade, and integration groups, followed once at the end by
`composer fix`. Run `git diff --check`, stale-symbol scans (`requestRebinder`,
`cachedRoutesByName`, fixed context keys), facade documentation checks, package metadata
validation, and package-checklist parity.

## Performance and compatibility result

- Ordinary route matching retains the compiled Symfony matcher and now preserves middleware,
  dispatcher, controller-lifetime, and controller caches for the identical container.
- Domain registration becomes assignment-based O(1) work. Compiled method/action lookup no
  longer reconstructs the full cached route table. Today every compiled-route 404 checks roughly
  six alternate verbs, rebuilding the complete route table for each check; the indexed lookup
  removes those repeated attacker-reachable rebuilds. The authenticated guest redirect consumer
  gains the same improvement on its GET-route lookup.
- No route table is newly materialized or retained for the worker lifetime. Lookup maps contain
  only method/name/action strings and are built lazily.
- Middleware cycle validation becomes a boot/first-resolution operation once same-container cache
  retention lands. It allocates only a small active-path array and adds no runtime state. It has
  no visited set, so pathological deep diamond graphs repeat path work; real middleware graphs are
  small and shallow, and memoization would add speculative machinery to a once-per-group worker
  path.
- Normal Redis throttles retain one round trip. Only documented `after()` semantics add the one
  unavoidable qualifying-response acquire described above.
- The transient Redis middleware adds one small allocation per resolution and removes unbounded
  retained request-key maps and cross-coroutine races.
- Other accepted paths add bounded type checks, array merges, or coroutine key concatenation; no
  lock, retry, yield, poll, serializer, container loop, registry, or request-retained worker state
  is added.
- The Support `groupBy()` correction changes PHPDoc and static-analysis coverage only; runtime code
  and cost are unchanged.
- The `RouteRegistrar` fluent-type correction changes PHPDoc only; runtime dispatch, macro
  precedence, and cost are unchanged.
- Numeric route-name normalization runs only while the compiled indexes are built or warmable
  routes are enumerated; steady lookups add no cast, scan, or allocation.
- Exact middleware filtering runs only while controller attributes are resolved, and the group
  parser replaces one truthiness check with one null check; neither adds state or I/O.
- URL base removal adds one fixed regex boundary and encodes the request base once for relative
  route generation; it adds no request state, container work, I/O, or extra route lookup.
- Middleware aliases and groups are boot-time configuration. Once a route has resolved its
  middleware, changing those registries during runtime no longer takes effect on that route at
  the next dispatch merely because the same container is reinstalled. The documented explicit
  invalidation seams remain `Route::flushController()`, serialization preparation, and installing
  a genuinely different container. This converts the existing boot-only warnings from advisory
  guidance into the cache contract and is the accepted consequence of preserving middleware
  resolution across requests.
- Laravel-facing APIs remain compatible or move to current parity. Invalid input shapes fail
  closed. The only removed Laravel extension point is the explicitly approved protected request
  rebinder, with its Hypervel replacement and compatibility impact documented.

## Rejected designs

- No request-scoped Router, route collection, compiled matcher, or URL-generator clone.
- No middleware graph cache, context slot, fourth protected parser parameter, global path, or
  general cycle detector.
- No route stack, route registry, object-ID allocator, WeakMap, or full-table route memoization.
- No literal Laravel Redis check-then-hit path for ordinary limits, reservation rollback,
  multi-key transaction, or cross-key Lua rewrite.
- No context/lock/pruning/destructor around Redis throttle maps; the correct owner is a transient
  middleware instance.
- No request rebinder no-op, dead protected compatibility method, or Precognition container
  rebinding.
- No broad route-cache invalidation, lock, generation counter, or relaxation of Hypervel's port
  conflict/matcher rules.
- No generic dependency scanner, new routing documentation page, or README inventory of fixes.
- No `APP_FORCE_HTTPS` rename or duplicate environment variable.

## Completion

After implementation, run a fresh caller/callee, route-identity, container-lifetime, Redis
protocol, compiled-cache, Laravel API, documentation, and hot-path self-review without trusting
this plan. Amend this plan in place if implementation evidence changes a design; replace obsolete
wording rather than appending decision history. Complete the focused second-opinion workflow for
unexpected findings, obtain final code-review sign-off, then update the audit ledger and checklist
with only the implemented result.
