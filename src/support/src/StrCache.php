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
     * The cache of plural words.
     *
     * @var array<string, string>
     */
    protected static array $pluralCache = [];

    /**
     * The cache of singular words.
     *
     * @var array<string, string>
     */
    protected static array $singularCache = [];

    /**
     * The cache of plural studly words.
     *
     * @var array<string, string>
     */
    protected static array $pluralStudlyCache = [];

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
     * Get the plural form of a word (cached).
     *
     * Best for finite inputs like class names, not user input.
     */
    public static function plural(string $value, int|array $count = 2): string
    {
        // Only cache the common case (count = 2, which gives plural form)
        if ($count === 2 && isset(static::$pluralCache[$value])) {
            return static::$pluralCache[$value];
        }

        $result = Str::plural($value, $count);

        if ($count === 2) {
            static::$pluralCache[$value] = $result;
        }

        return $result;
    }

    /**
     * Get the singular form of a word (cached).
     *
     * Best for finite inputs like class names, not user input.
     */
    public static function singular(string $value): string
    {
        if (isset(static::$singularCache[$value])) {
            return static::$singularCache[$value];
        }

        return static::$singularCache[$value] = Str::singular($value);
    }

    /**
     * Pluralize the last word of a studly caps string (cached).
     *
     * Best for finite inputs like class names, not user input.
     */
    public static function pluralStudly(string $value, int|array $count = 2): string
    {
        // Only cache the common case (count = 2, which gives plural form)
        if ($count === 2 && isset(static::$pluralStudlyCache[$value])) {
            return static::$pluralStudlyCache[$value];
        }

        $result = Str::pluralStudly($value, $count);

        if ($count === 2) {
            static::$pluralStudlyCache[$value] = $result;
        }

        return $result;
    }

    /**
     * Flush all caches.
     */
    public static function flush(): void
    {
        static::$snakeCache = [];
        static::$camelCache = [];
        static::$studlyCache = [];
        static::$pluralCache = [];
        static::$singularCache = [];
        static::$pluralStudlyCache = [];
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

    /**
     * Flush the plural cache.
     */
    public static function flushPlural(): void
    {
        static::$pluralCache = [];
    }

    /**
     * Flush the singular cache.
     */
    public static function flushSingular(): void
    {
        static::$singularCache = [];
    }

    /**
     * Flush the plural studly cache.
     */
    public static function flushPluralStudly(): void
    {
        static::$pluralStudlyCache = [];
    }
}
