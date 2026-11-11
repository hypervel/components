<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use Hypervel\Engine\Http\V2\Client as Http2Client;
use Hypervel\Engine\Http\V2\Request as Http2Request;
use Swoole\Coroutine\Socket;

class ResponseStreamingTest extends HttpServerIntegrationTestCase
{
    protected const string BINARY_CONTENTS = '0123456789abcdefghijklmnopqrstuvwxyz';

    public function testCallbackAndIterableStreamsPassThroughOutboundMiddleware(): void
    {
        $callback = $this->request('GET', '/callback-stream');
        $iterable = $this->request('GET', '/iterable-stream');

        $this->assertSame('callback-stream', (string) $callback->getBody());
        $this->assertSame('true', $callback->getHeaderLine('X-After-Middleware'));
        $this->assertSame('iterable-stream', (string) $iterable->getBody());
        $this->assertSame('true', $iterable->getHeaderLine('X-After-Middleware'));
    }

    public function testStorageStreamIsLazyUntilAfterOutboundMiddleware(): void
    {
        $response = $this->request('GET', '/storage-stream');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('storage response body', (string) $response->getBody());
        $this->assertSame('true', $response->getHeaderLine('X-After-Middleware'));
    }

    public function testRawPartitionedCookieAttributesReachTheWire(): void
    {
        $response = $this->request('GET', '/cookies');
        $cookie = $response->getHeaderLine('Set-Cookie');

        $this->assertStringContainsString('raw=a%2Fb', $cookie);
        $this->assertStringContainsString('partitioned', strtolower($cookie));
        $this->assertStringNotContainsString('a%252Fb', $cookie);
    }

    public function testHttpOneBinaryResponsesHonorRangesAndHead(): void
    {
        $response = $this->request('GET', '/binary');
        $range = $this->request('GET', '/binary', [
            'headers' => ['Range' => 'bytes=4-8'],
        ]);
        $head = $this->request('HEAD', '/binary');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BINARY_CONTENTS, (string) $response->getBody());
        $this->assertSame((string) strlen(self::BINARY_CONTENTS), $response->getHeaderLine('Content-Length'));

        $this->assertSame(206, $range->getStatusCode());
        $this->assertSame('45678', (string) $range->getBody());
        $this->assertSame('bytes 4-8/36', $range->getHeaderLine('Content-Range'));
        $this->assertSame('5', $range->getHeaderLine('Content-Length'));

        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());
        $this->assertSame('36', $head->getHeaderLine('Content-Length'));
    }

    public function testTemporaryAndDeleteAfterSendFilesAreCompletedAndReleased(): void
    {
        $temporary = $this->request('GET', '/temporary-binary');
        $deleted = $this->request('GET', '/delete-binary');
        $state = $this->decode($this->request('GET', '/delete-state'));

        $this->assertSame('temporary binary body', (string) $temporary->getBody());
        $this->assertSame('delete after send', (string) $deleted->getBody());
        $this->assertFalse($state['exists']);
    }

    public function testHttpTwoBinaryResponsesRemainBoundedAndHonorRanges(): void
    {
        $client = new Http2Client($this->getServerHost(), $this->getServerPort());

        try {
            $client->send(new Http2Request('/binary'));
            $response = $client->recv(5);

            $client->send(new Http2Request(
                path: '/binary',
                headers: ['range' => 'bytes=10-14'],
            ));
            $range = $client->recv(5);
        } finally {
            $client->close();
        }

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BINARY_CONTENTS, $response->getBody());
        $this->assertArrayNotHasKey('content-length', $response->getHeaders());

        $this->assertNotNull($range);
        $this->assertSame(206, $range->getStatusCode());
        $this->assertSame('abcde', $range->getBody());
        $this->assertSame('bytes 10-14/36', $range->getHeaders()['content-range']);
        $this->assertArrayNotHasKey('content-length', $range->getHeaders());
    }

    public function testHttpTwoPingWhileALargeResponseWaitsForTheClientDoesNotKillTheWorker(): void
    {
        // @TODO: Remove this skip once https://github.com/swoole/swoole-src/pull/6398 is released.
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped("Swoole's HTTP/2 server kills the worker when a PING arrives while a response waits for the client.");
        }

        $pid = (string) $this->request('GET', '/pid')->getBody();

        // A small receive buffer, so the 16 MB response fills the server's output buffer while the client isn't reading.
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $this->assertTrue($socket->setOption(SOL_SOCKET, SO_RCVBUF, 64 * 1024));
        $this->assertTrue($socket->connect($this->getServerHost(), $this->getServerPort(), 2));

        try {
            // The connection preface, SETTINGS and WINDOW_UPDATE frames that open the flow-control windows,
            // and GET /large-response on stream 1.
            $socket->sendAll(
                "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
                . "\0\0\x06\x04\0\0\0\0\0\0\x04\x7f\xff\xff\xff"
                . "\0\0\x04\x08\0\0\0\0\0\x7f\xff\0\0"
                . "\0\0\x1e\x01\x05\0\0\0\x01\x82\x86\x01\x09localhost\x04\x0f/large-response"
            );
            usleep(300_000);
            $socket->sendAll("\0\0\x08\x06\0\0\0\0\0ABCDEFGH");

            // The server can't queue the PING ACK while the response waits, so it closes the connection.
            do {
                $data = $socket->recv(65536, 5);
            } while (is_string($data) && $data !== '');
        } finally {
            $socket->close();
        }

        $this->assertSame('', $data);
        $this->assertSame($pid, (string) $this->request('GET', '/pid')->getBody());
    }

    public function testDisconnectStopsProductionAndReleasesTheGenerator(): void
    {
        $socket = fsockopen($this->getServerHost(), $this->getServerPort(), $errorCode, $errorMessage, 2);
        $this->assertIsResource($socket, $errorMessage);

        try {
            fwrite($socket, "GET /disconnect-stream HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

            while (($line = fgets($socket)) !== false && $line !== "\r\n");

            $this->assertNotSame('', fread($socket, 1024));
        } finally {
            fclose($socket);
        }

        $released = false;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $released = $this->decode($this->request('GET', '/stream-state'))['released'];

            if ($released) {
                break;
            }

            usleep(20_000);
        }

        $this->assertTrue($released);
    }
}
