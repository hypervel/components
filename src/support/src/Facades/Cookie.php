<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static bool has(\UnitEnum|string $key)
 * @method static string|null get(\UnitEnum|string $key, string|null $default = null)
 * @method static \Hypervel\Cookie\Cookie make(\UnitEnum|string $name, string $value, int $minutes = 0, string|null $path = null, string|null $domain = null, bool|null $secure = null, bool $httpOnly = true, bool $raw = false, string|null $sameSite = null)
 * @method static void queue(mixed ...$parameters)
 * @method static void expire(\UnitEnum|string $name, string|null $path = null, string|null $domain = null)
 * @method static void unqueue(\UnitEnum|string $name, string|null $path = null)
 * @method static array getQueuedCookies()
 * @method static \Hypervel\Cookie\Cookie forever(\UnitEnum|string $name, string $value, string|null $path = null, string|null $domain = null, bool|null $secure = null, bool $httpOnly = true, bool $raw = false, string|null $sameSite = null)
 * @method static \Hypervel\Cookie\Cookie forget(\UnitEnum|string $name, string|null $path = null, string|null $domain = null)
 *
 * @see \Hypervel\Cookie\CookieJar
 */
class Cookie extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cookie';
    }
}
