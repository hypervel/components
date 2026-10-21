<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

use SensitiveParameter;

class ClientRegistration
{
    /**
     * Create the credentials returned by client registration.
     */
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        public ?string $clientSecret = null,
    ) {
    }
}
