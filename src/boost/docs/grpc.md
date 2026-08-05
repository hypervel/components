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
    - [Handling Client Errors](#handling-client-errors)
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
- [Platform Limitations](#platform-limitations)

<a name="introduction"></a>
## Introduction

Hypervel gRPC provides client and server support for [gRPC](https://grpc.io), a high-performance remote procedure call framework built on HTTP/2 and Protocol Buffers. The package does not require the native gRPC PHP extension.

Hypervel clients may make unary, server-streaming, client-streaming, and bidirectional-streaming calls. Hypervel servers support unary and server-streaming methods on a dedicated HTTP/2 port.

The package supports metadata, standard and rich error statuses, deadlines, retries, gzip compression, TLS, health checks, and configurable resource limits.

<a name="installation"></a>
## Installation

Install the package using Composer:

```shell
composer require hypervel/grpc
```

Package discovery will automatically register the package's service provider. The gRPC server is disabled by default, so you may install the package for client use without opening another port.

<a name="protocol-buffer-tooling"></a>
### Protocol Buffer Tooling

Install the official [Protocol Buffers compiler](https://protobuf.dev/installation/) for your operating system. Given the following service definition in `resources/proto/helloworld.proto`:

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

You may generate the PHP message classes using `protoc`:

```shell
grpc_output="$(mktemp -d)"
trap 'rm -rf "$grpc_output"' EXIT

protoc \
  --proto_path=resources/proto \
  --php_out="$grpc_output" \
  resources/proto/helloworld.proto

cp -R "$grpc_output/App/." app/
```

The temporary output directory is necessary because `protoc` includes the complete PHP namespace in its generated path. The copy command places the generated `App\Grpc\Messages` classes under your application's normal `App\` PSR-4 root, and the temporary directory is removed automatically when the shell exits.

Generated message classes use the `google/protobuf` package. Installing the optional `ext-protobuf` extension improves serialization performance.

Hypervel initializes registered server request classes and concrete response return types that can be constructed without arguments before workers start. If another generated message may be used for the first time by concurrent coroutines—such as a server response behind an iterable or union return type, or a client request or response—construct one instance in a service provider's `boot` method. This registers Protocol Buffers' process-global descriptor metadata before concurrent work begins.

The official `grpc_php_plugin` may also generate client classes. These classes may be adapted to Hypervel as described in [Generated-Style Clients](#generated-style-clients).

<a name="installing-server"></a>
### Installing the Server

Publish the server configuration and standard health routes:

```shell
php artisan grpc:install
```

This command publishes the `config/grpc.php` configuration file and a `routes/grpc.php` route file containing the standard gRPC health service. Existing files will not be overwritten unless you use the `--force` option.

Next, enable the server in your application's `.env` file:

```ini
GRPC_SERVER_ENABLED=true
```

The gRPC listener will start with the normal Hypervel server process:

```shell
php artisan serve
```

<a name="server-configuration"></a>
## Server Configuration

The published `config/grpc.php` file contains the following server options:

| Option | Environment Variable | Default | Description |
|---|---|---:|---|
| `enabled` | `GRPC_SERVER_ENABLED` | `false` | Determines whether the gRPC listener is started |
| `name` | `GRPC_SERVER_NAME` | `grpc` | Unique name for the listener |
| `host` | `GRPC_SERVER_HOST` | `0.0.0.0` | Address on which the listener binds |
| `port` | `GRPC_SERVER_PORT` | `50051` | Port on which the listener binds |
| `routes` | None | `routes/grpc.php` | Route file loaded by the gRPC server |
| `max_receive_message_size` | `GRPC_SERVER_MAX_RECEIVE_MESSAGE_SIZE` | `4194304` | Maximum received message size in bytes |
| `max_send_message_size` | `GRPC_SERVER_MAX_SEND_MESSAGE_SIZE` | `4194304` | Maximum sent message size in bytes |
| `max_metadata_size` | `GRPC_SERVER_MAX_METADATA_SIZE` | `8192` | Maximum metadata size in bytes |
| `compression` | `GRPC_SERVER_COMPRESSION` | `null` | Preferred response compression: `identity` or `gzip` |
| `tls` | Various | Disabled | TLS configuration for the listener |
| `settings` | None | `[]` | Additional supported Swoole listener settings |

The server name must be unique among all Hypervel listeners, and the route file must be readable when the server starts. Hypervel rejects Swoole settings controlled by the gRPC package, as well as globally unsupported settings such as `event_object`.

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

The `local_cert` and `local_pk` options must be provided together. To require client certificates, enable `verify_peer`; use `cafile` to specify the trusted client CA. Hypervel verifies that each configured certificate, private key, and CA file is readable before starting the listener.

You may also terminate TLS at a reverse proxy and forward native HTTP/2 gRPC traffic to a plaintext Hypervel listener. The package does not support gRPC-Web or HTTP/1 clients.

<a name="routing"></a>
## Routing

The installed `routes/grpc.php` file uses the `Grpc` facade. You may group related methods under their fully qualified Protocol Buffer service name:

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

You may also register a fully qualified method name directly:

```php
Grpc::unary(
    'helloworld.Greeter/SayHello',
    [GreeterService::class, 'sayHello'],
);
```

Service and method names follow Protocol Buffer identifier rules and are case-sensitive. Hypervel uses the standard `/{fully-qualified-service}/{method}` gRPC path and the required HTTP `POST` method.

gRPC method names do not support URI parameters or fallback routes, and service groups may not be nested.

gRPC routes are isolated from your application's HTTP routes. However, they still support Hypervel's normal controller and closure dispatch, dependency injection, middleware, and route names.

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

Middleware aliases, groups, exclusions, and priorities registered on the application router are also available to gRPC routes. Global HTTP middleware is not automatically run on the gRPC port, so you should explicitly attach any middleware required by an RPC.

Hypervel's protocol handling and deadline enforcement always run and cannot be excluded from a gRPC route.

gRPC middleware should return the response produced by the next middleware or service. To add response metadata, use `GrpcResponse` or `RpcException` instead of changing HTTP response headers.

<a name="writing-services"></a>
## Writing Services

gRPC services are ordinary classes resolved by Hypervel's service container. They do not need to extend a base class or implement a generated interface:

```php
use App\Grpc\Messages\HelloReply;
use App\Grpc\Messages\HelloRequest;

class GreeterService
{
    public function sayHello(HelloRequest $request): HelloReply
    {
        return (new HelloReply)->setMessage('Hello ' . $request->getName());
    }
}
```

Every service action must declare exactly one concrete, non-nullable Protocol Buffer message parameter. You may also inject `ServerCallContext` and any other container dependencies in any parameter order. Hypervel validates service action signatures before the server starts.

> [!WARNING]
> The `ServerCallContext` contains data for one RPC. Inject it into the service method or a scoped dependency, not the constructor of a worker-lifetime singleton.

<a name="server-call-context"></a>
### Server Call Context

The `ServerCallContext` provides information about the current RPC:

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

The `deadline` method returns a `Hypervel\Support\CarbonImmutable` instance or `null` when the client did not set a deadline. The `timeRemaining` method returns the remaining number of seconds, while `deadlineExceeded` determines whether the deadline has passed.

The `previousAttempts` method returns the number of completed retry attempts reported by the client. This can be useful for logging or idempotency handling.

The context does not expose client cancellation state because Swoole cannot reliably report a peer stream reset to the server request handler. Use a deadline when service work must have a bounded lifetime.

<a name="responses"></a>
## Responses

A unary service action should return its Protocol Buffer message directly:

```php
public function sayHello(HelloRequest $request): HelloReply
{
    return (new HelloReply)->setMessage('Hello ' . $request->getName());
}
```

Unary actions must return exactly one Protocol Buffer message. Invalid return values are reported and returned to the client with an `Internal` status.

<a name="response-metadata"></a>
### Response Metadata

If you need to add initial or trailing metadata, wrap the response using `GrpcResponse`:

```php
use Hypervel\Grpc\Server\GrpcResponse;

return GrpcResponse::make($reply)
    ->withInitialMetadata(['x-request-id' => $requestId])
    ->withTrailingMetadata(['x-node' => $node]);
```

The `withInitialMetadata` and `withTrailingMetadata` methods return new response instances and may be chained. Calling either method more than once appends the additional values.

<a name="server-streaming"></a>
### Server Streaming

A server-streaming action may return any iterable of Protocol Buffer messages:

```php
/** @return iterable<HelloReply> */
public function listGreetings(HelloRequest $request): iterable
{
    foreach (['Hello', 'Hi', 'Welcome'] as $greeting) {
        yield (new HelloReply)->setMessage($greeting);
    }
}
```

To add metadata to a streamed response, use the `stream` method:

```php
return GrpcResponse::stream($this->replies($request))
    ->withInitialMetadata(['x-request-id' => $requestId])
    ->withTrailingMetadata(['x-node' => $node]);
```

Streamed responses are consumed lazily. Initial metadata is sent with the first response message. If a stream completes or fails before producing a message, its initial metadata is returned to the client as trailing metadata.

Hypervel keeps one streamed response message until the iterator advances or finishes so Swoole can send the final trailers correctly. If the stream pauses before producing its next message, delivery of the previous message is delayed until the stream resumes or ends.

If a stream fails after producing one or more messages, the client receives those messages before receiving the final error status.

<a name="errors"></a>
## Errors

Throw `RpcException` for an expected service failure:

```php
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\StatusCode;

throw new RpcException(StatusCode::NotFound, 'Greeting not found.');
```

The `StatusCode` enum contains every [standard gRPC status code](https://grpc.io/docs/guides/status-codes/) using PascalCase case names such as `InvalidArgument`, `NotFound`, and `Unavailable`.

You may attach trailing metadata or tell retry-enabled clients how long to wait before another attempt:

```php
throw (new RpcException(StatusCode::Unavailable, 'Try another node.'))
    ->withTrailingMetadata(['x-node' => $node])
    ->withRetryAfter(0.25);
```

You may use the `withoutRetry` method to prevent a retry-enabled client from retrying the failure. Expected `RpcException` instances are not reported. Other service or middleware exceptions are reported through Hypervel's exception handler and returned to the client with an `Unknown` status and a safe message.

<a name="handling-client-errors"></a>
### Handling Client Errors

Client calls throw `RpcException` when the server returns a non-OK gRPC status. The exception provides the status, response metadata, method path, and connection target:

```php
use Hypervel\Grpc\Exceptions\RpcException;

try {
    $reply = $client->sayHello($request)->wait();
} catch (RpcException $exception) {
    $code = $exception->status()->code();
    $message = $exception->status()->message();
    $initialMetadata = $exception->metadata();
    $trailingMetadata = $exception->trailers();
    $method = $exception->method();
    $target = $exception->target();
}
```

Connection failures throw `ConnectionException`, which provides the target and, when Swoole supplies one, a transport error code. Invalid gRPC responses throw `ProtocolException`.

<a name="rich-error-details"></a>
### Rich Error Details

To return structured error details, create a `Google\Rpc\Status` message and pass it to the `fromStatus` method:

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

On the client, the status object's `details` method returns the decoded `Google\Rpc\Status`. Its embedded `Any` values remain packed. Check for an expected type URL before decoding the value into a trusted message class:

```php
use Google\Rpc\ErrorInfo;

$details = $exception->status()->details()?->getDetails();
$any = $details === null || count($details) !== 1 ? null : $details[0];

if ($any?->getTypeUrl() === 'type.googleapis.com/google.rpc.ErrorInfo') {
    $errorInfo = new ErrorInfo;
    $errorInfo->mergeFromString($any->getValue());
}
```

This explicit form does not depend on the Protocol Buffer descriptor pool. The `Any::unpack` and `Any::is` methods may also be used after the target message's descriptor has been registered.

<a name="health-checking"></a>
## Health Checking

The `grpc:install` command adds the standard `grpc.health.v1.Health` service to `routes/grpc.php`:

```php
use Hypervel\Grpc\Health\HealthService;
use Hypervel\Support\Facades\Grpc;

Grpc::service('grpc.health.v1.Health', function (): void {
    Grpc::unary('Check', [HealthService::class, 'check']);
    Grpc::unary('List', [HealthService::class, 'list']);
    Grpc::serverStream('Watch', [HealthService::class, 'watch']);
});
```

The package includes the official health messages and a `HealthClient`, making the service compatible with Kubernetes gRPC probes and other standard clients.

By default, the `Check` and `List` methods report the whole server, represented by an empty service name, as `SERVING`. Named services are unknown until your application provides their status, and checking an unknown service returns a `NotFound` status.

The server's `Watch` method returns the standard `Unimplemented` fallback because Hypervel cannot reliably publish health changes across workers or detect an idle client disconnect. The `HealthClient` may still consume `Watch` responses from other gRPC servers that implement it.

You may use the included `HealthClient` to call a standard gRPC health service:

```php
use Hypervel\Grpc\Health\HealthClient;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;

$client = new HealthClient($target, ['timeout' => 2.0]);
$status = $client->check(
    (new HealthCheckRequest)->setService(''),
)->wait()->getStatus();
$client->close();
```

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

Since each Swoole worker has its own process memory, health state that may change while the server is running should come from a shared store or be calculated from shared dependencies.

<a name="clients"></a>
## Clients

Hypervel gRPC clients extend `BaseClient` and return a typed call object for each RPC. Create a client using a host and port, optionally including an `http://` or `https://` scheme:

```php
$client = new GreeterClient('grpc.example.com:50051', [
    'timeout' => 5.0,
]);
```

Connections are opened when first needed and reused by later calls. Therefore, client instances should normally be registered as worker-lifetime singletons. For a short-lived client, call its `close` method when it is no longer needed. The method is idempotent, and a closed client cannot start another call.

The client's `target` method returns the target string passed to its constructor.

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

The `[Reply::class, 'decode']` pair follows the official generated-client convention. Hypervel will create the response message and deserialize the received bytes into it.

To add metadata to every call made by a client, override the `prepareMetadata` method:

```php
use Hypervel\Grpc\Metadata;
use Hypervel\Support\Facades\Context;

/**
 * Prepare metadata for a new RPC.
 *
 * @param array<string, list<string>|string>|Metadata $metadata
 */
protected function prepareMetadata(array|Metadata $metadata): Metadata
{
    return parent::prepareMetadata($metadata)->with(
        'x-account-id',
        (string) Context::get('account_id'),
    );
}
```

The method runs once when an RPC is created. If the call is retried, Hypervel reuses the prepared metadata instead of running the method again.

The RPC method bodies produced by the official `grpc_php_plugin` may also be used with Hypervel. Change the parent from `Grpc\BaseStub` to `Hypervel\Grpc\Client\BaseClient`, then remove the generated constructor so the client inherits Hypervel's target-and-options constructor. You should also replace any `Grpc\UnaryCall`, `Grpc\ServerStreamingCall`, `Grpc\ClientStreamingCall`, or `Grpc\BidiStreamingCall` annotations and types with their `Hypervel\Grpc\Client` equivalents. The generated `_simpleRequest`, `_serverStreamRequest`, `_clientStreamRequest`, and `_bidiRequest` calls already use the argument order expected by Hypervel.

> [!NOTE]
> Hypervel timeouts are expressed in seconds, while the native gRPC PHP extension uses microseconds. Hypervel supports the options documented below and rejects unsupported native extension options.

<a name="binding-reusable-clients"></a>
### Binding Reusable Clients

You may register a reusable client in one of your application's service providers:

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

One HTTP/2 connection can handle many concurrent calls. You should only increase the `connections` option when the remote server limits concurrent streams or application measurements show that additional connections improve performance.

<a name="unary-calls"></a>
### Unary Calls

For unary calls, the `wait` method returns the response message:

```php
$reply = $client->sayHello($request)->wait();
```

If the server returns a non-OK status, the `wait` method throws `RpcException`. Connection failures throw `ConnectionException`, while invalid responses throw `ProtocolException`.

Repeated or concurrent calls to `wait` receive the same cached response or exception without deserializing the response or running a retry more than once.

<a name="server-streaming-calls"></a>
### Server-Streaming Calls

For server-streaming calls, you may read one response at a time using the `read` method:

```php
while (($reply = $call->read()) !== null) {
    // ...
}
```

Alternatively, you may iterate over the `responses` method:

```php
foreach ($call->responses() as $reply) {
    // ...
}
```

The `read` method returns `null` when the server completes the stream successfully and throws `RpcException` when the stream ends with a non-OK status. Only one coroutine may actively read a response stream at a time.

<a name="client-streaming-calls"></a>
### Client-Streaming Calls

For client-streaming calls, use the `write` method to send request messages. When all messages have been sent, call `writesDone` before waiting for the response:

```php
$call = $client->upload();

foreach ($requests as $request) {
    $call->write($request);
}

$call->writesDone();
$reply = $call->wait();
```

The `wait` method will call `writesDone` for you, so you may omit the explicit call when no more messages need to be sent. Calling `write` after `writesDone` throws `LogicException`.

<a name="bidirectional-streaming-calls"></a>
### Bidirectional-Streaming Calls

Bidirectional-streaming calls allow one coroutine to write request messages while another reads responses:

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

Call `writesDone` when no more request messages will be sent. Response messages remain available through `read` until the server completes the call.

One reader and one writer may operate concurrently on a bidirectional stream. Only one coroutine may actively read the stream at a time, and concurrent writes are serialized.

<a name="call-metadata-status"></a>
### Call Metadata and Status

Every call exposes:

```php
$initialMetadata = $call->metadata();
$trailingMetadata = $call->trailers();
$status = $call->status();
$successful = $status->isOk();
$peer = $call->peer();
```

These methods wait until the requested information is available. Unlike `wait`, `read`, and `responses`, the `status` method returns non-OK gRPC statuses instead of throwing `RpcException`. A connection or protocol failure may still be thrown when no valid status was received.

The `peer` method returns the host and port selected for the call.

<a name="client-options"></a>
### Client Options

The `BaseClient` constructor accepts these options:

| Option | Default | Description |
|---|---:|---|
| `connections` | `1` | Number of reusable HTTP/2 connections |
| `connect_timeout` | `3.0` | Connection timeout in seconds |
| `timeout` | `null` | Default call deadline in seconds |
| `max_receive_message_size` | `4194304` | Maximum received message size in bytes |
| `max_send_message_size` | `4194304` | Maximum sent message size in bytes |
| `max_metadata_size` | `8192` | Maximum metadata size in bytes |
| `max_buffered_messages` | `128` | Maximum unread messages held for one call |
| `max_buffered_bytes` | `16777216` | Maximum unread bytes held for one call |
| `compression` | `null` | `identity`, `gzip`, or a `Compression` value |
| `retry` | `null` | Default `RetryPolicy` for unary and server-streaming calls |
| `metadata` | `[]` | `Metadata` or an array appended to every call |
| `tls` | `[]` | TLS settings; enabled automatically for `https://` targets |
| `swoole` | `[]` | Additional Swoole HTTP/2 client settings |

Per-call options are:

| Option | Meaning |
|---|---|
| `timeout` | Replace the default deadline in seconds; `null` disables it |
| `compression` | Replace the default compression; `null` selects identity |
| `retry` | Replace the default policy; `null` disables retries |

The `retry` option is only supported by unary and server-streaming calls. Unknown client, TLS, and per-call option names are rejected instead of being silently ignored. The keys within the `swoole` array are native settings and are passed through after the checks described below.

The connection, message, metadata, and buffering limits must be positive. The `max_buffered_bytes` value must be at least as large as `max_receive_message_size` so one valid response message can be buffered.

The `connect_timeout` option controls connection establishment and may not be repeated within `swoole`. If you provide `swoole.write_timeout` or `swoole.timeout`, it must be a positive, finite number of seconds. Call deadlines will still take precedence when they expire sooner.

Default metadata is added before per-call metadata. Values using the same key are appended rather than replaced.

<a name="client-tls"></a>
### Client TLS

An `https://` target enables TLS, while an `http://` target uses plaintext. For a target without a scheme, you may set `tls.enabled` explicitly:

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

For mutual TLS, provide both `certificate` and `private_key`. A `passphrase` may only be used when a private key is configured. Hypervel verifies that configured CA, certificate, and private-key files are readable.

Targets may contain a hostname, IPv4 address, or bracketed IPv6 address with an optional port. Plaintext targets without a port use port 80, while TLS targets use port 443. TLS-only options are rejected for plaintext targets. Resolver targets such as `dns:///...` are not supported.

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

Metadata keys are normalized to lowercase. The `with` and `merge` methods append values in order. To replace an existing key, remove it before adding the new value:

```php
$metadata = $metadata->without('x-tag')->with('x-tag', 'replacement');
```

You may retrieve metadata using the `first`, `values`, and `all` methods:

```php
$first = $metadata->first('x-request-id');
$values = $metadata->values('x-tag');
$all = $metadata->all();
```

The `has` and `isEmpty` methods may be used to inspect the collection. `Metadata` is also iterable and countable by key, yielding each key with its list of values.

Non-binary values must contain visible ASCII characters and may not have surrounding whitespace. Keys ending in `-bin` accept arbitrary binary strings; Hypervel handles their wire encoding automatically. Protocol and transport headers cannot be used as application metadata keys.

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

Timeouts are positive, finite numbers expressed in seconds. A `null` timeout disables the deadline. The deadline covers the complete call, including connection setup, streaming operations, retries, and retry delays. When the deadline expires, the call fails with a `DeadlineExceeded` status.

> [!WARNING]
> You should configure deadlines for production calls. Without a deadline, application code may wait indefinitely for an unavailable peer or network path.

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

The `maxAttempts` value includes the original call. Hypervel uses capped exponential backoff with jitter of up to 20 percent in either direction. A server may replace the next delay using `RpcException::withRetryAfter` or prevent another retry using `RpcException::withoutRetry`.

Retries are only available for unary and server-streaming calls that have not received initial metadata or a response message. Hypervel will not retry a failed send because the server may already have received part or all of the request. All attempts and delays share the call's original deadline.

Because Swoole can present an early-metadata, zero-message response as a single event that is indistinguishable from a Trailers-Only response, an explicitly retry-enabled call may retry that response. See [Platform Limitations](#platform-limitations) for details.

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

Identity compression is used by default. Server response compression is configured using `grpc.server.compression` and is only used when the client supports it. You may override client compression for an individual call using the `compression` option.

Unsupported request compression returns an `Unimplemented` status. Corrupt gzip data returns `Internal` on the server and throws `ProtocolException` on the client.

<a name="resource-limits"></a>
## Resource Limits

The `max_send_message_size` and `max_receive_message_size` options limit both compressed and uncompressed message sizes. Exceeding a configured message-size limit causes the affected call to fail with `ResourceExhausted`.

The `max_metadata_size` option limits the complete visible HTTP/2 metadata block, including protocol fields as well as application metadata. Malformed binary metadata or oversized metadata received by a client throws `ProtocolException`. Invalid ASCII metadata fields may be discarded, as permitted by the gRPC protocol.

For streaming responses, `max_buffered_messages` and `max_buffered_bytes` limit how much unread data may be held for one call. If either limit is exceeded, that call fails with `ResourceExhausted` without blocking other calls on the same connection.

<a name="testing"></a>
## Testing

Since gRPC services are ordinary classes, you may test them by passing generated Protocol Buffer messages directly to their methods. For an isolated test that does not need the application container, extend `Hypervel\Tests\TestCase`. Use Hypervel Testbench when the test requires service-container bindings, middleware, configuration, or route registration:

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

For end-to-end tests, start a test gRPC server and call it through your application client. This verifies behavior that a direct service test does not cover, such as metadata, compression, deadlines, and streaming. The included `HealthClient` may also be used for deployment smoke tests.

<a name="platform-limitations"></a>
## Platform Limitations

Hypervel's gRPC behavior is tested in both directions against an independent grpc-go implementation. However, the Swoole HTTP/2 APIs impose a few limitations:

- Hypervel servers support unary and server-streaming methods. Swoole provides the complete request body to the request handler rather than exposing each incoming HTTP/2 data frame, so server-side client streaming and bidirectional streaming are not available. Hypervel clients may use all four call types against compatible servers.
- Client call objects do not provide a `cancel` method because Swoole cannot reset an individual client stream. Deadlines are still enforced.
- Independently repeated metadata fields may be reduced to one value by Swoole, and the inbound `:scheme` pseudo-header is not exposed. Hypervel preserves repeated values whenever the transport exposes them, but inbound duplicate-field and metadata-size validation can only cover those exposed values.
- A peer stream reset fails only the affected call, but Swoole does not provide its HTTP/2 error code.
- Swoole merges initial response metadata followed immediately by final metadata without a response message into one uncommitted response. Since this is indistinguishable from a Trailers-Only response, an explicitly retry-enabled call may retry it.
- The built-in health service returns `Unimplemented` from `Watch` because the server cannot reliably observe idle stream cancellation or distribute health changes across workers.
