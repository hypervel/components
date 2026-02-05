<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http\V2;

use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use Hypervel\Contracts\Engine\Http\V2\RequestInterface;
use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;
use Hypervel\Engine\Exception\HttpClientException;
use Swoole\Coroutine\Http2\Client as HTTP2Client;
use Swoole\Http2\Request as SwRequest;
use Swoole\Http2\Response as SwResponse;

class Client implements ClientInterface
{
    protected HTTP2Client $client;

    /**
     * Create a new HTTP/2 client instance.
     */
    public function __construct(string $host, int $port = 80, bool $ssl = false, array $settings = [])
    {
        $this->client = new HTTP2Client($host, $port, $ssl);

        if ($settings) {
            $this->client->set($settings);
        }

        $this->client->connect();
    }

    /**
     * Set the client settings.
     */
    public function set(array $settings): bool
    {
        return $this->client->set($settings);
    }

    /**
     * Send an HTTP/2 request.
     */
    public function send(RequestInterface $request): int
    {
        $res = $this->client->send($this->transformRequest($request));
        if ($res === false) {
            throw new HttpClientException($this->client->errMsg, $this->client->errCode);
        }

        return $res;
    }

    /**
     * Receive an HTTP/2 response.
     */
    public function recv(float $timeout = 0): ResponseInterface
    {
        $response = $this->client->recv($timeout);
        if ($response === false) {
            throw new HttpClientException($this->client->errMsg, $this->client->errCode);
        }

        return $this->transformResponse($response);
    }

    /**
     * Write data to a stream.
     */
    public function write(int $streamId, mixed $data, bool $end = false): bool
    {
        return $this->client->write($streamId, $data, $end);
    }

    /**
     * Send a ping frame.
     */
    public function ping(): bool
    {
        return $this->client->ping();
    }

    /**
     * Close the connection.
     */
    public function close(): bool
    {
        return $this->client->close();
    }

    /**
     * Determine if the client is connected.
     */
    public function isConnected(): bool
    {
        return $this->client->connected;
    }

    /**
     * Transform a Swoole response to a response interface.
     */
    private function transformResponse(SwResponse $response): ResponseInterface
    {
        return new Response(
            $response->streamId,
            $response->statusCode,
            $response->headers ?? [],
            $response->data,
        );
    }

    /**
     * Transform a request interface to a Swoole request.
     */
    private function transformRequest(RequestInterface $request): SwRequest
    {
        $req = new SwRequest();
        $req->method = $request->getMethod();
        $req->path = $request->getPath();
        $req->headers = $request->getHeaders();
        $req->data = $request->getBody();
        $req->pipeline = $request->isPipeline();
        $req->usePipelineRead = $request->isPipeline(); // @phpstan-ignore property.notFound (exists in Swoole 5.1.0+)
        return $req;
    }
}
