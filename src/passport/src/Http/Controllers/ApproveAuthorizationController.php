<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\ServerFactory;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class ApproveAuthorizationController
{
    use ConvertsPsrResponses;
    use HandlesOAuthErrors;
    use RetrievesAuthRequestFromSession;
    use ResolvesApprovingGuard;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ServerFactory $servers,
        protected AuthFactory $auth,
        protected Repository $config,
        protected ClientRepository $clients,
    ) {
    }

    /**
     * Approve the authorization request.
     */
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        return $this->withErrorHandling(function () use ($request, $psrResponse): Response {
            $authRequest = $this->getAuthRequestFromSession($request);
            $attributes = $request->session()->pull('authAttributes', []);

            $authRequest->setAuthorizationApproved(true);

            return TokenIssuance::run($this->servers, fn () => $this->convertResponse(
                $this->servers->authorizationServer()->completeAuthorizationRequest($authRequest, $psrResponse)
            ), $attributes);
        });
    }
}
