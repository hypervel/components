<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketMessageStub;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketStub;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketThrowingStub;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use Hypervel\WebSocketServer\Events\ConnectionClosed;
use Hypervel\WebSocketServer\Events\ConnectionClosing;
use Hypervel\WebSocketServer\Events\ConnectionOpened;
use Hypervel\WebSocketServer\Events\MessageHandled;
use Hypervel\WebSocketServer\Events\MessageReceived;
use Hypervel\WebSocketServer\Exceptions\Handler\WebSocketExceptionHandler;
use Hypervel\WebSocketServer\Server;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketSwooleServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ServerTest extends TestCase
{
    protected function tearDown(): void
    {
        WebSocketMessageStub::flushState();
        WebSocketStub::$coroutineId = 0;

        parent::tearDown();
    }

    /**
     * Verify that deferOnOpen defers the onOpen call so it runs after
     * the calling coroutine exits, and that onOpen is invoked on
     * OnOpenInterface implementors.
     */
    public function testDeferOnOpenCallsOnOpen(): void
    {
        WebSocketStub::$coroutineId = 0;

        $container = $this->createContainer();
        $server = new Server($container);

        $invoker = new ClassInvoker($server);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        // Run deferOnOpen inside a child coroutine so that defer() fires
        // when that coroutine exits, before we make our assertions.
        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1);
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertNotSame(0, WebSocketStub::$coroutineId, 'onOpen should have been called');
        $this->assertNotSame(Coroutine::id(), WebSocketStub::$coroutineId, 'onOpen should run in a different coroutine');
    }

    public function testDeferOnOpenLogsExceptionFromOnOpen(): void
    {
        $logged = false;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once()->with(m::on(
            function (string $message) use (&$logged): bool {
                $logged = str_contains($message, 'onOpen failed');

                return $logged;
            }
        ));

        $container = $this->createContainer($logger);
        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketThrowingStub, $swooleServer, 1);
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertTrue($logged);
    }

    public function testConnectionOpenedEventIsDispatched(): void
    {
        $dispatched = false;

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionOpened::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            function (ConnectionOpened $event) use (&$dispatched) {
                $dispatched = ($event->fd === 1 && $event->server === 'websocket');
                return $dispatched;
            }
        ));

        $container = $this->createContainer(dispatcher: $dispatcher);
        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1);
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertTrue($dispatched);
    }

    public function testConnectionOpenedEventNotDispatchedWithoutListeners(): void
    {
        $hasListenersChecked = false;

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionOpened::class)->andReturnUsing(
            function () use (&$hasListenersChecked): bool {
                $hasListenersChecked = true;

                return false;
            }
        );
        $dispatcher->shouldNotReceive('dispatch');

        $container = $this->createContainer(dispatcher: $dispatcher);
        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1);
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertTrue($hasListenersChecked);
    }

    public function testConnectionOpenedEventDispatchedEvenWhenOnOpenThrows(): void
    {
        $dispatched = false;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once();

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionOpened::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            function (ConnectionOpened $event) use (&$dispatched) {
                $dispatched = ($event->fd === 1);
                return $dispatched;
            }
        ));

        $container = $this->createContainer($logger, $dispatcher);
        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketThrowingStub, $swooleServer, 1);
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertTrue($dispatched);
    }

    public function testOnOpenRunsWhenConnectionOpenedEventThrows(): void
    {
        WebSocketStub::$coroutineId = 0;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once()->with(m::type('string'));

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionOpened::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('event failed'));

        $server = new Server($this->createContainer($logger, $dispatcher));
        $invoker = new ClassInvoker($server);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1);
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertNotSame(0, WebSocketStub::$coroutineId);
    }

    public function testMessageLifecycleEventsAreDispatchedInOrder(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (MessageReceived $event) => $event->fd === 1 && $event->server === 'websocket'
        ))->ordered();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageHandled::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (MessageHandled $event) => WebSocketMessageStub::$messageHandled
                && $event->fd === 1
                && $event->frame->data === 'test'
                && $event->server === 'websocket'
                && $event->exception === null
        ))->ordered();

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = 'test';

        $server->onMessage($swooleServer, $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageReceivedEventNotDispatchedWithoutListeners(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnFalse();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageHandled::class)->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = m::mock(WebSocketSwooleServer::class);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = 'test';

        $server->onMessage($swooleServer, $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandlerRunsWhenMessageReceivedEventThrows(): void
    {
        $exception = new RuntimeException('event failed');

        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')->once()->with($exception);

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(MessageReceived::class))->andThrow($exception);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageHandled::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (MessageHandled $event) => WebSocketMessageStub::$messageHandled
                && $event->exception === $exception
        ));

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageReceivedCancellationSkipsHandlerAndCompletionEvent(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(MessageReceived::class))->andThrow(new CanceledException);
        $dispatcher->shouldNotReceive('hasListeners')->with(MessageHandled::class);

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertFalse(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandlerFailuresAreReportedWithoutEscaping(): void
    {
        $exception = new RuntimeException('message failed');
        WebSocketMessageStub::$messageException = $exception;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldNotReceive('error');

        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')->once()->with($exception);

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnFalse();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageHandled::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (MessageHandled $event) => $event->exception === $exception
        ));

        $container = $this->createContainer($logger, $dispatcher, $exceptionHandler);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandledKeepsTheFirstFailure(): void
    {
        $receivedException = new RuntimeException('event failed');
        $messageException = new RuntimeException('message failed');
        WebSocketMessageStub::$messageException = $messageException;

        $reported = [];
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')->twice()->andReturnUsing(
            function (Throwable $throwable) use (&$reported): void {
                $reported[] = $throwable;
            }
        );

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(MessageReceived::class))->andThrow($receivedException);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageHandled::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (MessageHandled $event) => $event->exception === $receivedException
        ));

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertSame([$receivedException, $messageException], $reported);
    }

    public function testMessageHandlerCancellationIsContained(): void
    {
        WebSocketMessageStub::$messageException = new CanceledException;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldNotReceive('error');

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnFalse();
        $dispatcher->shouldNotReceive('hasListeners')->with(MessageHandled::class);

        $container = $this->createContainer($logger, $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandledFailureIsReportedWithoutEscaping(): void
    {
        $exception = new RuntimeException('completion failed');

        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')->once()->with($exception);

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnFalse();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageHandled::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(MessageHandled::class))->andThrow($exception);

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandledCancellationIsContained(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldNotReceive('error');

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageReceived::class)->andReturnFalse();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MessageHandled::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(MessageHandled::class))->andThrow(new CanceledException);

        $container = $this->createContainer($logger, $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testConnectionLifecycleEventsAreDispatchedAroundTheCloseHandler(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (ConnectionClosing $event) => ! WebSocketMessageStub::$closeHandled
                && $event->fd === 1
                && $event->reactorId === 0
                && $event->server === 'websocket'
        ))->ordered();
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (ConnectionClosed $event) => WebSocketMessageStub::$closeHandled
                && $event->fd === 1
                && $event->reactorId === 0
                && $event->server === 'websocket'
        ))->ordered();

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = m::mock(SwooleServer::class);

        $server->onClose($swooleServer, 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
    }

    public function testConnectionClosedEventNotDispatchedWithoutListeners(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosing::class)->andReturnFalse();
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = m::mock(SwooleServer::class);

        $server->onClose($swooleServer, 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
    }

    public function testConnectionClosingFailureDoesNotSkipCloseCallbacksOrCleanup(): void
    {
        $exception = new RuntimeException('closing event failed');
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')->once()->with($exception);
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ConnectionClosing::class))
            ->andThrow($exception);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(ConnectionClosed::class));
        $container = $this->createContainer(
            dispatcher: $dispatcher,
            exceptionHandler: $exceptionHandler,
        );
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container))->onClose(m::mock(SwooleServer::class), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testConnectionClosingCancellationSkipsCloseCallbacksAndStillCleansUp(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ConnectionClosing::class))
            ->andThrow(new CanceledException);
        $dispatcher->shouldNotReceive('hasListeners')->with(ConnectionClosed::class);
        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldNotReceive('make')->with(WebSocketMessageStub::class);
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container))->onClose(m::mock(SwooleServer::class), 1, 0);

        $this->assertFalse(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseWithoutCollectorStillReleasesConnectionContext(): void
    {
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');

        (new Server($this->createContainer()))->onClose(m::mock(SwooleServer::class), 1, 0);

        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseHandlerFailureDoesNotSkipEventOrCleanup(): void
    {
        WebSocketMessageStub::$closeException = new RuntimeException('close failed');

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once()->with(m::type('string'));

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once();

        $container = $this->createContainer($logger, $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container))->onClose(m::mock(SwooleServer::class), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseEventFailureDoesNotSkipCleanup(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once()->with(m::type('string'));

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('event failed'));

        $container = $this->createContainer($logger, $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container))->onClose(m::mock(SwooleServer::class), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseCancellationIsContainedAfterCleanup(): void
    {
        WebSocketMessageStub::$closeException = new CanceledException;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldNotReceive('error');

        $container = $this->createContainer($logger);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container))->onClose(m::mock(SwooleServer::class), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testExceptionHandlerResponseSuppressesHandshakeException(): void
    {
        $original = new RuntimeException('handshake failed');
        $response = new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
        $handler = m::mock(WebSocketExceptionHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->with($original, m::type(Response::class))
            ->andReturn($response);

        $container = $this->createContainer();
        $container->shouldReceive('make')
            ->once()
            ->with(WebSocketExceptionHandler::class)
            ->andReturn($handler);

        $this->assertSame(
            $response,
            (new ClassInvoker(new Server($container)))->handleException($original)
        );
    }

    public function testExceptionHandlerFailureRetainsHandshakeException(): void
    {
        $original = new RuntimeException('handshake failed');
        $handlingFailure = new RuntimeException('exception handler failed');
        $handler = m::mock(WebSocketExceptionHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->with($original, m::type(Response::class))
            ->andThrow($handlingFailure);

        $container = $this->createContainer();
        $container->shouldReceive('make')
            ->once()
            ->with(WebSocketExceptionHandler::class)
            ->andReturn($handler);

        try {
            (new ClassInvoker(new Server($container)))->handleException($original);
            $this->fail('Expected exception handling to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($handlingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    /**
     * Wait for a child coroutine and its deferred callbacks to finish.
     */
    private function waitForCoroutine(int $coroutineId, float $timeout = 1.0): void
    {
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);

        while (Coroutine::exists($coroutineId)) {
            if (hrtime(true) >= $deadline) {
                $this->fail("Coroutine {$coroutineId} did not finish within {$timeout} seconds.");
            }

            usleep(1_000);
        }
    }

    /**
     * Create a container mock with logger and optional event dispatcher.
     */
    protected function createContainer(
        ?StdoutLoggerInterface $logger = null,
        ?EventDispatcherContract $dispatcher = null,
        ?ExceptionHandlerContract $exceptionHandler = null,
    ): Container&\Mockery\MockInterface {
        $logger ??= m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn($logger);

        if ($exceptionHandler !== null) {
            $container->shouldReceive('make')
                ->with(ExceptionHandlerContract::class)
                ->andReturn($exceptionHandler);
        }

        if ($dispatcher) {
            $dispatcher->shouldReceive('hasListeners')
                ->with(ConnectionClosing::class)
                ->andReturnFalse()
                ->byDefault();
            $container->shouldReceive('bound')->with('events')->andReturnTrue();
            $container->shouldReceive('make')->with('events')->andReturn($dispatcher);
        } else {
            $container->shouldReceive('bound')->with('events')->andReturnFalse();
        }

        return $container;
    }

    // REMOVED: testEngineServer — Tests FooServer::getServer() which accepts Swow\Http\Server\Connection in a type union. Pure Swow/coroutine-server test, not applicable to Swoole-only mode.
}
