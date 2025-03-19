<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hyperf\Contract\TranslatorInterface;

/**
 * @method static bool hasForLocale(string $key, string|null $locale = null)
 * @method static bool has(string $key, string|null $locale = null, bool $fallback = true)
 * @method static array|string trans(string $key, array $replace = [], string|null $locale = null)
 * @method static array|string get(string $key, array $replace = [], string|null $locale = null, bool $fallback = true)
 * @method static array|string getFromJson(string $key, array $replace = [], string|null $locale = null)
 * @method static string transChoice(string $key, array|\Countable|int $number, array $replace = [], string|null $locale = null)
 * @method static string choice(string $key, \Countable|array|int $number, array $replace = [], string|null $locale = null)
 * @method static void addLines(array $lines, string $locale, string $namespace = '*')
 * @method static void load(string $namespace, string $group, string $locale)
 * @method static void addNamespace(string $namespace, string $hint)
 * @method static void addJsonPath(string $path)
 * @method static array parseKey(string $key)
 * @method static \Hyperf\Translation\MessageSelector getSelector()
 * @method static void setSelector(\Hyperf\Translation\MessageSelector $selector)
 * @method static \Hyperf\Contract\TranslatorLoaderInterface getLoader()
 * @method static string locale()
 * @method static string getLocaleContextKey()
 * @method static string getLocale()
 * @method static void setLocale(string $locale)
 * @method static string getFallback()
 * @method static void setFallback(string $fallback)
 * @method static void setLoaded(array $loaded)
 * @method static void setParsedKey(string $key, array $parsed)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hyperf\Translation\Translator
 */
class Lang extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TranslatorInterface::class;
    }
}
