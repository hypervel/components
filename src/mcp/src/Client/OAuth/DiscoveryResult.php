<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

class DiscoveryResult
{
    /**
     * Create a discovery result.
     *
     * @param array<int, string> $scopesSupported
     */
    public function __construct(
        public AuthServerMetadata $server,
        public array $scopesSupported = [],
        public ?string $resource = null,
    ) {
    }
}
