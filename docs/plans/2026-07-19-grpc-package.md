# Hypervel gRPC Package — Complete Implementation Plan

## 1. Outcome

Build one first-party `hypervel/grpc` package that contains the shared gRPC wire implementation, a coroutine-native HTTP/2 client, and a Hypervel-native server. The public API should feel like Laravel where Laravel concepts fit (facade-based route registration, ordinary service classes, middleware, container injection, immutable fluent response metadata), while call-shape vocabulary follows the official gRPC PHP API (`wait`, `read`, `write`, `writesDone`, and `responses`).

The package is a redesign informed by Hyperf's `grpc`, `grpc-client`, and `grpc-server` packages, not a line-for-line copy. Hyperf's generic RPC adapters and their dependencies do not belong in the result. The final implementation must contain no dormant compatibility layers, speculative service-governance abstractions, unused transport accessors, conditional feature shells, or comments describing code that no longer exists.

The completed package supports:

- unary and server-streaming RPCs on a Hypervel server;
- unary, server-streaming, client-streaming, and bidirectional-streaming calls from a Hypervel client to any interoperable gRPC server;
- standard gRPC framing, metadata, status, rich error details, deadlines, identity/gzip compression, message-size limits, and explicit retry policies;
- generated-style client stubs by retaining the four protected method names used by official PHP generated clients;
- the standard `grpc.health.v1` `Check`/`List` service and client, with an application-replaceable health provider and an explicit platform-correct `Watch` fallback;
- a dedicated HTTP/2 gRPC port and isolated router, without sending gRPC calls through the application's HTTP router or inventing a new server type.

Server-side client streaming and bidirectional streaming are not represented by public APIs because Swoole 6.2.2 only invokes `onRequest` after the complete HTTP/2 request body has arrived and exposes no incremental request DATA API. Client cancellation is likewise absent because Swoole 6.2.2 has no per-stream `RST_STREAM` client API. These are platform-shaped boundaries, not partially implemented features.

## 2. Decisions and evidence

### 2.1 Sources reviewed

Implementation must be checked against these exact local and primary references:

- Hyperf shared wire package: `../../../examples/hyperf/hyperf/src/grpc/`
- Hyperf direct and generic-RPC client package: `../../../examples/hyperf/hyperf/src/grpc-client/`
- Hyperf HTTP server integration: `../../../examples/hyperf/hyperf/src/grpc-server/`
- Hyperf gRPC documentation: `../../../examples/hyperf/hyperf/docs/en/grpc.md`
- Official gRPC repository: `.tmp/grpc-reference/`, checked out at `8542e01ff47e`; in particular `doc/PROTOCOL-HTTP2.md`, `doc/http-grpc-status-mapping.md`, the core HTTP filter/status processing, and the core client call state machine
- Official grpc-go repository: `.tmp/grpc-go-reference/`, checked out at `75f3c0bb2866`; in particular transport header parsing, rich-status verification, timeout handling, retry commitment, and streaming call behavior
- Official status, metadata, deadline, compression, retry, error, and performance guides:
  - <https://grpc.io/docs/guides/status-codes/>
  - <https://grpc.io/docs/guides/metadata/>
  - <https://grpc.io/docs/guides/deadlines/>
  - <https://grpc.io/docs/guides/compression/>
  - <https://grpc.io/docs/guides/retry/>
  - <https://grpc.io/docs/guides/error/>
  - <https://grpc.io/docs/guides/performance/>
- Official PHP generated-stub and call vocabulary: `.tmp/grpc-php/src/lib/`, checked out at grpc/grpc tag `v1.82.0` (`be984cb608f2`)
- Official gRPC health protocol: `.tmp/grpc-proto-reference/grpc/health/v1/health.proto`, checked out at `99135b19189588fcc787acb84cff27991787473d`; this current schema defines `Check`, `List`, and `Watch`, including the specified `UNIMPLEMENTED` fallback for a server that cannot support `Watch`
- Swoole HTTP/2 implementation: `.tmp/swoole-src/`, checked out at tag `v6.2.2` (`8e8c499`) with tag `v6.2.0` fetched for the package-minimum audit. The relevant HTTP/2 client, server, and response-emission source files are identical between those tags; `isStreamExist`, `usePipelineRead`, `Response::trailer`, and the verified trailer-finalization path therefore exist throughout the declared `^6.2` range.
- Hypervel HTTP/2 abstraction: `src/contracts/src/Engine/Http/V2/` and `src/engine/src/Http/V2/`
- Hypervel routing dispatch: `src/routing/src/{Router,Route,ControllerDispatcher,CallableDispatcher,ResolvesRouteDependencies}.php`
- Hypervel server and response emission: `src/server/src/`, `src/http/src/IterableStreamedResponse.php`, and `src/http-server/src/{Server,ResponseBridge}.php`
- Isolated-server precedents: `src/reverb/src/Servers/Hypervel/` and `src/websocket-server/src/`

### 2.2 Why one package

Create only `hypervel/grpc`, with client/server separation expressed by namespaces and directories.

Hyperf's three packages reflect Hyperf's micro-package style and its generic-RPC ecosystem. The shared `grpc` package contains only framing/status/path helpers, while `grpc-client` and `grpc-server` bridge those helpers into other Hyperf layers. Hypervel has no real consumer that needs the three-file wire package without either side, and no known consumer that benefits from installing client classes while excluding server classes at Composer resolution time. A two-package split fails the same concrete-consumer test. Namespace boundaries retain clarity without adding manifests, discovery wiring, release coordination, and cyclic design pressure.

If a real independently installable client-only consumer appears, the namespace boundary makes extraction straightforward. A hypothetical consumer must not dictate today's package structure.

### 2.3 Why the generic RPC packages are not ported

Do not port Hyperf's `rpc`, `rpc-client`, `rpc-server`, `service-governance`, or `load-balancer` packages for this work. Do not port these gRPC adapters:

- `DataFormatter`
- `GrpcPacker`
- `GrpcNormalizer`
- `GrpcTransporter`
- generic client proxies
- protocol/service registration listeners
- generic service-discovery health registries, resolvers, or load-balancer selection

The direct Hyperf gRPC client does not need the generic RPC layer. The gRPC server only enters it behind optional configuration, and the adapters exist to fit gRPC into Hyperf's generic transporter/packer model rather than to implement gRPC itself. `GrpcPacker` even uses PHP serialization, which is unrelated to the gRPC wire protocol. Kubernetes DNS, a service mesh, or an application-owned endpoint choice already covers common deployment topology. A future Hypervel JSON-RPC package should be designed as its own protocol package; shared abstractions should be extracted only after a second concrete implementation proves the common shape.

### 2.4 Hyperf defects and accidental behavior that must not survive

| Hyperf behavior | Verified problem | Final correction |
|---|---|---|
| `BaseClient` stores connection selection under one class-level coroutine key | Multiple client objects in one coroutine share an index; clients with different connection counts can select an invalid slot | Hypervel chooses a connection per new call with an instance-owned round-robin counter; no coroutine key exists |
| `GrpcClient` borrows send/result channels from a shared pool and never releases both paths | Worker-lifetime channel leak | Every connection and stream owns and deterministically closes its channels; the shared pool is not used |
| `CoreMiddleware` checks an unimported `Status` name | The `Google\Rpc\Status` branch is dead | Rich status is handled through explicit `Google\Rpc\Status` imports and a single status codec |
| `BaseClient::__destruct()` closes and can rethrow | Destructor exceptions are unsafe during shutdown; native channel teardown can be fatal after Swoole is gone | No destructor; explicit idempotent `close()` only |
| `BaseClient::__call()` and `_getGrpcClient()` expose/magic-forward the concrete transport; `close()` then silently reconnects on the next call | Public lifecycle and transport ownership become ambiguous and generated stubs can depend on internals | No magic forwarding or raw-client accessor; `close()` is terminal and later calls fail clearly |
| `StreamingCall` exposes mutable setup setters plus generic `send()`/`recv()` tuple shapes for every stream kind | Illegal call operations remain representable and protocol/status handling leaks into application code | Four typed call classes expose only `wait`, `read`, `responses`, `write`, and `writesDone`; messages return directly and non-OK completion throws |
| `StreamingCall::recv()` returns a hard-coded clean end whenever Swoole marks the final response `pipeline === false` | The final incremental response is precisely where Swoole exposes the peer's trailing metadata and `grpc-status`; Hyperf discards it, so a streamed non-OK completion can be reported as OK | Every final response event passes through the same status/metadata codec before `read()` returns `null`; the exact final-trailer regression is covered with both mocked Swoole event shapes and the independent Go peer |
| `Parser::unpack()` assumes one recv chunk equals one complete gRPC message | HTTP/2 DATA boundaries and gRPC message boundaries are independent | Stateful frame decoder accepts partial and multiple frames |
| Frame parsing does not validate the flag, declared length, compression, or size | Malformed/oversized messages can be accepted or misread | Strict flag/length/compression checks, applying the receive limit to both the declared wire payload and decompressed message |
| Client timeout only bounds a channel pop | The deadline is not propagated to the server | One absolute monotonic deadline drives `grpc-timeout`, waits, retries, and backoff |
| Silent three-attempt retry with fixed 100 ms sleeps | Hidden replay can duplicate work and does not implement gRPC retry commitment | No implicit retry; typed opt-in policy and spec commitment rules |
| HTTP response codes are treated as gRPC status values | HTTP and gRPC status spaces are different | Use only the official HTTP-to-gRPC fallback table when `grpc-status` is absent |
| `PathGenerator` builds `/grpc.{short service}` | That is a Hyperf convention, not the standard path | Exact `/{fully-qualified-service}/{method}` paths, case-sensitive |
| `WAIT_PENDDING` and related states conflate shutdown intent with connection state | Misspelled and not a meaningful public call state | No shutdown enum unless the rewritten implementation has a real state requiring it; the final design does not |
| Client talks directly to `Swoole\Coroutine\Http2\Client` | Bypasses Hypervel's transport boundary | gRPC imports only Hypervel engine HTTP/2 contracts; Swoole stays inside `hypervel/engine` |
| Server response uses Swoole's internal `pipeline` conventions directly | Couples protocol semantics to one driver and has no shared trailer path | Protocol-neutral trailer emission lives in `hypervel/http-server`; gRPC only supplies framed bodies and trailer values |
| Hyperf's gRPC manifests import generic-RPC classes through undeclared or merely suggested packages | Split-package installation can load classes whose direct dependencies Composer never required | The new package and every touched framework package declare each directly imported package; root `replace`/autoload/discovery metadata mirrors the split manifest |

### 2.5 Protocol invariants

These are acceptance requirements, not optional refinements:

1. Requests use HTTP/2 `POST` and exact paths `/{Service-Name}/{method}`.
2. Request headers include `content-type: application/grpc+proto` and `te: trailers`.
3. Each message is `[compressed flag: 1 byte][length: unsigned 32-bit big-endian][payload]`.
4. The compression flag is only `0` or `1`; `1` requires a negotiated `grpc-encoding`.
5. `grpc-timeout` is at most eight decimal digits followed by `H`, `M`, `S`, `m`, `u`, or `n`; encoding rounds upward so the transmitted deadline is never shorter than the caller requested.
6. Metadata keys are lowercase ASCII `[0-9a-z_.-]+`. User metadata cannot use HTTP/2 pseudo-header names or reserved `grpc-*` names.
7. `-bin` metadata values are raw bytes in the public object, unpadded base64 on emission, and accept padded or unpadded base64 on receipt. Comma-separated binary values are split before decoding.
8. Ordinary ASCII metadata is not URL encoded. Only `grpc-message` uses the protocol's percent encoding.
9. A valid gRPC response uses HTTP 200 even for a non-OK RPC status. `grpc-status` is always present in final trailers, including `0` for success. Trailers-only responses are valid.
10. A non-gRPC content type or a gRPC subtype this protobuf-only package does not implement is rejected with HTTP 415 so a client does not misinterpret its representation.
11. If a client receives no `grpc-status`, synthesize it from HTTP status: 400 → `Internal`; 401 → `Unauthenticated`; 403 → `PermissionDenied`; 404 → `Unimplemented`; 429/502/503/504 → `Unavailable`; every other HTTP status, including 200, → `Unknown`.
12. Never reverse that fallback table to choose server HTTP status codes.
13. Unsupported inbound message compression returns `Unimplemented` and advertises `grpc-accept-encoding`; a corrupt payload for a supported encoding returns `Internal`.
14. A malformed protobuf request returns `Internal`; an unknown method returns `Unimplemented`; an unhandled service exception returns `Unknown` and is reported to Hypervel's exception handler.
15. Rich error details use `grpc-status-details-bin` with `Google\Rpc\Status`, and its embedded numeric code must match `grpc-status`.
16. Retries are opt-in. A call is committed when response headers arrive or a response message is delivered. A trailers-only retryable status may be retried; a response with separate initial metadata may not.
17. A single deadline spans every retry attempt and every backoff.
18. Reuse client stubs and their HTTP/2 connections rather than creating a socket per RPC.
19. Retried requests report the count of prior attempts, and a valid server retry-pushback trailer overrides the next backoff while a negative/invalid value stops retry.
20. The dedicated server rejects HTTP/1.x before gRPC media-type handling; this package implements native gRPC over HTTP/2, not gRPC-Web or an HTTP/1 compatibility transport.

### 2.6 Swoole 6.2.2 runtime findings

The standalone spike is retained only in ignored `.tmp/grpc-stream-spike.php`; no spike code enters the package. It ran against the installed PHP 8.4.23 / Swoole 6.2.2 runtime and was cross-checked against the exact Swoole source.

For a complete unary request with an incrementally read server response, the correct native request flags are:

```php
$request->pipeline = false;       // request DATA ends with END_STREAM
$request->usePipelineRead = true; // response DATA/trailers arrive incrementally
```

The spike proved that a server can call `write()` for framed response messages, set trailers, and complete the stream. The Swoole client receives initial headers/message DATA, further DATA, and final trailers as separate responses when `usePipelineRead` is true.

It also exposed a precise Swoole 6.2.2 finalization rule:

- `write(frame1)`, set trailers, then `end(frame2)` sends both messages and the final trailers correctly.
- `write(frame1)`, `write(frame2)`, set trailers, then `end()` sends an empty END_STREAM DATA frame before Swoole can emit the trailer block; the trailers are lost/invalid.
- no-message trailer-bearing responses complete when trailers are set before `end()`.

Therefore trailer-aware streamed emission must retain one chunk. It writes each previous chunk, sets trailers after the producer completes, and passes the retained final chunk to `end($lastChunk)`. This behavior belongs in the shared response bridge because it is a protocol-neutral rule for emitting any Swoole streamed response with trailers.

The source review refines the no-message case: populating native `trailer()` always causes Swoole to emit an initial HEADERS block without END_STREAM and then a second final trailer block, even when the body is empty. That is a valid empty response but it is not gRPC Trailers-Only and it commits a retryable call. For a genuine Trailers-Only response, gRPC places `grpc-status`, `grpc-message`/details, and custom trailing metadata in the ordinary Symfony header bag, supplies no native trailers, and calls `end()` with an empty body; Swoole then sets END_STREAM on that single HEADERS block. The gRPC response factory owns this protocol choice. The shared bridge simply observes that `trailers()` is empty and uses its normal header/end path.

The source review also proved:

- `Swoole\Http\Request` exposes only the completed request body to `onRequest`; server-side client/bidi streaming cannot be implemented faithfully.
- an inbound server-side `RST_STREAM` only removes the native HTTP/2 stream; there is no PHP request-handler callback. `Swoole\Http\Response::isWritable()` checks only whether the local response context has ended or detached, not whether that HTTP/2 stream was reset. A producer that has no new item to write therefore cannot observe an idle client cancellation. This is why the package exposes no server cancellation promise and why the standard health `Watch` method must use its specified `UNIMPLEMENTED` fallback rather than leaving a generator parked forever.
- `Swoole\Http\Response` exposes no header-only flush operation for HTTP/2. Setting `status()`/`header()` only mutates the pending response; the first non-empty `write()` or `end()` actually sends the header block, and `write('')` is rejected. Therefore a lazy server stream cannot commit initial metadata before it has a first message without corrupting the gRPC message stream. Hypervel treats `withInitialMetadata()` like queued gRPC header metadata, primes every server stream by one item, and lets an empty/pre-yield completion collapse queued metadata into the one Trailers-Only block, matching the protocol's only available wire form.
- Swoole ignores a caller-supplied HTTP/2 `content-length` and injects its own whenever the initial header block is emitted through `end($body)`, including `content-length: 0` for no-argument/empty `end()`. If streamed emission reaches `write()` first, it sends headers without that field. Complete outbound metadata accounting must model that native behavior rather than trusting the Symfony header bag.
- `Swoole\Coroutine\Http2\Client` has no per-stream reset/cancel API.
- Swoole 6.2.2 consumes an inbound `RST_STREAM`, deletes the native stream, and continues inside `recv()` without returning the response object/error code to PHP. The engine must expose whether a known stream remains open so the gRPC receiver can detect that per-stream failure without closing unrelated calls.
- Swoole only marks a response as incrementally readable after non-final DATA. If an external peer sends a non-final initial HEADERS block followed directly by final trailers with no DATA, the PHP client receives one merged final response and cannot distinguish it from genuine one-block Trailers-Only. Hypervel enables incremental reads for every call and observes commitment as soon as Swoole exposes it, but opt-in retry must treat this one indistinguishable zero-message form as Trailers-Only. The same merge erases the original per-block boundary for inbound metadata-size accounting: distinct fields are conservatively counted together and duplicate keys may already have been overwritten. Document and independently test this narrow platform limitation; do not claim perfect header-block commitment or size visibility.
- native `send_request()` and `write_data()` emit a logical operation through multiple socket writes and mutate shared HPACK/stream state. The entire send/write operation must be serialized per connection; exposing Hyperf's `send_yield` switch would make correctness configurable and is not acceptable.
- a failed native `send()` can occur after partial bytes left the process, so it does not prove an RPC was unsent and cannot trigger transparent replay.
- Swoole stores received request and response headers/trailers in associative arrays. Independently repeated fields can be lost unless the peer combines them; PHP therefore cannot detect every duplicated singleton protocol field or recover its original contribution to the peer's header-list size. Swoole also consumes inbound `:scheme` without exposing it. Hypervel preserves repeated metadata in its API, combines outbound values, fully supports comma-separated binary metadata, validates and measures everything the transport exposes, and documents this native limitation rather than claiming byte-exact inbound visibility.

Implementation-time deadline integration exposed another Swoole timing boundary. A positive coroutine-channel timeout may return slightly before an absolute `hrtime()` deadline because Swoole's timer scheduling is millisecond-granular while gRPC deadlines use monotonic nanoseconds. The observed 50 ms waits straddled the intended instant by less than one millisecond. `Hypervel\Coordinator\Timer` previously treated one coordinator wait as proof that the requested interval elapsed, so its callback could cancel a request just before `ServerCallContext::deadlineExceeded()` became true. Fix the shared timer: capture the monotonic start before creating an `after()` coroutine, recheck elapsed monotonic time after every non-closing wake, and wait the remainder. `tick()` performs the same check from a fresh start after each callback, preserving its existing fixed-delay behavior. Do not add a gRPC-only tolerance or duration floor.

That early cancellation also exposed a shared exception-boundary defect. Response-bearing server callbacks were registered directly with Swoole, so an escaped cancellation or emission failure reached the global PHP exception handler. Its Laravel-SAPI `render(...)->send()` path then called the deliberately unsupported `Hypervel\Http\Response::send()`, causing a second exception; Swoole 6.2.2 terminated the worker with signal 11. Hypervel must not depend on the native crash being fixed: `hypervel/server` supplies the last response-aware boundary for `ON_REQUEST` and `ON_HANDSHAKE`, while the non-console global exception backstop becomes report-only because it has no native Swoole response to emit. The signal-11 defect and future upstream isolation/PHPT work are recorded in the monorepo's `_tmp/swoole-prs.md`; no Swoole reproducer or patch belongs in this framework change.

## 3. Final architecture

### 3.1 Package map

```text
src/grpc/
├── LICENSE.md
├── README.md
├── composer.json
├── config/
│   └── grpc.php
├── resources/
│   └── proto/
│       ├── LICENSE-Apache-2.0.txt
│       ├── README.md
│       └── grpc/health/v1/health.proto
├── stubs/
│   └── grpc.php
└── src/
    ├── Compression.php
    ├── GrpcServiceProvider.php
    ├── Metadata.php
    ├── Status.php
    ├── StatusCode.php
    ├── Console/
    │   └── InstallCommand.php
    ├── Exceptions/
    │   ├── ConnectionException.php
    │   ├── GrpcException.php
    │   ├── ProtocolException.php
    │   └── RpcException.php
    ├── Health/
    │   ├── HealthClient.php
    │   ├── HealthService.php
    │   ├── HealthStatusProvider.php
    │   ├── ServingHealthStatusProvider.php
    │   ├── ServingStatus.php
    │   └── V1/                  # generated health messages/metadata
    ├── Protocol/
    │   ├── Deadline.php
    │   ├── FrameDecoder.php
    │   ├── FrameEncoder.php
    │   ├── MediaType.php
    │   ├── MessageSerializer.php
    │   ├── MetadataCodec.php
    │   ├── ServiceMethod.php
    │   ├── StatusCodec.php
    │   └── Timeout.php
    ├── Client/
    │   ├── BaseClient.php
    │   ├── BidiStreamingCall.php
    │   ├── Call.php
    │   ├── ClientStreamingCall.php
    │   ├── Connection.php
    │   ├── Endpoint.php
    │   ├── Request.php
    │   ├── RetryBackoff.php
    │   ├── RetryPolicy.php
    │   ├── ServerStreamingCall.php
    │   ├── StreamState.php
    │   └── UnaryCall.php
    └── Server/
        ├── CallContextStore.php
        ├── ExceptionMapper.php
        ├── GrpcHttpResponse.php
        ├── GrpcResponse.php
        ├── GrpcRouteRegistrar.php
        ├── GrpcRouter.php
        ├── GrpcStreamedResponse.php
        ├── PendingGrpcRegistration.php
        ├── Pipeline.php
        ├── ResponseFactory.php
        ├── Server.php
        ├── ServerCallContext.php
        └── Middleware/
            └── HandleCall.php
```

The first-party facade follows the repository-wide convention and lives at `src/support/src/Facades/Grpc.php` as `Hypervel\Support\Facades\Grpc`. Like the existing optional-package `Jwt` facade, its lazy accessor string does not make `hypervel/support` depend on the optional package. Generated health PHP files live under `Health/V1`; their source proto is production package input, not a test fixture.

Classes that are transport machinery rather than supported application APIs receive `@internal` class docblocks. Do not create a public compressor registry, channel class, client manager, named-client repository, connection-state enum, shutdown enum, route subclass, controller dispatcher, response tuple, or generic RPC contract.

### 3.2 Responsibility boundaries

```text
Application service / generated-style stub
        │
        ├── server: Grpc facade → GrpcRouteRegistrar → isolated GrpcRouter
        │                                              → HandleCall → normal Route dispatcher
        │                                                │
        │                                                └── protocol response factory
        │
        └── client: BaseClient → call object → Connection → Engine HTTP/2 contract

Shared gRPC protocol: framing, metadata, timeout, status, compression, protobuf serialization

Shared Hypervel layers:
  routing      owns route matching and middleware pipeline construction
  http-server  owns status/header/body/trailer emission to Swoole
  engine       owns the raw Swoole HTTP/2 client
  server       owns port ordering and shared server TLS-option translation
```

Protocol-neutral capabilities go to the owning framework package. gRPC rules and values remain in `hypervel/grpc`. No other package learns what `grpc-status`, protobuf framing, or a gRPC deadline means.

## 4. Public API

### 4.1 Server route registration

`Hypervel\Support\Facades\Grpc` resolves a narrow `GrpcRouteRegistrar`; it is not a client facade. The registrar delegates to an internal isolated `GrpcRouter`, so inherited HTTP verbs and dispatch methods never become accidental facade APIs.

```php
use App\Grpc\GreeterService;
use Hypervel\Support\Facades\Grpc;

Grpc::service('helloworld.Greeter', function (): void {
    Grpc::unary('SayHello', [GreeterService::class, 'sayHello'])
        ->middleware('auth:service')
        ->name('grpc.greeter.say-hello');

    Grpc::serverStream('ListGreetings', [GreeterService::class, 'listGreetings'])
        ->middleware(TraceGrpcCall::class);
});
```

Apply attributes once to a whole service with the narrow Laravel-style pending registrar:

```php
Grpc::middleware(['auth:service', TraceGrpcCall::class])
    ->name('grpc.greeter.')
    ->service('helloworld.Greeter', function (): void {
        Grpc::unary('SayHello', [GreeterService::class, 'sayHello'])
            ->name('say-hello');

        Grpc::serverStream('ListGreetings', [GreeterService::class, 'listGreetings'])
            ->name('list-greetings');
    });
```

Equivalent fully qualified registration remains useful for generated route files:

```php
Grpc::unary(
    'helloworld.Greeter/SayHello',
    [GreeterService::class, 'sayHello'],
);
```

The facade annotations and registrar signatures are:

```php
/**
 * @method static \Hypervel\Routing\Route unary(string $method, array|string|callable $action)
 * @method static \Hypervel\Routing\Route serverStream(string $method, array|string|callable $action)
 * @method static void service(string $service, \Closure $routes)
 * @method static \Hypervel\Grpc\Server\PendingGrpcRegistration middleware(array|string $middleware)
 * @method static \Hypervel\Grpc\Server\PendingGrpcRegistration withoutMiddleware(array|string $middleware)
 * @method static \Hypervel\Grpc\Server\PendingGrpcRegistration name(string $name)
 */
class Grpc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GrpcRouteRegistrar::class;
    }
}

final class GrpcRouteRegistrar
{
    private ?string $service = null;

    public function __construct(private readonly GrpcRouter $router);

    public function unary(
        string $method,
        array|string|callable $action,
    ): \Hypervel\Routing\Route;

    public function serverStream(
        string $method,
        array|string|callable $action,
    ): \Hypervel\Routing\Route;

    public function service(string $service, \Closure $routes): void;

    public function middleware(array|string $middleware): PendingGrpcRegistration;
    public function withoutMiddleware(array|string $middleware): PendingGrpcRegistration;
    public function name(string $name): PendingGrpcRegistration;
}
```

The `Grpc` class in that snippet is implemented in `hypervel/support`, while its accessor remains the package-owned registrar. Do not add a second package-local facade or a global class alias.

`PendingGrpcRegistration` supports only immutable `middleware()`, `withoutMiddleware()`, and `name()` modifiers followed by `unary()`, `serverStream()`, or `service()`. It applies those ordinary route-group attributes around delegated registration; it does not expose HTTP verbs, URI prefixes/domains, controller groups, fallback routes, dispatch, or mutable router access. This covers the concrete service-wide middleware/name-prefix use case with concepts Laravel developers already know.

```php
final readonly class PendingGrpcRegistration
{
    public function middleware(array|string $middleware): self;
    public function withoutMiddleware(array|string $middleware): self;
    public function name(string $name): self;
    public function unary(string $method, array|string|callable $action): \Hypervel\Routing\Route;
    public function serverStream(string $method, array|string|callable $action): \Hypervel\Routing\Route;
    public function service(string $service, \Closure $routes): void;
}
```

Rules enforced at registration time:

- a full method contains exactly one service/method slash after trimming an optional leading slash;
- service and method segments follow protobuf identifier rules, including dots only in the fully qualified service name;
- no URI placeholders, wildcard routes, alternate HTTP verbs, optional methods, or fallback gRPC routes;
- paths remain case-sensitive;
- `service()` applies an exact service prefix and accepts only relative method names inside its closure;
- nested `service()` blocks are rejected because a gRPC method has exactly one fully qualified service segment;
- both registration methods return the ordinary `Hypervel\Routing\Route`, preserving fluent `middleware`, `withoutMiddleware`, and `name` APIs.

The singleton registrar is intentionally stateful and non-readonly: it keeps its nullable current service only while the boot-time `service()` closure runs and restores it in `finally`, just as the framework router must restore its group stack in `finally`. Route-file loading is synchronous before workers serve calls, so this temporary registration context is never request-shared. A throwing route file therefore cannot poison later registration, and no process-wide static service state survives a test or worker bootstrap.

`GrpcRouteRegistrar` plus its narrow pending value are the complete public registration surface. There is deliberately no public `post()` alias: the protocol requires POST, so exposing the transport verb would make the call shape less expressive. `GrpcRouter` must still extend the framework router to reuse its dispatch machinery, but is marked `@internal` and is not the facade accessor. Both registrar and client delegate service/method validation and exact path generation to the same internal `Protocol\ServiceMethod` value described in section 5.1.

Each registrar method adds a `POST` route and initially stores the validated service, method, and `server_streaming` flag in a serializable internal action marker. After bootstrap signature validation, the complete marker is:

```php
$route->setAction([
    ...$route->getAction(),
    '_grpc' => [
        'service' => $serviceMethod->service,
        'method' => $serviceMethod->method,
        'server_streaming' => $serverStreaming,
        'request_parameter' => $parameterName,
        'request_class' => $messageClass,
    ],
]);
```

Bootstrap validation adds the request entries before compilation. The complete marker survives route compilation, removes request-time reflection/path parsing, supplies `ServerCallContext`, and lets `ResponseFactory` validate the result shape without a custom `Route` subclass. Registration/validation are boot-time work; neither the registrar nor router mutates routes during request handling.

### 4.2 Service classes and dependency injection

Services are ordinary container-resolved classes. No base class, attribute, generated interface, or RPC-specific controller superclass is required.

```php
use App\Grpc\Messages\HelloReply;
use App\Grpc\Messages\HelloRequest;
use App\Repositories\GreetingRepository;
use Hypervel\Grpc\Server\ServerCallContext;

final class GreeterService
{
    public function sayHello(
        HelloRequest $request,
        ServerCallContext $call,
        GreetingRepository $greetings,
    ): HelloReply {
        return (new HelloReply)->setMessage(
            $greetings->for($request->getName()),
        );
    }

    /** @return iterable<HelloReply> */
    public function listGreetings(
        HelloRequest $request,
        ServerCallContext $call,
    ): iterable {
        foreach (['Hello', 'Hi', 'Welcome'] as $greeting) {
            yield (new HelloReply)->setMessage($greeting);
        }
    }
}
```

Exactly one parameter must name a concrete subclass of `Google\Protobuf\Internal\Message`; it must be non-nullable, non-union/intersection, non-variadic, and not passed by reference, and receives the single decoded request frame. A `ServerCallContext` parameter is optional, but if present there may be exactly one and it has the same non-nullable/non-union/non-variadic/not-by-reference requirements. `GrpcRouter` validates these rules for every action during server bootstrap, before route compilation or the listening socket starts, and writes the protobuf parameter name/class into the `_grpc` marker. Missing classes/methods, zero/multiple protobuf parameters, a non-instantiable message type, an invalid special-parameter shape, or multiple contexts are configuration exceptions that identify the route action; they fail before listening instead of masquerading as an RPC failure. Every other class dependency is resolved by Hypervel's existing `ControllerDispatcher` or `CallableDispatcher`, including contextual attributes and defaults. The same rules work for closures.

Unary routes accept a `Message` or unary `GrpcResponse`. Server-streaming routes accept an `iterable<Message>` or streaming `GrpcResponse`. Every yielded item is type-checked before framing. A wrong route/result combination is reported and returned as `Internal`; it is never coerced to a string or JSON response.

### 4.3 Standard health service

Ship the standard `grpc.health.v1.Health` service because the dedicated gRPC listener is a concrete target for Kubernetes' native gRPC liveness/readiness probes and ordinary gRPC health clients. Vendor the current official `health.proto`, retaining its service/message/package identity and license while adding only these PHP generation options:

```proto
option php_namespace = "Hypervel\\Grpc\\Health\\V1";
option php_metadata_namespace = "Hypervel\\Grpc\\Health\\V1\\Metadata";
```

Check in the generated request/response/enum/metadata PHP classes and an exact regeneration command. Also ship a small `HealthClient extends BaseClient` whose `check()`, `list()`, and `watch()` methods return the corresponding typed unary/server-streaming call objects. This makes the canonical protocol usable without requiring the native gRPC PHP extension or duplicating generated sources in every application.

```php
final class HealthClient extends BaseClient
{
    public function check(
        HealthCheckRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall {
        return $this->_simpleRequest(
            '/grpc.health.v1.Health/Check',
            $request,
            [HealthCheckResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function list(
        HealthListRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall {
        return $this->_simpleRequest(
            '/grpc.health.v1.Health/List',
            $request,
            [HealthListResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function watch(
        HealthCheckRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): ServerStreamingCall {
        return $this->_serverStreamRequest(
            '/grpc.health.v1.Health/Watch',
            $request,
            [HealthCheckResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
```

Application health is supplied through one narrow, worker-safe contract:

```php
enum ServingStatus: int
{
    case Unknown = 0;
    case Serving = 1;
    case NotServing = 2;
}

interface HealthStatusProvider
{
    public function statusFor(string $service): ?ServingStatus;

    /** @return array<string, ServingStatus> */
    public function statuses(): array;
}

final class ServingHealthStatusProvider implements HealthStatusProvider
{
    public function statusFor(string $service): ?ServingStatus
    {
        return $service === '' ? ServingStatus::Serving : null;
    }

    public function statuses(): array
    {
        return ['' => ServingStatus::Serving];
    }
}
```

The authored provider-facing `ServingStatus` intentionally omits protobuf value `SERVICE_UNKNOWN = 3`: the official schema reserves that value for a `Watch` response when a previously unknown service may become known later, not as a stored service status. The generated protobuf enum still contains all four wire values so `HealthClient::watch()` can decode external servers; parity tests cover the three values shared with the provider enum and separately assert the generated Watch-only value.

`GrpcServiceProvider` registers `ServingHealthStatusProvider` as the default singleton implementation. It reports only the conventional empty service name (whole-server health) as serving; it must not guess the names or readiness of application services. Applications needing live dependency/readiness state bind `HealthStatusProvider` to their own implementation backed by state that is coherent across workers. The package does not ship a mutable in-process registry: provider state copied when Swoole forks would diverge among workers, while adding a `Swoole\Table`, capacity settings, polling, and cross-worker notifications solely to make such a registry appear global would be overengineering.

The built-in service implements the current protocol, not the older two-method schema:

```php
final readonly class HealthService
{
    public function __construct(private HealthStatusProvider $health);

    public function check(HealthCheckRequest $request): HealthCheckResponse
    {
        $status = $this->health->statusFor($request->getService());

        if ($status === null) {
            throw new RpcException(StatusCode::NotFound, 'The requested service is unknown.');
        }

        return (new HealthCheckResponse)->setStatus($status->value);
    }

    public function list(HealthListRequest $request): HealthListResponse
    {
        $statuses = [];

        foreach ($this->health->statuses() as $service => $status) {
            $statuses[$service] = (new HealthCheckResponse)->setStatus($status->value);
        }

        return (new HealthListResponse)->setStatuses($statuses);
    }

    /** @return iterable<HealthCheckResponse> */
    public function watch(HealthCheckRequest $request): iterable
    {
        throw new RpcException(
            StatusCode::Unimplemented,
            'Health status watching is not supported by this server.',
        );
    }
}
```

`Watch` is deliberately registered but returns `UNIMPLEMENTED`, exactly as the official protocol permits and instructs clients to treat as non-retryable. A correct watch must remain open, immediately report known or `SERVICE_UNKNOWN` state, distribute later transitions across every worker, and notice an idle peer cancellation. Swoole exposes neither cross-worker state notification nor an observable idle HTTP/2 stream cancellation to the request handler. Emitting repeated heartbeat statuses or polling a private registry would change protocol behavior and leak abandoned generators. Do not claim `Watch` until the engine can support those semantics; the client method still works against external servers that implement it.

The installed `routes/grpc.php` stub opts into the built-in service through ordinary public routing, with no special registrar method or config branch:

```php
use Hypervel\Grpc\Health\HealthService;
use Hypervel\Support\Facades\Grpc;

Grpc::service('grpc.health.v1.Health', function (): void {
    Grpc::unary('Check', [HealthService::class, 'check']);
    Grpc::unary('List', [HealthService::class, 'list']);
    Grpc::serverStream('Watch', [HealthService::class, 'watch']);
});
```

Users can remove that block or attach ordinary route middleware if a deployment should not expose health. Do not add `Grpc::health()`, a `health.enabled` setting, or another registration abstraction for three normal routes. Do not add server reflection: PHP protobuf has no practical runtime descriptor-pool surface for a correct reflection service, and reflection is development tooling rather than deployment health infrastructure.

### 4.4 Server call context

```php
final readonly class ServerCallContext
{
    public function metadata(): Metadata;

    public function service(): string;

    public function method(): string;

    public function peer(): string;

    public function deadline(): ?CarbonImmutable;

    public function timeRemaining(): ?float;

    public function deadlineExceeded(): bool;

    public function previousAttempts(): int;
}
```

`deadline()` is the wall-clock value useful to application code; `timeRemaining()` and enforcement use an internal monotonic nanosecond deadline so wall-clock adjustments cannot extend or shorten a running call. `peer()` uses the request's remote address and port where available, brackets IPv6 addresses, and returns the address alone if Swoole did not expose a port. `previousAttempts()` is zero on the first attempt and exposes the validated `grpc-previous-rpc-attempts` count on retries, which is useful for logging and idempotency decisions. The context is bound through a coroutine-local store, so it is available for service/closure method injection and for constructor injection into dependencies that the container actually creates per call. Normal container lifetime rules still apply: a singleton constructor must not capture call-specific context; use method injection or an explicitly scoped/transient dependency.

No `cancelled()` or client-disconnect promise is exposed: Swoole cannot reliably report per-stream cancellation to the request handler. Deadline exhaustion is the supported termination signal.

### 4.5 Server responses and errors

The common case returns a protobuf message directly. Metadata-bearing responses use an immutable fluent wrapper:

```php
use Hypervel\Grpc\Server\GrpcResponse;

return GrpcResponse::make($reply)
    ->withInitialMetadata(['x-request-id' => $requestId])
    ->withTrailingMetadata(['x-node' => $node]);
```

Server-streaming metadata uses the explicit factory:

```php
return GrpcResponse::stream($this->replies($request))
    ->withInitialMetadata(['x-request-id' => $requestId])
    ->withTrailingMetadata(['x-node' => $node]);
```

For a stream that produces a first message, initial metadata is sent in the response-header block that precedes that message. If the stream is empty or fails before its first yield, there is no separate response-header block: queued initial and trailing metadata share the single Trailers-Only block and the client observes both as trailers. The name `withInitialMetadata()` describes where the queued metadata goes when an initial block exists; it does not promise an unsupported eager header flush.

The wrapper's supported surface is intentionally small:

```php
final class GrpcResponse
{
    public static function make(Message $message): self;

    /** @param iterable<Message> $messages */
    public static function stream(iterable $messages): self;

    public function withInitialMetadata(Metadata|array $metadata): self;

    public function withTrailingMetadata(Metadata|array $metadata): self;
}
```

Service code signals an RPC failure by throwing:

```php
throw new RpcException(StatusCode::NotFound, 'Greeting not found.');
```

Rich details use the one named factory:

```php
$details = (new \Google\Rpc\Status)
    ->setCode(StatusCode::InvalidArgument->value)
    ->setMessage('The request is invalid.')
    ->setDetails([$badRequestAny]);

throw RpcException::fromStatus($details);
```

Do not add 17 named status factories. `RpcException::fromStatus()` validates that the numeric code is a defined non-OK gRPC status. The server maps expected `RpcException` values without reporting them; every other service throwable is reported through `Hypervel\Contracts\Debug\ExceptionHandler` and becomes `Unknown` with a non-sensitive message. Protocol failures have their documented library-generated status (`Internal`, `ResourceExhausted`, or `Unimplemented`).

The constructor/factory surface is deliberately status-only; optional wire adornments are immutable fluent methods:

```php
final class RpcException extends GrpcException
{
    public function __construct(StatusCode $code, string $message = '');

    public static function fromStatus(\Google\Rpc\Status $status): self;

    public function withTrailingMetadata(Metadata|array $metadata): self;

    public function withRetryAfter(float $seconds): self;

    public function withoutRetry(): self;

    public function status(): Status;
    public function metadata(): Metadata;
    public function trailers(): Metadata;
    public function method(): ?string;
    public function target(): ?string;
}
```

`metadata()`/`trailers()` are populated internally when a client receives a peer error; a service-created exception starts with empty metadata and null method/target. `withTrailingMetadata()` clones and appends validated custom metadata, letting an expected unary or mid-stream failure return standard error trailers without opening constructor transport internals. `withRetryAfter()` validates non-negative finite Laravel-style seconds, rejects a value whose upward-rounded millisecond representation exceeds `PHP_INT_MAX`, and emits the standard retry-pushback field; `withoutRetry()` emits `-1` as its negative stop signal. Pushback is intentionally absent from successful `GrpcResponse`: it only has meaning on a retryable failure. These reserved protocol trailers cannot be forged through `Metadata`. Custom error metadata remains valid for HTTP/2 even when the failure occurs after initial headers: the gRPC protocol does not require trailer names in the initial header block, and Swoole 6.2.2 reads its trailer map only when `end()` builds the final HEADERS frame. The shared bridge announces names known in advance but must not reject an additional valid name discovered during streamed production. Rich structured error data still belongs in `Google\Rpc\Status`.

### 4.6 Status types and exceptions

```php
enum StatusCode: int
{
    case Ok = 0;
    case Cancelled = 1;
    case Unknown = 2;
    case InvalidArgument = 3;
    case DeadlineExceeded = 4;
    case NotFound = 5;
    case AlreadyExists = 6;
    case PermissionDenied = 7;
    case ResourceExhausted = 8;
    case FailedPrecondition = 9;
    case Aborted = 10;
    case OutOfRange = 11;
    case Unimplemented = 12;
    case Internal = 13;
    case Unavailable = 14;
    case DataLoss = 15;
    case Unauthenticated = 16;
}

final readonly class Status
{
    public function __construct(
        StatusCode $code,
        string $message = '',
        ?\Google\Rpc\Status $details = null,
    );

    public function code(): StatusCode;
    public function message(): string;
    public function details(): ?\Google\Rpc\Status;
    public function isOk(): bool;
}
```

Keep the useful per-case semantic docblocks from Hyperf's source—especially the distinctions among `FailedPrecondition`, `Aborted`, and `Unavailable`—while converting stale HTML markup and omitting the historical “copied from” class comment. Those descriptions help service authors choose the correct protocol status and are durable API documentation, not porting residue.

`Status` rejects details on `Ok` and rejects an embedded rich-status code or message that differs from its enum/message values. Because generated protobuf objects are mutable and a shallow PHP clone can share repeated-field objects, construct and return rich details through a serialize/`mergeFromString()` defensive copy; callers cannot mutate an allegedly immutable `Status` through `details()`. Client parsing applies the tolerant wire rules in section 5.6 before constructing this value.

Embedded rich-status entries remain opaque `Google\Protobuf\Any` values. The client cannot safely instantiate a type supplied by a peer-controlled type URL, and protobuf-PHP's `Any::unpack()`/`is()` only work after the expected target descriptor has already been registered. Application code that knows the expected type uses the pool-independent form:

```php
$details = $exception->status()->details()?->getDetails();
$any = $details === null || count($details) !== 1 ? null : $details[0];

if ($any?->getTypeUrl() === 'type.googleapis.com/google.rpc.ErrorInfo') {
    $errorInfo = new \Google\Rpc\ErrorInfo;
    $errorInfo->mergeFromString($any->getValue());
}
```

Document this client-side consumption pattern beside rich-error production. Do not pre-register only the common-proto descriptors or add automatic Any unpacking: application-defined detail types remain valid, and only the application knows which target class it trusts and expects.

Exception hierarchy:

```text
RuntimeException
└── GrpcException
    ├── RpcException        valid non-OK RPC status
    ├── ConnectionException transport/socket failure without a valid status
    └── ProtocolException   malformed frame/header/status/compression
```

`ConnectionException` includes the target, a nullable transport error code, and the previous exception. The code is nullable because Swoole consumes peer `RST_STREAM` frames without exposing their HTTP/2 error code. `ProtocolException` messages describe the violated invariant without retaining payload bytes.

### 4.7 Metadata

`Metadata` is immutable, preserves repeated values, and keeps binary values as raw bytes:

```php
$metadata = Metadata::make([
    'authorization' => 'Bearer ...',
    'x-tag' => ['one', 'two'],
    'trace-bin' => $rawTraceBytes,
])
    ->with('x-request-id', $requestId)
    ->without('x-tag');
```

```php
final class Metadata implements Countable, IteratorAggregate
{
    /** @param array<string, string|list<string>> $values */
    public static function make(array $values = []): self;

    public function with(string $key, string ...$values): self;
    public function without(string $key): self;
    public function merge(self|array $metadata): self;
    public function first(string $key, ?string $default = null): ?string;

    /** @return list<string> */
    public function values(string $key): array;

    public function has(string $key): bool;
    public function isEmpty(): bool;

    /** @return array<string, list<string>> */
    public function all(): array;
}
```

Construction validates and lowercases keys, validates visible-ASCII non-binary values, rejects leading/trailing whitespace on non-empty outbound ASCII values, rejects empty value lists and transport/protocol-owned keys, and retains empty string values. Key insertion order and each key's value order are stable; `count()` counts keys and iteration yields `key => list<string>`. `with()` requires at least one value and appends to any existing values in argument order; `merge()` likewise appends every source value after the receiver's values. Replacing a key is explicit as `without($key)->with($key, ...)`, avoiding an ambiguous special case for repeatable metadata. The single owned-key list rejects pseudo-fields, every `grpc-*` name, and HTTP fields the transport/package creates, consumes, or cannot preserve (`host`, `content-type`, `content-length`, `content-encoding`, `cache-control`, `accept-encoding`, `transfer-encoding`, `te`, `trailer`, `user-agent`, `server`, `date`, `cookie`, `set-cookie`, `connection`, `keep-alive`, `proxy-connection`, and `upgrade`). Cookie fields are explicitly owned because Swoole removes inbound `cookie` from the request-header map and separately parses cookie fields, so exposing them as ordinary gRPC metadata would be asymmetric and lossy. Cache-Control is likewise owned because Symfony's response header bag inserts or recomputes it and cannot preserve an application value byte-for-byte; gRPC response types remove that implicit HTTP default before their protocol snapshot and metadata-size accounting. It does not reject application authentication fields such as `authorization`. `Metadata` validation and inbound codec filtering share this one list so an accepted peer field cannot become an invalid application echo. Wire conversion is internal to `MetadataCodec`; users cannot accidentally provide already-base64-encoded `-bin` values.

### 4.8 Compression and retry policy

```php
enum Compression: string
{
    case Identity = 'identity';
    case Gzip = 'gzip';
}

final readonly class RetryPolicy
{
    /** @param list<StatusCode> $retryableStatusCodes */
    public function __construct(
        public int $maxAttempts,
        public float $initialBackoff = 0.1,
        public float $maxBackoff = 5.0,
        public float $backoffMultiplier = 2.0,
        public array $retryableStatusCodes = [StatusCode::Unavailable],
    );
}
```

`maxAttempts` includes the original RPC. The policy validates `maxAttempts >= 2`, positive finite durations, `maxBackoff >= initialBackoff`, multiplier `>= 1`, a non-empty unique set of non-OK codes, and applies the gRPC retry jitter range of ±20%. The exponential base is capped at `maxBackoff` before jitter, matching grpc-go; the actual sleep is then bounded by the remaining deadline. `Hypervel\Support\Sleep` performs coroutine-friendly, fakeable backoff.

An `@internal RetryBackoff` performs the exponential/jitter calculation using PHP's `Random\Randomizer`. Production constructs it with the default engine; unit tests inject a seeded `Mt19937` engine. This keeps random/test controls out of the public `RetryPolicy` constructor while making the exact capped ±20% algorithm deterministic under test.

Only unary and server-streaming calls accept a retry policy, because their complete outbound request is retained and safe to replay before commitment. A constructor-level retry policy is an eligible-call default: mixed-shape generated stubs apply it to unary/server-streaming methods and do not inherit it for client-streaming/bidi methods. Supplying a per-call `retry` key to client-streaming or bidi fails as an unsupported option rather than implying unsafe replay of arbitrary application writes. Transport send failure is never transparently replayed because Swoole cannot prove zero bytes were sent.

Commitment is enforced from every incremental response event Swoole surfaces. The runtime exception in section 2.6 remains: initial HEADERS followed immediately by final trailers and no DATA is merged into one final PHP response, so it is indistinguishable from genuine Trailers-Only and is treated as retry-eligible. This affects only explicitly enabled retries, is called out beside the retry option in the README/docs, and has a grpc-go interop fixture that sends early initial metadata then a zero-message retryable error so the behavior cannot change accidentally or be overstated. A response containing any surfaced non-final event or message is committed normally.

Every retry attempt adds the reserved `grpc-previous-rpc-attempts` request header with the count of completed prior attempts; the first attempt omits it. A trailers-only `grpc-retry-pushback-ms` from the peer is parsed before reserved fields are filtered: one non-negative decimal millisecond value representable as a PHP integer replaces the next computed backoff and resets the exponential sequence, while a negative, overflowing, malformed, array-valued, or comma-combined value suppresses the retry. Pushback still cannot extend the call's absolute deadline. The Hypervel server accepts the previous-attempt header only when the value visible through Swoole is one non-negative digits-only decimal integer representable by `ServerCallContext::previousAttempts()`; an array/comma value, overflow, or malformed syntax is `Internal`. Independently repeated fields that Swoole overwrites have the platform limitation described in section 2.6. It emits pushback only through `RpcException::withRetryAfter()`/`withoutRetry()`.

### 4.9 Generated-style client stubs

Keep `BaseClient` abstract and retain the official generated method-body vocabulary. The methods stay protected and underscore-prefixed because a protobuf service is allowed to define RPC names that would collide with ordinary public helper names.

```php
use Google\Protobuf\Internal\Message;

abstract class BaseClient
{
    public function __construct(string $target, array $options = []);

    public function target(): string;

    public function close(): void;

    protected function _simpleRequest(
        string $method,
        Message $argument,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall;

    protected function _clientStreamRequest(
        string $method,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): ClientStreamingCall;

    protected function _serverStreamRequest(
        string $method,
        Message $argument,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): ServerStreamingCall;

    protected function _bidiRequest(
        string $method,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): BidiStreamingCall;
}
```

An official `grpc_php_plugin` client method can be used by changing its parent/import from `\Grpc\BaseStub` to `\Hypervel\Grpc\Client\BaseClient`; its `_simpleRequest`, `_clientStreamRequest`, `_serverStreamRequest`, and `_bidiRequest` calls retain their expected argument order. This compatibility is intentionally for generated method bodies, not every ext-grpc runtime option: Hypervel timeouts are Laravel-style seconds rather than ext-grpc's microseconds, and unsupported option keys fail explicitly. Hypervel does not claim the `Grpc\` namespace, add global class aliases, depend on `ext-grpc`, or ship an unproven proto compiler. The package documentation includes both this generated-style workflow and concise manually authored stubs.

Example:

```php
namespace App\Grpc\Clients;

use App\Grpc\Messages\HelloReply;
use App\Grpc\Messages\HelloRequest;
use Hypervel\Grpc\Client\BaseClient;
use Hypervel\Grpc\Client\UnaryCall;

final class GreeterClient extends BaseClient
{
    public function sayHello(
        HelloRequest $request,
        array $metadata = [],
        array $options = [],
    ): UnaryCall {
        return $this->_simpleRequest(
            '/helloworld.Greeter/SayHello',
            $request,
            [HelloReply::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
```

`MessageSerializer` treats the conventional `[Reply::class, 'decode']` pair the same way as official grpc-php: instantiate the class and call `mergeFromString()`. It does not require a non-existent static `decode()` method. A genuinely callable deserializer remains supported and must return a protobuf `Message`. Null request/response messages are not accepted; an empty protobuf request uses `Google\Protobuf\GPBEmpty`.

Recommended application binding:

```php
$this->app->singleton(GreeterClient::class, fn () => new GreeterClient(
    config('services.greeter.url'),
    [
        'connect_timeout' => 3.0,
        'timeout' => 5.0,
        'retry' => new RetryPolicy(maxAttempts: 3),
    ],
));
```

There is no client facade or named-client manager. Normal singleton bindings already provide long-lived reusable clients, clear type information, and application-owned endpoint configuration.

### 4.10 Client call shapes

```php
$reply = $client->sayHello($request)->wait();
```

`wait()` returns the deserialized message. A non-OK final status throws `RpcException`; a transport failure throws `ConnectionException`; invalid peer output throws `ProtocolException`. There is no `[message, status]` tuple, fake response wrapper, `successful()` branch, or `ArrayAccess` compatibility layer.

Every call exposes blocking status/metadata accessors:

```php
abstract class Call
{
    public function metadata(): Metadata; // initial metadata; waits until known
    public function trailers(): Metadata; // waits for completion
    public function status(): Status;      // waits for completion; does not throw for non-OK
    public function peer(): string;
}
```

`peer()` returns the normalized authority selected for this call (`host:port`, with IPv6 brackets retained). Swoole does not expose a more authoritative per-stream remote-endpoint value, so this method does not imply reverse-DNS or socket introspection.

The shape-specific surface contains only legal operations:

```php
final class UnaryCall extends Call
{
    public function wait(): Message;
}

final class ServerStreamingCall extends Call
{
    public function read(): ?Message;

    /** @return iterable<Message> */
    public function responses(): iterable;
}

final class ClientStreamingCall extends Call
{
    public function write(Message $message): void;
    public function writesDone(): void;
    public function wait(): Message; // idempotently calls writesDone first
}

final class BidiStreamingCall extends Call
{
    public function write(Message $message): void;
    public function read(): ?Message;
    public function writesDone(): void;
}
```

`read()` returns one message, `null` after a clean OK end, and throws `RpcException` when it reaches a non-OK final status. `responses()` yields until `read()` returns null. `write()` and `writesDone()` return `void`: network I/O is not fluent because failure must be observed at the operation that caused it. Repeated `writesDone()` is harmless; a later `write()` throws `LogicException`. A call never exposes the raw connection, Swoole object, stream ID, or native request.

The observer methods do not turn an ordinary RPC status into control-flow: `status()` returns peer or library-produced statuses including non-OK, local `DeadlineExceeded`, and local slow-consumer `ResourceExhausted`; `metadata()`/`trailers()` return empty `Metadata` when the call terminated before that peer block existed. All three still throw `ConnectionException` or `ProtocolException` when no trustworthy RPC status/metadata result exists. Shape methods (`wait()`, `read()`, `responses()`) throw `RpcException` for the same non-OK status and attach whatever initial/trailing metadata was actually observed.

Unary and client-streaming `wait()` resolve exactly once under a call-local completion guard, deserialize once, and cache the message or terminal exception. Repeated or concurrent callers observe the same result rather than dividing the one response or triggering duplicate deserialization/retry. Streaming message consumption remains intentionally single-reader.

A streaming call supports one active reader and one active writer concurrently. Writes/half-close are serialized; a second simultaneous reader fails with `LogicException`. Metadata/status observers may wait concurrently with that reader and are all awakened by state changes.

### 4.11 Client options

Client constructor options:

```php
[
    'connections' => 1,
    'connect_timeout' => 3.0,
    'timeout' => null,
    'max_receive_message_size' => 4 * 1024 * 1024,
    'max_send_message_size' => 4 * 1024 * 1024,
    'max_metadata_size' => 8 * 1024,
    'max_buffered_messages' => 128,
    'max_buffered_bytes' => 16 * 1024 * 1024,
    'compression' => null,                 // null|Compression|'identity'|'gzip'
    'retry' => null,                       // ?RetryPolicy
    'metadata' => [],                      // Metadata|array
    'tls' => [
        'enabled' => null,                 // ?bool; null infers from target scheme
        'verify_peer' => true,
        'ca_file' => null,
        'certificate' => null,
        'private_key' => null,
        'passphrase' => null,
        'server_name' => null,
    ],
    'swoole' => [],                        // raw native settings escape hatch
]
```

Per-call options are only `timeout`, `compression`, and—for unary/server-streaming—`retry`. Resolve them with `array_key_exists`: an absent key inherits the applicable client default, while explicit `null` disables the nullable default (no deadline, identity compression, or no retry respectively). Client-streaming/bidi never inherit the constructor retry default and reject a per-call `retry` key. Unknown keys fail fast with `InvalidArgumentException`; misspellings must not silently disappear into native settings. `swoole` is the only raw settings namespace. Constructor validation requires positive integer connection/buffer/metadata limits, positive message limits no greater than `0xffffffff`, `max_buffered_bytes >= max_receive_message_size` so one valid message always fits, a positive finite connect timeout, and the exact declared nested option shapes.

The raw settings escape hatch cannot weaken deadline enforcement. Reject `swoole.connect_timeout` because the first-class `connect_timeout` option owns that setting. Resolve the baseline native write timeout from `swoole.write_timeout`, otherwise `swoole.timeout`, otherwise Swoole's 60-second default, and require the selected value to be a positive finite integer or float. Swoole treats a non-positive write timeout as unbounded, which would let one stalled client-streaming or bidirectional write freeze every multiplexed call on that connection beyond its RPC deadline. Positive raw write timeouts remain supported as the caller's upper bound; each actual send/write uses the smaller of that baseline and the current call's remaining deadline.

There is no default deadline at protocol level; `timeout => null` means no deadline. Documentation strongly recommends setting one in production. A call-level `timeout` is a positive finite number of seconds and replaces the client default. A target with `https://` enables TLS; a target with `http://` or no scheme is plaintext when `tls.enabled` is null. IPv6 bracket syntax is supported. `tls.enabled => true` enables TLS for a scheme-less target. Unsupported target schemes, empty hosts, malformed bracketed IPv6, missing/invalid ports, userinfo, paths other than an optional `/`, query strings, and fragments fail in the constructor; resolver-style targets such as `dns:///...` are not accepted while the package deliberately has no resolver subsystem.

TLS option validation requires a nullable boolean `enabled`, booleans/nullable strings of the remaining declared shape, readable CA/certificate/private-key paths, a certificate and private key supplied together, a passphrase only when that pair is present, and a non-empty `server_name` when present. `tls.enabled => true` conflicts with an `http://` target and `false` conflicts with `https://`. On a resolved plaintext target, reject TLS-only keys the caller explicitly supplied instead of silently ignoring them; the built-in `verify_peer => true` default is merely the secure default used if TLS resolves enabled and does not make a default plaintext target invalid. Preserve the caller-supplied-key set while applying defaults so this distinction is deterministic. Validate option structure before any lazy connection attempt so configuration errors surface at client construction.

Default metadata is merged before per-call metadata, so call values append rather than erase repeated defaults. Authentication that changes per request belongs in application code or middleware and can be passed as per-call metadata; the package does not introduce a generic interceptor framework or metadata callback with an unstable signature.

`connections` is the fixed number of call-accepting HTTP/2 slots owned by the stub and selected round-robin per call. It exists for peers with low concurrent-stream limits and demonstrated high-concurrency workloads; the default remains one because HTTP/2 multiplexes calls. A transport that must retire after an un-cancellable local stream failure stops accepting calls and is lazily replaced in its slot; it may coexist briefly with the replacement only while already accepted healthy calls finish. The option replaces Hyperf's `client_count` name. `send_yield`, `credentials`, raw retries integers, and transporter options do not exist.

## 5. Shared wire implementation

### 5.1 Media type, frame encoder, and stateful decoder

`Protocol\MediaType` owns the request/response check shared by server preflight and client response parsing. It recognizes `application/grpc`, `application/grpc+<non-empty subtype>`, and parameters following either form, case-insensitively; it rejects a loose `application/grpcfoo` prefix. It exposes whether the representation is protobuf: the base type means implicit protobuf and `+proto` is explicit protobuf. The package accepts only those two forms because it has no JSON/custom codec; a recognized but unsupported subtype is HTTP 415 on the server and `ProtocolException` on the client. It also supplies the emitted `application/grpc+proto` constant. Do not duplicate slightly different media-type tests in the client and server or pretend to support alternate codecs by protobuf-decoding their bytes.

`Protocol\ServiceMethod` is the single internal owner of gRPC method identity:

```php
/** @internal */
final readonly class ServiceMethod
{
    public static function parse(string $value): self;

    public static function from(string $service, string $method): self;

    public function path(): string;

    private function __construct(
        public string $service,
        public string $method,
    );
}
```

`parse()` accepts one optional leading slash followed by exactly one service/method separator. `from()` validates the two parts independently. The fully qualified service is one or more protobuf identifiers separated by dots; the method is one identifier. Empty segments, leading/trailing dots, additional slashes, URI placeholders, query/fragment text, and invalid identifier characters fail with `InvalidArgumentException`. `path()` always returns the exact case-preserving `/{fully-qualified-service}/{method}` form. Registrar grouping, route markers, client request construction, and call context all use this value; no second path regex or Hyperf-style `/grpc.{short service}` generator exists.

```php
final class FrameEncoder
{
    public function __construct(
        private readonly int $maxMessageSize,
    ) {
    }

    public function encode(
        string $payload,
        Compression $compression = Compression::Identity,
    ): string {
        if (strlen($payload) > $this->maxMessageSize) {
            throw new RpcException(
                StatusCode::ResourceExhausted,
                'The outbound gRPC message exceeds the configured limit.',
            );
        }

        $wirePayload = $compression === Compression::Gzip
            ? gzencode($payload, -1, ZLIB_ENCODING_GZIP)
            : $payload;

        if ($wirePayload === false) {
            throw new ProtocolException('Unable to compress the gRPC message.');
        }

        $wireLength = strlen($wirePayload);

        if ($wireLength > $this->maxMessageSize || $wireLength > 0xffffffff) {
            throw new RpcException(
                StatusCode::ResourceExhausted,
                'The encoded gRPC message exceeds the configured limit.',
            );
        }

        return pack('CN', $compression === Compression::Identity ? 0 : 1, $wireLength)
            . $wirePayload;
    }
}
```

The implementation checks the configured send limit against the serialized payload before compression and again against the resulting wire payload, then enforces the unsigned 32-bit frame limit. Applying the limit on both sides is symmetric with receive-side protection: a highly compressible oversized semantic message cannot bypass the limit, and incompressible gzip overhead cannot create a frame the peer/native package cap rejects.

```php
final class FrameDecoder
{
    private string $buffer = '';

    public function __construct(
        private readonly Compression $encoding,
        private readonly int $maxMessageSize,
    ) {
    }

    /** @return \Generator<int, string> */
    public function push(string $bytes): \Generator;

    public function finish(): void;
}
```

`push()` appends bytes and yields completed payloads one at a time instead of first building an unbounded array of tiny messages from one peer DATA event. Internal consumers always exhaust the generator—even after a call has hit its buffered-message cap, in which case later yielded payloads are discarded—so decoder offsets and compaction remain deterministic. It loops while at least five header bytes are available, validates the flag and applies `maxMessageSize` to the declared wire payload before waiting for it, retains partial data, decompresses each compressed message independently, and applies the same limit to the decompressed payload. Gzip decoding must not call `gzdecode()` and only measure afterward: a small compression bomb would already have allocated its full output. Feed a fresh `inflate_init(ZLIB_ENCODING_GZIP)` context in chunks of `max(64, intdiv($maxMessageSize, 1032))` compressed bytes, use `ZLIB_NO_FLUSH` until the final chunk and `ZLIB_FINISH` for the final call, and reject before appending any returned output that would cross the limit. DEFLATE's approximately 1032:1 maximum expansion keeps one returned chunk near the configured message limit; the 64-byte floor caps even a very small configured limit's temporary overshoot at roughly 66 KiB while avoiding pathological per-byte calls. At the default 4 MiB limit this reduces a worst-case full compressed frame from about 65,536 inflate calls to about 1,033 without weakening the output check. Require `inflate_get_status() === ZLIB_STREAM_END` and compare `inflate_get_read_len()` with the exact payload length so truncated streams and trailing bytes fail. This prevents oversized wire frames, tiny-frame list amplification, and compression bombs without inventing a second public limit. `finish()` throws if any incomplete header or body remains when END_STREAM arrives. A unary request/response requires exactly one decoded message; zero or multiple messages are a protocol failure. Zero-length protobuf payloads are valid.

Use `unpack('Cflag/Nlength', ...)` without platform-dependent integer formats. Do not retain consumed prefixes with repeated `substr` copies for arbitrarily large streams: maintain a read offset and compact the buffer after consumption.

### 5.2 Message serialization

```php
final class MessageSerializer
{
    public static function serialize(Message $message): string
    {
        return $message->serializeToString();
    }

    public static function deserialize(
        array|callable $deserialize,
        string $payload,
    ): Message;
}
```

For the conventional exact pair `[class-string<Message>, 'decode']`, instantiate the class and call `mergeFromString($payload)`, matching official grpc-php generated stubs without requiring a non-existent static `decode()` method. For any other callable—including another static method on a protobuf class—invoke it and validate the returned type. Reject a non-callable array that is not that exact generated-stub convention. Protobuf parse failures become `ProtocolException` on the client and `Internal` on the server; native exception messages are retained as previous exceptions but raw payloads are never placed in messages/logs.

### 5.3 Compression

Only identity and gzip are public because both are standardized, supported by the runtime, and independently interoperable. Each message uses a fresh gzip context. There is no public registry or extension interface.

Negotiation rules:

- outbound client request compression comes from the resolved call option;
- clients always advertise `grpc-accept-encoding: identity,gzip`;
- the server accepts identity/gzip request frames and rejects an unknown `grpc-encoding` as `Unimplemented` before invoking application middleware;
- the client rejects an unknown response `grpc-encoding` as a protocol failure before feeding any response frame;
- server response compression is the configured preferred encoding only when the client advertised it; otherwise identity;
- the response sends `grpc-encoding` only when a non-identity encoding was actually used for at least one response message; all streams are primed before headers, so an empty or pre-yield error Trailers-Only response correctly omits that unused field. Every response still advertises the server's accepted request encodings;
- a compressed flag with identity/no encoding is malformed;
- corrupt gzip for a supported encoding is `Internal` server-side and `ProtocolException` client-side.

Do not use HTTP `content-encoding`; compression is per gRPC message inside the HTTP/2 body.

### 5.4 Metadata codec

`MetadataCodec` is the only class that translates public raw values into wire headers.

Emission:

```php
// Conceptual: actual implementation validates and combines every value.
$wireValue = str_ends_with($key, '-bin')
    ? rtrim(base64_encode($rawValue), '=')
    : $rawValue;
```

Repeated outbound values are combined in their original order with commas because Swoole's request/response header APIs are associative. Binary values are safe to split because base64 does not contain commas. ASCII comma ambiguity caused by Swoole's representation is documented; the public object still preserves repetition whenever it can be observed.

Parsing filters every pseudo-field and the shared HTTP/protocol-owned key list from application metadata. Protocol fields such as `grpc-timeout`, `grpc-encoding`, `grpc-accept-encoding`, `grpc-status`, `grpc-message`, and `grpc-status-details-bin` are parsed by their owning codecs, never exposed as user metadata. Binary comma-separated values are split and strictly base64-decoded after restoring padding; malformed custom binary metadata is `Internal` on an inbound server request and `ProtocolException` on a client response, matching mature peer behavior rather than silently changing bytes. Ordinary ASCII metadata is never URL-decoded. Trim permitted surrounding spaces on receipt and discard a custom ASCII field containing bytes outside `0x20`–`0x7e` instead of failing the RPC; the protocol explicitly permits discard/accept for an HTTP-valid but gRPC-invalid ASCII value and forbids turning that peer input into an application echo failure.

Metadata size uses the protocol's suggested accounting: the sum of every visible field's key/value bytes plus 32 bytes per field, before reserved fields are filtered; binary values are counted in their base64 wire form. Outbound enforcement is exact over every field Hypervel and Swoole put on the wire. Client request accounting includes the generated `:method`, `:scheme`, `:path`, and `:authority`; outbound server accounting includes `:status`, the exact `Trailer` announcement that `ResponseBridge` derives from `trailerNames()`, and Swoole's body-derived `content-length` for every `GrpcHttpResponse`, including the exact value `0` on a true Trailers-Only response. A Trailers-Only response has no announcement. A non-empty `GrpcStreamedResponse` always has one cached first frame but cannot know whether a second item exists without advancing application code: a one-message stream reaches `end($firstFrame)` and gets that exact content length, while a multi-message stream reaches `write()` first and gets none. Reserve the first frame's content-length field in the initial-block calculation for every such stream. That is a bounded, conservative overcount only for multi-message streams and is preferable to eagerly advancing a second service item or fragmenting the first gRPC frame solely to affect Swoole headers. For outbound server responses, set `server` and `date` explicitly before measuring—using the stable package server value and one captured RFC 7231 timestamp—so Swoole does not append unaccounted implicit fields after validation.

Inbound enforcement is necessarily over the transport-observable block. Client response accounting reconstructs `:status` for an exposed initial response block only; final trailer blocks have no pseudo-fields. In Swoole's zero-DATA merged-header case, count the one merged event conservatively as exposed rather than inventing an unavailable boundary. Server preflight reconstructs `:method`, the configured listener's canonical `http`/`https` `:scheme`, the exact host-backed `:authority`, and `:path` including the exposed query string. It then counts every field/value Swoole retained. Swoole has already discarded the peer's original `:scheme` and collapsed independently repeated fields, so Hypervel cannot recover their original byte/count contribution or always detect a duplicated singleton field. Do not label this byte-exact in code or documentation. Comma-combined values remain visible and are validated normally. An observable inbound block above `max_metadata_size` becomes `ResourceExhausted` on the server and `ProtocolException` on the client. Before sending, calculate the complete outbound request/initial-header/trailer block including protocol fields—not merely custom metadata; outbound final trailer blocks likewise have no `:status`. Swoole's server response API also rejects a header/trailer name longer than 127 bytes: keep public `Metadata` protocol-portable, but have `ResponseFactory` treat such an outbound server metadata key exactly like an oversized block and replace it with compact initial/final `ResourceExhausted` before the native call. The HTTP/2 client path has no equivalent Swoole key-length cap beyond the configured metadata size. An oversized client request fails locally with `ResourceExhausted`; an oversized server initial/trailer block is replaced by a small trailers-only/final `ResourceExhausted` status with application metadata/details removed. Default: 8 KiB on both sides.

### 5.5 Timeout codec and deadline model

```php
final class Timeout
{
    public static function encode(float $seconds): string;
    public static function decode(string $header): float;
}
```

`encode()` starts with nanoseconds and moves through microseconds, milliseconds, seconds, minutes, and hours until the ceiling fits in eight digits. It uses `ceil`, never truncation. `decode()` requires the exact grammar `^[0-9]{1,8}[HMSmun]$` and returns finite seconds. Zero is valid wire syntax and represents an already-expired deadline; server handling returns `DeadlineExceeded` before user middleware. The wire grammar also permits durations longer than PHP's integer-nanosecond range (for example `99999999H`), so the server saturates the derived monotonic deadline at `PHP_INT_MAX` instead of rejecting a valid peer header; this matches the effectively-infinite handling in mature peers. The wall-clock projection uses the saturated remainder.

At call creation, construct one internal deadline from the caller's timeout:

```php
$deadlineNanoseconds = $timeout === null
    ? null
    : hrtime(true) + (int) ceil($timeout * 1_000_000_000);
```

Validate before this addition that the positive finite timeout can be represented in nanoseconds and that adding it to the current monotonic clock cannot exceed `PHP_INT_MAX`; reject an unrepresentable option with `InvalidArgumentException`. Build and size-check deadline-independent request headers synchronously, reserving the maximum nine-byte `grpc-timeout` field when a deadline exists. Give the connection a one-shot request finalizer that inserts the freshly encoded remainder only after lazy connection and immediately before native send, so connect/semaphore time cannot inflate the server's deadline. The connection send semaphore acquisition and every response wait/retry sleep are bounded by the same remainder. The call-local writer semaphore remains an ordinary serialization guard: all writers share this one deadline, framing before the connection is non-yielding, and the holder always reaches deadline-bounded connection/native I/O before releasing it in `finally`, so a second timed-acquire layer would be redundant.

Lazy connect caps both native `connect_timeout` and `write_timeout` by the current remainder. Every serialized native `send()`/`write()` publishes `min(baseline write timeout, remaining deadline)` immediately before the operation, or the positive baseline when the call has no deadline. No restore is needed because the connection semaphore serializes all writers and every operation publishes its own effective value. This is chiefly required for a large client-streaming/bidirectional write to a stalled peer: an unbounded or stale native write timeout would otherwise freeze unrelated multiplexed calls. Applying the same bound to the small HTTP/2 connection preface keeps the invariant complete but is not the main risk.

Expiry creates `RpcException(StatusCode::DeadlineExceeded, ...)`; it does not immediately close the entire multiplexed connection. Expiry before native send leaves a successfully connected socket reusable. A connect exception retires the connection. A failed or throwing native send/write retires it because partial bytes may already have left the process; the actual monotonic expiry at that point chooses `DeadlineExceeded` versus `ConnectionException`. The locally terminal stream is reduced to a discard tombstone and its connection retires: existing healthy streams finish, no new call is assigned to it, and the socket closes as soon as no healthy accepted stream remains. Late native events therefore cannot be mistaken for another call and abandoned payload/decoder memory is released immediately.

Put this arithmetic in one `@internal Protocol\Deadline` value used by calls, waits, backoff, and server context. It owns the nullable absolute nanoseconds plus a monotonic-now closure; production uses `static fn (): int => hrtime(true)`, while focused unit tests inject a manually advanced closure through an internal named constructor. It provides constructors for a validated local timeout and a saturated peer timeout, plus `remainingSeconds()`, `expired()`, `absoluteNanoseconds()`, and `encodedHeader()`. `encodedHeader()` returns `null` for no deadline and otherwise delegates the positive remainder to `Timeout::encode()`; an expired deadline is detected before header construction rather than emitting a synthetic value. This avoids wall-clock use and makes total-deadline/backoff tests deterministic without a public clock option or process-global fake.

On the server, decode the header once into a saturated monotonic deadline plus a `CarbonImmutable` wall-clock projection. If the remaining duration is already non-positive, return `DeadlineExceeded` without starting a timer or invoking user middleware. Otherwise capture `Hypervel\Engine\Coroutine::id()`, then use the `Hypervel\Coordinator\Timer` instance injected into `HandleCall` to register `$timer->after()` on the existing worker-exit coordinator. Its callback receives the coordinator-closing flag, does nothing when closing, and otherwise calls `Hypervel\Engine\Coroutine::cancelById($handlerId, true)`. The call lifecycle retains that same timer instance and returned ID so every completion path invokes `$timer->clear($timerId)`; `clear()` cancels and releases the coordinator-wait coroutine. The shared timer rechecks the same monotonic time domain after an early coordinator wake, so the callback cannot run before the interval supplied by `Deadline::remainingSeconds()` has elapsed. `HandleCall` catches `Swoole\Coroutine\CanceledException` as `DeadlineExceeded` only when its own monotonic deadline is expired; unrelated cancellation is rethrown to the shared native request-callback boundary, which aborts without inventing a gRPC status or entering the generic HTTP renderer. A lazy `GrpcStreamedResponse` applies the identical classification while iterating because cancellation can occur after `HandleCall` has returned. If CPU-bound code never yields, the event loop cannot run the timer, so the middleware/stream callback rechecks the monotonic deadline after user code returns and replaces the result/final status with `DeadlineExceeded` before emission. Do not write static `Timer::after()`/`clear()` calls: those framework methods are deliberately instance-owned.

### 5.6 Status codec

`StatusCodec` owns:

- parsing `grpc-status` into `StatusCode`, mapping malformed or undefined peer values to `Unknown` with a transport diagnostic;
- UTF-8 scrubbing, percent-encoding, and tolerant decoding of `grpc-message` only;
- base64 encoding/decoding `grpc-status-details-bin`;
- verifying a rich status's embedded code matches the outer code;
- producing trailers for OK and error responses;
- detecting trailers-only responses;
- applying the official HTTP fallback table only when final `grpc-status` is absent.

Before outbound percent encoding, replace invalid UTF-8 sequences with the Unicode replacement character, then leave bytes `0x20`–`0x7e` except `%` literal and encode every other byte plus `%` as uppercase `%HH`. Invalid percent triplets in an inbound `grpc-message` remain literal while valid triplets are decoded; the protocol explicitly forbids failing a call merely because its status message is badly escaped. An absent or malformed rich-details value—including invalid base64, invalid protobuf, or an array/comma-combined singleton—is ignored while the outer code/message remains authoritative. Accept padded or unpadded base64 for the one visible value. A valid rich status with the same code becomes authoritative, including its message and details. If a successfully parsed `Google\Rpc\Status` contradicts the outer code, replace the peer status with `Internal` and omit the contradictory details. This matches grpc-go's verification behavior without allowing optional details to hide a valid outer error. A response with HTTP 200 and no gRPC status becomes `Unknown`, as required by the fallback document. Retain the HTTP status/content type from the initial event because Swoole's separate final-trailer response object does not repeat them. Initial headers containing `grpc-status` are valid final status only when that same event ends the stream (trailers-only); status on a non-final initial block is a protocol error.

Validate the initial response content type before feeding body bytes to `FrameDecoder`. If it is missing or not a gRPC media type, enter a non-gRPC response mode: discard body events while counting them against `max_receive_message_size`, fail with `ProtocolException` if that bound is crossed, ignore any gRPC-looking fields, and otherwise synthesize the final status solely from the retained HTTP code with a fixed content-type diagnostic. This prevents an HTML proxy body from becoming a framing exception or unbounded buffer and implements the fallback document's intermediary case. A syntactically valid gRPC media type with a non-protobuf subtype is instead an immediate unsupported-representation `ProtocolException`; it is not an intermediary HTTP fallback and its body is never parsed as protobuf. For an accepted protobuf gRPC content type, an explicit final `grpc-status` remains authoritative even if the HTTP code is non-200; use HTTP mapping only when that final field is absent.

## 6. Client internals

### 6.1 Endpoint and engine request

`Endpoint` is an internal immutable parser with `host`, `port`, `tls`, `authority`, and `peer` values. It handles default ports 80/443, bracketed IPv6, explicit TLS override, and validates conflicts between URL scheme and options.

`Client\Request` is an internal immutable implementation of `Hypervel\Contracts\Engine\Http\V2\RequestInterface` that exposes only the exact headers/body/flags required by the engine. It does not inherit the engine's mutable request setters or add public request customization and override points.

The request and response sides are independent. Hypervel enables incremental response reads for all four shapes so initial metadata, message delivery, and retry commitment become observable as soon as Swoole can expose them; this does not turn a unary result into a streaming public API. Use the exact matrix instead of coupling both native flags:

| Call shape | `pipeline` (request stays open) | `usePipelineRead` (incremental response) |
|---|---:|---:|
| unary | `false` | `true` |
| server streaming | `false` | `true` |
| client streaming | `true` | `true` |
| bidirectional streaming | `true` | `true` |

For a server-streaming call, for example:

```php
new Request(
    path: '/helloworld.Greeter/SayHello',
    method: 'POST',
    body: $framedRequest,
    headers: $headers,
    pipeline: false,
    usePipelineRead: true,
);
```

Client-streaming and bidi calls send initial headers with `pipeline: true` and an empty body; each `write()` sends one complete framed message, and `writesDone()` sends an empty final DATA frame with END_STREAM. Unary sends its framed request with `pipeline: false`; all shapes retain `usePipelineRead: true`. This is the concrete reason the engine request contract must stop deriving `usePipelineRead` from `pipeline`.

Common headers include `content-type`, `te`, `user-agent`, `grpc-accept-encoding`, optional `grpc-encoding`, optional `grpc-timeout`, endpoint authority, and encoded metadata. Pass authority to the engine request under the ordinary `host` key: the verified Engine/Swoole request builder converts `host` into the HTTP/2 `:authority` pseudo-header and ignores caller-supplied colon-prefixed keys. Preserve brackets for IPv6 authorities. User agent uses `Composer\InstalledVersions` and a stable fallback:

```text
grpc-php-hypervel/<package-version> (PHP/<version>; Swoole/<version>)
```

### 6.2 Base client connection selection

The constructor normalizes options, parses the endpoint, resolves and retains the framework-bound `Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface` from `Hypervel\Container\Container::getInstance()`, and creates a fixed list of lazy `Connection` slots plus an initially empty instance-owned retiring set. It does not open sockets. This keeps the generated-style constructor at the useful two-argument shape, uses the engine provider's existing driver binding, and lets framework tests substitute the engine contract without a gRPC-only public transport-injection option. Each new call obtains the next accepting connection with a simple instance-owned round-robin counter; the increment contains no yield point and needs no coroutine context or static lock. If that slot's connection is retiring, atomically move it into the retiring set and replace the slot with a fresh lazy `Connection`. The old connection removes itself from that set through a one-shot completion callback after its accepted healthy states finish and it closes.

`close()` is idempotent, closes every slot and still-retiring connection, empties both collections, and makes later calls fail with `LogicException`. Tests and deliberately short-lived clients call it explicitly. Worker-lifetime singleton clients rely on process socket teardown and never perform native work from a destructor.

The protected request methods parse the service path through `ServiceMethod`, then normalize metadata, options, serializer, response type, deadline, and retry policy. Invalid arguments/options, local serialization/size errors, and complete outbound metadata accounting fail synchronously before a call is returned. For a deadline-bearing request, that accounting uses the maximum-width nine-byte timeout value; the retained one-shot finalizer substitutes the fresh encoded remainder only after connection acquisition and immediately before native send. Unary/server-streaming calls retain an internal attempt factory so a permitted retry can select the next healthy connection and replay the already serialized body with a newly finalized timeout header. The initial attempt starts before the protected method returns, matching normal gRPC call behavior, but a connect/send transport failure is recorded as the call state's terminal `ConnectionException` and observed by `wait()`/`read()`/status access rather than unpredictably escaping from one generated stub method. A streaming call's later `write()` still throws the failure from that operation directly.

### 6.3 Connection concurrency and lifecycle

One `Connection` owns one engine client, one receive coroutine, one capacity-one send semaphore, and its active `StreamState` objects.

```php
/** @internal */
final class Connection
{
    /** @param \Closure(): Request $requestFactory */
    public function start(\Closure $requestFactory, StreamState $state, Deadline $deadline): void;
    public function write(StreamState $state, string $frame, bool $end, Deadline $deadline): void;
    public function close(): void;
}
```

The send semaphore is an instance-owned `Hypervel\Engine\Channel(1)`. Acquiring pushes a token; releasing pops it in `finally`. Deadline-bearing acquisition loops after a false/early timed wake and rechecks the absolute monotonic deadline because Swoole's millisecond timer scheduling can resume just before a nanosecond deadline. The guarded region includes lazy connect/reconnect, native `send()`, native `write()`, stream registration, connection retirement, and `close()`. This serializes every operation that writes shared HTTP/2/HPACK/socket state rather than delegating it to a send coroutine. Deterministic `close()` marks the connection closed and closes the channel while it owns the token, so blocked/future acquirers wake and fail from the closed state instead of racing native teardown; it then releases the queued token and never touches native state again.

Lazy construction receives `min(connect timeout, remaining deadline)` and `min(baseline write timeout, remaining deadline)`. After connection, `start()` invokes the request finalizer and calls the engine's deadline-bounded `send()`; `write()` does the same for each DATA operation. A no-deadline call republishes the positive baseline before its operation so it cannot inherit a shorter timeout from another stream. An actual connect failure terminates the connection. A native send/write failure also terminates it even when expiry means the call observes `DeadlineExceeded`, because the client cannot prove that no partial bytes were emitted. Expiry before native send does not poison a healthy socket.

The receive coroutine's ID is also a native-socket ownership token. Swoole forbids closing a coroutine socket from one coroutine while another remains blocked in its read wait. `Coroutine::create()` can run the receiver to completion before returning, so store the returned ID only when an immediate `Coroutine::exists()` check confirms that receiver survived synchronous startup. Clear the stored ID on every receiver exit, making the property the authoritative null-iff-alive ownership state; later termination trusts that maintained state instead of repeating a separate liveness check. When termination runs outside the receiver, publish terminal connection/call state first, cancel the owned receiver without injecting `CanceledException`, and unconditionally join its physical teardown before closing the engine client. The join remains unbounded because proceeding after a cleanup timeout could close a socket still owned by the receiver; unlike `Waiter`, this boundary cannot safely abandon its child. The shipped native client currently unwinds synchronously, but the explicit join keeps that ordering correct for a substituted engine client with yielding deferred cleanup and removes dependence on native cancellation internals. When termination already runs inside the receiver—receive failure or idle retirement—skip self-cancellation/join and close directly because that coroutine is already the sole socket owner. Do not wait for the one-second poll or add a shutdown channel: explicit `close()` remains synchronous, and the receiver ID is the direct minimal handoff.

The engine factory creates and connects a client with all resolved native settings before returning. Connection replacement occurs only when no active engine client is usable and a new call starts. A failed send/write after an attempt begins fails the affected call, marks the socket unusable, and fails every other active stream because connection integrity is no longer known. It does not replay.

Do not send automatic HTTP/2 pings. Standard gRPC clients disable keepalive by default, and servers commonly disallow pings without active calls and enforce a five-minute minimum interval; an unconfigurable 60-second liveness policy would provoke `ENHANCE_YOUR_CALM`/GOAWAY from conforming peers. The package has no verified deployment requirement or native ACK/watchdog surface sufficient to justify a public keepalive option. Connection loss is observed by active receive operations or the next send, and application deadlines bound active calls.

Before entering native `send()`, publish the one `StreamState` currently pending registration while still under the send semaphore. If the existing receive coroutine observes an unknown stream ID while `send()` is yielding, it atomically claims that pending state, attaches the observed ID, inserts it into the active map, and routes the event directly. When `send()` returns, the sender either performs that attachment itself or verifies the receiver-attached ID is identical, then clears the pending slot. An unknown ID with no pending start, a second ID racing the same start, or a returned/observed mismatch fails the connection. This closes the send/receive race without predicting Swoole's next stream ID, blocking the shared receiver, or creating an orphan-event buffer that a fast streaming peer could grow before `send()` returns.

The single receive coroutine is the only caller of `recv()`. Swoole does not surface inbound `RST_STREAM`: its 6.2.2 parser deletes the native stream and stays inside `recv()`. Use a one-second internal receive poll (not a user-facing option), then audit locally active stream IDs with `isStreamOpen()` after every returned event and timeout. One second bounds reset detection without adding a dedicated polling coroutine or turning transport internals into public configuration. It:

1. receives incremental HTTP/2 response events with `recv(1.0)` and tracks last inbound activity;
2. treats the engine contract's `null` timeout result as the only poll-idle case, audits native stream existence and every active state's monotonic deadline, and continues; an expired call with no observer currently waiting is still marked `DeadlineExceeded`, released, and retired within the one-second poll bound rather than remaining live forever;
3. routes every event by stream ID to `StreamState`;
4. after routing/removing any final event, audits remaining stream IDs and deadlines, failing only a missing stream with `ConnectionException` because Swoole no longer exposes its reset code and expiring each overdue state independently;
5. if the connection is retiring and no healthy state remains, closes it and discards its lightweight abandoned-ID set;
6. on any other receive/connection failure, records one `ConnectionException` in all healthy active states, wakes their waiters, clears all maps, and exits;
7. exits without closing a normally reusable engine client once no registered/native stream remains; a later call starts a new receive coroutine;
8. performs all cleanup in `finally`.

If a call becomes locally terminal before peer END_STREAM—deadline expiry, local backpressure exhaustion, or a call-local protocol/deserialization failure—the stream cannot be reset through Swoole. Release its queued payloads, decoder buffer, and waiters immediately; retain only its stream ID as a discard tombstone. Mark its connection retiring and stop assigning new calls to that connection. Existing healthy streams continue. As soon as no healthy accepted state remains, close the socket, discard the retired stream IDs, and end the receiver; the client slot is replaced lazily for the next call. This bounds native/local abandoned-stream lifetime without immediately sacrificing unrelated multiplexed calls. A normally quiescent, non-retiring connection remains reusable.

Swoole closes the socket when GOAWAY is processed. Because accepted streams cannot then be guaranteed to finish and a transport failure does not prove whether application work ran, all incomplete streams fail; no `serverLastStreamId` replay machinery is added.

### 6.4 Stream state and backpressure

`StreamState` is an internal per-attempt state machine. It owns:

- initial metadata and whether response headers committed the call;
- the negotiated response compression;
- a stateful frame decoder;
- a queue of completed serialized message payloads;
- final trailers/status;
- a terminal transport/protocol exception;
- a bounded-by-callers map of capacity-one waiter channels;
- local abandonment/half-close/completion flags.

The receive coroutine must never block on one slow call and prevent every other multiplexed stream from progressing. State mutation and waiter registration/removal do not wait for channel capacity. A consumer checks its predicate, registers a fresh capacity-one channel, rechecks to close the registration race, then pops only for the remaining deadline. Each state change signals every currently registered channel with `push(..., 0)`. Swoole does not block that push for capacity, but it synchronously resumes a waiting coroutine before the push returns, so every signal is a re-entrancy boundary: publish terminal/shared state before signaling and recheck connection state after each signal site. Every consumer removes/closes its own channel in `finally`. This supports concurrent metadata/status observers without a missed edge or a permanently retained waiter.

Enforce at most one active `read()`/`responses()` consumer per server/bidi stream with a non-yielding guard; a second reader receives `LogicException` instead of nondeterministic message division. Client/bidi `write()` and `writesDone()` share a call-local capacity-one semaphore so only one outbound operation mutates half-close state at a time. Bidi permits one reader and one writer concurrently, matching normal gRPC streaming semantics. Call completion deterministically closes these call-owned guards after no waiter can use them.

Track queued payload bytes as well as count and limit each state by both `max_buffered_messages` and `max_buffered_bytes`. If a caller stops reading and either limit would be crossed, mark that call locally `ResourceExhausted`, release its buffers, discard further DATA until peer finalization or connection retirement closes the socket, and wake the caller. Decrement the byte count as payloads are consumed. Do not block the shared receive loop or allow unbounded worker memory. Partially decoded bytes are separately bounded by the wire receive limit.

`StreamState` owns both forms of local receive-limit enforcement. It catches only the frame decoder's `RpcException` and publishes its `ResourceExhausted` status through the same abandonment path as buffered-message exhaustion. `ProtocolException` still escapes to the connection's response handler because malformed peer framing is a protocol failure rather than a configured call limit. This distinction prevents one oversized response from being wrapped as a connection failure and failing unrelated multiplexed streams.

Initial metadata commits an attempt when a non-trailers-only header block arrives. A first/final block with `grpc-status` is trailers-only and may be considered for retry. Message delivery also commits. Retry logic consumes a retryable trailers-only status internally; those attempt details are not exposed as the final call's metadata/status.

On a final non-retried trailers-only response, custom fields belong to `trailers()` and to a thrown `RpcException`; `metadata()` returns an empty initial-metadata object because no separate response-header block existed. `status()`/`trailers()` may throw transport or protocol exceptions, but `status()` returns a valid non-OK peer `Status` rather than turning it into `RpcException`.

### 6.5 Call object behavior

The abstract call coordinates `StreamState`, decoding, deadlines, and final status. It is not a public transport abstraction.

One call-local attempt-transition semaphore protects the current-attempt reference and retry counter. Any observer that sees a retryable trailers-only result acquires it, rechecks that the attempt is still current, and alone creates the next attempt; concurrent `wait()`/`metadata()`/`status()` callers then observe the replacement. This prevents duplicate retries without moving network I/O into a global manager.

- `metadata()` processes events until initial metadata or trailers-only completion.
- `status()` processes through final trailers and returns the final status even when non-OK.
- `trailers()` first ensures final status is known.
- unary `wait()` requires exactly one message on OK, throws on non-OK before returning it, and publishes its cached result once to concurrent waiters.
- server/bidi `read()` returns buffered messages in order, then checks final status.
- client-streaming `wait()` half-closes once, then uses the unary response rule.
- deserialization occurs in the caller coroutine, not the shared receive coroutine, so user-provided callables cannot stall other streams.
- when a local deadline expires, mark the state abandoned and stop exposing late messages; the receiver drops native events while the connection follows the retirement rule above.

Server-streaming retry is permitted only before any initial metadata or message commits the call. Once `read()` can expose a message, no retry occurs. Unary retry similarly requires trailers-only failure. Backoff and the next attempt both consume the original deadline.

### 6.6 TLS and native settings

Translate first-class TLS options to Swoole settings before passing them to the engine factory:

```php
[
    'ssl_verify_peer' => $tls['verify_peer'],
    'ssl_cafile' => $tls['ca_file'],
    'ssl_cert_file' => $tls['certificate'],
    'ssl_key_file' => $tls['private_key'],
    'ssl_passphrase' => $tls['passphrase'],
    'ssl_host_name' => $tls['server_name'] ?? $endpoint->host,
    'connect_timeout' => $options['connect_timeout'],
]
```

Filter only null values; preserve explicit false/zero values. Merge raw `swoole` settings last except for the two timeout invariants: reject raw `connect_timeout`, extract the positive finite baseline from raw `write_timeout` (falling back to raw `timeout`, then 60 seconds), and republish the deadline-bounded `connect_timeout`/`write_timeout` values at lazy construction. Keep positive raw write tuning as the upper bound rather than passing an unbounded literal through. The native socket applies generic `timeout` first and its specific connect/write values afterward, so these first-class bounds remain authoritative while other raw settings stay effective. The native client class is intrinsically HTTP/2, so there is no broader invented blacklist masquerading as transport invariants. Do not bundle root certificates; use the operating system trust store unless the caller supplies `ca_file`.

## 7. Server internals

### 7.1 Provider and dedicated port

`GrpcServiceProvider::register()` always merges configuration and binds client-independent public services. When `grpc.server.enabled` is true, it also:

- binds `HealthStatusProvider` to the stateless `ServingHealthStatusProvider` singleton unless the application replaces that contract;
- registers the isolated `GrpcRouter` and public `GrpcRouteRegistrar` singletons;
- binds `ServerCallContext` to the coroutine-local context store;
- appends exactly one HTTP server entry to `server.servers` before `serve` reads configuration;
- validates its configured name as non-empty; final cross-provider uniqueness is enforced by `hypervel/server` after every provider has registered, as described in section 8.4.

Use the container's existing conditional-singleton API so provider order cannot erase an application implementation:

```php
$this->app->singletonIf(
    HealthStatusProvider::class,
    ServingHealthStatusProvider::class,
);
```

The appended entry is a normal HTTP server type:

```php
[
    'name' => $server['name'],
    'type' => ServerInterface::SERVER_HTTP,
    'host' => $server['host'],
    'port' => $server['port'],
    'sock_type' => $tls->socketType(SWOOLE_SOCK_TCP),
    'callbacks' => [
        Event::ON_REQUEST => [Server::class, 'onRequest'],
    ],
    'settings' => array_replace($server['settings'], $tls->settings(), [
        'open_http_protocol' => true,
        'open_http2_protocol' => true,
        'open_websocket_protocol' => false,
        'http_compression' => false,
        'package_max_length' => $server['max_receive_message_size'] + 5,
    ]),
]
```

Whole-response HTTP compression is forced off because gRPC compression is per message. Swoole's HTTP/2 `package_max_length` is its per-stream accumulated body cap; derive it as the configured wire receive limit plus the five-byte gRPC frame header so valid messages are not reset by Swoole's lower default. Reject—not silently overwrite—raw settings for `open_http_protocol`, `open_http2_protocol`, `open_websocket_protocol`, `http_compression`, `package_max_length`, `ssl_cert_file`, `ssl_key_file`, `ssl_passphrase`, `ssl_verify_peer`, `ssl_allow_self_signed`, `ssl_client_cert_file`, `ssl_ciphers`, and `ssl_protocols`; these are owned by the protocol/message-limit or first-class TLS configuration. Other Swoole settings can configure buffers, socket keepalive, and comparable port behavior. The protocol decoder still rejects a declared payload over the limit before waiting for its bytes, yielding trailers-only `ResourceExhausted`; a peer that actually transmits beyond the native body cap is reset by Swoole before `onRequest`, as the transport's final memory-safety boundary.

`register()` registers the install command when running in console mode. `boot()` registers publish groups. If the server is enabled, `boot()` directly requires the configured gRPC route file (global HTTP route caching must not suppress isolated routes) and leaves middleware synchronization, validation, and compilation to `Server::bootstrapForServer()`.

The server defaults to disabled. Installing a package for an outbound client must not unexpectedly bind port 50051. `GRPC_SERVER_ENABLED=true` is the explicit opt-in.

### 7.2 Isolated router and shared routing seam

The public `GrpcRouteRegistrar` owns gRPC path validation and the `unary()`, `serverStream()`, and `service()` methods. It delegates registration/grouping to an `@internal` `GrpcRouter`. The router extends the normal `Router` for exact matching, groups, middleware, route names, controller resolution, closure resolution, and route compilation. It has its own route collection, so application HTTP routes are unavailable on the gRPC port and gRPC routes are unavailable on the application port. Keeping the facade bound to the registrar—not the `Router` subclass—is what prevents `Grpc::get()`, `Grpc::post()`, dispatch, and other inherited HTTP methods from becoming supported APIs.

After `KernelContract::bootstrap()` has run every application bootstrapper/provider, `Server::bootstrapForServer()` calls an internal `GrpcRouter::syncMiddlewareFrom($applicationRouter)`: every application-router alias from `getMiddleware()` is passed to `aliasMiddleware()`, every group from `getMiddlewareGroups()` is passed to `middlewareGroup()`, and `middlewarePriority` is copied verbatim. Synchronizing at this point—not during the package provider's earlier `boot()`—includes aliases/groups registered by later application-provider boots before route warmup resolves them. Global HTTP middleware is intentionally not copied; only middleware explicitly attached to gRPC routes/groups runs on the gRPC port.

Three small protocol-neutral routing corrections/extensions are necessary. First, replace both hardcoded `new Hypervel\Routing\Pipeline($container)` expressions in `Router` with a protected factory.

```php
// Hypervel\Routing\Router
protected function newPipeline(): \Hypervel\Pipeline\Pipeline
{
    return new \Hypervel\Routing\Pipeline($this->container);
}
```

Both `runRouteWithinStack()` and `dispatchToCallback()` call this method. Ordinary HTTP and WebSocket behavior remains byte-for-byte equivalent. `GrpcRouter` overrides it:

```php
protected function newPipeline(): \Hypervel\Pipeline\Pipeline
{
    return new Pipeline($this->container);
}
```

The gRPC `Pipeline` extends the base pipeline and maps thrown values through `ExceptionMapper` rather than HTTP's exception renderer. The closest failing middleware/action is mapped once, and outer middleware receives a valid internal gRPC response.

Second, extract the duplicated middleware-disable branch from `runRouteWithinStack()` and `dispatchToCallback()` into a protected method:

```php
// Hypervel\Routing\Router
protected function middlewareFor(Route $route): array
{
    $disabled = $this->container->bound('middleware.disable')
        && $this->container->make('middleware.disable') === true;

    return $disabled ? [] : $this->gatherRouteMiddleware($route);
}
```

Both dispatch paths call `middlewareFor()`. `GrpcRouter` overrides it, calls the parent, then prepends `HandleCall` after ordinary route middleware has been resolved, excluded, and priority-sorted. This places protocol validation, context, decoding, and deadline enforcement outside user middleware and makes it non-removable. In particular, Hypervel's `middleware.disable` testing flag may disable user middleware but can never bypass the gRPC protocol decoder; the earlier `gatherRouteMiddleware()`-only design would have been skipped entirely by that flag.

Third, make global route-state ownership distinguish the application's primary router from an isolated router. `Router::setRoutes()` and `setCompiledRoutes()` currently always replace the container's global `routes` binding and invalidate process-wide routing caches. Consequently, compiling the existing `ReverbRouter` can silently point the URL generator/global route consumers at Reverb's private collection, despite its intended isolation, and discard the primary router's just-warmed caches. Add one protected ownership predicate and use it for both responsibilities:

```php
// Hypervel\Routing\Router
protected function ownsGlobalRouteState(): bool
{
    return true;
}
```

`GrpcRouter` and `ReverbRouter` override it to return `false`; the application router retains the default and therefore preserves cached-route/URL-generator behavior. `setRoutes()` and `setCompiledRoutes()` guard both `flushRoutingCaches()` and the `routes` container rebind with this predicate. The name deliberately describes ownership rather than only rebinding, so a later caller cannot overlook the cache side effect. The caches contain immutable reflection/class-hierarchy facts keyed by action/class, so compiling a new isolated collection may safely add its entries; clearing them instead discards the primary router's just-completed pre-fork warmup. Local compiled/middleware state belongs to the new `Route` objects and does not need a global flush. This is narrower than a parallel router abstraction and fixes the same verified ownership bug for every isolated router. Test after resolving and warming the application `url`/`routes` bindings: compiling either isolated router must leave the application collection, URL generator, and warmed reflection entries untouched, while replacing/compiling the primary router must still flush/rebind as before.

`GrpcRouter::prepareResponse()` is idempotent: an internal `GrpcHttpResponse`/`GrpcStreamedResponse` passes through, while a service result is converted by `ResponseFactory` according to the matched route's unary/server-stream action marker. This works with Router's existing inner and outer preparation calls. Because ordinary Symfony responses are mutable, each internal response retains the protocol-owned status/content/header snapshot created by the factory. After dispatch and all outer middleware have returned—but before `ResponseBridge` starts—the server calls `ResponseFactory::finalizeForEmission()`. It verifies that middleware did not replace fixed gRPC fields, mutate the framed unary body/stream placeholder, or add/remove arbitrary response headers, then performs the definitive initial-block size check over that exact snapshot. A mutation is reported as an invalid middleware result and becomes compact Trailers-Only `Internal`; an oversized intact block becomes the compact `ResourceExhausted` fallback. Response metadata must use `GrpcResponse`/`RpcException`, not generic HTTP header mutation. This closes the post-factory validation gap without making the internal transport response a public extension API. No `GrpcRoute`, copied dispatch lifecycle, `dispatchToCallback()` detour, custom controller dispatcher, or new argument-resolution framework API is needed.

`GrpcRouter::compileAndWarm()` first validates every route action signature through the existing reflection cache, stores the protobuf parameter name/class in the action marker, and then delegates to the parent compilation/warmup. Validation happens exactly once before workers fork. This is gRPC-specific policy built from the existing public route-reflection surface, so it does not require another shared argument-resolution API.

### 7.3 Inbound call middleware

`HandleCall` performs one coherent inbound call setup after the server has established that this is a routable gRPC request:

1. decode user metadata, `grpc-timeout`, and the non-negative decimal `grpc-previous-rpc-attempts` value (absent means zero; malformed/repeated values are `Internal`);
2. validate `grpc-encoding`, parse `grpc-accept-encoding` for response negotiation, advertise accepted encodings on protocol errors, and construct the request decoder;
3. decode the complete body and require exactly one message frame for the supported server call shapes;
4. read the bootstrap-validated protobuf class/parameter from the route marker;
5. deserialize into that class and set it on the route under the validated parameter name;
6. construct/store `ServerCallContext`;
7. establish the deadline timer;
8. call the remainder of the route middleware/action pipeline;
9. recheck deadline after non-yielding work;
10. clear timer/context in every unary completion path and transfer cleanup to the streamed response lifecycle when response production is still pending.

The existing dispatcher sees the already supplied protobuf object, skips resolving another instance, resolves `ServerCallContext` through its container binding, and splices all remaining dependencies into reflected order. Add regression tests where container dependencies appear before, between, and after the message/context parameters.

The server—not middleware attached only after a successful match—owns request preflight. It first requires the raw Swoole `server_protocol` value to be exactly `HTTP/2`; HTTP/1.x receives HTTP 505 without a gRPC content type. Swoole 6.2.2 explicitly records `HTTP/2` in that field for native HTTP/2 requests, so this does not infer the protocol from headers. It then accepts only `POST`; other methods receive HTTP 405 with `Allow: POST` and without a gRPC content type. It parses the protocol media-type grammar through `MediaType` and accepts only implicit/explicit protobuf; non-gRPC and unsupported gRPC subtypes receive HTTP 415. Once a request is identified as supported gRPC, preflight reconstructs the transport-observable request header list from the raw Swoole request and listener TLS configuration as specified in section 5.4, then applies `max_metadata_size`, returning trailers-only `ResourceExhausted` when exceeded. It requires `te: trailers`; a missing/invalid value receives trailers-only HTTP 200/`Internal`. Finally, require the untouched wire path to start with exactly one `/`, contain no query, and equal `ServiceMethod::parse($rawPath)->path()` byte-for-byte. A trailing slash, missing/doubled leading slash, extra segment, invalid identifier, or any other non-exact method path becomes trailers-only `Unimplemented` before `RequestBridge` can apply normal HTTP path normalization. A syntactically valid but unregistered path receives the same `Unimplemented` result from route matching. This ordering makes protocol detection independent of route discovery and validates transport headers and exact protocol identity before method dispatch.

### 7.4 Server transport handler

`Server` implements `OnRequestInterface` and `BootstrapsForServer`, following Hypervel's HTTP/Reverb handlers:

```php
final class Server implements OnRequestInterface, BootstrapsForServer
{
    public function bootstrapForServer(string $serverName): void
    {
        $this->kernel = $this->container->make(KernelContract::class);
        $this->kernel->bootstrap();
        $this->router = $this->container->make(GrpcRouter::class);
        $this->router->syncMiddlewareFrom($this->container->make('router'));
        $this->router->compileAndWarm();
    }

    public function onRequest(
        SwooleRequest $swooleRequest,
        SwooleResponse $swooleResponse,
    ): void;
}
```

`onRequest()` waits for worker start, captures the raw Swoole method before Symfony method-override processing, bridges the request, stores `RequestContext`, performs the raw-method/media-type/path preflight, dispatches the isolated router, maps the route matcher's not-found exception to `Unimplemented`, passes the internal response through `ResponseFactory::finalizeForEmission()`, catches and reports other failures that occur before emission as `Unknown`, sends through `ResponseBridge`, and always closes any active call lifecycle afterward. It does not call the application's HTTP `Kernel::handle()`, apply global web middleware, or render an HTML/JSON exception page. Once `ResponseBridge::send()` has begun, any exception is treated as a transport failure because Swoole exposes no reliable "headers sent" query: report it, best-effort `end()` only if the socket remains writable, and never attempt a replacement RPC response that could corrupt a partially emitted stream. A non-deadline coroutine cancellation remains control flow rather than an RPC error and is rethrown to the shared `hypervel/server` response-callback boundary, which silently aborts and best-effort completes a still-writable native response.

The HTTP kernel is resolved only to run the application's normal bootstrap sequence before middleware synchronization and route compilation. The gRPC port does not run the HTTP global middleware stack or HTTP terminating lifecycle.

### 7.5 Response factory and exception mapping

`ResponseFactory` converts application values into internal Symfony responses:

- unary `Message`/`GrpcResponse::make()` → `GrpcHttpResponse` with one framed message;
- streaming `iterable`/`GrpcResponse::stream()` → the primed stream behavior below, then `GrpcStreamedResponse` whose retained iterable yields the cached first frame and frames subsequent messages one at a time;
- an error before response commitment → true Trailers-Only `GrpcHttpResponse`, with final status/custom metadata in its one header block and an empty native trailer map;
- wrong types/counts/sizes → reported `Internal` response.

The factory is also the single final-emission gate. `finalizeForEmission()` accepts only its own internal response types, compares the fixed status/content/header snapshot after middleware, and computes the definitive outbound initial-block size. The compact `Internal` and `ResourceExhausted` fallbacks are generated through a private non-recursive path already proven to fit by provider validation; a broken middleware response can never cause the validator to recurse through the same invalid value. Dynamic streamed trailers are validated and size-checked when production completes, as described below.

For every server stream, normalize the iterable to one iterator and prime it inside `ResponseFactory` before any response headers leave:

- an exception before the first yield is mapped to a true Trailers-Only error;
- a clean empty iterator becomes a true Trailers-Only OK response;
- the first valid message is serialized/framed once and retained by `GrpcStreamedResponse`, which then continues the same iterator without rewinding it;
- deadline and send-size checks apply while priming exactly as they do during later iteration.

This matches the header commitment Swoole can actually put on the wire and makes the client retry rule real rather than nominal. If the first item exists, queued initial metadata is emitted with the initial response headers immediately before that cached frame and commits the attempt. If the iterator is empty or throws before its first item, no separate header block was sent: queued initial and trailing metadata are combined, preserving value order for duplicate keys, into the true Trailers-Only block and are observed by the client as trailing metadata. This is the same distinction between queued metadata and explicitly flushed headers in mature servers; Hypervel does not expose a `sendInitialMetadata()` API that its transport cannot implement. Never materialize more than the one primed frame.

Every valid gRPC response is HTTP 200 and sets:

```text
content-type: application/grpc+proto
grpc-accept-encoding: identity,gzip
server: grpc-php-hypervel/<package-version>
date: <one captured RFC 7231 timestamp>
trailer: grpc-status, grpc-message, grpc-status-details-bin, <metadata keys...>
```

Capture the server/date strings once when the response object is built and include those exact values in metadata-size accounting. The Trailers-Only exception below omits only the `trailer` announcement; it still sets the other applicable response fields. This prevents Swoole's otherwise automatic `server`/`date` insertion from invalidating a limit check after the package has accepted a header block.

`content-length` is intentionally absent from the application header bag because Swoole ignores a supplied value and derives its own. `ResponseFactory` nevertheless includes the native field in the size calculation exactly as described in section 5.4: the framed unary length, zero for Trailers-Only, and the conservative first-frame reservation for a non-empty server stream.

Initial metadata is emitted as ordinary response headers. After commitment, final application metadata and status fields are emitted through the trailer contract. The `Trailer` announcement contains the three fixed status names plus every application-metadata or retry-pushback name known before headers are sent. `grpc-status-details-bin` is named up front even if unused so a streamed exception can add rich details after headers have already left; a valid custom or retry-pushback name first discovered from a mid-stream exception is permitted by the HTTP/2 trailer path described in section 8.2. A true Trailers-Only response is the deliberate exception: all final fields are ordinary headers in its single END_STREAM header block, there is no `Trailer` announcement/native trailer call, and the client still classifies custom fields as trailing metadata because `grpc-status` is present on the final first event.

Exception mapping table:

| Failure | Result |
|---|---|
| service-thrown `RpcException` | its status/message/rich details/custom trailing metadata; not reported |
| deadline expired | `DeadlineExceeded` |
| request/send message exceeds configured size | `ResourceExhausted` |
| unsupported request compression | `Unimplemented` plus `grpc-accept-encoding` |
| malformed frame, gzip, timeout, or request protobuf | `Internal` |
| unknown route/method | `Unimplemented` |
| invalid service return/yield | reported, then `Internal` |
| any other service/middleware throwable | reported, then `Unknown` with a fixed non-sensitive message |

Do not expose `app.debug` exception messages over gRPC. Logs and the configured exception reporter retain diagnostics.

### 7.6 Server streaming lifecycle

The service returns an iterable without being materialized beyond the one-frame priming rule above. `GrpcStreamedResponse` extends Hypervel's existing `IterableStreamedResponse` and implements only `HasTrailers`. Its retained chunk iterable yields the cached primed frame, then continues the same iterator, checks deadline and message size for every item, and serializes/frames one item at a time. Application iteration/serialization failures after commitment are mapped to final error trailers and reported when appropriate. `IterableStreamedResponse::streamTo()` gives the bridge direct, synchronous write feedback: a failed writer stops and releases the producer immediately, while a thrown transport error unwinds without being mistaken for a service failure. Already emitted valid messages are never retracted. A `Swoole\Coroutine\CanceledException` raised while service iteration is running becomes `DeadlineExceeded` only when this call's monotonic deadline has actually expired; shutdown/unrelated cancellation and cancellation during transport emission escape to the transport handler instead of being mislabeled.

The response owns a completion callback that clears the deadline timer and coroutine-local call context after iteration or exception. The retained iterable runs that callback in `finally`, and the server's outer lifecycle close is idempotent so bridge/header/finalization failures also clean up.

The response does not call Swoole itself. It builds on the protocol-neutral framework response plus `HasTrailers`; the bridge owns native writes, one-chunk lookahead, trailers, and finalization. Do not add a second streaming contract beside `IterableStreamedResponse`.

## 8. Cross-package framework changes

Every change in this section is required by a concrete final gRPC code path. Do not add neighboring generalizations while editing these files.

### 8.1 `hypervel/engine` and `hypervel/contracts`: complete the consumed HTTP/2 surface

Current verified losses:

- `ClientFactoryInterface::make()` cannot pass settings, even though `Client::__construct()` accepts them and connects immediately.
- `ClientInterface::set()` suggests settings can be changed after construction even though the concrete constructor has already connected; repository search finds no consumer, and post-connect transport settings are misleading.
- `RequestInterface` exposes only `pipeline`; `Client::transformRequest()` incorrectly assigns that same value to native `pipeline` and `usePipelineRead`, although they have independent meanings.
- `Response` drops native `pipeline`, preventing callers from identifying incremental and final response events.
- `ClientInterface` cannot ask whether a known stream still exists, which is the only PHP-visible way to detect Swoole 6.2.2's swallowed inbound `RST_STREAM`.
- `recv()` throws the same generic exception for a caller-requested poll timeout and a real connection failure, forcing consumers to know Swoole's native timeout code.
- `write()` returns an unchecked false while `send()` and `recv()` throw `HttpClientException`.
- native HTTP/2 client methods can also throw their Swoole-specific exception while flushing queued control frames, bypassing the engine exception boundary even on methods that check false returns.

Change the contracts to:

```php
interface ClientFactoryInterface
{
    public function make(
        string $host,
        int $port = 80,
        bool $ssl = false,
        array $settings = [],
    ): ClientInterface;
}

interface RequestInterface
{
    public function getPath(): string;
    public function getMethod(): string;
    public function getHeaders(): array;
    public function getBody(): string;
    public function isPipeline(): bool;
    public function usesPipelineRead(): bool;
}

interface ResponseInterface
{
    public function getStreamId(): int;
    public function getStatusCode(): int;
    public function getHeaders(): array;
    public function getBody(): ?string;
    public function isEndStream(): bool;
}

interface ClientInterface
{
    public function send(RequestInterface $request, ?float $timeout = null): int;
    public function recv(float $timeout = 0): ?ResponseInterface;
    public function write(
        int $streamId,
        string $data,
        bool $end = false,
        ?float $timeout = null,
    ): void;
    public function close(): void;
    public function isConnected(): bool;
    public function isStreamOpen(int $streamId): bool;
}
```

Update concrete `Request` with an independent `bool $usePipelineRead` constructor/property, `usesPipelineRead()` accessor, and setter. Update `Client::transformRequest()` to copy both fields exactly. Update concrete `Response` to receive native `pipeline`, with `isEndStream()` returning `! $pipeline`. Update `transformResponse()` accordingly. Implement `isStreamOpen()` as the normalized engine wrapper for native `isStreamExist()`. Remove the general `set()` from the contract and concrete client; construction/factory settings remain the only arbitrary-settings path. The optional `send()`/`write()` timeout is the deliberately narrow exception with a concrete deadline consumer: validate a positive finite value and apply only native `write_timeout` immediately before that operation. The gRPC connection serializes those calls and republishes an effective timeout before each one, so post-connect mutation cannot leak one stream's deadline into another.

Normalize `recv()` so native `SOCKET_ETIMEDOUT` returns `null` and every other native false becomes `HttpClientException($errMsg, $errCode)`; the native timeout constant remains inside the engine implementation. Normalize native false from `write()` into the same exception. At construction and around every retained native operation (`send`, `recv`, `write`, `isStreamOpen`, and active `close`), catch the native Swoole HTTP/2 client exception and rethrow `HttpClientException` with the best available native message/code and the original as `previous`; no Swoole exception type crosses the engine boundary. Remove `ping()` from the contract and concrete client together with `set()`: repository search finds no HTTP/2 engine consumer for either, and gRPC has no keepalive policy. `close()` becomes idempotent `void`; it may ignore an already-closed false but throws if an active close fails for another reason. Existing engine tests and consumers are updated to the smaller, stronger consumed contracts.

Do not expose the native `isStreamExist()` spelling or add `stats()`, `goaway()`, `serverLastStreamId()`, a public `connect()`, response-error accessors, or raw client error getters. The one required normalized method is `isStreamOpen()`: Swoole's swallowed `RST_STREAM` behavior gives it a concrete consumer. The final connection tracks the rest of its own state, replaces the factory-created client to reconnect, receives operation errors as exceptions, and cannot safely resume accepted streams after Swoole closes on GOAWAY.

Files:

- `src/contracts/src/Engine/Http/V2/ClientFactoryInterface.php`
- `src/contracts/src/Engine/Http/V2/ClientInterface.php`
- `src/contracts/src/Engine/Http/V2/RequestInterface.php`
- `src/contracts/src/Engine/Http/V2/ResponseInterface.php`
- `src/engine/src/Http/V2/ClientFactory.php`
- `src/engine/src/Http/V2/Client.php`
- `src/engine/src/Http/V2/Request.php`
- `src/engine/src/Http/V2/Response.php`
- their unit and integration tests

### 8.2 `hypervel/http-server` and `hypervel/contracts`: trailer-aware response emission

Add a protocol-neutral contract:

```php
namespace Hypervel\Contracts\Http;

interface HasTrailers
{
    /** @return list<string> */
    public function trailerNames(): array;

    /** @return array<string, string> */
    public function trailers(): array;
}
```

`trailerNames()` is available before headers/body are sent; `trailers()` can resolve final dynamic values after a stream producer finishes.

The updated `0.4` branch already owns direct iterable streaming in `Hypervel\Http\IterableStreamedResponse`. It retains iterable chunks and exposes the internal `streamTo(Closure(string): bool)` bridge seam, clearing the producer in `finally` and falling back to the ordinary Symfony callback path if user code replaces the chunks with `setCallback()`. Reuse that class rather than adding the duplicate planned `StreamsContent` contract. `GrpcStreamedResponse` extends `IterableStreamedResponse` and implements `HasTrailers`; the only new shared contract is trailers.

While touching the owning emission path, fix its existing repeated-header loss. `ResponseBridge::sendStatusAndHeaders()` currently calls Swoole `header()` once per Symfony value, but Swoole stores the field in an associative slot, so every call after the first replaces the preceding value. Swoole 6.2's `header(string, string|array)` implementation natively emits each element of an array as a separate field. Pass a scalar for one value and the complete values list for repeated fields in one checked call. gRPC `MetadataCodec` still comma-combines its own repeated outbound metadata because Swoole's HTTP/2 client collapses independently repeated response fields while parsing; the generic bridge must nevertheless preserve repetition for other servers/clients that can observe it.

Extend `ResponseBridge`'s existing `IterableStreamedResponse` fast path, remove conflicting `Content-Length`/`Transfer-Encoding` fields for every streaming response, and share checked status/header/cookie/trailer/fixed-response finalization helpers. Trailer-aware normal responses follow this order:

```php
static::announceTrailers($response, $swooleResponse);
static::sendStatusAndHeaders($response, $swooleResponse);
static::sendTrailers($response, $swooleResponse);

$content = (string) $response->getContent();
$ended = $content === ''
    ? $swooleResponse->end()
    : $swooleResponse->end($content);

if (! $ended) {
    throw new RuntimeException('Unable to complete the response.');
}
```

Trailer-aware streamed responses use the verified one-chunk lookahead:

```php
$pending = null;
$writeFailed = false;
$writeFailure = null;
$producerFailure = null;
$level = ob_get_level();

ob_start(function (string $chunk) use (&$pending, &$writeFailed, &$writeFailure, $swooleResponse): string {
    if ($chunk === '' || $writeFailed) {
        return '';
    }

    if ($pending !== null) {
        try {
            $writeFailed = ! $swooleResponse->write($pending);
        } catch (\Throwable $throwable) {
            $writeFailure = $throwable;
            $writeFailed = true;
        }

        if ($writeFailed) {
            return '';
        }
    }

    $pending = $chunk;

    return '';
}, 1);

try {
    $response->sendContent();
} catch (\Throwable $throwable) {
    $producerFailure = $throwable;
} finally {
    static::restoreOutputBufferLevel($level);
}

if ($writeFailure !== null) {
    throw $writeFailure;
}

if ($writeFailed) {
    throw new RuntimeException(
        'Unable to write the streamed response.',
        previous: $producerFailure,
    );
}

if ($producerFailure !== null) {
    throw $producerFailure;
}

static::sendTrailers($response, $swooleResponse);

$ended = $pending === null
    ? $swooleResponse->end()
    : $swooleResponse->end($pending);

if (! $ended) {
    throw new RuntimeException('Unable to complete the streamed response.');
}
```

The output-buffer adapter above remains the fallback for third-party Symfony `StreamedResponse` objects and an `IterableStreamedResponse` whose callback has been replaced. Preserve the upstream cleanup guard for non-removable nested buffers. For a retained trailer-bearing `IterableStreamedResponse`, use the same one-chunk lookahead through `streamTo()`:

```php
$pending = null;
$writeFailed = false;

$handled = $response->streamTo(function (string $chunk) use (&$pending, &$writeFailed, $swooleResponse): bool {
    if ($chunk === '') {
        return true;
    }

    if ($pending !== null && ! $swooleResponse->write($pending)) {
        $writeFailed = true;

        return false;
    }

    $pending = $chunk;

    return true;
});

if (! $handled) {
    return false;
}

if ($writeFailed) {
    throw new RuntimeException('Unable to write the streamed response.');
}

static::sendTrailers($response, $swooleResponse);

$ended = $pending === null
    ? $swooleResponse->end()
    : $swooleResponse->end($pending);

if (! $ended) {
    throw new RuntimeException('Unable to complete the streamed response.');
}

return true;
```

The direct non-trailer `IterableStreamedResponse` path keeps the upstream clean-disconnect contract: each non-empty chunk is written immediately, a false write stops/releases the producer without creating exception/log noise, and the bridge still attempts its no-argument `end()`. A thrown write or producer exception propagates after producer cleanup. Ordinary Symfony callback streaming likewise stops further native writes after false while allowing the callback to finish because an output handler cannot safely throw. Its no-argument final `end()` remains best-effort; false is not promoted to an error after a clean peer disconnect.

`announceTrailers()` merges every normalized trailer name known before emission into the Symfony response's `Trailer` header before `sendStatusAndHeaders()` copies headers to Swoole. At this transport boundary, require every announced/final name to match the HTTP token grammar and Swoole's verified 127-byte header-name cap; reject pseudo-fields, framing/routing fields (`host`, `content-length`, `transfer-encoding`, `trailer`, and `te`), and HTTP/2-forbidden connection fields (`connection`, `keep-alive`, `proxy-connection`, and `upgrade`) as trailers; lowercase/deduplicate announced names in first-seen order; require every final value to be a string without CR/LF; and require the final associative key set to be unique after lowercasing. Then call native `trailer()` for each final value after streamed content production but before `end($lastChunk)`; a false trailer result is a transport exception. Do not require the final set to equal the announced set: HTTP/2 permits a trailer discovered during streamed production, and Swoole builds the final HEADERS block from its trailer map at `end()`. Zero chunks correctly call the no-argument `end()` path proved by the spike; one chunk is never sent through `write()` first.

Apply trailer announcement before every header-send branch. `withBody: false` is the HTTP HEAD path: it may send the known `Trailer` announcement with the ordinary headers, but it must not invoke a stream producer or evaluate/send final trailer values for a body that does not exist; it calls checked no-argument `end()` directly. Reject `HasTrailers` combined with `BinaryFileResponse` before bridge emission because `sendfile()` cannot honor dynamic trailers. Preserve the updated `0.4` already-direct-streamed `Hypervel\Http\Response` path: `Response::stream()` writes headers/chunks itself but deliberately leaves final no-argument `end()` to the bridge, whose exhaustive server lifecycle depends on that ownership. Supported gRPC responses never enter that low-level escape hatch, so do not add a speculative `HasTrailers` runtime guard around it.

Never throw from the `ob_start()` callback. PHP can bypass a failing output handler and leak the original chunk to process output. A trailer-bearing fallback remembers the first false/thrown native write separately from any producer throwable, swallows all later callback output, and restores removable buffers to the exact reachable original level. After restoration, transport failure takes precedence (with the producer throwable attached when the bridge creates the false-write exception); otherwise rethrow the producer failure. Only a fully successful trailer-bearing producer/write path may attempt trailers or `end()`. Preserve the updated upstream non-trailer ordering and clean-disconnect behavior rather than turning its false writes/final ends into transport errors.

Normalize false from `status()`, `header()`, and `cookie()` on every response, from `sendfile()` and fixed-body/HEAD `end()`, and from every trailer-bearing `write()`, `trailer()`, and `end()` into a transport `RuntimeException` after any required output-buffer restoration. Keep ordinary streamed writes/final no-argument ends and the already-direct-streamed final end on their upstream clean-stop contract; thrown native exceptions still propagate. Small private assertion helpers avoid duplicating the checked branches without flattening this meaningful distinction.

Do not teach `ResponseBridge` gRPC status names, framing, metadata, or exception mapping. Add tests with simple `HasTrailers` fixed/iterable/Symfony-callback responses, including no-body emission, zero/one/multiple chunks, a value and an additional valid trailer name discovered during production, rejected binary combination, exception cleanup, false trailer-bearing writes without process-output leakage, ordinary iterable/callback clean-stop behavior, and the exact call order that prevents the Swoole 6.2.2 trailer loss.

Files:

- new `src/contracts/src/Http/HasTrailers.php`
- `src/http-server/src/ResponseBridge.php`
- `tests/HttpServer/ResponseBridgeTest.php`

### 8.3 `hypervel/routing`: dispatch hooks, isolated collections, and exception-safe groups

Add the protected `Router::newPipeline()`, `Router::middlewareFor()`, and `Router::ownsGlobalRouteState()` methods described in section 7.2. Use the first two in both current dispatch construction sites and use the ownership predicate around global cache invalidation and container rebinding in both route-collection replacement sites. Add routing tests with tiny subclasses and assert both normal route dispatch and `dispatchToCallback()` use each dispatch hook, including the `middleware.disable` branch. Assert primary-router replacement still invalidates/rebinds global state while an isolated subclass does neither.

Complete the existing invokable-callable path so gRPC bootstrap validation can use `Route::signatureParameters()` for every action shape its registrar accepts. `Router::addRoute()` accepts `callable`, while `RouteAction` and `CallableDispatcher` contain invokable-object branches, but two actual blockers currently make that path unreachable: `Route::__construct()` narrows the raw action back to `Closure|array|null`, and `Route::$callable` can cache only a `Closure`. Widen the constructor to `callable|array|null`, matching `Route::parseAction()`, and widen the cached property from `?Closure` to `?object`. Every callable that can reach `runCallable()` is either a closure or invokable object—controller arrays/class strings are normalized to `Class@method` during route creation—so `?object` is both complete and a valid PHP property type, unlike `callable`. An invokable callable object must use `ReflectionMethod($object, '__invoke')` rather than the current invalid `ReflectionFunction($object)` path; cache both closure and invokable-object parameter reflection in `CallableDispatcher`'s existing worker-lifetime `WeakMap` so invokable routes do not re-reflect on every request without retaining otherwise-dead route objects. Widen `RouteSignatureParameters`' weak-map key annotation from `Closure` to `object`. Make the matching annotation change in `ImplicitRouteBinding`, whose existing non-string branch already uses a `WeakMap` at runtime, so static analysis reflects the now-supported object keys. While touching this path, make `Router::actionReferencesController()` branch explicitly by action type and return false for an invokable object before reading `['uses']`; current `isset($action['uses'])` on that object already returns false rather than crashing, so this is clarity/static-analysis cleanup, not a third blocker. Update `Router::warmUp()` to prewarm `RouteSignatureParameters` for every registered action instead of retaining its now-stale controller-only guard/comment. Preserve the current clear missing-class/missing-method behavior and flush semantics. Add routing regressions for a closure, controller array/string, invokable class string, and invokable object through registration, warmup, signature inspection, implicit-binding inspection, and dispatch; specifically assert that `actionReferencesController()` continues to classify invokable objects as non-controller actions rather than claiming a pre-existing crash. Then let `GrpcRouter::compileAndWarm()` apply its protobuf/context rules to the returned parameters. This completes a verified routing capability rather than adding a new argument-resolution API.

Also make existing `Router::group()` stack cleanup exception-safe. Its current `array_pop()` is skipped when a route closure/file throws, so a `Grpc::service()` failure can leak its service prefix into later registrations. Wrap each `loadRoutes()` call in `try/finally` and pop exactly the frame pushed for that iteration. Test nested ordinary groups, a throwing closure, a throwing route file, and successful registration after the exception. This is a fix to existing ownership, not a gRPC-only workaround.

Normalize valid callable arrays uniformly at the `RouteAction::parse()` boundary. A `[class-string, method]` value—whether supplied as the top-level action or nested under `uses`—becomes the ordinary `Class@method` controller form and retains container-controlled singleton/scoped/bound lifetimes. An `[object, method]` value in either position becomes `Closure::fromCallable()`, preserving the caller-supplied object just as an invokable object or capturing closure does. Named-function and `Class::staticMethod` strings still fail through the existing invalid-action exception. Document that caller-supplied closures, invokable objects, and object-method callables persist for the worker lifetime, and recommend class-string controller arrays when the container should own the controller lifetime. This prevents equivalent top-level and nested action forms from diverging without adding a new callable cache or serialization form.

Do not otherwise alter Laravel-parity route registration, response conversion, controller dispatch, dependency resolution, route serialization, or WebSocket callback signatures.

Files:

- `src/routing/src/Router.php`
- `src/routing/src/Route.php`
- `src/routing/src/RouteSignatureParameters.php`
- `src/routing/src/ImplicitRouteBinding.php`
- `src/reverb/src/Servers/Hypervel/ReverbRouter.php`
- focused tests under `tests/Routing/`
- Reverb route-isolation regressions under `tests/Reverb/`

### 8.4 `hypervel/server`: stable port ordering and reusable TLS translation

Appending a gRPC HTTP port currently exposes a server bug: `Server::sortServers()` repeatedly uses `array_unshift()` for HTTP ports, reversing their configured order. An appended gRPC port can become the main Swoole server instead of the application's first HTTP port. Replace it with a stable priority sort:

```php
// Priority preserves existing intent: WebSocket main when present, then HTTP,
// then base servers. Original order is the tie-breaker.
$priority = match ($port->getType()) {
    ServerInterface::SERVER_WEBSOCKET => 0,
    ServerInterface::SERVER_HTTP => 1,
    default => 2,
};
```

Decorate each configured port with its original index, sort by `[priority, index]`, then strip the decoration. Delete `enableHttpServer` and `enableWebSocketServer`: repository search confirms they exist only to drive the old order-dependent algorithm and would become dead fields after the stable sort. Existing one-port and HTTP+WebSocket behavior remains unchanged; multiple HTTP ports finally preserve configuration order. Add regression tests proving an appended second HTTP listener cannot replace the first as main.

Move listener-name uniqueness to the owning final configuration boundary. A gRPC provider cannot reliably reject a duplicate by inspecting `server.servers` during its own `register()` because a later package provider may append the collision. While `ServerConfig` materializes the complete post-provider port list, require every resolved `Port::getName()` to be non-empty and unique and throw `Hypervel\Server\Exceptions\InvalidArgumentException` naming the duplicate before `Server::init()` binds anything. The gRPC provider still validates its own shape early, but does not pretend provider order is a global guarantee. Add `ServerConfig` regressions for associative/numeric entries, duplicate names in either order, and distinct multi-provider-style listeners.

Reverb already contains private translation from Laravel-style server TLS options to Swoole settings. gRPC is the second concrete server consumer. Extract that exact concern into `Hypervel\Server\TlsOptions` and update Reverb and gRPC to use it:

```php
final readonly class TlsOptions
{
    public static function fromArray(array $options): self;
    public function enabled(): bool;
    public function socketType(int $type = SWOOLE_SOCK_TCP): int;

    /** @return array<string, mixed> */
    public function settings(): array;
}
```

The class accepts the existing Reverb keys (`local_cert`, `local_pk`, `passphrase`, `verify_peer`, `allow_self_signed`, `cafile`, `ciphers`, `crypto_method`), filters only nulls, owns the existing Swoole-name map, and preserves Reverb's existing pass-through for already-native `ssl_*` keys. `enabled()` retains the current cert-or-key detection; paired-certificate validation is a gRPC provider concern and must not silently change Reverb behavior. Keep Reverb's public configuration unchanged; delete its now-duplicated private resolver methods and update its tests. Do not create a broader server-options builder.

Files:

- `src/server/src/Server.php`
- `src/server/src/ServerConfig.php`
- new `src/server/src/TlsOptions.php`
- `src/reverb/composer.json` (add the already-used `hypervel/server` as a direct dependency)
- `src/reverb/src/ReverbServiceProvider.php`
- `tests/Server/ServerTest.php`
- `tests/Server/TlsOptionsTest.php`
- affected Reverb provider tests

### 8.5 `hypervel/support`: first-party facade placement

Add `Hypervel\Support\Facades\Grpc` in `src/support/src/Facades/Grpc.php`, following every other first-party facade and specifically the existing optional-package `Jwt` precedent. Its accessor returns `Hypervel\Grpc\Server\GrpcRouteRegistrar::class`; a class-string accessor is lazy and therefore does not require a support-package Composer dependency on `hypervel/grpc`. The gRPC package already depends directly on support. Do not retain a package-local duplicate, a global alias, or the third-party Sentry layout as precedent for a first-party API.

Files:

- new `src/support/src/Facades/Grpc.php`
- new `tests/Support/Facades/GrpcTest.php`

### 8.6 Static test cleanup

Do not add a gRPC method to `AfterEachTestSubscriber`. The package design has no static cache or process-global registry:

- facade roots are already cleared by the generic facade cleanup;
- router/controller/reflection caches are owned and flushed by routing's existing cleanup;
- connection/client/call state is instance-owned;
- `CallContextStore` uses coroutine context;
- wire codecs are stateless except per-instance `FrameDecoder`.

If implementation introduces static mutable state, remove it rather than adding cleanup unless the cache is a measured worker-lifetime optimization that follows the repository's static-cache rules.

### 8.7 `hypervel/coordinator`, `hypervel/server`, and `hypervel/foundation`: truthful timers and native exception boundaries

`Hypervel\Coordinator\Timer::after()` and `tick()` currently assume that one timed coordinator wait proves the requested interval elapsed. Swoole's positive channel waits can return slightly before a monotonic nanosecond deadline, so that assumption is false. Capture the `after()` start immediately before creating its child coroutine and pass it into a shared interval wait. After every non-closing wake, calculate elapsed seconds from `hrtime(true)` and wait the positive remainder; return only after the requested interval elapsed, the coordinator closed, or `clear()` removed the timer. `tick()` captures a new start for each interval after the preceding callback. Keep the existing `waiting` registration around every actual suspension so `clear()` remains cancellation-safe, and check timer registration between waits. The code comment records only the portable reason for the loop—coordinator waits can return before the monotonic interval elapsed—not Swoole's internal timer implementation.

Swoole-facing response callbacks also need one last protocol-neutral exception boundary. In `Hypervel\Server\Server::registerSwooleEvents()`, wrap only the two native event kinds whose second argument is `Swoole\Http\Response`: `Event::ON_REQUEST` and `Event::ON_HANDSHAKE`. The wrapper invokes the resolved callback normally. If `Swoole\Coroutine\CanceledException` escapes, do not report it or manufacture a response; best-effort call `end()` only when the native response remains writable. For another `Throwable`, report through `Hypervel\Contracts\Debug\ExceptionHandler`, then perform the same best-effort completion. Reporting, writability checks, and completion are themselves inside the total boundary and never rethrow. Ordinary HTTP, Reverb HTTP/handshake, and gRPC retain ownership of their normal protocol-specific errors; the wrapper handles only failures that escaped them.

Do not wrap lifecycle/bootstrap callbacks: their failures must still abort startup. Do not apply the response wrapper to `ON_MESSAGE`, `ON_CLOSE`, `ON_RECEIVE`, task, packet, or similar callbacks that have different argument/return contracts and no native HTTP response. Their uncaught failures reach the global backstop below.

Finally, remove `Foundation\Bootstrap\HandleExceptions::renderHttpResponse()`. It is Laravel-SAPI machinery that cannot work in Hypervel: the global handler has no native Swoole response, and `Hypervel\Http\Response::send()` intentionally throws. `handleException()` continues to report first and retains console rendering/exit behavior. For a non-console worker it returns after reporting, allowing the failed coroutine/callback to terminate without a second exception. Catch any `Throwable` from reporting rather than only `Exception`, because this is the process's last exception backstop and an `Error` from the reporter must not recursively fault.

Files:

- `src/coordinator/src/Timer.php`
- `tests/Coordinator/TimerTest.php`
- `src/server/src/Server.php`
- `tests/Server/ServerTest.php`
- `src/foundation/src/Bootstrap/HandleExceptions.php`
- `tests/Foundation/Bootstrap/HandleExceptionsTest.php`

## 9. Configuration, package metadata, and installation

### 9.1 Published configuration

`src/grpc/config/grpc.php`:

```php
<?php

declare(strict_types=1);

return [
    'server' => [
        'enabled' => (bool) env('GRPC_SERVER_ENABLED', false),
        'name' => (string) env('GRPC_SERVER_NAME', 'grpc'),
        'host' => (string) env('GRPC_SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('GRPC_SERVER_PORT', 50051),
        'routes' => base_path('routes/grpc.php'),
        'max_receive_message_size' => (int) env('GRPC_SERVER_MAX_RECEIVE_MESSAGE_SIZE', 4 * 1024 * 1024),
        'max_send_message_size' => (int) env('GRPC_SERVER_MAX_SEND_MESSAGE_SIZE', 4 * 1024 * 1024),
        'max_metadata_size' => (int) env('GRPC_SERVER_MAX_METADATA_SIZE', 8 * 1024),
        'compression' => env('GRPC_SERVER_COMPRESSION'),
        'tls' => [
            'local_cert' => env('GRPC_SERVER_TLS_CERT'),
            'local_pk' => env('GRPC_SERVER_TLS_KEY'),
            'passphrase' => env('GRPC_SERVER_TLS_PASSPHRASE'),
            'verify_peer' => (bool) env('GRPC_SERVER_TLS_VERIFY_PEER', false),
            'allow_self_signed' => (bool) env('GRPC_SERVER_TLS_ALLOW_SELF_SIGNED', false),
            'cafile' => env('GRPC_SERVER_TLS_CLIENT_CA'),
            'ciphers' => env('GRPC_SERVER_TLS_CIPHERS'),
            'crypto_method' => null,
        ],
        'settings' => [],
    ],
];
```

Provider validation requires a non-empty server name, valid host, port 1–65535, positive size limits that fit the unsigned 32-bit frame/native body cap (`max_receive_message_size <= 0xffffffff - 5` and `max_send_message_size <= 0xffffffff`), a metadata limit large enough for `MetadataCodec`'s computed minimal Trailers-Only error block, a readable route file, supported/null compression, the exact declared TLS option keys/types, paired readable TLS certificate/key files, a readable client CA when peer verification is enabled, and an array of raw settings without any provider-owned key listed in section 7.1. Final listener-name uniqueness is checked by `ServerConfig` over the complete post-provider list. The metadata minimum is derived by running the same wire-size function over the exact fixed `:status`/content-type/status/server/date/accepted-encoding fields plus native `content-length: 0`, not by maintaining a second magic byte constant. Without a certificate pair, the default false `verify_peer`/`allow_self_signed` toggles are harmless, but a passphrase, CA, ciphers, crypto-method bitmask, enabled peer verification, or enabled self-signed mode is rejected as an unusable TLS-only configuration. Unknown TLS keys and raw attempts to bypass protocol/TLS ownership fail clearly instead of being ignored or overwritten. Invalid enabled-server configuration throws a specific `InvalidArgumentException` before Swoole binds ports; client-only installation does not fail merely because the default server route stub has not been published.

Client endpoints remain application concerns and belong in `config/services.php`; do not add a named client registry under `grpc.clients`.

### 9.2 Package composer manifest

```json
{
    "name": "hypervel/grpc",
    "type": "library",
    "description": "The Hypervel gRPC package.",
    "license": "MIT",
    "keywords": ["php", "grpc", "swoole", "hypervel"],
    "autoload": {
        "psr-4": {
            "Hypervel\\Grpc\\": "src/"
        }
    },
    "require": {
        "php": "^8.4",
        "composer-runtime-api": "^2.2",
        "ext-swoole": "^6.2",
        "ext-zlib": "*",
        "google/common-protos": "^4.14",
        "google/protobuf": "^5.35",
        "hypervel/console": "^0.4",
        "hypervel/container": "^0.4",
        "hypervel/context": "^0.4",
        "hypervel/contracts": "^0.4",
        "hypervel/coordinator": "^0.4",
        "hypervel/engine": "^0.4",
        "hypervel/foundation": "^0.4",
        "hypervel/http": "^0.4",
        "hypervel/http-server": "^0.4",
        "hypervel/pipeline": "^0.4",
        "hypervel/routing": "^0.4",
        "hypervel/server": "^0.4",
        "hypervel/support": "^0.4",
        "nesbot/carbon": "^3.8.4",
        "symfony/console": "^8.1",
        "symfony/http-foundation": "^8.1",
        "symfony/http-kernel": "^8.1"
    },
    "suggest": {
        "ext-protobuf": "Improves Protocol Buffers serialization performance."
    },
    "config": {
        "sort-packages": true
    },
    "extra": {
        "hypervel": {
            "providers": [
                "Hypervel\\Grpc\\GrpcServiceProvider"
            ]
        },
        "branch-alias": {
            "dev-main": "0.4-dev"
        }
    }
}
```

Copy the standard authors/support blocks from adjacent Hypervel packages. Composer repository metadata checked from this worktree on 2026-07-19 reports `google/protobuf` 5.35.1 and `google/common-protos` 4.14.1 as the latest stable releases; `google/common-protos` itself permits protobuf `^4.31||^5.0`. During implementation use Composer to add the direct root requirements and retain the deliberate current-major constraints shown above. `google/common-protos` supplies `Google\Rpc\Status`; neither dependency may remain merely transitive.

Root `composer.json` changes:

- add direct `ext-zlib`, `google/protobuf`, and `google/common-protos` requirements with Composer commands;
- add `"Hypervel\\Grpc\\": "src/grpc/src/"` to root PSR-4 autoload;
- add `"hypervel/grpc": "self.version"` to `replace`;
- add the provider to root `extra.hypervel` metadata.

Do not add a redundant fixture autoload mapping: root `autoload-dev` already maps `Hypervel\Tests\` to `tests/`, which exactly covers the generated fixture namespace below.

Also add `hypervel/server` to `src/reverb/composer.json`: Reverb already imports `Hypervel\Server\Event` and `ServerInterface`, and the new shared `TlsOptions`; leaving that package transitive would violate the same direct-dependency rule applied to gRPC. `nesbot/carbon` is direct in gRPC because `ServerCallContext` exposes `CarbonImmutable` in its public signature.

The final split-package audit also keeps `hypervel/foundation` direct because the package configuration and provider call its `base_path()` / `config_path()` helpers, adds `hypervel/pipeline` for the gRPC pipeline subclass, and adds `symfony/http-kernel` for the router's `NotFoundHttpException` boundary. Remove the initially planned `ext-mbstring` entry because no production gRPC code uses it. `StatusCodec` validates percent-encoded hex pairs with PCRE instead of leaving a direct `ctype_xdigit()` call dependent on another package's transitive ctype polyfill.

Do not add the optional package to `DefaultProviders`; Composer package discovery is the correct path. Root metadata mirrors the split-package manifest because the components monorepo replaces it during development.

### 9.3 Install command and publish groups

Register `grpc:install` lazily with `#[AsCommand]`. It:

1. publishes `config/grpc.php` under tags `grpc` and `grpc-config`;
2. copies the package route stub to `routes/grpc.php` if absent;
3. honors `--force` for both files;
4. prints the one required activation step, `GRPC_SERVER_ENABLED=true`;
5. does not mutate `bootstrap/app.php`, `.env`, or application service bindings.

The provider also publishes the route stub under `grpc-routes`, allowing normal `vendor:publish` use. Client-only users need not run the command.

The shipped route stub is the exact standard-health block from section 4.3, with no example application namespace that would fail after installation. Its generated health messages and default provider are package files, so enabling a freshly installed server produces a useful probe endpoint without requiring application scaffolding. `--force` restores this current stub, including any canonical health-protocol additions; without `--force`, user-owned route changes are never overwritten.

## 10. Documentation

### 10.1 Package README

Create `src/grpc/README.md` with:

- `Ported from: https://github.com/hyperf/hyperf` and the three source component names;
- installation and optional server activation;
- a small route/service/client example;
- built-in `grpc.health.v1` `Check`/`List`, custom `HealthStatusProvider` binding, and the explicit `Watch` `UNIMPLEMENTED` platform boundary;
- generated-style stub parent replacement;
- supported call-shape matrix (server vs client);
- explicit error/timeout/compression/retry behavior;
- platform limitations: no per-stream client cancellation, no server-side client/bidi streaming, Swoole's loss of independently repeated inbound request/response fields and inbound `:scheme` (including the resulting duplicate/size-observability limit), reset-stream failures lack the peer's native HTTP/2 error code even though the affected call is detected and isolated, and a non-final initial HEADERS block followed immediately by final trailers without DATA is indistinguishable from Trailers-Only for opt-in retry commitment;
- `Differences From Hyperf`: single package, no generic RPC/governance layer, standard paths, message-or-throw client, explicit retries, engine abstraction, isolated router.

Do not describe absent concepts as future work or retain historical implementation commentary after code lands.

### 10.2 Full framework documentation

Add `src/boost/docs/grpc.md` in the existing Boost documentation voice and add `grpc.md` in alphabetical position to `src/boost/docs-ported.md` so the documentation package includes it. Cover:

- installing protobuf tooling and generating PHP message classes;
- package install/configuration/TLS;
- dedicated port and isolation model;
- `routes/grpc.php`, service groups, unary/server-stream routes, route middleware, and the rule that response metadata is attached through typed gRPC response/error APIs rather than mutable HTTP headers;
- standard health probing, the default whole-server `SERVING` provider, application readiness-provider bindings, and why this runtime honestly returns `UNIMPLEMENTED` from `Watch`;
- service method injection and `ServerCallContext`;
- direct/metadata-bearing/streaming responses;
- queued server-stream initial metadata behavior for first-message versus empty/pre-yield Trailers-Only completion;
- expected/rich errors and status codes;
- pool-independent rich-detail consumption by checking an Any type URL and merging its value into the expected message, including the protobuf-PHP descriptor precondition on `unpack()`/`is()`;
- all four client call shapes with typed examples;
- singleton client bindings in a service provider;
- every constructor/per-call option;
- deadlines, explicit retry commitment, compression, size limits, and metadata including binary values;
- client/server interoperability and platform limitations;
- testing services and clients.

No Laravel-difference document entry is needed because Laravel has no gRPC API. Do not add a `docs/todo.md` item for platform APIs Swoole does not expose.

### 10.3 Source comments and generated fixtures

Source comments explain only non-obvious live invariants: why send/write is serialized, why locally terminal streams retire their connection, why retained iterable production is used for write-failure feedback, and why trailer streaming retains the final chunk. Do not copy Hyperf headers, leave commented alternatives, mention removed classes at their old insertion points, or add TODOs for unsupported platform capabilities.

Generated protobuf files may retain generator-owned `Generated by protoc` comments. Keep the production health proto plus its exact command in `resources/proto/README.md`, and keep the test-service proto/command in its fixture README, so both output trees are reproducible. The vendored health proto retains the upstream license/header, records the pinned source revision, and ships the unmodified Apache-2.0 license text beside it; do not copy generated grpc-php runtime classes or the native extension.

Because `protoc` includes the complete PHP namespace in its output path while the package PSR-4 root already represents `Hypervel\Grpc`, the production README uses an isolated output directory and copies only the verified namespace subtree:

```bash
grpc_health_out="$(mktemp -d)"
trap 'rm -rf "$grpc_health_out"' EXIT

protoc \
  --proto_path=src/grpc/resources/proto \
  --php_out="$grpc_health_out" \
  src/grpc/resources/proto/grpc/health/v1/health.proto

rsync --archive --delete \
  "$grpc_health_out/Hypervel/Grpc/Health/V1/" \
  src/grpc/src/Health/V1/

./vendor/bin/php-cs-fixer fix \
  --config=.php-cs-fixer.php \
  src/grpc/src/Health/V1
```

Run the command from the components repository root with `protoc` v35.1, matching the selected protobuf 5.35 generation/runtime line. The canonical checked-in PHP form is pinned protoc output followed by the Composer-locked repository fixer; `DO NOT EDIT` forbids hand edits rather than that deterministic post-processing. Require a clean generated-tree diff on a second complete protoc/copy/fixer run. The fixture workflow applies the same fixer to exactly its three generated PHP destinations and not to authored fixture support. Any protoc, Go plugin, or fixer update must also pass the full gRPC unit, PHP integration, plaintext/TLS grpc-go client, and repository-wide verification suites because reproducible output alone does not prove that risky formatting transforms preserved runtime behavior. `HealthClient`, `HealthService`, the provider contract/default, and `ServingStatus` are authored package APIs and are not placed in the generated directory.

The official protoc 35.1 PHP generator emits `GPBUtil::checkEnum($value, EnumClass::class)` while the matching `google/protobuf` 5.35.1 userland runtime declares the method with one parameter; the same mismatch exists in that upstream release's own generated/runtime tree and PHP intentionally accepts the extra argument to a user-defined method. Keep generated health output byte-for-byte reproducible and retain one exact PHPStan `arguments.count` exception scoped to `HealthCheckResponse.php`; do not hand-edit generated output, exclude the generated directory, or suppress the identifier globally.

## 11. Testing plan

### 11.1 Protobuf fixtures

Create one purpose-built `test_service.proto` that covers every call shape and error feature, rather than porting unrelated Hyperf fixture trees:

```proto
syntax = "proto3";

package hypervel.grpc.testing;

option php_namespace = "Hypervel\\Tests\\Grpc\\Fixtures";
option php_metadata_namespace = "Hypervel\\Tests\\Grpc\\Fixtures\\Metadata";

service TestService {
  rpc Unary (TestRequest) returns (TestReply);
  rpc ServerStream (TestRequest) returns (stream TestReply);
  rpc ClientStream (stream TestRequest) returns (TestReply);
  rpc BidiStream (stream TestRequest) returns (stream TestReply);
}

message TestRequest {
  string value = 1;
}

message TestReply {
  string value = 1;
}
```

Check in generated PHP message/metadata classes, a generated-style PHP client extending Hypervel `BaseClient`, the Go generated peer files used for independent interop, and the source proto. Do not put fixture classes in the production package namespace. Keep generated fixture proto, message, and service names from ending in `Test`: PHPUnit recursively discovers `*Test.php`, so protoc output with that suffix would be mistaken for a test class. The descriptive `test_service.proto` basename generates `Metadata/TestService.php` and avoids that collision without weakening suite discovery or editing generated code.

### 11.2 Wire and value-object unit tests (`tests/Grpc/`)

| Test | Required cases |
|---|---|
| `StatusCodeTest` | exact values 0–16; invalid code handling |
| `StatusTest` | OK predicate; details forbidden for OK; details code/message validation; serialize-copy isolation from input and accessor mutations |
| `MediaTypeTest` | base/proto recognized as protobuf; json/custom recognized but unsupported; parameters; case insensitivity; loose prefix/empty subtype rejection |
| `ServiceMethodTest` | fully qualified and single-segment service names; exact case-preserving path; optional one leading slash; independent part construction; empty/dotted/invalid identifiers; extra slash; placeholder/query/fragment rejection |
| `MetadataTest` | lowercase normalization; key/value/edge-whitespace validation; exact shared protocol/HTTP-owned-key rejection without blocking `authorization`; empty string; repeated values; binary raw values; immutable `with` append/order and zero-value rejection; `without`; `merge` append/order; count/iteration |
| `MetadataCodecTest` | unpadded binary emission; padded/unpadded receipt; comma-separated binary values; malformed binary rejection; ASCII is not percent encoded; inbound surrounding-space trimming/invalid-field discard; pseudo/shared-owned filtering; exact outbound and transport-observable inbound 8 KiB accounting including reconstructed/transport-created fields and zero/body-derived content lengths; conservative zero-DATA merged-header event accounting; repeated outbound combination; explicit native-loss fixtures for overwritten repetitions and discarded inbound scheme |
| `FrameEncoderTest` | exact five-byte header; zero-length payload; identity/gzip; send/wire size limits |
| `FrameDecoderTest` | byte-by-byte frame; split header/body; many tiny frames are yielded without an intermediate list; consumer exhaustion/offset compaction; invalid flag; length overflow/limit; compressed without encoding; corrupt/truncated/trailing-data gzip; incremental high-ratio compression-bomb limit; partial `finish()` |
| `MessageSerializerTest` | generated message round trip; exact conventional missing `decode` method pair; a different callable static method is invoked; non-callable array and return-type validation; parse exception wrapping; null rejection by type |
| `TimeoutTest` | all six units; eight-digit boundary transitions; upward rounding; malformed rejection; zero and huge valid wire durations |
| `DeadlineTest` | absent/local/peer construction; invalid local values; overflow rejection; huge peer saturation; zero immediately expired; manual monotonic clock; encoded remainder rounds upward; no header after expiry; absolute deadline exposure |
| `StatusCodecTest` | OK/error trailers; exact visible-byte/percent/control/Unicode message encoding with uppercase escapes; outbound invalid UTF-8 replacement; malformed percent retained; rich details with padded/unpadded base64; malformed or array/comma-combined rich details ignored; rich-code mismatch → Internal; malformed/undefined status → Unknown; every HTTP fallback; retained initial HTTP status; 200-without-status Unknown; trailers-only recognition; status on non-final initial headers rejected |
| `RetryPolicyTest` | constructor validation; duplicate/OK code rejection |
| `RetryBackoffTest` | seeded capped exponential sequence; deterministic ±20% jitter bounds; representable non-negative server pushback overrides/resets sequence; negative/malformed/array-valued/comma-combined/overflowing pushback stops; deadline cap |
| `EndpointTest` | http/https/scheme-less; defaults; explicit TLS; IPv4/bracketed IPv6; normalized authority/peer; invalid scheme/host/port/TLS conflicts; malformed IPv6; userinfo; non-root path; query; fragment; unsupported resolver target |

### 11.3 Client unit tests

Use mocked engine contracts and real coroutine channels; never mock raw Swoole in gRPC package tests.

| Test | Required cases |
|---|---|
| `RequestTest` | exact standard path/headers/user-agent/host-to-authority; pipeline vs `usePipelineRead` matrix for all call shapes; timeout/compression/metadata/previous-attempt headers; complete outbound header-size limit |
| `BaseClientTest` | protected-method call types; generated-style compatibility; exact path normalization/identifier rejection; constructor/per-call option normalization including seconds-vs-ext-grpc timeout documentation; raw connect-timeout rejection; positive-finite write-timeout baseline precedence (`write_timeout`, `timeout`, 60-second default); deadline-independent header accounting with a reserved maximum-width timeout; fresh timeout finalization after delayed lazy connect; unknown-option failures; round-robin across different client instances; retiring-slot replacement/set deregistration; close covers active and retiring connections; no destructor; post-close failure |
| `ConnectionTest` | lazy factory settings bounded by the current deadline; fresh request finalization after connect; whole-send/write serialization under concurrent coroutines; mixed-deadline send-semaphore contention and early-wake-safe deadline acquisition; per-operation native timeout publication for deadline/no-deadline sends and writes; deadline-vs-connection failure classification; healthy connection reuse when expiry occurs before native send; receiver-wins/sender-wins pending-state registration races and mismatched/unsolicited IDs; test-owned sender coroutines are joined after channel synchronization; no orphan-event buffer; incremental routing by stream ID; one-second idle polling; receiver-side expiry/retirement of an unobserved deadline; a queued final event can complete the receiver during synchronous startup without retaining its ID; swallowed RST detected through `isStreamOpen()` and isolated to one call; no implicit keepalive pings; non-timeout connection failure fan-out; retiring connection preserves healthy calls, accepts no new calls, closes when only tombstones remain, and releases abandoned buffers; external close cancels a live receiver and uses an unbounded join, including through yielding deferred cleanup, before native socket close without leaking its cancellation/engine error; receiver-owned retirement skips self-cancellation/join; coroutine ID ownership is cleared on receiver exit; slot replacement; receiver stops/restarts with active streams; deterministic owned-channel close; no implicit replay |
| `StreamStateTest` | initial headers vs trailers-only metadata placement; frame feeds across response events; declared-wire and gzip-decompressed receive limits become call-local ResourceExhausted without escaping; response compression including unknown response encoding rejection; non-gRPC content type discards bounded body and uses HTTP fallback; recognized unsupported `application/grpc+json`/custom subtypes fail before protobuf framing; valid gRPC status overrides non-200 HTTP; all registered waiters wake without receiver blocking/missed registration race; waiter cleanup on timeout/error; message-count and queued-byte caps → local ResourceExhausted/retirement; byte count decrements on consume; abandonment releases payload/decoder memory; final status/trailers; terminal error wakeup |
| `UnaryCallTest` | message-or-throw; initial connect/send failure is stored and observed from the call; zero/multiple response messages; repeated/concurrent wait caches one deserialization/result/exception; metadata/status/trailers; concurrent observers create only one retry attempt; local deadline retires an un-cancellable stream; retryable trailers-only retry with incrementing previous-attempt header; pushback override/stop; no retry after surfaced initial metadata; intentionally merged header-plus-zero-DATA-trailer event follows the documented Trailers-Only rule; no retry on uncertain send; total deadline across backoff |
| `ServerStreamingCallTest` | ordered read/responses; empty stream; final non-OK throw; retry before commit; never retry after first headers/message; second simultaneous reader rejected; metadata observer concurrent with read; slow-consumer cap |
| `ClientStreamingCallTest` | open headers; concurrent writes serialize; idempotent half-close; write/half-close race; write-after-close; wait auto-close and caches one concurrent result; unary response/error; constructor retry default ignored and per-call retry key rejected |
| `BidiStreamingCallTest` | one reader/one writer independently interleaved; concurrent writes serialize; second reader rejected; half-close while reads continue; final status/error; constructor retry default ignored and per-call retry key rejected |
| `HealthClientTest` | exact canonical `Check`/`List`/`Watch` paths, request/response types, metadata/options forwarding, and unary vs server-streaming call shapes |
| `ClientCoroutineIsolationTest` | many clients/calls in parallel with forced yields; no shared connection index, state, metadata, deadlines, or stream events |

Add a deterministic mock response sequence for the exact Swoole `usePipelineRead` shape observed in the runtime spike: initial headers+DATA with `pipeline=true`, middle DATA, then final trailer headers with `pipeline=false`.

### 11.4 Server unit tests

| Test | Required cases |
|---|---|
| `GrpcRouteRegistrarTest` | unary/serverStream/service path registration; identifier/path rejection; case sensitivity; ordinary Route fluency; immutable pending middleware/without/name applied to one RPC and whole service; pending API exposes no HTTP router surface; no inherited HTTP verbs through the facade; nested service rejection; group-stack cleanup after a throwing service closure |
| `GrpcRouterTest` | app-router isolation before and after compilation; global `routes` binding/URL generator remain on the app collection; boot-time action validation for missing action, zero/multiple/nullable/union/intersection/variadic/by-reference/abstract protobuf parameters, and invalid/multiple contexts; validated marker survives compilation; internal middleware cannot be excluded or bypassed by `middleware.disable`; user middleware is disabled by that flag; alias/group/priority sync after late application-provider boot; global HTTP middleware excluded; pipeline/response conversion |
| `HandleCallTest` | metadata/encoding/accept-encoding/timeout parsing; exactly-one frame; validated marker consumption; argument seeding in every signature order; existing container dependency resolution; size limits; timer cancellation/clear; timer-registration failure still releases coroutine-local context; unrelated cancellation rethrow; CPU-bound post-return deadline |
| `ServerCallContextTest` | metadata/service/method/peer/previous attempts; wall-clock deadline projection; monotonic remainder; coroutine store isolation; missing-context binding failure |
| `GrpcResponseTest` | immutable factories/metadata; unary-vs-stream shape |
| `ResponseFactoryTest` | direct/wrapped unary; direct/wrapped stream; exactly one-frame priming even with queued initial metadata; initial metadata is emitted with the first frame; response compression negotiation and omission of unused `grpc-encoding` on empty/error Trailers-Only responses; empty/pre-yield completion becomes one-block Trailers-Only with no native trailers and folds queued initial/trailing metadata into ordered trailing values; every invalid return/yield; message size; definitive finalization-time initial/trailer size enforcement including exact unary/zero content length and conservative streamed-first-frame reservation; server-only 127-byte native metadata-name cap on initial and dynamic trailing metadata; fixed snapshot accepts an untouched response; post-factory status/body/fixed/custom-header mutations are reported and become a non-recursive compact Internal fallback; compact ResourceExhausted fallback; fixed response headers/trailer names |
| `ExceptionMapperTest` | expected RPC not reported; rich details; custom error trailers; retry-after rounding/zero and no-retry pushback; oversized error trailers compact to ResourceExhausted; generic → reported Unknown; protocol mappings; wrong return → reported Internal; no debug leakage; unknown method Unimplemented |
| `GrpcStreamedResponseTest` | extends `IterableStreamedResponse`; cached primed frame followed by same iterator without rewind/duplication; lazy subsequent iteration through `streamTo()`; ordered frames; writer false/throw stops and releases the producer without being mapped as a service failure; custom metadata; mid-stream RpcException with a newly discovered trailer name; mid-stream generic exception reporting; deadline cancellation while yielding → DeadlineExceeded; unrelated/transport cancellation rethrown; CPU-bound post-yield deadline; completion cleanup exactly once |
| `HealthServiceTest` | default empty-service `SERVING`; named service `NotFound`; custom provider `Unknown`/`Serving`/`NotServing`; `List` exact map; generated enum parity for the three provider values plus generated-only `SERVICE_UNKNOWN`; `Watch` trailers-only `Unimplemented`; provider output is not cached or copied into package static state |
| `ServerTest` | bootstrap/compile; worker coordinator; request/context bridge; raw-protocol/method/media-type including recognized unsupported `+json`/custom subtypes/transport-observable-header-size/TE preflight and 505/405/415/ResourceExhausted/Internal; exact configured-scheme/path/query/authority reconstruction; raw query/missing-or-doubled-leading-slash/trailing-slash/extra-segment/invalid and unmatched-valid gRPC paths → Unimplemented before HTTP normalization; isolated dispatch; mandatory final-emission validation before bridge send; unexpected failure; already-started response failure; lifecycle cleanup after bridge exception |
| `GrpcServiceProviderTest` | defaults/client-only no port; enabled port shape; non-empty name; default/replaceable health-provider binding; unknown TLS keys and provider-owned raw protocol/TLS settings rejected; valid raw settings preserved; TLS; config/routes publishing; router/context bindings; route load ignores global HTTP route cache; the appended listener participates in final `ServerConfig` uniqueness validation |
| `InstallCommandTest` | config/route creation, exact canonical health routes, existing preservation, force replacement, output, no bootstrap/env mutation |

Port every relevant Hyperf test assertion into the new behavior suites, rather than mechanically preserving old classes. Maintain an implementation checklist mapping Hyperf's `BaseClientTest`, `RequestTest`, `RouteGuideClientTest`, `GoUserServiceTest`, `CoreMiddlewareTest`, and `GrpcExceptionHandlerTest` to their new home. The old Node Route Guide mock is replaced by the one typed Go peer that covers all four call shapes; it contributes no unique behavior requiring a second fixture ecosystem. Tests whose only subject is a dropped generic-RPC adapter are not copied because the production concept does not exist.

### 11.5 Framework-package tests

`tests/Engine/` and `tests/Integration/Engine/Http2ClientTest.php`:

- factory settings reach construction before connect;
- independent request pipeline/read flags reach native request properties;
- response end state survives transformation;
- a receive poll timeout returns `null`, while non-timeout native failures retain their exception code/message;
- native stream existence is exposed only through normalized `isStreamOpen()`;
- write false becomes `HttpClientException`, and removed `set()`/`ping()` methods are absent from both the contract and concrete client;
- native construction/send/recv/write/stream-existence/active-close exceptions are wrapped as `HttpClientException` with their previous exception retained;
- optional per-operation send/write timeouts publish native `write_timeout`, reject non-positive/non-finite values, leave settings untouched when absent, and normalize native setting failure;
- void/idempotent close behavior.

`tests/Support/Facades/GrpcTest.php` asserts the support-owned facade resolves the package registrar, forwards only the documented registration surface, and clears through the ordinary facade test-state cleanup. Static analysis of the split `hypervel/support` package must remain green without adding `hypervel/grpc` as a hard dependency; the lazy accessor is the same established optional-package pattern as `Jwt`.

`tests/HttpServer/ResponseBridgeTest.php`:

- trailer announcement/order for normal responses;
- invalid/forbidden/over-127-byte announced and final trailer names fail at the bridge boundary;
- repeated Symfony response-header values reach Swoole in one array-valued call instead of overwriting each other;
- HEAD/no-body sends only known trailer announcement headers without invoking content/final-trailer producers, plus pre-send rejection for incompatible binary responses;
- zero/one/multiple streamed chunks with lookahead;
- retained `IterableStreamedResponse` zero/one/multiple chunks without output-buffer use; ordinary false writes stop cleanly, while trailer-bearing false writes terminate the producer and throw without trailers/end;
- final chunk passed to `end()`;
- trailers evaluated after callback;
- valid trailer fields discovered only during the callback are emitted even though absent from the initial `Trailer` announcement;
- false status/header/cookie, fixed-response/HEAD end, sendfile, and trailer-bearing write/trailer/end returns throw;
- false trailer-bearing fallback writes are reported only after output-buffer restoration and never leak the rejected chunk to process output;
- a producer exception after a false write does not hide the earlier transport failure, while a producer-only exception still propagates unchanged after restoration;
- output buffer returns to original level on response or transport exceptions;
- existing non-trailer iterable/callback clean disconnect, binary, HEAD, already-direct-streamed finalization, cookies, and headers behavior remains green.

`tests/Routing/`:

- both dispatch paths use the overridable pipeline factory and middleware-selection hook;
- `middleware.disable` still produces an empty list in the base router while an override can retain required protocol middleware;
- primary route replacement/compilation still invalidates/rebinds global route state, while an `ownsGlobalRouteState() === false` router does neither;
- isolated compilation preserves already warmed application reflection/middleware-name caches, while primary replacement retains the existing global invalidation;
- group frames are popped after throwing closures/route files and subsequent routes receive only their intended attributes;
- route registration, signature reflection, warmup, and dispatch cover closures, controller arrays/strings, invokable class strings, and invokable objects;
- top-level and nested object-method actions normalize to closures, top-level and nested class-string method arrays normalize to controller actions, and named-function/static-method strings fail clearly during registration;
- default factory still returns the exception-rendering routing pipeline.

`tests/Server/` and `tests/Reverb/`:

- stable multi-port ordering with original-index tie breaking;
- complete post-provider listener-name validation rejects empty/duplicate names before any bind;
- main app HTTP port remains first after gRPC append;
- WebSocket priority remains first;
- `TlsOptions` exact mapping and false/null handling;
- compiling `ReverbRouter` leaves the already resolved global route collection and URL generator routes untouched;
- Reverb config and generated Swoole settings remain unchanged after extraction.

`tests/Coordinator/`, `tests/Server/`, and `tests/Foundation/Bootstrap/` additionally prove the implementation-time exception fixes:

- a coordinator that returns early once cannot make `Timer::after()` fire before its monotonic interval, and the timer still completes within a bounded wait;
- real-coordinator clear/resume interleavings remain correct across repeated interval waits;
- escaped ordinary response-callback failures are reported and best-effort complete a writable native response;
- escaped cancellation is not reported and best-effort completes a writable response;
- an unwritable response is left alone, and reporter/writability/end failures do not escape the native callback boundary;
- non-console global exception handling reports without rendering or calling an unsupported SAPI response method;
- a `Throwable` raised by the reporter does not create a second uncaught exception at the global backstop.

### 11.6 Real PHP loopback integration

Add a `grpc` group to `bin/test-servers.sh` and a minimal bootstrapped Hypervel gRPC server at `tests/Integration/Grpc/server.php`. Reserve and document this matrix so processes never race another integration group:

| Port | Peer |
|---:|---|
| 19520 | Hypervel server, plaintext |
| 19521 | grpc-go server, plaintext |
| 19522 | Hypervel server, test TLS |
| 19523 | grpc-go server, test TLS |

The script always runs `go build` into ignored `.tmp/` before starting the group (the Go build cache keeps this cheap and prevents a stale binary after source/proto changes), starts all four as separate `setsid` process groups, and records them in the existing cleanup array. Start each Hypervel peer and wait for it before starting the next because application bootstrap temp paths are shared and can race, matching the existing Reverb safeguard; Go peers can then start independently. Add `wait_for_tcp()` for these ports because a pure gRPC listener has no ordinary `/up` route; retain HTTP readiness for existing groups. Use the existing `InteractsWithServer` trait with the appropriate port properties and do not add a duplicate foundation trait. Add `grpc` to the script's documented group parser and default all-groups list.

PHP client → PHP server tests:

- canonical health `Check`/`List` over the installed default routes, application provider replacement, unknown-service `NotFound`, and `Watch` `Unimplemented`;
- unary and server stream success/empty/error;
- initial/trailing/binary metadata;
- rich error details;
- deadlines both before dispatch and during yielding service work;
- identity/gzip both directions;
- server receive enforcement with an oversized declared frame, server send enforcement through an oversized service response, client receive enforcement plus successful same-client connection replacement, and metadata enforcement; client send rejection remains a unit assertion because it must occur synchronously before any transport is created;
- 100+ concurrent calls over one HTTP/2 connection with forced response reordering;
- multiple client connections round-robin;
- connection loss wakes all streams and a later new call reconnects;
- streaming partial success followed by non-OK trailers;
- pre-yield server-stream failure/empty success arrive as one-block Trailers-Only, and a retryable pre-yield failure can be retried before commitment;
- queued initial metadata does not create a fictitious commitment before a server stream's first message; it accompanies the first frame, or becomes trailing metadata on an empty/pre-yield Trailers-Only completion;
- exact response-trailer preservation through shared `ResponseBridge`.

A raw HTTP/1.1 request verifies the dedicated listener returns 505 without a gRPC content type. Raw engine HTTP/2 requests test malformed frame flags/lengths, multiple unary frames, invalid content type, non-POST method, missing/invalid `te`, unknown path, bad timeout, and unsupported compression. HTTP responses without `grpc-status` are covered exhaustively with engine-contract client sequences in `StatusCodecTest`/call tests; do not pretend a conforming Hypervel or grpc-go server can produce that intermediary-only condition.

### 11.7 Independent interoperability

Loopback can hide symmetric encoder/decoder mistakes. Add an official grpc-go peer under `tests/Integration/Grpc/Interop/` using the same proto:

- a Go server implements all four methods, metadata echo, gzip, delays, standard/rich errors, and controlled connection shutdown;
- the Go server includes a counted unary method that explicitly sends initial metadata and then returns a retryable status without a message; the PHP test proves Swoole presents the merged final event and that opt-in retry follows the documented indistinguishable Trailers-Only behavior;
- a Go client program calls the Hypervel server's unary/server-stream methods and asserts messages, metadata, rich errors, deadlines, and gzip;
- the official grpc-go health client calls Hypervel `Check` and `List`, observes `NotFound` for an unknown name, and observes the specified non-retryable `Unimplemented` result from `Watch`; Hypervel's `HealthClient::watch()` separately consumes a real grpc-go health watch so the client-side server-streaming method is fully interoperable;
- PHP integration tests target the Go server for all four client call shapes and repeat the protocol/error/concurrency cases that the peer supports;
- TLS variants use checked-in test-only CA/server certificate fixtures and verify both successful hostname validation and expected rejection.

Create `.github/workflows/grpc.yml` rather than overloading the engine workflow. Match the repository's PHP 8.4/Swoole container and pinned action versions, install Composer dependencies, use `actions/setup-go` with the version declared by the committed `go.mod`, build the interop binaries, start all four peers through the `grpc` test-server group, run `tests/Integration/Grpc` with `TEST_SERVER_HOST`, then run the Go client against both Hypervel ports. Pin Go module dependencies through committed `go.mod`/`go.sum`; generated Go files remain reproducible from the proto and fixture README command.

Do not download `grpcurl` when the checked-in Go client already gives typed, assertion-rich independent coverage.

### 11.8 Verification sequence

Follow repository order while implementing:

1. run each new/changed test file immediately after writing its paired production file;
2. run focused suites:

   ```bash
   ./vendor/bin/phpunit --no-progress tests/Grpc
   ./vendor/bin/phpunit --no-progress tests/Engine tests/HttpServer tests/Routing tests/Server tests/Reverb
   ```

3. start integration peers and run:

   ```bash
   TEST_SERVER_HOST=127.0.0.1 ./vendor/bin/phpunit --no-progress tests/Integration/Engine tests/Integration/Grpc
   ```

4. run static analysis for the whole repository:

   ```bash
   composer analyse
   ```

5. apply formatting and inspect the diff:

   ```bash
   ./vendor/bin/php-cs-fixer fix
   git diff --check
   ```

6. run the complete suite last:

   ```bash
   composer test:parallel
   composer test:testbench
   composer test:dogfood
   ```

Use the worktree's own Composer installation before testing; do not reuse another worktree's `vendor` symlink in the finished change.

## 12. Implementation order

This is dependency order, not a reduced feature sequence. Every listed capability is part of the completed change.

1. Add direct protobuf dependencies and package/root Composer wiring; create package skeleton/config/provider shell.
2. Implement and test public value types, exceptions, message/frame/metadata/timeout/status codecs.
3. Complete the consumed engine HTTP/2 contracts and tests.
4. Implement endpoint/request, `StreamState`, serialized `Connection`, call objects, retry, and abstract `BaseClient`; finish unit tests.
5. Complete the routing hooks/isolation/callable/group fixes and extend the shared iterable response emission with trailers, including Swoole lookahead and write-failure regressions.
6. Fix stable server ordering, extract `TlsOptions`, make coordinator timeouts monotonic, and add the shared native response/global exception boundaries; update their framework tests.
7. Implement the support-owned facade/router, call middleware/context, response types/factory/exception mapping, server provider/handler, canonical health classes/service/client/provider, and install command.
8. Add protobuf fixtures, full PHP loopback server/tests, Go peer/client including standard health interop, test-server group, and workflow.
9. Write README and Boost docs from the now-final API; remove any implementation notes rendered stale by the actual code.
10. Run the full verification sequence and perform a final dead-code/dependency/documentation audit.

At each step, if runtime behavior contradicts an invariant here, investigate the exact source/runtime behavior and update the design rather than adding a compatibility branch or leaving a TODO.

## 13. Final cleanup and acceptance checklist

Before considering the implementation complete:

- [ ] One `hypervel/grpc` package exists; no `grpc-client`/`grpc-server` package manifests.
- [ ] No dependency or import from Hypervel/Hyperf generic RPC, governance, discovery, or load-balancing layers exists.
- [ ] No production gRPC class imports raw Swoole HTTP/2 client/request/response types; only the server callback boundary imports Swoole HTTP request/response.
- [ ] No channel/client facade/manager/proxy/transporter/packer/normalizer/registry abstraction exists.
- [ ] Standard service paths, headers, frames, status trailers, timeout units, metadata, compression, and fallback mapping pass independent interop.
- [ ] Client supports all four call shapes; server exposes exactly unary and server streaming.
- [ ] The canonical `grpc.health.v1` schema, `Check`/`List` service, client, replaceable worker-safe provider contract, and honest `Watch` `Unimplemented` behavior pass grpc-go interop; no mutable per-worker health registry exists.
- [ ] Unary is message-or-throw; call I/O is non-fluent; no tuple/response compatibility wrapper exists.
- [ ] No public `cancel()` exists and no connection-wide close is mislabeled as call cancellation.
- [ ] Send/write operations are serialized internally; `send_yield` is absent.
- [ ] Every channel/socket/timer/context has deterministic ownership and cleanup; no destructor performs native work.
- [ ] A timed coordinator wait cannot fire early relative to its monotonic interval, and escaped response-callback/global exceptions cannot enter unsupported SAPI response emission.
- [ ] Slow consumers cannot block the shared receive loop or grow memory without bound.
- [ ] Deadlines propagate and are enforced across service execution, streaming, retries, waits, and backoff.
- [ ] Retries are opt-in and never occur after commitment or uncertain send.
- [ ] Server streaming emits final trailers with the verified one-chunk lookahead path.
- [ ] gRPC streaming extends the shared `IterableStreamedResponse`, so a native write failure stops/releases the producer immediately instead of draining an unobservable echo stream.
- [ ] The isolated router uses normal Route fluency, middleware, controller/closure dispatch, and dependency resolution without copied routing internals.
- [ ] The application HTTP port remains the main HTTP server after the gRPC port is appended.
- [ ] TLS settings are owned once in `hypervel/server` and Reverb behavior remains unchanged.
- [ ] Package/root manifests declare every direct dependency, split-package replacement, and provider discovery entry in the locations the repository actually uses; no unnecessary global facade alias exists.
- [ ] The only first-party gRPC facade is `Hypervel\Support\Facades\Grpc`, following the established optional-package convention without a hard support-to-gRPC dependency.
- [ ] Client-only installation opens no server port by default.
- [ ] No gRPC-specific static cleanup hook exists because no gRPC static state exists.
- [ ] Hyperf defects listed in section 2.4 each have a regression test or are structurally impossible.
- [ ] Every retained Hyperf behavioral test has a mapped new test; generic-RPC-only tests have no corresponding production concept.
- [ ] PHP↔PHP, PHP client↔grpc-go server, and grpc-go client↔PHP server suites pass.
- [ ] README/docs describe only shipped behavior and contain no promises for absent platform APIs.
- [ ] No stale code, unused class, unused config key, unused engine accessor, commented-out alternative, workaround note, TODO, or dead documentation remains.
- [ ] PHPStan, formatter, focused integration suites, complete parallel tests, Testbench, and dogfood all pass.

The final diff should read as one coherent Hypervel design: Laravel-shaped application ergonomics at the edge, protocol-correct gRPC semantics in the package, and Swoole-specific mechanics confined to their existing framework boundaries.
