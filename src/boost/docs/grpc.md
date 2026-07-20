# gRPC

- [Introduction](#introduction)
- [Installation](#installation)
    - [Protocol Buffer Tooling](#protocol-buffer-tooling)
    - [Installing the Server](#installing-server)
- [Server Configuration](#server-configuration)
    - [TLS](#server-tls)
- [Routing](#routing)
    - [Route Middleware](#route-middleware)
- [Writing Services](#writing-services)
    - [Server Call Context](#server-call-context)
- [Responses](#responses)
    - [Response Metadata](#response-metadata)
    - [Server Streaming](#server-streaming)
- [Errors](#errors)
    - [Rich Error Details](#rich-error-details)
- [Health Checking](#health-checking)
    - [Application Health Providers](#application-health-providers)
- [Clients](#clients)
    - [Generated-Style Clients](#generated-style-clients)
    - [Binding Reusable Clients](#binding-reusable-clients)
    - [Unary Calls](#unary-calls)
    - [Server-Streaming Calls](#server-streaming-calls)
    - [Client-Streaming Calls](#client-streaming-calls)
    - [Bidirectional-Streaming Calls](#bidirectional-streaming-calls)
    - [Call Metadata and Status](#call-metadata-status)
    - [Client Options](#client-options)
    - [Client TLS](#client-tls)
- [Metadata](#metadata)
- [Deadlines](#deadlines)
- [Retries](#retries)
- [Compression](#compression)
- [Resource Limits](#resource-limits)
- [Testing](#testing)
- [Interoperability and Platform Boundaries](#interoperability-platform-boundaries)

<a name="introduction"></a>
## Introduction

Hypervel gRPC provides a coroutine-native HTTP/2 client and server without
requiring the native gRPC PHP extension. Its application API follows Hypervel's
Laravel-style routing, middleware, container, facade, and immutable fluent
value conventions. Its call operations use the standard gRPC PHP vocabulary:
`wait`, `read`, `write`, `writesDone`, and `responses`.

The client supports unary, server-streaming, client-streaming, and
bidirectional-streaming calls to interoperable gRPC servers. The Hypervel
server supports unary and server-streaming methods on a dedicated HTTP/2 port.
Swoole exposes only the completed request body to a PHP request handler, so a
Hypervel server cannot receive client or bidirectional streams incrementally.

The package implements standard gRPC framing, metadata, status, rich error
details, deadlines, identity and gzip compression, message and metadata limits,
and explicit retries. It does not add a generic RPC layer, service discovery,
load balancing, or a named-client manager. Application service bindings and
normal deployment DNS remain the direct tools for those concerns.

<a name="installation"></a>
## Installation

Install the package using Composer:

```shell
composer require hypervel/grpc
```

Package discovery registers its service provider. The server remains disabled,
so installing the package for outbound calls does not open another port.

<a name="protocol-buffer-tooling"></a>
### Protocol Buffer Tooling

Install the official Protocol Buffers compiler for your operating system. The
package itself does not ship a compiler. Given this service definition:

```proto
syntax = "proto3";

package helloworld;

option php_namespace = "App\\Grpc\\Messages";
option php_metadata_namespace = "App\\Grpc\\Messages\\Metadata";

service Greeter {
  rpc SayHello (HelloRequest) returns (HelloReply);
  rpc ListGreetings (HelloRequest) returns (stream HelloReply);
}

message HelloRequest {
  string name = 1;
}

message HelloReply {
  string message = 1;
}
```

generate the PHP message classes with:

```shell
protoc \
  --proto_path=resources/proto \
  --php_out=app/Grpc/Generated \
  resources/proto/helloworld.proto
```

Adjust the output directory to match your application's PSR-4 layout. Generated
message classes use `google/protobuf`; installing `ext-protobuf` is optional but
improves their serialization performance.

The official `grpc_php_plugin` may also generate client method bodies. Hypervel
uses those bodies with a different parent class, as described under
[Generated-Style Clients](#generated-style-clients). Hypervel does not claim the
native extension's `Grpc\` namespace and does not require `ext-grpc`.

<a name="installing-server"></a>
### Installing the Server

Publish the server configuration and canonical health routes:

```shell
php artisan grpc:install
```

The command creates `config/grpc.php` and `routes/grpc.php` without overwriting
existing files. Use `--force` to restore the current package versions. Enable
the listener in your environment:

```ini
GRPC_SERVER_ENABLED=true
```

The gRPC port starts with the normal Hypervel server process:

```shell
php artisan serve
```

<a name="server-configuration"></a>
## Server Configuration

The published `config/grpc.php` file contains one dedicated server definition:

```php
'server' => [
    'enabled' => (bool) env('GRPC_SERVER_ENABLED', false),
    'name' => (string) env('GRPC_SERVER_NAME', 'grpc'),
    'host' => (string) env('GRPC_SERVER_HOST', '0.0.0.0'),
    'port' => (int) env('GRPC_SERVER_PORT', 50051),
    'routes' => base_path('routes/grpc.php'),
    'max_receive_message_size' => (int) env(
        'GRPC_SERVER_MAX_RECEIVE_MESSAGE_SIZE',
        4 * 1024 * 1024,
    ),
    'max_send_message_size' => (int) env(
        'GRPC_SERVER_MAX_SEND_MESSAGE_SIZE',
        4 * 1024 * 1024,
    ),
    'max_metadata_size' => (int) env(
        'GRPC_SERVER_MAX_METADATA_SIZE',
        8 * 1024,
    ),
    'compression' => env('GRPC_SERVER_COMPRESSION'),
    'tls' => [
        // ...
    ],
    'settings' => [],
],
```

`compression` accepts `null`, `identity`, or `gzip`. When gzip is preferred,
the server uses it only when the client advertises support. `settings` accepts
native Swoole listener settings that are not owned by the protocol, message
limits, or first-class TLS configuration. Hypervel rejects conflicting settings
instead of silently overriding them.

The configured server name must be unique among all Hypervel listeners. The
route file must be readable before the server starts.

<a name="server-tls"></a>
### TLS

The listener can terminate TLS directly:

```php
'tls' => [
    'local_cert' => env('GRPC_SERVER_TLS_CERT'),
    'local_pk' => env('GRPC_SERVER_TLS_KEY'),
    'passphrase' => env('GRPC_SERVER_TLS_PASSPHRASE'),
    'verify_peer' => (bool) env('GRPC_SERVER_TLS_VERIFY_PEER', false),
    'allow_self_signed' => (bool) env(
        'GRPC_SERVER_TLS_ALLOW_SELF_SIGNED',
        false,
    ),
    'cafile' => env('GRPC_SERVER_TLS_CLIENT_CA'),
    'ciphers' => env('GRPC_SERVER_TLS_CIPHERS'),
    'crypto_method' => null,
],
```

`local_cert` and `local_pk` must be supplied together. Enable `verify_peer` and
set `cafile` when clients must present a certificate. All configured certificate
and CA files are validated before the listener binds.

TLS may instead terminate at a reverse proxy that forwards native HTTP/2 gRPC
traffic to the plaintext Hypervel listener. gRPC-Web and HTTP/1 compatibility
transports are not implemented by this package.

<a name="routing"></a>
## Routing

The installed `routes/grpc.php` file uses the `Grpc` facade. Group related
methods under their fully qualified protobuf service name:

```php
use App\Grpc\GreeterService;
use Hypervel\Support\Facades\Grpc;

Grpc::service('helloworld.Greeter', function (): void {
    Grpc::unary('SayHello', [GreeterService::class, 'sayHello'])
        ->name('grpc.greeter.say-hello');

    Grpc::serverStream('ListGreetings', [GreeterService::class, 'listGreetings'])
        ->name('grpc.greeter.list-greetings');
});
```

You may also register a fully qualified method directly:

```php
Grpc::unary(
    'helloworld.Greeter/SayHello',
    [GreeterService::class, 'sayHello'],
);
```

Service and method names follow protobuf identifier rules and are
case-sensitive. Hypervel always builds the standard
`/{fully-qualified-service}/{method}` path and always uses HTTP `POST`. URI
parameters, fallback routes, nested service groups, alternate HTTP methods, and
optional method segments are not part of the gRPC routing API.

The gRPC router has its own route collection. Application HTTP routes are not
available on the gRPC listener, and gRPC routes are not available on the
application HTTP listener. Hypervel still uses its normal route matching,
controller and closure dispatch, dependency resolution, middleware ordering,
and route names.

<a name="route-middleware"></a>
### Route Middleware

Attach middleware to one route using ordinary route fluency:

```php
Grpc::unary('helloworld.Greeter/SayHello', [GreeterService::class, 'sayHello'])
    ->middleware(['auth:service', TraceGrpcCall::class])
    ->withoutMiddleware(ThrottleRequests::class);
```

Apply middleware or a name prefix to a complete service:

```php
Grpc::middleware(['auth:service', TraceGrpcCall::class])
    ->name('grpc.greeter.')
    ->service('helloworld.Greeter', function (): void {
        Grpc::unary('SayHello', [GreeterService::class, 'sayHello'])
            ->name('say-hello');
    });
```

Middleware aliases, groups, exclusions, and priority are copied from the
application router after providers finish booting. Global HTTP middleware is
not run on the gRPC port; attach the middleware that each RPC needs explicitly.
Protocol decoding, call context, and deadline enforcement always wrap route
middleware and cannot be excluded.

Middleware must return the service's protobuf message, iterable, or typed gRPC
response. Do not mutate HTTP status, headers, or framed response content.
Attach metadata through `GrpcResponse` and `RpcException`, which lets Hypervel
validate the complete protocol response before emission.

<a name="writing-services"></a>
## Writing Services

Services are ordinary container-resolved classes. They do not extend a gRPC
base class and do not need protocol attributes or generated interfaces:

```php
use App\Grpc\Messages\HelloReply;
use App\Grpc\Messages\HelloRequest;
use App\Repositories\GreetingRepository;
use Hypervel\Grpc\Server\ServerCallContext;

class GreeterService
{
    public function sayHello(
        GreetingRepository $greetings,
        HelloRequest $request,
        ServerCallContext $call,
    ): HelloReply {
        return (new HelloReply)->setMessage(
            $greetings->for($request->getName()),
        );
    }
}
```

Every action must declare exactly one concrete, non-nullable protobuf message
parameter. An optional `ServerCallContext` parameter may appear once. Container
dependencies, defaults, and contextual attributes may appear before, between,
or after those parameters. Hypervel validates every action signature during
server bootstrap and refuses to listen when it is invalid.

Because Hypervel auto-singletons unbound concrete classes, do not capture a
call-specific `ServerCallContext` in a worker-lifetime singleton constructor.
Use service-method injection, or bind a dependency with the correct scoped or
fresh lifetime when it must capture call data.

<a name="server-call-context"></a>
### Server Call Context

`ServerCallContext` exposes request and transport information:

```php
$metadata = $call->metadata();
$service = $call->service();
$method = $call->method();
$peer = $call->peer();
$deadline = $call->deadline();
$remainingSeconds = $call->timeRemaining();
$expired = $call->deadlineExceeded();
$previousAttempts = $call->previousAttempts();
```

`deadline()` returns a wall-clock `CarbonImmutable` value for application use.
Enforcement and `timeRemaining()` use a monotonic deadline, so wall-clock
changes cannot extend or shorten a running call. `previousAttempts()` exposes
the validated `grpc-previous-rpc-attempts` value for logging and idempotency
decisions.

The context is stored per coroutine. Swoole does not expose an idle HTTP/2
stream reset to a PHP request handler, so the context does not promise a
`cancelled()` state. Deadlines are the supported server-side termination signal.

<a name="responses"></a>
## Responses

A unary action normally returns its protobuf message directly:

```php
public function sayHello(HelloRequest $request): HelloReply
{
    return (new HelloReply)->setMessage('Hello ' . $request->getName());
}
```

Hypervel serializes and frames exactly one message. Returning no message,
multiple unary messages, a non-protobuf value, or a message beyond the
configured limit produces a typed protocol error rather than JSON or string
coercion.

<a name="response-metadata"></a>
### Response Metadata

Wrap a unary message when initial or trailing metadata is needed:

```php
use Hypervel\Grpc\Server\GrpcResponse;

return GrpcResponse::make($reply)
    ->withInitialMetadata(['x-request-id' => $requestId])
    ->withTrailingMetadata(['x-node' => $node]);
```

The fluent methods return independent immutable values. Calling either method
again appends values in order.

<a name="server-streaming"></a>
### Server Streaming

A server-streaming route may return any `iterable` of protobuf messages:

```php
/** @return iterable<HelloReply> */
public function listGreetings(HelloRequest $request): iterable
{
    foreach (['Hello', 'Hi', 'Welcome'] as $greeting) {
        yield (new HelloReply)->setMessage($greeting);
    }
}
```

Add metadata through the streaming response factory:

```php
return GrpcResponse::stream($this->replies($request))
    ->withInitialMetadata(['x-request-id' => $requestId])
    ->withTrailingMetadata(['x-node' => $node]);
```

Hypervel primes one message before committing response headers, then continues
the same iterator lazily. A non-empty stream sends initial metadata with its
first response message. If the iterator completes or throws before its first
message, Swoole has no header-only flush operation: queued initial and trailing
metadata are combined into the one final Trailers-Only block and are observed
as trailing metadata. The package does not expose a `sendInitialMetadata()` API
whose wire behavior the runtime cannot provide.

If a stream fails after sending messages, clients receive those messages and
then the non-OK final status. A native write failure stops and releases the
producer immediately. Hypervel retains one response chunk because Swoole only
preserves HTTP/2 trailers when the final chunk is passed to `end()`; the last
yielded message is emitted when the iterator advances or completes.

<a name="errors"></a>
## Errors

Throw `RpcException` for an expected service failure:

```php
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\StatusCode;

throw new RpcException(StatusCode::NotFound, 'Greeting not found.');
```

Attach trailing metadata or retry pushback immutably:

```php
throw (new RpcException(StatusCode::Unavailable, 'Try another node.'))
    ->withTrailingMetadata(['x-node' => $node])
    ->withRetryAfter(0.25);
```

Use `withoutRetry()` to send the standard negative retry-pushback signal.
Expected RPC exceptions are not reported. Unexpected service or middleware
exceptions are reported through Hypervel's exception handler and become
`Unknown` with a fixed non-sensitive message. Invalid application return values
become `Internal`.

<a name="rich-error-details"></a>
### Rich Error Details

The package supports the standard `grpc-status-details-bin` representation
using `Google\Rpc\Status`:

```php
use Google\Protobuf\Any;
use Google\Rpc\BadRequest;
use Google\Rpc\Status;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\StatusCode;

$detail = new Any;
$detail->pack(
    (new BadRequest)->setFieldViolations([
        (new BadRequest\FieldViolation)
            ->setField('name')
            ->setDescription('The name is required.'),
    ]),
);

throw RpcException::fromStatus(
    (new Status)
        ->setCode(StatusCode::InvalidArgument->value)
        ->setMessage('The request is invalid.')
        ->setDetails([$detail]),
);
```

On the client, `RpcException::status()->details()` returns the decoded outer
`Google\Rpc\Status`. Its embedded `Any` values remain opaque. Do not instantiate
a class from a peer-controlled type URL. Check the expected URL and merge the
bytes into the trusted type:

```php
use Google\Rpc\ErrorInfo;

$details = $exception->status()->details()?->getDetails();
$any = $details === null || count($details) !== 1 ? null : $details[0];

if ($any?->getTypeUrl() === 'type.googleapis.com/google.rpc.ErrorInfo') {
    $errorInfo = new ErrorInfo;
    $errorInfo->mergeFromString($any->getValue());
}
```

Protobuf PHP's `Any::unpack()` and `Any::is()` require the target descriptor to
have been registered first, usually by constructing the expected message class.
The explicit type-URL and `mergeFromString()` form does not depend on descriptor
pool state and makes the application's trusted type clear.

<a name="health-checking"></a>
## Health Checking

`grpc:install` adds the current standard `grpc.health.v1.Health` routes:

```php
use Hypervel\Grpc\Health\HealthService;
use Hypervel\Support\Facades\Grpc;

Grpc::service('grpc.health.v1.Health', function (): void {
    Grpc::unary('Check', [HealthService::class, 'check']);
    Grpc::unary('List', [HealthService::class, 'list']);
    Grpc::serverStream('Watch', [HealthService::class, 'watch']);
});
```

The package ships the official health messages and a `HealthClient`, so native
Kubernetes gRPC probes and ordinary clients can call the listener without an
application-owned copy of the schema.

The default provider reports only the conventional empty service name, meaning
whole-server health, as `SERVING`. It does not guess whether named application
services or dependencies are ready. An unknown service returns `NotFound`.

`Check` and `List` are fully implemented. The Hypervel `Watch` route returns
the standard permitted `Unimplemented` fallback. A correct watch must stay
open, publish transitions coherently across workers, and notice an idle client
cancellation. Swoole exposes neither the needed cross-worker notification nor
idle per-stream reset state to the request handler. The `HealthClient::watch()`
method still consumes watches from external servers that implement them.

<a name="application-health-providers"></a>
### Application Health Providers

Bind `HealthStatusProvider` when health depends on application state:

```php
use Hypervel\Grpc\Health\HealthStatusProvider;
use Hypervel\Grpc\Health\ServingStatus;

class ApplicationHealth implements HealthStatusProvider
{
    public function __construct(private DependencyHealth $dependencies)
    {
    }

    public function statusFor(string $service): ?ServingStatus
    {
        return $this->statuses()[$service] ?? null;
    }

    public function statuses(): array
    {
        return [
            '' => $this->dependencies->ready()
                ? ServingStatus::Serving
                : ServingStatus::NotServing,
            'billing' => $this->dependencies->billingReady()
                ? ServingStatus::Serving
                : ServingStatus::NotServing,
        ];
    }
}
```

Register it in a service provider:

```php
$this->app->singleton(HealthStatusProvider::class, ApplicationHealth::class);
```

Provider state must be coherent across worker processes. Use a shared system or
derive status from real dependencies; do not use a mutable in-process registry
that diverges after Swoole forks.

<a name="clients"></a>
## Clients

Hypervel clients own reusable HTTP/2 connections and create typed call objects.
Construct them with a host and port, optionally including `http://` or
`https://`:

```php
$client = new GreeterClient('grpc.example.com:50051', [
    'timeout' => 5.0,
]);
```

The constructor validates targets and options without opening a socket.
Connections are established lazily on the first call and reused for the worker
lifetime. Call `close()` for deliberately short-lived clients; it is idempotent
and terminal. The client never performs native socket work from a destructor.

<a name="generated-style-clients"></a>
### Generated-Style Clients

Create a small typed client by extending `BaseClient`:

```php
namespace App\Grpc\Clients;

use App\Grpc\Messages\HelloReply;
use App\Grpc\Messages\HelloRequest;
use Hypervel\Grpc\Client\BaseClient;
use Hypervel\Grpc\Client\ServerStreamingCall;
use Hypervel\Grpc\Client\UnaryCall;

class GreeterClient extends BaseClient
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

    public function listGreetings(
        HelloRequest $request,
        array $metadata = [],
        array $options = [],
    ): ServerStreamingCall {
        return $this->_serverStreamRequest(
            '/helloworld.Greeter/ListGreetings',
            $request,
            [HelloReply::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
```

The `[Reply::class, 'decode']` pair is the official generated-stub convention.
Hypervel instantiates the message and calls `mergeFromString()`; the generated
class does not need a real static `decode()` method.

An official `grpc_php_plugin` client may be used by replacing its
`Grpc\BaseStub` parent and import with
`Hypervel\Grpc\Client\BaseClient`. Its `_simpleRequest`,
`_serverStreamRequest`, `_clientStreamRequest`, and `_bidiRequest` calls keep
their generated argument order. This is method-body compatibility, not complete
native extension option compatibility: Hypervel timeout values are seconds,
not the native extension's microseconds, and unsupported option keys fail
explicitly.

<a name="binding-reusable-clients"></a>
### Binding Reusable Clients

Register one client instance for the worker lifetime:

```php
use App\Grpc\Clients\GreeterClient;
use Hypervel\Grpc\Client\RetryPolicy;

$this->app->singleton(GreeterClient::class, fn () => new GreeterClient(
    config('services.greeter.url'),
    [
        'connect_timeout' => 3.0,
        'timeout' => 5.0,
        'retry' => new RetryPolicy(maxAttempts: 3),
    ],
));
```

HTTP/2 multiplexes calls over one connection by default. Increase
`connections` only when a peer has a low concurrent-stream limit or measured
load benefits from additional sockets. Each call selects a slot round-robin.

<a name="unary-calls"></a>
### Unary Calls

`wait()` returns the deserialized protobuf message:

```php
$reply = $client->sayHello($request)->wait();
```

A non-OK status throws `RpcException`. A transport failure throws
`ConnectionException`; malformed peer output throws `ProtocolException`.
Repeated or concurrent `wait()` calls observe the same cached result or
exception and do not deserialize or retry twice.

<a name="server-streaming-calls"></a>
### Server-Streaming Calls

Read one message at a time:

```php
while (($reply = $call->read()) !== null) {
    // ...
}
```

Or iterate every response:

```php
foreach ($call->responses() as $reply) {
    // ...
}
```

`read()` returns `null` after a clean `Ok` completion and throws `RpcException`
when it reaches a non-OK final status. A stream supports one active reader. A
metadata or status observer may wait concurrently with it.

<a name="client-streaming-calls"></a>
### Client-Streaming Calls

Write request messages and half-close before waiting for the unary response:

```php
$call = $client->upload();

foreach ($requests as $request) {
    $call->write($request);
}

$call->writesDone();
$reply = $call->wait();
```

`wait()` calls `writesDone()` idempotently, so the explicit half-close may be
omitted when no more writes are needed. A write after half-close throws
`LogicException`.

<a name="bidirectional-streaming-calls"></a>
### Bidirectional-Streaming Calls

Bidirectional calls allow one reader and one writer concurrently:

```php
$call = $client->chat();

$call->write($firstRequest);
$firstReply = $call->read();

$call->write($secondRequest);
$call->writesDone();

while (($reply = $call->read()) !== null) {
    // ...
}
```

Writes and half-close are serialized. A second simultaneous reader is rejected
instead of dividing messages between callers nondeterministically.

<a name="call-metadata-status"></a>
### Call Metadata and Status

Every call exposes:

```php
$initialMetadata = $call->metadata();
$trailingMetadata = $call->trailers();
$status = $call->status();
$peer = $call->peer();
```

These methods block until the requested information is known. `status()`
returns valid non-OK RPC statuses instead of throwing `RpcException`, which is
useful when status is data rather than control flow. It may still throw a
transport or protocol exception when no trustworthy RPC status exists.

`peer()` is the normalized authority selected for the call. Swoole does not
expose a more authoritative per-stream remote endpoint.

<a name="client-options"></a>
### Client Options

The `BaseClient` constructor accepts these options:

| Option | Default | Description |
|---|---:|---|
| `connections` | `1` | Fixed reusable HTTP/2 connection slots |
| `connect_timeout` | `3.0` | Positive connection timeout in seconds |
| `timeout` | `null` | Default positive RPC deadline in seconds |
| `max_receive_message_size` | `4194304` | Maximum wire and decoded response message bytes |
| `max_send_message_size` | `4194304` | Maximum serialized and wire request message bytes |
| `max_metadata_size` | `8192` | Maximum complete HTTP/2 metadata block bytes |
| `max_buffered_messages` | `128` | Maximum unread messages buffered for one call |
| `max_buffered_bytes` | `16777216` | Maximum unread payload bytes buffered for one call |
| `compression` | `null` | `identity`, `gzip`, or a `Compression` value |
| `retry` | `null` | Default `RetryPolicy` for unary and server-streaming calls |
| `metadata` | `[]` | `Metadata` or an array appended to every call |
| `tls` | secure defaults | First-class TLS settings |
| `swoole` | `[]` | Raw native HTTP/2 client settings |

Per-call options are:

| Option | Meaning |
|---|---|
| `timeout` | Replace the default deadline in seconds; `null` disables it |
| `compression` | Replace the default compression; `null` selects identity |
| `retry` | Replace the default policy; `null` disables retries |

`retry` is accepted only for unary and server-streaming calls. Client and
bidirectional streams do not retain arbitrary application writes for replay and
reject a per-call retry key. Unknown constructor, TLS, and per-call keys fail
immediately so a misspelling cannot silently change behavior.

Raw `swoole.connect_timeout` is rejected because the first-class
`connect_timeout` option owns that setting. A raw `swoole.write_timeout`, or
`swoole.timeout` when no specific write value is present, may set the native
socket-write upper bound but must be a positive finite number. Without either,
the bound is 60 seconds. Hypervel republishes the smaller of this bound and the
remaining RPC deadline before every serialized send or streaming write.

Default metadata is appended before per-call metadata. Repeated values are not
silently replaced.

<a name="client-tls"></a>
### Client TLS

An `https://` target enables TLS and an `http://` target selects plaintext. A
scheme-less target may set `tls.enabled` explicitly:

```php
$client = new GreeterClient('grpc.example.com:443', [
    'tls' => [
        'enabled' => true,
        'verify_peer' => true,
        'ca_file' => base_path('certificates/ca.pem'),
        'server_name' => 'grpc.example.com',
    ],
]);
```

The full TLS option set is:

```php
'tls' => [
    'enabled' => null,
    'verify_peer' => true,
    'ca_file' => null,
    'certificate' => null,
    'private_key' => null,
    'passphrase' => null,
    'server_name' => null,
],
```

`certificate` and `private_key` must be supplied together for mutual TLS, and a
`passphrase` requires that pair. Configured files must be readable. TLS-only
options are rejected for a resolved plaintext target instead of being ignored.

Targets support hostnames, IPv4, and bracketed IPv6 with optional ports.
Userinfo, query strings, fragments, non-root paths, malformed ports, and
resolver-style targets such as `dns:///...` are rejected because the package
does not claim a resolver subsystem.

<a name="metadata"></a>
## Metadata

`Metadata` is immutable and keeps binary values as raw bytes:

```php
use Hypervel\Grpc\Metadata;

$metadata = Metadata::make([
    'authorization' => 'Bearer ...',
    'x-tag' => ['one', 'two'],
    'trace-bin' => $rawTraceBytes,
])
    ->with('x-request-id', $requestId)
    ->without('x-tag');
```

Keys are normalized to lowercase. `with()` and `merge()` append values in
order. Replace a key explicitly with `without($key)->with($key, ...)`.

```php
$first = $metadata->first('x-request-id');
$values = $metadata->values('x-tag');
$all = $metadata->all();
```

Non-binary values must contain visible ASCII without surrounding whitespace.
Keys ending in `-bin` accept arbitrary raw bytes and are base64-encoded only at
the wire boundary. Protocol, HTTP/2 pseudo, connection, and transport-owned
fields are reserved and cannot be supplied as application metadata.

<a name="deadlines"></a>
## Deadlines

Set a default client deadline or override it for one call:

```php
$client = new GreeterClient($target, ['timeout' => 5.0]);

$reply = $client->sayHello(
    $request,
    options: ['timeout' => 0.25],
)->wait();
```

Timeouts are positive finite seconds. `null` means no deadline. Hypervel sends
the standard `grpc-timeout` header and uses one monotonic absolute deadline for
lazy connection, connection-write contention, each native send or streaming
write, response waits, service execution, streamed production, retry attempts,
and backoff. The timeout header is refreshed immediately before each attempt is
sent, so connection time cannot extend the deadline observed by the server. A
deadline that expires locally or on the server produces `DeadlineExceeded`.

Set deadlines on production calls. Without one, a peer or network path may
leave application code waiting indefinitely.

<a name="retries"></a>
## Retries

Retries are disabled by default. Enable them with a typed policy:

```php
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\StatusCode;

$policy = new RetryPolicy(
    maxAttempts: 3,
    initialBackoff: 0.1,
    maxBackoff: 2.0,
    backoffMultiplier: 2.0,
    retryableStatusCodes: [StatusCode::Unavailable],
);

$reply = $client->sayHello(
    $request,
    options: ['retry' => $policy],
)->wait();
```

`maxAttempts` includes the original attempt. Hypervel applies standard capped
exponential backoff with ±20 percent jitter. A valid
`grpc-retry-pushback-ms` trailer replaces the next delay; a negative or invalid
value stops retries.

Only an uncommitted unary or server-streaming call is eligible. Initial response
metadata or a delivered response message commits the call. A Trailers-Only
retryable status may be retried. A transport send failure is never replayed
because Swoole cannot prove that no bytes left the process. Every attempt and
backoff shares the original deadline, and the server can inspect
`ServerCallContext::previousAttempts()`.

Swoole merges a non-final initial header block followed directly by final
trailers when there is no DATA frame. The runtime cannot distinguish that
zero-message response from a true Trailers-Only response, so explicitly enabled
retry treats it as uncommitted. This narrow limitation is covered by grpc-go
interoperability tests.

<a name="compression"></a>
## Compression

The package supports standard identity and gzip message compression:

```php
use Hypervel\Grpc\Compression;

$client = new GreeterClient($target, [
    'compression' => Compression::Gzip,
]);

$reply = $client->sayHello(
    $request,
    options: ['compression' => Compression::Identity],
)->wait();
```

Server response compression is selected with `grpc.server.compression` and is
used only when the client accepts it. Compression applies to each gRPC message,
not the complete HTTP response. Unsupported inbound compression returns
`Unimplemented`; corrupt gzip data returns `Internal` on the server and
`ProtocolException` on the client.

<a name="resource-limits"></a>
## Resource Limits

Send and receive limits apply to both serialized and encoded message sizes, so
compression cannot bypass them. The receive decoder also bounds decompressed
output while inflating, which prevents a small gzip input from allocating an
oversized message first.

Metadata limits account for the complete transport-observable HTTP/2 block,
including protocol and pseudo-fields rather than only application metadata.
An oversized local outbound block or server inbound block produces
`ResourceExhausted`; malformed or oversized peer response metadata produces
`ProtocolException`.

The client receive coroutine never blocks on a slow call. Each call has
independent unread message and byte limits. Crossing either marks that call
`ResourceExhausted`, releases its buffers, retires the un-cancellable stream's
connection, and lets healthy streams already accepted on that connection
finish.

<a name="testing"></a>
## Testing

Service classes contain ordinary methods, so test application behavior by
constructing or resolving the service and passing generated protobuf messages.
Use the normal Hypervel testbench when middleware, bindings, configuration, or
route registration are part of the behavior:

```php
use App\Grpc\GreeterService;
use App\Grpc\Messages\HelloRequest;
use Hypervel\Testbench\TestCase;

class GreeterServiceTest extends TestCase
{
    public function testGreetsAName(): void
    {
        $service = $this->app->make(GreeterService::class);
        $reply = $service->sayHello(
            (new HelloRequest)->setName('Taylor'),
        );

        $this->assertSame('Hello Taylor', $reply->getMessage());
    }
}
```

When testing the wire and client together, start a real Hypervel or independent
gRPC test listener and call it through the generated-style client. This covers
HTTP/2 framing, trailers, compression, deadlines, and connection reuse that a
service-only test cannot. Keep test clients short-lived and call `close()` in
exception-safe cleanup.

The shipped `HealthClient` is also useful for deployment smoke tests:

```php
use Hypervel\Grpc\Health\HealthClient;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;

$client = new HealthClient($target, ['timeout' => 2.0]);
$status = $client->check(new HealthCheckRequest)->wait()->getStatus();
$client->close();
```

<a name="interoperability-platform-boundaries"></a>
## Interoperability and Platform Boundaries

Hypervel's framing, metadata, errors, gzip, deadlines, health service, all four
client call shapes, and TLS behavior are tested in both directions with an
independent grpc-go peer.

The Swoole 6.2 transport defines these visible limits:

- Server request handlers receive the complete body only. Hypervel therefore
  exposes unary and server-streaming server routes, while clients may use all
  four call shapes against servers that support them.
- The HTTP/2 client has no per-stream reset method, so calls do not expose
  `cancel()`. A locally terminal stream retires its connection without
  sacrificing other healthy streams already in progress.
- Swoole stores request and response fields in associative arrays. Independently
  repeated inbound fields may be reduced to one value, and inbound `:scheme` is
  not exposed. Hypervel preserves repetition whenever the transport exposes it,
  but duplicate detection and metadata-size accounting cannot reconstruct bytes
  that Swoole discarded.
- A peer `RST_STREAM` removes the native stream without exposing its HTTP/2
  error code. Hypervel still detects the missing stream, fails only the affected
  call, and retains a nullable transport code.
- Initial headers followed immediately by trailers without response DATA are
  merged into one event, producing the explicit retry-commitment limitation
  described above.
- A server producer cannot observe an idle peer reset, so the standard health
  `Watch` route uses its specified `Unimplemented` fallback instead of leaving
  an abandoned generator alive.

These are documented transport boundaries rather than partially implemented
APIs. Hypervel does not add protocol shims that claim behavior Swoole cannot
provide.
