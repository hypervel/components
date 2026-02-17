<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Exception\SocketClosedException;
use Hypervel\Engine\Exception\SocketConnectException;
use Hypervel\Engine\SafeSocket;
use Hypervel\Engine\Socket;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine\Server;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class SocketTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testSocketConnectFailed()
    {
        try {
            (new Socket\SocketFactory())->make(new Socket\SocketOption('127.0.0.1', 33333));
        } catch (SocketConnectException $exception) {
            $this->assertSame(SOCKET_ECONNREFUSED, $exception->getCode());
            $this->assertSame('Connection refused', $exception->getMessage());
        }

        try {
            (new Socket\SocketFactory())->make(new Socket\SocketOption('192.0.0.1', 9501, 1));
        } catch (SocketConnectException $exception) {
            $this->assertSame(SOCKET_ETIMEDOUT, $exception->getCode());
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }
    }

    public function testSafeSocketSendAndRecvPacket()
    {
        $server = new Server('0.0.0.0', 9506);
        $p = function (string $data): string {
            return pack('N', strlen($data)) . $data;
        };
        go(function () use ($server, $p) {
            $server->set([
                'open_length_check' => true,
                'package_max_length' => 1024 * 1024 * 2,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
            ]);
            $server->handle(function (Server\Connection $connection) use ($p) {
                $socket = new SafeSocket($connection->exportSocket(), 65535);
                while (true) {
                    try {
                        $body = $socket->recvPacket();
                        if (empty($body)) {
                            $socket->close();
                            break;
                        }
                        go(function () use ($socket, $body, $p) {
                            $body = substr($body, 4);
                            if ($body === 'ping') {
                                $socket->sendAll($p('pong'));
                            } else {
                                $socket->sendAll($p($body));
                            }
                        });
                    } catch (Throwable $exception) {
                        $socket->close();
                        $this->assertInstanceOf(SocketClosedException::class, $exception);
                        break;
                    }
                }
            });
            $server->start();
        });

        sleep(1);

        $socket = (new Socket\SocketFactory())->make(new Socket\SocketOption('127.0.0.1', 9506, protocol: [
            'open_length_check' => true,
            'package_max_length' => 1024 * 1024 * 2,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ]));

        for ($i = 0; $i < 200; ++$i) {
            $res = $socket->sendAll($p(str_repeat('s', 10240)), 1);
        }

        for ($i = 0; $i < 200; ++$i) {
            $socket->recvPacket(1);
        }

        $server->shutdown();
    }

    public function testSafeSocketBroken()
    {
        $server = new Server('0.0.0.0', 9506);
        $p = function (string $data): string {
            return pack('N', strlen($data)) . $data;
        };
        go(function () use ($server, $p) {
            $server->set([
                'open_length_check' => true,
                'package_max_length' => 1024 * 1024 * 2,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
            ]);
            $server->handle(function (Server\Connection $connection) use ($p) {
                $socket = new SafeSocket($connection->exportSocket(), 65535);
                while (true) {
                    try {
                        $body = $socket->recvPacket();
                        if (empty($body)) {
                            $socket->close();
                            break;
                        }
                        go(function () use ($socket, $body, $p) {
                            $body = substr($body, 4);
                            if ($body === 'ping') {
                                $socket->sendAll($p('pong'));
                            } else {
                                $socket->sendAll($p($body));
                            }
                        });
                    } catch (Throwable $exception) {
                        $socket->close();
                        $this->assertInstanceOf(SocketClosedException::class, $exception);
                        break;
                    }
                }
            });
            $server->start();
        });

        sleep(1);

        $socket = (new Socket\SocketFactory())->make(new Socket\SocketOption('127.0.0.1', 9506, protocol: [
            'open_length_check' => true,
            'package_max_length' => 1024 * 1024 * 2,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ]));

        $socket->sendAll($p(str_repeat('s', 10240)), 1);
        $socket->recvPacket(1);
        $socket->sendAll($p(str_repeat('s', 10240)), 1);
        $socket->recvPacket(1);

        $socket->close();

        sleep(1);

        $server->shutdown();
    }

    public function testSafeSocketBrokenDontThrow()
    {
        $server = new Server('0.0.0.0', 9506);
        $p = function (string $data): string {
            return pack('N', strlen($data)) . $data;
        };
        go(function () use ($server, $p) {
            $server->set([
                'open_length_check' => true,
                'package_max_length' => 1024 * 1024 * 2,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
            ]);
            $server->handle(function (Server\Connection $connection) use ($p) {
                $socket = new SafeSocket($connection->exportSocket(), 65535, false);
                while (true) {
                    $body = $socket->recvPacket();
                    if (empty($body)) {
                        $socket->close();
                        break;
                    }
                    go(function () use ($socket, $body, $p) {
                        $body = substr($body, 4);
                        if ($body === 'ping') {
                            $socket->sendAll($p('pong'));
                        } else {
                            $socket->sendAll($p($body));
                        }
                    });
                }
                $this->assertTrue(true);
            });
            $server->start();
        });

        sleep(1);

        $socket = (new Socket\SocketFactory())->make(new Socket\SocketOption('127.0.0.1', 9506, protocol: [
            'open_length_check' => true,
            'package_max_length' => 1024 * 1024 * 2,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ]));

        $socket->sendAll($p(str_repeat('s', 10240)), 1);
        $socket->recvPacket(1);
        $socket->sendAll($p(str_repeat('s', 10240)), 1);
        $socket->recvPacket(1);

        $socket->close();

        sleep(1);

        $server->shutdown();
    }

    public function testSocketGetOption()
    {
        $server = new Server('0.0.0.0', 9506);

        sleep(1);

        $socket = (new Socket\SocketFactory())->make($option = new Socket\SocketOption('127.0.0.1', 9506, protocol: [
            'open_length_check' => true,
            'package_max_length' => 1024 * 1024 * 2,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ]));

        $this->assertSame($option, $socket->getSocketOption());

        $socket->close();

        sleep(1);

        $server->shutdown();
    }
}
