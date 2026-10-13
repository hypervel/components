<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Contracts\ApprovedDeviceAuthorizationResponse;
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
        protected AuthFactory $auth,
        protected Repository $config,
        protected ClientRepository $clients,
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
            $deviceCode = $this->getDeviceCodeFromSession($request);
            $attributes = $request->session()->pull('authAttributes', []);

            TokenIssuance::run($this->servers, fn () => $this->servers->authorizationServer()->completeDeviceAuthorizationRequest(
                $deviceCode->getIdentifier(),
                $deviceCode->getUserIdentifier(),
                true
            ), $attributes);
        });

        return $response;
    }
}
