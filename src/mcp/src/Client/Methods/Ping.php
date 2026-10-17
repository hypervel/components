<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Protocol;

/**
 * @implements Method<void>
 */
class Ping implements Method
{
    /**
     * Get the ping method name.
     */
    public function method(): string
    {
        return 'ping';
    }

    /**
     * Get the ping parameters.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return [];
    }

    /**
     * Ping the server.
     */
    public function handle(Protocol $protocol): void
    {
        $protocol->dispatch($this);
    }
}
