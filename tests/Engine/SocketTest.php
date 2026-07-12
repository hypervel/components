<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Contracts\Engine\SocketInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Exceptions\SocketClosedException;
use Hypervel\Engine\Exceptions\SocketConnectException;
use Hypervel\Engine\SafeSocket;
use Hypervel\Engine\Socket;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Socket as SwooleSocket;
use Swoole\Coroutine\WaitGroup;
use Throwable;

use function Hypervel\Coroutine\go;

class SocketTest extends TestCase
{
    public function testSocketConnectFailed(): void
    {
        try {
            (new Socket\SocketFactory)->make(new Socket\SocketOption('127.0.0.1', 33333));
        } catch (SocketConnectException $exception) {
            $this->assertSame(SOCKET_ECONNREFUSED, $exception->getCode());
            $this->assertSame('Connection refused', $exception->getMessage());
        }

        try {
            (new Socket\SocketFactory)->make(new Socket\SocketOption('192.0.0.1', 8000, 0.2));
        } catch (SocketConnectException $exception) {
            $this->assertSame(SOCKET_ETIMEDOUT, $exception->getCode());
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }
    }

    public function testSafeSocketSendAndRecvPacket(): void
    {
        $server = new Server('127.0.0.1', 0);
        $p = function (string $data): string {
            return pack('N', strlen($data)) . $data;
        };
        $server->set($this->packetProtocol());

        $this->withServer(
            $server,
            function (Server\Connection $connection, array &$serverErrors) use ($p): void {
                $socket = new SafeSocket($connection->exportSocket(), 65535);

                while (true) {
                    try {
                        $body = $socket->recvPacket();

                        if (empty($body)) {
                            $socket->close();
                            break;
                        }

                        go(function () use ($socket, $body, $p, &$serverErrors): void {
                            try {
                                $body = substr($body, 4);
                                $socket->sendAll($p($body === 'ping' ? 'pong' : $body));
                            } catch (Throwable $exception) {
                                $serverErrors[] = $exception;
                            }
                        });
                    } catch (Throwable $exception) {
                        $socket->close();

                        if (! $exception instanceof SocketClosedException) {
                            $serverErrors[] = $exception;
                        }

                        break;
                    }
                }
            },
            function (int $port) use ($p): void {
                $socket = $this->connect($port);

                try {
                    for ($i = 0; $i < 200; ++$i) {
                        $socket->sendAll($p(str_repeat('s', 10240)), 1);
                    }

                    for ($i = 0; $i < 200; ++$i) {
                        $socket->recvPacket(1);
                    }
                } finally {
                    $socket->close();
                }
            },
        );
    }

    public function testSafeSocketBroken(): void
    {
        $server = new Server('127.0.0.1', 0);
        $closed = new Channel(1);
        $p = function (string $data): string {
            return pack('N', strlen($data)) . $data;
        };
        $server->set($this->packetProtocol());

        $this->withServer(
            $server,
            function (Server\Connection $connection, array &$serverErrors) use ($p, $closed): void {
                $socket = new SafeSocket($connection->exportSocket(), 65535);

                while (true) {
                    try {
                        $body = $socket->recvPacket();

                        if (empty($body)) {
                            $socket->close();
                            break;
                        }

                        go(function () use ($socket, $body, $p, &$serverErrors): void {
                            try {
                                $body = substr($body, 4);
                                $socket->sendAll($p($body === 'ping' ? 'pong' : $body));
                            } catch (Throwable $exception) {
                                $serverErrors[] = $exception;
                            }
                        });
                    } catch (Throwable $exception) {
                        $socket->close();

                        if (! $exception instanceof SocketClosedException) {
                            $serverErrors[] = $exception;
                        }

                        break;
                    }
                }

                $closed->push(true);
            },
            function (int $port) use ($p, $closed): void {
                $socket = $this->connect($port);

                try {
                    $socket->sendAll($p(str_repeat('s', 10240)), 1);
                    $socket->recvPacket(1);
                    $socket->sendAll($p(str_repeat('s', 10240)), 1);
                    $socket->recvPacket(1);
                } finally {
                    $socket->close();
                }

                $this->assertTrue($closed->pop(0.5));
            },
        );
    }

    public function testSafeSocketBrokenDontThrow(): void
    {
        $server = new Server('127.0.0.1', 0);
        $closed = new Channel(1);
        $p = function (string $data): string {
            return pack('N', strlen($data)) . $data;
        };
        $server->set($this->packetProtocol());

        $this->withServer(
            $server,
            function (Server\Connection $connection, array &$serverErrors) use ($p, $closed): void {
                $socket = new SafeSocket($connection->exportSocket(), 65535, false);

                while (true) {
                    $body = $socket->recvPacket();

                    if (empty($body)) {
                        $socket->close();
                        break;
                    }

                    go(function () use ($socket, $body, $p, &$serverErrors): void {
                        try {
                            $body = substr($body, 4);
                            $socket->sendAll($p($body === 'ping' ? 'pong' : $body));
                        } catch (Throwable $exception) {
                            $serverErrors[] = $exception;
                        }
                    });
                }

                $closed->push(true);
            },
            function (int $port) use ($p, $closed): void {
                $socket = $this->connect($port);

                try {
                    $socket->sendAll($p(str_repeat('s', 10240)), 1);
                    $socket->recvPacket(1);
                    $socket->sendAll($p(str_repeat('s', 10240)), 1);
                    $socket->recvPacket(1);
                } finally {
                    $socket->close();
                }

                $this->assertTrue($closed->pop(0.5));
            },
        );
    }

    public function testSocketGetOption(): void
    {
        $server = new Server('127.0.0.1', 0);
        $server->set($this->packetProtocol());

        $this->withServer(
            $server,
            fn (Server\Connection $connection) => $connection->close(),
            function (int $port): void {
                $option = new Socket\SocketOption(
                    '127.0.0.1',
                    $port,
                    protocol: $this->packetProtocol(),
                );
                $socket = (new Socket\SocketFactory)->make($option);

                try {
                    $this->assertSame($option, $socket->getSocketOption());
                } finally {
                    $socket->close();
                }
            },
        );
    }

    public function testServerHandlerExceptionSurfacesInTheParent(): void
    {
        $server = new Server('127.0.0.1', 0);
        $handled = new Channel(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected server handler failure');

        $this->withServer(
            $server,
            function () use ($handled): never {
                $handled->push(true);

                throw new RuntimeException('expected server handler failure');
            },
            function (int $port) use ($handled): void {
                $socket = (new Socket\SocketFactory)->make(
                    new Socket\SocketOption('127.0.0.1', $port),
                );

                try {
                    $this->assertTrue($handled->pop(0.5));
                } finally {
                    $socket->close();
                }
            },
        );
    }

    public function testClientFailureStillShutsDownTheServer(): void
    {
        $server = new Server('127.0.0.1', 0);
        $coroutinesBefore = SwooleCoroutine::stats()['coroutine_num'];

        try {
            $this->withServer(
                $server,
                fn (Server\Connection $connection) => $connection->close(),
                function (int $port): never {
                    $socket = (new Socket\SocketFactory)->make(
                        new Socket\SocketOption('127.0.0.1', $port),
                    );

                    try {
                        throw new RuntimeException('expected client failure');
                    } finally {
                        $socket->close();
                    }
                },
            );

            $this->fail('The client failure should escape the server boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected client failure', $exception->getMessage());
        }

        $this->assertSame(
            $coroutinesBefore,
            SwooleCoroutine::stats()['coroutine_num'],
        );
    }

    public function testSafeSocketClosesAfterANativeSendFailure(): void
    {
        $server = new Server('127.0.0.1', 0);
        $peerClosed = new Channel(2);

        $this->withServer(
            $server,
            function (Server\Connection $connection) use ($peerClosed): void {
                $connection->close();
                $peerClosed->push(true);
            },
            function (int $port) use ($peerClosed): void {
                foreach ([true, false] as $throw) {
                    $nativeSocket = (new Socket\SocketFactory)->make(
                        new Socket\SocketOption('127.0.0.1', $port),
                    );
                    $this->assertInstanceOf(SwooleSocket::class, $nativeSocket);
                    $socket = new SafeSocket($nativeSocket, throw: $throw);

                    try {
                        $this->assertTrue($peerClosed->pop(0.5));
                        $this->driveSafeSocketToTerminalSendFailure($socket);

                        if ($throw) {
                            try {
                                $socket->sendAll('after-close');
                                $this->fail('A closed SafeSocket should reject later sends.');
                            } catch (SocketClosedException) {
                                $this->addToAssertionCount(1);
                            }
                        } else {
                            $this->assertFalse($socket->sendAll('after-close'));
                        }
                    } finally {
                        $socket->close();
                    }
                }
            },
        );
    }

    public function testSafeSocketPreservesAZeroStringPayload(): void
    {
        $nativeSocket = new ZeroPayloadSocket;
        $socket = new SafeSocket($nativeSocket);

        try {
            $this->assertSame('0', $socket->recvAll());
            $this->assertSame('0', $socket->recvPacket());
        } finally {
            $socket->close();
        }
    }

    /**
     * Run assertions against a test server with bounded, unconditional cleanup.
     */
    private function withServer(Server $server, callable $handler, callable $callback): void
    {
        $serverErrors = [];
        $ready = new Channel(1);
        $finished = new WaitGroup(1);

        go(function () use ($server, $handler, &$serverErrors, $ready, $finished): void {
            try {
                $server->handle(function (Server\Connection $connection) use (
                    $handler,
                    &$serverErrors,
                ): void {
                    try {
                        $handler($connection, $serverErrors);
                    } catch (Throwable $exception) {
                        $serverErrors[] = $exception;
                    }
                });
                $ready->push(true);
                $server->start();
            } catch (Throwable $exception) {
                $serverErrors[] = $exception;
            } finally {
                $finished->done();
            }
        });

        $exception = null;

        try {
            if ($ready->pop(0.5) !== true) {
                if ($serverErrors !== []) {
                    throw $serverErrors[0];
                }

                throw new RuntimeException('The Engine test server did not become ready.');
            }

            $callback($server->port);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        } finally {
            try {
                $server->shutdown();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }

            try {
                if (! $finished->wait(1.0)) {
                    throw new RuntimeException('The Engine test server did not stop within one second.');
                }
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }

            try {
                $ready->close();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        if ($serverErrors !== []) {
            throw $serverErrors[0];
        }
    }

    /**
     * Connect a packet socket to the given test server.
     */
    private function connect(int $port): SocketInterface
    {
        return (new Socket\SocketFactory)->make(new Socket\SocketOption(
            '127.0.0.1',
            $port,
            protocol: $this->packetProtocol(),
        ));
    }

    /**
     * Get the length-framed packet protocol used by these tests.
     */
    private function packetProtocol(): array
    {
        return [
            'open_length_check' => true,
            'package_max_length' => 1024 * 1024 * 2,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ];
    }

    /**
     * Send until the background consumer observes the closed peer.
     */
    private function driveSafeSocketToTerminalSendFailure(SafeSocket $socket): void
    {
        /** @var Channel $channel */
        $channel = (new ReflectionProperty($socket, 'channel'))->getValue($socket);
        $loop = new ReflectionProperty($socket, 'loop');
        $deadline = hrtime(true) + 500_000_000;
        $payload = str_repeat('x', 65_536);

        while (! $channel->isClosing() && hrtime(true) < $deadline) {
            try {
                $socket->sendAll($payload, 0.05);
            } catch (SocketClosedException) {
                break;
            }

            usleep(100);
        }

        $this->assertTrue($channel->isClosing());

        while ($loop->getValue($socket) === true && hrtime(true) < $deadline) {
            usleep(100);
        }

        $this->assertFalse($loop->getValue($socket));
    }
}

class ZeroPayloadSocket extends SwooleSocket
{
    public function __construct()
    {
        parent::__construct(AF_INET, SOCK_STREAM, 0);
    }

    public function recvAll(int $length = 65536, float $timeout = 0): false|string
    {
        return '0';
    }

    public function recvPacket(float $timeout = 0): false|string
    {
        return '0';
    }
}
