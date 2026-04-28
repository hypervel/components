<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\Contracts\Engine\Http\RawResponseInterface;

final class RawResponse implements RawResponseInterface
{
    /**
     * Create a new raw response instance.
     *
     * @param string[][] $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public string $version
    ) {
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
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get the HTTP protocol version.
     */
    public function getVersion(): string
    {
        return $this->version;
    }
}
