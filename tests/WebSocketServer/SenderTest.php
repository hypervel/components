<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Sender;
use Mockery;
use Mockery\MockInterface;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
class SenderTest extends TestCase
{
    public function testSenderCheck()
    {
        $container = $this->getContainer();
        $server = Mockery::mock(Server::class);
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

    protected function getContainer(): Container|MockInterface
    {
        $container = Mockery::mock(Container::class);

        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn(
            Mockery::mock(StdoutLoggerInterface::class)->shouldIgnoreMissing()
        );

        return $container;
    }
}
