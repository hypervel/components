# Hypervel Saloon Port

## Objective

Add `hypervel/saloon` as a first-party Hypervel component for building API integrations and SDKs. Port Saloon's useful public model—connectors, requests, middleware, authentication, responses, data objects, fakes, fixtures, OAuth 2, caching, pagination, rate limiting, and bounded concurrent sends—while replacing its framework-neutral transport and Laravel adapter layers with Hypervel's own services.

The result must feel familiar to Saloon users and Laravel developers, but it must be designed for Hypervel's long-lived workers:

- connectors contain stable integration configuration and are safe for worker reuse, while mutable requests and pending state remain operation-owned;
- request customization uses Hypervel HTTP's familiar fluent vocabulary without exposing framework-neutral stores as the primary API;
- request-specific state is coroutine-local;
- immutable class metadata and reusable HTTP transports are worker-cached with bounded keys;
- network concurrency is explicit and bounded;
- framework HTTP, cache, rate-limiter, events, console, collections, and testing behavior remains authoritative;
- no compatibility aliases, deprecated APIs, duplicate adapters, or unused extension machinery remain.

This is a new package. Earlier Hypervel behavior and churn do not constrain the design. Preserve useful Saloon concepts and names, not framework-neutral internals that Hypervel does not need.

Any verified framework defect uncovered while implementing this plan must be fixed at its owning Hypervel component with regression coverage. Do not add a Saloon-local workaround or leave the defect for later.

## Source and framework references

Use these checked-in sources as the implementation references:

- `examples/saloon/saloon`: core connectors, requests, bodies, middleware, authentication, OAuth 2, response helpers, fakes, fixtures, pools, and tests.
- `examples/saloon/cache-plugin`: cache behavior and tests.
- `examples/saloon/pagination-plugin`: paginators and tests.
- `examples/saloon/rate-limit-plugin`: public rate-limit use cases and tests.
- `examples/saloon/laravel-plugin`: service provider, facade, events, console generators, stubs, and Laravel-facing tests.
- `src/http`: the concrete transport, response wrapper, named connections, middleware, events, fakes, and Telescope integration.
- `src/cache`, `src/rate-limiter`, `src/coroutine`, `src/events`, `src/collections`, `src/console`, `src/prompts`, and `src/testing`: the framework-owned primitives used by the port.
- `src/permission`: the package skeleton reference for a third-party ecosystem port.

Local measurements found negligible wrapper construction cost and no meaningful request-time difference between raw Guzzle with a reused handler and Hypervel HTTP with a registered connection. Use Hypervel HTTP because it removes the duplicated Saloon sender/factory layer while retaining named connection reuse, framework middleware, fakes, events, and Telescope. Do not layer Saloon over `hypervel/api-client`: it is an alternative high-level integration model, and composing both would duplicate request/response/middleware lifecycles. Benchmark complete single sends and pools against upstream after implementation; do not add timing assertions to CI.

## Package shape

Create one optional package at `src/saloon` with namespace `Hypervel\Saloon`. Fold the official plugins into the same package:

```text
Hypervel\Saloon\
├── Cache\
├── Console\Commands\
├── Contracts\
├── Data\
├── Enums\
├── Events\
├── Exceptions\
├── Facades\Saloon
├── Http\
├── Pagination\
├── RateLimit\
├── Repositories\
├── Traits\
├── SaloonManager
└── SaloonServiceProvider
```

Keep Saloon's `Traits` convention throughout this port. Do not introduce a parallel `Concerns` directory. Keep source class and member order close to Saloon where an upstream class remains recognizable; use Hypervel-owned structure for the provider, manager, sender, and plugin integrations.

The package Composer file must declare every dependency it uses directly, including the required Hypervel components and PSR/Guzzle types referenced by source. Wire `Hypervel\Saloon\` into the root autoloader, add `hypervel/saloon` to root `replace`, and register the provider and facade alias in both Composer metadata locations. Saloon is optional and must not be added to `DefaultProviders`.

The expected direct runtime set is `hypervel/cache`, `collections`, `conditionable`, `console`, `container`, `contracts`, `coroutine`, `events`, `filesystem`, `foundation`, `http`, `macroable`, `prompts`, `rate-limiter`, `reflection`, and `support`; `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `psr/http-message`, `symfony/console`, `symfony/dom-crawler`, `symfony/finder`, `symfony/var-dumper`, `ext-dom`, `ext-mbstring`, and `ext-simplexml`. `foundation` owns the path helpers used by published config, `reflection` owns the closure-type inspection used by test assertions, and generator prompts, Symfony Console/Finder types, and multibyte string functions are used directly. `hypervel/coroutine` owns pool context propagation, so Saloon does not depend directly on `hypervel/context`. Confirm the final set from actual imports and use Composer commands for root dependency changes; do not rely on transitive packages.

Add:

- `src/saloon/LICENSE.md`, preserving the MIT notices from Saloon core and every folded official plugin and adding Hypervel's notice;
- a minimal `src/saloon/README.md` containing the header, documentation link, and upstream link;
- `src/docs/saloon.md` as the only full user guide, written in Laravel documentation prose;
- `src/saloon/config/saloon.php` and generator stubs.

Do not copy the Laravel plugin's unused Boost resource files. Hypervel's current Boost component has no package-resource registration surface, so those files would be dead code. Saloon usage belongs in the normal guide.

## Public differences from Saloon

Document these durable differences and their replacements in the guide. The README stays a pointer to that guide rather than becoming a second documentation surface:

- The transport is Hypervel HTTP. There is no configurable `Sender`, sender factory collection, Guzzle sender API, or process-global `Config` class.
- Request customization uses Hypervel HTTP names such as `withHeader()`, `withQueryParameters()`, `withOptions()`, `timeout()`, `withToken()`, and `retry()` instead of requiring callers to mutate Saloon stores directly. Saloon-specific authenticators and declarative request traits remain available; the mutable `HasTries` fields are not retained.
- Connector instances are reusable definitions, not request builders. Per-operation authentication, cache controls, debugging, middleware, retries, and mocks belong on Request/PendingRequest; connector defaults use protected hooks and `boot()`.
- `sendAsync()`, paginator `async()`, and Guzzle promise APIs are replaced by the coroutine-native `Pool` API.
- Cache, pagination, and rate limiting ship under `Hypervel\Saloon` and use Hypervel services; separate plugin drivers and packages are not used.
- Rate limits are immutable Hypervel `AdmissionPolicy` values backed by configured framework stores. Saloon's mutable `Limit`, percentage thresholds, and package-owned store adapters are not retained.
- OAuth 1 is not included. OAuth 2 remains supported.
- OAuth configuration is an immutable constructor value. The mutable `OAuthConfig` setters are replaced by named constructor arguments so a reused connector cannot be reconfigured by another coroutine.
- `authorizationUrl()` returns an object containing both the URL and state instead of `getAuthorizationUrl()` storing state on a mutable connector; `getState()` is removed.
- Deprecated upstream APIs and compatibility shims are omitted, including `sendAndRetry`, deprecated facade response recording, `assertSentJson`, and deprecated paginator page accessors. The canonical authentication surface is `withToken()`, `withBasicAuth()`, `withDigestAuth()`, `withNtlmAuth()`, `authenticate()`, and `defaultAuth()`; these HTTP-style methods are first-class Hypervel APIs rather than aliases for Saloon's deprecated `with*Auth` names.
- Custom response factories receive the Hypervel response through `fromResponse()` rather than rebuilding framework metadata from `fromPsrResponse()`. `resolveResponseClass(Response $response)` receives a normal Saloon response, so a request or connector may select a response subclass from its status, headers, or body without another response-context abstraction.
- Pool concurrency is a positive integer; callable concurrency policies and promise-returning APIs are not retained.
- Pool response and exception handlers receive the result and iterable key; the removed aggregate-promise argument has no coroutine equivalent.
- Response-detected rate limits return a cooldown duration from `resolveRateLimitCooldown()`. Cooldown detection records the response and never resends recursively; the normal connector/request retry policy remains authoritative, and any configured next attempt must pass the recorded cooldown first.
- Static `MockConfig` is replaced by manager-backed `Saloon::fixturePath()` and `Saloon::throwOnMissingFixtures()` test configuration that the framework test subscriber resets with the container after each test.
- Framework-only Nightwatch and Pulse integrations are omitted. Real Saloon network requests already flow through Hypervel HTTP's event and Telescope path.
- `xmlReader()` is omitted rather than depending on a Saloon-namespaced optional package that requires the original `Saloon\Http\Response` type. Keep `xml()` and `dom()`; declare `ext-simplexml` and Symfony DomCrawler as direct package dependencies so advertised response methods always work after installation.

Do not preserve removed methods as aliases or forwarding wrappers. The guide must show the supported replacement for every meaningful removed surface.

## Lifetimes and state ownership

### Connectors and requests

Connectors are stable integration definitions. They hold constructor-supplied API configuration and expose base URL, default headers/query/options/authentication, middleware boot hooks, response/DTO behavior, and HTTP connection selection without accumulating request state. Their base class does not implement `SelfBuilding`: a fixed connector injected into a normal worker-shared service is safe to reuse and benefits from Hypervel's auto-singleton behavior.

Do not place mutable header, query, option, delay, middleware, authenticator, retry, cache-control, debugger, base-URL-override, or mock state on Connector. Protected defaults return values without mutating the connector; `boot(PendingRequest $pendingRequest)` customizes that operation. Base-URL overrides use stable boolean resolver methods rather than public flags. Dynamic tenant credentials or base URLs belong in readonly constructor state on a connector explicitly created for that operation, not in process-global config or a connector captured by a worker-shared controller.

Request remains caller-owned and mutable while preparing one operation. It implements `SelfBuilding`; an explicit container resolution at the call site therefore constructs it freshly while resolving constructor dependencies. Direct `new` construction and Saloon's argument-forwarding `make()` API remain supported. Do not document constructor injection of mutable Request objects into worker-shared services. PendingRequest, Response, middleware repositories, and body repositories are operation-owned and must never be stored on a worker singleton.

`Request::__clone()` must clone every initialized mutable repository owned by the request: headers, query, options, delay, middleware pipeline, body, cookies, retry policy, and cache controls. `MiddlewarePipeline::__clone()` must in turn clone its internal pipelines. Do not recursively clone caller-supplied authenticators, objects, or stream resources stored inside those repositories, and do not clone the explicitly shared MockClient used to record pooled work. This makes paginator-created request clones independent without inventing a general object-graph copier.

### Manager and test state

`SaloonManager` is a worker singleton for immutable or boot-configured package services:

- the concrete `Sender`;
- the framework cache factory and rate-limiter manager passed into operation-owned pending requests;
- global request, response, and fatal middleware registered during worker boot.

Public global middleware mutators must carry the standard `Boot-only.` warning because they affect every later request in the worker. Copy the configured pipe definitions into each new operation pipeline; running or mutating a request must never alter the manager's worker-global definitions.

The active global `MockClient`, stray-request setting, fixture path override, and missing-fixture behavior are test state on `SaloonManager`. This matches Hypervel HTTP's testing model: every pool child sees the same recorder, while `AfterEachTestSubscriber` resets the container and manager between tests. Do not duplicate this with static state or coroutine-context bridging. These APIs are tests-only and are not a runtime per-request customization surface.

Expose `Saloon::fixturePath(string $path)` and `Saloon::throwOnMissingFixtures(bool $throw = true)` as `Tests only.` manager mutators. `Saloon::fake()`, `MockClient::global()`, assertion APIs, and `MockClient::destroyGlobal()` delegate to the manager. Keep the explicit MockClient send argument and request-owned fake support for operation-specific behavior; do not put a mutable mock on Connector.

Use config only for worker defaults such as the fixture directory. Never mutate config to change a mock or tenant-specific connector during a request.

### Bounded static metadata

Plugin boot methods are derived solely from a connector or request class and its recursively used traits. Cache the ordered method-name list in `Http\PendingRequest\BootPlugins`, keyed by concrete class, instead of reflecting on every send. Do not add a separate registry class. The key count is bounded by loaded connector/request classes in the worker.

Connector, Request, and PendingRequest use Hypervel's `Macroable`; add `flushState()` methods that clear their macro state. Response inherits the framework response's Macroable behavior and cleanup rather than declaring a second macro store. `BootPlugins::flushState()` clears its metadata. Register Saloon's optional-package cleanup calls through one grouped `callIfExists()` method in `AfterEachTestSubscriber`; do not add package state to Testbench teardown.

Use Hypervel's `Conditionable`, `Macroable`, `class_uses_recursive()`, `value()`, `data_get()`, `data_set()`, `Str::random()`, collections, and immutable date APIs directly. Remove upstream Helpers, ArrayHelpers, ObjectHelpers, StringHelpers, StatusCodeHelper, and Storage when their last consumer is translated. Retain only Saloon-domain helpers such as middleware processing, fixture redaction, and request-exception formatting.

## HTTP transport

### Laravel-style request surface

Request and PendingRequest expose the Hypervel HTTP names for behavior they share with the framework: body format and attachment methods, `withQueryParameters()`, `withHeader()` / `withHeaders()` / `replaceHeaders()`, content negotiation, `withUserAgent()`, cookies, authentication, redirects, TLS verification, response sinks, `timeout()`, `connectTimeout()`, `retry()`, `withOptions()`, and conditional throwing. Match Hypervel HTTP signatures where the behavior is the same, including `withNtlmAuth()` and the `retry(array|int $times, Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true)` policy. Implement these methods directly against Saloon's operation repositories; do not forward calls to an early framework PendingRequest or create a second mutable option source.

Use Laravel-style noun and conversion accessors where they apply on the redesigned operation classes: `method()`, `uri()`, `headers()`, `request()`, `connector()`, `pendingRequest()`, and `toPsrRequest()`. Do not retain parallel `get*` aliases. These accessors return resolved values, never a mutable repository.

Keep `authenticate(Authenticator $authenticator)` for custom and OAuth authentication. The familiar `withToken()`, `withBasicAuth()`, `withDigestAuth()`, and `withNtlmAuth()` methods configure the corresponding authenticator rather than bypassing Saloon's authentication phase. Authenticators write dedicated operation auth/certificate state that Sender applies after raw options are validated; they do not smuggle owned values into `withOptions()`. Keep declarative request traits such as `HasJsonBody`, `HasFormBody`, `HasMultipartBody`, `HasXmlBody`, `AcceptsJson`, and `AlwaysThrowOnErrors`; they remain useful for class-per-endpoint definitions and write only to the current PendingRequest. Do not port `HasTimeout`: protected defaults, `boot()`, and the fluent timeout methods cover its behavior without property-name reflection.

Header, query, option, delay, and body repositories remain operation internals. Middleware customizes them through the same fluent PendingRequest methods as application code; custom body repositories retain their explicit stream contract. Public examples use fluent methods and protected default hooks, not `headers()->add()`, `query()->add()`, or `config()->merge()`.

### One concrete sender

Add a stateless `Hypervel\Saloon\Http\Sender` injected with `Hypervel\Http\Client\Factory`. It is the only package transport. Do not port:

- `Contracts\Sender`;
- `Traits\Connector\HasSender`;
- `Http\Senders\GuzzleSender`;
- PSR factory collections or multipart body factories;
- sender resolver/configuration globals.

Connector instances cannot receive framework services through their normal user constructors, so `Connector::send()` resolves the canonical `saloon` manager once at the call boundary and delegates the operation. This is the only container fallback on the send path. The manager, sender, and the framework services they use all receive constructor injection and can be rebound by applications and tests. Pending requests receive the already-resolved cache/rate services from the manager; plugin traits must not perform repeated container lookups.

`withOptions()` accepts real Guzzle transport options such as redirects, proxy, TLS/certificates, streaming, and stats callbacks; dedicated fluent methods own timeouts. Fail fast when raw options contain `headers`, `query`, `cookies`, `body`, `json`, `form_params`, `multipart`, `auth`, `delay`, or `http_errors`: Saloon's operation state and manager own those values, and accepting a second source would make cache/fake identity contradict the transmitted request or bypass Saloon authentication/error handling. One internal validator owns this key set and checks the merged operation options after request middleware, the default connection before provider registration, and any connector-selected connection before use. Hypervel HTTP separately rejects handler, object-pool, transport-sharing, and connection-cap options outside registered connections. Do not silently strip or override an invalid placement.

Port Saloon's HTTP method enum and add the standardized `QUERY` method from RFC 10008. It is a normal body-capable method on the existing send path; do not add method-specific transport, retry, or cache machinery.

For each attempt, the sender must:

1. call `Factory::createPendingRequest()` so framework global middleware, fakes, events, and test controls apply;
2. select the fixed registered connection returned by `Connector::resolveHttpConnection()` or the configured package default;
3. apply the final Saloon URI, headers, cookies, auth, TLS/certificate settings, and validated Guzzle options;
4. attach the prepared Saloon body stream through Hypervel HTTP's raw-body method;
5. add one request middleware that invokes Saloon's final PSR request hook after previously registered Hypervel request middleware and records the final application-owned request on the Saloon pending request before Guzzle adds automatic transport headers;
6. send once and wrap the resulting Hypervel response.

```php
$httpRequest = $this->http->createPendingRequest()
    ->connection(
        $connector->resolveHttpConnection()
            ?? $this->config->string('saloon.connection.name')
    )
    ->withOptions([
        ...$this->transportOptions($pendingRequest),
        'delay' => 0,
        'http_errors' => false,
    ]);

if (($body = $pendingRequest->preparedBody()) !== null) {
    $httpRequest->withBody($body, null);
}

$httpRequest
    ->withHeaders($pendingRequest->headers())
    ->withRequestMiddleware(function (RequestInterface $request) use ($pendingRequest) {
        $request = $pendingRequest->handlePsrRequest($request);
        $pendingRequest->notifyPsrRequestObservers($request);
        $pendingRequest->setPsrRequest($request);

        return $request;
    });

$response = $httpRequest->send(
    $pendingRequest->method()->value,
    (string) $pendingRequest->uri(),
);
```

Do not configure Hypervel HTTP retries or asynchronous mode here. Force transport delay to zero and `http_errors` to false after applying validated options: the Saloon manager owns delays and response exceptions, so framework-global HTTP defaults cannot introduce a second lifecycle. Hypervel HTTP must perform exactly one transport attempt per Saloon attempt.

### Body mapping

Retain Saloon's body contracts, repositories, and familiar traits (`HasJsonBody`, `HasFormBody`, `HasMultipartBody`, `HasXmlBody`, stream/string bodies). After ordinary request middleware has finished mutating repositories, materialize the body once into an operation-owned PSR stream and retain it on PendingRequest. Body repositories use Guzzle PSR-7 directly rather than receiving a configurable factory:

```php
public function toStream(): StreamInterface;
```

| Saloon body | Prepared bytes |
|---|---|
| JSON repository | JSON encoded once with the repository's configured flags; `false` is a `BodyException`, never an empty payload |
| form repository | URL-encoded values normalized and encoded once with `http_build_query()` |
| multipart repository | one `MultipartStream` preserving its boundary, filenames, per-part headers, and value order |
| string/XML repository | the exact string bytes |
| stream repository | the original resource/PSR stream |

Pass that stream to Hypervel HTTP as a raw body and apply the already-merged Saloon headers afterwards, so body traits and user middleware remain the content-type authority. The same prepared bytes back cache identity, fake/cached PSR requests, debugging, and the network send; do not independently encode a repository on those paths. Never pre-read a stream to make it repeatable. Record its current offset when possible; if the final PSR hook replaces it, record the replacement stream's offset before transport. The retry rules below restore the actual attempt body or reject an unsafe non-seekable retry. Mark credentials, tokens, certificate passphrases, and raw authorization values with `SensitiveParameter` where signatures expose them.

Normalize the merged header repository once on PendingRequest before any final PSR construction. Match Hypervel HTTP's scalar, null, `Stringable`, and arrays-of-those contract, including its non-finite float strings; reject nested arrays and unresolved objects/resources. This makes direct fake/cache requests and network requests accept and reject the same values without adding a public header abstraction.

Use one internal `StructuredDataNormalizer` for JSON bodies, form bodies, and repository query values. Recursively resolve Hypervel `Arrayable`, `JsonSerializable`, and `Stringable` values with the same precedence as Hypervel HTTP. JSON normalization leaves floats for the repository's configured JSON flags to govern; URL-encoded normalization converts non-finite floats to the same `NAN`/`INF`/`-INF` strings as Hypervel HTTP before PHP encoding and accepts only nested arrays of scalar or null terminal values. Reject unresolved objects/resources instead of relying on warning-driven PHP coercion. This keeps Laravel-style values and fake/cache/network bytes consistent without exposing another public abstraction.

Translate a thrown JSON encoding error, or a `false` result when custom flags disable throwing, into `BodyException` while preserving the original exception when one exists. Never turn an encoding failure into an empty request body.

Correct the repository contracts while adapting this boundary: string bodies treat only null and the empty string as empty (`"0"` is content); a null stream value becomes an empty seekable stream instead of reaching a resource-only factory; `MultipartBodyRepository::get()` returns `mixed` because its caller-supplied default is unconstrained; and finite numeric multipart values are converted to strings before `MultipartStream`, avoiding Guzzle PSR-7's deprecated scalar conversion. Reject non-finite numeric multipart values rather than emitting unstable warning-driven text.

### Final request URI

Keep Saloon's base-URL/endpoint join contract, but validate transport URLs through PSR URI components rather than `FILTER_VALIDATE_URL` or underscore substitution. A connector base URL must be an absolute HTTP(S) URI; `NullConnector` may use an empty base with an absolute HTTP(S) request endpoint. `Connector::allowsBaseUrlOverride(): bool` returns false, while `Request::allowsBaseUrlOverride(): ?bool` returns null to inherit; `SoloRequest` and trusted OAuth requests override the method rather than mutating a public flag. With a non-empty base, reject absolute endpoints unless the request, connector, or immutable OAuth config explicitly allows the trusted override. This preserves relative path joining while closing alternate-scheme and credential-redirect paths at the shared owner.

Build the final URI once after ordinary request middleware and use it for the sender, direct fake/cache PSR construction, cache identity, events, and response request access. Do not pass a separate Guzzle `query` option: it replaces the endpoint's existing query string.

Retain Saloon's repository-over-endpoint precedence without its lossy `strtok()` parser. Split the existing raw query into pairs and decode only each name with query-string (`urldecode`) semantics. For every top-level repository key, remove raw pairs whose decoded name equals that key or begins with that key followed by `[`, so a scalar, null, empty array, or nested value replaces the same parsed query family. Retain all other raw pairs, then append `http_build_query($repository, '', '&', PHP_QUERY_RFC3986)`. This preserves untouched ordering, duplicate keys, valueless pairs, zero/false/empty-string names and values, and original encoding while retaining Saloon's top-level nested-array precedence. Attach the result through the PSR URI; do not add a general query parser or normalize untouched endpoint pairs.

### Final PSR request ownership

The application-owned request can change inside Hypervel request middleware after Saloon's logical repositories are built. The Saloon middleware is appended after the factory's global middleware, invokes the connector/request hook, and becomes the authoritative capture point for `Response::toPsrRequest()` and Saloon network event payloads. Guzzle then adds automatic transport headers without changing its URI or body. Do not reconstruct a network PSR request from stale Saloon state after the send.

Saloon fakes and cache hits return before Hypervel HTTP runs. For those paths, `PendingRequest::createPsrRequest()` constructs the logical request directly with Guzzle PSR-7 after all ordinary Saloon request middleware has completed, applies the connector/request PSR hook once, then notifies the same final-PSR observers; no lower-level middleware remains to change it. Use this request for the fake/cached Response and mock recording. This direct construction replaces the upstream PSR factory collection without losing the PSR request API.

### Named connection and hot transports

The provider registers one fixed connection, `saloon` by default, from `saloon.connection.name` and `saloon.connection.options`. `Connector::resolveHttpConnection(): ?string` returns null by default and may select another connection that the application registered at worker boot. It must not create connections or derive names from tenants, hosts, connector instances, or request data; those names would grow the worker's handler registry without a bound. Inject the config repository into Sender so connectors do not resolve framework config themselves.

Each request gets a fresh Hypervel pending request, Guzzle client, middleware stack, and cookie jar. The named connection reuses only the low-level handler, whose cURL handles retain keep-alive sockets, DNS state, and TLS sessions. This is the correct hot-connection mechanism. Do not use `hypervel/object-pool`: clients, stacks, and cookie jars are operation-local, while the reusable transport resource already has a narrower owner.

`saloon.connection.options` keeps Saloon's 10-second connect timeout and 30-second request timeout unless an application publishes different values. It enables Guzzle's best-effort handler transport sharing so reusable easy handles can share DNS and TLS-session state when the installed cURL supports it. Do not duplicate a TLS setting in Saloon config: Hypervel HTTP already sets TLS 1.2 as the minimum, while Guzzle negotiates TLS 1.3 when both peers support it. Pool concurrency bounds each explicit fan-out operation, while Hypervel rate-limiter policies can enforce per-tenant and service-wide request rates across workers and servers.

## HTTP transport corrections

With Hypervel's supported cURL transport, Guzzle accepts `max_host_connections` and `max_total_connections` only by selecting a bare `CurlMultiHandler`. A worker-shared bare multi handler cannot be driven concurrently by Swoole coroutines; doing so raises a fatal `Swoole\Error`. Hypervel HTTP currently accepts these keys in registered connections but forwards them as ineffective request options. Reject both keys at the HTTP-owned boundary rather than advertising a silent no-op or enabling an unsafe handler.

Keep handler construction limited to transport sharing and the safe `multiplex` handler setting:

```php
$handlerOptions = Arr::only($config, [
    'transport_sharing',
]);

if (($config['multiplex'] ?? null) === Multiplexing::NONE) {
    $handlerOptions['multiplex'] = Multiplexing::NONE;
}
```

`multiplex` remains in request options for every mode; only `Multiplexing::NONE` also configures handler selection. Remove transport sharing from request options. Add both cap keys to `ReservedOptions`' unconditional rejection set so registered connections, global options, fluent `withOptions()`, raw request options, and per-send overrides fail with guidance to use bounded coroutine fan-out or the rate limiter. Do not add a package-owned aggregate concurrency semaphore without a concrete API contract that requires one.

Registered asynchronous requests expose a separate framework defect. `PendingRequest::buildHandlerStack()` currently gives every request on a named connection the same worker-lived handler. The synchronous path safely uses its private easy-handle branch, but asynchronous requests drive the shared multi-handler and concurrent coroutines can fatal. Use the registered handler only when `! $this->async`; asynchronous requests build a fresh handler, while synchronous requests retain warm connection reuse. Add concurrent registered-async and sequential registered-sync regressions and correct the HTTP connection documentation.

Update `tests/Http/HttpConnectionTest.php`, `tests/Integration/Engine/HttpClientConnectionTest.php`, and `src/docs/http-client.md` with safe handler forwarding, request-option separation, cap rejection at every entry point, registered-async isolation, synchronous reuse, and reconfiguration invalidation.

Hypervel HTTP currently runs Guzzle's `prepare_body` middleware before registered request middleware and `beforeSending()` callbacks. A supported callback that replaces the PSR body can therefore retain the old automatic `Content-Length`, `Transfer-Encoding`, or `Expect` headers; replacing a 17-byte JSON body with a one-byte stream currently sends `Content-Length: 17`. Fix the owner rather than special-casing Saloon: remove `prepare_body` from the default stack and reinsert it after user middleware and before the recorder, stub, and transport handlers. Keep prepared-body identity tracking before user middleware so body replacement still invalidates logical request data. This preserves middleware order, makes every supported PSR body replacement safe, and lets Saloon's PSR hook run once before final body preparation. Add HTTP regressions for request middleware and `beforeSending()` body replacements, explicit caller-supplied content headers, request-data invalidation, fakes, and real handler capture.

Hypervel's `PendingRequest::withBody()` currently always writes a content type. Widen its second parameter to `?string`, retaining `application/json` as the default and skipping `contentType()` only when the caller explicitly passes null. This lets Saloon attach its prepared raw stream without inventing a content type; the Saloon header repository is applied afterwards. Add focused HTTP tests and document the nullable form. Existing Laravel-style calls are unchanged.

Add a protected `Hypervel\Http\Client\Response::newRequestException(): RequestException` factory and use it in `toException()`, `throwIfStatus()`, and `throwUnlessStatus()`. The default returns the existing framework exception. Saloon Response overrides it to return its client, server, or general request exception without copying the status-specific throwing methods.

```php
protected function newRequestException(): RequestException
{
    return new RequestException($this, $this->truncateExceptionsAt);
}
```

This is a protected extension seam for response subclasses, not a second exception pipeline. Saloon must override `throw()` narrowly because its supported `shouldThrowRequestException()` hook may suppress an exception for a response that still reports `failed()`; the inherited framework method assumes those two decisions cannot differ and would otherwise execute `throw null`. Gate on `toException()` instead, return the response when it is null, and preserve the framework callback signature when it is present. Test suppression plus the unchanged framework exception type and Saloon's general, 4xx, and 5xx selections through every throwing method.

## Request orchestration

Retain Saloon's higher-level middleware pipeline because it operates on connectors, requests, DTOs, fakes, cache entries, and rate policies rather than raw HTTP messages. Keep named pipes, ordering, request/response/fatal phases, connector and request middleware hooks, plugin boot hooks, authentication, and custom failure handlers. Retain `SoloRequest` and its `NullConnector` for one-off calls that do not need an integration connector; they use the same sender and lifecycle as normal requests.

Constructing a PendingRequest only assembles operation state and applies defaults. It must not boot plugins, consume a fake or cache entry, inspect or reserve rate-limit state, sleep, dispatch sending/sent events, or touch the network. Every hook and effect begins in the manager's send loop, so callers may inspect or further customize a pending request safely before sending it.

Store pipes in first/default/last buckets when they are registered and maintain a name set for duplicate detection. Processing then walks the three buckets directly without repartitioning and allocating a merged array on every attempt; flatten only for the infrequent pipeline-copy API. Preserve insertion order inside each bucket.

The manager's send loop owns this order for every attempt while the connector remains the public entry point. Cache, fake, admission, delay, and event handling are fixed terminal phases, not ordinary user-reorderable pipes:

1. create and initialize a fresh PendingRequest;
2. copy the connector's stable defaults into the fresh PendingRequest, then merge request-owned properties;
3. apply authentication and boot cached plugin methods;
4. validate the request and retry/body rules;
5. merge worker-global middleware into the operation pipeline;
6. run ordinary connector, request, plugin, and worker-global request middleware;
7. materialize the operation body once, then match an explicit fake before cache lookup and inspect the server cooldown before configured admission policies; skip admission, delay, and transport once a fake or cache hit supplies a response;
8. use `Sleep` for configured delay only when the network will be called;
9. dispatch `SendingSaloonRequest` only when the dispatcher has matching listeners;
10. call the sender once or use the short-circuit response;
11. before user response middleware, record a cooldown and write an eligible successful cache entry only for a response returned by the sender, then dispatch `SentSaloonRequest` for every response when listeners exist; Saloon fake and cache-hit responses never populate or refresh the cache;
12. run response/fatal middleware and evaluate error handling and the Saloon retry policy.

Retry delays, including a closure-defined exponential backoff, must use checked integer arithmetic and `Sleep::usleep()`/`Sleep::sleep()`. Each attempt gets a fresh PendingRequest so middleware and merged repositories run again. Capture the starting offset of the actual attempt body: the final PSR body after the connector/request hook when one was built, otherwise the prepared body. Before discarding a failed attempt, restore that seekable stream to its captured offset; this also rewinds seekable multipart parts. The next attempt then rematerializes from the reset repositories rather than reusing stale prepared bytes. If the failed attempt consumed a non-seekable body, throw `BodyException` before retrying. Preserve the original exception as `previous` when translating a transport failure into `FatalRequestException`. Remove the upstream destructor/closure resource workaround when no connector-owned Guzzle client remains.

Hypervel HTTP continues to dispatch its own lower-level events and record real requests in Telescope. Saloon events describe the domain-level operation, including cache/fake short circuits. Do not add duplicate Telescope middleware or dispatch HTTP events for requests that never reached HTTP.

## Responses and data objects

`Hypervel\Saloon\Http\Response` extends `Hypervel\Http\Client\Response`. Removing Saloon's conflicting repository-first response signatures lets it inherit the complete Hypervel HTTP surface directly: body and JSON decoding, headers, cookies, status predicates, transfer metadata, throwing, ArrayAccess, macros, PSR access, and string conversion. Add only Saloon-owned `request()`, `connector()`, `pendingRequest()`, and `toPsrRequest()` access plus sender exception, DTO, XML/DOM, export, debugging, and cached/mocked state. Do not forward or reimplement inherited methods.

Create it through `fromResponse(HypervelResponse $response, PendingRequest $pendingRequest, RequestInterface $request, ?Throwable $exception = null)`. Copy the framework response's PSR response, cookies, transfer stats, decoder, decoded state, and exception settings as `ApiResponse::createFrom()` already does, then attach Saloon's operation references. Custom response classes use the same factory with the current base Saloon response, so any body buffering and framework metadata survive class selection. Do not retain `fromPsrResponse()`.

Keep response buffering lazy. `body()` delegates directly for a seekable stream. For a non-seekable stream, read its remaining contents once, replace the inherited PSR body with a seekable stream, clear stale decoded state, and return the buffered value; every later body, stream, PSR, decode, cache, or middleware access then sees the same bytes. Seekable and never-read streaming responses do no extra work.

Implement file/resource export with Guzzle PSR-7 stream copying instead of a hand-written 1 KB `fwrite()` loop. A path is method-owned and is always closed in `finally`; a caller-provided resource follows `closeResource`. Capture and restore a seekable source's position around a full-body copy. Rewind and truncate a seekable destination before writing, then rewind it for a caller that keeps it open; non-seekable streams continue from their current positions. Preserve partial-write handling through the PSR stream utility and do not close a caller-owned resource when copying fails unless the caller requested it.

Keep Saloon's custom response classes, XML/DOM helpers, DTO creation and `dtoOrFail()`, `WithResponse`, cached/mocked flags, and debug helpers. Inherited `json()`, `object()`, `collect()`, `fluent()`, status predicates, throwing helpers, ArrayAccess, and `__toString()` are authoritative. `setCached(bool)` and `setMocked(bool)` must honor both `true` and `false`.

Do not port `Debugger::$dieHandler`. It exists only to let upstream tests intercept `exit` and would be mutable process-global state in a Hypervel worker. The `die: true` path exits directly and is covered in an isolated subprocess. Expose `debugRequest()`, `debugResponse()`, and `debug()` on mutable Request/PendingRequest, not Connector; a connector that always enables debugging does so in `boot()`. Register request debuggers as operation-owned final-PSR observers rather than constructing an early PSR request inside the Saloon middleware pipeline; invoke them after the connector/request PSR hook on both network and short-circuit paths. This preserves the public callback while preventing PSR hooks from running twice. The default debugger deliberately shows raw headers and seekable body content, reading from and restoring the stream's current position so debugging cannot change transmitted or later-readable bytes; show a non-seekable marker instead of consuming a request stream before transport, while a non-seekable response is replaced by its lazy buffered form. Generic redaction would make debugging incomplete and cannot identify arbitrary body secrets. Document that output can expose credentials, that custom callbacks own any stream reads, and that `die: true` terminates the current worker and is suitable only for local debugging.

Hypervel HTTP's decoded cache accepts valid scalar JSON, so Saloon must not add another decoded cache. Correct `Hypervel\Http\Client\Response::object()` to return `mixed`: native `json_decode()` and the supported custom decoder can return scalar values, while the current `array|object|null` declaration throws for valid scalar JSON. Add framework regressions for scalar JSON and custom decoder values.

Create the normal Saloon Response first, pass it to the request's `resolveResponseClass()` and then the connector's resolver, and return it directly when neither selects another class. When a valid `class-string<Response>` is selected, call its `fromResponse()` factory with that current base response and the operation references. A resolver that reads a non-seekable body therefore buffers it before subclass creation. The custom-response path adds one allocation; the normal path does not add a second wrapper.

Remove the curated status-specific exception classes. `Hypervel\Saloon\Exceptions\Request\RequestException` extends the framework RequestException and exposes `status()` plus PendingRequest access. `ClientException` and `ServerException` retain useful 4xx/5xx catch boundaries, and `FatalRequestException` remains the transport-failure type. Saloon Response overrides `newRequestException()` to select those three response exception types. Framework `DeterminesStatusCode` predicates work on the exception through `status()`; unlisted codes remain available through the integer status rather than requiring one class per code.

Carry user-defined DTO types through static analysis without adding a runtime DTO contract. `Request` is `@template TDto`, and `PendingRequest` and `Response` carry the same template. `Connector::send()` maps `Request<TDto>` to `Response<TDto>`, while `Response::dto()` and `dtoOrFail()` return `TDto`; annotate every forwarding boundary so the template is not dropped. Internal and heterogeneous pool surfaces use `mixed` where one exact DTO type cannot be guaranteed. Users may return any value from `createDtoFromResponse()` and opt into object inference with `@extends Request<UserData>`; no base class, serializer, or DTO package is required. Add a `types/Saloon` fixture proving the complete request-to-DTO inference chain. Use a `Hypervel\Support\DataObject` subclass for one guide example, while stating explicitly that it is optional and that plain classes or any other DTO implementation work through the same method.

## Coroutine-native pool

Replace the Guzzle promise pool with `Hypervel\Saloon\Http\Pool`. Keep the familiar ability to supply an iterable of keyed Request instances or a producer receiving the pool's Connector and returning that iterable, plus response and exception handlers. Reject any other item before trying to send it. `Connector::pool()` and the Pool constructor retain Saloon's default concurrency of 5 but accept only a positive integer; callable concurrency policies add complexity without a verified use that cannot be expressed by choosing a limit before sending. Keep concurrency explicit on each pool rather than adding a duplicate package setting. Use framework admission policies for per-tenant and service-wide request rates; do not present them as aggregate in-flight connection limits.

Within a coroutine, create `WaitConcurrent($concurrency)` and call `fork()` for each item so request, tenant, log, and other application context propagates to each child. The manager's tests-only mock is already shared by object identity and needs no context entry. Iteration blocks when the bound is full, so a large lazy iterable does not create an unbounded callback queue. Array writes and handler invocation remain inside the child; always join before returning.

Response and exception handlers execute in their request's child coroutine. Document this so handlers that update shared application state use an appropriate coroutine-safe service; the pool must not serialize handlers and erase the concurrency benefit.

```php
$position = 0;
$orchestrationFailure = null;

try {
    foreach ($this->requests() as $key => $request) {
        $inputPosition = $position++;

        $concurrent->fork(function () use ($inputPosition, $key, $request): void {
            try {
                $response = $this->sendRequest($request);
            } catch (Throwable $exception) {
                if ($this->exceptionHandler === null) {
                    $this->failures[$inputPosition] = [$key, $exception];
                } else {
                    try {
                        ($this->exceptionHandler)($exception, $key);
                    } catch (Throwable $callbackException) {
                        $this->failures[$inputPosition] = [$key, $exception];
                        $this->callbackFailures[$inputPosition] = [$key, $callbackException];
                    }
                }

                return;
            }

            if ($this->collectResponses) {
                $this->responses[$inputPosition] = [$key, $response];
            }

            try {
                ($this->responseHandler)?->__invoke($response, $key);
            } catch (Throwable $callbackException) {
                $this->callbackFailures[$inputPosition] = [$key, $callbackException];
            }
        });
    }
} catch (Throwable $exception) {
    $orchestrationFailure = $exception;
} finally {
    $concurrent->wait();
}
```

`send(): array` resets prior results and returns responses in input iteration order with the same keys. Track each item by a monotonic input position internally, pass the iterable key to callbacks, then rebuild keyed results after joining; completion order must not affect the result, even when a generator repeats a key. Normal PHP array semantics apply when rebuilding: the later input position wins a repeated key. Always join started children when producing or scheduling later items throws. After every child joins, throw `PoolException` with that orchestration failure, keyed send failures, callback failures, and partial responses when any operation failed. When an exception handler handles a send failure, that key is omitted from the returned responses. In collecting mode, a successful response remains available as partial work when its response handler throws. Expose read-only accessors on `PoolException`; do not hide any cause or throw before other children are joined.

Add `process(): void` for large or continuously produced lazy iterables whose response handler owns each successful response. It runs the same private orchestration with response collection disabled; successfully handled send failures are discarded immediately, while unhandled failures and callback failures remain available for the final exception. Do not implement a second scheduling path. This keeps successful-result memory bounded rather than forcing every response into an array. `send()` is the Laravel-style collecting API and has memory proportional to its returned results.

For normal CLI code outside a coroutine, enter one coroutine with `Hypervel\Coroutine\run()`, capture the result or exception, and execute the same implementation. Manager-backed test state remains visible to the root and its children without context copying. Do not add a slower sequential fallback. Validate concurrency as a positive integer.

## OAuth 2 and authentication

Port Saloon's authenticators and OAuth 2 grants, token requests, token response, refresh/client-credentials/authorization-code flows, PKCE support, and token helpers. Do not port OAuth 1. Treat only absolute HTTP and HTTPS endpoints as valid transport URLs; other schemes must not pass the base-URL override path.

Replace the mutable OAuthConfig builder with a final readonly value whose named constructor arguments hold client credentials, redirect URI, authorize/token/user endpoints, default scopes, optional request modifier, and the trusted absolute-endpoint flag. A connector lazily memoizes its immutable default config; a tenant connector derives it from readonly constructor state. There are no setters or request-time config mutation. Built-in authenticators are readonly values as well.

Use immutable dates for held expiry values and capture every date modifier result. Mark client secrets, access/refresh tokens, passwords, certificate passphrases, and token-exchange inputs as sensitive. Preserve Saloon's base-URL override guard so credentials cannot be redirected to arbitrary endpoints unless a connector, request, or OAuth config explicitly opts into an application-controlled absolute endpoint through its stable resolver/value; do not port the public mutable override flags.

Do not store generated OAuth state on a connector. One reused connector can serve concurrent authorization flows, so a mutable `$state` property lets one flow overwrite another. Return an immutable value containing both outputs:

```php
final readonly class AuthorizationUrl implements Stringable
{
    public function __construct(
        public string $url,
        public string $state,
    ) {
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
```

`authorizationUrl()` returns `AuthorizationUrl`. The application stores its `state` in session/database state and supplies the expected and returned values to the token exchange. When either state argument is supplied, require both non-empty strings and compare them with `hash_equals()`; passing only one must fail rather than silently disabling validation. This keeps multi-tab and concurrent flows independent without coupling Saloon to sessions.

Build authorization query strings without blanket `array_filter()`: valid falsey scope/additional values must not disappear. Protocol-owned keys (`response_type`, `client_id`, `redirect_uri`, `state`, and `scope` when present) take precedence over the additional-parameter array, whose purpose is to add provider fields rather than replace the validated OAuth request.

## Fakes, fixtures, and assertions

Port request matching, response sequences, closure responses, wildcard URL matching, mock response builders, recorded requests/responses, fixtures, redaction hooks, and non-deprecated assertions. The global facade and `MockClient::global()` both delegate to the manager's tests-only state. Saloon fakes take precedence and populate Saloon's mocked flag, recordings, and assertions. A lower-level Hypervel HTTP fake still prevents transport and remains visible through HTTP's own recorder, but it does not pretend to be a Saloon `MockClient` response.

An unmatched MockClient remains strict by default. Give it the familiar `preventStrayRequests(bool $prevent = true)` and `allowStrayRequests(?array $only = null)` controls: allowing null permits any unmatched request to continue through the normal cache/admission/send lifecycle, while an array permits only matching URL patterns. The manager owns this branch after the mock lookup; the response matcher returns a nullable fake and never returns a PendingRequest sentinel or widens the fake-response union. Document the array as `list<string>` and match it against the final logical URI.

`assertSent()` closures may type their first parameter with a request class or class union. Filter recorded requests by those types before invoking the closure, using Hypervel's existing `ReflectsClosures` support; untyped callbacks retain their current behavior. Reflection occurs only when a test assertion runs, and no package-specific reflection parser or production-path work is added.

Use a configured default fixture directory and safe nested fixture names. Replace upstream's broad normalization machinery with a direct relative-name contract: non-empty portable path segments containing letters, digits, `.`, `_`, `-`, `=`, or `&`, separated by `/`, with `.` and `..` rejected as whole segments. Convert `/` to the platform separator only after validation and append `.json`. This retains organized and query-derived names such as `pagination/per-page-limit=5&page=4` while rejecting absolute paths, backslashes, null bytes, and traversal. Deliberate symlinks inside a developer-owned fixture directory are not an untrusted-input boundary.

Store a fixture as typed status, headers, body, and context. Redact configured headers and JSON/body patterns before writing. Use the framework `Filesystem` directly and its atomic `replace()` method after creating the parent directory; do not port Saloon's package-owned Storage wrapper. Because users construct fixtures directly, accept an optional Filesystem in the constructor and resolve the stateless concrete from the container only when none is supplied. Tests must use `ParallelTesting::tempDir()` and own cleanup.

## Cache module

Place caching under `Hypervel\Saloon\Cache`. Keep the `Cacheable` contract, request-only `HasCaching` trait, and cached response value, but remove upstream request/recorder middleware classes, driver abstractions, and Flysystem/PSR/Laravel cache adapters. The manager performs lookup after ordinary request middleware and records a real successful response before ordinary response middleware. Use the `Hypervel\Contracts\Cache\Factory` injected into the pending request by the manager and work with its repository directly.

The cacheable resource supplies:

```php
interface Cacheable
{
    public function cacheFor(): DateInterval|DateTimeInterface|int;

    public function cacheStore(): UnitEnum|string|null;
}
```

`HasCaching` provides `cacheStore()` returning `null`, so a request that also implements `Cacheable` supplies only `cacheFor()`. A request may instead use the trait without that contract solely to control caching supplied by its connector. A contract-only request implements both contract methods explicitly. A request-level `Cacheable` resource supplies the duration before a connector-level one. Store selection uses the first non-null request, connector, then `saloon.cache.store` value; a null package default uses the framework's default cache store.

Keep `enableCaching()`, `disableCaching()`, and `invalidateCache()` on mutable Request only. Their state is copied into PendingRequest for one operation; a request may therefore bypass or invalidate caching supplied by its connector without mutating that connector. Connector caching is declarative: implement `Cacheable`, override the Connector base's protected cache-key/cacheable-method hooks when needed, and use `boot()` for operation-specific behavior. The base copies those hooks into the operation while applying connector defaults. Request `HasCaching` provides matching nullable overrides: a non-null request key/method list wins, otherwise the connector hook and then package defaults apply. Do not add a registry or a second cache pipeline.

Default cacheable methods are GET, HEAD, and OPTIONS. Cache successful responses only. Store one immutable payload containing status, headers, and body; the framework repository owns TTL and serialization, so do not duplicate expiry timestamps or cache-driver wrappers.

The default key includes connector class, request class, method, final logical URI, normalized headers, cookies, dedicated authentication/certificate state, prepared body bytes, and normalized transport options that can change the successful response (`allow_redirects`, certificates/keys, content decoding, `curl`, `expect`, IP-family/IDN selection, proxy, TLS verification/crypto method, and HTTP version), then hashes the canonical representation once with SHA-256. Omit only timing, diagnostics, observation callbacks, and execution-shape controls that cannot change successful response bytes or metadata. This cache key is a response-isolation boundary over attacker-controlled and tenant-specific values, so collision resistance matters more than the small CPU saving from an internal checksum. Lowercase and sort header names while preserving each header's value order; sort other associative maps recursively without reordering lists. Export cookie jars to stable cookie fields and reject any default identity value that cannot be represented safely. Only a package prefix and hash enter the backend. A custom `cacheKey()` replaces the canonical identity material but is still hashed, keeping backend keys bounded and preventing raw secrets from becoming key names. For a seekable prepared stream, hash from its current logical contents and restore the original position. Require a custom key for a non-seekable body rather than consuming it.

Reject caching when Guzzle's `sink` option is configured. A cache hit cannot reproduce its file/resource write side effect without duplicating transport behavior inside the cache layer; callers that need both features should export the returned Saloon response explicitly. Streaming remains supported, but cacheable responses are buffered by definition.

The default key is deliberately owned by the fully merged Saloon request before the transport boundary. A custom PSR hook or global Hypervel HTTP option/middleware that changes response identity after that point must also override the applicable connector/request cache-key hook; attempting to reproduce the lower transport layer during a cache lookup would duplicate side effects and make cache hits execute part of that stack. Document this constraint next to custom PSR middleware and lower HTTP customization. Cache-key work runs only when connector or request caching is enabled.

Do not add a cache lock or stampede protocol without a verified requirement; it changes failure and latency behavior and duplicates cache-store concerns.

## Pagination module

Place API pagination under `Hypervel\Saloon\Pagination`. It is not the same domain as `hypervel/pagination`, which represents application/UI pagination over local data. Port `Paginator`, `PagedPaginator`, `OffsetPaginator`, `CursorPaginator`, and the pagination contracts using Hypervel Collections and LazyCollection. Do not retain `HasAsyncPagination`, its mutable `async` flag, promise unions, or trait-detection branches. The coroutine-native `pool()` method belongs directly on `Paginator`; synchronous iteration remains synchronous and precisely typed.

Synchronous iteration fetches one page at a time and retains only current iterator state. Reset every iterator field on rewind. Use `xxh128` body checksums to detect an API repeatedly returning the same page without creating temporary resources. Preserve maximum-page, per-page, mapping, cursor, empty-result, and response-access behavior. Remove deprecated `$page` and `getPage` accessors.

`pool()` uses the coroutine Saloon pool, not promises. Fetch the first page to determine the range, then lazily schedule the bounded remaining page set with the selected concurrency. `getTotalPages()` has a default exception for APIs that cannot know a finite range; a paginator supporting pooled pagination overrides it. Preserve keyed page responses and the pool's complete error semantics; never enqueue beyond the configured maximum page.

## Rate-limit module and framework cooldowns

### Client-side policies

Place integration code under `Hypervel\Saloon\RateLimit`. Do not port Saloon's mutable `Limit`, bucket, file/memory/Predis/PSR/cache stores, or read-modify-write persistence. Accept Hypervel `AdmissionPolicy` objects and resolve a `Hypervel\RateLimiter\Limiter` from the configured store.

`HasRateLimits` supplies these hooks:

```php
/** @return list<AdmissionPolicy> */
abstract protected function resolveRateLimits(): array;

protected function resolveRateLimitStore(): UnitEnum|string|null
{
    return null;
}

protected function waitForRateLimits(): bool
{
    return false;
}
```

Before the network send, atomically `consume()` each policy under a stable limiter name derived from the resource class. Run admission after fake/cache lookup and skip it for a short-circuit response, which must not spend external API capacity. A denied result either throws `RateLimitReachedException` containing the policy/result or sleeps for its `retryAfter()` and retries that same policy when waiting is enabled. Continue to the next policy only after the current one allows the request, so an earlier successful reservation is never consumed twice. Use `Sleep`; never poll more frequently than the store's returned delay.

A connector and its request may both use `HasRateLimits`. Apply both sets—connector policies in declaration order, then request policies—rather than silently choosing one owner. Each resource keeps its own store, wait, cooldown-key, and response-cooldown hooks; the default class-based keys make connector cooldowns integration-wide and request cooldowns endpoint-specific.

Reject policies configured with `after()` or `response()` callbacks. Those hooks belong to server middleware that can inspect an already-produced response or synthesize one; applying them here would either abandon atomic pre-admission or invent a second fake-response contract. Saloon response middleware and exception handling already own those use cases. The accepted policies therefore remain immutable admission definitions with their normal `by()`, `cost()`, and `globally()` modifiers.

Multiple policies are independent reservations. Consume them in declaration order. If a later policy denies, earlier successful policies remain consumed; a cross-key distributed transaction would add greater complexity and contention than the behavior warrants. Document this so callers put broad/cheap policies first.

### Server-directed cooldown

An HTTP 429 `Retry-After` is an arbitrary cooldown duration discovered after a response. Existing `Limit` identities include window parameters and `Backoff` computes exponential delays, so neither can represent a stable dynamic server cooldown. Add the missing primitive to `hypervel/rate-limiter` rather than storing ad hoc cache state in Saloon.

Add:

```php
$cooldown = Cooldown::for($resourceKey);

$result = $limiter->block($cooldown, $seconds, $limiterName);
$decision = $limiter->inspect($cooldown, $limiterName);
$limiter->clear($cooldown, $limiterName);
```

`Cooldown` is an immutable key value whose identity excludes duration. `CooldownResult` implements `Decision`, is denied while an unexpired block exists, and exposes `retryAfter()`; an absent or expired block is allowed with zero retry time. `Limiter::block()` returns that result, validates a positive representable duration, and atomically extends the stored expiry to `max(existing expiry, now + duration)`; a shorter concurrent response cannot release a longer cooldown. Extend `Store` with the typed block operation, include Cooldown in `inspect()`/`clear()` typing and `KeyResolver` identity, and update the RateLimiter facade metadata for the new method and return unions.

Implement the primitive in every first-party store at the store's authoritative clock:

- Worker array and Swoole table: update the expiry-only state atomically under the existing store synchronization model.
- Database: lock the row inside the existing transaction path and write the maximum database-clock expiry.
- Redis: use one Lua script with Redis time and `PEXPIRE`/expiry state so compare-and-extend is atomic.

Reuse existing numeric state columns; cooldown needs only `expires_at`, so no migration change is required. Add full store contract coverage, key stability, overflow validation, concurrent extension, inspection, expiry, clear, pruning, and fake-time tests. Update `src/docs/rate-limiter.md`.

For resources using `HasRateLimits`, inspect the transport response before user response middleware, then parse `Retry-After` as non-negative delta seconds or an HTTP date with immutable dates. A valid, representable future delay imposes the cooldown even if later middleware throws. Return the original 429 through normal response/error handling; cooldown detection itself must not recursively resend it. A later operation—or a new attempt explicitly requested by the normal retry policy—sees the shared cooldown and throws or waits according to the resource policy. Missing, malformed, unrepresentable, zero, or already elapsed delays do not create state.

Inspect the resource cooldown before consuming configured admission policies so a blocked operation does not spend capacity without reaching the network. `resolveRateLimitCooldownKey(PendingRequest)` returns the resource class by default and may include a stable tenant/account credential identity when the remote API limits those independently. `resolveRateLimitCooldown(Response)` returns the parsed 429 delay by default and may be overridden to recognize a provider-specific status, body, or header. Returning null records no cooldown. These two protected hooks replace the upstream mutable custom-response Limit callback without adding a second persistence model.

## Provider, configuration, facade, and commands

`SaloonServiceProvider` must:

- merge and publish `saloon.php`;
- bind the canonical `saloon` key to `SaloonManager` through a closure and alias the concrete manager, avoiding a canonical-alias resolution cycle;
- register the fixed named HTTP connection during worker boot;
- publish generator stubs;
- register commands only when running in console.

Configuration contains only real worker defaults:

```php
return [
    'connection' => [
        'name' => 'saloon',
        'options' => [
            'connect_timeout' => 10,
            'timeout' => 30,
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ],
    ],
    'cache' => ['store' => null],
    'rate_limiter' => ['store' => null],
    'fixtures' => [
        'path' => base_path('tests/Fixtures/Saloon'),
        'throw_on_missing' => false,
    ],
    'integrations_path' => app_path('Http/Integrations'),
    'integrations_namespace' => null,
];
```

Do not add per-tenant config mutations, arbitrary registries, a sender class option, or object-pool settings. Connectors carry dynamic tenant credentials/base URLs through their own constructor and hooks.

Generator commands write files to `integrations_path` and use `integrations_namespace` when it is set. A null namespace resolves to the application's root namespace plus `Http\Integrations`; never guess a namespace by converting an arbitrary filesystem path. Path and namespace remain independently configurable for applications with custom Composer mappings.

Port the Laravel plugin's established command names and prompts:

- `saloon:connector`
- `saloon:request`
- `saloon:response`
- `saloon:auth`
- `saloon:plugin`
- `saloon:list`

Generate into `App\Http\Integrations` by default and honor the configured path/namespace. Port stubs with Hypervel namespaces, strict types, native types, supported body/auth patterns, and no sender boilerplate. Test custom paths, namespaces, nested request names, OAuth connector selection, validation, listing, and published stubs against Testbench's disposable skeleton.

## Documentation

Write `src/docs/saloon.md` as a complete public guide, not a change log or implementation record. Cover:

- installation, publishing, and package discovery;
- reusable connectors, fixed-configuration dependency injection, per-operation tenant connectors and requests, methods including `QUERY`, endpoints, fluent headers/query/options/authentication, bodies, files, and the direct PSR-stream contract for custom body repositories;
- authentication and OAuth 2 state handling;
- request/response/fatal middleware and plugin traits;
- sending, the inherited Hypervel response API, Saloon response extensions, JSON/XML/DOM, typed user-defined DTOs, errors, retries, response-data-dependent custom responses, and the credential exposure of raw debug output;
- fakes, partial fakes, response sequences, fixtures, redaction, typed assertions, and stray-request prevention;
- bounded pools and context propagation;
- cache duration/store/key/invalidation behavior, buffering, and the cache-plus-sink restriction;
- API pagination and why it is separate from view pagination;
- admission policies, stores, waiting, 429 cooldowns, and multi-policy reservation behavior;
- events, macros, console generators, and extension points;
- named connection reuse, fixed connection names, bounded pool concurrency, rejected unsafe handler caps, the distinction between request rates and aggregate in-flight requests, and why object pooling is not used;
- coroutine/worker ownership, immutable OAuth/connector configuration, operation-only cache/debug controls, and security-sensitive URL/token behavior.

Use examples that compile against the final API. Avoid internal implementation language unless it explains a user-visible constraint. Add the guide to the docs navigation in the same location as HTTP/API integration documentation.

## Implementation sequence

1. **Inventory and skeleton.** Rebuild the source/test inventories from all five upstream packages. Create one implementation checklist entry per source and test file, marking each as port, merge, adapt, or deliberately omitted with its replacement. Add package/root Composer wiring through Composer commands for resolved dependencies, then the provider/config/facade skeleton.
2. **HTTP owner fixes.** Reject unsafe connection-cap options, isolate registered asynchronous handlers while preserving synchronous reuse, correct safe named-connection handler options, post-mutation body preparation, nullable raw-body content types, scalar object decoding, the response-exception factory, tests, and HTTP docs first so the Saloon sender can rely on the final transport contract.
3. **Core values and repositories.** Port enums (including `QUERY`), contracts, the reduced exception hierarchy, data values, internal header/query/option/body repositories, helpers still needed after framework substitutions, and authentication.
4. **Core operation path.** Port the stable Connector, mutable SelfBuilding Request, side-effect-free PendingRequest construction, Response subclass with exception suppression, Laravel-style fluent surface, middleware, retry/error handling, request-clone independence, response-data-dependent subclass selection, DTO generic propagation/type fixtures, plugin boot caching, manager state, events, and the concrete Sender. Run each new/changed test file immediately.
5. **Fakes and fixtures.** Port MockClient, strict and partial fake behavior, fake/recorded responses, sequences, fixture storage/redaction, typed facade assertions, manager-backed tests-only state, and subscriber-owned container cleanup.
6. **OAuth 2.** Port grant flows and token models using immutable dates and the AuthorizationUrl state contract.
7. **Pool.** Replace promise tests with bounded coroutine, context propagation, failure settlement, and non-coroutine entry tests.
8. **Cache and pagination.** Port each module against Hypervel repositories/collections and the new pool, deleting replaced plugin drivers and promise code rather than leaving adapters.
9. **Cooldown and rate limits.** Add and verify Cooldown across every framework store, then implement the Saloon rate-limit trait and 429 handling on it.
10. **Console and documentation.** Port commands/stubs, write the guide and minimal README, update navigation and both package metadata locations.
11. **Completeness sweep.** Compare every upstream source/test symbol against the inventory; verify removed framework-neutral/deprecated classes have no consumers, no references rooted in the upstream `Saloon\` or `Illuminate\` namespaces, and no Laravel adapter, promise, sender factory, plugin driver, OAuth 1, mutable connector request/debug/cache/auth/retry state, mutable OAuth setters, or stale namespace references remain.

During implementation, copy recognizable ported files alphabetically, one at a time before adapting them, as required by `AGENTS.md`. Use the complete upstream test inventory as a behavior checklist. Port tests when the source/API remains recognizable; write focused Hypervel tests covering the same cases when a framework-neutral class or public surface is replaced. This changes test structure, never the coverage obligation. Read each full source or test reference and run every new or changed test file immediately.

## Testing plan

### Core and framework integration

Place unit/package tests under `tests/Saloon` and external HTTP tests under `tests/Integration/Saloon` using `InteractsWithServer`. Add the integration directory to `engine.yml`.

Cover:

- Worker-reused connectors with fixed configuration, explicitly constructed tenant connectors with isolated readonly configuration, connector injection into a default worker-shared controller, fresh Request resolution at an operation call site, honored explicit bindings, direct construction, side-effect-free pending construction, independent cloning of every initialized mutable request repository/pipeline/cookie/retry/cache-control value, and repeated sends that create independent pending state.
- Property merge precedence, authentication, the full shared Hypervel HTTP fluent surface, all supported methods including body-bearing `QUERY`, HTTP(S)-only base/endpoint joining, endpoint/repository query merging (duplicates, zero/false/empty-string names and values, valueless pairs, exact and nested-family overrides, literal bracket keys, plus/percent-encoded names, nested/Arrayable/JsonSerializable/Stringable/non-finite values), header normalization, body formats, structured JSON/form/query normalization, string `"0"`, empty/null streams, custom JSON flags, multipart boundaries/metadata/numeric normalization/defaults, one-time prepared-body identity across fake/cache/network paths, stream ownership, cookies, accepted transport options, rejected request-shaping/authentication/error options, TLS/certificates, stable base-URL override resolvers, final PSR middleware replacement, and one HTTP attempt per Saloon attempt.
- Response inheritance without duplicated framework methods, scalar/array/object JSON, invalid JSON, seekable and lazily buffered non-seekable streams, retained framework response metadata, exception-safe path/resource export and ownership, inherited reason/header/status/ArrayAccess helpers, response-data-dependent custom response selection (including a resolver that buffers a non-seekable body before selecting a subclass), general/client/server exception selection through every throwing method, DTO runtime behavior and static type inference, exception chains, cached/mocked false resets, and XML/DOM.
- Request/PendingRequest debugging ownership, connector boot customization, request/response debugging order, one PSR-hook invocation, custom callbacks, seekable-position preservation, non-consuming request-stream markers, buffered non-seekable response bodies, raw-output credential warning, and the direct-exit path in an isolated subprocess with no process-global test hook.
- Middleware ordering/names/replacement, fixed terminal-phase ordering after ordinary request middleware, plugin boot order/inheritance, cached metadata, global boot middleware, listener guards, retries/backoff, per-attempt rematerialization after seekable prepared/final-hook stream and multipart restoration, non-seekable retry rejection, and `Sleep` fakes.
- Saloon sending/sent events exactly once for sender, fake, and cache-hit responses; real Hypervel HTTP event/Telescope visibility without duplicate Saloon instrumentation on short-circuit paths.

### Worker and coroutine safety

- One reused connector can send concurrent requests with different operation headers, authentication, middleware, cookies, and mocks without shared state; separately constructed tenant connectors keep their readonly credentials and base URLs isolated.
- Global middleware persists intentionally from boot; manager-backed global mocks, stray settings, and fixture overrides persist only for the current test and are removed by the subscriber's container reset. Explicit request-owned mocks remain operation-local.
- Pool children inherit ordinary application context and record on the manager's current test mock.
- Named handler identity is reused by synchronous requests while pending requests, clients, stacks, and cookie jars are fresh; registered asynchronous requests use fresh handlers and remain safe across concurrent coroutines.
- Real-server sequential sends through one named connection retain the same local connection after the first request, confirmed through transfer stats without a timing threshold; concurrent registered asynchronous sends complete without sharing a multi-handler.
- Macro and plugin metadata cleanup is owned by `AfterEachTestSubscriber`; direct `flushState()` tests verify every static property.
- A bounded set of connector/request classes produces a bounded metadata cache with no per-request keys.

### Pool

- Concurrency never exceeds the configured bound, including a large lazy generator.
- Pooled pagination clones requests whose query, headers, options, delay, middleware, body, cookies, retry policy, and cache controls were initialized before pagination without sharing later mutations.
- Direct iterables and connector-aware producers accept only Request items; input keys are retained, response/exception callbacks receive the correct key, all children join, and partial responses/failures are exposed.
- The no-handler path throws after settlement; the handler path returns successful keys; callback failures are preserved separately from send failures.
- A lazy producer or scheduler failure still joins every started child and remains available with partial responses and any child failures.
- `process()` retains no successful responses or successfully handled failures while consuming a large lazy iterable, and shares the exact scheduler/error path with `send()`.
- Parent application-context propagation and non-coroutine CLI entry both use the same behavior, including a manager mock configured before the root coroutine starts.

### Fakes and fixtures

- Exact/class/wildcard/callback matching, sequences, exhaustion, strict unmatched behavior, reversible all/URL-limited partial fakes through `preventStrayRequests()` / `allowStrayRequests()`, normal cache/network continuation for permitted unmatched requests, request recording, class- and union-typed assertion callbacks, pooled recording, fake precedence over an existing cache entry, and the distinct Saloon-versus-HTTP fake recorders/flags.
- Nested and query-derived valid fixture names, rejected traversal/absolute/backslash/dot-segment names, atomic writes, missing behavior, redaction, merge/through hooks, and parallel temp-directory isolation. Saloon fakes and cache hits must not populate or refresh cache entries.

### OAuth 2 and security

- Immutable OAuth config construction/memoization without setters, every grant, refresh, PKCE, expiry, state generation/validation, authorization-query falsey values/owned-key precedence, absolute endpoint policy, sensitive signatures, and concurrent authorization flows on one connector.
- The returned AuthorizationUrl keeps URL/state paired and no connector state remains.

### Cache

- Request-over-connector configuration, request-only cache controls over a stable caching connector, connector/request key hooks, package/framework default store selection, duration types, supported methods, successful-only writes, hits, false cached reset, invalidation, and expiry.
- Canonical map/list handling, case-insensitive header identity, header/cookie/auth/certificate/response-affecting transport-option isolation, prepared-body distinction, request-middleware mutations before lookup, custom PSR-middleware keys, unsupported identity-value rejection, custom keys, seekable position restoration, rejection of non-seekable default-key bodies, and the cache-plus-sink guard.
- Concurrent coroutine use against framework cache repositories without package-owned process state.

### Pagination

- Paged/offset/cursor/map behavior, first/last/empty pages, maximum pages, per-page values, mapping, repeated-body stopping, rewind/reset, exception propagation, and lazy memory behavior.
- Coroutine pool page bounds, keyed responses, concurrency, and failures.

### Rate limiter and cooldown

- Fixed/sliding/leaky/unlimited policies through Saloon, store selection, resource/key scoping, callback-policy rejection, threshold-free atomic admission, multiple policy order, denied exceptions, waiting with `Sleep`, fake/cache bypass, and no network call before admission.
- Retry-After seconds/date/invalid/past values, resource/tenant cooldown keys, provider-specific cooldown detection, shared 429 cooldown, cooldown-before-admission ordering, no cooldown-recursive resend, explicit retries passing through the recorded cooldown, and next-operation wait/fail behavior.
- Cooldown contract tests across worker-array, database, Redis, and Swoole stores: stable identity, atomic max extension, concurrent blocks, authoritative clocks, overflow, inspect, expiry, clear, and pruning.

### Static analysis

- `types/Saloon` assertions proving `Request<TDto>` flows through `Connector::send()` and `Response<TDto>` to `dto()` / `dtoOrFail()` without changing runtime behavior.

### Package and console

- Root/subpackage autoload, replace, provider and facade discovery metadata, config merge/publish, fixed connection registration, optional provider loading, dependency completeness, and HTTP raw bodies with an explicitly absent content type.
- Every generator/list command, prompt, independent custom integration path and namespace, stub publication, and generated syntax.
- README/doc navigation and examples checked against actual classes and signatures.

## Verification and completion

After each file-level test and coherent module suite is green:

1. run targeted PHPStan for the new package and changed HTTP/rate-limiter source;
2. run the Saloon, HTTP, RateLimiter, Testing, and relevant integration groups;
3. run `composer fix` from the components root;
4. run the package metadata and Testbench package-mode checks;
5. rerun the local transport/pool benchmark and inspect allocations/handler identity without adding CI thresholds;
6. perform a full diff review through all callers/callees, checking public API consistency, named arguments, extension visibility, coroutine ownership, static cleanup, bounded memory/concurrency, event guards, secret handling, duplicate mechanisms, and dead source/tests/docs;
7. compare the final tree with all five upstream inventories and verify every retained feature has source, tests, and user documentation while every omitted feature has a clear supported replacement.

Completion requires a clean worktree apart from the intended changes, no placeholders or deferred fixes, no compatibility wrappers for removed machinery, no unbounded worker registry or pool scheduling, and green full verification.
