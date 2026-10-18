<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Closure;
use Hypervel\Passport\Exceptions\OAuthServerException;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Render authorization errors after rollback using the validated interaction context.
     *
     * @template TResult
     * @param Closure(): TResult $callback
     * @param array<string, mixed> $pending
     * @return Response|TResult
     */
    protected function withAuthorizationErrorHandling(Closure $callback, array $pending, AuthorizationResponse $responses): mixed
    {
        try {
            return $callback();
        } catch (LeagueException|OAuthServerException $exception) {
            // The Passport wrapper eagerly builds a query redirect; use its cause for either response mode.
            /** @var LeagueException $error */
            $error = $exception instanceof OAuthServerException ? $exception->getPrevious() : $exception;

            return $responses->error(
                $error,
                $error->getErrorType() === 'invalid_client' ? null : $pending['redirect_uri'],
                $pending['parameters']['state'] ?? null,
                $pending['response_mode'],
            );
        }
    }
}
