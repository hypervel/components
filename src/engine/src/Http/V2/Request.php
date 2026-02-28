<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http\V2;

use Hypervel\Contracts\Engine\Http\V2\RequestInterface;

class Request implements RequestInterface
{
    /**
     * Create a new HTTP/2 request instance.
     */
    public function __construct(
        protected string $path = '/',
        protected string $method = 'GET',
        protected string $body = '',
        protected array $headers = [],
        protected bool $pipeline = false
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
     * Set the request path.
     */
    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * Get the request method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Set the request method.
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * Get the request body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Set the request body.
     */
    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    /**
     * Get the request headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set the request headers.
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    /**
     * Determine if this is a pipeline request.
     */
    public function isPipeline(): bool
    {
        return $this->pipeline;
    }

    /**
     * Set whether this is a pipeline request.
     */
    public function setPipeline(bool $pipeline): void
    {
        $this->pipeline = $pipeline;
    }
}
