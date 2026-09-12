<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Closure;
use Hypervel\Container\Container as BaseContainer;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Waiter;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketStub;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use Hypervel\WebSocketServer\Events\ConnectionOpening;
use Hypervel\WebSocketServer\Exceptions\Handler\WebSocketExceptionHandler;
use Hypervel\WebSocketServer\Security;
use Hypervel\WebSocketServer\Server;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Server as SwooleWebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Hypervel\Support\defer;

class ServerHandshakeTest extends TestCase
{
    #[DataProvider('acceptedTerminationOutcomes')]
    public function testAcceptedHandshakeTerminatesAfterOpenAndDrainsOnce(string $outcome, array $expected): void
    {
        $calls = [];
        $exception = $outcome === 'canceled' ? new CanceledException : new RuntimeException('termination failed');
        $events = new Dispatcher;
        $events->listen(RequestReceived::class, function () use (&$calls): void {
            defer(function () use (&$calls): void { $calls[] = 'handshake deferred'; });
        });
        $container = $this->container($events);
        $reporter = m::mock(ExceptionHandler::class);
        if ($outcome === 'failed') {
            $reporter->expects('report')->with($exception);
        } else {
            $reporter->shouldNotReceive('report');
        }
        $container->shouldReceive('make')->with(ExceptionHandler::class)->andReturn($reporter);
        $container->shouldReceive('make')->with(Security::class)->andReturn(new Security);
        $handler = m::mock(OnOpenInterface::class);
        $handler->expects('onOpen')->andReturnUsing(function () use (&$calls): void {
            $calls[] = 'open';
            defer(function () use (&$calls): void { $calls[] = 'open deferred'; });
        });
        $container->shouldReceive('make')->with(WebSocketStub::class)->andReturn($handler);
        $middleware = new HandshakeTerminationStub(function () use (&$calls, $outcome, $exception): void {
            $calls[] = FdCollector::get(42);
            $calls[] = 'terminated';
            defer(function () use (&$calls): void { $calls[] = 'termination deferred'; })->always();
            if ($outcome !== 'success') {
                throw $exception;
            }
        });
        $container->shouldReceive('make')->with(HandshakeTerminationStub::class)->andReturn($middleware);
        $native = m::mock(SwooleWebSocketServer::class);
        $native->expects('isEstablished')->with(42)->andReturnTrue();
        $server = new HandshakeLifecycleServer($container, $this->router(101, [HandshakeTerminationStub::class . ':value']), $native);
        $response = $this->response(101, onEnd: function () use (&$calls): bool {
            $calls[] = 'sent';
            return true;
        });

        (new Waiter(1))->wait(fn () => $server->onHandshake($this->request(), $response));

        $this->assertSame(['sent', 'open', WebSocketStub::class, 'terminated', ...$expected], $calls);
    }

    /**
     * Supply termination outcomes after the connection opens.
     */
    public static function acceptedTerminationOutcomes(): array
    {
        return [
            ['success', ['handshake deferred', 'open deferred', 'termination deferred']],
            ['failed', ['termination deferred']],
            ['canceled', []],
        ];
    }

    #[DataProvider('uncommittedTerminationOutcomes')]
    public function testUncommittedHandshakeTerminatesBeforeDrainingAndReleasesContext(int $status, string $outcome, array $expected): void
    {
        $calls = [];
        $exception = $outcome === 'canceled' ? new CanceledException : new RuntimeException('termination failed');
        $events = new Dispatcher;
        $events->listen(RequestReceived::class, function () use (&$calls): void {
            defer(function () use (&$calls): void { $calls[] = 'normal'; });
            defer(function () use (&$calls): void { $calls[] = WebSocketContext::get('middleware.value'); })->always();
        });
        $container = $this->container($events);
        $container->shouldReceive('make')->with(Security::class)->andReturn(new Security);
        $container->shouldReceive('make')->with(HandshakeTerminationStub::class)->andReturn(
            new HandshakeTerminationStub(function () use (&$calls, $outcome, $exception): void {
                $calls[] = 'first';
                if ($outcome !== 'success') {
                    throw $exception;
                }
            }),
        );
        $container->shouldReceive('make')->with('second.middleware')->andReturn(
            new HandshakeTerminationStub(function () use (&$calls): void { $calls[] = 'second'; }),
        );
        $server = new HandshakeLifecycleServer(
            $container,
            $this->router($status, [HandshakeTerminationStub::class, 'second.middleware']),
            m::mock(SwooleWebSocketServer::class),
        );
        $caught = null;

        try {
            $server->onHandshake($this->request(), $this->response($status, $status === 403 ? 'Forbidden' : ''));
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($outcome === 'success' ? null : $exception, $caught);
        $this->assertSame($expected, $calls);
        $this->assertNull(FdCollector::get(42));
        $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
    }

    /**
     * Supply response statuses and termination outcomes without an upgrade.
     */
    public static function uncommittedTerminationOutcomes(): array
    {
        return [
            [302, 'success', ['first', 'second', 'normal', 'preserved']],
            [302, 'failed', ['first', 'second', 'preserved']],
            [302, 'canceled', ['first']],
            [403, 'success', ['first', 'second', 'preserved']],
        ];
    }

    public function testHandledHandshakeExceptionUsesTheRenderedStatusForDeferredWork(): void
    {
        $calls = [];
        $exception = new RuntimeException('rendered as a redirect');
        $events = new Dispatcher;
        $events->listen(RequestReceived::class, function () use (&$calls, $exception): void {
            defer(function () use (&$calls): void { $calls[] = 'deferred'; });

            throw $exception;
        });
        $container = $this->container($events);
        $container->expects('make')->with(SafeCaller::class)->andReturn(new SafeCaller($container));
        $handler = m::mock(WebSocketExceptionHandler::class);
        $handler->expects('handle')->with($exception, m::type(Response::class))->andReturn(new Response('', 302));
        $container->expects('make')->with(WebSocketExceptionHandler::class)->andReturn($handler);
        $server = new HandshakeLifecycleServer($container, m::mock(Router::class), m::mock(SwooleWebSocketServer::class));

        $server->onHandshake($this->request(), $this->response(302));

        $this->assertSame(['deferred'], $calls);
    }

    public function testDisabledHandshakeMiddlewareIsNotTerminated(): void
    {
        $container = $this->container();
        $container->shouldReceive('bound')->with('middleware.disable')->andReturnTrue();
        $container->shouldReceive('make')->with('middleware.disable')->andReturnTrue();
        $container->shouldReceive('make')->with(Security::class)->andReturn(new Security);
        $container->shouldNotReceive('make')->with(HandshakeTerminationStub::class);
        $server = new HandshakeLifecycleServer(
            $container,
            $this->router(403, [HandshakeTerminationStub::class]),
            m::mock(SwooleWebSocketServer::class),
        );

        $server->onHandshake($this->request(), $this->response(403, 'Forbidden'));

        $this->assertNull(FdCollector::get(42));
    }

    public function testDispatchesHttpLifecycleAroundNativeHandshakeEmission(): void
    {
        $order = [];
        $observedEvents = [];
        $events = new Dispatcher;

        foreach ([ConnectionOpening::class, RequestReceived::class, RequestHandled::class, ResponseSent::class] as $eventClass) {
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
            ConnectionOpening::class,
            RequestReceived::class,
            RequestHandled::class,
            'send',
            ResponseSent::class,
        ], $order);
        $this->assertSame(42, $observedEvents[ConnectionOpening::class]->fd);
        $this->assertInstanceOf(HttpRequest::class, $observedEvents[ConnectionOpening::class]->request);
        $this->assertSame('websocket', $observedEvents[ConnectionOpening::class]->server);
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

    public function testConnectionOpeningFailureIsRenderedAndReleasesContext(): void
    {
        $exception = new RuntimeException('Opening listener failed.');
        $handledEvent = null;
        $events = new Dispatcher;
        $events->listen(ConnectionOpening::class, static function () use ($exception): never {
            throw $exception;
        });
        $events->listen(RequestHandled::class, static function (RequestHandled $event) use (&$handledEvent): void {
            $handledEvent = $event;
        });
        $container = $this->container($events);
        $container->shouldNotReceive('make')->with(Security::class);
        $container->shouldReceive('make')->once()->with(SafeCaller::class)
            ->andReturn(new SafeCaller($container));
        $router = m::mock(Router::class);
        $router->shouldNotReceive('dispatchToCallback');
        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldNotReceive('isEstablished');

        (new RenderingHandshakeLifecycleServer(
            $container,
            $router,
            $nativeServer,
        ))->onHandshake($this->request(), $this->response(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'Handled',
        ));

        $this->assertInstanceOf(RequestHandled::class, $handledEvent);
        $this->assertSame($exception, $handledEvent->exception);
        $this->assertNull(FdCollector::get(42));
        $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
    }

    public function testConnectionOpeningCancellationSkipsFallbackEmissionAndReleasesContext(): void
    {
        $events = new Dispatcher;
        $events->listen(ConnectionOpening::class, static function (): never {
            throw new CanceledException;
        });
        $container = $this->container($events);
        $container->shouldNotReceive('make')->with(Security::class);
        $router = m::mock(Router::class);
        $router->shouldNotReceive('dispatchToCallback');
        $nativeServer = m::mock(SwooleWebSocketServer::class);
        $nativeServer->shouldNotReceive('isEstablished');
        $response = m::mock(SwooleResponse::class);
        $response->shouldReceive('status', 'header', 'end')->never();

        try {
            (new HandshakeLifecycleServer($container, $router, $nativeServer))
                ->onHandshake($this->request(), $response);
            $this->fail('Expected connection opening cancellation to be rethrown.');
        } catch (CanceledException) {
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
        $container->shouldReceive('bound')->with('middleware.disable')->andReturnFalse()->byDefault();

        if ($events !== null) {
            $container->shouldReceive('make')->once()->with('events')->andReturn($events);
        }

        return $container;
    }

    /**
     * Create a router returning the requested handshake status.
     */
    protected function router(int $status, array $middleware = []): Router
    {
        $route = m::mock(Route::class);
        $route->shouldReceive('getControllerClass')->andReturn(WebSocketStub::class);

        $router = m::mock(Router::class);
        $router->shouldReceive('gatherRouteMiddleware')->with($route)->andReturn($middleware);
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
        BaseContainer::getInstance()->scoped(DeferredCallbackCollection::class);
    }
}

class HandshakeTerminationStub
{
    /**
     * Create middleware with an observable termination callback.
     */
    public function __construct(private Closure $callback)
    {
    }

    /**
     * Run the termination callback.
     */
    public function terminate(HttpRequest $request, Response $response): void
    {
        ($this->callback)();
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

class RenderingHandshakeLifecycleServer extends HandshakeLifecycleServer
{
    /**
     * Render the opening-listener failure.
     */
    protected function handleException(Throwable $throwable): Response
    {
        return new Response('Handled', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
