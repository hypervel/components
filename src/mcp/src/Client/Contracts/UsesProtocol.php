<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Contracts;

use Hypervel\Mcp\Enums\ProtocolVersion;

interface UsesProtocol
{
    /**
     * Select the protocol version for subsequent exchanges.
     */
    public function useProtocol(ProtocolVersion $protocolVersion): void;
}
