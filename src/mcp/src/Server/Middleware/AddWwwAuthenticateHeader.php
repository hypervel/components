<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
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
        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        $route = $request->route();
        if (! $route instanceof Route || ! in_array(self::class, app('router')->gatherRouteMiddleware($route), true)) {
            return $response;
        }

        $isOauth = app('router')->has('mcp.oauth.protected-resource.nested');
        if ($isOauth) {
            $response->headers->set(
                'WWW-Authenticate',
                'Bearer realm="mcp", resource_metadata="' . route('mcp.oauth.protected-resource.nested', ['path' => $request->path()]) . '"'
            );

            return $response;
        }

        // Sanctum, can't share discover URL
        $response->headers->set(
            'WWW-Authenticate',
            'Bearer realm="mcp", error="invalid_token"'
        );

        return $response;
    }
}
