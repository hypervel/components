<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use RuntimeException;

class RequestFramingTest extends HttpServerIntegrationTestCase
{
    public function testChunkedRequestsKeepTheirExactBoundary(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6205');
        }

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
        $this->assertStringEndsWith('up', $response);
    }

    public function testPipelinedResponsesStayInRequestOrder(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires https://github.com/swoole/swoole-src/pull/6220');
        }

        $response = $this->sendRawRequest(
            "GET /pipeline/first HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n\r\n"
            . "GET /pipeline/second HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );
        $first = strpos($response, 'first response');
        $second = strpos($response, 'second response');

        $this->assertNotFalse($first);
        $this->assertNotFalse($second);
        $this->assertLessThan($second, $first);
    }

    /**
     * Send one raw request and read the complete response.
     */
    protected function sendRawRequest(string $request): string
    {
        $socket = $this->openSocket();

        try {
            $this->assertSame(strlen($request), fwrite($socket, $request));
            $response = stream_get_contents($socket);

            if (! is_string($response)) {
                throw new RuntimeException('Unable to read the HTTP response.');
            }

            return $response;
        } finally {
            fclose($socket);
        }
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
