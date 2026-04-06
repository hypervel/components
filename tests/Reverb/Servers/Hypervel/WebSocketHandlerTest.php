<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\Reverb\Servers\Hypervel\Connection;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Routing\Route;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Hypervel\WebSocketServer\Sender;
use Mockery as m;
use ReflectionClass;
use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

/**
 * @internal
 * @coversNothing
 */
class WebSocketHandlerTest extends ReverbTestCase
{
    protected PusherServer $pusherServer;

    protected ApplicationProvider $applicationProvider;

    protected WebSocketHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusherServer = m::mock(PusherServer::class);
        $this->applicationProvider = $this->app->make(ApplicationProvider::class);

        $this->handler = new WebSocketHandler(
            $this->app,
            $this->pusherServer,
            $this->applicationProvider,
        );
    }

    public function testInvokeReturns426ForNonWebsocketRequest()
    {
        $request = m::mock(HttpRequest::class);

        $response = ($this->handler)($request, 'reverb-key');

        $this->assertSame(426, $response->getStatusCode());
        $this->assertSame('websocket', $response->headers->get('Upgrade'));
        $this->assertSame('Upgrade Required', $response->getContent());
    }

    public function testOnOpenCreatesConnectionAndDelegatesToServer()
    {
        $this->setupRequestContext('reverb-key');

        $sender = m::mock(Sender::class);
        $this->app->instance(Sender::class, $sender);

        $this->pusherServer->shouldReceive('open')->once()
            ->with(m::type(\Hypervel\Reverb\Connection::class));

        $swooleServer = m::mock(WebSocketServer::class);
        $swooleRequest = new SwooleRequest;
        $swooleRequest->fd = 1;

        $this->handler->onOpen($swooleServer, $swooleRequest);

        $connections = WebSocketHandler::connections();
        $this->assertCount(1, $connections);
        $this->assertArrayHasKey(1, $connections);
        $this->assertInstanceOf(\Hypervel\Reverb\Connection::class, $connections[1]);
    }

    public function testOnOpenRejectsInvalidAppKey()
    {
        $this->setupRequestContext('invalid-key');

        // Replace application provider with one that throws
        $appProvider = m::mock(ApplicationProvider::class);
        $appProvider->shouldReceive('findByKey')->with('invalid-key')
            ->andThrow(new InvalidApplication('invalid-key'));

        $handler = new WebSocketHandler($this->app, $this->pusherServer, $appProvider);

        $swooleServer = m::mock(WebSocketServer::class);
        $swooleServer->shouldReceive('push')->once()->with(
            1,
            '{"event":"pusher:error","data":"{\"code\":4001,\"message\":\"Application does not exist\"}"}'
        );
        $swooleServer->shouldReceive('disconnect')->once()->with(1);

        $swooleRequest = new SwooleRequest;
        $swooleRequest->fd = 1;

        $handler->onOpen($swooleServer, $swooleRequest);

        // No connection should be stored
        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testOnMessageDelegatesTextFrameToServer()
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->shouldReceive('message')->once()
            ->with($connection, '{"event":"pusher:ping"}');

        $swooleServer = m::mock(WebSocketServer::class);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        $frame->data = '{"event":"pusher:ping"}';

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageRejectsOversizedMessage()
    {
        $connection = $this->createStoredConnection(1);

        // The message should NOT reach PusherServer
        $this->pusherServer->shouldNotReceive('message');

        $swooleServer = m::mock(WebSocketServer::class);
        $swooleServer->shouldReceive('push')->once()
            ->with(1, 'Maximum message size exceeded');

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        // max_message_size in test config is 10_000
        $frame->data = str_repeat('a', 10_001);

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageHandlesPingControlFrame()
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->shouldReceive('control')->once()
            ->with($connection, WEBSOCKET_OPCODE_PING);

        $swooleServer = m::mock(WebSocketServer::class);
        $swooleServer->shouldReceive('push')->once()
            ->with(1, '', WEBSOCKET_OPCODE_PONG);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_PING;

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageHandlesPongControlFrame()
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->shouldReceive('control')->once()
            ->with($connection, WEBSOCKET_OPCODE_PONG);

        $swooleServer = m::mock(WebSocketServer::class);
        // No pong response should be sent for pong frames
        $swooleServer->shouldNotReceive('push');

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_PONG;

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageIgnoresUnknownFd()
    {
        $this->pusherServer->shouldNotReceive('message');
        $this->pusherServer->shouldNotReceive('control');

        $swooleServer = m::mock(WebSocketServer::class);

        $frame = new Frame;
        $frame->fd = 999;
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        $frame->data = '{"event":"pusher:ping"}';

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnCloseDelegatesToServerAndCleansUp()
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->shouldReceive('close')->once()->with($connection);

        $swooleServer = m::mock(\Swoole\Server::class);

        $this->handler->onClose($swooleServer, 1, 0);

        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testOnCloseIgnoresUnknownFd()
    {
        $this->pusherServer->shouldNotReceive('close');

        $swooleServer = m::mock(\Swoole\Server::class);

        // Should not throw — just a no-op
        $this->handler->onClose($swooleServer, 999, 0);
    }

    public function testFlushStateClearsConnections()
    {
        $this->createStoredConnection(1);
        $this->createStoredConnection(2);

        $this->assertCount(2, WebSocketHandler::connections());

        WebSocketHandler::flushState();

        $this->assertEmpty(WebSocketHandler::connections());
    }

    /**
     * Set up RequestContext with a mocked HttpRequest that has route parameters.
     */
    private function setupRequestContext(string $appKey): void
    {
        $route = m::mock(Route::class);
        $route->shouldReceive('parameter')->with('appKey')->andReturn($appKey);

        $request = m::mock(HttpRequest::class);
        $request->shouldReceive('route')->andReturn($route);
        $request->headers = new \Symfony\Component\HttpFoundation\HeaderBag(['Origin' => 'http://localhost']);

        RequestContext::set($request);
    }

    /**
     * Create a Reverb Connection and store it in the handler's static map.
     */
    private function createStoredConnection(int $fd): \Hypervel\Reverb\Connection
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $wsConnection = new Connection(
            m::mock(Sender::class)->shouldIgnoreMissing(),
            $fd,
        );

        $connection = new \Hypervel\Reverb\Connection($wsConnection, $app, 'http://localhost');

        // Directly set in the static connections array via reflection
        $reflection = new ReflectionClass(WebSocketHandler::class);
        $property = $reflection->getProperty('connections');
        $connections = $property->getValue();
        $connections[$fd] = $connection;
        $property->setValue(null, $connections);

        return $connection;
    }
}
