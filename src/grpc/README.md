# gRPC for Hypervel

Coroutine-native gRPC client and server support for Hypervel.

Ported from:

- https://github.com/hyperf/hyperf/tree/master/src/grpc
- https://github.com/hyperf/hyperf/tree/master/src/grpc-client
- https://github.com/hyperf/hyperf/tree/master/src/grpc-server

## Installation

```shell
composer require hypervel/grpc
```

The package can be used as a client without starting another listener. To
install the server configuration and standard health routes, run:

```shell
php artisan grpc:install
```

Then enable the dedicated HTTP/2 listener:

```ini
GRPC_SERVER_ENABLED=true
```

The listener defaults to `0.0.0.0:50051`. Its routes are isolated from the
application HTTP router: HTTP routes are not served on the gRPC port, and gRPC
routes are not served on the application port.

## Server

Register unary and server-streaming methods in `routes/grpc.php`:

```php
use App\Grpc\GreeterService;
use Hypervel\Support\Facades\Grpc;

Grpc::middleware('auth:service')
    ->name('grpc.greeter.')
    ->service('helloworld.Greeter', function (): void {
        Grpc::unary('SayHello', [GreeterService::class, 'sayHello'])
            ->name('say-hello');

        Grpc::serverStream('ListGreetings', [GreeterService::class, 'listGreetings'])
            ->name('list-greetings');
    });
```

Services are ordinary container-resolved classes. Exactly one method parameter
must be a concrete protobuf message. `ServerCallContext` is optional, and all
other dependencies use normal container injection:

```php
use App\Grpc\Messages\HelloReply;
use App\Grpc\Messages\HelloRequest;
use App\Repositories\GreetingRepository;
use Hypervel\Grpc\Server\GrpcResponse;
use Hypervel\Grpc\Server\ServerCallContext;

class GreeterService
{
    public function sayHello(
        HelloRequest $request,
        ServerCallContext $call,
        GreetingRepository $greetings,
    ): GrpcResponse {
        $reply = (new HelloReply)->setMessage(
            $greetings->for($request->getName()),
        );

        return GrpcResponse::make($reply)
            ->withInitialMetadata(['x-request-id' => $call->metadata()->first('x-request-id', '')])
            ->withTrailingMetadata(['x-node' => gethostname() ?: 'unknown']);
    }

    /** @return iterable<HelloReply> */
    public function listGreetings(HelloRequest $request): iterable
    {
        foreach (['Hello', 'Hi', 'Welcome'] as $greeting) {
            yield (new HelloReply)->setMessage($greeting);
        }
    }
}
```

A direct `Message` return is the normal unary response. A direct
`iterable<Message>` is the normal server-streaming response. Use
`GrpcResponse::make()` or `GrpcResponse::stream()` only when response metadata
is needed. Hypervel retains one server-streamed response chunk because Swoole
only preserves HTTP/2 trailers when the final chunk is passed to `end()`; the
last yielded message is emitted when the iterator advances or completes.

Expected failures are represented by `RpcException`:

```php
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\StatusCode;

throw (new RpcException(StatusCode::NotFound, 'Greeting not found.'))
    ->withTrailingMetadata(['x-error-source' => 'greetings']);
```

Rich errors use `RpcException::fromStatus()` with `Google\Rpc\Status`.
Unexpected exceptions are reported through Hypervel's exception handler and
returned as a non-sensitive `Unknown` status.

## Client

Client classes extend `BaseClient` and may use the same protected method-body
vocabulary as clients produced by the official PHP gRPC generator:

```php
use App\Grpc\Messages\HelloReply;
use App\Grpc\Messages\HelloRequest;
use Hypervel\Grpc\Client\BaseClient;
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
}
```

For an official `grpc_php_plugin` client, replace its `Grpc\BaseStub` parent
with `Hypervel\Grpc\Client\BaseClient`. The generated `_simpleRequest`,
`_serverStreamRequest`, `_clientStreamRequest`, and `_bidiRequest` method calls
keep their argument order. Hypervel does not require `ext-grpc`; the optional
`ext-protobuf` extension improves protobuf serialization performance.

Bind reusable clients as worker-lifetime singletons:

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

Call results use message-or-throw semantics:

```php
$reply = $client->sayHello($request)->wait();

$stream = $client->listGreetings($request);
foreach ($stream->responses() as $reply) {
    // ...
}
```

The client supports all four gRPC call shapes:

| Shape | Operations |
|---|---|
| Unary | `wait()` |
| Server streaming | `read()`, `responses()` |
| Client streaming | `write()`, `writesDone()`, `wait()` |
| Bidirectional streaming | `write()`, `read()`, `writesDone()` |

Every call also exposes `metadata()`, `trailers()`, `status()`, and `peer()`.
Non-OK completion throws `RpcException` from `wait()`, `read()`, or
`responses()`. Transport failures throw `ConnectionException`, while malformed
peer output throws `ProtocolException`.

### Client Options

| Option | Default | Meaning |
|---|---:|---|
| `connections` | `1` | Reusable HTTP/2 connection slots |
| `connect_timeout` | `3.0` | Connection timeout in seconds |
| `timeout` | `null` | Default RPC deadline in seconds |
| `max_receive_message_size` | `4194304` | Maximum received message bytes |
| `max_send_message_size` | `4194304` | Maximum sent message bytes |
| `max_metadata_size` | `8192` | Maximum HTTP/2 metadata block bytes |
| `max_buffered_messages` | `128` | Per-call unread message limit |
| `max_buffered_bytes` | `16777216` | Per-call unread byte limit |
| `compression` | `null` | `identity`, `gzip`, or `Compression` |
| `retry` | `null` | Default `RetryPolicy` for eligible calls |
| `metadata` | `[]` | Metadata included with every call |
| `tls` | secure defaults | TLS settings described below |
| `swoole` | `[]` | Raw native client settings |

Per-call options are `timeout`, `compression`, and `retry`. Passing `null`
explicitly disables an inherited nullable default. Retries apply only to unary
and server-streaming calls before response commitment. They are never implicit,
never replay an uncertain send, and share the original deadline across every
attempt and backoff.

Raw `swoole.connect_timeout` is rejected because `connect_timeout` owns that
setting. A raw `swoole.write_timeout`, or `swoole.timeout` when no specific
write value exists, may set the socket-write upper bound but must be a positive
finite number. Each send and streaming write uses the smaller of that bound and
the call's remaining deadline, so one stalled stream cannot hold every call on
the multiplexed connection past its deadline.

TLS is inferred from an `https://` target, or can be enabled for a scheme-less
target:

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

Mutual TLS also accepts `certificate`, `private_key`, and `passphrase`.
The certificate and private key must be supplied together, and a passphrase
requires that pair.

## Metadata

`Metadata` is immutable, lowercases keys, preserves repeated values when the
transport exposes them, and keeps `-bin` values as raw bytes:

```php
use Hypervel\Grpc\Metadata;

$metadata = Metadata::make([
    'authorization' => 'Bearer ...',
    'x-tag' => ['one', 'two'],
    'trace-bin' => $rawTraceBytes,
])->with('x-request-id', $requestId);
```

Reserved protocol and transport fields cannot be supplied as application
metadata.

## Health Checking

The installed route file exposes the standard `grpc.health.v1.Health` service.
The default provider reports the whole server (`""`) as `SERVING` and does not
guess application service readiness. Bind `HealthStatusProvider` to report real
application health:

```php
use Hypervel\Grpc\Health\HealthStatusProvider;
use Hypervel\Grpc\Health\ServingStatus;

class ApplicationHealth implements HealthStatusProvider
{
    public function statusFor(string $service): ?ServingStatus
    {
        return $this->statuses()[$service] ?? null;
    }

    public function statuses(): array
    {
        return [
            '' => ServingStatus::Serving,
            'billing' => ServingStatus::NotServing,
        ];
    }
}

$this->app->singleton(HealthStatusProvider::class, ApplicationHealth::class);
```

`Check` and `List` are fully supported. `Watch` returns the standard permitted
`Unimplemented` fallback because Swoole cannot expose idle per-stream client
cancellation or coordinate health transitions across workers correctly. The
shipped `HealthClient` can consume `Watch` from external servers that implement
it.

## Platform Boundaries

- The Hypervel server supports unary and server-streaming methods. Swoole gives
  request handlers only the completed request body, so server-side client and
  bidirectional streaming are not exposed.
- Swoole has no client per-stream reset API, so calls do not expose `cancel()`.
  Deadlines remain fully enforced.
- Swoole stores inbound headers in associative arrays. Independently repeated
  request or response fields may be reduced to one value, and inbound `:scheme`
  is not exposed. Metadata-size and duplicate-field validation can cover only
  the transport-observable values.
- A peer `RST_STREAM` is detected and isolated, but Swoole does not expose its
  HTTP/2 error code.
- Initial headers followed directly by final trailers without a DATA frame are
  merged by Swoole. With explicit retries enabled, this zero-message form is
  indistinguishable from a true Trailers-Only response and remains eligible for
  retry.

## Differences From Hyperf

- Client, server, and shared wire code live in one package with clear
  namespaces rather than three Composer packages.
- The package has no generic RPC, service-governance, discovery, load-balancer,
  transporter, packer, normalizer, or proxy layer.
- Service paths follow the standard `/{fully-qualified-service}/{method}` form.
- Clients return protobuf messages or throw typed exceptions; they do not
  return message/status tuples.
- Retries are explicit and follow gRPC commitment and total-deadline rules.
- Raw Swoole HTTP/2 mechanics remain behind Hypervel's engine and response
  bridge boundaries.
