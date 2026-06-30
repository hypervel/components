<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Sender;
use Mockery as m;
use Mockery\MockInterface;
use Swoole\Server;

class SenderTest extends TestCase
{
    public function testSenderCheck(): void
    {
        $container = $this->getContainer();
        $server = m::mock(Server::class);
        $server->shouldReceive('connection_info')->once()->andReturn(false);
        $server->shouldReceive('connection_info')->once()->andReturn([]);
        $server->shouldReceive('connection_info')->once()->andReturn(['websocket_status' => WEBSOCKET_STATUS_CLOSING]);
        $server->shouldReceive('connection_info')->once()->andReturn(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $container->shouldReceive('make')->with(Server::class)->andReturn($server);
        $sender = new Sender($container);

        $this->assertFalse($sender->check(1));
        $this->assertFalse($sender->check(1));
        $this->assertFalse($sender->check(1));
        $this->assertTrue($sender->check(1));
    }

    // REMOVED: testSenderResult — Tests coroutine-server path (CoroutineServer::class config, setResponse(), direct push via $responses property). All of this code was removed in the Swoole-only simplification of Sender.php.

    public function testSendPipeMessageDoesNotSendToSelfWhenWorkerIdIsNull(): void
    {
        $container = $this->getContainer();

        $server = m::mock(Server::class);
        // check() returns false — fd not active, triggers sendPipeMessage path
        $server->shouldReceive('connection_info')->andReturn(false);
        $server->shouldNotReceive('sendMessage');

        $container->shouldReceive('make')->with(Server::class)->andReturn($server);

        $sender = new Sender($container);
        // Do NOT call setWorkerId — simulating the case where InitSenderListener
        // hasn't run yet or missed this instance

        $this->assertTrue($sender->disconnect(42));
    }

    protected function getContainer(): Container|MockInterface
    {
        $container = m::mock(Container::class);

        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn(
            m::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing()
        );

        return $container;
    }
}
