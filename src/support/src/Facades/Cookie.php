<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Cookie\Contracts\Cookie as CookieContract;

/**
 * @method static bool has(\UnitEnum|string $key)
 * @method static string|null get(\UnitEnum|string $key, string|null $default = null)
 * @method static \Hypervel\Cookie\Cookie make(\UnitEnum|string $name, string $value, int $minutes = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = true, bool $raw = false, string|null $sameSite = null)
 * @method static void queue(mixed ...$parameters)
 * @method static void expire(\UnitEnum|string $name, string $path = '', string $domain = '')
 * @method static void unqueue(\UnitEnum|string $name, string $path = '')
 * @method static array getQueuedCookies()
 * @method static \Hypervel\Cookie\Cookie forever(\UnitEnum|string $name, string $value, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = true, bool $raw = false, string|null $sameSite = null)
 * @method static \Hypervel\Cookie\Cookie forget(\UnitEnum|string $name, string $path = '', string $domain = '')
 *
 * @see \Hypervel\Cookie\CookieManager
 */
class Cookie extends Facade
{
    protected static function getFacadeAccessor()
    {
        return CookieContract::class;
    }
}
