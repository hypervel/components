<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use Hypervel\Engine\Http\V2\Client as Http2Client;
use Hypervel\Engine\Http\V2\Request as Http2Request;
use RuntimeException;

class RequestFramingTest extends HttpServerIntegrationTestCase
{
    public function testChunkedRequestsKeepTheirExactBoundary(): void
    {
        $socket = $this->openSocket();
        $headers = "POST /inspect HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Expect: 100-continue\r\n\r\n";
        $bodyAndNextRequest = "5;name=value\r\nhello\r\n"
            . "0\r\nX-Trailer: value\r\n\r\n"
            . "GET /up HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n";

        try {
            $this->assertSame(strlen($headers), fwrite($socket, $headers));
            $this->assertStringStartsWith('HTTP/1.1 100 Continue', $this->readResponseHead($socket));
            $this->assertSame(strlen($bodyAndNextRequest), fwrite($socket, $bodyAndNextRequest));
            $response = stream_get_contents($socket);
            $this->assertIsString($response);
        } finally {
            fclose($socket);
        }

        $this->assertSame(0, substr_count($response, 'HTTP/1.1 100 Continue'));
        $this->assertSame(2, substr_count($response, 'HTTP/1.1 200 OK'));
        $this->assertStringContainsString('"content":"hello"', $response);
        // Swoole does not keep pipelined responses in request order, so only check that the second response's body arrived.
        $this->assertStringContainsString("\r\n\r\nup", $response);
    }

    public function testOversizedHttpTwoRequestBodyLeavesOtherStreamsUsable(): void
    {
        // @TODO: Remove this skip once https://github.com/swoole/swoole-src/pull/6389 is released.
        if (version_compare(SWOOLE_VERSION, '6.3.0RC2', '<=')) {
            $this->markTestSkipped('Swoole closes the whole HTTP/2 connection when one request body exceeds package_max_length.');
        }

        $client = new Http2Client($this->getServerHost(), $this->getServerPort());

        try {
            // The fixture server's package_max_length is 64 KB.
            $client->send(new Http2Request('/inspect', 'POST', str_repeat('a', 128 * 1024)));
            $streamId = $client->send(new Http2Request('/up'));

            do {
                $response = $client->recv(5);
                $this->assertNotNull($response);
            } while ($response->getStreamId() !== $streamId);
        } finally {
            $client->close();
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('up', $response->getBody());
    }

    /**
     * Open a bounded connection to the HTTP test server.
     *
     * @return resource
     */
    protected function openSocket()
    {
        $socket = stream_socket_client(
            sprintf('tcp://%s:%d', $this->getServerHost(), $this->getServerPort()),
            $errorCode,
            $errorMessage,
            2
        );

        if (! is_resource($socket)) {
            throw new RuntimeException("Unable to connect to the HTTP test server: {$errorMessage}", $errorCode);
        }

        stream_set_timeout($socket, 2);

        return $socket;
    }

    /**
     * Read one HTTP response head.
     *
     * @param resource $socket
     */
    protected function readResponseHead($socket): string
    {
        $head = '';

        while (! str_ends_with($head, "\r\n\r\n")) {
            $line = fgets($socket);

            if ($line === false) {
                throw new RuntimeException('Timed out waiting for the HTTP response head.');
            }

            $head .= $line;
        }

        return $head;
    }
}
