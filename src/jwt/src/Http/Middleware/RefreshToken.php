<?php

declare(strict_types=1);

namespace Hypervel\JWT\Http\Middleware;

use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Auth\Factory as Auth;
use Hypervel\Http\Request;
use Hypervel\JWT\Exceptions\TokenBlacklistedException;
use Hypervel\JWT\Exceptions\TokenExpiredException;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\JwtGuard;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RefreshToken
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Auth $auth
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        try {
            $token = $this->guard($guard)->refresh();
        } catch (TokenInvalidException|TokenExpiredException|TokenBlacklistedException) {
            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        if ($token === null) {
            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        $response = $next($request);
        $response->headers->set('Authorization', 'Bearer ' . $token);

        return $response;
    }

    /**
     * Resolve the JWT guard.
     */
    protected function guard(?string $guard = null): JwtGuard
    {
        $resolved = $this->auth->guard($guard);

        if (! $resolved instanceof JwtGuard) {
            throw new RuntimeException('JWT middleware requires a JWT guard.');
        }

        return $resolved;
    }
}
