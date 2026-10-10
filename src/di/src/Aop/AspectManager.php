<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Closure;

/**
 * Cache immutable aspect chains for the worker's intercepted methods.
 */
class AspectManager
{
    /**
     * @var array<string, array<string, Closure(ProceedingJoinPoint): mixed>>
     */
    protected static array $chains = [];

    /**
     * Get the compiled aspect chain for a class method.
     *
     * @return null|Closure(ProceedingJoinPoint): mixed
     */
    public static function get(string $class, string $method): ?Closure
    {
        return static::$chains[$class][$method] ?? null;
    }

    /**
     * Set the compiled aspect chain for a class method.
     *
     * @param Closure(ProceedingJoinPoint): mixed $value
     */
    public static function set(string $class, string $method, Closure $value): void
    {
        static::$chains[$class][$method] = $value;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$chains = [];
    }
}
