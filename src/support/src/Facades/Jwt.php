<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static \Hypervel\Jwt\Providers\Lcobucci createLcobucciDriver()
 * @method static string getDefaultDriver()
 * @method static string encode(array $payload)
 * @method static array decode(string $token, bool $validate = true, bool $checkBlacklist = true)
 * @method static string refresh(string $token, bool $forceForever = false, bool $resetClaims = false, array $customClaims = [], int|false|null $ttl = false)
 * @method static bool invalidate(string $token, bool $forceForever = false)
 * @method static bool hasBlacklistEnabled()
 * @method static mixed driver(\UnitEnum|string|null $driver = null)
 * @method static \Hypervel\Jwt\JwtManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static \Hypervel\Jwt\JwtManager setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Jwt\JwtManager forgetDrivers()
 *
 * @see \Hypervel\Jwt\JwtManager
 */
class Jwt extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'jwt';
    }
}
