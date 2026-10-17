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

        $route = $request->route();
        if (! $route instanceof Route) {
            return $response;
        }

        $middleware = app('router')->gatherRouteMiddleware($route);

        if (! in_array(self::class, $middleware, true)) {
            return $response;
        }

        $isOauth = app('router')->has('mcp.oauth.protected-resource.nested') && ! $this->usesOnlySanctum($middleware);
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
