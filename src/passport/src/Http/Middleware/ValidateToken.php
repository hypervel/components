<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\Contracts\ScopeAuthorizable;
use Hypervel\Passport\Exceptions\AuthenticationException;
use Hypervel\Passport\ServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\Response;

abstract class ValidateToken
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ServerFactory $servers,
    ) {
    }

    /**
     * Specify the parameters for the middleware.
     *
     * @param string|string[] $param
     */
    public static function using(array|string $param, string ...$params): string
    {
        if (is_array($param)) {
            return static::class . ':' . implode(',', $param);
        }

        return static::class . ':' . implode(',', [$param, ...$params]);
    }

    /**
     * Handle an incoming request.
     *
     * @param (Closure(Request): Response) $next
     */
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $token = $this->validateToken($request);

        $this->validate($token, ...$params);

        return $next($request);
    }

    /**
     * Validate and get the request's access token.
     *
     * @throws AuthenticationException
     */
    protected function validateToken(Request $request): ScopeAuthorizable
    {
        // If the user is authenticated and already has an access token set via
        // the token guard, there's no need to validate the request's bearer
        // token again, so we'll return the access token as the valid one.
        $user = $request->user();

        if ($user instanceof OAuthenticatable && $token = $user->currentAccessToken()) {
            return $token;
        }

        // Otherwise, we will convert the request to a PSR-7 implementation and
        // pass it to the OAuth2 server to be validated. If the bearer token
        // passed the validation, we will return an access token instance.
        try {
            $psrRequest = $this->servers->validateAuthenticatedRequest($request);
        } catch (OAuthServerException) {
            throw new AuthenticationException;
        }

        return AccessToken::fromPsrRequest($psrRequest);
    }

    /**
     * Validate the given access token.
     */
    abstract protected function validate(ScopeAuthorizable $token, string ...$params): void;
}
