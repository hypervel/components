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
     * The closure used to resolve CORS configuration for the current request.
     *
     * @var null|Closure(Request): array
     */
    protected static ?Closure $configResolver = null;

    /**
     * All of the registered skip callbacks.
     *
     * @var array<int, Closure(Request): bool>
     */
    protected static array $skipCallbacks = [];

    /**
     * Create a new middleware instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
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

        $config = static::$configResolver !== null
            ? (static::$configResolver)($request)
            : $this->container['config']->get('cors', []);

        $cors = new CorsService($config);

        if ($cors->isPreflightRequest($request)) {
            $response = $cors->handlePreflightRequest($request);

            $cors->varyHeader($response, 'Access-Control-Request-Method');

            return $response;
        }

        $response = $next($request);

        if ($request->getMethod() === 'OPTIONS') {
            $cors->varyHeader($response, 'Access-Control-Request-Method');
        }

        return $cors->addActualRequestHeaders($response, $request);
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
     * Register a closure that resolves CORS configuration for the current request.
     *
     * Boot-only. The closure receives the current request and returns the CORS
     * options array; useful for multi-tenant CORS where the config varies by
     * host or other request data. Persists for the worker lifetime.
     *
     * @param null|Closure(Request): array $callback
     */
    public static function resolveConfigUsing(?Closure $callback): void
    {
        static::$configResolver = $callback;
    }

    /**
     * Register a callback that instructs the middleware to be skipped.
     *
     * Boot-only. Skip callbacks persist for the worker lifetime and run on
     * every subsequent request.
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
        static::$configResolver = null;
        static::$skipCallbacks = [];
    }
}
