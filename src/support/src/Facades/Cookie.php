<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static \Symfony\Component\HttpFoundation\Cookie make(\UnitEnum|string $name, string|null $value, int $minutes = 0, string|null $path = null, string|null $domain = null, bool|null $secure = null, bool $httpOnly = true, bool $raw = false, string|null $sameSite = null)
 * @method static \Symfony\Component\HttpFoundation\Cookie forever(\UnitEnum|string $name, string $value, string|null $path = null, string|null $domain = null, bool|null $secure = null, bool $httpOnly = true, bool $raw = false, string|null $sameSite = null)
 * @method static \Symfony\Component\HttpFoundation\Cookie forget(\UnitEnum|string $name, string|null $path = null, string|null $domain = null)
 * @method static bool hasQueued(\UnitEnum|string $key, string|null $path = null)
 * @method static \Symfony\Component\HttpFoundation\Cookie|null queued(\UnitEnum|string $key, mixed $default = null, string|null $path = null)
 * @method static void queue(mixed ...$parameters)
 * @method static void expire(\UnitEnum|string $name, string|null $path = null, string|null $domain = null)
 * @method static void unqueue(\UnitEnum|string $name, string|null $path = null)
 * @method static \Hypervel\Cookie\CookieJar setDefaultPathAndDomain(string $path, string|null $domain, bool|null $secure = false, string|null $sameSite = null)
 * @method static array getQueuedCookies()
 * @method static \Hypervel\Cookie\CookieJar flushQueuedCookies()
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Cookie\CookieJar
 */
class Cookie extends Facade
{
    /**
     * Determine if a cookie exists on the request.
     */
    public static function has(string $key): bool
    {
        return ! is_null(static::$app['request']->cookie($key, null));
    }

    /**
     * Retrieve a cookie from the request.
     */
    public static function get(?string $key = null, mixed $default = null): string|array|null
    {
        return static::$app['request']->cookie($key, $default);
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'cookie';
    }
}
