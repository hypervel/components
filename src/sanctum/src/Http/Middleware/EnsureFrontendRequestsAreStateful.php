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
     * The closure used to determine whether the current request is stateful.
     *
     * @var null|Closure(Request): bool
     */
    protected static ?Closure $statefulRequestResolver = null;

    /**
     * The closure used to resolve stateful domains for the current request.
     *
     * @var null|Closure(Request): array<int, string>
     */
    protected static ?Closure $statefulDomainsResolver = null;

    /**
     * Handle the incoming requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = (new Pipeline(app()))->send($request)->through(
            static::fromFrontend($request) ? $this->frontendMiddleware() : []
        )->then(function ($request) use ($next) {
            return $next($request);
        });

        return $response;
    }

    /**
     * Get the middleware that should be applied to requests from the "frontend".
     *
     * @return array<int, mixed>
     */
    protected function frontendMiddleware(): array
    {
        $middleware = [
            config('sanctum.middleware.encrypt_cookies', \Hypervel\Cookie\Middleware\EncryptCookies::class),
            \Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Hypervel\Session\Middleware\StartSession::class,
            config('sanctum.middleware.validate_csrf_token', \Hypervel\Foundation\Http\Middleware\PreventRequestForgery::class),
            config('sanctum.middleware.authenticate_session'),
        ];

        $filtered = [];

        foreach ($middleware as $candidate) {
            if ($candidate !== null
                && $candidate !== ''
                && ! in_array($candidate, $filtered, true)) {
                $filtered[] = $candidate;
            }
        }

        array_unshift($filtered, function (Request $request, Closure $next) {
            $request->attributes->set('sanctum', true);

            return $next($request);
        });

        return $filtered;
    }

    /**
     * Determine if the given request is from the first-party application frontend.
     */
    public static function fromFrontend(Request $request): bool
    {
        if (static::$statefulRequestResolver !== null) {
            return (static::$statefulRequestResolver)($request);
        }

        $domain = $request->headers->get('referer') ?: $request->headers->get('origin');

        if (is_null($domain)) {
            return false;
        }

        $domain = Str::replaceFirst('https://', '', $domain);
        $domain = Str::replaceFirst('http://', '', $domain);
        $domain = Str::endsWith($domain, '/') ? $domain : "{$domain}/";

        $stateful = self::resolveStatefulDomains($request);

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
    private static function resolveStatefulDomains(Request $request): array
    {
        if (static::$statefulDomainsResolver !== null) {
            return self::filterDomainList((static::$statefulDomainsResolver)($request));
        }

        return self::filterDomainList(config('sanctum.stateful_domains', []));
    }

    /**
     * Filter a stateful domain list to non-empty strings.
     *
     * @return array<int, string>
     */
    private static function filterDomainList(mixed $domains): array
    {
        return is_array($domains)
            ? array_values(array_filter(
                $domains,
                static fn (mixed $domain): bool => is_string($domain) && $domain !== ''
            ))
            : [];
    }

    /**
     * Register a closure that determines whether the current request is stateful.
     *
     * Boot-only. The closure receives the current request and returns the final
     * stateful decision. Persists for the worker lifetime.
     *
     * @param null|Closure(Request): bool $callback
     */
    public static function resolveStatefulRequestsUsing(?Closure $callback): void
    {
        static::$statefulRequestResolver = $callback;
    }

    /**
     * Register a closure that resolves stateful domains for the current request.
     *
     * Boot-only. The closure receives the current request and returns the
     * stateful domain list; useful when the list varies by host or other
     * request data. Persists for the worker lifetime.
     *
     * @param null|Closure(Request): array<int, string> $callback
     */
    public static function resolveStatefulDomainsUsing(?Closure $callback): void
    {
        static::$statefulDomainsResolver = $callback;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$statefulRequestResolver = null;
        static::$statefulDomainsResolver = null;
    }
}
