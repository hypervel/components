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
use Hypervel\HttpServer\Events\RequestCanceled;
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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;
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
    use VerifiesDoubles;

    #[DataProvider('acceptedTerminationOutcomes')]
    public function testAcceptedHandshakeTerminatesAfterOpenAndDrainsOnce(string $outcome, array $expected): void
    {
        $calls = [];
        $canceled = [];
        $exception = match ($outcome) {
            'canceled' => new CanceledException,
            'wrapped' => new RuntimeException('termination cleanup failed', previous: new CanceledException),
            default => new RuntimeException('termination failed'),
        };
        $events = new Dispatcher;
        $events->listen(RequestReceived::class, function () use (&$calls): void {
            defer(function () use (&$calls): void { $calls[] = 'handshake deferred'; });
        });
        $events->listen(RequestCanceled::class, function (RequestCanceled $event) use (&$calls, &$canceled): void {
            $canceled[] = $event;
            $calls[] = 'request canceled';
        });
        $container = $this->container($events);
        $reporter = Double::for(ExceptionHandler::class)->strict();
        if ($outcome === 'failed' || $outcome === 'wrapped') {
            $reporter->expects('report')->with(Argument::same($exception));
        } else {
            $reporter->expects('report')->never();
        }
        $container->allows('make')->with(ExceptionHandler::class)->returns($reporter);
        $container->allows('make')->with(Security::class)->returns(new Security);
        $handler = Double::for(OnOpenInterface::class)->strict();
        $handler->expects('onOpen')->resolves(function () use (&$calls): void {
            $calls[] = 'open';
            defer(function () use (&$calls): void { $calls[] = 'open deferred'; });
        });
        $container->allows('make')->with(WebSocketStub::class)->returns($handler);
        $middleware = new HandshakeTerminationStub(function () use (&$calls, $outcome, $exception): void {
            $calls[] = FdCollector::get(42);
            $calls[] = 'terminated';
            defer(function () use (&$calls): void { $calls[] = 'termination deferred'; })->always();
            if ($outcome !== 'success') {
                throw $exception;
            }
        });
        $container->allows('make')->with(HandshakeTerminationStub::class)->returns($middleware);
        $native = Double::for(SwooleWebSocketServer::class)->strict();
        $native->expects('isEstablished')->with(42)->returns(true);
        $server = new HandshakeLifecycleServer($container->instance(), $this->router(101, [HandshakeTerminationStub::class . ':value']), $native);
        $response = $this->response(101, onEnd: function () use (&$calls): bool {
            $calls[] = 'sent';
            return true;
        });

        (new Waiter(1))->wait(fn () => $server->onHandshake($this->request(), $response));

        $this->assertSame(['sent', 'open', WebSocketStub::class, 'terminated', ...$expected], $calls);

        foreach ($canceled as $event) {
            $this->assertSame($exception, $event->cancellation);
            $this->assertSame(101, $event->response?->getStatusCode());
        }
    }

    /**
     * Supply termination outcomes after the connection opens.
     */
    public static function acceptedTerminationOutcomes(): array
    {
        return [
            ['success', ['handshake deferred', 'open deferred', 'termination deferred']],
            ['failed', ['termination deferred']],
            ['canceled', ['request canceled']],
            ['wrapped', ['request canceled']],
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
        $cancellations = [];
        $events->listen(RequestCanceled::class, function (RequestCanceled $event) use (&$calls, &$cancellations): void {
            $cancellations[] = $event->cancellation;
            $calls[] = 'request canceled';
        });
        $container = $this->container($events);
        $container->allows('make')->with(Security::class)->returns(new Security);
        $container->allows('make')->with(HandshakeTerminationStub::class)->returns(
            new HandshakeTerminationStub(function () use (&$calls, $outcome, $exception): void {
                $calls[] = 'first';
                if ($outcome !== 'success') {
                    throw $exception;
                }
            }),
        );
        $container->allows('make')->with('second.middleware')->returns(
            new HandshakeTerminationStub(function () use (&$calls): void { $calls[] = 'second'; }),
        );
        $server = new HandshakeLifecycleServer(
            $container->instance(),
            $this->router($status, [HandshakeTerminationStub::class, 'second.middleware']),
            Double::for(SwooleWebSocketServer::class)->strict(),
        );
        $caught = null;

        try {
            $server->onHandshake($this->request(), $this->response($status, $status === 403 ? 'Forbidden' : ''));
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($outcome === 'success' ? null : $exception, $caught);
        $this->assertSame($expected, $calls);
        $this->assertSame($outcome === 'canceled' ? [$exception] : [], $cancellations);
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
            [302, 'canceled', ['first', 'request canceled']],
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
        $container->expects('make')->with(SafeCaller::class)->returns(new SafeCaller($container->instance()));
        $handler = Double::for(WebSocketExceptionHandler::class)->strict();
        $handler->expects('handle')->with(Argument::same($exception), Argument::type(Response::class))->returns(new Response('', 302));
        $container->expects('make')->with(WebSocketExceptionHandler::class)->returns($handler);
        $server = new HandshakeLifecycleServer($container->instance(), Double::for(Router::class)->strict(), Double::for(SwooleWebSocketServer::class)->strict());

        $server->onHandshake($this->request(), $this->response(302));

        $this->assertSame(['deferred'], $calls);
    }

    public function testDisabledHandshakeMiddlewareIsNotTerminated(): void
    {
        $container = $this->container(middlewareDisabled: true);
        $container->allows('make')->with('middleware.disable')->returns(true);
        $container->allows('make')->with(Security::class)->returns(new Security);
        $container->expects('make')->never()->with(HandshakeTerminationStub::class);
        $server = new HandshakeLifecycleServer(
            $container->instance(),
            $this->router(403, [HandshakeTerminationStub::class]),
            Double::for(SwooleWebSocketServer::class)->strict(),
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
        $container->expects('make')->with(Security::class)->returns(new Security);
        $container->expects('make')->with(WebSocketStub::class)->returns(new WebSocketStub);
        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->with(42)->returns(true);
        $response = $this->response(
            Response::HTTP_SWITCHING_PROTOCOLS,
            onEnd: function () use (&$order): bool {
                $order[] = 'send';

                return true;
            },
        );

        (new HandshakeLifecycleServer(
            $container->instance(),
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
        $container->expects('make')->with(Security::class)->returns(new Security);
        $container->expects('make')->with(WebSocketStub::class)->returns(new WebSocketStub);

        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->with(42)->resolves(function (): bool {
            $this->assertNull(FdCollector::get(42));

            return true;
        });

        $server = new HandshakeLifecycleServer(
            $container->instance(),
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
        $container->expects('make')->with(Security::class)->returns(new Security);
        $container->expects('make')->with(WebSocketStub::class)->returns(new WebSocketStub);

        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->never();

        $response = $this->response(Response::HTTP_SWITCHING_PROTOCOLS, endResult: false);

        try {
            (new HandshakeLifecycleServer(
                $container->instance(),
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
        $container->expects('make')->with(Security::class)->returns(new Security);

        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->never();

        (new HandshakeLifecycleServer(
            $container->instance(),
            $this->router(Response::HTTP_FORBIDDEN),
            $nativeServer,
        ))->onHandshake($this->request(), $this->response(Response::HTTP_FORBIDDEN, 'Forbidden'));

        $this->assertNull(FdCollector::get(42));
        $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
    }

    public function testUnsupportedWebSocketVersionIsRejectedBeforeRouting(): void
    {
        $sentEvent = null;
        $events = new Dispatcher;
        $events->listen(ResponseSent::class, function (ResponseSent $event) use (&$sentEvent): void {
            $sentEvent = $event;
        });
        $container = $this->container($events);
        $container->expects('make')->with(Security::class)->returns(new Security);
        $container->expects('make')->with(SafeCaller::class)->returns(new SafeCaller($container->instance()));
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->allows('warning');
        $container->expects('make')->with(WebSocketExceptionHandler::class)->returns(new WebSocketExceptionHandler($logger));
        $router = Double::for(Router::class)->strict();
        $router->expects('dispatchToCallback')->never();
        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->never();

        (new HandshakeLifecycleServer($container->instance(), $router, $nativeServer))->onHandshake(
            $this->request(version: '8'),
            $this->response(Response::HTTP_UPGRADE_REQUIRED, 'sec-websocket-version is unsupported!'),
        );

        $this->assertInstanceOf(ResponseSent::class, $sentEvent);
        $this->assertSame('websocket', $sentEvent->response->headers->get('Upgrade'));
        $this->assertSame('Upgrade', $sentEvent->response->headers->get('Connection'));
        $this->assertSame(Security::VERSION, $sentEvent->response->headers->get('Sec-WebSocket-Version'));
        $this->assertNull(FdCollector::get(42));
        $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
    }

    public function testConnectionClosedDuringEmissionIsNotPublishedAfterward(): void
    {
        $container = $this->container();
        $container->expects('make')->with(Security::class)->returns(new Security);
        $container->expects('make')->with(WebSocketStub::class)->returns(new WebSocketStub);

        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->with(42)->resolves(function (): bool {
            $this->assertNull(FdCollector::get(42));

            return false;
        });

        (new HandshakeLifecycleServer(
            $container->instance(),
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
        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class, RequestCanceled::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event::class;
            });
        }
        $container = $this->container($events);
        $container->expects('make')->with(Security::class)->returns(new Security);

        $router = Double::for(Router::class)->strict();
        $router->expects('dispatchToCallback')
            ->resolves(function (): never {
                WebSocketContext::set('middleware.value', 'preserved');

                throw new CanceledException;
            });

        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->never();

        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('status')->never();
        $response->expects('header')->never();
        $response->expects('end')->never();

        try {
            (new HandshakeLifecycleServer($container->instance(), $router, $nativeServer))
                ->onHandshake($this->request(), $response);
            $this->fail('Expected handshake cancellation to be rethrown.');
        } catch (CanceledException) {
            $this->assertSame([RequestReceived::class, RequestCanceled::class], $dispatchedEvents);
            $this->assertNull(FdCollector::get(42));
            $this->assertArrayNotHasKey(42, WebSocketContext::getStorage());
        }
    }

    public function testHandshakeCancellationReportsTheHeldFailure(): void
    {
        $heldFailure = new RuntimeException('Handled listener failed.');
        $cancellation = new CanceledException;
        $canceled = null;
        $events = new Dispatcher;
        $events->listen(RequestHandled::class, static fn () => throw $heldFailure);
        $events->listen(ResponseSent::class, static fn () => throw $cancellation);
        $events->listen(RequestCanceled::class, static function (RequestCanceled $event) use (&$canceled): void {
            $canceled = $event;
        });
        $container = $this->container($events);
        $container->allows('make')->with(Security::class)->returns(new Security);
        $reporter = Double::for(ExceptionHandler::class)->strict();
        $reporter->expects('report')->with(Argument::same($heldFailure));
        $reportingContainer = new BaseContainer;
        $reportingContainer->instance(ExceptionHandler::class, $reporter);
        BaseContainer::setInstance($reportingContainer);
        $server = new HandshakeLifecycleServer($container->instance(), $this->router(302), Double::for(SwooleWebSocketServer::class)->strict());

        try {
            $server->onHandshake($this->request(), $this->response(302));
            $this->fail('Expected handshake cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame($heldFailure, $canceled->exception);
        $this->assertSame(302, $canceled->response?->getStatusCode());
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
        $container->expects('make')->never()->with(Security::class);
        $container->expects('make')->with(SafeCaller::class)
            ->returns(new SafeCaller($container->instance()));
        $router = Double::for(Router::class)->strict();
        $router->expects('dispatchToCallback')->never();
        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->never();

        (new RenderingHandshakeLifecycleServer(
            $container->instance(),
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
        $container->expects('make')->never()->with(Security::class);
        $router = Double::for(Router::class)->strict();
        $router->expects('dispatchToCallback')->never();
        $nativeServer = Double::for(SwooleWebSocketServer::class)->strict();
        $nativeServer->expects('isEstablished')->never();
        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('status')->never();
        $response->expects('header')->never();
        $response->expects('end')->never();

        try {
            (new HandshakeLifecycleServer($container->instance(), $router, $nativeServer))
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
    protected function container(?Dispatcher $events = null, bool $middlewareDisabled = false): OverriddenDouble
    {
        $container = Double::for(Container::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->allows('debug');
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->expects('bound')->with('events')->returns($events !== null);
        $container->allows('bound')->with('middleware.disable')->returns($middlewareDisabled);

        if ($events !== null) {
            $container->expects('make')->with('events')->returns($events);
        }

        return $container;
    }

    /**
     * Create a router returning the requested handshake status.
     */
    protected function router(int $status, array $middleware = []): Router
    {
        $route = Double::for(Route::class)->strict();
        $route->allows('getControllerClass')->returns(WebSocketStub::class);

        $router = Double::for(Router::class)->strict();
        $router->allows('gatherRouteMiddleware')->with(Argument::same($route))->returns($middleware);
        $router->expects('dispatchToCallback')
            ->with(Argument::type(HttpRequest::class), Argument::type(Closure::class))
            ->resolves(function (HttpRequest $request) use ($route, $status): Response {
                WebSocketContext::set('middleware.value', 'preserved');
                $request->setRouteResolver(static fn (): Route => $route);

                return new Response($status === Response::HTTP_FORBIDDEN ? 'Forbidden' : '', $status);
            });

        return $router;
    }

    /**
     * Create a native handshake request.
     */
    protected function request(string $version = Security::VERSION): SwooleRequest
    {
        $request = Double::for(SwooleRequest::class)->strict();
        $request->fd = 42;
        $request->server = [
            'request_method' => 'get',
            'request_uri' => '/socket',
        ];
        $request->header = [
            'host' => 'example.com',
            Security::SEC_WEBSOCKET_KEY => 'dGhlIHNhbXBsZSBub25jZQ==',
            Security::SEC_WEBSOCKET_VERSION => $version,
        ];
        $request->get = [];
        $request->post = [];
        $request->cookie = [];
        $request->files = [];
        $request->expects('rawContent')->returns(false);

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
        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('status')->with($status)->returns(true);
        $response->allows('header')->returns(true);
        $expectation = $response->expects('end')->with($content);

        $onEnd === null
            ? $expectation->returns($endResult)
            : $expectation->resolves($onEnd);

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
