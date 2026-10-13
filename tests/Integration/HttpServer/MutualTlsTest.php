<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Tests\TestCase;

class MutualTlsTest extends TestCase
{
    use InteractsWithServer;

    protected bool $runTestsInCoroutine = false;

    protected int $serverPort = 19507;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();
    }

    public function testMutualTlsRequiresAClientCertificate(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6188');
        }

        $this->assertFalse($this->connect());
        $this->assertStringContainsString('secure', $this->connect(withClientCertificate: true));
    }

    protected function connect(bool $withClientCertificate = false): string|false
    {
        $tlsDirectory = __DIR__ . '/Fixtures/Tls';
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'cafile' => $tlsDirectory . '/ca.crt',
            'peer_name' => 'localhost',
        ];

        if ($withClientCertificate) {
            $options['local_cert'] = $tlsDirectory . '/client.crt';
            $options['local_pk'] = $tlsDirectory . '/client.key';
        }

        $socket = @stream_socket_client(
            sprintf('tls://%s:%d', $this->getServerHost(), $this->getServerPort()),
            $errorCode,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $options]),
        );

        if (! is_resource($socket)) {
            return false;
        }

        stream_set_timeout($socket, 2);
        fwrite($socket, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        return $response;
    }
}
