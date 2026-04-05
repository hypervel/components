<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Servers\Hypervel\Connection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Hypervel\WebSocketServer\Sender;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ConnectionTest extends ReverbTestCase
{
    public function testIdReturnsFd()
    {
        $sender = m::mock(Sender::class);
        $connection = new Connection($sender, 42);

        $this->assertSame(42, $connection->id());
    }

    public function testSendDelegatesToSender()
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('push')->once()->with(42, 'hello')->andReturn(true);

        $connection = new Connection($sender, 42);
        $connection->send('hello');
    }

    public function testControlSendsOpcodeViaSender()
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('push')->once()->with(42, '', WEBSOCKET_OPCODE_PING)->andReturn(true);

        $connection = new Connection($sender, 42);
        $connection->control(WEBSOCKET_OPCODE_PING);
    }

    public function testCloseDisconnectsFd()
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('disconnect')->once()->with(42)->andReturn(true);

        $connection = new Connection($sender, 42);
        $connection->close();
    }

    public function testCloseWithMessageSendsThenDisconnects()
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('push')->once()->with(42, 'goodbye')->andReturn(true);
        $sender->shouldReceive('disconnect')->once()->with(42)->andReturn(true);

        $connection = new Connection($sender, 42);
        $connection->close('goodbye');
    }
}
