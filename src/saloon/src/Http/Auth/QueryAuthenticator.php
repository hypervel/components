<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

readonly class QueryAuthenticator implements Authenticator
{
    /**
     * Create a query authenticator.
     */
    public function __construct(
        public string $parameter,
        #[SensitiveParameter]
        public string $value,
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withQueryParameters([$this->parameter => $this->value]);
    }
}
