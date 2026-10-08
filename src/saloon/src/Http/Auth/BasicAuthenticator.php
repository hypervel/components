<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

class BasicAuthenticator implements Authenticator
{
    /**
     * Create a basic authenticator.
     */
    public function __construct(
        public readonly string $username,
        #[SensitiveParameter]
        public readonly string $password,
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->replaceHeaders(['Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)]);
    }
}
