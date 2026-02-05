<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Engine\Socket;

/**
 * Integration tests for Socket that require an external TCP server.
 *
 * These tests require a TCP server running on the configured host/port
 * that responds to length-prefixed packets.
 *
 * @internal
 * @coversNothing
 */
class SocketTest extends EngineIntegrationTestCase
{
    /**
     * The TCP server port for socket tests.
     */
    protected int $httpServerPort = 9502;

    public function testSocketRecvPacketFromTcpServer(): void
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setProtocol([
            'open_length_check' => true,
            'package_max_length' => 1024 * 1024 * 2,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ]);
        $socket->connect($this->getHttpServerHost(), $this->getHttpServerPort());
        $socket->sendAll(pack('N', 4) . 'ping');
        $this->assertSame('pong', substr($socket->recvPacket(), 4));

        $id = uniqid();
        $socket->sendAll(pack('N', strlen($id)) . $id);
        $this->assertSame('recv:' . $id, substr($socket->recvPacket(), 4));
    }

    public function testSocketRecvPacketFromTcpServerViaFactory(): void
    {
        $socket = (new Socket\SocketFactory())->make(new Socket\SocketOption(
            $this->getHttpServerHost(),
            $this->getHttpServerPort(),
            protocol: [
                'open_length_check' => true,
                'package_max_length' => 1024 * 1024 * 2,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
            ]
        ));
        $socket->sendAll(pack('N', 4) . 'ping');
        $this->assertSame('pong', substr($socket->recvPacket(), 4));

        $id = uniqid();
        $socket->sendAll(pack('N', strlen($id)) . $id);
        $this->assertSame('recv:' . $id, substr($socket->recvPacket(), 4));
    }

    public function testSocketRecvAllFromTcpServer(): void
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $socket->connect($this->getHttpServerHost(), $this->getHttpServerPort());
        $socket->sendAll(pack('N', 4) . 'ping');
        $res = $socket->recvAll(4);
        $this->assertSame(4, unpack('Nlen', $res)['len']);
        $res = $socket->recvAll(4);
        $this->assertSame('pong', $res);

        $id = str_repeat(uniqid(), rand(1, 10));
        $socket->sendAll(pack('N', $len = strlen($id)) . $id);
        $res = $socket->recvAll(4);
        $len += 5;
        $this->assertSame($len, unpack('Nlen', $res)['len']);
        $res = $socket->recvAll($len);
        $this->assertSame('recv:' . $id, $res);
    }
}
