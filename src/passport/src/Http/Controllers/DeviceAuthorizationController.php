<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\DeviceCodeRepository;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PendingAuthorizationRepository;
use Hypervel\Passport\Scope;
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
        protected AuthManager $auth,
        protected DeviceCodeRepository $deviceCodes,
        protected ClientRepository $clients,
        protected Repository $config,
        protected PendingAuthorizationRepository $pending,
        protected Dispatcher $events,
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
            strtoupper(str_replace('-', '', $userCode))
        );

        $client = $deviceCode ? $this->clients->findActive($deviceCode->getClient()->getIdentifier()) : null;

        if (! $deviceCode || ! $client || ! $client->supportsProvider($this->getApprovingProvider())) {
            return to_route('passport.device')
                ->withInput(['user_code' => $userCode])
                ->withErrors([
                    'user_code' => 'Incorrect code.',
                ]);
        }

        $this->withErrorHandling(fn () => $this->getClientForApproval(
            $client->getKey(),
            'urn:ietf:params:oauth:grant-type:device_code',
            $deviceCode->getScopes(),
        ));

        $user = $this->getApprovingGuard()->user();
        $deviceCode->setUserIdentifier((string) $user->getAuthIdentifier());

        $scopes = $this->parseScopes($deviceCode);
        $parameters = $this->withErrorHandling(fn (): array => RequestParameters::fromRequest($request));
        $state = $this->withErrorHandling(fn () => $this->authorizationState($request, $parameters, $user, $client, $scopes, $deviceCode, $this->events));
        $authToken = $this->pending->store($request, [
            ...$state,
            'parameters' => $parameters,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'stage' => 'consent',
            'device_code' => $deviceCode->getIdentifier(),
            'scopes' => array_map(static fn (Scope $scope): string => $scope->id, $scopes),
        ]);

        return $viewResponse->withParameters([
            'client' => $client,
            'user' => $user,
            'scopes' => $scopes,
            'resources' => Passport::resourcesFor(array_keys($state['permissions']['resources'] ?? [])),
            'permissions' => $state['permissions'],
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
