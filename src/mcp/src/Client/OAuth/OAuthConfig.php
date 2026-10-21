<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

use SensitiveParameter;

class OAuthConfig
{
    /**
     * Create the OAuth configuration for an MCP resource.
     */
    public function __construct(
        public ?string $clientId = null,
        #[SensitiveParameter]
        public ?string $clientSecret = null,
        public ?string $scope = null,
        public ?string $redirectUri = null,
    ) {
    }
}
