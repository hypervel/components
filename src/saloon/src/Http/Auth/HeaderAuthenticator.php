<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

readonly class HeaderAuthenticator implements Authenticator
{
    /**
     * Create a header authenticator.
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        public string $headerName = 'Authorization',
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->replaceHeaders([$this->headerName => $this->accessToken]);
    }
}
