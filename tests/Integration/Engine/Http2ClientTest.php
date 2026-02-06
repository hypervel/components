<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Engine\Http\V2\Client;
use Hypervel\Engine\Http\V2\Request;

/**
 * Integration tests for the HTTP/2 Client.
 *
 * These tests require an HTTP/2 server running on the configured host/port.
 *
 * @internal
 * @coversNothing
 */
class Http2ClientTest extends EngineIntegrationTestCase
{
    public function testHttp2ServerReceived()
    {
        $client = new Client($this->getHttpServerHost(), $this->getHttpServerPort());
        $client->send(new Request('/'));
        $response = $client->recv(1);
        $this->assertSame('Hello World.', $response->getBody());

        $client->send(new Request('/header'));
        $response = $client->recv(1);
        $id = $response->getHeaders()['x-id'];
        $this->assertSame($id, $response->getBody());

        $client->send(new Request('/not-found'));
        $response = $client->recv(1);
        $this->assertSame(404, $response->getStatusCode());

        $this->assertTrue($client->isConnected());

        $client->close();

        $this->assertFalse($client->isConnected());
    }
}
