<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\DeviceCodeRepository;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Contracts\DeniedDeviceAuthorizationResponse;
use Hypervel\Passport\PendingAuthorizationRepository;
use Hypervel\Passport\ServerFactory;

class DenyDeviceAuthorizationController
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
    ) {
    }

    /**
     * Deny the device authorization request.
     */
    public function __invoke(
        Request $request,
        DeniedDeviceAuthorizationResponse $response
    ): DeniedDeviceAuthorizationResponse {
        $this->withErrorHandling(function () use ($request): void {
            $deviceCode = $this->getDeviceCodeFromSession($request);

            TokenIssuance::run($this->servers, fn () => $this->servers->authorizationServer()->completeDeviceAuthorizationRequest(
                $deviceCode->getIdentifier(),
                $deviceCode->getUserIdentifier(),
                false
            ), ['provider' => $this->getApprovingProvider()]);
        });

        return $response;
    }
}
