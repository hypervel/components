<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Events\AuthorizationUserResolved;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Scope;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

trait ResolvesApprovingGuard
{
    /**
     * Resolve the guard authorizing the current request.
     */
    protected function getApprovingGuard(): StatefulGuard
    {
        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard($this->config->get('passport.guard'));

        return $guard;
    }

    /**
     * Get the name of the guard authorizing the current request.
     */
    protected function getApprovingGuardName(): string
    {
        return $this->config->get('passport.guard') ?? $this->auth->getDefaultDriver();
    }

    /**
     * Get the approving guard's user provider.
     */
    protected function getApprovingProvider(): ?string
    {
        return $this->auth->getUserProviderName($this->config->get('passport.guard'));
    }

    /**
     * Capture the approving identity and synchronous extension decisions.
     *
     * @param array<string, mixed> $parameters
     * @param list<Scope> $scopes
     * @return array<string, mixed>
     */
    protected function authorizationState(
        Request $request,
        array $parameters,
        Authenticatable $user,
        Client $client,
        array $scopes,
        AuthorizationRequestInterface|DeviceCodeEntityInterface $authorization,
        Dispatcher $events,
    ): array {
        $provider = $this->getApprovingProvider();
        $guard = $this->getApprovingGuardName();
        $event = null;

        if ($events->hasListeners(AuthorizationUserResolved::class)) {
            $event = new AuthorizationUserResolved($request, $parameters, $user, $provider, $guard, $client, $scopes, $authorization);
            $events->dispatch($event);
        }

        $duration = $event !== null ? $event->rememberConsentFor : Passport::rememberConsentFor();
        $permissions = $event->permissions ?? [];
        // Resource permissions belong to the validated protocol request, not extension contributions.
        unset($permissions['resources']);

        return [
            'user_id' => (string) $user->getAuthIdentifier(),
            'provider' => $provider,
            'guard' => $guard,
            'attributes' => [...($event->attributes ?? []), 'provider' => $provider],
            'permissions' => $permissions,
            // Public interval state survives JSON sessions without Carbon's internal objects or callbacks.
            'remember_consent_for' => $duration === null ? null : get_object_vars($duration),
        ];
    }

    /**
     * Ensure the consent belongs to the current user and provider.
     *
     * @param array<string, mixed> $pending
     */
    protected function ensureApprovingUser(array $pending): void
    {
        $user = $this->getApprovingGuard()->user();

        if ($user !== null && $pending['user_id'] === (string) $user->getAuthIdentifier()
            && $pending['provider'] === $this->getApprovingProvider()
            && $pending['guard'] === $this->getApprovingGuardName()) {
            return;
        }

        throw InvalidAuthTokenException::different();
    }

    /**
     * Get the pending interaction selected by a browser decision.
     *
     * @return array<string, mixed>
     */
    protected function getPendingAuthorization(Request $request): array
    {
        $token = $request->input('auth_token');

        if (! is_string($token) || $token === '') {
            throw InvalidAuthTokenException::different();
        }

        return $this->pending->get($request, $token);
    }

    /**
     * Recheck the client and scopes saved with the consent request.
     *
     * @param list<ScopeEntityInterface> $scopes
     */
    protected function getClientForApproval(string $identifier, string $grantType, array $scopes): Client
    {
        $client = $this->clients->findActive($identifier);

        if (! $client || ! $client->supportsProvider($this->getApprovingProvider()) || ! $client->hasGrantType($grantType)) {
            throw OAuthServerException::unauthorizedClient();
        }

        foreach ($scopes as $scope) {
            $identifier = $scope->getIdentifier();

            if (! Passport::hasScope($identifier) || ! $client->hasScope($identifier)) {
                throw OAuthServerException::invalidScope($identifier);
            }
        }

        return $client;
    }
}
