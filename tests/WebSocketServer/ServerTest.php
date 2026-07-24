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
use Hypervel\WebSocketServer\Events\ConnectionOpened;
use Hypervel\WebSocketServer\Events\MessageReceived;
use Hypervel\WebSocketServer\Server;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketSwooleServer;

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

    public function testMessageReceivedEventIsDispatched(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(MessageReceived::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (MessageReceived $event) => $event->fd === 1 && $event->server === 'websocket'
        ));

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
        $dispatcher->shouldReceive('hasListeners')->with(MessageReceived::class)->andReturnFalse();
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
        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once()->with(m::type('string'));

        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(MessageReceived::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('event failed'));

        $container = $this->createContainer($logger, $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandlerFailuresAreReportedWithoutEscaping(): void
    {
        $exception = new RuntimeException('message failed');
        WebSocketMessageStub::$messageException = $exception;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldNotReceive('error');

        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')->once()->with($exception);

        $container = $this->createContainer($logger, exceptionHandler: $exceptionHandler);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandlerCancellationIsContained(): void
    {
        WebSocketMessageStub::$messageException = new CanceledException;

        $logger = m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldNotReceive('error');

        $container = $this->createContainer($logger);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container))->onMessage(m::mock(WebSocketSwooleServer::class), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testConnectionClosedEventIsDispatched(): void
    {
        $dispatcher = m::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(
            fn (ConnectionClosed $event) => $event->fd === 1 && $event->reactorId === 0 && $event->server === 'websocket'
        ));

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
            $container->shouldReceive('bound')->with('events')->andReturnTrue();
            $container->shouldReceive('make')->with('events')->andReturn($dispatcher);
        } else {
            $container->shouldReceive('bound')->with('events')->andReturnFalse();
        }

        return $container;
    }

    // REMOVED: testEngineServer — Tests FooServer::getServer() which accepts Swow\Http\Server\Connection in a type union. Pure Swow/coroutine-server test, not applicable to Swoole-only mode.
}
