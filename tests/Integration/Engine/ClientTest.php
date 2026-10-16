<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use GuzzleHttp;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\Client;
use Throwable;

/**
 * Integration tests for the HTTP Client.
 *
 * These tests require an HTTP server running on the configured host/port.
 */
class ClientTest extends EngineIntegrationTestCase
{
    public function testClientRequest(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $response = $client->request('GET', '/');
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Hypervel'], $response->headers['server']);
        $this->assertSame('Hello World.', $response->body);
    }

    public function testClientSocketConnectionRefused(): void
    {
        try {
            // Use a port that definitely has no server running
            $client = new Client('127.0.0.1', 29501);
            $client->request('GET', '/timeout?time=1');
            $this->fail('Expected HttpClientException to be thrown');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(HttpClientException::class, $exception);
            $this->assertSame(SOCKET_ECONNREFUSED, $exception->getCode());
            $this->assertSame('Connection refused', $exception->getMessage());
        }
    }

    public function testClientJsonRequest(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $response = $client->request(
            'POST',
            '/',
            ['Content-Type' => 'application/json charset=UTF-8'],
            json_encode(['name' => 'Hypervel'], JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Hypervel'], $response->headers['server']);
        $this->assertSame('Hello World.', $response->body);
    }

    public function testClientSocketConnectionTimeout(): void
    {
        try {
            $client = new Client($this->getServerHost(), $this->getServerPort());
            $client->set(['timeout' => 0.1]);
            $client->request('GET', '/timeout?time=1');
            $this->fail('Expected HttpClientException to be thrown');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(HttpClientException::class, $exception);
            $this->assertSame(SOCKET_ETIMEDOUT, $exception->getCode());
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }
    }

    public function testClientCookies(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $response = $client->request('GET', '/cookies');
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Hypervel'], $response->headers['server']);
        $this->assertSame([
            'X-Server-Id=' . $response->body,
            'X-Server-Name=Hypervel',
        ], $response->headers['set-cookie']);
    }

    public function testGuzzleClientWithCookies(): void
    {
        $client = new GuzzleHttp\Client([
            'base_uri' => sprintf('http://%s:%d/', $this->getServerHost(), $this->getServerPort()),
            'cookies' => true,
        ]);

        $response = $client->get('cookies');

        $cookies = $client->getConfig('cookies');

        $this->assertSame((string) $response->getBody(), $cookies->toArray()[0]['Value']);
        $this->assertSame('Hypervel', $cookies->toArray()[1]['Value']);
    }

    public function testServerHeaders(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $response = $client->request('GET', '/header');
        $this->assertSame($response->body, $response->headers['x-id'][1]);

        $client = new GuzzleHttp\Client([
            'base_uri' => sprintf('http://%s:%d/', $this->getServerHost(), $this->getServerPort()),
        ]);

        $response = $client->get('/header');
        $this->assertCount(2, $response->getHeader('x-id'));
        $this->assertSame((string) $response->getBody(), $response->getHeader('x-id')[1]);
    }

    public function testClientNotFound(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $response = $client->request('GET', '/not_found');
        $this->assertSame(404, $response->statusCode);
    }

    public function testClientRejectsInvalidDeferredSocketSettings(): void
    {
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Requires rejected deferred client settings from https://github.com/swoole/swoole-src/pull/6267.');
        }

        $client = new Client($this->getServerHost(), $this->getServerPort());
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $this->assertTrue($client->set([
                'socks5_host' => '127.0.0.1',
                'timeout' => 0.2,
            ]));

            try {
                $client->request('GET', '/');
                $this->fail('Invalid deferred socket settings must stop the request.');
            } catch (HttpClientException $exception) {
                $this->assertSame(SWOOLE_ERROR_INVALID_PARAMS, $exception->getCode());
            }

            $this->assertTrue((bool) array_filter(
                $warnings,
                static fn (string $warning): bool => str_contains($warning, 'socks5_port should not be null'),
            ));
        } finally {
            restore_error_handler();
            $client->close();
        }
    }
}
