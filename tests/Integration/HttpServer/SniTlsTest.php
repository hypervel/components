<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Tests\TestCase;

class SniTlsTest extends TestCase
{
    use InteractsWithServer;

    protected bool $runTestsInCoroutine = false;

    protected int $serverPort = 19508;

    /**
     * Require the SNI-only TLS test server.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();
    }

    public function testSniOnlyServersCompleteTlsHandshakes(): void
    {
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6214');
        }

        $tlsDirectory = __DIR__ . '/Fixtures/Tls';
        $socket = stream_socket_client(
            sprintf('tls://%s:%d', $this->getServerHost(), $this->getServerPort()),
            $errorCode,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => $tlsDirectory . '/ca.crt',
                'peer_name' => 'localhost',
            ]]),
        );

        $this->assertIsResource($socket, $errorMessage);
        stream_set_timeout($socket, 2);
        fwrite($socket, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        $this->assertStringContainsString('secure', $response);
    }
}
