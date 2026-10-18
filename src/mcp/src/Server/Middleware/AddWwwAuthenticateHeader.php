<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

class AddWwwAuthenticateHeader
{
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

        $metadataUrl = $this->resourceMetadataUrl($request);
        if ($metadataUrl !== null) {
            $response->headers->set(
                'WWW-Authenticate',
                'Bearer realm="mcp", resource_metadata="' . addcslashes($metadataUrl, '\"') . '"'
            );

            return $response;
        }

        if ($this->mcpMiddleware($request) === null) {
            return $response;
        }

        // Sanctum does not use OAuth discovery.
        $response->headers->set(
            'WWW-Authenticate',
            'Bearer realm="mcp", error="invalid_token"'
        );

        return $response;
    }

    /**
     * Get the discovery URL for a route protected by MCP OAuth middleware.
     */
    public function resourceMetadataUrl(Request $request): ?string
    {
        $middleware = $this->mcpMiddleware($request);

        if ($middleware === null || ! app('router')->has('mcp.oauth.protected-resource.nested')
            || $this->usesOnlySanctum($middleware)) {
            return null;
        }

        $metadataRoute = app('router')->getRoutes()->getByName('mcp.oauth.protected-resource.nested');
        $parameters = array_intersect_key($request->route()->parameters(), array_flip($metadataRoute->parameterNames()));

        return route('mcp.oauth.protected-resource.nested', [...$parameters, 'path' => $request->path()]);
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

            return $config->get("auth.guards.{$guard}.driver") === 'sanctum';
        });
    }
}
