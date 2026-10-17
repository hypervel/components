<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Protocol;
use Hypervel\Mcp\Client\Schema\InitializeResult;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Schema\Implementation;

/**
 * @implements Method<InitializeResult>
 */
class Initialize implements Method
{
    /**
     * Create an initialization request.
     */
    public function __construct(
        protected Implementation $clientInfo,
        protected ProtocolVersion $protocolVersion = ProtocolVersion::V2025_11_25,
    ) {
    }

    /**
     * Get the initialization method name.
     */
    public function method(): string
    {
        return 'initialize';
    }

    /**
     * Get the client information and requested protocol version.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return [
            'protocolVersion' => $this->protocolVersion->value,
            'capabilities' => (object) [],
            'clientInfo' => $this->clientInfo->toArray(),
        ];
    }

    /**
     * Initialize the connection and hydrate the server's response.
     */
    public function handle(Protocol $protocol): InitializeResult
    {
        return InitializeResult::from($protocol->dispatch($this));
    }
}
