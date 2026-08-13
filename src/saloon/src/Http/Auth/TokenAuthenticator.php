<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

readonly class TokenAuthenticator implements Authenticator
{
    /**
     * Create a token authenticator.
     */
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public string $prefix = 'Bearer',
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withHeader('Authorization', trim($this->prefix . ' ' . $this->token));
    }
}
