<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Contracts\Engine\Socket\SocketFactoryInterface;
use Hypervel\Contracts\Engine\SocketInterface;
use Hypervel\Redis\Subscriber\Connection;
use Hypervel\Redis\Subscriber\Exceptions\SocketException;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ConnectionTest extends TestCase
{
    public function testSendSucceeds()
    {
        $socket = m::mock(SocketInterface::class);
        $socket->shouldReceive('sendAll')->with('hello')->once()->andReturn(5);

        $connection = $this->createConnection($socket);

        $this->assertTrue($connection->send('hello'));
    }

    public function testSendThrowsWhenSendAllReturnsFalse()
    {
        $socket = m::mock(SocketInterface::class);
        $socket->shouldReceive('sendAll')->with('data')->once()->andReturn(false);

        $connection = $this->createConnection($socket);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to send data to the socket.');

        $connection->send('data');
    }

    public function testSendThrowsWhenSendIncomplete()
    {
        $socket = m::mock(SocketInterface::class);
        // Data is 5 bytes but only 3 were sent
        $socket->shouldReceive('sendAll')->with('hello')->once()->andReturn(3);

        $connection = $this->createConnection($socket);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('The sending data is incomplete');

        $connection->send('hello');
    }

    public function testRecvDelegatesToSocket()
    {
        $socket = m::mock(SocketInterface::class);
        $socket->shouldReceive('recvPacket')->with(-1.0)->once()->andReturn("*3\r\n");

        $connection = $this->createConnection($socket);

        $this->assertSame("*3\r\n", $connection->recv());
    }

    public function testRecvPassesTimeout()
    {
        $socket = m::mock(SocketInterface::class);
        $socket->shouldReceive('recvPacket')->with(5.0)->once()->andReturn(false);

        $connection = $this->createConnection($socket);

        $this->assertFalse($connection->recv(5.0));
    }

    public function testCloseSucceeds()
    {
        $socket = m::mock(SocketInterface::class);
        $socket->shouldReceive('close')->once()->andReturn(true);

        $connection = $this->createConnection($socket);

        $connection->close();

        // Second close should not call socket->close() again
        $connection->close();
    }

    public function testCloseThrowsWhenSocketCloseFails()
    {
        $socket = m::mock(SocketInterface::class);
        $socket->shouldReceive('close')->once()->andReturn(false);

        $connection = $this->createConnection($socket);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Failed to close the socket.');

        $connection->close();
    }

    private function createConnection(SocketInterface $socket): Connection
    {
        $factory = m::mock(SocketFactoryInterface::class);
        $factory->shouldReceive('make')->once()->andReturn($socket);

        return new Connection('127.0.0.1', 6379, 5.0, $factory);
    }
}
