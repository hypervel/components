<?php

declare(strict_types=1);

namespace Hypervel\Auth\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * The callback that should be used to generate the authentication redirect path.
     *
     * @var null|callable
     */
    protected static $redirectToCallback;

    /**
     * Specify the guards for the middleware.
     */
    public static function using(string $guard, string ...$others): string
    {
        return static::class . ':' . implode(',', [$guard, ...$others]);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect($this->redirectTo($request));
            }
        }

        return $next($request);
    }

    /**
     * Get the path the user should be redirected to when they are authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return static::$redirectToCallback
            ? call_user_func(static::$redirectToCallback, $request)
            : $this->defaultRedirectUri();
    }

    /**
     * Get the default URI the user should be redirected to when they are authenticated.
     */
    protected function defaultRedirectUri(): string
    {
        foreach (['dashboard', 'home'] as $uri) {
            if (Route::has($uri)) {
                return route($uri);
            }
        }

        $routes = Route::getRoutes()->get('GET');

        foreach (['dashboard', 'home'] as $uri) {
            if (isset($routes[$uri])) { /* @phpstan-ignore isset.offset (route collection may be string-keyed by URI) */
                return '/' . $uri;
            }
        }

        return '/';
    }

    /**
     * Specify the callback that should be used to generate the redirect path.
     */
    public static function redirectUsing(callable $redirectToCallback): void
    {
        static::$redirectToCallback = $redirectToCallback;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$redirectToCallback = null;
    }
}
