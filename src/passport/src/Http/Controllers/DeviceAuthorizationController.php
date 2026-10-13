<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\DeviceCodeRepository;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Scope;
use Hypervel\Support\Str;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class DeviceAuthorizationController
{
    use HandlesOAuthErrors;
    use ResolvesApprovingGuard;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected AuthFactory $auth,
        protected DeviceCodeRepository $deviceCodes,
        protected ClientRepository $clients,
        protected Repository $config,
    ) {
    }

    /**
     * Authorize a device to access the user's account.
     */
    public function __invoke(
        Request $request,
        DeviceAuthorizationViewResponse $viewResponse
    ): RedirectResponse|DeviceAuthorizationViewResponse {
        if (! is_string($userCode = $request->query('user_code')) || $userCode === '') {
            return to_route('passport.device');
        }

        $deviceCode = $this->deviceCodes->getDeviceCodeEntityByUserCode(
            str_replace('-', '', $userCode)
        );

        $client = $deviceCode ? $this->clients->findActive($deviceCode->getClient()->getIdentifier()) : null;

        if (! $deviceCode || ! $client || ! $this->clientMatchesProvider($client)) {
            return to_route('passport.device')
                ->withInput(['user_code' => $userCode])
                ->withErrors([
                    'user_code' => 'Incorrect code.',
                ]);
        }

        $user = $this->getApprovingGuard()->user();
        $deviceCode->setUserIdentifier((string) $user->getAuthIdentifier());

        $scopes = $this->parseScopes($deviceCode);
        $attributes = $this->withErrorHandling(fn () => $this->authorizationAttributes($request, $user));

        $request->session()->put('authToken', $authToken = Str::random());
        $request->session()->put('deviceCode', serialize($deviceCode));
        $request->session()->put('authAttributes', $attributes);

        return $viewResponse->withParameters([
            'client' => $client,
            'user' => $user,
            'scopes' => $scopes,
            'request' => $request,
            'authToken' => $authToken,
        ]);
    }

    /**
     * Transform the device code entity's scopes into Scope instances.
     *
     * @return Scope[]
     */
    protected function parseScopes(DeviceCodeEntityInterface $deviceCode): array
    {
        return Passport::scopesFor(
            collect($deviceCode->getScopes())->map(
                fn (ScopeEntityInterface $scope): string => $scope->getIdentifier()
            )->unique()->all()
        );
    }
}
