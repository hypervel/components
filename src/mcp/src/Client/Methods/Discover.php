<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Protocol;
use Hypervel\Mcp\Client\Schema\DiscoverResult;

/**
 * @implements Method<DiscoverResult>
 */
class Discover implements Method
{
    /**
     * Get the discovery method name.
     */
    public function method(): string
    {
        return 'server/discover';
    }

    /**
     * Get the discovery parameters.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return [];
    }

    /**
     * Discover the server's capabilities and implementation.
     */
    public function handle(Protocol $protocol): DiscoverResult
    {
        return DiscoverResult::from($protocol->dispatch($this));
    }
}
