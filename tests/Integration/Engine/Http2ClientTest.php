<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Engine;

use Hypervel\Engine\Http\V2\Client;
use Hypervel\Engine\Http\V2\ClientFactory;
use Hypervel\Engine\Http\V2\Request;
use ReflectionProperty;
use Swoole\Coroutine\Http2\Client as NativeClient;

/**
 * Integration tests for the HTTP/2 Client.
 *
 * These tests require an HTTP/2 server running on the configured host/port.
 */
class Http2ClientTest extends EngineIntegrationTestCase
{
    public function testHttp2ServerReceived(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $client->send(new Request('/'));
        $response = $client->recv(1);
        $this->assertNotNull($response);
        $this->assertSame('Hello World.', $response->getBody());
        $this->assertTrue($response->isEndStream());

        $client->send(new Request('/header'));
        $response = $client->recv(1);
        $this->assertNotNull($response);
        $id = $response->getHeaders()['x-id'];
        $this->assertSame($id, $response->getBody());

        $client->send(new Request('/not-found'));
        $response = $client->recv(1);
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());

        $this->assertTrue($client->isConnected());

        $client->close();

        $this->assertFalse($client->isConnected());
    }

    public function testFactoryAppliesSettingsBeforeConnecting(): void
    {
        $client = (new ClientFactory)->make(
            $this->getServerHost(),
            $this->getServerPort(),
            settings: ['connect_timeout' => 2.5],
        );

        $this->assertInstanceOf(Client::class, $client);

        $native = (new ReflectionProperty(Client::class, 'client'))->getValue($client);

        $this->assertInstanceOf(NativeClient::class, $native);
        $this->assertSame(2.5, $native->setting['connect_timeout']);

        $client->close();
    }

    public function testReceivePollTimeoutAndStreamExistenceAreNormalized(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $streamId = $client->send(new Request('/timeout?time=1'));

        $this->assertTrue($client->isStreamOpen($streamId));
        $this->assertNull($client->recv(0.01));
        $this->assertTrue($client->isStreamOpen($streamId));

        $response = $client->recv(2);

        $this->assertNotNull($response);
        $this->assertTrue($response->isEndStream());
        $this->assertFalse($client->isStreamOpen($streamId));

        $client->close();
    }
}
