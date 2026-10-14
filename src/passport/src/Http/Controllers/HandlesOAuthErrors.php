<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Closure;
use Hypervel\Passport\Exceptions\OAuthServerException;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;

trait HandlesOAuthErrors
{
    /**
     * Perform the given callback with exception handling.
     *
     * @template TResult
     *
     * @param (Closure(): TResult) $callback
     * @return TResult
     *
     * @throws OAuthServerException
     */
    protected function withErrorHandling(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (LeagueException $e) {
            throw new OAuthServerException($e);
        }
    }
}
