<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Exceptions\MissingAuthenticatorException;
use Hypervel\Saloon\Http\PendingRequest;

trait RequiresAuth
{
    /**
     * Throw an exception if an authenticator is not on the request while it is booting.
     *
     * @throws MissingAuthenticatorException
     */
    public function bootRequiresAuth(PendingRequest $pendingRequest): void
    {
        $authenticator = $pendingRequest->authenticator();

        if (! $authenticator instanceof Authenticator) {
            throw new MissingAuthenticatorException($this->getRequiresAuthMessage($pendingRequest));
        }
    }

    /**
     * Default message.
     */
    protected function getRequiresAuthMessage(PendingRequest $pendingRequest): string
    {
        return sprintf('The "%s" request requires authentication.', $pendingRequest->request()::class);
    }
}
