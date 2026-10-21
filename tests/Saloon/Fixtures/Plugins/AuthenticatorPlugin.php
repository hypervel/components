<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Plugins;

use Hypervel\Saloon\Http\PendingRequest;

trait AuthenticatorPlugin
{
    /**
     * Authenticate the pending request with a token.
     */
    public function bootAuthenticatorPlugin(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withToken('plugin-auth');
    }
}
