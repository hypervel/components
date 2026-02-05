<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Engine\Http\Client;
use Hypervel\Support\Json;

/**
 * Integration tests for the HTTP Server.
 *
 * These tests require an HTTP server running on the configured host/port.
 *
 * @internal
 * @coversNothing
 */
class HttpServerTest extends EngineIntegrationTestCase
{
    /**
     * The HTTP server port for these tests.
     */
    protected int $httpServerPort = 9505;

    public function testHttpServerHelloWorld(): void
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $response = $client->request('GET', '/');
        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Hello World.', $response->body);
    }

    public function testHttpServerReceived(): void
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $response = $client->request('POST', '/', contents: 'Hyperf');
        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Received: Hyperf', $response->body);
    }

    public function testHttpServerCookies(): void
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());

        $client->setCookies(['key' => 'value']);

        $response = $client->request('POST', '/set-cookies', ['user_id' => uniqid()], Json::encode(['id' => $id = uniqid()]));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(1, count($response->getHeaders()['set-cookie']));
        $this->assertStringStartsWith('id=' . $id, $response->getHeaders()['set-cookie'][0]);
        $json = Json::decode((string) $response->getBody());
        $this->assertSame(['key' => 'value'], $json);

        $response = $client->request('POST', '/set-cookies', [], Json::encode(['id2' => $id2 = uniqid()]));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(1, count($response->getHeaders()['set-cookie']));
        $this->assertStringStartsWith('id2=' . $id2, $response->getHeaders()['set-cookie'][0]);
        $json = Json::decode((string) $response->getBody());
        $this->assertSame(['key' => 'value', 'id' => $id], $json);

        $client->setCookies([]);
        $response = $client->request('POST', '/set-cookies', [], Json::encode(['id2' => $id2 = uniqid()]));
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(1, count($response->getHeaders()['set-cookie']));
        $this->assertStringStartsWith('id2=' . $id2, $response->getHeaders()['set-cookie'][0]);
        $json = Json::decode((string) $response->getBody());
        $this->assertSame([], $json);
    }
}
