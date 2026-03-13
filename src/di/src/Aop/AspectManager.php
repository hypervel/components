<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

/**
 * Runtime cache of resolved aspects per class::method.
 *
 * Once the aspect pipeline for a given class method is resolved,
 * it is cached here so subsequent calls skip the resolution logic.
 */
class AspectManager
{
    /**
     * @var array<string, array<string, array<int, string>>>
     */
    protected static array $container = [];

    /**
     * Get the resolved aspects for a class method.
     */
    public static function get(string $class, string $method): array
    {
        return static::$container[$class][$method] ?? [];
    }

    /**
     * Determine if aspects have been resolved for a class method.
     */
    public static function has(string $class, string $method): bool
    {
        return isset(static::$container[$class][$method]);
    }

    /**
     * Set the resolved aspects for a class method.
     */
    public static function set(string $class, string $method, array $value): void
    {
        static::$container[$class][$method] = $value;
    }

    /**
     * Append an aspect to the resolved list for a class method.
     */
    public static function insert(string $class, string $method, string $value): void
    {
        static::$container[$class][$method][] = $value;
    }

    /**
     * Flush all cached aspect resolutions.
     */
    public static function flushState(): void
    {
        static::$container = [];
    }
}
