<?php

declare(strict_types=1);

namespace Hypervel\Router;

/**
 * Manages middleware exclusions for routes.
 *
 * This class stores middleware that should be excluded from routes after
 * middleware groups have been expanded. This allows `without_middleware`
 * to work correctly with middleware groups like 'web' or 'api'.
 */
class MiddlewareExclusionManager
{
    /**
     * The container for middleware exclusions.
     *
     * @var array<string, array<string, array<string, string[]>>>
     */
    public static array $container = [];

    /**
     * Add excluded middleware for a route.
     *
     * @param string $server The server name (e.g., 'http')
     * @param string $path The route path
     * @param string $method The HTTP method
     * @param string[] $middleware The middleware classes to exclude
     */
    public static function addExcluded(string $server, string $path, string $method, array $middleware): void
    {
        if (empty($middleware)) {
            return;
        }

        $method = strtoupper($method);

        static::$container[$server][$path][$method] = array_merge(
            static::$container[$server][$path][$method] ?? [],
            $middleware
        );
    }

    /**
     * Get excluded middleware for a route.
     *
     * @param string $server The server name
     * @param string $path The route path
     * @param string $method The HTTP method
     * @return string[] The middleware classes to exclude
     */
    public static function get(string $server, string $path, string $method): array
    {
        $method = strtoupper($method);

        if (isset(static::$container[$server][$path][$method])) {
            return static::$container[$server][$path][$method];
        }

        // For HEAD requests, attempt fallback to GET
        // Keep consistent with MiddlewareManager behavior
        if ($method === 'HEAD') {
            return static::$container[$server][$path]['GET'] ?? [];
        }

        return [];
    }

    /**
     * Clear all stored exclusions.
     *
     * Useful for testing.
     */
    public static function clear(): void
    {
        static::$container = [];
    }
}
