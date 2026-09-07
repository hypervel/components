<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use Hypervel\WebSocketServer\Events\ConnectionClosed;
use Hypervel\WebSocketServer\Events\ConnectionOpening;
use Hypervel\WebSocketServer\Security;
use Hypervel\WebSocketServer\Server;
use Mockery as m;
use Sentry\EventType;
use Sentry\Logs\Log;
use Sentry\Metrics\Types\Metric;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleWebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Sentry\logger;
use function Sentry\traceMetrics;

class WebSocketRuntimeContextTest extends SentryTestCase
{
    public function testRejectedHandshakeFlushesTelemetryRecordedBeforeValidation(): void
    {
        $this->app->make('events')->listen(ConnectionOpening::class, static function (): void {
            logger()->info('opening rejected connection');
        });

        $server = new SentryWebSocketServer(
            $this->app,
            m::mock(Router::class)->shouldIgnoreMissing(),
            m::mock(SwooleWebSocketServer::class),
        );
        $request = $this->handshakeRequest(validSecurityKey: false);
        $response = $this->handshakeResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'Handshake failed.');

        $coroutineId = Coroutine::create(static function () use ($server, $request, $response): void {
            $server->onHandshake($request, $response);
        });
        Coroutine::join([$coroutineId]);

        $this->assertSame(['opening rejected connection'], $this->capturedLogBodies());
    }

    public function testOpeningContextIncludesDeferredOnOpenTelemetry(): void
    {
        $this->app->make('events')->listen(ConnectionOpening::class, static function (): void {
            logger()->info('connection opening');
        });

        $handler = new SentryWebSocketRuntimeHandler;
        $handler->openCallback = static function (): void {
            logger()->info('connection opened');
        };
        $this->app->instance(SentryWebSocketRuntimeHandler::class, $handler);

        $route = m::mock(Route::class);
        $route->shouldReceive('getControllerClass')->andReturn(SentryWebSocketRuntimeHandler::class);
        $router = m::mock(Router::class);
        $router->shouldReceive('dispatchToCallback')->once()
            ->andReturnUsing(static function (HttpRequest $request) use ($route): Response {
                $request->setRouteResolver(static fn (): Route => $route);

                return new Response('', Response::HTTP_SWITCHING_PROTOCOLS);
            });
        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldReceive('isEstablished')->once()->with(42)->andReturnTrue();
        $server = new SentryWebSocketServer($this->app, $router, $nativeServer);
        $request = $this->handshakeRequest();
        $response = $this->handshakeResponse(Response::HTTP_SWITCHING_PROTOCOLS);

        $coroutineId = Coroutine::create(static function () use ($server, $request, $response): void {
            $server->onHandshake($request, $response);
        });
        Coroutine::join([$coroutineId]);

        $this->assertSame([
            ['connection opening', 'connection opened'],
        ], $this->capturedLogBatches());
    }

    public function testConcurrentMessagesFlushIsolatedTelemetry(): void
    {
        $firstReady = new Channel(1);
        $secondReady = new Channel(1);
        $releaseFirst = new Channel(1);
        $releaseSecond = new Channel(1);
        $handler = new SentryWebSocketRuntimeHandler;
        $handler->messageCallback = static function (Frame $frame) use (
            $firstReady,
            $secondReady,
            $releaseFirst,
            $releaseSecond,
        ): void {
            logger()->info($frame->data . ' log');
            traceMetrics()->count($frame->data . '.metric', 1);

            if ($frame->fd === 1) {
                $firstReady->push(true);
                $releaseFirst->pop();

                return;
            }

            $secondReady->push(true);
            $releaseSecond->pop();
        };
        $this->app->instance(SentryWebSocketRuntimeHandler::class, $handler);
        FdCollector::set(1, SentryWebSocketRuntimeHandler::class);
        FdCollector::set(2, SentryWebSocketRuntimeHandler::class);

        $server = new Server($this->app);
        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $firstCoroutineId = Coroutine::create(static function () use ($server, $nativeServer): void {
            $server->onMessage($nativeServer, self::frame(1, 'first'));
        });
        $secondCoroutineId = Coroutine::create(static function () use ($server, $nativeServer): void {
            $server->onMessage($nativeServer, self::frame(2, 'second'));
        });

        $this->assertTrue($firstReady->pop(1.0));
        $this->assertTrue($secondReady->pop(1.0));

        $releaseFirst->push(true);
        Coroutine::join([$firstCoroutineId]);

        $this->assertSame([['first log']], $this->capturedLogBatches());
        $this->assertSame([['first.metric']], $this->capturedMetricBatches());

        $releaseSecond->push(true);
        Coroutine::join([$secondCoroutineId]);

        $this->assertSame([['first log'], ['second log']], $this->capturedLogBatches());
        $this->assertSame([['first.metric'], ['second.metric']], $this->capturedMetricBatches());
    }

    public function testClosingContextFlushesAfterHandlerAndCompletionEvent(): void
    {
        $handler = new SentryWebSocketRuntimeHandler;
        $handler->closeCallback = static function (): void {
            logger()->info('connection close handler');
        };
        $this->app->instance(SentryWebSocketRuntimeHandler::class, $handler);
        $this->app->make('events')->listen(ConnectionClosed::class, static function (): void {
            logger()->info('connection closed');
        });
        FdCollector::set(42, SentryWebSocketRuntimeHandler::class);

        $server = new Server($this->app);
        $nativeServer = m::mock(SwooleServer::class);
        $coroutineId = Coroutine::create(static function () use ($server, $nativeServer): void {
            $server->onClose($nativeServer, 42, 0);
        });
        Coroutine::join([$coroutineId]);

        $this->assertSame([
            ['connection close handler', 'connection closed'],
        ], $this->capturedLogBatches());
    }

    /**
     * Create a native handshake request.
     */
    private function handshakeRequest(bool $validSecurityKey = true): SwooleRequest
    {
        $request = m::mock(SwooleRequest::class);
        $request->fd = 42;
        $request->server = [
            'request_method' => 'get',
            'request_uri' => '/socket',
        ];
        $request->header = [
            'host' => 'example.com',
            Security::SEC_WEBSOCKET_KEY => $validSecurityKey ? 'dGhlIHNhbXBsZSBub25jZQ==' : 'invalid',
        ];
        $request->get = [];
        $request->post = [];
        $request->cookie = [];
        $request->files = [];
        $request->shouldReceive('rawContent')->once()->andReturnFalse();

        return $request;
    }

    /**
     * Create a native handshake response.
     */
    private function handshakeResponse(int $status, string $content = ''): SwooleResponse
    {
        $response = m::mock(SwooleResponse::class);
        $response->shouldReceive('status')->once()->with($status)->andReturnTrue();
        $response->shouldReceive('header')->zeroOrMoreTimes()->andReturnTrue();
        $response->shouldReceive('end')->once()->with($content)->andReturnTrue();

        return $response;
    }

    /**
     * Create a WebSocket frame.
     */
    private static function frame(int $fd, string $data): Frame
    {
        $frame = new Frame;
        $frame->fd = $fd;
        $frame->data = $data;

        return $frame;
    }

    /**
     * Return captured log bodies grouped by envelope.
     *
     * @return list<list<string>>
     */
    private function capturedLogBatches(): array
    {
        return array_map(
            static fn (array $event): array => array_map(
                static fn (Log $log): string => $log->getBody(),
                $event[0]->getLogs(),
            ),
            $this->getCapturedSentryEventsOfType(EventType::logs()),
        );
    }

    /**
     * Return captured log bodies in flush order.
     *
     * @return list<string>
     */
    private function capturedLogBodies(): array
    {
        return array_merge(...$this->capturedLogBatches());
    }

    /**
     * Return captured metric names grouped by envelope.
     *
     * @return list<list<string>>
     */
    private function capturedMetricBatches(): array
    {
        return array_map(
            static fn (array $event): array => array_map(
                static fn (Metric $metric): string => $metric->getName(),
                $event[0]->getMetrics(),
            ),
            $this->getCapturedSentryEventsOfType(EventType::metrics()),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        CoordinatorManager::until(Constants::WORKER_START)->resume();
    }

    protected function tearDown(): void
    {
        FdCollector::flushState();
        WebSocketContext::flushState();

        parent::tearDown();
    }
}

class SentryWebSocketServer extends Server
{
    public function __construct(
        Container $container,
        private readonly Router $router,
        private readonly SwooleWebSocketServer $nativeServer,
    ) {
        parent::__construct($container);
    }

    /**
     * Get the native WebSocket server.
     */
    public function getServer(): SwooleWebSocketServer
    {
        return $this->nativeServer;
    }

    /**
     * Get the test router.
     */
    protected function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get the test connection identifier.
     */
    protected function getFd(SwooleResponse $response): int
    {
        return 42;
    }

    /**
     * Render a failed handshake.
     */
    protected function handleException(Throwable $throwable): Response
    {
        return new Response('Handshake failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

class SentryWebSocketRuntimeHandler implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    public ?Closure $openCallback = null;

    public ?Closure $messageCallback = null;

    public ?Closure $closeCallback = null;

    /**
     * Handle a new WebSocket connection.
     */
    public function onOpen(SwooleWebSocketServer $server, SwooleRequest $request): void
    {
        ($this->openCallback)($server, $request);
    }

    /**
     * Handle an incoming WebSocket message.
     */
    public function onMessage(SwooleWebSocketServer $server, Frame $frame): void
    {
        ($this->messageCallback)($frame);
    }

    /**
     * Handle a WebSocket connection close.
     */
    public function onClose(SwooleServer $server, int $fd, int $reactorId): void
    {
        ($this->closeCallback)($fd, $reactorId);
    }
}
