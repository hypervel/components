<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Middleware;

use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Sanctum\Exceptions\MissingAbilityException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CheckAbilities
{
    public function __construct(
        protected AuthFactory $auth
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @throws AuthenticationException
     * @throws MissingAbilityException
     */
    public function handle(ServerRequestInterface $request, Closure $next, string ...$abilities): ResponseInterface
    {
        $user = $this->auth->guard()->user();

        if (! $user || ! method_exists($user, 'currentAccessToken') || ! $user->currentAccessToken()) {
            throw new AuthenticationException();
        }

        foreach ($abilities as $ability) {
            if (! method_exists($user, 'tokenCan') || ! $user->tokenCan($ability)) {
                throw new MissingAbilityException($ability);
            }
        }

        return $next($request);
    }
}
