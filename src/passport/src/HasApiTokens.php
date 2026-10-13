<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\Contracts\ScopeAuthorizable;
use LogicException;

/**
 * @phpstan-require-implements OAuthenticatable
 */
trait HasApiTokens
{
    /**
     * The current access token for the authentication user.
     */
    protected ?ScopeAuthorizable $accessToken = null;

    // The deprecated clients() relation is omitted; use oauthApps().

    /**
     * Get all of the user's registered OAuth applications.
     *
     * @return ClientRelation<$this>
     */
    public function oauthApps(): ClientRelation
    {
        // Use the client's configured connection, as client authentication does.
        $client = Passport::client();
        [$type, $id] = $this->getMorphs('owner', null, null);

        return new ClientRelation(
            $client->newQuery(),
            $this,
            $client->qualifyColumn($type),
            $client->qualifyColumn($id),
            $this->getKeyName()
        );
    }

    /**
     * Get all of the access tokens for the user.
     *
     * @return TokenRelation<$this>
     */
    public function tokens(): TokenRelation
    {
        // Use the token's configured connection, as bearer authentication does.
        $token = Passport::token();

        return (new TokenRelation(
            $token->newQuery(),
            $this,
            $token->qualifyColumn('user_id'),
            $this->getAuthIdentifierName()
        ))->where($token->qualifyColumn('provider'), $this->getProviderName());
    }

    /**
     * Get the access token currently associated with the user.
     */
    public function token(): ?ScopeAuthorizable
    {
        return $this->currentAccessToken();
    }

    /**
     * Get the access token currently associated with the user.
     */
    public function currentAccessToken(): ?ScopeAuthorizable
    {
        return $this->accessToken;
    }

    /**
     * Determine if the current API token has a given scope.
     */
    public function tokenCan(string $scope): bool
    {
        return $this->accessToken && $this->accessToken->can($scope);
    }

    /**
     * Determine if the current API token is missing a given scope.
     */
    public function tokenCant(string $scope): bool
    {
        return ! $this->tokenCan($scope);
    }

    /**
     * Create a new personal access token for the user.
     *
     * @param string[] $scopes
     */
    public function createToken(string $name, array $scopes = []): PersonalAccessTokenResult
    {
        return app(PersonalAccessTokenFactory::class)->make(
            $this->getAuthIdentifier(),
            $name,
            $scopes,
            $this->getProviderName()
        );
    }

    /**
     * Get the user provider name.
     *
     * @throws LogicException
     */
    public function getProviderName(): string
    {
        $providers = collect(config()->array('auth.guards'))->where('driver', 'passport')->pluck('provider')->all();

        foreach (config()->array('auth.providers') as $provider => $config) {
            if (in_array($provider, $providers, true) && $config['driver'] === 'eloquent' && is_a($this, $config['model'])) {
                return $provider;
            }
        }

        throw new LogicException('Unable to determine authentication provider for this model from configuration.');
    }

    /**
     * Set the current access token for the user.
     */
    public function withAccessToken(?ScopeAuthorizable $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }
}
