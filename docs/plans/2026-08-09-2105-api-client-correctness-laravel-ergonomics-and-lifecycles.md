# API Client Correctness, Laravel Ergonomics, and Lifecycles

## Scope and outcome

Complete the deferred API Client audit, the fresh package-wide architecture/API review, and the related HTTP and split-package ownership defects exposed by that review. The fifteen self-contained finding subjects and decisions below originated in the ignored `.tmp/audit-findings/api-client.md` evidence record; that path is provenance only and is not required to resume or implement this plan. The package is Hypervel-native, so current Laravel HTTP client conventions are the API reference rather than an implementation to copy.

Keep the useful three-layer design:

```text
ApiClient integration prototype
  -> operation-local Hypervel\ApiClient\PendingRequest
    -> Hypervel\Http\Client\PendingRequest
      -> transport
```

The completed package must provide reusable integration classes, Laravel-shaped fluent request configuration, API request/response middleware, operation context, truthful typed resources, non-throwing-by-default HTTP behavior, and exact state transfer across retries. It must remain safe when an unbound concrete `ApiClient` or middleware is auto-singletoned for a worker. Remove the base `DataObject` contract, custom middleware cache, option staging, public prototype toggles, and every superseded helper rather than retaining compatibility aliases.

References checked for this design:

- all API Client source/tests and the originating 15-finding audit;
- current Hypervel HTTP Request, Response, PendingRequest, Factory/facade metadata, Pipeline, Container, resource, context, and package-metadata patterns;
- current Laravel HTTP `Factory`, `PendingRequest`, `Request`, `Response`, resource, forwarding, and pipeline conventions;
- installed Guzzle request preparation, redirects, body rewind, PSR-7 stream, cookie, and transfer-stat behavior;
- all repository API Client consumers, Guzzle/PSR imports, split manifests, documentation indexes, and the core audit ledger.

Focused probes reproduced worker-shared middleware mutation, lost delegated values, shadowed Guzzle middleware, callback accumulation, wrapper-state loss, configured-query deletion, post-dispatch async rejection, stale response decoding, invalid stream/framing behavior, and scalar-resource failures. Existing green tests establish only the baseline.

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

| Finding | Decision |
|---|---|
| Public middleware toggles mutate a worker-shared client | Remove worker-shared middleware mutation from `ApiClient`; middleware suppression exists only on a fresh pending operation. |
| The pending-request proxy discards values and shadows HTTP middleware APIs | Stop shadowing HTTP middleware names and use decorated forwarding so fluent and value-returning methods remain truthful. |
| Valid callable-string middleware is treated as a class | Delete the custom resolver/cache; Pipeline owns callable execution and container resolution/lifetimes. |
| Resource selection and forwarding reject valid public surfaces | Accept the base resource class, forward macros/PSR methods, and make resource generic transitions truthful. |
| Request mutation has stale caches and incoherent data-format behavior | Rebuild API request mutation around explicit structured/raw state and exact method/format ownership. |
| Replacing a response body leaves the decoded response stale | Clear response decode state whenever middleware replaces its body. Do not add duplicate plural PSR helpers. |
| JSON failures escape as incidental return-type errors | Use throwing JSON boundaries and preserve the original `JsonException`. |
| PSR-only conversion discards HTTP wrapper state | Add explicit wrapper factories that preserve every relevant parent field; no reflection or serialization copier. |
| Reusing a pending request accumulates bridge callbacks | Register one retry-aware bridge per API pending request and clear transient active request state in `finally`. |
| Unsupported async dispatch performs the request before failing | Reject `async(true)` before builder creation or dispatch; `async(false)` remains a fluent no-op. |
| Guzzle options are silently order-dependent | Delete Guzzle option staging; forwarded `withOptions()` is the one owner. |
| A forwarded custom Guzzle client bypasses the required API bridge | Reject `setClient()` before dispatch and direct low-level transport customization to the supported `setHandler()` seam. |
| API middleware installs a server-only stream and stale framing | Use seekable PSR streams and exact post-preparation framing at the body-replacement owner. |
| The wrapper is behind current Laravel HTTP APIs | Align terminals with current HTTP types, fix shared HTTP Request parsing/query invariants, and preserve omitted query arguments. |
| The public package is undocumented and its metadata is incomplete | Add complete static types, split metadata, Laravel-prose documentation, and a minimal README link. |
| Package test statics are cleaned up only after successful assertions | Reset test helper statics centrally in exception-safe teardown. |
| Architecture/API review | Remove base `DataObject` configuration and public state getters; use constructor injection plus `configurePendingRequest()`. |
| Context review | Put one operation-local context API on pending request, request, and response; never use coroutine/global storage. |
| Error/resource review | Default to non-throwing HTTP and use an explicit array-conversion contract without rejecting raw response access. |
| Structured input review | Distinguish an omitted GET/HEAD query from explicit null; normalize query, form, and multipart wrapper values at their HTTP owners. |
| Final request-body ownership | Preserve exact logical payload values until supported HTTP middleware replaces the prepared body, then invalidate them so later callbacks, fakes, recorders, and API middleware read the final bytes. |
| Telescope raw-body capture | Omit the structured-payload option for raw bodies so Telescope uses its bounded PSR-body parser; document that structured entries report caller input rather than later callback rewrites. |
| Dependency sweep | Declare every verified direct owner in the changed split packages, advertise optional Foundation search/Faker and Database Faker features, and remove Reverb's false API Client dependency. |
| `api-client-01` ledger correction | Amend the earlier targeted record: only resource JSON was fixed; request encoding still used non-throwing `json_encode()`. |

The Support/HTTP mutual dependency is not a defect in this work. The source dependency and transitive path remain even if one truthful edge is removed, the same-version component graph contains many intentional mutual pairs, and no supported install/behavior failure was established. Do not add a TODO or cosmetic manifest edit.

The originating audit's labels are evidence-local and collide with the already durable `api-client-01` JSON record. Record the completed work under these unused durable IDs instead:

| Durable ID | Owner / subject |
|---|---|
| `api-client-02` | reusable client ownership, configuration, and construction |
| `api-client-03` | truthful forwarding and API middleware surface/lifetimes |
| `api-client-04` | one bridge, operation context, retry/failure cleanup |
| `api-client-05` | explicit request/response wrapper reconstruction |
| `api-client-06` | structured/raw request mutation, streams, JSON, and framing |
| `api-client-07` | response decode invalidation and resource array contracts |
| `api-client-08` | default error, async, and HTTP option ownership |
| `api-client-09` | terminal/query parity and omitted-argument preservation |
| `api-client-10` | static types, documentation, test isolation, and package metadata |
| `http-28` | shared Request media/data/query/subtype correctness |
| `telescope-43` | raw HTTP body payload capture and structured-payload ownership |
| `broadcasting-18`, `contracts-13`, `foundation-20`, `http-29`, `inertia-24`, `socialite-28`, `support-35`, `telescope-42` | exact direct dependency ownership at each split package |
| `database-34` | Faker suggestion for Eloquent model factories |
| `reverb-41` | false API Client dependency removal |

## Implementation

### 1. Make `ApiClient` a reusable integration definition

Delete `TConfig`, `$config`, `getConfig()`, middleware enablement state/toggles, state getters, `resolveMiddleware()`, and `$middlewareCache`. Keep protected resource and middleware defaults. Concrete clients own typed constructor dependencies; `DataObject` remains available but is not required by the base class.

Use the Laravel-shaped construction seams:

```php
/**
 * @template TResource of ApiResource = ApiResource
 * @mixin PendingRequest<TResource>
 */
class ApiClient
{
    use ForwardsCalls;

    /** @var class-string<TResource> */
    protected string $resource = ApiResource::class;

    /** @var list<callable|object|string> */
    protected array $requestMiddleware = [];

    /** @var list<callable|object|string> */
    protected array $responseMiddleware = [];

    /** @return PendingRequest<TResource> */
    public function createPendingRequest(): PendingRequest
    {
        $request = $this->newPendingRequest()
            ->withResource($this->resource)
            ->replaceApiRequestMiddleware($this->requestMiddleware)
            ->replaceApiResponseMiddleware($this->responseMiddleware);

        $this->configurePendingRequest($request);

        return $request;
    }

    /** @return PendingRequest<TResource> */
    protected function newPendingRequest(): PendingRequest
    {
        /** @var PendingRequest<TResource> $request */
        $request = new PendingRequest;

        return $request;
    }

    protected function configurePendingRequest(PendingRequest $request): void
    {
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo(
            $this->createPendingRequest(),
            $method,
            $parameters,
        );
    }
}
```

Defaults are applied before `configurePendingRequest()` so subclasses can intentionally replace them. Client forwarding is ordinary: every call begins with a fresh API pending request; returning the shared client instead would discard operation state.

No manager, facade, service provider, registry, clone, request-scoped client, or compatibility aliases are added.

Concrete integrations bind the static resource type explicitly; the protected property selects the same class at runtime:

```php
/** @extends ApiClient<IssueResource> */
final class IssueClient extends ApiClient
{
    protected string $resource = IssueResource::class;
}
```

Without `@extends`, PHPStan correctly falls back to `ApiResource`; the property value cannot bind a generic by itself. Document this pairing and pin both direct terminals and `createPendingRequest()` on a concrete client in the max-level fixture.

### 2. Separate API middleware from HTTP/Guzzle middleware

Use unambiguous singular append and explicit replacement methods:

```php
public function withApiRequestMiddleware(callable|object|string $middleware): static;
public function replaceApiRequestMiddleware(array $middleware): static;
public function withApiResponseMiddleware(callable|object|string $middleware): static;
public function replaceApiResponseMiddleware(array $middleware): static;
public function withoutApiMiddleware(): static;
```

Delete `enableMiddleware()`, `disableMiddleware()`, `withRequestMiddleware()`, `withAddedRequestMiddleware()`, `withResponseMiddleware()`, `withAddedResponseMiddleware()`, and custom middleware construction. Pass configured entries directly to Pipeline. Callable strings execute as callables; class strings resolve through the container with their registered transient/scoped/singleton lifetime. Unbound middleware retains Hypervel's normal auto-singleton reuse.

The HTTP builder's `withRequestMiddleware()` and `withResponseMiddleware()` become reachable through forwarding. API middleware receives config through object construction/container DI or operation context, never an implicit one-argument constructor.

### 3. Make pending-request forwarding and context truthful

Move the trait to `Hypervel\ApiClient\Concerns\HasContext` and use it on `PendingRequest`, `ApiRequest`, and `ApiResponse`:

```php
public function withContext(array|string $key, mixed $value = null): static;
public function context(?string $key = null, mixed $default = null): mixed;
```

Pending context seeds every outgoing API request. Request middleware may change it. Copy the final request context into the API response before response middleware runs; the last retry attempt therefore owns the returned context. PHP array copy-on-write keeps this bounded and local.

Keep Pipeline construction explicit and testable without tying the pending request back to its client prototype:

```php
public function __construct(?Pipeline $pipeline = null)
{
    $this->pipeline = $pipeline ?? Container::getInstance()->make(Pipeline::class);
}
```

The concrete Pipeline binding is intentionally transient because Pipeline is a mutable per-operation builder. Resolving it honors that framework binding and test/application swaps; do not instantiate it directly or let it fall through to worker auto-singleton behavior.

API `PendingRequest::__call()` uses `forwardDecoratedCallTo()`: substitute the wrapper only when the HTTP builder returns itself; return arrays, strings, clients, handlers, and other values unchanged. Remove `$guzzleOptions`/`withGuzzleOptions()`; normal `withOptions()` forwards immediately.

Reject unsupported async before touching the HTTP builder:

```php
public function async(bool $async = true): static
{
    if ($async) {
        throw new InvalidArgumentException('The API client does not support asynchronous requests.');
    }

    return $this;
}
```

Default builder creation uses `Http::createPendingRequest()` rather than `Http::throw()`. Callers and subclasses opt into existing `throw()` semantics through normal forwarding.

Do not forward HTTP `setClient()`. A caller-owned `ClientInterface` bypasses the handler stack that owns the API bridge, so the request reaches the transport before resource construction fails without an active API request. Declare `setClient(ClientInterface $client): never` on the API pending request and throw `BadMethodCallException` before dispatch. `setHandler()` remains the supported low-level transport seam because it replaces only the handler beneath the fresh HTTP middleware stack. Name the protected HTTP pending-request accessor `getRequest()` to match its `$request` property and avoid a false getter/setter pair.

### 4. Register one retry-aware request bridge

Replace per-terminal callback appends with one bridge installed lazily by the first terminal call, after all callbacks configured before that first dispatch. Maintain a nullable transient `ApiRequest` property for the active/final attempt and clear it in `finally` after response middleware/resource construction or any failure.

```php
protected function prepareClient(): ClientPendingRequest
{
    $request = $this->getRequest();

    if (! $this->bridgeRegistered) {
        $this->bridgeRegistered = true;
        $request->beforeSending(function (HttpRequest $request): RequestInterface {
            $apiRequest = ApiRequest::createFrom($request)
                ->withContext($this->context());

            $this->activeRequest = $this->runRequestMiddleware($apiRequest);

            return $this->activeRequest->toPsrRequest();
        });
    }

    return $request;
}
```

Each HTTP retry invokes the same bridge once from the retry's fresh base request, so mutation does not compound. Before the first dispatch, caller `beforeSending` callbacks run first and the API bridge runs last; the bridge therefore captures their final request before API middleware and resource construction. Callbacks appended after a pending request has already dispatched run after the installed bridge and are an unsupported ordering escape hatch; do not add callback-list mutation merely to reorder them. Pin both orders explicitly. Like Laravel's mutable HTTP builder, a single pending request is not a concurrent-sharing primitive.

`sendRequest(string $method, mixed ...$arguments)` converts the final HTTP response, transfers context, runs response middleware, creates the resource, and clears `$activeRequest` in `finally`. Initialize it to null; never dereference a request that failed before the bridge.

### 5. Preserve wrapper state explicitly

Keep public PSR constructors and add named factories. Copy only the fixed parent state:

```php
public static function createFrom(HttpClientRequest $request): static
{
    $apiRequest = new static($request->toPsrRequest());
    $apiRequest->data = $request->data;
    $apiRequest->hasDecodedData = $request->hasDecodedData;
    $apiRequest->attributes = $request->attributes;

    return $apiRequest;
}

public static function createFrom(HttpClientResponse $response): static
{
    $apiResponse = new static($response->toPsrResponse());
    $apiResponse->cookies = $response->cookies;
    $apiResponse->transferStats = $response->transferStats;
    $apiResponse->decoded = $response->decoded;
    $apiResponse->hasDecoded = $response->hasDecoded;
    $apiResponse->decodingFlags = $response->decodingFlags;
    $apiResponse->decodeUsing = $response->decodeUsing;
    $apiResponse->truncateExceptionsAt = $response->truncateExceptionsAt;

    return $apiResponse;
}
```

Subclass scope can copy these protected parent fields directly from the source wrappers. Do not widen parent fields, add accessors solely for copying, reflect, serialize, or retain duplicate wrapper graphs. Preserve cookies, effective URI/transfer stats, custom decoder, decode cache/flags, attributes, and exception truncation.

### 6. Give `ApiRequest` coherent raw and structured mutation

Use `GuzzleHttp\Psr7\Uri` and `Utils::streamFor()`; remove Engine Stream and the `hypervel/engine` dependency. String URLs take precedence over callable transforms so a valid URL such as `trim` is not invoked.

Public structured methods are:

```php
public function withData(array $data): static;               // replace
public function mergeData(array $data): static;              // additive
public function withoutData(array|string $keys): static;     // dot notation supported
```

For body-bearing methods they mutate representable JSON/form/default structured state. Derive eligibility from the final request: JSON and form bodies are representable; an empty body without a content type may become structured; multipart, another declared content type, or a non-empty untyped body is not. Do not retain a separate structured-body flag whose value can drift when middleware changes headers or bodies. For GET/HEAD, structured methods reject with a descriptive exception directing callers to `withQuery()` / `withoutQuery()`; they never create or edit GET/HEAD bodies. Explicit raw GET/HEAD bodies remain supported through `withBody()` and are replaced with another `withBody()`.

`withBody()` invalidates structured state. `asJson()` / `asForm()` convert only structured representable data and reject raw/multipart bodies. Unknown body formats fail at this API boundary instead of passing arrays to a stream constructor.

Apply every structured mutation eagerly so later API middleware always sees matching logical data, body bytes, and framing headers. Do not retain a deferred-change flag or flush work from accessors. Centralize body installation:

```php
protected function replaceBody(string $body): void
{
    $request = $this->request->withBody(Utils::streamFor($body));

    if ($request->hasHeader('Transfer-Encoding')) {
        $request = $request->withoutHeader('Content-Length');
    } else {
        $request = $request->withHeader('Content-Length', (string) strlen($body));
    }

    $this->request = $request;
}
```

Use this for raw and encoded replacement bodies so redirects/retries can rewind them and framing cannot remain stale. Preserve `Transfer-Encoding`; otherwise publish the exact byte count. Encode JSON once with `JSON_THROW_ON_ERROR`; default structured writes establish JSON content type.

Normalize token/header signatures (`#[SensitiveParameter]` on the token; declared false user agent produces the same header value as HTTP). Header values use PSR-compatible string/string-list types.

### 7. Correct shared HTTP Request ownership

Fix behavior used by API Client in `Hypervel\Http\Client\Request`, not locally:

- normalize the media type case-insensitively and ignore legal parameters for JSON/form classification;
- keep `data(): array`; if JSON decodes to a scalar, throw a descriptive `InvalidArgumentException` rather than failing at a typed property/return;
- track whether form/JSON data has genuinely been decoded so valid empty arrays are cached; `withData()` injects logical caller data without marking it decoded, and both formats decode only while the cache is unset and the injected array is empty;
- preserve numeric query keys during parse/mutate/build operations;
- let `withoutQuery()` remove either one key or a key list, matching the sibling request-removal APIs;
- recover URL-embedded query data for HEAD assertions and recorders as well as GET;
- return `static` from `withData()`, `withQuery()`, and `withoutQuery()`.

Keep logical payloads exact until a supported request hook replaces the prepared PSR body. When configured Guzzle middleware exists, push one gated handler before it that stores the prepared body stream under a protected internal option key. At the existing before-sending boundary, read and remove that internal key before invoking public callbacks, compare stream identity against the prepared baseline and after each accepted callback result, then keep it removed for downstream handlers. A replacement clears the local logical data for later callbacks and removes `hypervel_data` from options passed to the recorder/stub; header-only changes retain exact PHP values. Do not read, hash, or compare body bytes. In-place stream mutation is a deliberate low-level bypass and is not tracked.

Raw `withBody()` requests and requests carrying an explicit raw `body` option do not publish `hypervel_data`; request wrappers and Telescope therefore parse the final PSR body. Empty structured JSON, form, and query payloads still publish the option. Telescope's structured path reports the caller's logical payload from the original Guzzle options and cannot observe later handler-stack rewrites; state that ownership in its method comment instead of adding watcher-side redirect/rewrite detection.

Decode non-empty request JSON with `JSON_THROW_ON_ERROR`: malformed JSON keeps its native parse diagnostic, while valid non-array JSON receives the request-specific array-contract exception. An exactly empty body has no payload and decodes to `[]`; whitespace-only JSON remains malformed.

Normalize structured values with one recursive helper that converts nested Hypervel `Stringable`, `JsonSerializable`, and `Arrayable` values. The wire and logical-payload consumers have distinct owners:

- `normalizeRequestOptions()` validates merged query options as `array|string|null` and supplied form options as arrays, then normalizes non-finite floats. A directly null `form_params` option remains untouched so Guzzle retains its established bodyless-request behavior; a supplied structured value that resolves to null is still invalid;
- `parseRequestData()` resolves per-terminal query, JSON, and form input before publishing `hypervel_data`, because that payload is computed before option merging and wire normalization;
- multipart remains structurally aligned through the existing `normalizeMultipartOption()` owner on both paths. `parseHttpOptions()` unwraps a top-level `JsonSerializable` or `Arrayable` container before requiring an array, while `normalizeMultipartOption()` resolves nested structured part values. `parseRequestData()` returns an actual multipart option through that shared owner without a second general traversal; multipart-configured GET/HEAD requests continue through normal query parsing when no multipart option is present.

Remove `get()`'s local query normalization once both consumers are correct. Query values must resolve to an array, string, or null; form and multipart values must resolve to arrays, with format-specific `InvalidArgumentException` messages otherwise. Do not change JSON-body precedence, redefine configured query options as per-terminal `hypervel_data`, or widen Request data to mixed. `Request::query()` remains the accessor for the merged effective URI query. Initialize the HTTP pending request's transient `$request` property to null.

HTTP and API `get()` and `head()` accept `Arrayable|array|JsonSerializable|string|null`. API terminals branch on their own `func_num_args()` and omit the query argument entirely when absent. This preserves defaults installed by `withQueryParameters()`; explicit null remains an explicit replacement. Add the current `query()` terminal and use current write-terminal `Arrayable|array|JsonSerializable` types.

### 8. Make response/resource conversion explicit

`ApiResponse::withBody()` replaces the PSR body, then clears `$decoded`, `$hasDecoded`, and `$decodingFlags`; retain `$decodeUsing` so it runs against the replacement. Keep the existing singular PSR header methods; do not duplicate the parent response's dynamic plural/PSR surface.

Add `Hypervel\ApiClient\Exceptions\InvalidResourceDataException extends UnexpectedValueException`. `ApiResponse` implements `Arrayable` with `@implements Arrayable<array-key, mixed>` and owns array conversion:

```php
/**
 * @return array<array-key, mixed>
 * @throws InvalidResourceDataException
 * @throws JsonException
 */
public function toArray(): array
{
    $decoded = $this->json();

    if (is_array($decoded)) {
        return $decoded;
    }

    if ($decoded === null && ($this->decodeUsing !== null || $this->hasEmptyOrNullJsonBody())) {
        return [];
    }

    throw new InvalidResourceDataException('The API response body could not be converted to an array.');
}

protected function hasEmptyOrNullJsonBody(): bool
{
    $body = $this->body();
    $length = strlen($body);
    $offset = strspn($body, " \t\n\r");

    if ($offset === $length) {
        return true;
    }

    if ($length - $offset < 4 || substr_compare($body, 'null', $offset, 4) !== 0) {
        return false;
    }

    $offset += 4;

    return $offset + strspn($body, " \t\n\r", $offset) === $length;
}
```

`hasEmptyOrNullJsonBody()` scans only JSON whitespace (`SP`, `HTAB`, `LF`, `CR`) with offsets/`strspn()` and compares the four `null` bytes in place. It must not `trim()` or copy a potentially large error body merely to reject it.

The final implementation must distinguish the default decoder from a custom decoder exactly: custom null maps to `[]`; default null maps to `[]` only for empty/whitespace or JSON `null`; malformed/nonempty default JSON and any other value throw. `Arrayable`, `JsonSerializable`, and object results from a custom decoder are not recursively unwrapped. `JSON_THROW_ON_ERROR` may surface `JsonException` first when flags request it. Raw `body()`, status, headers, PSR response, and `getResponse()` always remain available; resource construction never rejects text/error bodies.

`ApiResource` also declares `@implements Arrayable<array-key, mixed>` and delegates `toArray()` to its response. `__get()` and `offsetGet()` use the throwing array conversion but return null for a missing key after a valid array body. `__isset()` and `offsetExists()` never raise `InvalidResourceDataException`; they return false unless `json()` produced an array containing the key, while an application-configured `JSON_THROW_ON_ERROR` decoder may still surface `JsonException` first. Document both wrappers' throwing Arrayable contract and the missing-key behavior because generic consumers such as `collect($apiResponse)` invoke the conversion.

Use `is_a($resource, ApiResource::class, true)`. Give `withResource()` a method template, truthful `@return $this`, and `@phpstan-this-out static<TNewResource>` so both fluent expressions and the mutated variable narrow without a contradictory `@return static<TNewResource>`. Replace the untyped variadic resource factory with `make(ApiResponse $response, ApiRequest $request): static`, making the bridge's non-null request invariant statically enforceable. Resource `__call()` performs ordinary forwarding without `method_exists()`, allowing response macros and PSR methods. Add `toPrettyJson()`, keep caller JSON flags, use `JSON_THROW_ON_ERROR`, and declare `jsonSerialize(): array`.

### 9. Make dynamic types complete and truthful

Keep `@mixin ClientPendingRequest`, but add a complete explicit `@method` list for every current public fluent HTTP method that returns the inner builder, with `static` as the API return. Do not include HTTP terminals there; explicitly declare the eight resource terminals (`get`, `head`, `query`, `post`, `patch`, `put`, `delete`, `send`) with their current parameter types and `TResource` return.

Exclude `setClient()` from the forwarded method list and declare the native `never` method instead. Keep `setHandler()` in the fluent surface.

Value-returning delegated methods remain discoverable through the mixin and runtime decorated forwarding. Propagate `PendingRequest<TResource>` through the outer client. Add a max-level fixture under `types/ApiClient/` covering:

- client and explicit-pending fluent chains, including a concrete `@extends ApiClient<IssueResource>` default and `createPendingRequest()` retaining that resource;
- all eight resource terminals;
- value forwarding (`getOptions()`, `getConnection()`, builder/handler values);
- base and narrowed resources after `withResource()`;
- inherited request mutators returning `ApiRequest`;
- Arrayable generics on response/resource.

Do not add a PHPStan extension, reflection parity test, generated forwarding class, or runtime forwarding allowlist.

### 10. Make split manifests truthful

Declare exact namespace owners, using root-compatible constraints and metadata tests that compare split constraints to root rather than pinning duplicated version literals:

| Package | Change |
|---|---|
| API Client | add `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `psr/http-message`; remove `hypervel/engine` |
| Broadcasting | add `guzzlehttp/guzzle` |
| Contracts | add `psr/http-message` (a real standalone interface-signature defect) |
| Database | suggest `fakerphp/faker` for Eloquent model factories |
| Foundation | add `guzzlehttp/guzzle`, `league/flysystem`, `league/uri`, `monolog/monolog`; suggest `algolia/algoliasearch-client-php`, `meilisearch/meilisearch-php`, `typesense/typesense-php`, and `fakerphp/faker` for the documented testing traits and helper |
| HTTP | add `guzzlehttp/promises`, `guzzlehttp/psr7`, `psr/http-message` |
| Inertia | add `guzzlehttp/guzzle`, `guzzlehttp/promises` |
| Socialite | add `psr/http-message` |
| Support | add `guzzlehttp/promises`, `league/commonmark` |
| Telescope | add `guzzlehttp/promises` |
| Reverb | remove unused `hypervel/api-client` and its positive metadata assertion |

Add `guzzlehttp/promises` and `guzzlehttp/psr7` to the root manifest because source imports them directly. Use the lower bounds required by the current root Guzzle 7 line (`^2.5.2` and `^2.13` at planning time), let Composer resolve the newest compatible releases, and update the lockfile through Composer rather than manual edits. Existing `guzzlehttp/guzzle`, `psr/http-message`, and Algolia root constraints remain authoritative.

Add or extend bounded `PackageMetadataTest` coverage in every changed package. API Client, Inertia, and Support require new metadata test files; Broadcasting, Contracts, Database, Foundation, HTTP, Socialite, Telescope, and Reverb extend their existing files. Every hard-dependency constraint comparison asserts that the key exists in both root and split manifests first, without duplicating presence already established by a broader dependency loop. Complete that direct-assertion convention across all affected metadata tests; do not add a helper. Tests assert hard-dependency root/split consistency and optional suggestion presence, not copied version text or the entire manifest.

### 11. Documentation, records, and deletion

Revise the hidden draft into `src/boost/docs/api-client.md` only after the code/API is final. Use Laravel-docs prose to cover installation, integration classes, constructor configuration, the required `@extends ApiClient<ConcreteResource>` plus matching `$resource` property, pending request customization, one pending request per concurrent operation, terminals/options/query omission, context propagation, errors/`throw()`, typed resources and scalar-body conversion, null reads for missing resource fields, API vs Guzzle middleware, DI lifetimes, raw/structured request mutation, async rejection, custom-handler support and custom-client rejection, and HTTP fakes. Every example must define the variables it uses. Add the package to the Boost documentation index.

Keep `src/api-client/README.md` to package classification and a documentation link. Do not put audit history, bug fixes, implementation internals, or migration notes in public docs.

Update the core audit plan/ledger to mark the complete API Client audit finished, record every cross-package owner, and correct the earlier `api-client-01` claim. Remove or replace obsolete tests/comments/docs along with every deleted API; do not leave aliases or deprecated shims.

## Testing

### Focused API Client coverage

- client construction ordering, fresh-operation isolation, constructor-owned config, and absence of worker-shared toggles;
- singular append/replacement middleware APIs; closures, objects, callable strings/arrays, class-string DI, and explicit container lifetimes for request and response middleware;
- fluent/value forwarding, unshadowed Guzzle middleware, `withOptions()` ordering, default nonthrowing behavior, opt-in `throw()`, pre-builder async rejection, `setClient()` rejection before transport, and `setHandler()` retaining the API bridge;
- one bridge per reused pending request, one middleware run per retry attempt, no compounding, callback ordering, and transient-state cleanup after every request/response/resource/transport failure;
- context seeding, request mutation, response propagation, retry last-attempt ownership, default lookup, and operation isolation;
- explicit wrapper factories preserving data, attributes, cookies, transfer stats/effective URI, decoders/cache/flags, and truncation;
- URL callable precedence; replace/merge/dot-notation remove semantics; eager body/data/header coherence between middleware; GET/HEAD guard; raw GET bodies; raw/multipart conversion rejection; throwing JSON; seekable redirect/retry bodies; transfer/content-length framing;
- stale response decode invalidation while retaining custom decoder;
- base/subclass/invalid resource selection, exact `make(ApiResponse, ApiRequest)` construction, generic narrowing, macro/PSR forwarding, pretty JSON, caller flags, array/scalar/malformed/empty/null/custom-decoder cases, existence probes that avoid `InvalidResourceDataException` while preserving configured `JsonException`, and generic `collect()` behavior;
- all eight terminals, omitted versus explicit-null GET/HEAD queries, configured-query preservation, and Arrayable/JsonSerializable write/query inputs, including missing resource-field reads.

### Shared-owner and metadata coverage

- HTTP media types with case/parameters, exact-empty JSON read requests, distinct malformed/scalar JSON diagnostics, empty decode caching, numeric query keys, static subtype returns, GET/HEAD URL-query observation, bodyless form-configured HTTP/API Client reads and deletes, recursive structured query/form/multipart normalization on both wire and logical-payload paths, multipart-configured read requests retaining string and URL queries, and invalid result types;
- exact logical JSON/form values without a body rewrite; final data after local/global Guzzle middleware or `beforeSending` body replacement; later callbacks, stubs, recorders, API middleware, retries, multipart file probes, and scalar replacement failures; raw terminal body options; gated and callback-hidden prepared-body metadata; no added common-path parsing;
- Telescope raw-body capture for `withBody()` and the structured-input ownership boundary;
- every compared root/split hard dependency being present before its constraint is compared, optional Foundation/Database feature suggestion, and Reverb dependency removal;
- test helper statics reset from `tearDown()` even after exceptions;
- documentation index/link integrity and max-level type fixtures.

### Gates

Run focused API Client, HTTP, and metadata suites during implementation, then:

```bash
composer validate --strict
composer fix
```

After green gates, trace every changed caller/callee and retry/failure path again; inspect the final diff for dead state, accidental API shadows, hidden hot-path work, incomplete records, and public-documentation boundary violations. Then obtain code-review sign-off.

## Performance and completion criteria

The final design removes callback growth, option replay state, package-local middleware caching, and prototype mutation. The only added ordinary-path work is fixed wrapper field assignment, PHP copy-on-write context propagation, decorated-forwarding identity checks on dynamic calls, and normal Pipeline/container resolution for configured class middleware. Body-stream identity checks add no parsing or body reads; the prepared-body option/handler exists only when Guzzle middleware is configured. The `setClient()` rejection runs only when that unsupported method is called. Unbound middleware retains worker reuse; explicit lifetimes now work correctly. Body encoding/framing runs only when middleware mutates a body. There are no new locks, atomics, yields, sleeps, retries, network calls, serialization passes, coroutine context entries, or worker-growth structures.

The work is complete only when:

- all settled findings and related package-owner defects are implemented with focused regressions;
- API Client remains fully capable through the new Laravel-shaped APIs without compatibility machinery;
- no stale property, helper, comment, dependency, test, or documentation survives the owning-model changes;
- resource and query contracts are explicit at runtime and static-analysis boundaries;
- split manifests are truthful without version-literal maintenance traps;
- docs contain only supported public usage in Laravel prose;
- focused and full gates pass, self-review is complete, and peer code review signs off;
- the audit plan/ledger routes every completed finding and leaves no deferred verified defect or TODO.
