<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use GuzzleHttp;
use Hypervel\Engine\Exceptions\SocketConnectException;
use Hypervel\Engine\Exceptions\SocketTimeoutException;
use Hypervel\Engine\Http\Client;

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
        $this->assertSame(['Hypervel'], $response->headers['Server']);
        $this->assertSame('Hello World.', $response->body);
    }

    public function testClientSocketConnectionRefused(): void
    {
        try {
            // Use a port that definitely has no server running
            $client = new Client('127.0.0.1', 29501);
            $client->request('GET', '/timeout?time=1');
            $this->fail('Expected SocketConnectException to be thrown');
        } catch (SocketConnectException $exception) {
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
        $this->assertSame(['Hypervel'], $response->headers['Server']);
        $this->assertSame('Hello World.', $response->body);
    }

    public function testClientSocketConnectionTimeout(): void
    {
        try {
            $client = new Client($this->getServerHost(), $this->getServerPort());
            $client->set(['timeout' => 0.1]);
            $client->request('GET', '/timeout?time=1');
            $this->fail('Expected SocketTimeoutException to be thrown');
        } catch (SocketTimeoutException $exception) {
            $this->assertSame(SOCKET_ETIMEDOUT, $exception->getCode());
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }
    }

    public function testClientCookies(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $response = $client->request('GET', '/cookies');
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Hypervel'], $response->headers['Server']);
        $this->assertSame([
            'X-Server-Id=' . $response->body,
            'X-Server-Name=Hypervel',
        ], $response->headers['Set-Cookie']);
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
        $this->assertSame($response->body, $response->headers['X-Id'][1]);

        $client = new GuzzleHttp\Client([
            'base_uri' => sprintf('http://%s:%d/', $this->getServerHost(), $this->getServerPort()),
        ]);

        $response = $client->get('/header');
        $this->assertSame((string) $response->getBody(), $response->getHeader('x-id')[1]);

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
}
