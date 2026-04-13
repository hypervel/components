<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Fruitcake\Cors\CorsService;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The CORS service instance.
     */
    protected CorsService $cors;

    /**
     * All of the registered skip callbacks.
     *
     * @var array<int, Closure(\Hypervel\Http\Request): bool>
     */
    protected static array $skipCallbacks = [];

    /**
     * Create a new middleware instance.
     */
    public function __construct(Container $container, CorsService $cors)
    {
        $this->container = $container;
        $this->cors = $cors;
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (static::$skipCallbacks as $callback) {
            if ($callback($request)) {
                return $next($request);
            }
        }

        if (! $this->hasMatchingPath($request)) {
            return $next($request);
        }

        $this->cors->setOptions($this->container['config']->get('cors', []));

        if ($this->cors->isPreflightRequest($request)) {
            $response = $this->cors->handlePreflightRequest($request);

            $this->cors->varyHeader($response, 'Access-Control-Request-Method');

            return $response;
        }

        $response = $next($request);

        if ($request->getMethod() === 'OPTIONS') {
            $this->cors->varyHeader($response, 'Access-Control-Request-Method');
        }

        return $this->cors->addActualRequestHeaders($response, $request);
    }

    /**
     * Get the path from the configuration to determine if the CORS service should run.
     */
    protected function hasMatchingPath(Request $request): bool
    {
        $paths = $this->getPathsByHost($request->getHost());

        foreach ($paths as $path) {
            if ($path !== '/') {
                $path = trim($path, '/');
            }

            if ($request->fullUrlIs($path) || $request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the CORS paths for the given host.
     */
    protected function getPathsByHost(string $host): array
    {
        $paths = $this->container['config']->get('cors.paths', []);

        if (isset($paths[$host])) {
            return $paths[$host];
        }

        return array_filter($paths, function ($path) {
            return is_string($path);
        });
    }

    /**
     * Register a callback that instructs the middleware to be skipped.
     */
    public static function skipWhen(Closure $callback): void
    {
        static::$skipCallbacks[] = $callback;
    }

    /**
     * Flush the middleware's global state.
     */
    public static function flushState(): void
    {
        static::$skipCallbacks = [];
    }
}
