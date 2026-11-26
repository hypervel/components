<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Routing\RouteParameterBinder;
use Hypervel\Support\Attributes\BootOnly;
use Symfony\Component\HttpFoundation\Response;

class AddWwwAuthenticateHeader
{
    /** @var null|(Closure(Request): ?string) */
    protected static ?Closure $resourceMetadataUrlResolver = null;

    /**
     * Specify the callback used to resolve the OAuth resource metadata URL.
     *
     * The callback persists in a static property and affects every MCP authentication challenge on the worker.
     *
     * @param null|(Closure(Request): ?string) $callback
     */
    #[BootOnly]
    public static function resolveResourceMetadataUrlUsing(?Closure $callback): void
    {
        static::$resourceMetadataUrlResolver = $callback;
    }

    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() !== 401 || $response->headers->has('WWW-Authenticate')) {
            return $response;
        }

        $middleware = $this->mcpMiddleware($request);
        if ($middleware === null) {
            return $response;
        }

        $metadataUrl = $this->resolveMetadataUrl($request, $middleware);

        $response->headers->set(
            'WWW-Authenticate',
            $metadataUrl === null
                // Sanctum-only routes and apps without OAuth metadata routes have no discovery URL.
                ? 'Bearer realm="mcp", error="invalid_token"'
                : 'Bearer realm="mcp", resource_metadata="' . addcslashes($metadataUrl, '\"') . '"'
        );

        return $response;
    }

    /**
     * Get the discovery URL for a route protected by MCP OAuth middleware.
     *
     * Kept public because hypervel/passport calls this to build OAuth challenges.
     */
    public function resourceMetadataUrl(Request $request): ?string
    {
        $middleware = $this->mcpMiddleware($request);

        if ($middleware === null) {
            return null;
        }

        return $this->resolveMetadataUrl($request, $middleware);
    }

    /**
     * Resolve the discovery URL for an MCP route's effective middleware.
     *
     * @param array<int, Closure|string> $middleware
     */
    protected function resolveMetadataUrl(Request $request, array $middleware): ?string
    {
        if ($this->usesOnlySanctum($middleware)) {
            return null;
        }

        if (static::$resourceMetadataUrlResolver instanceof Closure) {
            $url = (static::$resourceMetadataUrlResolver)($request);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $metadataRoute = app('router')->getRoutes()->getByName('mcp.oauth.protected-resource.nested');

        if (! $metadataRoute instanceof Route) {
            return null;
        }

        $metadataRoute->ensureCompiled();

        $route = $request->route();
        $matchedParameters = $route instanceof Route ? $route->parameters() : [];

        // Bind without cloning the shared route or writing unused CoroutineContext slots, keeping path parameters the matched MCP route shares with the metadata route (such as a {tenant} prefix).
        $parameters = array_replace(
            array_intersect_key(
                $matchedParameters,
                array_flip($metadataRoute->parameterNames()),
            ),
            (new RouteParameterBinder($metadataRoute))->parameters($request),
        );

        return route('mcp.oauth.protected-resource.nested', [
            ...$parameters,
            'path' => $request->path(),
        ]);
    }

    /**
     * Get the route's effective middleware when MCP challenges are enabled.
     *
     * @return null|array<int, Closure|string>
     */
    protected function mcpMiddleware(Request $request): ?array
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return null;
        }

        $middleware = app('router')->gatherRouteMiddleware($route);

        return in_array(self::class, $middleware, true) ? $middleware : null;
    }

    /**
     * Determine whether this route authenticates exclusively through Sanctum.
     *
     * @param array<int, Closure|string> $middleware
     */
    private function usesOnlySanctum(array $middleware): bool
    {
        $guards = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            [$class, $parameters] = array_pad(explode(':', $entry, 2), 2, null);

            if (is_a($class, Authenticate::class, true)) {
                array_push($guards, ...($parameters === null ? [null] : explode(',', $parameters)));
            }
        }

        $config = config();

        return $guards !== [] && array_all($guards, function (?string $guard) use ($config): bool {
            $guard = $guard ?: $config->string('auth.defaults.guard');

            return $config->nullableString("auth.guards.{$guard}.driver", null) === 'sanctum';
        });
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$resourceMetadataUrlResolver = null;
    }
}
