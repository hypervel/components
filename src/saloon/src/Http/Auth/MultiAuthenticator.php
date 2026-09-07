<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;

class MultiAuthenticator implements Authenticator
{
    /**
     * The authenticators.
     *
     * @var list<Authenticator>
     */
    protected readonly array $authenticators;

    /**
     * Create a multi-authenticator.
     */
    public function __construct(Authenticator ...$authenticators)
    {
        $this->authenticators = $authenticators;
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        foreach ($this->authenticators as $authenticator) {
            $authenticator->set($pendingRequest);
        }
    }
}
