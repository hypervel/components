<?php

declare(strict_types=1);

namespace Hypervel\Tests\Guzzle\Stub;

use Hypervel\Engine\Http\Client;
use Hypervel\Engine\Http\RawResponse;
use Hypervel\Guzzle\CoroutineHandler;
use Mockery;

/**
 * Test stub for CoroutineHandler that mocks the HTTP client.
 *
 * Returns a mock client that captures request details and returns
 * them as a JSON response body, allowing tests to verify the handler
 * is correctly configuring requests without making real HTTP calls.
 */
class CoroutineHandlerStub extends CoroutineHandler
{
    public int $count = 0;

    protected int $statusCode;

    public function __construct(int $statusCode = 200)
    {
        $this->statusCode = $statusCode;
    }

    /**
     * Expose createSink for testing.
     *
     * @param resource|string $sink
     * @return resource
     */
    public function createSink(string $body, $sink)
    {
        return parent::createSink($body, $sink);
    }

    /**
     * Expose rewriteHeaders for testing.
     */
    public function rewriteHeaders(array $headers): array
    {
        return parent::rewriteHeaders($headers);
    }

    /**
     * Create a mock client that captures request details.
     */
    protected function makeClient(string $host, int $port, bool $ssl): Client
    {
        $client = Mockery::mock(Client::class . '[request]', [$host, $port, $ssl]);
        $client->shouldReceive('request')->withAnyArgs()->andReturnUsing(function ($method, $path, $headers, $body) use ($host, $port, $ssl, $client) {
            ++$this->count;
            $body = json_encode([
                'host' => $host,
                'port' => $port,
                'ssl' => $ssl,
                'method' => $method,
                'headers' => $headers,
                'setting' => $client->setting,
                'uri' => $path,
                'body' => $body,
            ]);
            return new RawResponse($this->statusCode, [], $body, '1.1');
        });
        return $client;
    }
}
