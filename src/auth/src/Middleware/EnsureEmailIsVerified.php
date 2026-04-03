<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

use Closure;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Redirect;
use Hypervel\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    /**
     * Specify the redirect route for the middleware.
     */
    public static function redirectTo(string $route): string
    {
        return static::class . ':' . $route;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        if (! $request->user()
            || ($request->user() instanceof MustVerifyEmail
                && ! $request->user()->hasVerifiedEmail())) {
            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : Redirect::guest(URL::route($redirectToRoute ?: 'verification.notice'));
        }

        return $next($request);
    }
}
