<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client;

use Hypervel\Mcp\Client\Schema\DiscoverResult;
use Hypervel\Mcp\Client\Schema\InitializeResult;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Schema\Implementation;

class NegotiatedConnection
{
    /**
     * Create a negotiated connection.
     */
    public function __construct(
        public ProtocolVersion $protocolVersion,
        public DiscoverResult|InitializeResult $result,
    ) {
    }

    /**
     * Get the modern discovery result, if available.
     */
    public function discoverResult(): ?DiscoverResult
    {
        return $this->result instanceof DiscoverResult ? $this->result : null;
    }

    /**
     * Get the legacy initialization result, if available.
     */
    public function initializeResult(): ?InitializeResult
    {
        return $this->result instanceof InitializeResult ? $this->result : null;
    }

    /**
     * Get the advertised server capabilities.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return $this->result->capabilities;
    }

    /**
     * Get the server implementation information.
     */
    public function serverInfo(): ?Implementation
    {
        return $this->result->serverInfo;
    }

    /**
     * Get the server instructions.
     */
    public function instructions(): ?string
    {
        return $this->result->instructions;
    }
}
