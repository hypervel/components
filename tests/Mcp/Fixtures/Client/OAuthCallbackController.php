<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures\Client;

use Hypervel\Http\RedirectResponse;
use Hypervel\Mcp\Client\OAuth\TokenSet;

class OAuthCallbackController
{
    /**
     * Redirect after the test client exchanges its token.
     */
    public function callback(string $provider, TokenSet $token): RedirectResponse
    {
        return redirect("/connected/{$provider}");
    }
}
