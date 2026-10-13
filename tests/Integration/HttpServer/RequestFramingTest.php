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

        $response = $this->sendRawRequest(
            "POST /inspect HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Expect: 100-continue\r\n\r\n"
            . "5;name=value\r\nhello\r\n"
            . "0\r\nX-Trailer: value\r\n\r\n"
            . "GET /up HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );

        $this->assertSame(1, substr_count($response, 'HTTP/1.1 100 Continue'));
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

    protected function sendRawRequest(string $request): string
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

        try {
            $this->assertSame(strlen($request), fwrite($socket, $request));

            return stream_get_contents($socket);
        } finally {
            fclose($socket);
        }
    }
}
