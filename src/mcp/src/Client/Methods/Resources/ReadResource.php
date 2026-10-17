<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods\Resources;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Protocol;
use Hypervel\Mcp\Client\Schema\ResourceReadResult;

/**
 * @implements Method<ResourceReadResult>
 */
class ReadResource implements Method
{
    /**
     * Create a resource read request.
     */
    public function __construct(
        protected string $uri,
    ) {
    }

    /**
     * Get the resource read method name.
     */
    public function method(): string
    {
        return 'resources/read';
    }

    /**
     * Get the requested resource URI.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return ['uri' => $this->uri];
    }

    /**
     * Read and hydrate the resource contents.
     */
    public function handle(Protocol $protocol): ResourceReadResult
    {
        return ResourceReadResult::from($protocol->dispatch($this));
    }
}
