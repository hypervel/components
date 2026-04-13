<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketMessageStub;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketStub;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketThrowingStub;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Events\ConnectionClosed;
use Hypervel\WebSocketServer\Events\ConnectionOpened;
use Hypervel\WebSocketServer\Events\MessageReceived;
use Hypervel\WebSocketServer\Server;
use Mockery;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketSwooleServer;

/**
 * @internal
 * @coversNothing
 */
class ServerTest extends TestCase
{
    protected function tearDown(): void
    {
        FdCollector::flushState();
        WebSocketMessageStub::flushState();

        parent::tearDown();
    }

    /**
     * Verify that deferOnOpen defers the onOpen call so it runs after
     * the calling coroutine exits, and that onOpen is invoked on
     * OnOpenInterface implementors.
     */
    public function testDeferOnOpenCallsOnOpen()
    {
        WebSocketStub::$coroutineId = 0;

        $container = $this->createContainer();
        $container->shouldReceive('make')->with(WebSocketStub::class)->andReturn(new WebSocketStub);

        $server = new Server($container);

        $invoker = new ClassInvoker($server);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        // Run deferOnOpen inside a child coroutine so that defer() fires
        // when that coroutine exits, before we make our assertions.
        $channel = new \Swoole\Coroutine\Channel(1);
        Coroutine::create(function () use ($invoker, $swooleServer, $channel) {
            $invoker->deferOnOpen(new SwooleRequest, WebSocketStub::class, $swooleServer, 1);
            $channel->push(true);
        });
        $channel->pop();

        // Yield to allow the deferred callback to execute.
        usleep(1000);

        $this->assertNotSame(0, WebSocketStub::$coroutineId, 'onOpen should have been called');
        $this->assertNotSame(Coroutine::id(), WebSocketStub::$coroutineId, 'onOpen should run in a different coroutine');
    }

    public function testDeferOnOpenLogsExceptionFromOnOpen()
    {
        $logger = Mockery::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once()->with(Mockery::on(
            fn (string $message) => str_contains($message, 'onOpen failed')
        ));

        $container = $this->createContainer($logger);
        $container->shouldReceive('make')->with(WebSocketThrowingStub::class)->andReturn(new WebSocketThrowingStub);

        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        $channel = new \Swoole\Coroutine\Channel(1);
        Coroutine::create(function () use ($invoker, $swooleServer, $channel) {
            $invoker->deferOnOpen(new SwooleRequest, WebSocketThrowingStub::class, $swooleServer, 1);
            $channel->push(true);
        });
        $channel->pop();

        // Yield to allow the deferred callback to execute.
        usleep(1000);
    }

    public function testConnectionOpenedEventIsDispatched()
    {
        $dispatched = false;

        $dispatcher = Mockery::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionOpened::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(Mockery::on(
            function (ConnectionOpened $event) use (&$dispatched) {
                $dispatched = ($event->fd === 1 && $event->server === 'websocket');
                return $dispatched;
            }
        ));

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketStub::class)->andReturn(new WebSocketStub);

        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        $channel = new \Swoole\Coroutine\Channel(1);
        Coroutine::create(function () use ($invoker, $swooleServer, $channel) {
            $invoker->deferOnOpen(new SwooleRequest, WebSocketStub::class, $swooleServer, 1);
            $channel->push(true);
        });
        $channel->pop();
        usleep(1000);

        $this->assertTrue($dispatched);
    }

    public function testConnectionOpenedEventNotDispatchedWithoutListeners()
    {
        $dispatcher = Mockery::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionOpened::class)->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketStub::class)->andReturn(new WebSocketStub);

        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        $channel = new \Swoole\Coroutine\Channel(1);
        Coroutine::create(function () use ($invoker, $swooleServer, $channel) {
            $invoker->deferOnOpen(new SwooleRequest, WebSocketStub::class, $swooleServer, 1);
            $channel->push(true);
        });
        $channel->pop();
        usleep(1000);
    }

    public function testConnectionOpenedEventDispatchedEvenWhenOnOpenThrows()
    {
        $dispatched = false;

        $logger = Mockery::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once();

        $dispatcher = Mockery::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionOpened::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(Mockery::on(
            function (ConnectionOpened $event) use (&$dispatched) {
                $dispatched = ($event->fd === 1);
                return $dispatched;
            }
        ));

        $container = $this->createContainer($logger, $dispatcher);
        $container->shouldReceive('make')->with(WebSocketThrowingStub::class)->andReturn(new WebSocketThrowingStub);

        $server = new Server($container);
        $invoker = new ClassInvoker($server);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        $channel = new \Swoole\Coroutine\Channel(1);
        Coroutine::create(function () use ($invoker, $swooleServer, $channel) {
            $invoker->deferOnOpen(new SwooleRequest, WebSocketThrowingStub::class, $swooleServer, 1);
            $channel->push(true);
        });
        $channel->pop();
        usleep(1000);

        $this->assertTrue($dispatched);
    }

    public function testMessageReceivedEventIsDispatched()
    {
        $dispatcher = Mockery::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(MessageReceived::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(Mockery::on(
            fn (MessageReceived $event) => $event->fd === 1 && $event->server === 'websocket'
        ));

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = 'test';

        $server->onMessage($swooleServer, $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageReceivedEventNotDispatchedWithoutListeners()
    {
        $dispatcher = Mockery::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(MessageReceived::class)->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = 'test';

        $server->onMessage($swooleServer, $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testConnectionClosedEventIsDispatched()
    {
        $dispatcher = Mockery::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(Mockery::on(
            fn (ConnectionClosed $event) => $event->fd === 1 && $event->reactorId === 0 && $event->server === 'websocket'
        ));

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = Mockery::mock(SwooleServer::class);

        $server->onClose($swooleServer, 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
    }

    public function testConnectionClosedEventNotDispatchedWithoutListeners()
    {
        $dispatcher = Mockery::mock(EventDispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->with(ConnectionClosed::class)->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->shouldReceive('make')->with(WebSocketMessageStub::class)->andReturn(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container);
        $swooleServer = Mockery::mock(SwooleServer::class);

        $server->onClose($swooleServer, 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
    }

    /**
     * Create a container mock with logger and optional event dispatcher.
     */
    protected function createContainer(
        ?StdoutLoggerInterface $logger = null,
        ?EventDispatcherContract $dispatcher = null,
    ): Container&\Mockery\MockInterface {
        $logger ??= Mockery::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing();

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn($logger);

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
