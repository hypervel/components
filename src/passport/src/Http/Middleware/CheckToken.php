<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Middleware;

use Hypervel\Passport\Contracts\ScopeAuthorizable;
use Hypervel\Passport\Exceptions\MissingScopeException;

class CheckToken extends ValidateToken
{
    /**
     * Determine if the token has all the given scopes.
     *
     * @throws MissingScopeException
     */
    protected function validate(ScopeAuthorizable $token, string ...$params): void
    {
        foreach ($params as $scope) {
            if ($token->cant($scope)) {
                throw new MissingScopeException($scope);
            }
        }
    }
}
