<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

use Closure;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthenticateWithBasicAuth
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected AuthFactory $auth,
    ) {
    }

    /**
     * Specify the guard and field for the middleware.
     */
    public static function using(?string $guard = null, ?string $field = null): string
    {
        return static::class . ':' . implode(',', func_get_args());
    }

    /**
     * Handle an incoming request.
     *
     * @throws UnauthorizedHttpException
     */
    public function handle(Request $request, Closure $next, ?string $guard = null, ?string $field = null): Response
    {
        $this->auth->guard($guard)->basic($field ?: 'email'); /* @phpstan-ignore method.notFound (basic() is on SupportsBasicAuth, not the Guard contract) */

        return $next($request);
    }
}
