<?php

declare(strict_types=1);

namespace Hypervel\Support;

/**
 * Cached string transformations for known-finite inputs.
 *
 * Use this class for framework internals where input strings come from
 * a finite set (class names, attribute names, column names, etc.).
 *
 * For arbitrary or user-provided input, use Str methods directly
 * to avoid unbounded cache growth in long-running Swoole workers.
 */
class StrCache
{
    /**
     * The cache of snake-cased words.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $snakeCache = [];

    /**
     * The cache of camel-cased words.
     *
     * @var array<string, string>
     */
    protected static array $camelCache = [];

    /**
     * The cache of studly-cased words.
     *
     * @var array<string, string>
     */
    protected static array $studlyCache = [];

    /**
     * Convert a string to snake case (cached).
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (isset(static::$snakeCache[$value][$delimiter])) {
            return static::$snakeCache[$value][$delimiter];
        }

        return static::$snakeCache[$value][$delimiter] = Str::snake($value, $delimiter);
    }

    /**
     * Convert a value to camel case (cached).
     */
    public static function camel(string $value): string
    {
        if (isset(static::$camelCache[$value])) {
            return static::$camelCache[$value];
        }

        return static::$camelCache[$value] = Str::camel($value);
    }

    /**
     * Convert a value to studly case (cached).
     */
    public static function studly(string $value): string
    {
        if (isset(static::$studlyCache[$value])) {
            return static::$studlyCache[$value];
        }

        return static::$studlyCache[$value] = Str::studly($value);
    }

    /**
     * Flush all caches.
     */
    public static function flush(): void
    {
        static::$snakeCache = [];
        static::$camelCache = [];
        static::$studlyCache = [];
    }

    /**
     * Flush the snake cache.
     */
    public static function flushSnake(): void
    {
        static::$snakeCache = [];
    }

    /**
     * Flush the camel cache.
     */
    public static function flushCamel(): void
    {
        static::$camelCache = [];
    }

    /**
     * Flush the studly cache.
     */
    public static function flushStudly(): void
    {
        static::$studlyCache = [];
    }
}
