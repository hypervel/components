<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketStub;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use Hypervel\WebSocketServer\Security;
use Hypervel\WebSocketServer\Server;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Server as SwooleWebSocketServer;
use Symfony\Component\HttpFoundation\Response;

class ServerHandshakeTest extends TestCase
{
    public function testDispatchesHttpLifecycleAroundNativeHandshakeEmission(): void
    {
        $order = [];
        $observedEvents = [];
        $events = new Dispatcher;

        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$order, &$observedEvents): void {
                $order[] = $event::class;
                $observedEvents[$event::class] = $event;
            });
        }

        $container = $this->container($events);
        $container->shouldReceive('make')->once()->with(Security::class)->andReturn(new Security);
        $container->shouldReceive('make')->once()->with(WebSocketStub::class)->andReturn(new WebSocketStub);
        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldReceive('isEstablished')->once()->with(42)->andReturnTrue();
        $response = $this->response(
            Response::HTTP_SWITCHING_PROTOCOLS,
            onEnd: function () use (&$order): bool {
                $order[] = 'send';

                return true;
            },
        );

        (new HandshakeLifecycleServer(
            $container,
            $this->router(Response::HTTP_SWITCHING_PROTOCOLS),
            $nativeServer,
        ))->onHandshake($this->request(), $response);

        $this->assertSame([
            RequestReceived::class,
            RequestHandled::class,
            'send',
            ResponseSent::class,
        ], $order);
        $this->assertNull($observedEvents[RequestReceived::class]->response);
        $this->assertSame(Response::HTTP_SWITCHING_PROTOCOLS, $observedEvents[RequestHandled::class]->response->getStatusCode());
        $this->assertSame($observedEvents[RequestHandled::class]->response, $observedEvents[ResponseSent::class]->response);
        $this->assertNull($observedEvents[ResponseSent::class]->exception);
        $this->assertSame('websocket', $observedEvents[ResponseSent::class]->server);
    }

    public function testPublishesConnectionOnlyAfterSuccessfulLiveHandshakeEmission(): void
    {
        $container = $this->container();
        $container->shouldReceive('make')->once()->with(Security::class)->andReturn(new Security);
        $container->shouldReceive('make')->once()->with(WebSocketStub::class)->andReturn(new WebSocketStub);

        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldReceive('isEstablished')->once()->with(42)->andReturnUsing(function (): bool {
            $this->assertNull(FdCollector::get(42));

            return true;
        });

        $server = new HandshakeLifecycleServer(
            $container,
            $this->router(Response::HTTP_SWITCHING_PROTOCOLS),
            $nativeServer,
        );

        $server->onHandshake($this->request(), $this->response(Response::HTTP_SWITCHING_PROTOCOLS));

        $this->assertSame(WebSocketStub::class, FdCollector::get(42));
        $this->assertSame('preserved', WebSocketContext::get('middleware.value', fd: 42));
    }

    public function testEmissionFailureRollsBackUnpublishedConnectionState(): void
    {
        $sentEvent = null;
        $events = new Dispatcher;
        $events->listen(ResponseSent::class, function (ResponseSent $event) use (&$sentEvent): void {
            $sentEvent = $event;
        });
        $container = $this->container($events);
        $container->shouldReceive('make')->once()->with(Security::class)->andReturn(new Security);
        $container->shouldReceive('make')->once()->with(WebSocketStub::class)->andReturn(new WebSocketStub);

        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldNotReceive('isEstablished');

        $response = $this->response(Response::HTTP_SWITCHING_PROTOCOLS, endResult: false);

        try {
            (new HandshakeLifecycleServer(
                $container,
                $this->router(Response::HTTP_SWITCHING_PROTOCOLS),
                $nativeServer,
            ))->onHandshake($this->request(), $response);
            $this->fail('Expected handshake emission failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to complete the response.', $exception->getMessage());
            $this->assertInstanceOf(ResponseSent::class, $sentEvent);
            $this->assertSame($exception, $sentEvent->exception);
            $this->assertNull(FdCollector::get(42));
            $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
        }
    }

    public function testRejectedHandshakeReleasesConnectionContext(): void
    {
        $container = $this->container();
        $container->shouldReceive('make')->once()->with(Security::class)->andReturn(new Security);

        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldNotReceive('isEstablished');

        (new HandshakeLifecycleServer(
            $container,
            $this->router(Response::HTTP_FORBIDDEN),
            $nativeServer,
        ))->onHandshake($this->request(), $this->response(Response::HTTP_FORBIDDEN, 'Forbidden'));

        $this->assertNull(FdCollector::get(42));
        $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
    }

    public function testConnectionClosedDuringEmissionIsNotPublishedAfterward(): void
    {
        $container = $this->container();
        $container->shouldReceive('make')->once()->with(Security::class)->andReturn(new Security);
        $container->shouldReceive('make')->once()->with(WebSocketStub::class)->andReturn(new WebSocketStub);

        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldReceive('isEstablished')->once()->with(42)->andReturnUsing(function (): bool {
            $this->assertNull(FdCollector::get(42));

            return false;
        });

        (new HandshakeLifecycleServer(
            $container,
            $this->router(Response::HTTP_SWITCHING_PROTOCOLS),
            $nativeServer,
        ))->onHandshake($this->request(), $this->response(Response::HTTP_SWITCHING_PROTOCOLS));

        $this->assertNull(FdCollector::get(42));
        $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
    }

    public function testHandshakeCancellationSkipsFallbackEmissionAndReleasesContext(): void
    {
        $dispatchedEvents = [];
        $events = new Dispatcher;
        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event::class;
            });
        }
        $container = $this->container($events);
        $container->shouldReceive('make')->once()->with(Security::class)->andReturn(new Security);

        $router = m::mock(Router::class);
        $router->shouldReceive('dispatchToCallback')->once()
            ->andReturnUsing(function (): never {
                WebSocketContext::set('middleware.value', 'preserved');

                throw new CanceledException;
            });

        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldNotReceive('isEstablished');

        $response = m::mock(SwooleResponse::class);
        $response->shouldReceive('status', 'header', 'end')->never();

        try {
            (new HandshakeLifecycleServer($container, $router, $nativeServer))
                ->onHandshake($this->request(), $response);
            $this->fail('Expected handshake cancellation to be rethrown.');
        } catch (CanceledException) {
            $this->assertSame([RequestReceived::class], $dispatchedEvents);
            $this->assertNull(FdCollector::get(42));
            $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
        }
    }

    /**
     * Create the package container mock.
     */
    protected function container(?Dispatcher $events = null): Container
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with(StdoutLoggerInterface::class)
            ->andReturn(m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing());
        $container->shouldReceive('bound')->once()->with('events')->andReturn($events !== null);

        if ($events !== null) {
            $container->shouldReceive('make')->once()->with('events')->andReturn($events);
        }

        return $container;
    }

    /**
     * Create a router returning the requested handshake status.
     */
    protected function router(int $status): Router
    {
        $route = m::mock(Route::class);
        $route->shouldReceive('getControllerClass')->andReturn(WebSocketStub::class);

        $router = m::mock(Router::class);
        $router->shouldReceive('dispatchToCallback')->once()
            ->with(m::type(HttpRequest::class), m::type(Closure::class))
            ->andReturnUsing(function (HttpRequest $request) use ($route, $status): Response {
                WebSocketContext::set('middleware.value', 'preserved');
                $request->setRouteResolver(static fn (): Route => $route);

                return new Response($status === Response::HTTP_FORBIDDEN ? 'Forbidden' : '', $status);
            });

        return $router;
    }

    /**
     * Create a native handshake request.
     */
    protected function request(): SwooleRequest
    {
        $request = m::mock(SwooleRequest::class);
        $request->fd = 42;
        $request->server = [
            'request_method' => 'get',
            'request_uri' => '/socket',
        ];
        $request->header = [
            'host' => 'example.com',
            Security::SEC_WEBSOCKET_KEY => 'dGhlIHNhbXBsZSBub25jZQ==',
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
    protected function response(
        int $status,
        string $content = '',
        bool $endResult = true,
        ?Closure $onEnd = null,
    ): SwooleResponse {
        $response = m::mock(SwooleResponse::class);
        $response->shouldReceive('status')->once()->with($status)->andReturnTrue();
        $response->shouldReceive('header')->zeroOrMoreTimes()->andReturnTrue();
        $expectation = $response->shouldReceive('end')->once()->with($content);

        $onEnd === null
            ? $expectation->andReturn($endResult)
            : $expectation->andReturnUsing($onEnd);

        return $response;
    }

    protected function setUp(): void
    {
        parent::setUp();

        CoordinatorManager::until(Constants::WORKER_START)->resume();
    }
}

class HandshakeLifecycleServer extends Server
{
    public function __construct(
        Container $container,
        protected Router $router,
        protected SwooleWebSocketServer $nativeServer,
    ) {
        parent::__construct($container);
    }

    /**
     * Get the test router.
     */
    protected function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get the test native server.
     */
    public function getServer(): SwooleWebSocketServer
    {
        return $this->nativeServer;
    }

    /**
     * Get the test connection identifier.
     */
    protected function getFd(SwooleResponse $response): int
    {
        return 42;
    }
}
