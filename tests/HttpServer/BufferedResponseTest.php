<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class BufferedResponseTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testDefaultServerSettingsDeliverLargeBufferedResponses(): void
    {
        $server = new Process([PHP_BINARY, __DIR__ . '/Fixtures/buffered-response-server.php']);
        $server->setTimeout(10);
        $serverProcessId = null;
        $client = null;

        try {
            $server->start();
            $serverProcessId = $server->getPid();
            $ready = $server->waitUntil(static fn (): bool => preg_match('/READY (\d+)\n/', $server->getOutput()) === 1);
            $this->assertTrue($ready, $server->getOutput() . $server->getErrorOutput());
            preg_match('/READY (\d+)\n/', $server->getOutput(), $matches);

            $client = stream_socket_client('tcp://127.0.0.1:' . $matches[1], $error, $message, 3);
            $this->assertIsResource($client, $message);
            stream_set_timeout($client, 3);
            fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n");
            $response = stream_get_contents($client);
            $this->assertFalse(stream_get_meta_data($client)['timed_out'], 'The response did not finish.');

            [$headers, $body] = explode("\r\n\r\n", $response, 2);
            $this->assertStringStartsWith('HTTP/1.1 200 ', $headers);
            $this->assertMatchesRegularExpression('/\r\nContent-Length: 3000000(?:\r\n|$)/i', $headers);
            $this->assertSame(3000000, strlen($body), $server->getErrorOutput());
            $this->assertSame(hash('xxh128', str_repeat('0123456789', 300000)), hash('xxh128', $body));
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }

            if ($serverProcessId !== null) {
                // The fixture owns its process group, including every Swoole child.
                posix_kill(-$serverProcessId, SIGKILL);
            }

            $server->stop(0);
        }
    }
}
