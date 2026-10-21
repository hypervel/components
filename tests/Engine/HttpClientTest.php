<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\Client;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine\Socket;

class HttpClientTest extends TestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = ParallelTesting::tempDir('HttpClientTest');

        (new Filesystem)->deleteDirectory($this->temporaryDirectory);
        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testClosingAnUnconnectedClientReportsNoConnection(): void
    {
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires initialized coroutine HTTP client socket state from https://github.com/swoole/swoole-src/pull/6186.');
        }

        $client = new Client('127.0.0.1', 80);

        $this->assertFalse($client->close());
        $this->assertSame(SWOOLE_ERROR_CLIENT_NO_CONNECTION, $client->errCode);
    }

    public function testRequestSendsCallerHostAndValidCloseToken(): void
    {
        $this->skipWhenCoroutineHttpClientFixesAreUnavailable();

        $server = LoopbackHttpServer::start();
        $client = new Client('127.0.0.1', $server->port);
        $client->set(['keep_alive' => false]);

        try {
            $client->request('GET', '/', ['HOST' => 'example.test']);
            $request = $server->request();

            $this->assertIsString($request);
            preg_match_all('/^Host: (.*)\r$/mi', $request, $hosts);
            preg_match_all('/^Connection: (.*)\r$/mi', $request, $connections);
            $this->assertSame(['example.test'], $hosts[1]);
            $this->assertSame(['close'], $connections[1]);
        } finally {
            $client->close();
        }
    }

    public function testResponseConnectionCloseDoesNotAffectTheNextRequest(): void
    {
        $this->skipWhenCoroutineHttpClientFixesAreUnavailable();

        $responses = [
            static function (Socket $socket): void {
                $socket->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 6\r\nConnection: close\r\n\r\nclosed");
            },
            static function (Socket $socket): void {
                $socket->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nnext");
            },
        ];
        $server = LoopbackHttpServer::start($responses);
        $client = new Client('127.0.0.1', $server->port);

        try {
            $this->assertSame('closed', $client->request()->body);
            $this->assertFalse($client->connected);
            $this->assertSame('next', $client->request()->body);
            $this->assertTrue($client->connected);
        } finally {
            $client->close();
        }
    }

    public function testRequestConnectionCloseDoesNotAffectTheNextRequest(): void
    {
        $this->skipWhenCoroutineHttpClientFixesAreUnavailable();

        $responses = [
            static function (Socket $socket): void {
                $socket->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nfirst");
            },
            static function (Socket $socket): void {
                $socket->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nsecond");
            },
        ];
        $server = LoopbackHttpServer::start($responses);
        $client = new Client('127.0.0.1', $server->port);

        try {
            $this->assertSame('first', $client->request(headers: ['Connection' => 'close'])->body);
            $this->assertFalse($client->connected);
            $this->assertSame('second', $client->request()->body);
            $this->assertTrue($client->connected);

            $server->request();
            $request = $server->request();
            $this->assertIsString($request);
            preg_match_all('/^Connection: (.*)\r$/mi', $request, $connections);
            $this->assertSame(['keep-alive'], $connections[1]);
        } finally {
            $client->close();
        }
    }

    public function testResponseConnectionTokenListClosesTheClient(): void
    {
        $this->skipWhenCoroutineHttpClientFixesAreUnavailable();

        $server = LoopbackHttpServer::start([
            static function (Socket $socket): void {
                $socket->sendAll(
                    "HTTP/1.1 200 OK\r\n"
                    . "Content-Length: 2\r\n"
                    . "Upgrade: h2,h2c\r\n"
                    . "Connection: Upgrade, close\r\n\r\n"
                    . 'OK'
                );
            },
        ]);
        $client = new Client('127.0.0.1', $server->port);

        try {
            $this->assertSame('OK', $client->request()->body);
            $this->assertFalse($client->connected);
        } finally {
            $client->close();
        }
    }

    public function testEofCompletesOnlyCloseDelimitedResponses(): void
    {
        $this->skipWhenCoroutineHttpClientFixesAreUnavailable();

        $server = LoopbackHttpServer::start([
            static function (Socket $socket): void {
                $socket->sendAll("HTTP/1.1 200 OK\r\nConnection: close\r\n\r\ncomplete");
            },
            static function (Socket $socket): void {
                $socket->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 20\r\nConnection: close\r\n\r\nshort");
            },
        ]);

        $client = new Client('127.0.0.1', $server->port);

        try {
            $this->assertSame('complete', $client->request()->body);
        } finally {
            $client->close();
        }

        $client = new Client('127.0.0.1', $server->port);

        try {
            $client->request();
            $this->fail('A truncated framed response must fail.');
        } catch (HttpClientException $exception) {
            $this->assertSame(SOCKET_ECONNRESET, $exception->getCode());
        } finally {
            $client->close();
        }
    }

    public function testRequestAfterEmptyDownloadReturnsItsOwnBody(): void
    {
        $this->skipWhenCoroutineHttpClientFixesAreUnavailable();

        $download = $this->temporaryDirectory . '/download';
        file_put_contents($download, 'unchanged');

        $server = LoopbackHttpServer::start([
            ['status' => 204, 'body' => ''],
            ['body' => 'response body'],
        ]);
        $client = new Client('127.0.0.1', $server->port);

        try {
            $this->assertTrue($client->download('/', $download));
            $this->assertSame(204, $client->statusCode);
            $this->assertSame('response body', $client->request()->body);
            $this->assertSame('unchanged', file_get_contents($download));
        } finally {
            $client->close();
        }
    }

    /**
     * Skip the test until a Swoole release includes the coroutine HTTP client fixes.
     */
    private function skipWhenCoroutineHttpClientFixesAreUnavailable(): void
    {
        // @TODO: Remove this skip once the fixes from https://github.com/swoole/swoole-src/pull/6149 are in a Swoole release.
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires the coroutine HTTP client fixes from https://github.com/swoole/swoole-src/pull/6149.');
        }
    }
}
