<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;

class NullAuthenticator implements Authenticator
{
    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
    }
}
