<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static void addJsonPath(string $path)
 * @method static void addLines(array $lines, string $locale, string $namespace = '*')
 * @method static void addNamespace(string $namespace, string $hint)
 * @method static void addPath(string $path)
 * @method static array<array-key, mixed> array(string $key, array $replace = [], string|null $locale = null, bool $fallback = true)
 * @method static string choice(string $key, \Countable|array|int|float $number, array $replace = [], string|null $locale = null)
 * @method static void determineLocalesUsing(callable $callback)
 * @method static void flushMacros()
 * @method static void flushParsedKeys()
 * @method static void flushState()
 * @method static array|string get(string $key, array $replace = [], string|null $locale = null, bool $fallback = true)
 * @method static string getFallback()
 * @method static \Hypervel\Contracts\Translation\Loader getLoader()
 * @method static string getLocale()
 * @method static \Hypervel\Translation\MessageSelector getSelector()
 * @method static \Hypervel\Translation\Translator handleMissingKeysUsing(callable|null $callback)
 * @method static bool has(string $key, string|null $locale = null, bool $fallback = true)
 * @method static bool hasForLocale(string $key, string|null $locale = null)
 * @method static bool hasMacro(string $name)
 * @method static void load(string $namespace, string $group, string $locale)
 * @method static string locale()
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static array parseKey(string $key)
 * @method static void setBaseLocale(string $locale)
 * @method static void setFallback(string $fallback)
 * @method static void setLoaded(array $loaded)
 * @method static void setLocale(string $locale)
 * @method static void setParsedKey(string $key, array $parsed)
 * @method static void setSelector(\Hypervel\Translation\MessageSelector $selector)
 * @method static string string(string $key, array $replace = [], string|null $locale = null, bool $fallback = true)
 * @method static void stringable(string|\Closure $class, callable|null $handler = null)
 *
 * @see \Hypervel\Translation\Translator
 */
class Lang extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'translator';
    }
}
