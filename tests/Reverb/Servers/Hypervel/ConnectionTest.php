<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Servers\Hypervel\Connection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Hypervel\WebSocketServer\Sender;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;

class ConnectionTest extends ReverbTestCase
{
    public function testIdReturnsFd(): void
    {
        $connection = new Connection(new Sender($this->app), 42);

        $this->assertSame(42, $connection->id());
    }

    public function testSendDelegatesToSender(): void
    {
        $server = $this->server();
        $server->expects('push')->with(42, 'hello')->returns(true);

        $connection = new Connection(new Sender($this->app), 42);
        $connection->send('hello');
    }

    public function testControlSendsOpcodeViaSender(): void
    {
        $server = $this->server();
        $server->expects('push')->with(42, '', WEBSOCKET_OPCODE_PING)->returns(true);

        $connection = new Connection(new Sender($this->app), 42);
        $connection->control(WEBSOCKET_OPCODE_PING);
    }

    public function testCloseDisconnectsFd(): void
    {
        $server = $this->server();
        $server->expects('disconnect')->with(42)->returns(true);

        $connection = new Connection(new Sender($this->app), 42);
        $connection->close();
    }

    public function testCloseWithMessageSendsThenDisconnects(): void
    {
        $server = $this->server();
        $server->expects('push')->with(42, 'goodbye')->returns(true);
        $server->expects('disconnect')->with(42)->returns(true);

        $connection = new Connection(new Sender($this->app), 42);
        $connection->close('goodbye');
    }

    /**
     * Bind a native server with an active WebSocket connection.
     */
    protected function server(): WebSocketServer&DoubleInterface
    {
        $server = Double::for(WebSocketServer::class)->strict();
        $server->allows('connection_info')->with(42)->returns(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $this->app->instance(Server::class, $server);

        return $server;
    }
}
