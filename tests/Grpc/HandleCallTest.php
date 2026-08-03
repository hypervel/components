<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Closure;
use Google\Protobuf\Any;
use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Internal\Message;
use Hypervel\Coordinator\Timer;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\Server\CallContextStore;
use Hypervel\Grpc\Server\GrpcStreamedResponse;
use Hypervel\Grpc\Server\Middleware\HandleCall;
use Hypervel\Grpc\Server\ResponseFactory;
use Hypervel\Grpc\StatusCode;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use Swoole\Coroutine\CanceledException;

class HandleCallTest extends TestCase
{
    public function testDecodesTheRequestAndScopesItsCallContext(): void
    {
        $contexts = new CallContextStore;
        $handler = new HandleCall($contexts, new Timer, 1024, Compression::Gzip);
        $message = (new Any)->setTypeUrl('testing.Request')->setValue('payload');
        $request = $this->request($message, server: [
            'REMOTE_ADDR' => '2001:db8::1',
            'REMOTE_PORT' => 50051,
            'HTTP_X_TRACE' => 'trace-id',
            'HTTP_GRPC_PREVIOUS_RPC_ATTEMPTS' => '0002',
        ]);
        $request->headers->set('grpc-accept-encoding', ['identity', 'gzip']);

        $result = $handler->handle($request, function (Request $request) use ($contexts): string {
            $context = $contexts->get();
            $message = $request->route('request');

            $this->assertInstanceOf(Any::class, $message);
            $this->assertSame('payload', $message->getValue());
            $this->assertSame('trace-id', $context->metadata()->first('x-trace'));
            $this->assertSame('testing.Service', $context->service());
            $this->assertSame('Call', $context->method());
            $this->assertSame('[2001:db8::1]:50051', $context->peer());
            $this->assertSame(2, $context->previousAttempts());

            return 'handled';
        });

        $this->assertSame('handled', $result);
        $this->assertSame(
            Compression::Gzip,
            $request->attributes->get(ResponseFactory::COMPRESSION_ATTRIBUTE),
        );
        $this->expectException(LogicException::class);
        $contexts->get();
    }

    public function testDecodesGzipRequestMessages(): void
    {
        $contexts = new CallContextStore;
        $handler = new HandleCall($contexts, new Timer, 1024, Compression::Identity);
        $message = (new Any)->setValue(str_repeat('compressible', 20));
        $request = $this->request($message, Compression::Gzip, [
            'HTTP_GRPC_ENCODING' => 'gzip',
        ]);

        $result = $handler->handle(
            $request,
            static fn (Request $request): string => $request->route('request')->getValue(),
        );

        $this->assertSame($message->getValue(), $result);
    }

    public function testRequiresExactlyOneRequestFrame(): void
    {
        foreach ([
            'zero' => '',
            'multiple' => $this->body(new GPBEmpty) . $this->body(new GPBEmpty),
        ] as $case => $body) {
            $contexts = new CallContextStore;
            $handler = new HandleCall($contexts, new Timer, 1024, Compression::Identity);
            $request = $this->request(new GPBEmpty, body: $body);

            try {
                $handler->handle($request, static fn (): string => 'unreachable');
                $this->fail("Expected the {$case}-frame request to fail.");
            } catch (ProtocolException $exception) {
                $this->assertSame(
                    'A supported gRPC server call requires exactly one request message.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testRejectsUnsupportedRequestCompression(): void
    {
        $handler = new HandleCall(new CallContextStore, new Timer, 1024, Compression::Identity);
        $request = $this->request(new GPBEmpty, server: [
            'HTTP_GRPC_ENCODING' => 'snappy',
        ]);

        try {
            $handler->handle($request, static fn (): string => 'unreachable');
            $this->fail('Expected the unsupported encoding to fail.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Unimplemented, $exception->status()->code());
            $this->assertStringContainsString('snappy', $exception->getMessage());
        }
    }

    public function testRejectsMalformedTimeoutAndPreviousAttemptHeaders(): void
    {
        foreach ([
            'timeout' => [
                'HTTP_GRPC_TIMEOUT',
                'one-second',
                'The grpc-timeout request header is malformed.',
            ],
            'attempt syntax' => [
                'HTTP_GRPC_PREVIOUS_RPC_ATTEMPTS',
                '-1',
                'The grpc-previous-rpc-attempts request header is malformed.',
            ],
            'attempt overflow' => [
                'HTTP_GRPC_PREVIOUS_RPC_ATTEMPTS',
                (string) PHP_INT_MAX . '0',
                'The grpc-previous-rpc-attempts request header is too large.',
            ],
        ] as $case => [$name, $value, $message]) {
            $handler = new HandleCall(new CallContextStore, new Timer, 1024, Compression::Identity);
            $request = $this->request(new GPBEmpty, server: [$name => $value]);

            try {
                $handler->handle($request, static fn (): string => 'unreachable');
                $this->fail("Expected the {$case} header to fail.");
            } catch (ProtocolException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testRegistersAndClearsOneDeadlineTimer(): void
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')
            ->once()
            ->with(m::type('float'), m::type(Closure::class))
            ->andReturn(41);
        $timer->shouldReceive('clear')->once()->with(41);
        $contexts = new CallContextStore;
        $handler = new HandleCall($contexts, $timer, 1024, Compression::Identity);
        $request = $this->request(new GPBEmpty, server: ['HTTP_GRPC_TIMEOUT' => '1S']);

        $result = $handler->handle($request, function () use ($contexts): string {
            $this->assertNotNull($contexts->get()->deadline());
            $this->assertGreaterThan(0, $contexts->get()->timeRemaining());

            return 'handled';
        });

        $this->assertSame('handled', $result);
    }

    public function testForgetsTheCallContextWhenDeadlineTimerRegistrationFails(): void
    {
        $failure = new LogicException('Unable to register the deadline timer.');
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->andThrow($failure);
        $timer->shouldNotReceive('clear');
        $contexts = new CallContextStore;
        $handler = new HandleCall($contexts, $timer, 1024, Compression::Identity);
        $request = $this->request(new GPBEmpty, server: ['HTTP_GRPC_TIMEOUT' => '1S']);

        try {
            $handler->handle($request, static fn (): string => 'unreachable');
            $this->fail('Expected deadline timer registration to fail.');
        } catch (LogicException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->expectException(LogicException::class);
        $contexts->get();
    }

    public function testRechecksTheDeadlineAfterNonYieldingServiceWork(): void
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('after')->once()->andReturn(42);
        $timer->shouldReceive('clear')->once()->with(42);
        $handler = new HandleCall(new CallContextStore, $timer, 1024, Compression::Identity);
        // Setup counts against the deadline, so leave headroom under suite load while the service work still outlasts it.
        $request = $this->request(new GPBEmpty, server: ['HTTP_GRPC_TIMEOUT' => '100m']);

        $result = $handler->handle($request, static function (): string {
            usleep(200_000);

            return 'too late';
        });

        $this->assertInstanceOf(RpcException::class, $result);
        $this->assertSame(StatusCode::DeadlineExceeded, $result->status()->code());
    }

    public function testRethrowsCancellationThatWasNotCausedByItsDeadline(): void
    {
        $contexts = new CallContextStore;
        $handler = new HandleCall($contexts, new Timer, 1024, Compression::Identity);
        $request = $this->request(new GPBEmpty);
        $cancellation = new CanceledException;

        try {
            $handler->handle($request, static function () use ($cancellation): never {
                throw $cancellation;
            });
            $this->fail('Expected unrelated cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->expectException(LogicException::class);
        $contexts->get();
    }

    public function testTransfersCleanupToAStreamedResponse(): void
    {
        $contexts = new CallContextStore;
        $handler = new HandleCall($contexts, new Timer, 1024, Compression::Identity);
        $request = $this->request(new GPBEmpty);
        $response = new GrpcStreamedResponse(
            ['frame'],
            [],
            [],
            static fn (): array => [],
        );

        $result = $handler->handle($request, static fn (): GrpcStreamedResponse => $response);

        $this->assertSame($response, $result);
        $this->assertSame('testing.Service', $contexts->get()->service());
        $response->streamTo(static fn (): bool => false);

        $this->expectException(LogicException::class);
        $contexts->get();
    }

    private function request(
        Message $message,
        Compression $compression = Compression::Identity,
        array $server = [],
        ?string $body = null,
    ): Request {
        $request = Request::create(
            '/testing.Service/Call',
            'POST',
            server: $server,
            content: $body ?? $this->body($message, $compression),
        );
        $route = new Route('POST', 'testing.Service/Call', static fn (): GPBEmpty => new GPBEmpty);
        $route->setAction([
            ...$route->getAction(),
            '_grpc' => [
                'service' => 'testing.Service',
                'method' => 'Call',
                'server_streaming' => false,
                'request_parameter' => 'request',
                'request_class' => $message::class,
            ],
        ]);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }

    private function body(
        Message $message,
        Compression $compression = Compression::Identity,
    ): string {
        return (new FrameEncoder(1024))->encode(
            MessageSerializer::serialize($message),
            $compression,
        );
    }
}
