<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\AuthorizationFinalizer;
use Hypervel\Passport\Bridge\DeviceCodeRepository;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Contracts\ApprovedDeviceAuthorizationResponse;
use Hypervel\Passport\Enums\AuthorizationApproval;
use Hypervel\Passport\PendingAuthorizationRepository;
use Hypervel\Passport\ServerFactory;

class ApproveDeviceAuthorizationController
{
    use HandlesOAuthErrors;
    use RetrievesDeviceCodeFromSession;
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
        protected DeviceCodeRepository $deviceCodes,
        protected AuthorizationFinalizer $finalizer,
    ) {
    }

    /**
     * Approve the device authorization request.
     */
    public function __invoke(
        Request $request,
        ApprovedDeviceAuthorizationResponse $response
    ): ApprovedDeviceAuthorizationResponse {
        $this->withErrorHandling(function () use ($request): void {
            $pending = $this->getPendingAuthorization($request);
            $deviceCode = $this->getDeviceCodeFromSession($request);

            $this->finalizer->approve(
                $request,
                $deviceCode,
                $pending,
                $this->getApprovingGuard()->user(),
                AuthorizationApproval::Prompt,
                fn () => $this->servers->authorizationServer()->completeDeviceAuthorizationRequest(
                    $deviceCode->getIdentifier(),
                    $deviceCode->getUserIdentifier(),
                    true,
                ),
            );
        });

        return $response;
    }
}
