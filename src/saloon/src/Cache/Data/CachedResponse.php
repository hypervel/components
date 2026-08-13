<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Cache\Data;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Saloon\Http\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class CachedResponse
{
    /**
     * Create a cached response value.
     *
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * Create a cached value from a Saloon response.
     */
    public static function fromResponse(Response $response): self
    {
        return new self(
            $response->status(),
            $response->headers(),
            $response->body(),
        );
    }

    /**
     * Create the cached PSR response.
     */
    public function toPsrResponse(): ResponseInterface
    {
        return new PsrResponse($this->status, $this->headers, $this->body);
    }
}
