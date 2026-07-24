<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface RequestInterface
{
    /**
     * Get the request path.
     */
    public function getPath(): string;

    /**
     * Get the request method.
     */
    public function getMethod(): string;

    /**
     * Get the request headers.
     */
    public function getHeaders(): array;

    /**
     * Get the request body.
     */
    public function getBody(): string;

    /**
     * Determine whether the request uses pipelining.
     */
    public function isPipeline(): bool;

    /**
     * Determine whether the response is read incrementally.
     */
    public function usesPipelineRead(): bool;
}
