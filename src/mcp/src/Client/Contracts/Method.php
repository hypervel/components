<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Contracts;

use Hypervel\Mcp\Client\Protocol;

/**
 * @template TResult
 */
interface Method
{
    /**
     * Get the protocol method name.
     */
    public function method(): string;

    /**
     * Get the request parameters.
     *
     * @return array<string, mixed>
     */
    public function params(): array;

    /**
     * Execute the method through the protocol.
     *
     * @return TResult
     */
    public function handle(Protocol $protocol);
}
