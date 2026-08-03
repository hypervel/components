# HTTP Correctness, JSON:API, and Current Laravel Parity

## Scope

Complete the HTTP package audit and the known JSON:API parity work. Correct verified request, response, middleware, client, file, recording, configuration, documentation, metadata, and test defects at their owning boundaries. Port the current Laravel HTTP and JSON:API surfaces without weakening Hypervel's Swoole ownership or application extension points.

This plan is the implementation source of truth. After context compaction, read `AGENTS.md` and this file in full; do not reread the core audit plan. Reopen source and current upstream files for evidence rather than relying on a compacted summary.

## Goals and invariants

- Preserve current Laravel public APIs, named arguments, protected extension points, configuration shape, and conventional behavior unless this plan records an approved Hypervel difference.
- Use the originating Laravel pull requests to discover every changed file, then port from the current local Laravel branch, including later fixes, consumers, tests, fixtures, metadata, and documentation.
- Keep Hypervel's dynamic CORS resolver and connection extensions useful for request-specific applications, including tenant-aware applications.
- Keep request-specific values local to the request. Do not store resolved CORS configuration or other mutable request data on worker-lived middleware or services.
- Record each HTTP request attempt once at the existing Guzzle middleware boundary, including failed asynchronous attempts and multipart request data.
- Finish JSON:API parity without duplicate relationship resolution or stale test layout.
- Fail at the owner of invalid native/configuration state. Do not add compatibility fallbacks that hide missing application configuration.
- Remove superseded helpers, duplicate logic, stale comments, obsolete documentation, and the completed JSON:API todo.
- Add no new lock, registry, context slot, cache, retry loop, validation framework, compatibility shim, or transport abstraction.

## Anti-overengineering and performance rules

These rules are copied here because this is the only plan reread during implementation:

- Require a supported, realistic path and meaningful harm before treating a concern as a defect. Merely conceivable states do not justify machinery.
- Added complexity must pay for a demonstrated failure, a complete trace proving a realistic failure schedule, an approved capability with real consumers, or deletion of greater complexity.
- Prefer the simplest existing Laravel or Hypervel API, PHP feature, or dependency primitive.
- Fix related symptoms at the lowest shared owner. Do not add consumer-local compensation for a broken shared contract.
- Do not add machinery for deliberate escape hatches such as a fully custom HTTP client, disabled middleware, raw transport access, or other explicit bypasses unless the public contract promises instrumentation through them.
- For worker or coroutine concerns, identify the concrete shared state, realistic interleaving, and failure before adding isolation or cleanup.
- Bound waits only when progress depends on an external owner that can disappear. Do not add arbitrary internal timeouts, polling, or retry policy.
- Treat upstream changes as evidence, not proof: trace Hypervel behavior before adopting or rejecting them, and fix a verified defect even when upstream shares it.
- Preserve Laravel APIs by default. A difference requires a concrete benefit and explicit owner approval; parity is not a reason to preserve a verified bug.
- Every performance proposal must account for allocations, container/config resolution, locks, hashing, serialization, yields, retries, logging, and retained worker memory. A source-proven or measured hot-path regression requires owner approval.
- Performance improvements must have a practical benefit after complexity and upstream divergence are counted; benchmark noise does not justify a fork.
- Do not retain incomplete fixes to minimize churn. Correct every verified defect completely and remove the superseded design.
- Run independent cleanup steps exhaustively and preserve the earliest failure where this work touches resource ownership.
- Tests must fail against the old behavior for the intended reason. Do not add production seams solely for tests.
- Prefer direct branches and existing helpers over generic abstractions. Do not extract one-use trivial helpers.

## Research and settled boundaries

### References

- Hypervel baseline: the `0.4` branch in this worktree.
- Current upstream: `examples/laravel/framework`, Laravel 13.x. Historical pull requests are discovery only.
- Known originating framework changes include PRs `#41322`, `#44179`, `#54850`, `#54998`, `#59239`, `#59813`, `#60503`, `#60589`, `#60614`, `#60663`, `#60726`, `#60734`, `#60742`, `#60752`, `#60753`, `#60829`, `#60834`, `#60852`, and `#60945`; the response JSON signature documentation originates in Laravel docs PR `#11306`.
- Prior HTTP work that must remain valid: `http-01` inherited Request reset; `http-02`, `filesystem-07`, `foundation-04`, and `http-server-03` response ownership; `http-03` current Foundation parity; `http-04`, `http-05`, and `http-06` JSON:API identity, include, and fieldset corrections.

For each upstream update, inspect every file in the originating implementation and documentation pull request, then copy or merge the current upstream result one file at a time. Preserve Hypervel-specific tests and approved adaptations.

Also compare the current public symbols of every touched upstream file with Hypervel and reconcile each difference. For `Request` and its concerns, this exhaustive comparison found only the approved `get()` omission, the correctly translated `setHypervelSession()`, and `image()` from the unported Image component.

### Worker and request ownership

`HandleCors` is an unbound concrete, so Hypervel auto-singletons it for the worker. Its resolved configuration must remain a local variable. The mutable `CorsService` remains a fresh per-request object; storing either on the middleware would leak configuration across concurrent requests.

The dynamic resolver must run before matching paths because the returned configuration owns `paths`. It runs once for every request reaching the middleware except an explicit `skipWhen` exclusion. This fixes the current bug where configured paths are consulted first and resolver-only paths never activate. With no resolver, the middleware performs one typed `cors` configuration read rather than the current two reads. A resolver callback pays the same cost per invocation as before, but may now run on requests that the stale static paths would previously reject. This necessary hot-path change is owner-approved; document that callbacks should remain cheap and return the complete CORS configuration.

### Approved API decisions

- Change protected CORS helpers to accept the request-local path configuration. Preserving Laravel's protected signature would require unsafe shared mutation; this difference is approved.
- Remove the obsolete `trustedproxy.proxies` fallback. Hypervel's supported bootstrap API is `$middleware->trustProxies(...)`; this difference is approved.
- Preserve Laravel's protected textual trusted-header extension surface by typing `$headers` as `int|string`. Textual `HEADER_X_FORWARDED_ALL` and every current Symfony `Request::HEADER_*` spelling resolve explicitly; unknown strings throw `InvalidArgumentException` instead of silently enabling the full trust mask. This fail-closed invalid-input difference is approved.
- Keep `Request::capture()` absent and direct `Response::sendContent()` unsupported because Swoole's request/response bridges own those operations.
- Keep Laravel Cloud, Forge, and Vapor host-specific proxy behavior absent because those integrations are not Hypervel surfaces.
- Narrow `PrefersJsonResponses::handle()` to `Response`. Both global and route pipelines normalize the downstream value before middleware unwinds, and sibling middleware already use this type. This closes a Hypervel port inconsistency without runtime work.
- Retain `requestsReusableClient()` and `getReusableClient()`. They are protected Laravel extension surface, not dead code.
- Keep Pool and Batch HTTP client APIs unported; their approved unsupported status is unchanged.
- Keep `Request::image()` with the complete first-party Image component rather than adding a partial HTTP bridge. The owner approved a separate Image work unit recorded in `docs/todo.md`; it includes Filesystem integration, HTTP metadata, and the two current request tests.

## Finding dispositions

| ID | Decision |
|---|---|
| `http-a01` | Port `PendingRequest::query()` and its facade/docs/tests surface. |
| `http-a02` | Already fixed: explicit empty JSON:API sparse fieldsets remain empty. Preserve the regression. |
| `http-a03` | Move `withCookies()` from `RedirectResponse` to `ResponseTrait` as in current Laravel. |
| `http-a04` | Trust wildcard IPv4 and IPv6 proxy ranges correctly. |
| `http-a05` | Complete current JSON:API source, tests, fixtures, and performance corrections as specified below. |
| `http-a06` | Make client request header lookup PSR-7 case-insensitive and literal, including dotted names; use current `array_all()` coverage. |
| `http-a07` | Treat only trimmed empty request content as no JSON; decode valid `"0"`. |
| `http-a08` | Port current Laravel cache-header handling for bodyless HEAD responses. |
| `http-a09` | Accept `Arrayable|array|JsonSerializable` payloads for body-bearing verbs and QUERY. |
| `http-a10` | Correct the complete throw/throwIf/throwUnless family, callback storage, and status-code types. |
| `http-a11` | Remove duplicate HTTP/Testbench configuration defaults at their consumers; retain partial-bootstrap defaults owned by `LoadConfiguration`. |
| `http-a12` | Mark Factory global middleware configuration as boot-only. |
| `http-a13` | Keep `Request::capture()` omitted and record the supported Swoole request boundary. |
| `http-a14` | Apply request-exception truncation before preparing the message. |
| `http-a15` | Consolidate success and failure recording in the recorder middleware; this also closes `http-r01`. |
| `http-a16` | Resolve complete CORS configuration once per applicable request before path matching. |
| `http-a17` | Already fixed: invalid nested includes fail through the intended request exception. Preserve the regression. |
| `http-a18` | Make file detection null-safe while preserving valid `"0"` values and filenames. |
| `http-a19` | Use truthful nullable extension return types. |
| `http-a20` | Mark HTTP credentials as sensitive parameters. |
| `http-a21` | Correct root/split extension metadata and validate it. |
| `http-a22` | Port the complete current JSON:API test/fixture layout and merge Hypervel regressions. |
| `http-a23` | Remove unused related-resource class state. |
| `http-a24` | Apply only bounded strict-comparison/current-helper corrections with counterfactual value cases. |
| `http-a25` | Port the current relevant Laravel HTTP unit and integration coverage, preserving custom Hypervel tests. |
| `http-a26` | Add package provenance. |
| `http-a27` | Keep direct response emission unsupported and record the transport owner. |
| `http-a28` | Correct Laravel's response-content truthiness bug by preserving body `"0"`, and read response content once when deciding cacheability. |
| `http-a29` | Preserve an explicit trusted-header mask of `0` and explicit empty proxy overrides. |
| `http-a30` | Delete legacy proxy config fallback; record supported bootstrap and omitted vendor-specific behavior. |
| `http-a31` | Preserve a hash-name path of `"0"`. |
| `http-a32` | Preserve an explicit fake-file size of zero, including nonempty content. |
| `http-a33` | Add worker-lifetime warnings to HTTP test and global configuration mutators. |
| `http-a34` | Port current fake response stream support. |
| `http-a35` | Document `Response::json()` decode flags and the worker-lifetime default-flags boundary. |
| `http-a36` | Rejected: retain protected reusable-client methods. |
| `http-a37` | Add current `PendingRequest::createClient(HandlerStack)` and delegate `buildClient()` through it while retaining Factory's connection/cookie-aware creator. |
| `http-a38` | Make `Factory::record()` public and complete facade and real-request coverage. |
| `http-a39` | Normalize array keys at `RedirectResponse::with()` before crossing the strict session-key boundary. |
| `http-a40` | Reproduce Laravel's weak scalar/Stringable response-content coercion explicitly before Symfony's strict string boundary. |
| `http-a41` | Normalize the supported boolean `withUserAgent()` values before `trim()`. |
| `http-a42` | Preserve Symfony's port parsing for bracket-prefixed host headers without a closing bracket. |
| `http-a43` | Narrow `PendingRequest::newResponse()` to the response type its constructor actually accepts. |
| `http-a44` | Preserve fake promise rejections while simulating transfer statistics so recorder middleware sees failed attempts. |
| `http-a45` | Make fake sinks match Guzzle's string, resource, and PSR stream behavior and propagate failed writes. |
| `http-a46` | Enforce `RelationResolver`'s declared class-string contract instead of silently discarding a missing resource class. |
| `http-a47` | Restore the URL parameter forwarding surface to the generator's open normalization contract and require an expiration for temporary signed URIs. |
| `http-a48` | Treat only the protocol value `"true"` as a PJAX request, rejecting Laravel's loose acceptance of misleading truthy strings. |
| `http-r01` | Close through `http-a15`; duplicate response recording has the same lower owner. |
| `testbench-03` | Remove Testbench's duplicate `app.url` call-site fallback while retaining `$baseUrl` as its sole default. |

## Final design

### 1. HTTP client APIs and types

Port `PendingRequest::query()` in current upstream order. `QUERY`, `POST`, `PATCH`, `PUT`, and `DELETE` accept `Arrayable|array|JsonSerializable`; normalize using the existing request-option path. Update the facade metadata and concise HTTP client documentation.

Add public `PendingRequest::createClient(HandlerStack $handlerStack): ClientInterface`. Preserve custom-client precedence with `buildClient()` shaped as `$this->client ?? $this->createClient($this->buildHandlerStack())`, and align `getReusableClient()` with upstream as `$this->client ??= $this->createClient($this->buildHandlerStack())`. The new method uses Factory's existing `createClient($handlerStack, $this->cookies)` when a factory exists and otherwise constructs Guzzle with the same handler and cookie jar. Do not remove or reshape Factory's connection-aware creator.

Move `withCookies()` to `ResponseTrait` so ordinary and redirect responses share the Laravel API. Remove only the duplicate method/import from `RedirectResponse`.

Normalize `RedirectResponse::with()` array keys to strings at the session boundary. PHP exposes only integer or string array keys, and the explicit cast preserves Laravel's weak-mode coercion without widening the session API. Pin both list keys (`'0'`, `'1'`) and the ordinary associative form.

Make `Factory::record()` public. Add facade metadata and prove a real, unfaked request can be recorded. Mark these worker-lived Factory methods with concise lifecycle warnings where applicable:

- `globalMiddleware`, `globalRequestMiddleware`, `globalResponseMiddleware`, and `globalOptions`: Boot-only.
- `fake`, `fakeSequence`, and `stubUrl`: Tests only.
- `sequence` and the newly public `record`: Tests only.
- `preventStrayRequests` and `allowStrayRequests`: Boot or tests only.

Mirror those warnings on real facade methods; facade documentation is generated from the underlying methods, so keep the source as the authority.

Match current Laravel's direct assignment/return shape in `withUserAgent()` and cast its declared boolean values before `trim()`, preserving `false` as an empty header and `true` as `"1"`. Narrow `newResponse()` to `ResponseInterface`; promises have never been accepted by the wrapped response constructor. Type the test override consistently, add no analyzer-only annotation, and recheck every nullable Guzzle response path while rewriting recording.

### 2. Throw behavior

Use explicit, truthful signatures:

```php
// PendingRequest: compatible superset of the upstream call shape.
public function throwIf(bool|callable $condition, ?callable $callback = null): static;
public function throwUnless(bool|callable $condition, ?callable $callback = null): static;

// Response: restore the explicit current behavior while accepting every
// callable already accepted at runtime.
public function throw(?callable $callback = null): static;
public function throwIf(bool|Closure $condition, ?callable $callback = null): static;
public function throwUnless(bool|Closure $condition, ?callable $callback = null): static;
public function throwIfStatus(int|callable $statusCode): static;
public function throwUnlessStatus(int|callable $statusCode): static;
```

Retain shaped callback docblocks where native `callable` cannot express parameters.

Normalize stored PendingRequest callables into `Closure` values because PHP does not allow callable property types and the existing `?Closure` properties reject valid string and array callables:

```php
$this->throwCallback = $callback === null ? fn () => null : $callback(...);

if (is_callable($condition)) {
    $this->throwIfCallback = $condition(...);
}
```

Invert PendingRequest callable conditions lazily:

```php
if (is_callable($condition)) {
    return $this->throwIf(fn (Response $response) => ! $condition($response), $callback);
}

return $this->throwIf(! $condition, $callback);
```

Response already owns a concrete response, so its inverse remains eager through `value($condition, $this)`. Regress both true and false callable results and string, array, and Closure callbacks.

### 3. Request/response recording

`buildRecorderHandler()` owns all recording. Keep its fulfillment handler and add a rejection handler that records the original request with `hypervel_data`, includes a response for a Guzzle `RequestException` that has one, and propagates the original rejection unchanged:

```php
return $promise->then(
    function ($response) use ($request, $options) {
        // Existing success recording.
        return $response;
    },
    function ($reason) use ($request, $options) {
        $this->factory?->recordRequestResponsePair(
            (new Request($request))
                ->withData($options['hypervel_data'] ?? [])
                ->setRequestAttributes($this->attributes),
            $reason instanceof RequestException && $reason->hasResponse()
                ? $this->newResponse($reason->getResponse())
                : null,
        );

        return Create::rejectionFor($reason);
    },
);
```

Remove recording from the three marshal methods; they retain exception conversion and event dispatch. This produces one record per attempt, includes asynchronous failures and multipart data, and removes the `http_errors=true` duplicate. `Create::rejectionFor()` preserves a non-Throwable rejection for downstream handlers; do not wrap it.

When a fake returns a promise, attach transfer-stat simulation as a fulfillment handler and return the derived promise instead of calling `wait()` inside `Factory::fake()`. This preserves rejected promises until the recorder middleware and keeps transfer statistics available before response population in synchronous and asynchronous requests.

Return the sink-derived promise from `buildStubHandler()` and return the original PSR response after a successful write. Support the same `resource|string|StreamInterface` sink values as Guzzle without wrapping caller-owned resources. A string sink must write the complete body; a resource sink fails only when `fwrite()` returns `false`, matching Guzzle without rejecting valid short writes on non-blocking streams. Let PSR streams throw through their own contract. Use `$sink !== null` so a valid string path of `"0"` is not skipped. A failed sink records one failed attempt with a null response because the transfer did not complete at the client boundary. Leave rewind failures unchanged: unlike a failed write, they do not lose the requested bytes, and no supported non-seekable case requires additional policy.

A fully custom client supplied by `setClient()` continues to bypass the handler stack and therefore all recording, matching its existing bypass of successes, stubs, and stray-request checks. A `StrayRequestException` thrown by `buildStubHandler()` before a stub response is produced remains unrecorded. Do not add fallback recording or deduplication state for either deliberate boundary.

### 4. CORS and proxy middleware

`HandleCors::handle()` performs these steps:

1. honor `skipWhen` callbacks;
2. resolve the complete request-local config once from the custom resolver or `$config->array('cors')`;
3. match using `$config['paths'] ?? []` so a custom resolver omitting paths fails closed;
4. create `CorsService` with that same local config;
5. process preflight or the downstream response without storing request state on the middleware.

Change `hasMatchingPath()` and `getPathsByHost()` to receive the local paths array. Add one short source comment explaining that mutable CORS state remains request-local because middleware instances are shared by a worker. Update public routing documentation: the resolver runs once for every request reaching the middleware except explicit skip rules, must return the complete config including `paths`, and should remain cheap.

Use typed full CORS configuration with no call-site default. Applications that enable or resolve an owning service must supply its configuration. Do not alter `LoadConfiguration`'s bootstrap fallbacks: it deliberately supports partial configuration during bootstrap.

In `TrustProxies`:

- trust `0.0.0.0/0` and `::/0` for the wildcard form;
- use null-coalescing precedence so `withHeaders(0)` and `at([])` remain explicit;
- keep protected textual header configuration live, including separate `HEADER_X_FORWARDED_ALL` and `HEADER_X_FORWARDED_TRAEFIK` arms, and reject unknown spellings;
- remove `config('trustedproxy.proxies')`;
- direct users to `$middleware->trustProxies(...)` in the package difference record;
- retain a concise source marker for omitted Laravel Cloud/Forge/Vapor behavior, with no user-facing claim that Hypervel provides those vendor integrations. Current upstream has no matching tests for these omissions, so do not invent `REMOVED:` markers at unrelated test positions.

### 5. JSON:API completion

Port current source behavior while preserving the stronger Hypervel identity boundary:

- `RelationResolver::handle()` unwraps closure-returned `JsonApiResource` and `AnonymousResourceCollection` values.
- Keep `RelationResolver::handle(): Collection|Model|null`; a single resource wrapping a non-model is invalid relation data and should fail at the declared boundary rather than widening the API. Do not add a speculative collection-content validator.
- Reject a missing string resource class in `RelationResolver`'s constructor, naming the relation and class. This enforces the existing `class-string<JsonApiResource>` contract at the last boundary where the value is still identifiable as configuration; valid mappings retain the existing single `class_exists()` check. Do not add subclass validation.
- Keep early conversion of plain `JsonResource` values to `JsonApiResource` before type/ID resolution. Do not add Laravel's later redundant guard.
- Remove `compileIncludedNestedRelationshipsMap()` and every caller. In Hypervel this path is live but duplicates the later authoritative traversal, causing relationships and queries to resolve twice.
- Prove each relationship closure runs exactly once, nested payloads remain exact, and `BelongsToMany` includes contain the expected identity/count despite random non-unique map keys.
- Use current `Collection::only()`, `isset(class_uses_recursive(...)[AsPivot::class])`, `new Stringable(...)`, and corrected identifiers.
- Remove the unused related-resource class variable and the completed JSON:API todo.
- Preserve `http-04`, `http-05`, and `http-06`: null resource IDs throw, top-level includes do not feed a null sentinel to nested parsing, and explicitly empty fieldsets stay empty.
- Do not add a dead `?? []` after `Collection::all()` or a second relationship map.

Mirror current upstream layout:

- Unit: `tests/Http/Resources/JsonApi/{JsonApiRequestTest,JsonApiResourceTest,RelationResolverTest}.php`. The request file retains Hypervel's query-only `http-05` / `http-06` regressions on the lightweight unit base.
- Integration: `tests/Integration/Http/Resources/JsonApi/{JsonApiCollectionTest,JsonApiRequestTest,JsonApiResourceTest,TestCase}.php`.
- Port the complete package-scoped fixture set under the integration JSON:API directory and merge the existing Hypervel-specific identity/include/fieldset tests.
- Correct upstream's inert `CommentResource` mapping from the nonexistent `UserApiResource` to `UserResource` and use the sibling fixtures' protected typed arrays. The existing `PostResource` author mapping remains the discriminating proof that explicit resource classes override model discovery.

Move existing JSON:API tests rather than copying and deleting them. Preserve all custom tests while matching current upstream class/file boundaries.

### 6. Native and value boundaries

- Client request headers use PSR-7 `hasHeader()` and `getHeader()` directly. This restores case-insensitive lookup and treats dots literally; remove `Arr` header lookup. Use current `array_all()` for multiple headers.
- `Request::json()` regards only `trim($content) === ''` as absent, so `"0"` decodes normally.
- `Request::hasFile()` handles null/missing filenames without converting valid `"0"` values into absence.
- `FileHelpers::extension()` and `UploadedFile::clientExtension()` return `?string`.
- Preserve path `"0"` in `hashName()`.
- Preserve explicit size zero in fake files; the counterfactual uses nonempty content followed by `size(0)`.
- In `Response::setContent()`, preserve JSON/Renderable precedence, then cast scalar and native `Stringable` values before Symfony's `?string` setter. Keep the raw value in `original`; reject other objects naturally.
- In `Request::getPort()`, use offset zero when a bracket-prefixed host has no closing bracket, matching Symfony's weak call-site behavior without adding a host parser.
- Use `mixed` for every URL/route parameter slot that forwards into `UrlGenerator::formatParameters(mixed)`, including the Foundation helpers, URL generator contract and implementation, Redirector, and Uri. The accepted set is deliberately open because the owner applies `Arr::wrap()` and normalizes every `UrlRoutable`; do not replace it with a guessed union or add forwarding-layer conversion. Preserve Laravel parameter names, including `$parameters` on `url()` and `secure_url()` and `$extra` on generator `to()` and `query()`.
- Restore `Uri::temporarySignedRoute()` to a required, non-null `DateInterval|DateTimeInterface|int $expiration`. Its prior optional null default silently produced a permanent signed URL. Keep the shared Laravel behavior for integer zero unchanged.
- Mark passwords on `withBasicAuth()`, `withDigestAuth()`, and `withNtlmAuth()`, and the token on `withToken()`, with `#[SensitiveParameter]`.
- Apply exception truncation before `prepareMessage()`.
- `SetCacheHeaders` handles HEAD responses, reads content once, and distinguishes exact empty content from `"0"`.
- Document optional JSON decode flags on `Response::json()`. Add `Boot-only.` and a concrete cross-request warning to the public static `$defaultJsonDecodingFlags` property: changing it during request handling affects JSON decoding in every concurrent and subsequent request on that worker. Keep instance-scoped `decodeUsing()` unchanged.
- Treat only the literal `"true"` header value as PJAX. This fixes Laravel's loose acceptance of values such as `"false"` and deliberately rejects arbitrary truthy strings such as `"1"`.
- Apply bounded strict comparisons only where they preserve actual types: URL checks, stray-request patterns, and current `array_any()`/`array_all()` forms. Do not start a cosmetic strictness sweep.

### 7. Configuration, metadata, and documentation

Use `$config->string('app.url')` in `HttpServiceProvider`; remove its duplicate fallback. Foundation's application config owns the `http://localhost` default, so normal application and console bootstrap always supply the key. In Testbench, read `app.url` through `config()->string()` without an inline fallback. Retain the inherited `protected string $baseUrl = 'http://localhost'` default for modes that do not overwrite it. Testbench's own booted modes provide the key through Foundation config; arbitrary applications using `dontMergeFrameworkConfiguration()` remain responsible for config required by services they enable. Keep the Socialite config-rebinding regression focused by cloning the current application configuration into its replacement repository and changing only `services.github`.

Require `ext-filter` in both root and HTTP split metadata. Suggest `ext-gd` only in the distributed HTTP split package for `Hypervel\Http\Testing\FileFactory::image()`; the root monorepo does not duplicate optional split-package suggestions. Add `tests/Http/PackageMetadataTest.php`, following the established package convention, to pin those contracts. Add concise package provenance.

User-facing documentation remains Laravel-style and task-focused:

- HTTP client: QUERY, throw behavior where useful, lifecycle warnings, response JSON flags, and fake streams.
- Requests/responses: Swoole owns capture and emission; name the supported framework request/response paths.
- Routing/middleware: dynamic CORS timing/completeness and proxy bootstrap migration.
- Do not expose internal handler ordering, auto-singleton mechanics, or audit terminology in public guides.

Intentional omitted Laravel APIs/features receive the repository's required record only when users or future ports need it: short README difference, natural source comment, and a matching `REMOVED:` test marker when current upstream has a test position for the omission. Internal adaptations such as early JSON:API normalization remain in the ledger/plan, not user docs.

### 8. Current Laravel tests

Audit current upstream `tests/Http` and `tests/Integration/Http` in full. Merge rather than replace existing Hypervel tests. Port source-adjacent coverage first so behavior changes are counterfactual before implementation.

Known missing unit files include:

- `HttpJsonResponseTest.php`
- `HttpMimeTypeTest.php`
- `HttpRedirectResponseTest.php`
- `HttpTestingFileFactoryTest.php`
- `HttpUploadedFileTest.php`
- `JsonResourceTest.php`
- `tests/Http/Fixtures/Enums.php`, consumed by `HttpRequestTest`. Preserve upstream's lowercase and snake-case enum case names as ported identifiers rather than rewriting the fixture API.
- `Middleware/CacheTest.php`
- `Middleware/TrustProxiesTest.php`

Merge current `HttpClientTest`, `HttpRequestTest`, and `HttpResponseTest`. Port relevant integration coverage for event streams, the HTTP client, JSON responses, resources, responses, JSON resource collections, JSON:API, and CORS. Keep Cache and TrustProxies test filenames in their current upstream locations.

Adapt reachable middleware callbacks in ported tests to return `Response` at Hypervel's typed boundary. Retain `null` callbacks when the tested exception must prevent downstream invocation; do not widen middleware source types to preserve loose upstream fixtures.

Do not port `testImageMethod` or `testImageMethodReturnsNullForMissingKey` ahead of the complete Image component. This is an open todo rather than a closed difference, so add no README entry, source omission comment, `REMOVED:` marker, placeholder, or absence test.

Keep the lightweight `tests/Http/Middleware/HandleCorsTest.php` and merge current upstream coverage into the existing application-backed `tests/Integration/Http/Middleware/HandleCorsTest.php`; the files exercise different boundaries. Merge current upstream event-stream coverage into the existing `tests/Integration/Http/EventStreamResponseTest.php`; keep it under HTTP because its HTTP-owned `StreamedEvent` and `IterableStreamedResponse` behavior is reached through the routing response factory. Update the existing Hypervel-specific `tests/Integration/Http/Middleware/TrustProxiesTest.php` for the proxy changes and preserve its coroutine regression. Retain the existing `ResponseBindingTest`, `TrustHostsTest`, `PreventRequestForgeryServerRuntimeTest`, and `ThrottleRequestsWithRedisTest` as Hypervel-specific integration coverage. Keep the established `tests/Foundation/Http/Middleware/ValidatePathEncodingTest.php` placement, which already mirrors upstream; do not move unrelated established tests during this layout work.

Do not port Foundation-owned CSRF, TrimStrings, request-duration, Routing throttling, or approved unsupported Pool/Batch tests into HTTP. Keep every Hypervel-specific Swoole, coroutine, connection, response-bridge, and regression test.

## Implementation sequence

Work one file at a time and run each changed test file immediately.

1. Port/merge unit `Middleware/CacheTest` and `Middleware/TrustProxiesTest`, and update the existing integration `tests/Integration/Http/Middleware/TrustProxiesTest.php`; implement their source fixes. In unit TrustProxies coverage, retain supported current behavior, add counterfactual textual-header and invalid-value coverage, and do not invent `REMOVED:` markers for the Laravel Cloud/Forge/Vapor and deleted `trustedproxy.proxies` cases because current upstream has no matching tests. In integration coverage, rename the wildcard test for catchall semantics and make it use a multi-hop chain. Change the coroutine-isolation regression to `TrustProxies::at('REMOTE_ADDR')`, preserving a different resolved trusted proxy for each request after wildcard behavior changes.
2. Port/merge request, redirect/response-trait, uploaded-file, mime, and JSON response tests; implement their bounded source fixes, including explicit weak-mode content and bracketed-host normalization.
3. Port/merge HTTP client tests; implement QUERY, payload unions, user-agent normalization, truthful response construction, client creation, throw behavior, recording, fake promise settlement and sinks, fake streams, Factory record visibility, lifecycle warnings, facade metadata, and truncation.
4. Port JSON:API unit files, then integration base/fixtures/files serially; implement RelationResolver and element-resolution changes only after counterfactual tests exist.
5. Implement CORS config ownership and focused unit/integration coverage.
6. Correct `HttpServiceProvider`, Testbench fallback ownership, package metadata, provenance, public docs, README/source/test difference records, and remove the completed todo.
7. Port the remaining relevant current upstream tests and reconcile only failures that expose supported behavior.
8. Update the audit ledger, routing index, dependency index, and package checklist after implementation and sign-off; do not pre-claim completion.

## Test plan

### Focused regressions

- QUERY request construction, facade use, and all supported payload interfaces.
- ResponseTrait cookies on ordinary and redirect responses; redirect flash arrays preserve normalized list keys and associative keys.
- Header case-insensitivity and dotted literal header names.
- Empty versus `"0"` JSON bodies, response bodies, file names, paths, and file sizes.
- Response scalar/Stringable wire content versus raw original content, including a discriminating float, nested response, and one invalid object.
- Boolean user agents preserve Laravel's `false -> ''` and `true -> '1'` results.
- Bracket-prefixed host headers without a closing bracket preserve both no-colon and explicit-port Symfony outcomes.
- Direct `UrlRoutable` parameters pass through URL, query, secure, action, redirect, Uri, and temporary-signed forwarding boundaries; temporary signed Uri generation includes an `expires` query parameter. Widen the existing `UrlGenerator` test implementation with the contract in the same edit so it remains loadable.
- HEAD cache-header parity, `"0"` body cacheability, and one content read.
- PJAX accepts only `"true"`; `"false"`, `"1"`, null, and an empty value are rejected.
- Wildcard proxy regressions must be counterfactual: use a multi-hop IPv4 forwarded chain that returned an intermediate proxy under the old calling-peer-only behavior, and a multi-hop IPv6-peer chain whose correct result requires the `::/0` catchall as well as `0.0.0.0/0`. A single-hop chain does not discriminate. Also cover header mask `0`, an empty override, and absence of the legacy fallback.
- Textual proxy headers: accept the legacy Laravel all-header spelling and every current Symfony constant name, including Traefik; reject an obvious typo instead of trusting every forwarded header.
- Preserve the existing trusted-proxy coroutine regression with `TrustProxies::at('REMOTE_ADDR')`, distinct peer/forwarded addresses, and forced interleaving. Wildcard catchall state is identical for every request and can no longer prove per-request trusted-proxy isolation.
- CORS static and dynamic paths, host-specific paths, complete-config reuse, one resolver invocation, skipped requests, and missing resolver paths failing closed. Add `tests/Http/Middleware/CoroutineIsolationTest.php`: two concurrent requests receive different resolver-supplied origins, force interleaving between resolution and header assertion, and each retain its own `Access-Control-Allow-Origin`; the test must fail if request config is stored on the shared middleware instance.
- Throw callbacks for Closure/string/array callables; PendingRequest deferred true/false conditions; Response eager true/false conditions; callback forwarding; status-code callable and integer forms.
- Recording: success, connection failure, response-bearing failure, async failure, each retry attempt, `http_errors=true` no duplicate, multipart data retained, custom-client bypass, and synchronous stray-request behavior.
- Fake promise and sink behavior: asynchronous fulfilled-fake transfer stats, PSR stream writes, failed string/resource writes propagating, and one null-response record for each failed sink.
- Fake PSR streams/resources across direct, failed, and sequence responses.
- JSON:API exact nested payload, exactly-once relationship resolution, `BelongsToMany` inclusion, closure-returned resources/collections, missing relationship resource classes, plain JsonResource normalization, empty fieldsets, valid/invalid includes, and null IDs.
- Config ownership in normal, cached, Testbench, and explicitly partial application modes at the service/middleware boundary.
- Facade metadata and split/root Composer dependency contracts.
- README/source/test records remain present for intentional omissions.

### Validation cadence

1. Run each changed/new test class immediately.
2. Run all HTTP unit tests and relevant HTTP integration groups.
3. Run affected Testbench tests and `composer test:testbench` after Testbench changes.
4. Run `composer fix` once the coherent implementation is complete; it owns formatting, both PHPStan configs, parallel components, Testbench, and dogfood suites.
5. Run strict Composer validation for root and `src/http/composer.json`, facade documentation checks, documentation link/format checks where available, `git diff --check`, stale-symbol searches, and current-upstream file inventory comparisons.

Do not run redundant manual formatter/PHPStan/full-suite passes immediately before `composer fix`; focused tests still run as each file changes.

## Fresh review and completion

Before requesting code review:

- reread the complete diff and every changed caller/callee;
- trace Guzzle handler ordering, rejection propagation, retries, async promises, custom-client bypass, and request-data ownership;
- trace CORS middleware lifetime, resolver timing, config ownership, and concurrent request isolation;
- compare every ported source/test file with the current Laravel branch and verify historical pull-request file coverage;
- prove all prior `http-01` through `http-06` assumptions still hold;
- verify no response emission or request capture path bypasses the Swoole bridges;
- inspect hot paths for added allocations, config/container reads, callbacks, locks, retries, yields, and retained memory;
- remove dead imports, helpers, compatibility branches, stale comments, obsolete tests, and superseded documentation;
- confirm no accepted concern has been answered with a registry, cache, context slot, broad validator, or workaround.

Request independent review of the complete code and validation, loop until sign-off, then update final audit records. The HTTP package is complete only when accepted findings are implemented, relevant current Laravel tests and JSON:API fixtures are present, all gates are green, the fresh review and independent review are complete, the ledger/routing/checklist are accurate, and the owner has reviewed the final summary before any commit.
