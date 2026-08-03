<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Any;
use Google\Protobuf\GPBEmpty;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Grpc\Server\CallContextStore;
use Hypervel\Grpc\Server\ExceptionMapper;
use Hypervel\Grpc\Server\GrpcHttpResponse;
use Hypervel\Grpc\Server\GrpcResponse;
use Hypervel\Grpc\Server\GrpcStreamedResponse;
use Hypervel\Grpc\Server\ResponseFactory;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Grpc\StatusCode;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Iterator;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ResponseFactoryTest extends TestCase
{
    public function testBuildsUnaryResponseWithFramingCompressionAndMetadata(): void
    {
        [$factory] = $this->factory();
        $request = $this->request(serverStreaming: false, compression: Compression::Gzip);
        $message = (new Any)->setTypeUrl('testing.Message')->setValue('payload');
        $value = GrpcResponse::make($message)
            ->withInitialMetadata(['x-initial' => 'one'])
            ->withTrailingMetadata(['x-trailing-bin' => "\x00\x01"]);

        $response = $factory->make($request, $value);

        $this->assertInstanceOf(GrpcHttpResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/grpc+proto', $response->headers->get('content-type'));
        $this->assertSame('gzip', $response->headers->get('grpc-encoding'));
        $this->assertSame('one', $response->headers->get('x-initial'));
        $this->assertFalse($response->headers->has('cache-control'));
        $this->assertSame(1, ord((string) $response->getContent()[0]));
        $this->assertSame('0', $response->trailers()['grpc-status']);
        $this->assertSame('AAE', $response->trailers()['x-trailing-bin']);
        $this->assertContains('grpc-status-details-bin', $response->trailerNames());
        $this->assertContains('x-trailing-bin', $response->trailerNames());
    }

    public function testPreservesCacheRelatedMetadataWithoutSymfonyCacheControlSideEffects(): void
    {
        [$factory] = $this->factory();
        $request = $this->request(serverStreaming: false);
        $value = GrpcResponse::make(new Any)
            ->withInitialMetadata(['etag' => 'fixture']);

        $response = $factory->make($request, $value);

        $this->assertSame('fixture', $response->headers->get('etag'));
        $this->assertFalse($response->headers->has('cache-control'));
        $this->assertSame($response, $factory->finalizeForEmission($response));
    }

    public function testBuildsTrueTrailersOnlyResponseForEmptyStreamAndFoldsMetadata(): void
    {
        [$factory] = $this->factory();
        $request = $this->request(serverStreaming: true, compression: Compression::Gzip);
        $value = GrpcResponse::stream([])
            ->withInitialMetadata(['x-order' => 'initial'])
            ->withTrailingMetadata(['x-order' => 'trailing']);

        $response = $factory->make($request, $value);

        $this->assertInstanceOf(GrpcHttpResponse::class, $response);
        $this->assertSame('', $response->getContent());
        $this->assertSame('0', $response->headers->get('grpc-status'));
        $this->assertSame('initial,trailing', $response->headers->get('x-order'));
        $this->assertFalse($response->headers->has('grpc-encoding'));
        $this->assertSame([], $response->trailerNames());
        $this->assertSame([], $response->trailers());
    }

    public function testBuildsATrailersOnlyErrorOutsideRouteDispatch(): void
    {
        [$factory] = $this->factory();

        $response = $factory->error(
            (new RpcException(StatusCode::Unimplemented, 'Unknown method.'))
                ->withTrailingMetadata(['x-error' => 'routing']),
        );

        $this->assertSame('', $response->getContent());
        $this->assertSame('12', $response->headers->get('grpc-status'));
        $this->assertSame('Unknown method.', $response->headers->get('grpc-message'));
        $this->assertSame('routing', $response->headers->get('x-error'));
        $this->assertSame([], $response->trailerNames());
        $this->assertSame([], $response->trailers());
    }

    public function testMapsAPreYieldFailureToTrailersOnlyAndPreservesQueuedMetadata(): void
    {
        [$factory] = $this->factory();
        $request = $this->request(serverStreaming: true);
        $messages = static function (): iterable {
            throw (new RpcException(StatusCode::Unavailable, 'retry later'))
                ->withTrailingMetadata(['x-error' => 'service'])
                ->withRetryAfter(0.025);
            yield;
        };
        $value = GrpcResponse::stream($messages())
            ->withInitialMetadata(['x-order' => 'initial'])
            ->withTrailingMetadata(['x-order' => 'trailing']);

        $response = $factory->make($request, $value);

        $this->assertInstanceOf(GrpcHttpResponse::class, $response);
        $this->assertSame('14', $response->headers->get('grpc-status'));
        $this->assertSame('retry later', $response->headers->get('grpc-message'));
        $this->assertSame('initial,trailing', $response->headers->get('x-order'));
        $this->assertSame('service', $response->headers->get('x-error'));
        $this->assertSame('25', $response->headers->get('grpc-retry-pushback-ms'));
        $this->assertSame([], $response->trailers());
    }

    #[DataProvider('throwingIteratorMethods')]
    public function testMapsCustomIteratorPrimingFailuresWithinTheGeneratorRewind(string $method): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with(m::type(RuntimeException::class));
        [$factory] = $this->factory($handler);
        $request = $this->request(serverStreaming: true);
        $value = GrpcResponse::stream(new ResponseFactoryThrowingIterator($method))
            ->withInitialMetadata(['x-order' => 'initial'])
            ->withTrailingMetadata(['x-order' => 'trailing']);

        $response = $factory->make($request, $value);

        $this->assertInstanceOf(GrpcHttpResponse::class, $response);
        $this->assertSame('', $response->getContent());
        $this->assertSame('2', $response->headers->get('grpc-status'));
        $this->assertSame(
            'An unknown error occurred while handling the RPC.',
            $response->headers->get('grpc-message'),
        );
        $this->assertSame('initial,trailing', $response->headers->get('x-order'));
        $this->assertSame([], $response->trailerNames());
        $this->assertSame([], $response->trailers());
    }

    public static function throwingIteratorMethods(): array
    {
        return [
            'valid' => ['valid'],
            'current' => ['current'],
        ];
    }

    public function testPrimesOneStreamItemAndContinuesTheSameIteratorLazily(): void
    {
        [$factory] = $this->factory();
        $request = $this->request(serverStreaming: true);
        $advances = 0;
        $messages = static function () use (&$advances): iterable {
            yield (new Any)->setValue('first');
            ++$advances;
            yield (new Any)->setValue('second');
            ++$advances;
        };

        $response = $factory->make($request, $messages());

        $this->assertInstanceOf(GrpcStreamedResponse::class, $response);
        $this->assertFalse($response->headers->has('cache-control'));
        $this->assertSame(0, $advances);
        $chunks = [];
        $this->assertTrue($response->streamTo(static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        }));
        $this->assertCount(2, $chunks);
        $this->assertSame(2, $advances);
        $this->assertSame('0', $response->trailers()['grpc-status']);
    }

    public function testMapsMidStreamExpectedFailureToFinalTrailers(): void
    {
        [$factory] = $this->factory();
        $request = $this->request(serverStreaming: true);
        $messages = static function (): iterable {
            yield new GPBEmpty;
            throw (new RpcException(StatusCode::Aborted, 'retry transaction'))
                ->withTrailingMetadata(['x-failure' => 'conflict'])
                ->withoutRetry();
        };

        $response = $factory->make($request, GrpcResponse::stream($messages())
            ->withTrailingMetadata(['x-node' => 'one']));
        $response->streamTo(static fn (): bool => true);
        $trailers = $response->trailers();

        $this->assertSame('10', $trailers['grpc-status']);
        $this->assertSame('retry transaction', $trailers['grpc-message']);
        $this->assertSame('one', $trailers['x-node']);
        $this->assertSame('conflict', $trailers['x-failure']);
        $this->assertSame('-1', $trailers['grpc-retry-pushback-ms']);
    }

    public function testReportsInvalidUnaryAndStreamedServiceValues(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->twice();
        [$factory] = $this->factory($handler);

        $unary = $factory->make($this->request(serverStreaming: false), 'invalid');
        $stream = $factory->make(
            $this->request(serverStreaming: true),
            [new GPBEmpty, 'invalid'],
        );
        $this->assertInstanceOf(GrpcStreamedResponse::class, $stream);
        $stream->streamTo(static fn (): bool => true);

        $this->assertSame('13', $unary->headers->get('grpc-status'));
        $this->assertSame('13', $stream->trailers()['grpc-status']);
    }

    public function testFinalizationRejectsProtocolStateMutation(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        [$factory] = $this->factory($handler);
        $response = $factory->make($this->request(serverStreaming: false), new GPBEmpty);
        $response->headers->set('x-injected', 'middleware');

        $final = $factory->finalizeForEmission($response);

        $this->assertNotSame($response, $final);
        $this->assertSame('13', $final->headers->get('grpc-status'));
        $this->assertFalse($final->headers->has('x-injected'));
    }

    public function testFinalizationRejectsAReplacementHttpResponse(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        [$factory] = $this->factory($handler);

        $final = $factory->finalizeForEmission(new Response('not grpc'));

        $this->assertSame('13', $final->headers->get('grpc-status'));
        $this->assertSame('', $final->getContent());
    }

    public function testFinalizationReplacesOversizedInitialAndFixedTrailerBlocks(): void
    {
        [$factory] = $this->factory(maxMetadataSize: 1024);
        $initial = $factory->make(
            $this->request(serverStreaming: false),
            GrpcResponse::make(new GPBEmpty)->withInitialMetadata(['x-large' => str_repeat('a', 2048)]),
        );
        $trailing = $factory->make(
            $this->request(serverStreaming: false),
            GrpcResponse::make(new GPBEmpty)->withTrailingMetadata(['x-large' => str_repeat('a', 2048)]),
        );

        $finalInitial = $factory->finalizeForEmission($initial);
        $finalTrailing = $factory->finalizeForEmission($trailing);

        $this->assertSame('8', $finalInitial->headers->get('grpc-status'));
        $this->assertSame('8', $finalTrailing->headers->get('grpc-status'));
        $this->assertFalse($finalInitial->headers->has('x-large'));
        $this->assertSame([], $finalTrailing->trailers());
    }

    public function testStreamMetadataLimitCountsOnlyHeadersEmittedByTheTransport(): void
    {
        [$factory] = $this->factory();
        $response = $factory->make(
            $this->request(serverStreaming: true),
            GrpcResponse::stream([new GPBEmpty])
                ->withInitialMetadata(['x-boundary' => str_repeat('x', 256)]),
        );
        $this->assertInstanceOf(GrpcStreamedResponse::class, $response);
        $headers = [
            ':status' => '200',
            ...$response->headers->all(),
            'trailer' => implode(', ', $response->trailerNames()),
        ];
        $wireSize = MetadataCodec::wireSize($headers);
        [$exactFactory] = $this->factory(maxMetadataSize: $wireSize);
        [$undersizedFactory] = $this->factory(maxMetadataSize: $wireSize - 1);

        $this->assertSame($response, $exactFactory->finalizeForEmission($response));

        $replacement = $undersizedFactory->finalizeForEmission($response);

        $this->assertNotSame($response, $replacement);
        $this->assertSame('8', $replacement->headers->get('grpc-status'));
    }

    public function testDynamicOversizedTrailersBecomeResourceExhausted(): void
    {
        [$factory] = $this->factory(maxMetadataSize: 1024);
        $messages = static function (): iterable {
            yield new GPBEmpty;
            throw (new RpcException(StatusCode::Unavailable, 'large'))
                ->withTrailingMetadata(['x-large' => str_repeat('a', 2048)]);
        };
        $response = $factory->make($this->request(serverStreaming: true), $messages());
        $response->streamTo(static fn (): bool => true);

        $trailers = $response->trailers();

        $this->assertSame('8', $trailers['grpc-status']);
        $this->assertArrayNotHasKey('x-large', $trailers);
    }

    public function testRejectsAMetadataLimitThatCannotEmitTheCompactFallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The gRPC metadata limit is too small to emit a protocol error response.',
        );

        $this->factory(maxMetadataSize: 1);
    }

    /**
     * Create a response factory with an active server call context.
     *
     * @return array{ResponseFactory, CallContextStore}
     */
    private function factory(
        ?ExceptionHandler $handler = null,
        int $maxMetadataSize = 8192,
    ): array {
        $handler ??= m::mock(ExceptionHandler::class);
        $contexts = new CallContextStore;
        $contexts->set(new ServerCallContext(
            Metadata::make(),
            'testing.Service',
            'Call',
            '127.0.0.1:50051',
            null,
            Deadline::fromTimeout(null),
            0,
        ));

        return [
            new ResponseFactory(
                new ExceptionMapper($handler),
                $contexts,
                4 * 1024 * 1024,
                $maxMetadataSize,
            ),
            $contexts,
        ];
    }

    /**
     * Create a request with its matched gRPC route and negotiated compression.
     */
    private function request(
        bool $serverStreaming,
        Compression $compression = Compression::Identity,
    ): Request {
        $request = Request::create('/testing.Service/Call', 'POST');
        $route = new Route('POST', 'testing.Service/Call', static fn (): GPBEmpty => new GPBEmpty);
        $route->setAction([
            ...$route->getAction(),
            '_grpc' => [
                'service' => 'testing.Service',
                'method' => 'Call',
                'server_streaming' => $serverStreaming,
                'request_parameter' => 'request',
                'request_class' => GPBEmpty::class,
            ],
        ]);
        $request->setRouteResolver(static fn (): Route => $route);
        $request->attributes->set(ResponseFactory::COMPRESSION_ATTRIBUTE, $compression);

        return $request;
    }
}

class ResponseFactoryThrowingIterator implements Iterator
{
    public function __construct(private readonly string $throwFrom)
    {
    }

    public function current(): mixed
    {
        if ($this->throwFrom === 'current') {
            throw new RuntimeException('The iterator current callback failed.');
        }

        return new GPBEmpty;
    }

    public function next(): void
    {
    }

    public function key(): int
    {
        return 0;
    }

    public function valid(): bool
    {
        if ($this->throwFrom === 'valid') {
            throw new RuntimeException('The iterator valid callback failed.');
        }

        return true;
    }

    public function rewind(): void
    {
    }
}
