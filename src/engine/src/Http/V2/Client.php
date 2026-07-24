<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http\V2;

use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use Hypervel\Contracts\Engine\Http\V2\RequestInterface;
use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;
use Hypervel\Engine\Exceptions\HttpClientException;
use InvalidArgumentException;
use Swoole\Coroutine\Http2\Client as HTTP2Client;
use Swoole\Coroutine\Http2\Client\Exception as NativeHttp2Exception;
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
        try {
            $this->client = new HTTP2Client($host, $port, $ssl);

            if ($settings !== [] && $this->client->set($settings) === false) {
                throw $this->transportException('Unable to configure the HTTP/2 client.');
            }

            if ($this->client->connect() === false) {
                throw $this->transportException('Unable to connect the HTTP/2 client.');
            }
        } catch (NativeHttp2Exception $exception) {
            throw new HttpClientException(
                $exception->getMessage(),
                $exception->getCode(),
                $exception,
            );
        }
    }

    /**
     * Send an HTTP/2 request.
     */
    public function send(RequestInterface $request, ?float $timeout = null): int
    {
        try {
            $this->applyWriteTimeout($timeout);
            $streamId = $this->client->send($this->transformRequest($request));
        } catch (NativeHttp2Exception $exception) {
            throw $this->transportException('Unable to send the HTTP/2 request.', $exception);
        }

        if ($streamId === false) {
            throw $this->transportException('Unable to send the HTTP/2 request.');
        }

        return $streamId;
    }

    /**
     * Receive an HTTP/2 response.
     */
    public function recv(float $timeout = 0): ?ResponseInterface
    {
        try {
            $response = $this->client->recv($timeout);
        } catch (NativeHttp2Exception $exception) {
            throw $this->transportException('Unable to receive the HTTP/2 response.', $exception);
        }

        if ($response === false) {
            if ($this->client->errCode === SOCKET_ETIMEDOUT) {
                return null;
            }

            throw $this->transportException('Unable to receive the HTTP/2 response.');
        }

        return $this->transformResponse($response);
    }

    /**
     * Write data to a stream.
     */
    public function write(
        int $streamId,
        string $data,
        bool $end = false,
        ?float $timeout = null,
    ): void {
        try {
            $this->applyWriteTimeout($timeout);
            $written = $this->client->write($streamId, $data, $end);
        } catch (NativeHttp2Exception $exception) {
            throw $this->transportException('Unable to write HTTP/2 stream data.', $exception);
        }

        if ($written === false) {
            throw $this->transportException('Unable to write HTTP/2 stream data.');
        }
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if (! $this->client->connected) {
            return;
        }

        try {
            $closed = $this->client->close();
        } catch (NativeHttp2Exception $exception) {
            throw $this->transportException('Unable to close the HTTP/2 client.', $exception);
        }

        if ($closed === false) {
            throw $this->transportException('Unable to close the HTTP/2 client.');
        }
    }

    /**
     * Determine if the client is connected.
     */
    public function isConnected(): bool
    {
        return $this->client->connected;
    }

    /**
     * Determine whether the stream remains open.
     */
    public function isStreamOpen(int $streamId): bool
    {
        try {
            return $this->client->isStreamExist($streamId);
        } catch (NativeHttp2Exception $exception) {
            throw $this->transportException('Unable to inspect the HTTP/2 stream.', $exception);
        }
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
            $response->pipeline,
        );
    }

    /**
     * Transform a request interface to a Swoole request.
     */
    private function transformRequest(RequestInterface $request): SwRequest
    {
        $nativeRequest = new SwRequest;
        $nativeRequest->method = $request->getMethod();
        $nativeRequest->path = $request->getPath();
        $nativeRequest->headers = $request->getHeaders();
        $nativeRequest->data = $request->getBody();
        $nativeRequest->pipeline = $request->isPipeline();
        $nativeRequest->usePipelineRead = $request->usesPipelineRead(); // @phpstan-ignore property.notFound (exists in Swoole 5.1.0+)

        return $nativeRequest;
    }

    /**
     * Apply a timeout to the next serialized socket write operation.
     */
    private function applyWriteTimeout(?float $timeout): void
    {
        if ($timeout === null) {
            return;
        }

        if (! is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException(
                'The HTTP/2 write timeout must be a positive finite number of seconds.',
            );
        }

        if ($this->client->set(['write_timeout' => $timeout]) === false) {
            throw $this->transportException('Unable to configure the HTTP/2 write timeout.');
        }
    }

    /**
     * Create a normalized transport exception.
     */
    private function transportException(
        string $fallbackMessage,
        ?NativeHttp2Exception $previous = null,
    ): HttpClientException {
        $message = $previous?->getMessage() ?: $this->client->errMsg ?: $fallbackMessage;
        $code = $previous?->getCode() ?: $this->client->errCode;

        return new HttpClientException($message, $code, $previous);
    }
}
