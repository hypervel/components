<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\AuthorizationFinalizer;
use Hypervel\Passport\AuthorizationRequestResolver;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Enums\AuthorizationApproval;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Passport\PendingAuthorizationRepository;
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
        protected AuthManager $auth,
        protected Repository $config,
        protected ClientRepository $clients,
        protected PendingAuthorizationRepository $pending,
        protected AuthorizationRequestResolver $authorizations,
        protected AuthorizationFinalizer $finalizer,
        protected AuthorizationResponse $responses,
    ) {
    }

    /**
     * Approve the authorization request.
     */
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $pending = $this->getPendingAuthorization($request);

        return $this->withAuthorizationErrorHandling(function () use ($request, $psrResponse, $pending): Response {
            $authRequest = $this->getAuthRequestFromSession($request);
            $authRequest->setAuthorizationApproved(true);

            $response = $this->finalizer->approve(
                $request,
                $authRequest,
                $pending,
                $this->getApprovingGuard()->user(),
                AuthorizationApproval::Prompt,
                fn () => $this->convertResponse($this->servers->authorizationServer()->completeAuthorizationRequest($authRequest, $psrResponse)),
            );

            return $this->responses->success(
                $response,
                $pending['redirect_uri'],
                $pending['parameters']['state'] ?? null,
                $pending['response_mode'],
            );
        }, $pending, $this->responses);
    }
}
