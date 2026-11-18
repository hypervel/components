<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Connection as ReverbConnection;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\Reverb\Servers\Hypervel\Connection;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Routing\Route;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Hypervel\WebSocketServer\Sender;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use ReflectionClass;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

use function Hypervel\Coroutine\go;

class WebSocketHandlerTest extends ReverbTestCase
{
    protected PusherServer $pusherServer;

    protected ApplicationProvider $applicationProvider;

    protected WebSocketHandler $handler;

    /**
     * Set up the protocol server and connection handler.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pusherServer = Double::for(PusherServer::class)->strict();
        $this->applicationProvider = $this->app->make(ApplicationProvider::class);

        $this->handler = new WebSocketHandler(
            $this->app,
            $this->pusherServer,
            $this->applicationProvider,
        );
    }

    public function testInvokeReturns426ForNonWebsocketRequest(): void
    {
        $request = new HttpRequest;

        $response = ($this->handler)($request, 'reverb-key');

        $this->assertSame(426, $response->getStatusCode());
        $this->assertSame('websocket', $response->headers->get('Upgrade'));
        $this->assertSame('Upgrade Required', $response->getContent());
    }

    public function testOnOpenCreatesConnectionAndDelegatesToServer(): void
    {
        $this->setupRequestContext('reverb-key');

        $sender = Double::for(Sender::class)->strict();
        $this->app->instance(Sender::class, $sender);

        $this->pusherServer->expects('open')
            ->with(Argument::type(ReverbConnection::class))
            ->resolves(static function (ReverbConnection $connection): void {
                $connection->markEstablished();
            });

        $swooleServer = Double::for(WebSocketServer::class)->strict();
        $swooleRequest = new SwooleRequest;
        $swooleRequest->fd = 1;

        $this->handler->onOpen($swooleServer, $swooleRequest);

        $connections = WebSocketHandler::connections();
        $this->assertCount(1, $connections);
        $this->assertArrayHasKey(1, $connections);
        $this->assertInstanceOf(ConnectionLifecycle::class, $connections[1]);
        $this->assertInstanceOf(ReverbConnection::class, $connections[1]->connection());
    }

    public function testOnOpenRejectsInvalidAppKey(): void
    {
        $this->setupRequestContext('invalid-key');

        // Replace application provider with one that throws
        $appProvider = Double::for(ApplicationProvider::class)->strict();
        $appProvider->allows('findByKey')->with('invalid-key')
            ->throws(new InvalidApplication('invalid-key'));

        $handler = new WebSocketHandler($this->app, $this->pusherServer, $appProvider);

        $swooleServer = Double::for(WebSocketServer::class)->strict();
        $swooleServer->expects('push')->with(
            1,
            '{"event":"pusher:error","data":"{\"code\":4001,\"message\":\"Application does not exist\"}"}'
        );
        $swooleServer->expects('disconnect')->with(1);

        $swooleRequest = new SwooleRequest;
        $swooleRequest->fd = 1;

        $handler->onOpen($swooleServer, $swooleRequest);

        // No connection should be stored
        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testOnOpenClosesAConnectionRejectedByTheProtocolServer(): void
    {
        $this->setupRequestContext('reverb-key');

        $this->app->instance(Sender::class, Double::for(Sender::class)->strict());

        $this->pusherServer->expects('open');
        $this->pusherServer->expects('close');

        $swooleServer = Double::for(WebSocketServer::class)->strict();
        $swooleServer->expects('disconnect')->with(1)->returns(true);
        $swooleRequest = new SwooleRequest;
        $swooleRequest->fd = 1;

        $this->handler->onOpen($swooleServer, $swooleRequest);

        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testOnCloseWaitsForYieldingOpenAndCleansUpTheAttachedConnection(): void
    {
        $this->setupRequestContext('reverb-key');

        $application = $this->applicationProvider->findByKey('reverb-key');
        $closeStarted = new Channel(1);
        $closeFinished = new Channel(1);
        $swooleServer = Double::for(WebSocketServer::class)->strict();
        $server = Double::for(Server::class)->strict();

        $provider = Double::for(ApplicationProvider::class)->strict();
        $provider->expects('findByKey')->with('reverb-key')
            ->resolves(function () use ($application, $closeStarted, $closeFinished, $server): Application {
                go(function () use ($closeStarted, $closeFinished, $server): void {
                    $closeStarted->push(true);
                    $this->handler->onClose($server, 1, 0);
                    $closeFinished->push(true);
                });

                $closeStarted->pop();

                while (WebSocketHandler::connections() !== []) {
                    usleep(100);
                }

                return $application;
            });

        $handler = new WebSocketHandler($this->app, $this->pusherServer, $provider);
        $this->app->instance(Sender::class, Double::for(Sender::class)->strict());
        $this->pusherServer->expects('open')
            ->resolves(static function (ReverbConnection $connection): void {
                $connection->markEstablished();
            });
        $this->pusherServer->expects('close')
            ->with(Argument::type(ReverbConnection::class));

        $swooleRequest = new SwooleRequest;
        $swooleRequest->fd = 1;

        $handler->onOpen($swooleServer, $swooleRequest);

        $this->assertTrue($closeFinished->pop(1));
        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testOnMessageDelegatesTextFrameToServer(): void
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->expects('message')
            ->with(Argument::same($connection), '{"event":"pusher:ping"}');

        $swooleServer = Double::for(WebSocketServer::class)->strict();

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        $frame->data = '{"event":"pusher:ping"}';

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageRejectsOversizedMessage(): void
    {
        $connection = $this->createStoredConnection(1);

        // The message should NOT reach PusherServer
        $this->pusherServer->expects('message')->never();

        $swooleServer = Double::for(WebSocketServer::class)->strict();
        $swooleServer->expects('push')
            ->with(1, 'Maximum message size exceeded');

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        // max_message_size in test config is 10_000
        $frame->data = str_repeat('a', 10_001);

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageHandlesPingControlFrame(): void
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->expects('control')
            ->with(Argument::same($connection), WEBSOCKET_OPCODE_PING);

        $swooleServer = Double::for(WebSocketServer::class)->strict();
        $swooleServer->expects('push')
            ->with(1, '', WEBSOCKET_OPCODE_PONG);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_PING;

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageHandlesPongControlFrame(): void
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->expects('control')
            ->with(Argument::same($connection), WEBSOCKET_OPCODE_PONG);

        $swooleServer = Double::for(WebSocketServer::class)->strict();
        // No pong response should be sent for pong frames
        $swooleServer->expects('push')->never();

        $frame = new Frame;
        $frame->fd = 1;
        $frame->opcode = WEBSOCKET_OPCODE_PONG;

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnMessageIgnoresUnknownFd(): void
    {
        $this->pusherServer->expects('message')->never();
        $this->pusherServer->expects('control')->never();

        $swooleServer = Double::for(WebSocketServer::class)->strict();

        $frame = new Frame;
        $frame->fd = 999;
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        $frame->data = '{"event":"pusher:ping"}';

        $this->handler->onMessage($swooleServer, $frame);
    }

    public function testOnCloseDelegatesToServerAndCleansUp(): void
    {
        $connection = $this->createStoredConnection(1);

        $this->pusherServer->expects('close')->with(Argument::same($connection));

        $swooleServer = Double::for(Server::class)->strict();

        $this->handler->onClose($swooleServer, 1, 0);

        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testOnCloseIgnoresUnknownFd(): void
    {
        $this->pusherServer->expects('close')->never();

        $swooleServer = Double::for(Server::class)->strict();

        // Should not throw — just a no-op
        $this->handler->onClose($swooleServer, 999, 0);
    }

    public function testFlushStateClearsConnections(): void
    {
        $this->createStoredConnection(1);
        $this->createStoredConnection(2);

        $this->assertCount(2, WebSocketHandler::connections());

        WebSocketHandler::flushState();

        $this->assertEmpty(WebSocketHandler::connections());
    }

    /**
     * Set up RequestContext with the application route parameter.
     */
    private function setupRequestContext(string $appKey): void
    {
        $route = Double::for(Route::class)->strict();
        $route->allows('parameter')->with('appKey')->returns($appKey);

        $request = new HttpRequest;
        $request->setRouteResolver(fn (): Route => $route);
        $request->headers->set('Origin', 'http://localhost');

        RequestContext::set($request);
    }

    /**
     * Create a Reverb Connection and store it in the handler's static map.
     */
    private function createStoredConnection(int $fd): ReverbConnection
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $wsConnection = new Connection(
            Double::for(Sender::class)->strict(),
            $fd,
        );

        $connection = new ReverbConnection($wsConnection, $app, 'http://localhost');

        $connection->markEstablished();
        $lifecycle = new ConnectionLifecycle($fd);
        $lifecycle->attach($connection);

        // Directly set in the static connections array via reflection
        $reflection = new ReflectionClass(WebSocketHandler::class);
        $property = $reflection->getProperty('connections');
        $connections = $property->getValue();
        $connections[$fd] = $lifecycle;
        $property->setValue(null, $connections);

        return $connection;
    }
}
