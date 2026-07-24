<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc\ServerTest;

use Closure;
use Google\Protobuf\GPBEmpty;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coordinator\Timer;
use Hypervel\Events\Dispatcher;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Grpc\Server\CallContextStore;
use Hypervel\Grpc\Server\ExceptionMapper;
use Hypervel\Grpc\Server\GrpcHttpResponse;
use Hypervel\Grpc\Server\GrpcRouter;
use Hypervel\Grpc\Server\GrpcRouteRegistrar;
use Hypervel\Grpc\Server\GrpcStreamedResponse;
use Hypervel\Grpc\Server\Middleware\HandleCall;
use Hypervel\Grpc\Server\ResponseFactory;
use Hypervel\Grpc\Server\Server;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Grpc\StatusCode;
use Hypervel\Http\Request;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ServerTest extends TestCase
{
    protected function setUpInCoroutine(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
    }

    protected function tearDownInCoroutine(): void
    {
        CoordinatorManager::clear(Constants::WORKER_START);
        CoordinatorManager::clear(Constants::WORKER_EXIT);
    }

    public function testBootstrapsTheApplicationAndCompilesTheIsolatedRouter(): void
    {
        $environment = $this->environment();

        $this->assertSame(
            GPBEmpty::class,
            $environment->router->getRoutes()->getRoutes()[0]
                ->getAction('_grpc.request_class'),
        );
        $this->assertSame(
            'request',
            $environment->router->getRoutes()->getRoutes()[0]
                ->getAction('_grpc.request_parameter'),
        );
    }

    public function testDispatchesAUnaryCallAndEmitsFramingAndFinalTrailers(): void
    {
        $environment = $this->environment();
        [$swooleResponse, $capture] = $this->response();

        $environment->server->onRequest($this->request(), $swooleResponse);

        $this->assertSame([], $environment->exceptionHandler->reported);
        $this->assertSame(200, $capture->status);
        $this->assertSame('application/grpc+proto', $capture->headers['content-type']);
        $this->assertSame('0', $capture->trailers['grpc-status']);
        $this->assertCount(1, $capture->ends);
        $this->assertCount(1, $capture->ends[0]);
        $decoder = new FrameDecoder(Compression::Identity, 1024);
        $payloads = iterator_to_array($decoder->push($capture->ends[0][0]), false);
        $decoder->finish();
        $this->assertSame([''], $payloads);
    }

    #[DataProvider('preflightFailures')]
    public function testRejectsInvalidTransportRequestsBeforeRouteDispatch(
        string $protocol,
        string $method,
        ?string $contentType,
        ?string $te,
        string $path,
        ?string $query,
        int $httpStatus,
        ?StatusCode $grpcStatus,
    ): void {
        $environment = $this->environment();
        [$swooleResponse, $capture] = $this->response();
        $headers = ['host' => 'grpc.example.test:50051'];

        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        if ($te !== null) {
            $headers['te'] = $te;
        }

        $environment->server->onRequest($this->request(
            protocol: $protocol,
            method: $method,
            path: $path,
            query: $query,
            headers: $headers,
        ), $swooleResponse);

        $this->assertSame($httpStatus, $capture->status);

        if ($grpcStatus === null) {
            $this->assertArrayNotHasKey('content-type', $capture->headers);
            $this->assertArrayNotHasKey('grpc-status', $capture->headers);
        } else {
            $this->assertSame('application/grpc+proto', $capture->headers['content-type']);
            $this->assertSame((string) $grpcStatus->value, $capture->headers['grpc-status']);
        }

        if ($httpStatus === 405) {
            $this->assertSame('POST', $capture->headers['allow']);
        }
    }

    /**
     * Return raw transport preflight failure cases.
     *
     * @return iterable<string, array{string, string, null|string, null|string, string, null|string, int, null|StatusCode}>
     */
    public static function preflightFailures(): iterable
    {
        $validPath = '/testing.Service/Unary';
        $contentType = 'application/grpc+proto';

        yield 'http 1.1' => ['HTTP/1.1', 'POST', $contentType, 'trailers', $validPath, null, 505, null];
        yield 'method' => ['HTTP/2', 'GET', $contentType, 'trailers', $validPath, null, 405, null];
        yield 'lowercase method' => ['HTTP/2', 'post', $contentType, 'trailers', $validPath, null, 405, null];
        yield 'mixed-case method' => ['HTTP/2', 'Post', $contentType, 'trailers', $validPath, null, 405, null];
        yield 'missing content type' => ['HTTP/2', 'POST', null, 'trailers', $validPath, null, 415, null];
        yield 'non grpc content type' => ['HTTP/2', 'POST', 'application/json', 'trailers', $validPath, null, 415, null];
        yield 'unsupported grpc subtype' => ['HTTP/2', 'POST', 'application/grpc+json', 'trailers', $validPath, null, 415, null];
        yield 'missing te' => ['HTTP/2', 'POST', $contentType, null, $validPath, null, 200, StatusCode::Internal];
        yield 'invalid te' => ['HTTP/2', 'POST', $contentType, 'gzip', $validPath, null, 200, StatusCode::Internal];
        yield 'missing leading slash' => ['HTTP/2', 'POST', $contentType, 'trailers', 'testing.Service/Unary', null, 200, StatusCode::Unimplemented];
        yield 'double leading slash' => ['HTTP/2', 'POST', $contentType, 'trailers', '//testing.Service/Unary', null, 200, StatusCode::Unimplemented];
        yield 'trailing slash' => ['HTTP/2', 'POST', $contentType, 'trailers', $validPath . '/', null, 200, StatusCode::Unimplemented];
        yield 'extra segment' => ['HTTP/2', 'POST', $contentType, 'trailers', $validPath . '/Extra', null, 200, StatusCode::Unimplemented];
        yield 'invalid identifier' => ['HTTP/2', 'POST', $contentType, 'trailers', '/testing-Service/Unary', null, 200, StatusCode::Unimplemented];
        yield 'query in URI' => ['HTTP/2', 'POST', $contentType, 'trailers', $validPath . '?x=1', null, 200, StatusCode::Unimplemented];
        yield 'separate query' => ['HTTP/2', 'POST', $contentType, 'trailers', $validPath, 'x=1', 200, StatusCode::Unimplemented];
        yield 'unmatched canonical method' => ['HTTP/2', 'POST', $contentType, 'trailers', '/testing.Service/Missing', null, 200, StatusCode::Unimplemented];
    }

    public function testRejectsOversizedTransportObservableMetadata(): void
    {
        $environment = $this->environment(maxMetadataSize: 1024);
        [$swooleResponse, $capture] = $this->response();

        $environment->server->onRequest($this->request(headers: [
            'host' => 'grpc.example.test:50051',
            'content-type' => 'application/grpc+proto',
            'te' => 'trailers',
            'x-large' => str_repeat('a', 2048),
        ]), $swooleResponse);

        $this->assertSame('8', $capture->headers['grpc-status']);
    }

    public function testAccountsForTheConfiguredSchemeAuthorityAndExactPath(): void
    {
        $path = '/testing.Service/Unary';
        $headers = [
            'host' => 'grpc.example.test:5443',
            'content-type' => 'application/grpc+proto',
            'te' => 'trailers',
            'x-padding' => str_repeat('a', 1024),
        ];
        $metadataSize = MetadataCodec::wireSize([
            ':method' => 'POST',
            ':scheme' => 'https',
            ':authority' => $headers['host'],
            ':path' => $path,
            'content-type' => $headers['content-type'],
            'te' => $headers['te'],
            'x-padding' => $headers['x-padding'],
        ]);
        $accepted = $this->environment(
            maxMetadataSize: $metadataSize,
            tls: ['local_cert' => __FILE__, 'local_pk' => __FILE__],
        );
        [$acceptedResponse, $acceptedCapture] = $this->response();

        $accepted->server->onRequest($this->request(path: $path, headers: $headers), $acceptedResponse);

        $this->assertSame('0', $acceptedCapture->trailers['grpc-status']);

        $rejected = $this->environment(
            maxMetadataSize: $metadataSize - 1,
            tls: ['local_cert' => __FILE__, 'local_pk' => __FILE__],
        );
        [$rejectedResponse, $rejectedCapture] = $this->response();

        $rejected->server->onRequest($this->request(path: $path, headers: $headers), $rejectedResponse);

        $this->assertSame('8', $rejectedCapture->headers['grpc-status']);
    }

    public function testMapsUnexpectedServiceFailuresWithoutLeakingTheirMessage(): void
    {
        $failure = new RuntimeException('sensitive service failure');
        $environment = $this->environment(static function (
            GrpcRouteRegistrar $registrar,
        ) use ($failure): void {
            $registrar->unary(
                'testing.Service/Unary',
                static function (GPBEmpty $request) use ($failure): never {
                    throw $failure;
                },
            );
        });
        [$swooleResponse, $capture] = $this->response();

        $environment->server->onRequest($this->request(), $swooleResponse);

        $this->assertSame('2', $capture->headers['grpc-status']);
        $this->assertSame(
            'An unknown error occurred while handling the RPC.',
            $capture->headers['grpc-message'],
        );
        $this->assertStringNotContainsString('sensitive', $capture->headers['grpc-message']);
        $this->assertSame([$failure], $environment->exceptionHandler->reported);
    }

    public function testFinalizesAfterOuterMiddlewareAndRejectsProtocolMutation(): void
    {
        $environment = $this->environment(static function (GrpcRouteRegistrar $registrar): void {
            $registrar->unary(
                'testing.Service/Unary',
                static fn (GPBEmpty $request): GPBEmpty => $request,
            )->middleware(MutatesGrpcResponse::class);
        });
        [$swooleResponse, $capture] = $this->response();

        $environment->server->onRequest($this->request(), $swooleResponse);

        $this->assertSame('13', $capture->headers['grpc-status']);
        $this->assertArrayNotHasKey('x-invalid', $capture->headers);
        $this->assertCount(1, $environment->exceptionHandler->reported);
    }

    public function testTransportFailureAfterEmissionDoesNotSendAReplacementAndCleansTheCall(): void
    {
        $environment = $this->environment(static function (GrpcRouteRegistrar $registrar): void {
            $registrar->serverStream(
                'testing.Service/Unary',
                static function (GPBEmpty $request): iterable {
                    yield new GPBEmpty;
                    yield new GPBEmpty;
                },
            );
        });
        [$swooleResponse, $capture] = $this->response();
        $capture->writeResult = false;
        $capture->writable = false;

        $environment->server->onRequest($this->request(), $swooleResponse);

        $this->assertSame(200, $capture->status);
        $this->assertSame([], $capture->ends);
        $this->assertCount(1, $environment->exceptionHandler->reported);
        $this->assertSame(
            'Unable to write the streamed response.',
            $environment->exceptionHandler->reported[0]->getMessage(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No gRPC server call is active');

        $environment->contexts->get();
    }

    /**
     * Build a bootstrapped isolated server environment.
     *
     * @param null|Closure(GrpcRouteRegistrar): void $routes
     * @param array<string, mixed> $tls
     */
    private function environment(
        ?Closure $routes = null,
        int $maxMetadataSize = 8192,
        array $tls = [],
    ): ServerEnvironment {
        $container = new Container;
        $events = new Dispatcher($container);
        $container->instance('events', $events);
        $applicationRouter = new Router($events, $container);
        $router = new GrpcRouter($events, $container);
        $registrar = new GrpcRouteRegistrar($router);
        $contexts = new CallContextStore;
        $exceptionHandler = new RecordingExceptionHandler;
        $exceptions = new ExceptionMapper($exceptionHandler);
        $responses = new ResponseFactory($exceptions, $contexts, 4 * 1024 * 1024, $maxMetadataSize);
        $handler = new HandleCall(
            $contexts,
            new Timer,
            4 * 1024 * 1024,
            Compression::Identity,
        );
        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('bootstrap')->once();

        $container->instance(KernelContract::class, $kernel);
        $container->instance(
            CallableDispatcherContract::class,
            new CallableDispatcher($container),
        );
        $container->instance('router', $applicationRouter);
        $container->instance(GrpcRouter::class, $router);
        $container->instance(ResponseFactory::class, $responses);
        $container->instance(ExceptionMapper::class, $exceptions);
        $container->instance(ExceptionHandler::class, $exceptionHandler);
        $container->instance(CallContextStore::class, $contexts);
        $container->instance(HandleCall::class, $handler);
        $container->bind(
            ServerCallContext::class,
            static fn () => $contexts->get(),
        );
        $container->instance('config', new Repository([
            'grpc' => [
                'server' => [
                    'routes' => __DIR__ . '/Fixtures/routes.php',
                    'max_metadata_size' => $maxMetadataSize,
                    'tls' => $tls,
                ],
            ],
        ]));

        ($routes ?? static function (GrpcRouteRegistrar $registrar): void {
            $registrar->unary(
                'testing.Service/Unary',
                static fn (GPBEmpty $request): GPBEmpty => $request,
            );
        })($registrar);

        $server = new Server($container);
        $server->bootstrapForServer('grpc');

        return new ServerEnvironment(
            $server,
            $router,
            $contexts,
            $exceptionHandler,
        );
    }

    /**
     * Build a raw Swoole request.
     *
     * @param array<string, string> $headers
     */
    private function request(
        string $protocol = 'HTTP/2',
        string $method = 'POST',
        string $path = '/testing.Service/Unary',
        ?string $query = null,
        array $headers = [
            'host' => 'grpc.example.test:50051',
            'content-type' => 'application/grpc+proto',
            'te' => 'trailers',
        ],
        ?string $body = null,
    ): SwooleRequest {
        $request = m::mock(SwooleRequest::class);
        $request->server = [
            'server_protocol' => $protocol,
            'request_method' => $method,
            'request_uri' => $path,
        ];

        if ($query !== null) {
            $request->server['query_string'] = $query;
        }

        $request->header = $headers;
        $request->get = [];
        $request->post = [];
        $request->cookie = [];
        $request->files = [];
        $request->shouldReceive('rawContent')->andReturn(
            $body ?? (new FrameEncoder(1024))->encode((new GPBEmpty)->serializeToString()),
        );

        return $request;
    }

    /**
     * Build a response mock that records every native operation.
     *
     * @return array{SwooleResponse, ServerResponseCapture}
     */
    private function response(): array
    {
        $capture = new ServerResponseCapture;
        $response = m::mock(SwooleResponse::class);
        $response->shouldReceive('status')->zeroOrMoreTimes()->andReturnUsing(
            static function (int $status) use ($capture): bool {
                $capture->status = $status;

                return true;
            },
        );
        $response->shouldReceive('header')->zeroOrMoreTimes()->andReturnUsing(
            static function (string $name, string|array $value) use ($capture): bool {
                $capture->headers[strtolower($name)] = $value;

                return true;
            },
        );
        $response->shouldReceive('cookie')->zeroOrMoreTimes()->andReturnTrue();
        $response->shouldReceive('trailer')->zeroOrMoreTimes()->andReturnUsing(
            static function (string $name, string $value) use ($capture): bool {
                $capture->trailers[strtolower($name)] = $value;

                return true;
            },
        );
        $response->shouldReceive('write')->zeroOrMoreTimes()->andReturnUsing(
            static function (string $chunk) use ($capture): bool {
                $capture->writes[] = $chunk;

                return $capture->writeResult;
            },
        );
        $response->shouldReceive('end')->zeroOrMoreTimes()->andReturnUsing(
            static function (...$arguments) use ($capture): bool {
                $capture->ends[] = $arguments;

                return true;
            },
        );
        $response->shouldReceive('isWritable')->zeroOrMoreTimes()->andReturnUsing(
            static fn (): bool => $capture->writable,
        );

        return [$response, $capture];
    }
}

readonly class ServerEnvironment
{
    public function __construct(
        public Server $server,
        public GrpcRouter $router,
        public CallContextStore $contexts,
        public RecordingExceptionHandler $exceptionHandler,
    ) {
    }
}

class ServerResponseCapture
{
    public ?int $status = null;

    /** @var array<string, list<string>|string> */
    public array $headers = [];

    /** @var array<string, string> */
    public array $trailers = [];

    /** @var list<string> */
    public array $writes = [];

    /** @var list<list<string>> */
    public array $ends = [];

    public bool $writeResult = true;

    public bool $writable = true;
}

class MutatesGrpcResponse
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof GrpcHttpResponse || $response instanceof GrpcStreamedResponse) {
            $response->headers->set('x-invalid', 'middleware');
        }

        return $response;
    }
}

class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $e): void
    {
        $this->reported[] = $e;
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render(Request $request, Throwable $e): SymfonyResponse
    {
        return new SymfonyResponse('', 500);
    }

    public function renderForConsole(OutputInterface $output, Throwable $e): void
    {
    }

    public function afterResponse(callable $callback): void
    {
    }
}
