<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Middleware;

use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Contracts\ScopeAuthorizable;
use Hypervel\Passport\Exceptions\AuthenticationException;
use Hypervel\Passport\Exceptions\MissingScopeException;

class EnsureClientIsResourceOwner extends ValidateToken
{
    /**
     * Determine if the token's client is the resource owner and has all the given scopes.
     *
     * @throws AuthenticationException|MissingScopeException
     */
    protected function validate(ScopeAuthorizable $token, string ...$params): void
    {
        if (
            ! $token instanceof AccessToken
            || $token->oauth_user_id === null
            || $token->oauth_user_id !== $token->oauth_client_id
            || $token->user_id !== null
        ) {
            throw new AuthenticationException;
        }

        foreach ($params as $scope) {
            if ($token->cant($scope)) {
                throw new MissingScopeException($scope);
            }
        }
    }
}
