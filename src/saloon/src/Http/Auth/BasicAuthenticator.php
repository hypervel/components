<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

readonly class BasicAuthenticator implements Authenticator
{
    /**
     * Create a basic authenticator.
     */
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        public string $password,
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->setTransportAuthentication([$this->username, $this->password]);
    }
}
