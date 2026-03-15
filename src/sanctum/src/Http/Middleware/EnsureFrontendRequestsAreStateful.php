<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Routing\Pipeline;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureFrontendRequestsAreStateful
{
    /**
     * Handle the incoming requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->configureSecureCookieSessions();

        return (new Pipeline(app()))->send($request)->through(
            static::fromFrontend($request) ? $this->frontendMiddleware() : []
        )->then(function ($request) use ($next) {
            return $next($request);
        });
    }

    /**
     * Get the middleware that should be applied to requests from the "frontend".
     *
     * @return array<int, mixed>
     */
    protected function frontendMiddleware(): array
    {
        $middleware = array_values(array_filter(array_unique([
            config('sanctum.middleware.encrypt_cookies', \Hypervel\Cookie\Middleware\EncryptCookies::class),
            \Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Hypervel\Session\Middleware\StartSession::class,
            config('sanctum.middleware.validate_csrf_token', \Hypervel\Foundation\Http\Middleware\PreventRequestForgery::class),
            config('sanctum.middleware.authenticate_session'),
        ])));

        array_unshift($middleware, function (Request $request, Closure $next) {
            $request->attributes->set('sanctum', true);

            return $next($request);
        });

        return $middleware;
    }

    /**
     * Determine if the given request is from the first-party application frontend.
     */
    public static function fromFrontend(Request $request): bool
    {
        $domain = $request->headers->get('referer') ?: $request->headers->get('origin');

        if (is_null($domain)) {
            return false;
        }

        $domain = Str::replaceFirst('https://', '', $domain);
        $domain = Str::replaceFirst('http://', '', $domain);
        $domain = Str::endsWith($domain, '/') ? $domain : "{$domain}/";

        $stateful = array_filter(static::statefulDomains());

        return Str::is(Collection::make($stateful)->map(function ($uri) use ($request) {
            $uri = $uri === Sanctum::$currentRequestHostPlaceholder ? $request->getHttpHost() : $uri;

            return trim($uri) . '/*';
        })->all(), $domain);
    }

    /**
     * Get the domains that should be treated as stateful.
     *
     * @return array<int, string>
     */
    public static function statefulDomains(): array
    {
        return config('sanctum.stateful', []);
    }

    /**
     * Configure secure cookie sessions.
     */
    protected function configureSecureCookieSessions(): void
    {
        config([
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
    }
}
