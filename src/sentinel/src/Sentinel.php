<?php

declare(strict_types=1);

namespace Hypervel\Sentinel;

use Hypervel\Support\Facades\Facade;

/**
 * @method static mixed driver(\UnitEnum|string|null $driver = null)
 * @method static \Hypervel\Sentinel\Drivers\Driver driverOrFallback(string|null $driver)
 * @method static \Hypervel\Sentinel\SentinelManager extend(string $driver, \Closure $callback)
 * @method static \Hypervel\Sentinel\SentinelManager forgetDrivers()
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static string getDefaultDriver()
 * @method static array<array-key, mixed> getDrivers()
 * @method static \Hypervel\Sentinel\SentinelManager setContainer(\Hypervel\Contracts\Container\Container $container)
 *
 * @see \Hypervel\Sentinel\SentinelManager
 */
class Sentinel extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return SentinelManager::class;
    }
}
