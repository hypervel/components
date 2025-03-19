<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\JWT\Contracts\ManagerContract;

/**
 * @method static \Hypervel\JWT\Providers\Lcobucci createLcobucciDriver()
 * @method static string getDefaultDriver()
 * @method static string encode(array $payload)
 * @method static array decode(string $token, bool $validate = true, bool $checkBlacklist = true)
 * @method static string refresh(string $token, bool $forceForever = false)
 * @method static bool invalidate(string $token, bool $forceForever = false)
 * @method static mixed driver(string|null $driver = null)
 * @method static \Hypervel\JWT\JWTManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static \Psr\Container\ContainerInterface getContainer()
 * @method static \Hypervel\JWT\JWTManager setContainer(\Psr\Container\ContainerInterface $container)
 * @method static \Hypervel\JWT\JWTManager forgetDrivers()
 *
 * @see \Hypervel\JWT\JWTManager
 */
class JWT extends Facade
{
    protected static function getFacadeAccessor()
    {
        return ManagerContract::class;
    }
}
