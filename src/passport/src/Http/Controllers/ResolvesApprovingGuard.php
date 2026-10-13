<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use Hypervel\Passport\Passport;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

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
     * Get the approving guard's user provider.
     */
    protected function getApprovingProvider(): ?string
    {
        /** @var AuthManager $auth */
        $auth = $this->auth;

        return $auth->getUserProviderName($this->config->get('passport.guard'));
    }

    /**
     * Determine whether the client accepts the approving provider.
     */
    protected function clientMatchesProvider(Client $client): bool
    {
        return $client->provider === null || $client->provider === $this->getApprovingProvider();
    }

    /**
     * Capture attributes belonging to the authorization being approved.
     *
     * @return array<string, mixed>
     */
    protected function authorizationAttributes(Request $request, Authenticatable $user): array
    {
        return [
            ...(Passport::$authorizationAttributesCallback ? (Passport::$authorizationAttributesCallback)($request, $user) : []),
            'provider' => $this->getApprovingProvider(),
        ];
    }

    /**
     * Ensure the consent belongs to the current user and provider.
     */
    protected function ensureApprovingUser(Request $request, ?string $identifier): void
    {
        $user = $this->getApprovingGuard()->user();
        $attributes = $request->session()->get('authAttributes', []);

        if ($user !== null && $identifier === (string) $user->getAuthIdentifier()
            && ($attributes['provider'] ?? null) === $this->getApprovingProvider()) {
            return;
        }

        $request->session()->forget(['authToken', 'authRequest', 'deviceCode', 'authAttributes']);

        throw InvalidAuthTokenException::different();
    }

    /**
     * Recheck the client and scopes saved with the consent request.
     *
     * @param list<ScopeEntityInterface> $scopes
     */
    protected function getClientForApproval(string $identifier, string $grantType, array $scopes): Client
    {
        $client = $this->clients->findActive($identifier);

        if (! $client || ! $this->clientMatchesProvider($client) || ! $client->hasGrantType($grantType)) {
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
