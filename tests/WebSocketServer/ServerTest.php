<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hyperf\Support\Reflection\ClassInvoker;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Dispatcher\HttpDispatcher;
use Hypervel\ExceptionHandler\ExceptionHandlerDispatcher;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\HttpServer\ResponseEmitter;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Stub\WebSocketStub;
use Hypervel\WebSocketServer\Server;
use Mockery;
use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Server as WebSocketSwooleServer;

/**
 * @internal
 * @coversNothing
 */
class ServerTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Verify that deferOnOpen defers the onOpen call so it runs after
     * the calling coroutine exits, and that onOpen is invoked on
     * OnOpenInterface implementors.
     */
    public function testDeferOnOpenCallsOnOpen()
    {
        WebSocketStub::$coroutineId = 0;

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(WebSocketStub::class)->andReturn(new WebSocketStub());

        $server = new Server(
            $container,
            Mockery::mock(HttpDispatcher::class),
            Mockery::mock(ExceptionHandlerDispatcher::class),
            Mockery::mock(ResponseEmitter::class),
            Mockery::mock(StdoutLoggerInterface::class),
        );

        $invoker = new ClassInvoker($server);
        $swooleServer = Mockery::mock(WebSocketSwooleServer::class);

        // Run deferOnOpen inside a child coroutine so that defer() fires
        // when that coroutine exits, before we make our assertions.
        $channel = new \Swoole\Coroutine\Channel(1);
        Coroutine::create(function () use ($invoker, $swooleServer, $channel) {
            $invoker->deferOnOpen(new SwooleRequest(), WebSocketStub::class, $swooleServer, 1);
            $channel->push(true);
        });
        $channel->pop();

        // Yield to allow the deferred callback to execute.
        usleep(1000);

        $this->assertNotSame(0, WebSocketStub::$coroutineId, 'onOpen should have been called');
        $this->assertNotSame(Coroutine::id(), WebSocketStub::$coroutineId, 'onOpen should run in a different coroutine');
    }

    // REMOVED: testEngineServer — Tests FooServer::getServer() which accepts Swow\Http\Server\Connection in a type union. Pure Swow/coroutine-server test, not applicable to Swoole-only mode.
}
