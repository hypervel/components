<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Foundation\MaintenanceModeManager;

/**
 * @method static string getDefaultDriver()
 * @method static mixed driver(string|null $driver = null)
 * @method static \Hypervel\Foundation\MaintenanceModeManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static \Hypervel\Foundation\MaintenanceModeManager setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Foundation\MaintenanceModeManager forgetDrivers()
 *
 * @see \Hypervel\Foundation\MaintenanceModeManager
 */
class MaintenanceMode extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return MaintenanceModeManager::class;
    }
}
