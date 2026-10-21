<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Passport\ServerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AccessTokenController
{
    use ConvertsPsrResponses;
    use HandlesOAuthErrors;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ServerFactory $servers,
    ) {
    }

    /**
     * Issue an access token.
     */
    public function issueToken(ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        return $this->withErrorHandling(fn () => $this->convertResponse(
            $this->servers->authorizationServer()->respondToAccessTokenRequest($psrRequest, $psrResponse)
        ));
    }
}
