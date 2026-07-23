<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

use GuzzleHttp\Client;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;

abstract class HttpServerIntegrationTestCase extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19506;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();

        $this->client = new Client([
            'base_uri' => sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort()),
            'http_errors' => false,
            'timeout' => 5,
        ]);
    }

    /**
     * Send an HTTP request to the integration server.
     */
    protected function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $uri, $options);
    }

    /**
     * Decode a JSON response body.
     */
    protected function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
