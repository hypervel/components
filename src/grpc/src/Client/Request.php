<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Hypervel\Contracts\Engine\Http\V2\RequestInterface;

/**
 * @internal
 */
final readonly class Request implements RequestInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $path,
        private string $method,
        private string $body,
        private array $headers,
        private bool $pipeline,
        private bool $usePipelineRead,
    ) {
    }

    /**
     * Get the request path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the request method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the request headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get the request body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Determine whether the request body remains open.
     */
    public function isPipeline(): bool
    {
        return $this->pipeline;
    }

    /**
     * Determine whether the response is read incrementally.
     */
    public function usesPipelineRead(): bool
    {
        return $this->usePipelineRead;
    }
}
