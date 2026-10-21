<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class ResponseCancellationTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[DataProvider('silentProviderPhases')]
    public function testClientDisconnectInterruptsProviderIoWithoutWaitingForProviderData(string $phase, string $mode): void
    {
        $provider = new Process([PHP_BINARY, dirname(__DIR__) . '/Http/Fixtures/streaming-server.php', $phase]);
        $provider->setTimeout(10);
        $server = null;
        $serverProcessId = null;
        $client = null;
        $control = null;

        try {
            $provider->start();
            $this->waitForOutput($provider, "\n");
            $providerAddress = trim($provider->getOutput());
            $server = new Process([PHP_BINARY, __DIR__ . '/Fixtures/disconnect-server.php', $providerAddress, $mode]);
            $server->setTimeout(10);
            $server->start();
            $serverProcessId = $server->getPid();
            $this->waitForOutput($server, 'READY ');
            preg_match('/READY (\d+)/', $server->getOutput(), $matches);
            $address = '127.0.0.1:' . $matches[1];

            $client = stream_socket_client('tcp://' . $address, $error, $message, 2);
            $this->assertIsResource($client, $message);
            fwrite($client, "GET /stream HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $this->waitForOutput($provider, 'REQUEST');

            $control = stream_socket_client('tcp://' . $providerAddress, $error, $message, 2);
            $this->assertIsResource($control, $message);
            stream_set_timeout($control, 2);
            $this->assertSame("ready\n", fgets($control));

            if ($phase === 'delayed') {
                $this->waitForOutput($server, 'UPSTREAM_HEADERS');
            }

            fclose($client);
            $client = null;
            $this->waitForOutput($server, 'STOPPED');
            $this->waitForOutput($server, 'APPLICATION_CLOSE');
            $this->assertTrue($provider->isRunning(), 'Provider completion, rather than the disconnect, unblocked the producer.');

            $health = stream_socket_client('tcp://' . $address, $error, $message, 2);
            $this->assertIsResource($health, $message);

            try {
                stream_set_timeout($health, 2);
                fwrite($health, "GET /health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
                $this->assertStringContainsString('healthy', stream_get_contents($health));
            } finally {
                fclose($health);
            }

            $this->assertSame('', $server->getErrorOutput());
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }

            if (is_resource($control)) {
                fclose($control);
            }

            if ($serverProcessId !== null) {
                // The fixture owns its process group, including every Swoole child.
                posix_kill(-$serverProcessId, SIGKILL);
            }

            $server?->stop(0);
            $provider->stop(0);
        }
    }

    /**
     * Provide silent header and body waits under both supported server modes.
     */
    public static function silentProviderPhases(): array
    {
        return [
            'headers in process mode' => ['silent-headers', 'process'],
            'body in process mode' => ['delayed', 'process'],
            'headers in base mode' => ['silent-headers', 'base'],
            'body in base mode' => ['delayed', 'base'],
        ];
    }

    public function testHttp2StreamResetLeavesSilentProductionUntilTheNextWriteAndPreservesSiblingStreams(): void
    {
        $provider = new Process([PHP_BINARY, dirname(__DIR__) . '/Http/Fixtures/streaming-server.php', 'delayed']);
        $provider->setTimeout(10);
        $server = null;
        $serverProcessId = null;
        $client = null;
        $control = null;

        try {
            $provider->start();
            $this->waitForOutput($provider, "\n");
            $providerAddress = trim($provider->getOutput());
            $server = new Process([PHP_BINARY, __DIR__ . '/Fixtures/disconnect-server.php', $providerAddress, 'process']);
            $server->setTimeout(10);
            $server->start();
            $serverProcessId = $server->getPid();
            $this->waitForOutput($server, 'READY ');
            preg_match('/READY (\d+)/', $server->getOutput(), $matches);
            $client = stream_socket_client('tcp://127.0.0.1:' . $matches[1], $error, $message, 2);
            $this->assertIsResource($client, $message);
            stream_set_timeout($client, 2);

            // Static HPACK indexes encode GET and http; the path and authority are literals.
            fwrite($client, "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
                . $this->http2Frame(4, 0, 0, '')
                . $this->http2Frame(1, 5, 1, "\x82\x86\x04\x07/stream\x01\x09localhost"));
            $this->waitForOutput($provider, 'REQUEST');
            $this->waitForOutput($server, 'UPSTREAM_HEADERS');
            $control = stream_socket_client('tcp://' . $providerAddress, $error, $message, 2);
            $this->assertIsResource($control, $message);
            stream_set_timeout($control, 2);
            $this->assertSame("ready\n", fgets($control));

            // RST_STREAM(CANCEL) affects stream 1; stream 3 proves the connection remains usable.
            fwrite($client, $this->http2Frame(3, 0, 1, pack('N', 8))
                . $this->http2Frame(1, 5, 3, "\x82\x86\x04\x07/health\x01\x09localhost"));
            $this->assertSame('healthy', $this->http2Body($client, 3));
            $this->assertStringNotContainsString('STOPPED', $server->getOutput());

            fclose($control);
            $control = null;
            $this->waitForOutput($server, 'STOPPED');

            fwrite($client, $this->http2Frame(1, 5, 5, "\x82\x86\x04\x07/health\x01\x09localhost"));
            $this->assertSame('healthy', $this->http2Body($client, 5));
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }

            if (is_resource($control)) {
                fclose($control);
            }

            if ($serverProcessId !== null) {
                posix_kill(-$serverProcessId, SIGKILL);
            }

            $server?->stop(0);
            $provider->stop(0);
        }
    }

    /**
     * Encode one frame for the reset behavior unavailable through Swoole's HTTP/2 client.
     */
    private function http2Frame(int $type, int $flags, int $stream, string $payload): string
    {
        return substr(pack('N', strlen($payload)), 1) . pack('CCN', $type, $flags, $stream) . $payload;
    }

    /**
     * Read one response body, acknowledging the server's initial settings.
     *
     * @param resource $client
     */
    private function http2Body(mixed $client, int $stream): string
    {
        $body = '';

        do {
            $header = stream_get_contents($client, 9);
            $this->assertSame(9, strlen($header), 'The HTTP/2 fixture stopped sending frames.');
            $length = unpack('N', "\0" . substr($header, 0, 3))[1];
            $type = ord($header[3]);
            $flags = ord($header[4]);
            $identity = unpack('N', substr($header, 5))[1];
            $payload = $length === 0 ? '' : stream_get_contents($client, $length);
            $this->assertSame($length, strlen($payload));

            if ($type === 4 && ($flags & 1) === 0) {
                fwrite($client, $this->http2Frame(4, 1, 0, ''));
            }

            if ($identity === $stream && $type === 0) {
                $body .= $payload;
            }
        } while ($identity !== $stream || ($flags & 1) === 0 || ! in_array($type, [0, 1], true));

        return $body;
    }

    /**
     * Wait for a fixture boundary while retaining its diagnostics on failure.
     */
    private function waitForOutput(Process $process, string $line): void
    {
        $deadline = microtime(true) + 3;

        do {
            if (str_contains($process->getOutput(), $line)) {
                return;
            }

            if (! $process->isRunning()) {
                break;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail("The fixture did not reach [{$line}].\n" . $process->getOutput() . $process->getErrorOutput());
    }
}
