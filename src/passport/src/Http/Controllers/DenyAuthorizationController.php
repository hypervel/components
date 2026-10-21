<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\AuthorizationRequestResolver;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Passport\PendingAuthorizationRepository;
use Hypervel\Passport\ServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class DenyAuthorizationController
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
        protected AuthManager $auth,
        protected Repository $config,
        protected ClientRepository $clients,
        protected PendingAuthorizationRepository $pending,
        protected AuthorizationRequestResolver $authorizations,
        protected AuthorizationResponse $responses,
    ) {
    }

    /**
     * Deny the authorization request.
     */
    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        $pending = $this->getPendingAuthorization($request);

        return $this->withAuthorizationErrorHandling(function () use ($request, $pending): Response {
            $this->getAuthRequestFromSession($request);

            return $this->responses->error(
                OAuthServerException::accessDenied(),
                $pending['redirect_uri'],
                $pending['parameters']['state'] ?? null,
                $pending['response_mode'],
            );
        }, $pending, $this->responses);
    }
}
