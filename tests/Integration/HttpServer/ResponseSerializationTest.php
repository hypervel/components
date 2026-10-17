<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use Hypervel\Engine\Http\V2\Client as Http2Client;
use Hypervel\Engine\Http\V2\Request as Http2Request;

class ResponseSerializationTest extends HttpServerIntegrationTestCase
{
    public function testResponseSerializationDoesNotMutateHeaderValues(): void
    {
        // @TODO: Remove this skip once Swoole preserves response header values during serialization.
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Swoole mutates caller-owned response header values while serializing them.');
        }

        $token = bin2hex(random_bytes(8));
        $this->request('GET', '/response-header?token=' . $token);
        $state = $this->decode($this->request('GET', '/response-header-state?token=' . $token));

        $this->assertSame(bin2hex("value\t "), $state['value']);
    }

    public function testHttpTwoResponseSerializationDoesNotMutateHeaderValues(): void
    {
        // @TODO: Remove this skip once Swoole preserves response header values during serialization.
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Swoole mutates caller-owned response header values while serializing them.');
        }

        $token = bin2hex(random_bytes(8));
        $client = new Http2Client($this->getServerHost(), $this->getServerPort());

        try {
            $client->send(new Http2Request('/response-header?token=' . $token));
            $response = $client->recv(5);
        } finally {
            $client->close();
        }

        $this->assertNotNull($response);
        $state = $this->decode($this->request('GET', '/response-header-state?token=' . $token));
        $this->assertSame(bin2hex("value\t "), $state['value']);
    }
}
