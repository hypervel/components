<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Redis\Subscriber\Connection;
use Hypervel\Redis\Subscriber\Exceptions\ServerException;
use Hypervel\Redis\Subscriber\Exceptions\SocketException;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

class ConnectionTest extends TestCase
{
    public function testSendWritesTheCompletePayload(): void
    {
        $payload = str_repeat('redis-command-', 100_000);
        $received = '';
        $server = new RespServer;
        $server->start(function ($client) use (&$received, $payload): void {
            while (strlen($received) < strlen($payload)) {
                $chunk = fread($client, strlen($payload) - strlen($received));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $received .= $chunk;
            }
        });
        $connection = new Connection($server->endpoint());

        try {
            $this->assertTrue($connection->send($payload));
            $server->wait();
            $this->assertSame($payload, $received);
        } finally {
            $connection->close();
        }
    }

    public function testSendThrowsAfterPeerCloses(): void
    {
        $server = new RespServer;
        $server->start(static function (): void {
        });
        $connection = new Connection($server->endpoint());
        $server->wait();

        try {
            $this->expectException(SocketException::class);
            $connection->send(str_repeat('x', 16 * 1024 * 1024));
        } finally {
            $connection->close();
        }
    }

    #[DataProvider('responseProvider')]
    public function testReceiveDecodesResp2(string $response, mixed $expected): void
    {
        $this->assertSame($expected, $this->receive($response));
    }

    public static function responseProvider(): array
    {
        return [
            'simple string' => ["+OK\r\n", 'OK'],
            'positive integer' => [":42\r\n", 42],
            'minimum integer' => [':' . PHP_INT_MIN . "\r\n", PHP_INT_MIN],
            'bulk string' => ["$5\r\nhello\r\n", 'hello'],
            'empty bulk string' => ["$0\r\n\r\n", ''],
            'null bulk string' => ["$-1\r\n", null],
            'binary bulk string' => ["$6\r\na\r\nb\0c\r\n", "a\r\nb\0c"],
            'nested array' => ["*3\r\n+OK\r\n:2\r\n*2\r\n$1\r\na\r\n$-1\r\n", ['OK', 2, ['a', null]]],
            'null array' => ["*-1\r\n", null],
        ];
    }

    public function testReceiveHandlesChunkedShortReads(): void
    {
        $this->assertSame(
            "a\r\nb\0c",
            $this->receive(["$6\r\n", "a\r", "\nb\0", "c\r\n"]),
        );
    }

    public function testErrorFrameThrowsServerException(): void
    {
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('ERR command failed');

        $this->receive("-ERR command failed\r\n");
    }

    #[DataProvider('malformedResponseProvider')]
    public function testMalformedResponseThrowsSocketException(
        string $response,
        string $message,
    ): void {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage($message);

        $this->receive($response);
    }

    public static function malformedResponseProvider(): array
    {
        return [
            'empty frame' => ["\r\n", 'empty Redis response'],
            'unknown frame' => ["?unknown\r\n", 'Unsupported Redis response type'],
            'malformed integer' => [":12x\r\n", 'Invalid Redis integer'],
            'overflowing integer' => [':' . PHP_INT_MAX . "0\r\n", 'exceeds the native integer range'],
            'invalid bulk length' => ["$-2\r\n", 'Invalid Redis bulk string length'],
            'invalid array length' => ["*-2\r\n", 'Invalid Redis array length'],
            'truncated line' => ['+OK', 'incomplete Redis response line'],
            'truncated bulk' => ["$5\r\nabc", 'complete Redis response payload'],
            'missing bulk terminator' => ["$3\r\nabcxx", 'missing its trailing CRLF'],
        ];
    }

    public function testEndpointFormattingSupportsIpv4Ipv6TlsAndUnix(): void
    {
        $this->assertSame('tcp://127.0.0.1:6379', $this->endpoint('127.0.0.1'));
        $this->assertSame('tcp://[::1]:6379', $this->endpoint('::1'));
        $this->assertSame('tcp://[::1]:6379', $this->endpoint('[::1]'));
        $this->assertSame(
            'tls://redis.test:6379',
            $this->endpoint('redis.test', context: ['verify_peer' => false]),
        );
        $this->assertSame(
            'tls://[::1]:6379',
            $this->endpoint('::1', context: ['verify_peer' => false]),
        );
        $this->assertSame(
            'tcp://redis.test:6379',
            $this->endpoint('tcp://redis.test', context: ['verify_peer' => false]),
        );
        $this->assertSame('tls://[::1]:6380', $this->endpoint('::1', 6380, 'TLS'));
        $this->assertSame('tls://redis.test:6380', $this->endpoint('tls://redis.test', 6380, 'TLS'));
        $this->assertSame('tcp://redis.test:6380', $this->endpoint('tcp://redis.test:6380'));
        $this->assertSame('tcp://[::1]:6380', $this->endpoint('tcp://[::1]:6380'));
        $this->assertSame('unix:///tmp/redis.sock', $this->endpoint('/tmp/redis.sock', 0));
        $this->assertSame('unix:///tmp/redis.sock', $this->endpoint('unix:///tmp/redis.sock', 0, 'UNIX'));
    }

    #[DataProvider('invalidEndpointProvider')]
    public function testEndpointFormattingRejectsUnsupportedShapes(
        string $host,
        ?string $scheme,
        string $message,
    ): void {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage($message);

        $this->endpoint($host, 6379, $scheme);
    }

    public static function invalidEndpointProvider(): array
    {
        return [
            'empty host' => ['', null, 'non-empty string'],
            'unsupported scheme' => ['udp://redis.test', null, 'Unsupported'],
            'conflicting scheme' => ['tls://redis.test', 'tcp', 'must match'],
            'Unix path with TCP scheme' => ['/tmp/redis.sock', 'tcp', 'cannot use a non-Unix scheme'],
            'relative Unix URI' => ['unix://redis.sock', null, 'must contain an absolute path'],
            'credentials' => ['tcp://user:secret@redis.test:6379', null, 'cannot contain credentials'],
            'path' => ['tcp://redis.test/path', null, 'cannot contain credentials'],
            'query' => ['tcp://redis.test?name=value', null, 'cannot contain credentials'],
            'fragment' => ['tcp://redis.test#fragment', null, 'cannot contain credentials'],
            'schemeless path' => ['redis.test/path', null, 'cannot contain credentials'],
            'unbracketed IPv6 URI' => ['tcp://fe80::1:2637', null, 'must use bracketed IPv6 addresses'],
            'schemeless host with port' => ['redis.test:6380', null, 'pass the port separately'],
        ];
    }

    public function testUnixSocketOperation(): void
    {
        $directory = ParallelTesting::tempDir('RedisSubscriberConnectionTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $path = $directory . '/redis.sock';
        $server = new RespServer('unix://' . $path);
        $server->start(static function ($client): void {
            fwrite($client, "+PONG\r\n");
        });
        $connection = new Connection($path);

        try {
            $this->assertSame('PONG', $connection->receive());
            $server->wait();
        } finally {
            $connection->close();
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testNonEmptyContextSelectsTlsForSchemelessEndpoint(): void
    {
        $clientOptions = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
        $server = new RespServer('tls://127.0.0.1:0', [
            'ssl' => [
                'local_cert' => __DIR__ . '/../Fixtures/Tls/server.crt',
                'local_pk' => __DIR__ . '/../Fixtures/Tls/server.key',
                'allow_self_signed' => true,
            ],
        ]);
        $server->start(static function ($client): void {
            fwrite($client, "+OK\r\n");
        });
        $endpoint = parse_url($server->endpoint());
        $connection = new Connection(
            $endpoint['host'],
            $endpoint['port'],
            context: $clientOptions,
        );

        try {
            $this->assertSame('OK', $connection->receive());
            $server->wait();
        } finally {
            $connection->close();
        }
    }

    public function testTlsContextShapes(): void
    {
        $clientOptions = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];

        foreach ([
            'flat' => $clientOptions,
            'ssl' => ['ssl' => $clientOptions],
            'stream' => ['stream' => $clientOptions],
        ] as $context) {
            $server = new RespServer('tls://127.0.0.1:0', [
                'ssl' => [
                    'local_cert' => __DIR__ . '/../Fixtures/Tls/server.crt',
                    'local_pk' => __DIR__ . '/../Fixtures/Tls/server.key',
                    'allow_self_signed' => true,
                ],
            ]);
            $server->start(static function ($client): void {
                fwrite($client, "+OK\r\n");
            });
            $connection = new Connection($server->endpoint(), context: $context);

            try {
                $this->assertSame('OK', $connection->receive());
                $server->wait();
            } finally {
                $connection->close();
            }
        }
    }

    public function testCloseIsIdempotent(): void
    {
        $server = new RespServer;
        $server->start(static function (): void {
        });
        $connection = new Connection($server->endpoint());

        $connection->close();
        $connection->close();
        $server->wait();
    }

    private function endpoint(
        string $host,
        int $port = 6379,
        ?string $scheme = null,
        array $context = [],
    ): string {
        $reflection = new ReflectionClass(Connection::class);
        $connection = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod('endpoint')->invoke(
            $connection,
            $host,
            $port,
            $scheme,
            $context,
        );
    }

    private function receive(string|array $chunks): mixed
    {
        $server = new RespServer;
        $server->start(static function ($client) use ($chunks): void {
            foreach ((array) $chunks as $chunk) {
                fwrite($client, $chunk);
                usleep(1_000);
            }
        });
        $connection = new Connection($server->endpoint());

        try {
            return $connection->receive();
        } finally {
            $connection->close();
            $server->wait();
        }
    }
}
