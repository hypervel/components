<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Engine\Exception\HttpClientException;
use Hypervel\Engine\Http\Client;
use Throwable;

/**
 * Integration tests for the HTTP Client.
 *
 * These tests require an HTTP server running on the configured host/port.
 *
 * @internal
 * @coversNothing
 */
class ClientTest extends EngineIntegrationTestCase
{
    public function testClientRequest(): void
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $response = $client->request('GET', '/');
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Hyperf'], $response->headers['server']);
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
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $response = $client->request(
            'POST',
            '/',
            ['Content-Type' => 'application/json charset=UTF-8'],
            json_encode(['name' => 'Hyperf'], JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Hyperf'], $response->headers['server']);
        $this->assertSame('Hello World.', $response->body);
    }

    public function testClientSocketConnectionTimeout(): void
    {
        try {
            $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
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
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $response = $client->request('GET', '/cookies');
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Hyperf'], $response->headers['server']);
        $this->assertSame([
            'X-Server-Id=' . $response->body,
            'X-Server-Name=Hyperf',
        ], $response->headers['set-cookie']);
    }

    public function testGuzzleClientWithCookies(): void
    {
        // TODO: Enable this test once the hypervel/guzzle package is ported from Hyperf
        $this->markTestSkipped('Requires hypervel/guzzle package (CoroutineHandler)');

        // $client = new GuzzleHttp\Client([
        //     'base_uri' => sprintf('http://%s:%d/', $this->getHttpServerHost(), $this->getHttpServerPort()),
        //     'handler' => GuzzleHttp\HandlerStack::create(new CoroutineHandler()),
        //     'cookies' => true,
        // ]);
        //
        // $response = $client->get('cookies');
        //
        // $cookies = $client->getConfig('cookies');
        //
        // $this->assertSame((string) $response->getBody(), $cookies->toArray()[0]['Value']);
        // $this->assertSame('Hyperf', $cookies->toArray()[1]['Value']);
    }

    public function testServerHeaders(): void
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $response = $client->request('GET', '/header');
        if (SWOOLE_VERSION_ID >= 60000) {
            $this->assertSame($response->body, $response->headers['x-id'][1]);
        } else {
            // Co Client won't support getting multi response headers.
            $this->assertSame($response->body, implode(',', $response->headers['x-id']));
        }

        // TODO: Enable Guzzle tests once the hypervel/guzzle package is ported from Hyperf
        // $client = new GuzzleHttp\Client([
        //     'base_uri' => sprintf('http://%s:%d/', $this->getHttpServerHost(), $this->getHttpServerPort()),
        //     'handler' => GuzzleHttp\HandlerStack::create(new CoroutineHandler()),
        // ]);
        //
        // $response = $client->get('/header');
        // if (SWOOLE_VERSION_ID >= 60000) {
        //     $this->assertSame((string) $response->getBody(), $response->getHeader('x-id')[1]);
        // } else {
        //     // Co Client Won't support to get multi response headers.
        //     $this->assertSame((string) $response->getBody(), $response->getHeaderLine('x-id'));
        // }
        //
        // // When Swoole version > 4.5, The native curl support to get multi response headers.
        // if (SWOOLE_VERSION_ID >= 40600) {
        //     $client = new GuzzleHttp\Client([
        //         'base_uri' => sprintf('http://%s:%d/', $this->getHttpServerHost(), $this->getHttpServerPort()),
        //     ]);
        //     $response = $client->get('/header');
        //     $this->assertSame(2, count($response->getHeader('x-id')));
        //     $this->assertSame((string) $response->getBody(), $response->getHeader('x-id')[1]);
        // }
    }

    public function testClientNotFound(): void
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $response = $client->request('GET', '/not_found');
        $this->assertSame(404, $response->statusCode);
    }
}
