# Native Swoole HTTP Transport and Guzzle 8 Compatibility

## Status

Approved for implementation after fresh self-review and independent signoff by `claude-http` on 2026-08-08.

## Objective

Add a fully realised outbound HTTP transport to Components, not a ClickHouse-specific shortcut:

- make Engine's HTTP/1.1 client a safe, reusable, driver-owned abstraction over Swoole;
- keep Hypervel's Laravel-shaped HTTP client and the complete Guzzle middleware/promise contract while allowing supported transfers to use pooled Swoole clients;
- provide explicit `auto`, `swoole`, and `curl` selection with fail-closed capability routing;
- make native synchronous requests lifecycle-safe, observable, and bounded under worker reuse while preserving Guzzle's async contract;
- complete generic object-pool shutdown/test cleanup exposed by this work;
- support released Guzzle 8 without excluding the still-large Guzzle 7 ecosystem; and
- measure the feature against a non-Swoole origin before choosing the framework default.

The result must read as one native design. There is no compatibility layer for the old Engine inheritance shape, no stale cURL-only documentation, and no minimum ClickHouse-only subset.

## References and verified baseline

- Repository rules: `AGENTS.md` and `docs/ai/porting-hyperf.md`
- Components base: `0.4` at `8dba27c3ec63b91a91159c86e938102b5c14b805`
- Current installed stack: Guzzle `7.15.3`, Promises `2.5.2`, PSR-7 `2.13.0`, Swoole `6.2.2`
- Released Guzzle 8 reference: `examples/guzzle` tag `8.0.2` at `d1cbca76970939a9c2ced55b1e25ea26f34fc773`
- Promises 3 reference: tag `3.0.1` at `64f38b87fa7d371853804161bfc701c9bc2cc00a`
- PSR-7 3 reference: tag `3.0.0` at `b094ded77ee97a6027ad6cf0e8c7b9f88381814c`
- Hyperf comparison: `examples/hyperf/hyperf` at `06f6c9f2631900d67a824b117184c9b78091a401`, especially its historical Guzzle coroutine/pool handlers
- Current Laravel-facing API reference: `examples/laravel/framework` at `8df67f9d176d1d0375a866d8c6780be95ce0336e`
- Existing owners: `Hypervel\ObjectPool`, `GuzzleHttp\HandlerStack`, `PendingRequest`, `ClientRequestWatcher`, and `GuzzleHttpClientAspect`

Source and runtime checks established these facts:

- Engine V1 currently inherits the native client, exposes unguarded native state, echoes the caller's requested version as response fact, and has no production consumer. Engine V2 already uses composition and owns `close()`/`isConnected()`.
- Swoole's HTTP client connects lazily. Its request body/uploads reset after a request, but headers persist and cookies persist and absorb response `Set-Cookie`; both must be replaced/cleared on every lease.
- `setHeaders([])` clears native headers. `setCookies([])` clears the cookie values but makes Swoole emit an empty `Cookie:` header; assigning the documented public cookie state to `null` clears it without emitting that header. Both behaviors were verified on Swoole 6.2.2.
- With `lowercase_header = false`, Swoole 6.2.2 preserves wire casing and returns every repeated header, including `Set-Cookie`, as an array in `headers`; `set_cookie_headers` is then `null`.
- Deferred `execute()` followed by `recv()` remains a useful Engine primitive and succeeds across coroutines when operations are sequential. Native Guzzle async nevertheless fails the ownership gate: Guzzle's pending parent/child promise cycle can retain an abandoned lease until cyclic GC, so framework async stays on Guzzle while coroutine `parallel()` supplies native concurrency without a promise-owned socket.
- Swoole decompression returns decoded bytes while retaining original `Content-Encoding` and encoded `Content-Length`; Guzzle renames those to `x-encoded-content-encoding` and `x-encoded-content-length`. The adapter must reproduce Guzzle's response contract.
- PSR-7 retains brackets in an IPv6 URI host, while Swoole requires an unbracketed connection host and synthesizes an invalid unbracketed IPv6 `Host` authority. Native preparation must normalize the connection host once while preserving Guzzle's prepared `Host` header.
- Guzzle rewinds replayable request bodies before every transfer. Native must do the same immediately before reading the complete body so retries never send an exhausted stream.
- Swoole does not expose the wire reason phrase. Standard status codes retain the PSR-7 conventional phrase, but a custom origin phrase cannot be reproduced without a separate HTTP parser.
- Native misuse outside a coroutine can terminate fatally before PHP can normalize it. Capability/operation guards therefore run before every native call.
- Swoole's TLS client verifies remote certificates correctly. Its server-side mTLS has separate defects: coroutine listeners ignore `ssl_client_cert_file`, and process/coroutine servers do not enforce cafile/capath-only peer verification. Hypervel's fixture therefore uses the currently correct process-server `ssl_client_cert_file` path; this does not affect production transport code or require a later migration after Swoole is fixed.
- Laravel and Hypervel put `STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT` on every pending request. Guzzle treats it as a minimum, while Swoole's `ssl_protocols` is an absolute allow-set; direct pass-through would silently pin HTTPS to TLS 1.2 instead of permitting TLS 1.3. Guzzle 8 also supplies a documented TLS 1.2 minimum for HTTPS when the option is absent, while Guzzle 7 leaves the floor to its TLS backend.
- A process-mode Swoole HTTP server serves HTTP/1.1 and HTTP/2 on one TLS listener through ALPN. Exact casing, repeated headers, pre-compressed bodies, stalls, resets, and truncation are more truthfully expressed by a literal-byte socket fixture. Node and MockServer add no CI capability; Node remains only for the non-Swoole benchmark origin.
- Hyperf's handler is useful reference evidence, not code to port: it creates one client per request, gives incomplete option fidelity, maps unrelated failures to `ConnectException`, and its pool key omits connection-affecting settings.
- Both Telescope and Sentry intercept `GuzzleHttp\Client::transfer()`, above the bottom handler. Keeping Guzzle preserves direct Guzzle and third-party instrumentation by construction.
- Sentry's aspect currently injects propagation headers before creating the HTTP child and leaves that child current until settlement. The first bug parents the remote server to the transaction rather than the client span; the second nests concurrently started async requests and can leave a finished child current after out-of-order settlement. The child must be current only for the synchronous `transfer()` invocation, while later promise finalization operates directly on the captured span.
- `ObjectPool::get()` has one expected backpressure outcome but throws an untyped `RuntimeException`; `PoolManager::flush()` currently has no production or central test-teardown caller.
- Guzzle 8 retains the handler/promise seam but adds four factory request options, two persistent transport-sharing modes, and a new exception hierarchy. Connection caps and `Multiplexing` already exist in Guzzle 7.15.3. `RequestException::hasResponse()/getResponse()` no longer exists on the Guzzle 8 base class, and `NetworkException`/`NetworkTimeoutException` are not `RequestException` descendants.
- A root Guzzle 8 solve is currently blocked by `algolia/algoliasearch-client-php 4.46.3`, which requires `guzzlehttp/psr7 ^2.0`; Guzzle 8 requires PSR-7 `^3.0`. The package's current development branch retains that constraint. Removing first-party Scout/Algolia coverage or using a Composer alias would be a test-quality hack, not a migration.

## Existing contracts to preserve

- `Http::` remains Laravel-shaped: middleware, fakes, recording, cookies, redirects, status handling, retries, request/response events, macros, `setHandler()`, `setClient()`, and response APIs remain above the transport.
- Hypervel keeps coroutine-native `parallel()`, `Parallel`, and `defer`; Laravel's HTTP `pool()`/`batch()` APIs remain intentionally absent.
- Named connections remain worker-lifetime presets/handler identities; `PendingRequest`, handler stack, cookie jar, callbacks, request data, and response evidence remain operation-local.
- Explicit caller-owned `setHandler()`/`setClient()` bypass default transport selection. The framework must not wrap or reinterpret a caller's custom handler.
- PSR-18 discovery continues to prefer ordinary Guzzle. Arbitrary third-party `new GuzzleHttp\Client()` instances and packages using Guzzle's own multi handler are not globally intercepted or rewritten.
- Engine stays independent of Object Pool. Pooling belongs wholly to `hypervel/http`.
- The native path is HTTP/1.1 and buffered-response only. Guzzle remains the complete fallback for streaming, HTTP/2/3, proxies, advanced TLS/authentication, and every option not proven equivalent.

## What this audit is not

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

## Final architecture

```text
Http:: / PendingRequest
  -> existing Guzzle Client + HandlerStack + middleware
     -> caller handler/client, when explicitly supplied
     -> TransportHandler for framework-owned handlers
        -> SwooleHandler when the request is proven equivalent
           -> ObjectPool lease
              -> Engine HTTP/1.1 Client (one in-flight request)
        -> untouched Utils::chooseHandler() fallback
```

`TransportHandler` is routing/ownership, `SwooleHandler` is Guzzle-to-Engine adaptation, and Engine owns native state/errors. One transport handler owns one logical HTTP identity, its configured default mode, and any request-local fluent override; it does not create separately owning handler variants per mode. Do not add a transport registry, extension manager, response DTO hierarchy, separate ephemeral native path, or another HTTP package.

### Public HTTP surface

Use string driver names, as Laravel managers/connections do; do not add an enum or convenience methods such as `useSwoole()`:

```php
Http::setDefaultTransport('auto');
Http::setDefaultPoolOptions([
    'min_retained_objects' => 0,
    'max_objects' => 100,
    'wait_timeout' => 1.0,
]);

Http::registerConnection('analytics', [
    'base_uri' => 'https://analytics.internal',
    'transport' => 'swoole',
    'pool' => ['max_objects' => 50],
]);

Http::connection('analytics')->get('/health');
Http::connection('analytics')->transport('curl')->get('/download');
```

Add:

- `Factory::setDefaultTransport(string $transport): static`
- `Factory::getDefaultTransport(): string`
- `Factory::setDefaultPoolOptions(array $options): static`
- `Factory::getDefaultPoolOptions(): array`
- `Factory::purge(?string $name = null): void`
- `PendingRequest::transport(string $transport): static`
- registered connection keys `transport` and `pool`

There is no new `config/http.php`: current global client policy is configured through boot-only factory mutators (`globalOptions`, global middleware, connection registration), and this follows that established owner. Add every method to the `Http` facade annotations.

Transport precedence is exactly:

```text
factory default < registered connection transport < fluent transport()
```

Pool precedence is factory defaults merged with the registered connection's partial `pool` array. `connection($name, $config)` remains a request-option override and must reject `transport`, `pool`, and `transport_sharing`; infrastructure policy is not request-scoped. This intentionally supersedes the earlier proposal to accept infrastructure keys in per-call connection config: doing so duplicates `transport()`, fragments pool identity/capacity from one request, and makes boot-only handler ownership untrue.

`globalOptions()`, `withOptions()`, and raw `send()` options reject `transport`/`pool` with remedies pointing to the dedicated APIs. Explicit `curl` plus an explicit pool is contradictory and rejected. `setDefaultTransport()` and `setDefaultPoolOptions()` are boot-only and call `purge()` before replacing global policy; `setConnectionConfig()` calls `purge($name)` before replacement. `purge($name)` closes and evicts one named handler, while `purge()` closes and evicts the unnamed default plus every named handler. Purge is non-terminal: the next request rebuilds fresh handlers and pools. It is a boot/test/manual-owner lifecycle operation because requests retaining an invalidated handler or waiting for its pool may fail; an already borrowed transfer completes and its late return is destroyed. Boot-only mutation needs no lock or inheritance/refcount tracker.

`PendingRequest::transport()` stores request-local policy on the pending request. `buildHandlerStack()` obtains the one owning `TransportHandler` and, only for a fluent override, binds the selected mode in a small bottom-handler closure which calls `handleUsing($mode, $request, $options)`. No private key enters Guzzle options or becomes visible to middleware/instrumentation, and no separately owning handler variant is constructed. Combining `transport()` with caller-owned `setHandler()`/`setClient()` is rejected whichever mutator is called second; `buildClient()` also asserts the invariant before a caller-owned client can bypass stack construction.

`Factory::getConnectionOptions()` strips `transport`, `pool`, `transport_sharing`, and Guzzle handler-construction options before request-option merging. Ordinary unnamed requests use one cached default `TransportHandler`; named requests use the existing per-name cache. Inject Object Pool's `Factory` contract into the HTTP factory when the container has its alias, and create a local `PoolManager` only for manual `new Factory` use. Each transport handler has explicit idempotent `close()`; never close its native channel-backed pools from a destructor. Application managers use the deterministic worker/test Object Pool flush, while manual factories call `purge()` before their lifecycle ends.

The existing public `getConnectionHandler()` is also the explicit bridge for a package that must keep its raw Guzzle API while opting into Hypervel's transport. Register a named connection and give that callable to Guzzle's `HandlerStack`; do not add a second transport registry or globally rewrite every Guzzle client.

### Engine HTTP/1.1 client

Replace `Client extends Swoole\Coroutine\Http\Client` with composition, matching Engine V2's structural precedent while retaining V1's correct lazy connection. The native client is private and never escapes.

The contract contains the complete useful lifecycle:

```php
interface ClientInterface
{
    public function set(array $settings): void;

    public function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $contents = '',
        string $version = '1.1',
    ): RawResponseInterface;

    public function send(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $contents = '',
        string $version = '1.1',
    ): void;

    public function recv(float $timeout = 0): RawResponseInterface;
    public function close(): void;
    public function isConnected(): bool;
}
```

`set()` remains only as a strict Engine-owned per-transfer boundary so a pooled handler can reset mutable settings without keying pools by request policy. It no longer forwards arbitrary keys or returns an ignorable false result. Constructor and setter validation share definitions but accept disjoint categories:

| Category | Supported settings | Rule |
|---|---|---|
| construction-only | `ssl_verify_peer`, `ssl_allow_self_signed`, `ssl_cafile`, `ssl_capath`, `ssl_cert_file`, `ssl_key_file`, `ssl_host_name`, `ssl_protocols` | validate booleans/non-empty readable paths/host as applicable; `ssl_protocols` must be a non-zero integer subset of Swoole's TLS 1.0/1.1/1.2/1.3 bits; include every effective value in the physical fingerprint; reject through `set()` |
| per-transfer `set()` | `connect_timeout`, `timeout`, `read_timeout`, `body_decompression` | finite non-negative numbers or boolean as applicable; supply the full normalized set before every send so native merge semantics cannot retain a prior request; reject in constructor settings |
| framework-fixed | `keep_alive = true`, `http_compression = false`, `lowercase_header = false` | not public settings; native compression header injection stays disabled because Guzzle owns `Accept-Encoding`, and response header names retain wire casing |

Reject unknown settings, wrong-category mutation, invalid types/ranges, and mutation while a response is pending; throw a normalized Engine exception when native configuration fails. Do not expose unrelated Swoole socket/WebSocket knobs without a real framework consumer.

`request()` is `send()` followed by `recv()`. `send()` sets defer mode, marks the client busy before native I/O, unconditionally replaces headers, resets the documented public native cookie state to `null` (not `setCookies([])`, which emits an empty header), applies the full per-transfer settings, method, and body, and executes. Cookies are carried only by the prepared PSR request's `Cookie` header; response `Set-Cookie` remains response data for Guzzle's cookie middleware. `recv()` is the only response read and clears busy state on every terminal path. Failed send/receive closes the client before the wrapper becomes reusable. `close()` is idempotent and clears pending state; destruction performs non-throwing best-effort close. `isConnected()` truthfully reports the native connection.

Response decoding must preserve Swoole's original header-name casing. With `lowercase_header = false`, `headers` already contains complete arrays for every repeated header and `set_cookie_headers` is `null`. Delete the inherited lowercase comment and `Set-Cookie` special case entirely: convert only scalar values to one-element lists and pass array values through under their original keys. Do not lowercase the map or add a second header-normalization/merge layer.

Only HTTP/1.1 is accepted. Reject other request versions; `RawResponse::getVersion()` always returns actual `'1.1'` rather than echoing input. Do not expose native WebSocket upgrade, download, push, raw properties, or other unrelated methods through the Engine HTTP abstraction.

Use the existing Engine exception convention:

| Condition | Exception |
|---|---|
| DNS/refusal/TLS establishment, including connect timeout | `SocketConnectException` (preserve `ETIMEDOUT` for the boundary adapter) |
| read/request timeout after establishment | `SocketTimeoutException` |
| reset, EOF, stale keep-alive, proven lost peer | `SocketClosedException` |
| outside coroutine | `RunningInNonCoroutineException` |
| overlapping operation or configuration while pending | new `HttpClientBusyException extends HttpClientException` |
| honestly unclassified native failure | `HttpClientException` |

Classify from both native failure status and native error code; the latter may be a POSIX errno or a public Swoole code such as `SWOOLE_ERROR_SSL_VERIFY_FAILED`. Do not map every `SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED` to closed; only lost-peer errno such as `EPIPE`, `ECONNRESET`, `ECONNABORTED`, and `ENOTCONN` earns that class. `ETIMEDOUT` is a connect timeout before establishment and a read/request timeout after establishment, matching the phase-specific exception table. Preserve native message/code and the native exception as `previous` where available. Cancellation is promise ownership above Engine and needs no Engine exception or classification enum.

### Guzzle handler and capability boundary

`TransportHandler` owns a configured mode, consumes the optional fluent override, and selects between two callables: `SwooleHandler` and an untouched `Utils::chooseHandler($handlerOptions)` fallback. `auto` invokes native only when the single support predicate succeeds; explicit `swoole` uses the same predicate but throws `UnsupportedTransportException extends \InvalidArgumentException` naming the exact incompatible option/header/body/protocol or missing coroutine context; `curl` invokes the existing handler directly. Engine direct use keeps `RunningInNonCoroutineException`, while the handler's strict refusal occurs before Engine I/O. “Curl” means Guzzle's existing chosen handler, which may use its stream fallback where Guzzle requires it.

The classifier is fail-closed and cheap: constant array/key/type checks plus request metadata. No I/O, reflection, container resolution, cURL-constant scan, or URI parsing beyond values already on the PSR request occurs per transfer.

| Native support | Existing Guzzle fallback / strict refusal |
|---|---|
| coroutine context; `http`/`https`; HTTP/1.1 | non-coroutine; other schemes/versions |
| ordinary methods/headers; URI without userinfo | explicit `Expect: 100-continue`; URI userinfo |
| empty or seekable, known-size in-memory/temp string bodies; ordinary JSON and form bodies | multipart, file-backed, non-seekable, unknown-length, chunked, or streaming request bodies |
| finite non-negative `int|float` Guzzle `delay` milliseconds via coroutine sleep | every other delay value |
| `connect_timeout`, `timeout`, `read_timeout` mapped onto existing Engine/native controls | no new deadline/read/write public APIs |
| `decode_content` false/true/string with Guzzle-compatible request/header rewriting | unsupported compression environment/value |
| `verify` true/false/CA file; PEM certificate + key with supported no-password form; exactly representable `crypto_method`/`crypto_method_max` TLS range | proxy, stream context, forced IP family, unrepresentable TLS floor, advanced certificate formats/passwords |
| basic auth already materialized as `Authorization` by Guzzle | digest/NTLM/raw cURL authentication |
| `on_stats`, Guzzle 8 response/stream factories, protocol allowlist | `debug`, `stream`, `sink`, `on_headers`, `on_trailers`, `progress` |
| native retry setting zero; exclusive/non-multiplexed operation | transport retries, multiplex modes other than `NONE` |

Do not implement `sink` by buffering and writing after the transfer; that would turn a streaming download into a full-memory response. Do not add a partial native `download()` path for string sinks. All multipart declines even when its prepared body happens to be seekable, avoiding a second upload encoder and memory regressions.

Guzzle middleware continues to own redirects, cookie jars, status exceptions, request preparation, retries, fakes, recording, callbacks, and events. Tests prove those paths through native; the handler does not duplicate them.

Map `decode_content` exactly, and reset `body_decompression` on every lease: `false` disables body decompression while preserving any caller-supplied `Accept-Encoding`; `true` enables body decompression and leaves the prepared header untouched; a string enables body decompression and Guzzle has already materialized that exact value as `Accept-Encoding`. Keep native `http_compression` false in all three cases so Swoole never injects its own header. When native decoding occurred, preserve the original metadata as Guzzle's `x-encoded-content-encoding`/`x-encoded-content-length` headers and remove the live `Content-Encoding` and `Content-Length`; when it did not, preserve the original headers and bytes.

Raw `curl` request options force wholesale fallback; there is no CURLOPT capability list. Proxy support also stays fallback until exact URI/no-proxy/auth/tunnel/TLS semantics are proven by a real consumer. This is complete support because `auto` retains today's implementation and strict mode refuses precisely.

Translate TLS ranges through an explicit map of Guzzle's four accepted PHP client constants to ordered TLS versions, then a second explicit map to Swoole's four TLS bits. Never pass the PHP value through, derive it with bit arithmetic, or call Guzzle's internal `Handler\TlsVersion`: PHP's client constants contain a role bit, and the two APIs have different semantics. The server-side `TlsOptions` pass-through is deliberately separate because PHP stream-context server `crypto_method` is already an allow-set whose bit layout matches Swoole; add a concise comment at both translation points to prevent incorrect consolidation.

TLS classification is scheme- and major-aware:

- an explicit valid minimum, with or without a valid maximum, is exactly representable on both majors;
- for Guzzle 8 HTTPS, an absent minimum becomes its documented TLS 1.2 floor, so no-bound and max-only requests are representable when the resulting range is valid;
- for Guzzle 7 HTTPS, an absent minimum is backend policy that Swoole's absolute mask cannot reproduce, so `auto` falls back and strict `swoole` refuses;
- plain HTTP validates supplied constants/ranges according to the installed Guzzle major but omits TLS controls from native construction and pool identity; in particular, Guzzle 8 applies no implicit floor to HTTP and accepts max-only values that would be below its HTTPS floor; and
- native HTTP/2 remains unsupported, so do not reproduce Guzzle's HTTP/2 minimum-floor adjustment.

#### Drift prevention

Maintain one explicit classification for every public `GuzzleHttp\RequestOptions` constant: handled above the handler, native-supported, or fallback-only. A reflection test compares the current class constants to that classification and fails while naming additions/removals. Runtime keys not on the known Guzzle/internal list fail closed even before CI is updated.

Recognize only proven internal keys (`hypervel_data`, Telescope/Sentry flags/tags, `synchronous`, and Guzzle's version-specific internal/factory keys). The reflection guard names public option drift; runtime fail-closed handling safely routes any new internal/default key to Guzzle. Do not add a second baseline-capture test for the same risk.

`transport_sharing` remains registered-handler configuration, not a request option. Guzzle 7 handler-prefer and Guzzle 8 handler/persistent-prefer modes may be ignored by native because they are preferences. Handler/persistent-require force fallback in `auto` and throw in strict `swoole`. A required-sharing mode plus an explicit Hypervel pool is rejected because every request would bypass that pool. Document that Guzzle sharing describes cURL share handles and is separate from Hypervel's pooled transport.

`max_host_connections`, `max_total_connections`, and `Multiplexing` are available on both supported Guzzle majors and need no version branch. Pass connection caps unchanged to Guzzle fallback, but make native decline them because Hypervel exposes a different per-logical-connection/per-origin pool budget and cannot truthfully emulate a cross-origin total cap. Reject a config combining those options with an explicit Hypervel pool. A registered `multiplex` value configures the fallback and remains a request default; native accepts only `Multiplexing::NONE`. Only the four factory request options, two persistent sharing modes, and exception hierarchy are Guzzle 8-specific here.

### Pool identity and lifecycle

Every native request uses Object Pool; there is no one-off code path. HTTP's normalized defaults are existing `PoolOptions` defaults with `min_retained_objects` changed to `0`, so creation stays lazy and HTTP does not retain an unused connection merely because a handler was built.

Pool identity is:

```text
logical HTTP identity + physical connection fingerprint
```

The logical identity is the registered connection name or one reserved unnamed-default identity. The physical fingerprint contains lower-cased scheme/normalized host, effective port, and all immutable native construction inputs (TLS verification/CA/client certificate/key and future settings that change connection construction). IPv6 URI brackets are removed once during preparation, and that same value feeds Engine construction, TLS peer naming, and the fingerprint; the prepared PSR `Host` header stays bracketed. The fingerprint excludes headers, cookies, bodies, and mutable per-request settings because the handler resets them for every lease. The pool's stored client factory likewise captures only host, port, SSL mode, and construction settings; retaining the complete first request would keep its body and request policy for the pool lifetime. Keep the protected instance construction seam, not a weak reference, public factory hook, or connection DTO. Use `PoolFingerprint`, not a second canonicalizer; change its two internal hashes from `sha256` to the repository-mandated non-cryptographic `xxh128`. Do not add a second fingerprint cache around this cheap internal identity calculation.

Two named connections intentionally have separate budgets/sockets even if they target the same endpoint. One logical connection splits by physical origin. Each `TransportHandler` stores only the exact pool identity and expected pool instance pairs it created; `close()` calls `PoolManager::remove($identity, $expected)`. That set is ownership bookkeeping, not a registry. The existing recycler removes idle dynamic-origin pools; do not add an LRU/max-origin cache without evidence.

Add `Hypervel\ObjectPool\Exceptions\PoolExhaustedException extends \RuntimeException` and throw it only at the wait-deadline exhaustion site. The HTTP handler maps only that type to Guzzle `ConnectException`, which `PendingRequest` maps to `ConnectionException`. Closed-pool, duplicate factory, ownership, and configuration failures remain raw lifecycle/programming errors; never broaden the catch to `RuntimeException` or message matching.

Complete generic cleanup:

- `ObjectPoolServiceProvider` listens for `OnWorkerExit`; if `$app->resolved(PoolManager::class)`, resolve and `flush()`. Do not instantiate an unused manager.
- `InteractsWithTestCaseLifecycle::tearDownTheTestEnvironment()` performs the same concrete resolved gate and flushes Object Pool in its own coroutine immediately after the existing database-pool block, before parallel callbacks and application flush, preserving the first teardown exception.
- add Foundation's direct `hypervel/object-pool` dependency; do not rely on HTTP's transitive dependency.

These fixes close existing Broadcasting, Queue, Mail, and Filesystem resources too. `PoolManager::flush()` already clears registry state before closing, and closed pools destroy late returns, so no coordinator, mutex, or shutdown registry is added.

### Async capability boundary

Native routing requires Guzzle's internal `synchronous` option to be exactly `true`. Supported synchronous requests normalize delay to integer microseconds and sleep after classification but before pool lookup/borrow; transfer timing begins before both delay and pool wait. After borrowing, PSR-17 factories and the replayable request body are fully materialized before touching the Engine client. Seekable bodies rewind immediately before every read, including retries. The request then uses one lease for the complete Engine `request()` call and settles it before returning a fulfilled or rejected Guzzle promise. Engine runtime failures, including a connected busy client, always discard. Every other terminal path releases only a connected, clean client and discards a disconnected one; this safely reuses connected clients after pre-I/O or response-conversion failures without phase tracking. This leaves no pending promise-owned socket and no special cancellation or abandonment state.

Native async is deliberately declined. Guzzle's normal middleware attaches child promises while the bottom promise is pending: the parent retains handlers and the child retains the parent through its wait/cancel callbacks. Cancellation and explicit `wait()` settle cleanly, but dropping the last external reference can retain the whole graph—and therefore a lease—until cyclic GC. A weak reference from the bottom handler cannot break ownership held by the rest of Guzzle's chain. A custom Promise/A+ implementation, global pending-transfer tracker, child coroutine, or lazy response would add brittle machinery or change semantics for no framework benefit.

The public contract is:

- `auto` plus `async()` uses the untouched Guzzle handler;
- `curl` plus `async()` uses the untouched Guzzle handler;
- effective strict `swoole` plus `async()` is rejected synchronously as an incompatible configuration, naming `parallel()` over synchronous requests as the native-concurrency alternative; and
- synchronous requests run inside Hypervel's `parallel()` coroutines concurrently through the native pool.

Reject strict Swoole async at whichever API mutator makes the combination effective. This covers factory-default policy, registered connections, fluent `transport()`, and both `async()`/transport-selection call orders. `buildClient()` asserts the same invariant so a boot-only policy change cannot bypass it. The raw-Guzzle bridge retains the handler-level check and returns an ordinary rejected promise, because caller-owned Guzzle clients do not pass through `PendingRequest`'s ergonomic validation.

### Errors, stats, Telescope, and Sentry

Map Engine exceptions through one handler-local version adapter so native failures use the installed Guzzle major's most specific truthful type:

| Engine failure | Guzzle 7 | Guzzle 8 |
|---|---|---|
| `SocketConnectException` with connect-timeout error code | `ConnectException` | `ConnectTimeoutException` |
| other `SocketConnectException` | `ConnectException` | `ConnectException` |
| `SocketTimeoutException` after establishment | `ConnectException` | `NetworkTimeoutException` |
| `SocketClosedException`, including a truncated response | response-less `RequestException` | `NetworkException` |
| unclassified `HttpClientException` | response-less `RequestException` | response-less `RequestException` |

Coroutine/busy/unsupported configuration remains a precise programming/configuration exception. Named `previous` works on both Guzzle majors despite their different positional constructors. Instantiate Guzzle 8-only classes behind one `class_exists()`/major boundary; do not scatter version checks. Guzzle 8 removed handler-context arrays, so diagnostics live in the preserved Engine exception rather than a version-specific context promise.

Swoole exposes that response headers arrived before a reset, but replaces the original HTTP status with `-3` and discards the partial body. A response-bearing Guzzle exception therefore cannot be constructed honestly without installing a PHP `write_func` on every successful response merely to retain rare failure evidence. Do not pay that steady-state performance/complexity cost or fabricate a status/body. The native exception stays response-less; Guzzle 7 fallback may retain a partial response on `RequestException`, and Guzzle 8 fallback may use `ResponseTransferException`, but Hypervel normalizes all of them to the same public `ConnectionException`. Document the lower-level diagnostic-evidence difference. Also document the unavoidable reason-phrase difference: Swoole exposes only the status code, so PSR-7 supplies conventional phrases and cannot preserve a custom wire phrase. Do not add a response parser or fabricate one.

Independently fix `PendingRequest`'s version-neutral failure handling. One private response extractor returns a response only when the concrete exception exposes `getResponse()`. One private connection-failure predicate covers `ConnectException`, Guzzle 8 `NetworkException`, and transport failures even when Guzzle captured partial response headers: Guzzle 8 `ResponseTransferException`/`ResponseTimeoutException`, or an exact Guzzle 7 `RequestException` with a response and `getPrevious() === null`. Keep a source-trace comment at the Guzzle 7 arm: CurlFactory uses that exact shape for a post-header transfer failure, while `on_headers`, `on_trailers`, and redirect rewind failures preserve their user/rewind throwable as `previous`. Response-less `RequestException` is also a connection failure. Complete/callback response failures take the with-response path: `BadResponseException`/`TooManyRedirectsException`, Guzzle 8 `ResponseException` after excluding its `ResponseTransferException` family, and Guzzle 7 response-bearing `RequestException` with a previous throwable. Use these rules consistently in sync, async, retry, event, recorder, and response-population paths, recording no response for a connection failure rather than presenting a truncated response as valid. The marshal helpers accept the common `TransferException`/request contract rather than incorrectly requiring `ConnectException` or `RequestException`.

Correct retry policy through one private `shouldRetry(Throwable $exception): bool`: an explicit `retry(..., when: ...)` predicate remains authoritative, while the default does not retry `\InvalidArgumentException`. This covers Hypervel validation, Guzzle/PSR-7 invalid input, user middleware/callbacks, and strict transport refusal without a transport exception registry. Only `Response::failed()` enters response retry/throw policy in either sync or async; informational and redirect responses return once, never invoke retry/`throwIf` callbacks, and async settles with the response rather than `null`. Pass the Laravel callback arguments—exception, pending request, and current request method—in both sync and async; the method is `null` when the attempt failed before before-sending established a request. Initialize nullable request/promise properties, reset current request evidence at the start of every `sendRequest()` attempt, and make fresh `getPromise()` truthfully return `null`. Do not otherwise change the existing Laravel-shaped async settlement contract.

The narrowed Guzzle 7 classification deliberately corrects existing truncated-transfer behavior: a partial response that previously reached `marshalRequestExceptionWithResponse()` now becomes `ConnectionException`. Update old tests to the corrected contract rather than preserving the invalid response shape.

Native completion constructs `TransferStats` with measured duration and `handlerStats['transport'] = 'swoole'`. Only the fallback branch decorates an existing `on_stats`, reconstructing immutable stats while preserving request, response, duration, error data, and existing handler stats, and adding `transport = 'guzzle'`. Do not label it `curl`, because Guzzle may select its stream handler. Fakes/direct custom handlers remain unlabeled unless they provide the stat; absence must not be misreported as fallback.

Telescope keeps interception at `Client::transfer()`, records native and fallback requests once, and adds transfer evidence only when present. In particular, `Http::fake()` truthfully supplies no transfer time or transport; omit both keys instead of fabricating a measured zero. Its existing rejection continuation intentionally gains no cancellation wrapper: cancellation can omit one record but retains no resource or corrupt state, so matching Sentry's machinery would be unearned. Keep the client-request views tolerant of missing evidence and render the preview's unit only when a duration is displayed.

Correct Sentry's propagation and async span lifecycle in the touched aspect. Create the HTTP child before injecting headers, make it current only around header preparation and the synchronous `ProceedingJoinPoint::process()` call, and restore the captured parent unconditionally in `finally`. This makes concurrent async client spans siblings and prevents an outstanding promise from owning coroutine-current state, while synchronous native/fallback transfers and middleware still run under their client span. Later stats, rejection, and cancellation finalization operates directly on the captured span and never mutates Hub context.

Use `Span::getEndTimestamp() !== null` as the sole once-only early-return predicate. `on_stats` supplies truthful response/status/breadcrumb evidence; failure without stats marks the span as an internal error, and cancellation deliberately records no breadcrumb rather than fabricating `TransferStats`. A synchronous throw finishes through the same path. An already rejected promise is finalized immediately and returned unchanged because wrapping it would turn its no-op `cancel()` into a new `CancellationException`; an already fulfilled promise is also returned unchanged because conforming handlers have already invoked `on_stats`. Only a pending promise with an active span is forwarded through Guzzle's own concrete `Promise`: wait delegates to `wait(false)`, cancel delegates in `try`/`finally`, and fulfillment/rejection preserves the exact value/reason while settling only if the forwarding promise is still pending. This is not a custom Promise/A+ implementation or native transport owner.

### Guzzle 8 migration

Change direct constraints in root, HTTP, Console, Notifications, Scout, and Socialite to:

```json
"guzzlehttp/guzzle": "^7.15.1 || ^8.0.2"
```

Do not require Guzzle 8 exclusively: supported SDKs still require Guzzle 7/PSR-7 2, and excluding them provides no framework benefit. Do not add direct Promises/PSR-7 constraints where Guzzle owns them.

Make production code major-neutral:

- honor Guzzle 8 `response_factory` and `stream_factory` when constructing native responses/streams; request/URI factories are consumed above the handler;
- use one internal response extractor for Guzzle exceptions: return `getResponse()` only when the concrete object exposes it, otherwise null. Replace every scattered `hasResponse()/getResponse()` assumption in `PendingRequest` and recorder paths;
- classify Guzzle 8 `NetworkException` and `ResponseTransferException` families (including both timeout classes) as Hypervel `ConnectionException`; complete response exceptions use the response path only after excluding transfer failures as described above;
- use shared promise APIs present in Promises 2/3 and test fallback wait, rejection, cancellation, and abandonment under both;
- validate Guzzle 8's new persistent sharing and factory-option contracts plus the shared multiplex/connection-cap contracts described above; and
- run generated Sentry/Telescope AOP proxies against both `Client::transfer()` implementations.

Keep the normal root lock/test lane on Guzzle 7 while Algolia pins PSR-7 2. Add a focused `guzzle-8` workflow that, only in its disposable checkout:

```bash
composer remove --dev algolia/algoliasearch-client-php --no-update
composer update --with-all-dependencies --with guzzlehttp/guzzle:^8.0.2 --prefer-dist --no-interaction
# Assert the installed major through Composer\InstalledVersions.
git restore composer.json
```

The temporary `--with` constraint pins the solve without replacing the committed dual constraint. Assert the installed major before restoring the canonical manifest, then run metadata checks against what the repository actually ships; the untracked solved lock intentionally no longer matches and must not be validated in this lane. Run all affected package suites plus the Engine HTTP server workflow. Run Foundation and Scout with a negative `Algolia` test-name filter so every non-Algolia test remains covered, including provider-level Guzzle injection and real Meilisearch/Typesense integrations; the ordinary Guzzle 7 lane retains full Algolia coverage.

Guzzle 8 source analysis covers the changed surface plus every source file containing a real `GuzzleHttp` symbol. It cannot analyze `ScoutServiceProvider` or `InteractsWithAlgolia` because both reference the absent SDK; record these explicit gaps in the workflow rather than hiding them behind a broad exclusion config. Runtime tests still cover the provider's non-Algolia paths under Guzzle 8, and the normal lane analyzes both files. Do not add SDK stubs, source-only installs, a Guzzle-specific PHPStan config, an alternate lock, a PSR-7 alias, or delete Guzzle 7/Algolia coverage. When Algolia permits PSR-7 3, delete the CI exclusion and let the normal dependency solve select Guzzle 8.

Track that deletion in `docs/todo.md` and place the exact `@TODO: Remove Guzzle 7 compatibility` marker at every source, test, and workflow branch that becomes unnecessary under a Guzzle 8 minimum. These are deliberate ecosystem-blocked cleanup markers, not incomplete implementation TODOs.

### Adjacent consumers

- `hypervel/api-client` forwards fluent calls to `Http::`; `transport()` therefore works without another selector.
- Inertia SSR and Console scheduler pings construct/use ordinary Guzzle clients. They remain behaviorally unchanged but are Guzzle 8 compatibility and performance-shape tests. Do not silently replace arbitrary raw Guzzle clients; packages that want native selection should use Hypervel's HTTP client or its explicit handler APIs.
- Scout's explicit Guzzle clients remain on their existing Guzzle handlers in this change; test both supported majors where its installed SDK set permits them.
- Sentry's detached SDK curl client is outside Guzzle and outside this feature. Do not fold it into the transport design.
- The future ClickHouse bridge is a validating consumer of `Http::transport('auto')`, not an implementation dependency or reason to leak ClickHouse concepts into Components. Declining native async costs its planned parallel design nothing: bounded `parallel()` calls each own one synchronous native transfer, while its current `GuzzleHttp\Pool` path remains a correct fallback.

## Documentation

Update only maintained sources and package metadata:

- `src/boost/docs/http-client.md`: architecture, modes/default, fluent and named APIs, exact auto/fallback semantics, strict refusal, supported boundary, pool options/identity, concurrency bound and wait behavior beside `parallel`, the async capability boundary, transfer stat, examples, Guzzle sharing distinction, and Guzzle 7/8 support.
- `src/boost/docs/pools.md`: native HTTP as the worked must-pool example, worker/test cleanup, typed exhaustion, and existing pool vocabulary. Mirror Mail's identity wording and Database's statement that `min_retained_objects` is not prewarming/replenishment.
- `src/http/README.md` and `src/engine/README.md`: concise package-level differences/architecture, not duplicate user manuals.
- `GuzzlePsr18Strategy`/provider prose: Guzzle remains preferred because Symfony's shared multi client is unsafe under Swoole; remove the stale claim that PSR-18 necessarily routes through a synchronous cURL handler.
- facade annotations and package metadata tests.

Delete or rewrite stale statements that HTTP clients are not pooled or that named handlers necessarily mean cURL reuse. Delete dead `PendingRequest::requestsReusableClient()` and `getReusableClient()` rather than wiring them into the new promise owner. No temporary migration comments, TODOs, obsolete code paths, or duplicate docs remain.

## Testing plan

### Engine unit and integration

- construction/per-transfer setting categories, wrong-category and unknown setting rejection, complete reset values, host/port/TLS inputs, lazy construction, idempotent close, `isConnected`, and non-coroutine guard before native I/O;
- synchronous request plus Engine send/recv, one-pending-request exclusivity, configuration-while-busy rejection, recovery/close after every failure class, truthful HTTP/1.1 version, header/cookie replacement, and body/upload reset evidence;
- native error status + native error-code mapping for connect, timeout, TLS verification (`SWOOLE_ERROR_SSL_VERIFY_FAILED`), reset/EOF, lost peer, unknown send failure, and previous exception preservation;
- unsatisfied native TLS range maps `SWOOLE_ERROR_SSL_HANDSHAKE_FAILED` at connect-failed status to `SocketConnectException`;
- real HTTP/HTTPS server tests for methods, repeated keep-alive, mixed-case response-header key preservation, repeated `Set-Cookie` and non-cookie header arrays through the same generic conversion, cookies, omission of `Cookie` after a prior cookie-bearing response/request, empty/binary bodies, TLS verification/CA/client cert, compression false/true/string across repeated pooled leases, header rewrite, delay/timeouts, connection close, and concurrent clients;
- extend the existing `bin/test-servers.sh engine`/`InteractsWithServer` workflow rather than starting an ad hoc server per test. Rename the misleading `http_server_v2.php` HTTP/1.1 example and its `HTTP v2` label; identify port 19501 as h2c-capable;
- add a process-mode TLS origin with `open_http2_protocol = true` and `ssl_client_cert_file`, serving HTTP/1.1 and HTTP/2 through ALPN on one port. Keep one real negotiated HTTP/2 fallback assertion and verify IP-literal TLS/SNI behavior before adding any special case; capability branches and sharing/multiplex modes remain table-driven, with no required-multiplex end-to-end test;
- add one plain/TLS literal-byte socket fixture for exact casing, repeated headers, pre-compressed bytes, delayed response, stalled handshake, pre-header reset, and truncated bodies. Configure Swoole's process socket timeout to -1 so hooked listener accept does not expire at its 60-second default; explicit accepted-stream timeouts still bound handlers. Spawn connection handlers through Engine's fail-loud coroutine primitive rather than the application wrapper, whose exception reporter needs a bootstrapped container. A TLS listener retries only Swoole's verified SWOOLE_ERROR_SSL_BAD_CLIENT accept outcome; every other false accept throws. Harness and test readiness perform real verified TLS/mTLS handshakes rather than bare TCP probes. Do not use a framework HTTP response writer for wire-format assertions;
- own a regenerable CA/server/client certificate set and generation script under `tests/Integration/Http/Transport/Fixtures/Tls`; do not couple HTTP tests to gRPC certificates; and
- enumerate `tests/Integration/Http/Transport` explicitly in the Engine and Guzzle 8 workflows, keep each fixture port on its own `InteractsWithServer` test class, raise the Engine job timeout from five to ten minutes, and bound every fixture delay below the test timeout. Do not add Node or MockServer to integration CI.

### HTTP handler and API

- transport/default/pool validation, precedence, named/default handler caching, named/all purge, purge-then-rebuild, reconfiguration close, deterministic direct-handler cleanup, raw-option rejection, symmetric fluent-transport versus explicit-handler/client rejection in both call orders, manual `new Factory`, and facade annotations;
- invalid arguments are attempted once by the default retry policy in sync and handler-rejected async paths; an explicit predicate can opt in; sync/async predicates receive the current method, pre-request failures receive `null`, reused pending requests do not expose stale methods, and fresh `getPromise()` returns `null`; informational/redirect responses return once without invoking retry/`throwIf`, including a real async `Response` rather than `null`;
- one table-driven classifier suite for every Guzzle request option, proven internal key, shared connection-cap/multiplex mode, version-specific sharing mode, body category, protocol, coroutine state, URI userinfo, IPv6 literal, and strict error message; `_conditional` remains unrecognized because Guzzle removes it before the handler seam;
- TLS classifier rows cover explicit min/min+max, Guzzle 7 versus 8 absent-min and max-only HTTPS behavior, Guzzle 8 scheme-aware low max handling, invalid constants/ranges, the universal Hypervel TLS 1.2 minimum taking native, and TLS 1.2+ still negotiating TLS 1.3;
- reflection drift tests under Guzzle 7 and 8 that report exact new/missing constants; runtime unknown keys prove fail-closed fallback without a duplicative baseline-capture mechanism;
- auto selects native only for supported inputs; every declined input reaches the untouched fallback; strict mode never silently falls back; curl mode never constructs/leases native clients;
- request/response conversion, response/stream factories before Engine interaction, finite integer/float delay before pool acquisition, exact `decode_content` behavior with live encoding/length removal, mixed-case duplicate header preservation through `withAddedHeader()`, standard reason phrases, documented custom-reason loss, transfer time/error data/transport stats, and nested `on_stats` callbacks exactly once;
- upper middleware through native: redirects, cookie jar, JSON/form preparation, HTTP errors, retries that rewind and resend the complete replayable body, fakes, stray policy, recording, before/after callbacks, and events;
- pool identity across default/named connections, origins, TLS inputs, request-only changes, pool policy changes, reconfiguration, idle recycling, max lifetime, exhaustion, closed-pool diagnostics, and parallel fan-out above/below `max_objects`;
- HTTPS requests with different effective TLS ranges use different pools, while plain HTTP requests do not split identity on TLS-only options;
- strict-Swoole/async rejection for factory defaults, registered connections, fluent overrides, both mutator orders, and the final build invariant; raw-Guzzle bridge rejection; auto/curl async fallback; synchronous success release, unconditional Engine-runtime/busy discard even when a fake remains connected, connected pre-I/O/response-conversion release, disconnected discard, no poisoned reborrow, and pool close cleanup under Promises 2/3.

Use a protected construction seam or direct internal collaborators for test doubles; do not add a public transport registry/factory solely for tests.

### Generic lifecycle and observability

- typed Object Pool exhaustion is the only reclassified runtime path;
- worker exit flushes idle pools, late borrowed returns are destroyed, repeated flush is safe, and an unused manager is not resolved;
- Foundation teardown flushes the resolved Object Pool after callbacks/database cleanup and before application flush, preserves the first exception, and leaves suites without pools untouched;
- Telescope native synchronous and fallback sync/async success and rejection record once, preserve callbacks/tags/redaction, and include duration/transport only when truthful; a real `Http::fake()` request omits both keys rather than storing a fabricated zero;
- Sentry native synchronous and Guzzle sync/async success/response error/connect error/timeout finalize through stats; outgoing trace headers name the HTTP child; concurrent async requests remain sibling spans; out-of-order completion/cancellation leaves the original parent current; direct and downstream-promise cancellation finish immediately and once; pending/already-settled rejection without stats preserves the exact rejection; and stats followed by rejection/cancellation does not change the first end timestamp.

### Guzzle-major and consumer matrix

The normal Guzzle 7 lane runs the complete `composer fix`/Testbench dogfood workflow and Engine integrations. The focused Guzzle 8 lane runs at minimum:

- `tests/Http`, `tests/Engine`, `tests/ObjectPool`, filtered `tests/Foundation`, `tests/Sentry`, `tests/Telescope`;
- `tests/Broadcasting`, `tests/Console`, `tests/Notifications`, `tests/Socialite`, `tests/Inertia`, `tests/ApiClient`;
- all non-Algolia `tests/Scout` plus Meilisearch/Typesense integrations;
- `tests/Integration/Engine` and `tests/Integration/HttpServer` with the engine test servers;
- relevant package metadata, static analysis, facade documentation, and Testbench package-mode tests.

Test native synchronous and fallback sync/async behavior on both majors. No timing assertion belongs in CI.

For both majors, cover read timeout, connect timeout, DNS failure, pre-header reset, and a truncated response which sends status/headers plus a short body before reset. Use the deterministic process-mode TLS and literal-byte socket fixtures above (a stalled TLS handshake for connect timeout, delayed response for read timeout, accept-and-close for reset) plus the reserved `.invalid` DNS namespace; assert the public Hypervel `ConnectionException` for native synchronous and fallback sync/async paths. For failures before headers, also assert the exact native-versus-fallback Guzzle exception class at the bottom-handler seam. For truncation, assert the intentional lower-level evidence difference and the identical public classification without fabricating a native response. On Guzzle 7, separately prove that response-bearing `on_headers`, `on_trailers`, and non-rewindable redirect failures with a previous throwable still take the response path, while the old truncated-response expectation is deliberately replaced.

## Benchmark and default decision

Add a maintained developer harness under `tests/Benchmarks/HttpTransport/`, following the existing benchmark layout, with a dependency-free Node standard-library HTTP/HTTPS origin so the comparison is not confounded by a Swoole server. Node is justified here because PHP's built-in server has no TLS support and its default serial model distorts the concurrency comparison; writing and maintaining a custom PHP TLS/event-loop server solely for a benchmark would be worse complexity. The repository already has a root Node workspace and JavaScript fixtures. Keep the README, origin, local test certificate, and runner together; do not ship a production command or add an npm dependency.

Measure Guzzle fallback, explicit native, and auto with identical middleware/options:

- cold and warm keep-alive, HTTP and TLS;
- sequential and coroutine-parallel loads at several concurrency levels;
- small/large request and response bodies, ordinary JSON/form, and an Inertia-SSR-shaped POST;
- loopback, deterministic server delay, and an optional caller-supplied representative-RTT target;
- median/p95 latency, throughput, process CPU, peak memory, and file descriptors; and
- direct native versus auto on the same supported request to isolate classifier/routing cost.

Warm up, alternate run order, repeat enough rounds to avoid one-shot noise, record PHP/Swoole/Guzzle/cURL/OS/CPU versions, and never claim universal ratios from one machine.

`auto` always means native when supported and Guzzle otherwise. The benchmark decides only the factory default:

- choose `auto` when supported representative workloads improve materially without a meaningful regression or resource increase;
- otherwise default to `curl`, while shipping complete opt-in `auto`/`swoole` support.

Record results and the chosen default in this plan and the PR before completion. A nanosecond-scale classifier result is expected to be noise beside I/O, but measurement—not assumption—closes the decision.

## Implementation order

1. Add Engine composition, category-strict settings, split send/receive lifecycle, errors, and focused tests.
2. Add typed Object Pool exhaustion, switch `PoolFingerprint` to `xxh128`, and add generic worker/test cleanup with their tests/dependency.
3. Replace `ReservedOptions`' transport-sharing boolean with an explicit allowed-key list for its call context, correct retry/property state, then add the HTTP transport handler, capability classifier, synchronous pool ownership, factory/PendingRequest APIs, strict-async guard, and Guzzle 7 tests. Registered connection validation alone allows `transport`, `pool`, and handler construction keys; all request-option entry points reject infrastructure keys with the dedicated-API remedy.
4. Complete conversion fidelity, fallback stats, exception adaptation, upper-middleware integrations, and actual server tests.
5. Update Guzzle constraints/code and add the Guzzle 8 CI lane; resolve every affected package/test failure without version forks scattered through source.
6. Integrate Telescope/Sentry and fix the cancellation-only span leak.
7. Add docs/facade/package metadata, delete dead/stale source and prose, then run stale-symbol searches.
8. Run the benchmark, record the default decision, and complete the full validation matrix.

After each slice, run focused suites. Before handoff run formatter, static analysis, `composer test:parallel`, Testbench package-mode/dogfood, Engine integration workflow, Guzzle 8 lane, `git diff --check`, and searches for removed/stale symbols and cURL-only claims.

## Explicitly rejected designs

- no standalone Swoole HTTP package or ClickHouse-specific framework adapter;
- no Engine dependency on Object Pool;
- no Engine inheritance from the native client or exposure of raw Swoole methods;
- no transport extension registry/`Factory::extend()`, enum, convenience selector methods, or second PSR-18 discovery client;
- no automatic monkey-patching of arbitrary third-party Guzzle clients or multi handlers;
- no per-request pool mutator, per-call infrastructure config, pool refcount registry, LRU origin registry, or separate non-pooled native path;
- no native pending-promise owner, custom Promise/A+ implementation, child-coroutine promise settlement, context copying, global pending-transfer tracker, lazy response, or fabricated cancellation stats;
- no native streaming/sink/download shortcut, multipart encoder, proxy approximation, URI-userinfo authentication encoder, CURLOPT capability table, HTTP/2 claim, reason-phrase parser, or silent strict fallback;
- no Node or MockServer integration origin, second CI runtime, service container, or HTTP fixture framework; Node is retained only for the non-Swoole benchmark;
- no new total-deadline/retry policy or RawResponse timing/transport fields;
- no Guzzle 8-only minimum, Composer alias, alternate committed lock, or deletion of Guzzle 7/Algolia coverage; and
- no brittle CI benchmark thresholds, temporary harness, compatibility comments, dead methods, or duplicated documentation.

## Completion criteria

- Every public mode/API, precedence rule, capability refusal, and Guzzle-major difference is tested and documented.
- Supported native requests are semantically equivalent at Hypervel's public boundary where Swoole exposes the required evidence; bottom-handler exception evidence also matches where available. The documented differences are unavailable partial diagnostics for truncated responses and unavailable custom wire reason phrases. Truncation is normalized to `ConnectionException`; standard status phrases remain conventional; unsupported requests deterministically use the unchanged fallback or throw in strict mode.
- No native client is concurrently reused, returned with unread state, leaked across handler replacement, or retained past worker/test shutdown.
- Guzzle 7 full CI and the focused Guzzle 8 lane pass, with the Algolia blocker accurately isolated rather than hidden.
- Telescope records native synchronous and fallback sync/async success/rejection once with truthful transport evidence. Sentry keeps propagation, span nesting, Hub context, and once-only finalization correct for native synchronous and fallback sync/async/error/timeout/cancellation paths; abandoned promises finish only when their real transport completes because deterministic abandonment would require rejected global ownership machinery.
- Benchmarks against a non-Swoole origin determine and record the factory default; classifier overhead is noise and no supported representative workload has an unexplained regression.
- Formatter, static analysis, all affected suites, integration servers, Testbench package mode/dogfood, facade metadata, package metadata, and `git diff --check` pass.
- Final searches find no old inherited Engine API assumptions, dead reusable-client helpers, stale non-pooled/cURL-only claims, migration TODOs, temporary code, or documentation conflicts.
