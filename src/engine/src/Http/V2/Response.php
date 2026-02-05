<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http\V2;

use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;

class Response implements ResponseInterface
{
    /**
     * Create a new HTTP/2 response instance.
     */
    public function __construct(
        protected int $streamId,
        protected int $statusCode,
        protected array $headers,
        protected ?string $body
    ) {
    }

    /**
     * Get the stream ID.
     */
    public function getStreamId(): int
    {
        return $this->streamId;
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the response headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get the response body.
     */
    public function getBody(): ?string
    {
        return $this->body;
    }
}
