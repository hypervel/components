<?php

declare(strict_types=1);

namespace Hypervel\Passport\Contracts;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Passport\Client;
use Hypervel\Passport\PersonalAccessTokenResult;
use Hypervel\Passport\Token;

interface OAuthenticatable extends Authenticatable
{
    /**
     * Get all the user's registered OAuth applications.
     *
     * @return MorphMany<Client, Model>
     */
    public function oauthApps(): MorphMany;

    /**
     * Get all the access tokens for the user.
     *
     * @return HasMany<Token, Model>
     */
    public function tokens(): HasMany;

    /**
     * Determine if the current API token has a given scope.
     */
    public function tokenCan(string $scope): bool;

    /**
     * Determine if the current API token is missing a given scope.
     */
    public function tokenCant(string $scope): bool;

    /**
     * Create a new personal access token for the user.
     *
     * @param string[] $scopes
     */
    public function createToken(string $name, array $scopes = []): PersonalAccessTokenResult;

    /**
     * Get the access token currently associated with the user.
     */
    public function currentAccessToken(): ?ScopeAuthorizable;

    /**
     * Set the current access token for the user.
     */
    public function withAccessToken(?ScopeAuthorizable $accessToken): static;

    /**
     * Get the user provider name.
     */
    public function getProviderName(): string;
}
