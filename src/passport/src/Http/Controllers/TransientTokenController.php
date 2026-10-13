<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Passport\ApiTokenCookieFactory;

class TransientTokenController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ApiTokenCookieFactory $cookieFactory,
    ) {
    }

    /**
     * Get a fresh transient token cookie for the authenticated user.
     */
    public function refresh(Request $request): Response
    {
        return (new Response('Refreshed.'))->withCookie($this->cookieFactory->make(
            $request->user()->getAuthIdentifier(),
            $request->session()->token()
        ));
    }
}
