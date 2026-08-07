# Inertia Correctness, SSR Lifecycle, and Current Parity

## Scope and outcome

Complete the accepted Inertia maintenance work before porting the separately scoped DevTools feature. Preserve the package's Laravel-style API and Hypervel's performance adaptations: coroutine-local request state, worker-cached immutable metadata, one reusable cookie-free SSR client with bounded I/O and connection reuse, worker-wide transport backoff, and configurable SSR runtimes including Node, Bun, and absolute executable paths.

The final package must inherit provider-boot configuration into each request without sharing mutable request state; preserve Inertia cache variance and version headers; publish truthful initial-page JSON; classify SSR failures without poisoning unrelated pages; validate the established SSR response shape; report command outcomes accurately; and reuse the existing page-finder cache. DevTools remains the immediately following feature-parity work unit, and the core package checklist remains unchecked until it lands.

References checked for this design:

- Hypervel Inertia source, configuration, facade, package metadata, README, Boost Vite guide, every package test, Context/Container/Console owners, and repository consumers at `de04fad613a8`;
- current `inertiajs/inertia-laravel` 3.x source/tests/configuration at `c014246529dd`, including current version-mismatch, hot URL, and DevTools surfaces;
- originating upstream changes for the version header and custom hot URL, plus the installed `@inertiajs/core` 3.5.0 health, render, and response-less shutdown endpoints;
- Symfony HttpFoundation `Vary`, streamed/binary content, Symfony Process, Hypervel `SignalRegistry`, Guzzle cURL and stream handlers, and Hypervel HTTP exception wrapping;
- the completed `support-02` enum/string-boundary decision and the core audit's checked-native-boundary rule.

Focused evidence reproduced lost non-coroutine boot configuration, immediate-success `StartSsr` tests leaking process-global PCNTL handlers, nonzero child exits reported as success, stream-handler shutdown ambiguity, null scroll callbacks executing twice, malformed SSR payloads escaping as `TypeError`, and view data overriding the authoritative Inertia page. An isolated real-process probe verified that Hypervel's coroutine-scoped `trap()` stops a Symfony child from a sibling waiter coroutine and then re-raises SIGTERM as exit 143.

## What this audit is not

The following wording is retained verbatim from the core audit plan. Its principle numbering is also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this plan” refers to that plan's **Established remediation vocabulary** section.

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

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Findings and final decisions

| ID | Category / severity | Final decision |
|---|---|---|
| `inertia-01` | Request-lifecycle defect / Major | Make `InertiaState` a replicable boot baseline and the sole request-state accessor; shallow-clone it once on first request access. |
| `inertia-02` | Protocol parity defect / Minor | Add the current asset version header to version-mismatch location responses. |
| `inertia-03` | Current parity improvement | Add `INERTIA_SSR_HOT_URL`, prefer it over the Vite hot file, and normalize both base and requested paths. |
| `inertia-04` | HTTP cache defect / Major | Append and case-insensitively deduplicate `Vary: X-Inertia` on the response actually returned. |
| `inertia-05` | Serialization defect / Major | Throw on invalid initial-page JSON instead of emitting an empty payload. |
| `inertia-06` | Performance defect / Improvement | Do not encode the full page again when SSR already supplied the rendered body. |
| `inertia-07` | Boundary correctness / Minor | Replace truthiness at the seven evidenced identifier, header, event, and helper contracts while preserving unrelated value semantics. |
| `inertia-08` | Memoization defect / Minor | Cache a null `ScrollProp` result with one explicit resolution flag. |
| `inertia-09` | Command outcome defect / Major | Make SSR start/stop commands report actual child/server outcomes through one gateway transport and handler-independent health preflight. |
| `inertia-10` | Worker-availability defect / Major | Arm worker backoff only for connection or malformed-transport failures, never page-local render failures. |
| `inertia-11` | Transport-contract defect / Major | Validate exactly `{head: array<string>, body: string}`, normalize remote error metadata, and remove the dead exception catch. |
| `inertia-12` | Approved performance improvement | Singleton-bind the opt-in page finder so its existing successful-path cache survives repeated resolutions. |
| `inertia-13` | Port modernization / Minor | Replace the provider's two container array accesses with canonical `make()` calls. |
| `inertia-14` | Documentation/conformance defect / Minor | Document public SSR operation and testing differences; correct focused prose, docblocks, and package test return types. |
| `inertia-15` | Checked-native-boundary defect / Minor | Treat a Vite hot file removed between metadata check and read as normal client-rendered fallback. |
| `inertia-16` | Response replacement defect / Major | Treat only exact empty-string content as empty; preserve `"0"`, streamed, and binary responses. |
| `inertia-17` | Container identity defect / Major | Make the concrete `HttpGateway` auto-singleton authoritative and bind `Gateway` to that instance. |
| `inertia-18` | State-owner cleanup / Minor | Put dispatch-once behavior in one `InertiaState` instance method and delete component copies and the static wrapper. |
| `inertia-19` | Render-consistency defect / Major | Reserve view key `page` for the framework-built page so directives and components cannot render different payloads. |
| `inertia-20` | Current feature parity / Separate work unit | Record current upstream DevTools as the next implementation/documentation plan; do not mark Inertia complete before it lands. |
| `inertia-21` | Process-lifecycle defect / Major | Replace raw process-global PCNTL handlers with the inherited coroutine-scoped `trap()` API. |
| `inertia-22` | Scroll ownership defect / Major | Clone each `ScrollProp` at the resolver's per-path boundary so framework resolution cannot leak results across requests or couple metadata between paths. |
| `inertia-23` | Strict-port correctness / Minor | Cast top-level array keys at the string path boundary and correct the stale string-only prop annotations so numeric props retain Laravel's weak-coercion behavior under strict types. |

The seven original `inertia-07` sites do not all have equal real-world harm. `getShared('')` is a concrete wrong public result, the helper contradicts its declared null-conditional return, protocol key `"0"` is reachable, and the remaining exact comparisons make declared nullable/string boundaries truthful. `inertia-16` is separate because `empty()` currently destroys real streamed/binary responses.

## Implementation

### 1. Publish one boot baseline and one request state

Make `InertiaState` implement `ReplicableContext`. `current()` works identically inside and outside coroutines: outside provider boot creates or retrieves the non-coroutine baseline; inside a request, first access shallow-clones that baseline into the coroutine. Fail loudly if the package-owned context key contains a foreign value; use a local PHPDoc narrowing rather than a runtime type guard.

```php
public static function current(): self
{
    if (CoroutineContext::has(self::CONTEXT_KEY)) {
        /** @var self $state */
        $state = CoroutineContext::get(self::CONTEXT_KEY);

        return $state;
    }

    // Providers configure Inertia before the server starts request coroutines,
    // so each request begins with an independent copy of that boot baseline.
    /** @var self|null $baseline */
    $baseline = CoroutineContext::getFromNonCoroutine(self::CONTEXT_KEY);

    $state = $baseline?->replicate() ?? new self;
    CoroutineContext::set(self::CONTEXT_KEY, $state);

    return $state;
}

public function replicate(): static
{
    return clone $this;
}
```

`current(): self` and `new self` are deliberate: this fixed context key is the package's one internal state type, not a late-static factory. `replicate(): static` follows the `ReplicableContext` contract and preserves the runtime type of an object already stored in context; it does not create a subclass-construction API whose instances would collide under the same key.

Shallow cloning is deliberate: package arrays use PHP copy-on-write, closures are immutable, and arbitrary caller-owned prop objects retain their normal identity. The framework-owned mutable `ScrollProp` is isolated later at its resolver boundary. Do not copy all non-coroutine context, deep-clone user data, add static factory state, or share the baseline object itself.

The worker retains one baseline `InertiaState` plus the values providers share at boot. Provider boot writes it once; request mutations apply only to clones, so request traffic cannot grow it. SSR dispatch happens only while rendering inside a request coroutine, so the baseline's page and dispatch/result slots remain empty. It needs no package `flushState()` or subscriber entry: the existing global `CoroutineContext::flush()` clears non-coroutine storage between tests. Pin that dependency in the state tests. A request-local `flushShared()` clears only its clone and must not remove boot-shared props from sibling or later requests.

Update the class docblock to describe both roles accurately: one provider-boot baseline in non-coroutine storage and an independent request-local copy in coroutine context. Do not leave it claiming the object exists only per request.

Route every state read in `ResponseFactory`, `Response`, `HttpGateway`, `App`, `Head`, and the directive path through `current()`. Replace the three dispatch-once copies with one instance owner:

```php
public function dispatchSsr(): ?SsrResponse
{
    if (! $this->ssrDispatched) {
        $this->ssrDispatched = true;
        $this->ssrResponse = app(Gateway::class)->dispatch($this->page);
    }

    return $this->ssrResponse;
}
```

Components call this method on the current state. Compiled directives explicitly assign their view-scope `$page` to the current state before dispatch because directives can render without `Response::toResponse()` ever seeding state. Do not add `setPage()` or retain a one-line static wrapper.

In `Response::toResponse()`, make the resolved page authoritative in both state and view data:

```php
$state = InertiaState::current();
$state->page = $page;

return ResponseFactory::view(
    $this->rootView,
    ['page' => $page] + $this->viewData,
);
```

This intentionally prevents `withViewData(['page' => ...])` from splitting directive JSON/SSR from component rendering without renumbering unrelated keys at this merge boundary. It changes no Laravel-documented capability: `page` is framework-owned protocol data.

### 2. Make response headers and empty-content handling exact

Port the current version header in `onVersionChange()`:

```php
$response = Inertia::location($request->fullUrl());
$response->headers->set(Header::VERSION, Inertia::getVersion());

return $response;
```

Append `X-Inertia` to `Vary` on both the non-Inertia early return and the final Inertia response, after any replacement. Use Symfony's parsed list and a case-insensitive comparison; do not parse the header manually:

```php
protected function addInertiaVaryHeader(Response $response): void
{
    foreach ($response->getVary() as $header) {
        if (strcasecmp($header, Header::INERTIA) === 0) {
            return;
        }
    }

    $response->setVary(Header::INERTIA, false);
}
```

An Inertia response is empty only when materialized content is exactly `''`:

```php
if ($response->isOk() && $response->getContent() === '') {
    $response = $this->onEmptyResponse($request, $response);
}
```

`false` from `StreamedResponse` or `BinaryFileResponse` means content is not materialized; `"0"` is legitimate content. Neither is a redirect signal.

### 3. Make initial-page serialization truthful and avoid duplicate work

Use native JSON encoding with the throwing flag in the component and compiled directive fallback:

```php
json_encode($page, JSON_THROW_ON_ERROR)
```

Retain native encoding so normal HTML bytes and flags do not change. `App` keeps its non-nullable public `$pageJson` initialized, but only encodes when no SSR response exists:

```php
$this->response = $state->dispatchSsr();
$this->pageJson = $this->response === null
    ? json_encode($state->page, JSON_THROW_ON_ERROR)
    : '';
```

Do not add a JSON wrapper, sanitizer, lazy value object, or a second rendering component.

### 4. Correct exact nullable and string boundaries

Apply only these evidenced changes:

| Owner | Exact contract |
|---|---|
| `ResponseFactory::getShared()` | `null` means all shared props; `''` and `"0"` are keys and use the supplied default when absent. |
| `inertia()` | only `null` returns the factory; every string delegates to `render()`. |
| `PropsResolver::parseHeader()` | remove only exact `''`; preserve `"0"`; return `null` when no entries remain. |
| `Middleware::resolveValidationErrors()` | treat a non-null, non-empty error-bag header—including `"0"`—as named. |
| `MergesProps::append()` / `prepend()` | accept non-null `matchOn`, including `"0"`; empty string retains the existing absence behavior. |
| `Directive::compile()` | default the root ID only for exact `''`; preserve `"0"`. |
| `SsrRenderFailed::toArray()` | filter only `null`, preserving diagnostic `"0"`. |

Representative forms:

```php
if ($key !== null) {
    return Arr::get($sharedProps, $key, $default);
}

$values = array_filter(
    explode(',', $this->request->header($key, '')),
    fn (string $value): bool => $value !== '',
);

return $values === [] ? null : $values;
```

Do not trim official comma-joined client headers or sweep unrelated falsey application values.

At the one top-level prop-path producer, normalize the path without changing the resolved array key:

```php
$path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
```

This matches the existing casts in `resolveSharedProps()` and Laravel's weak coercion. Correct the four affected `PropsResolver` return annotations to `array<array-key, mixed>`, the `InertiaState::$sharedProps` and `Response::$props` annotations to `array<array-key, mixed|ProvidesInertiaProperties>`, and type `Response::with()` as `array|ProvidesInertiaProperties|int|string`; type `withViewData()` as `array|string`. These are truthful contract corrections rather than PHPStan fixes. Use strict membership for rescued, once, reset, and redirect-method lists so numeric-looking strings cannot collide. Do not widen downstream string path contracts or other string-keyed response shapes, and do not add key validation.

### 5. Make SSR transport classification and payload validation authoritative

Preserve the worker-cached raw Guzzle client, cookies-off policy, configured connect/total timeouts, and successful connection reuse. As soon as a render request returns an HTTP response, clear prior backoff because transport reachability is proven. A malformed response then re-arms backoff; a structured page render error does not.

Validate only the established top-level success shape:

```php
protected function isValidSsrResponse(mixed $data): bool
{
    if (! is_array($data)
        || ! isset($data['head'], $data['body'])
        || ! is_array($data['head'])
        || ! is_string($data['body'])
    ) {
        return false;
    }

    foreach ($data['head'] as $head) {
        if (! is_string($head)) {
            return false;
        }
    }

    return true;
}
```

Empty `head` is valid. Invalid JSON, scalar JSON, missing keys, non-string head entries, or a non-string body enter the existing failure-event/fallback path with transport backoff. Do not require `array_is_list()`, recursively inspect application page data, add JSON Schema, or create DTO/error hierarchies.

Keep the classification explicit at the three call sites:

```php
$response = $this->ssrClient()->request(/* ... */);
self::$ssrUnavailableUntil = null;

if ($response->getStatusCode() >= 400) {
    $decoded = json_decode((string) $response->getBody(), true);
    $structured = is_array($decoded);

    if (! $structured) {
        $this->armTransportBackoff();
    }

    $this->handleSsrFailure($page, $structured ? $decoded : null);

    return null;
}

$data = json_decode((string) $response->getBody(), true);

if (! $this->isValidSsrResponse($data)) {
    $this->armTransportBackoff();
    $this->handleSsrFailure($page, ['error' => 'Invalid SSR response.']);

    return null;
}
```

Connection exceptions arm backoff before calling `handleSsrFailure()` with type `connection`; this ordering survives `throw_on_error`:

```php
} catch (TransferException $e) {
    $this->armTransportBackoff();
    $this->handleSsrFailure($page, [
        'error' => $e->getMessage(),
        'type' => 'connection',
    ]);

    return null;
}
```

Keep the protected two-parameter signature unchanged so subclasses remain compatible. Only the transport call sites may arm worker-wide backoff; a remote `type: connection` value must not control worker state. Normalize untrusted optional error fields to `string|null`:

```php
$event = new SsrRenderFailed(
    page: $page,
    error: $this->stringOrNull($error['error'] ?? null) ?? 'Unknown SSR error',
    type: SsrErrorType::fromString($this->stringOrNull($error['type'] ?? null)),
    hint: $this->stringOrNull($error['hint'] ?? null),
    browserApi: $this->stringOrNull($error['browserApi'] ?? null),
    stack: $this->stringOrNull($error['stack'] ?? null),
    sourceLocation: $this->stringOrNull($error['sourceLocation'] ?? null),
);
```

Use two private helpers rather than repeating the three call-site writes or four metadata checks:

```php
/**
 * Activate SSR transport backoff.
 */
private function armTransportBackoff(): void
{
    self::$ssrUnavailableUntil = microtime(true)
        + (float) config('inertia.ssr.backoff', 5.0);
}

/**
 * Return the value when it is a string.
 */
private function stringOrNull(mixed $value): ?string
{
    return is_string($value) ? $value : null;
}
```

Structured browser API, component-resolution, and render failures still dispatch `SsrRenderFailed`, fall back to client rendering, and throw under `throw_on_error`; they do not suppress unrelated pages. Connection and malformed-transport failures arm worker backoff. Delete the dead `catch (SsrException) { throw $e; }`; the remaining catch is already narrowed to `TransferException`.

Do not add locks, single-flight coordination, retries, a clock service, or another breaker state. Concurrent in-flight discovery is bounded and a racing response is direct reachability evidence.

### 6. Make hot URL publication checked and current

Add nullable `inertia.ssr.hot_url` / `INERTIA_SSR_HOT_URL`. A configured URL bypasses the file read. Otherwise immediately check the suppressed native result and return `null` for the established client-rendering fallback:

```php
protected function getHotUrl(string $path = '/'): ?string
{
    $baseUrl = (string) config('inertia.ssr.hot_url');

    if ($baseUrl === '') {
        $baseUrl = @file_get_contents(Vite::hotFile());

        if ($baseUrl === false) {
            return null;
        }
    }

    return rtrim(trim($baseUrl), '/') . Str::start($path, '/');
}
```

`dispatch()` returns `null` when the hot URL disappears after `Vite::isRunningHot()`. This is a development publication race, not an SSR server error; do not emit a synthetic failure event or add file synchronization.

Show that nullable boundary at the dispatch site before making the request:

```php
$url = $isHot
    ? $this->getHotUrl('/__inertia_ssr')
    : $this->getProductionUrl('/render');

if ($url === null) {
    return null;
}
```

### 7. Give the gateway and page finder one worker identity

Use the concrete auto-singleton as the one gateway instance and bind the contract through it:

```php
$this->app->singleton(
    Gateway::class,
    fn ($app) => $app->make(HttpGateway::class),
);

$this->app->singleton('inertia.view-finder', function ($app) {
    $config = $app->make('config');

    return new FileViewFinder(
        $app->make('files'),
        $config->array('inertia.pages.paths'),
        $config->array('inertia.pages.extensions'),
    );
});
```

Direct `HttpGateway::class` and `Gateway::class` resolution must return the same worker object. The view finder reuses its existing bounded successful-path cache only when page-existence enforcement resolves it; misses remain uncached, roots/extensions are boot configuration, and application/test rebinding still wins. Do not add another cache, invalidation API, watcher, or concrete alias.

Use `$this->app->make('router')` for middleware registration. Do not add container aliases or concrete bindings for static analysis.

### 8. Make SSR commands truthful and runtime-neutral

Keep `--runtime`, `INERTIA_SSR_RUNTIME`, Bun, Node, and absolute paths unchanged. Replace raw PCNTL ownership with the inherited coroutine-scoped signal registry:

```php
$this->trap([SIGINT, SIGQUIT, SIGTERM], function () use ($process): void {
    $process->stop();
});

foreach ($process as $type => $data) {
    // Existing output handling.
}

return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
```

Signals are conventionally re-raised by `SignalRegistry` and terminate as `128 + signal`; only normally completed children reach the return. Do not add an extension branch, save/restore native handlers, or special signal-success flag.

Put transport-only shutdown on concrete `HttpGateway`:

```php
public function shutdown(): bool
{
    $response = $this->ssrClient()->request(
        'GET',
        $this->getProductionUrl('/shutdown'),
    );

    return $response->getStatusCode() >= 200
        && $response->getStatusCode() < 300;
}
```

`StopSsr` owns orchestration and messages:

```php
if (! $gateway->isHealthy()) {
    $this->error('Unable to connect to Inertia SSR server.');

    return self::FAILURE;
}

try {
    if (! $gateway->shutdown()) {
        $this->error('Inertia SSR server refused to stop.');

        return self::FAILURE;
    }
} catch (TransferException) {
    // The official endpoint terminates after a verified health response
    // and closes the connection without sending a response.
}
```

The preflight is handler-independent, uses the same client and configured timeouts, and runs only in a CLI command. `isHealthy()` intentionally bypasses render backoff so a degraded server can still be stopped. Do not add cURL errno inspection, raw sockets, polling, retries, daemonization, or a shutdown capability interface.

Use `$signature` consistently on `StopSsr`. Keep `SsrException::$event` with its other member declarations.

### 9. Resolve scroll props per path and memoize null results

`ScrollProp` is the only built-in prop wrapper that mutates during resolution: it memoizes the resolved value and records request-specific merge intent. Clone it at both points where `resolveProps()` begins processing a prop path, including a prop type returned by a callable or property provider:

```php
// Shared scroll props may outlive a request, and resolution mutates them.
// Resolve each prop path through its own copy.
$prop = $value instanceof ScrollProp ? clone $value : $value;
```

Use the same assignment after the one-level prop-type unwrap. The clone must precede filtering and `resolveValue()` so deferred metadata is request-local and `collectMetadata()` observes the resolved copy. This boundary also covers nested and `ProvidesInertiaProperties`-produced scroll props without walking the shared-prop tree. Do not clone inside `resolveValue()`, deep-clone shared data, add a marker interface, or add baseline bookkeeping.

Using one `ScrollProp` object for two prop paths currently couples their merge paths. Resolving one clone per path corrects that independent defect; each logical prop performs its own callback once and contributes only its own metadata.

Add one boolean beside the existing value:

```php
protected bool $hasResolved = false;

public function __invoke(): mixed
{
    if ($this->hasResolved) {
        return $this->resolved;
    }

    $this->resolved = $this->resolveCallable($this->value);
    $this->hasResolved = true;

    return $this->resolved;
}
```

Set the flag only after successful resolution. Setting it first would silently cache the untyped property's default `null` when the callback throws, replacing the current retry behavior and potentially surfacing a misleading pagination-metadata error later. Declare the flag immediately after `$resolved`; do not add a sentinel object, reflection, clone reset, or generic memoizer.

### 10. Documentation, conformance, and durable records

Add one concise Laravel-docs-style subsection after `inertia:start-ssr` in `src/boost/docs/vite.md`, list it in the table of contents, and keep the existing starter-kit note attached to the start command. Teach:

- runtime selection with `--runtime=bun` and `INERTIA_SSR_RUNTIME`;
- `hot_url`, connect/total timeouts, and the worker-scoped connection/malformed-response backoff;
- client-rendered fallback, `throw_on_error`, and `SsrRenderFailed`;
- no internal state-machine or Guzzle implementation narrative.

Keep the package README minimal. Add only the public testing difference under `Differences From Laravel`: SSR uses a dedicated raw client, so `Http::fake()` and `Http::preventStrayRequests()` do not intercept it; tests use `HttpGateway::useTestingClient()`. Normalize the upstream reference to the standard `Ported from:` line, but do not add a documentation link: the SSR subsection belongs to the Vite guide, not a dedicated Inertia page. Do not duplicate that guide.

Correct `optimisations` to `optimizations`, “These options configures” to “These options configure”, and backoff prose so only connection/malformed transport failures suppress SSR. Add Laravel-style title docblocks to `ExceptionResponse::__construct()`, `render()`, `usingMiddleware()`, `withSharedData()`, `rootView()`, `statusCode()`, `resolveMiddleware()`, `resolveMiddlewareFromRoute()`, and `resolveMiddlewareFromKernel()`; `Inertia::getFacadeAccessor()`; the `App` and `Head` constructors; and document the transport exception from `HttpGateway::shutdown()`, while preserving useful type tags. Add `: void` to the 14 package-local methods in `ComponentTest`, three in `CoroutineIsolationTest`, and two in `BundleDetectorTest`.

Update the core ledger with `inertia-01` through `inertia-23`, implementation evidence, rejected concerns, performance/API assessment, and the separately scoped DevTools surface. The DevTools record must name implementation PRs `inertiajs/inertia-laravel#892` and follow-ups `#894`–`#897`, documentation PR `inertiajs/docs#79`, and the 19 current source files: `Collector.php`; `Data/{IncomingEntry,PropType,RequestType}.php`; `DevTools.php`; `DevToolsHeader.php`; `DevToolsServiceProvider.php`; `EntriesRepository.php`; `EntryStore.php`; `Http/{Authorize,EntriesController,PreserveFlashData,PreventPreviousUrlTracking}.php`; `IncomingEntryBuilder.php`; `PropClassifier.php`; `RedactsSensitiveData.php`; `RequestAttribute.php`; `RequestRecorder.php`; and `SourceLocator.php`. Note that `#895` landed, was reverted, then re-landed; use the current branch rather than an intermediate historical diff. Keep the core `inertia` checklist unchecked. Do not add a TODO placeholder or partial DevTools code to this plan.

## Rejected concerns and required invariants

- Do not replace the reusable SSR client with per-request `Http` facade calls; connection/handler reuse is intentional and performance-critical.
- Do not claim `Http::fake()` intercepts raw SSR requests; document the testing client seam.
- Do not clone arbitrary prop objects or copy the whole non-coroutine context.
- Do not synchronize circuit-breaker discovery or retry failed renders.
- Do not normalize arbitrary falsey application values beyond the listed owners.
- Do not port upstream's independent Guzzle metadata widening; `hypervel/http` owns that dependency.
- Do not add a real Node/Bun service to the general suite; deterministic transport/process seams cover this adapter. Runtime selection remains tested directly.
- Do not change Console's zero-argument `setSignalsToDispatchEvent()`: current Laravel deliberately uses it to prevent Symfony PCNTL signal ownership, while Hypervel commands use the coroutine-scoped registry.
- Do not mark the package complete while current upstream DevTools is absent.

No Laravel-facing method, named argument, facade, prop type, middleware contract, helper, or command option is removed. `InertiaState` is a Hypervel-internal lifecycle owner; replacing its static dispatch helper with the single instance owner is not a Laravel API change. Reserving root-view key `page` corrects conflicting internal protocol data rather than removing a documented extension point.

## Testing plan

Run each touched test file immediately, then the complete package suite. Required regression coverage:

1. **State:** boot shared props/root view/version/URL/component controls plus `ssrDisabled` and `ssrExcludedPaths` inherit into requests; sibling mutations remain isolated; request-local `flushShared()` does not clear sibling or later boot props; explicit context copies replicate; fresh requests see baseline but not completed sibling state; request-scoped state written during an HTTP request is asserted inside that request coroutine because its parent sees only the boot baseline and the harness's session/auth synchronization; component and coroutine tests read/write through `InertiaState::current()` while retaining raw `CoroutineContext::forget()` only as their test-only request-boundary reset.
2. **Middleware:** version header; preserved/deduplicated mixed-case `Vary`; ordinary and every replacement response; exact `''`, `"0"`, streamed, and binary content; named error bag `"0"`.
3. **Response/rendering:** authoritative page cannot be overridden through view data; directive/component head/body equivalence; successful SSR skips both fallback encoders even for an otherwise unencodable page; client fallback preserves exact output; one unencodable directive value and one different component value each throw `ViewException` with `JsonException` as the previous exception. Keep the SSR short-circuit in one paired component/directive test and note that its fake gateway does not encode the page.
4. **Identifiers:** null/empty/`"0"` pairs for shared keys, helper components, partial/reset/once headers, merge match keys, root IDs, and failure diagnostics; numeric top-level prop paths work under strict types while retaining their resolved key, and numeric-looking reset/once values do not collide through loose comparison.
5. **Scroll:** repeated direct null resolution invokes the callback once; a boot-shared scroll prop produces distinct sibling-request results without accumulated merge metadata; a provider-produced scroll prop follows the same boundary; and one object used at two paths resolves once per path without duplicated or crossed metadata.
6. **Gateway:** configured/default/falsey hot URL, slash normalization, removed unreadable hot file fallback without a synthetic failure event, one concrete/contract identity, valid empty head, every malformed success/error shape, remote metadata normalization, event dispatch, `throw_on_error`, render-error follow-up success, connection/malformed backoff, in-flight success clearing backoff, and health bypassing backoff.
7. **Commands:** Node/Bun/absolute runtime preservation; zero/nonzero process exit; inherited signal registration without raw PCNTL mutation; healthy/unhealthy preflight; returned 2xx/500; response-less close after health; refusal before health; configured timeout/client seam.
8. **Finder/provider:** singleton identity, successful lookup reuse, configurable paths/extensions, misses not cached, and application rebinding.
9. **Docs/types/records:** README and Vite claims match final source; no direct package-source state lookup remains outside `InertiaState::current()`; no stale raw signal, facade stop transport, or duplicated dispatch block remains.

After focused tests:

```shell
./vendor/bin/phpunit --no-progress tests/Inertia
composer fix
```

If `composer fix` fails, correct with targeted checks and then run the failed entry plus every remaining script entry as required by `AGENTS.md`. Finish with a full diff review through all callers/callees, current upstream comparison, API/named-argument review, state/lifetime review, static reset review, hot-path allocation/network review, and peer code-review sign-off.

## Completion criteria

- Every accepted finding except separately scoped DevTools is implemented with load-bearing coverage and no superseded path.
- DevTools is durably recorded as the immediate next work unit and the package checklist remains unchecked.
- Boot configuration, request state, gateway identity, page data, and command signal ownership each have one authoritative owner.
- Healthy SSR retains its reusable client. Necessary hot-path additions are one first-access shallow state clone in place of the current new-state allocation, one shallow clone per resolved scroll-prop path, Symfony's bounded `Vary` list read, and direct SSR shape checks; successful SSR also deletes the current second full-page encoding and temporary string.
- Documentation is Laravel-style, concise, and truthful; README contains only the public testing difference.
- Focused tests and `composer fix` are green; self-review finds no stale code, workaround, unbounded state, Laravel API break, or speculative machinery; peer review signs off.
