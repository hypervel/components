<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Authenticators;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;

class PizzaAuthenticator implements Authenticator
{
    /**
     * Create a pizza authenticator.
     */
    public function __construct(
        public string $pizza,
        public string $drink,
    ) {
    }

    /**
     * Set the pending request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->replaceHeaders(['X-Pizza' => $this->pizza, 'X-Drink' => $this->drink]);

        $pendingRequest->withOptions(['debug' => true]);
    }
}
