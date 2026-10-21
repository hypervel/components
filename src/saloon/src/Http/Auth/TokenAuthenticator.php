<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

class TokenAuthenticator implements Authenticator
{
    /**
     * Create a token authenticator.
     */
    public function __construct(
        #[SensitiveParameter]
        public readonly string $token,
        public readonly string $prefix = 'Bearer',
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->replaceHeaders(['Authorization' => trim($this->prefix . ' ' . $this->token)]);
    }
}
