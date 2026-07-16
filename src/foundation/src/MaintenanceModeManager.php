<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hypervel\Support\Manager;

class MaintenanceModeManager extends Manager
{
    /**
     * Create an instance of the file based maintenance driver.
     */
    protected function createFileDriver(): FileBasedMaintenanceMode
    {
        return new FileBasedMaintenanceMode;
    }

    /**
     * Create an instance of the cache based maintenance driver.
     *
     * @throws \Hypervel\Contracts\Container\BindingResolutionException
     */
    protected function createCacheDriver(): CacheBasedMaintenanceMode
    {
        $store = $this->config->string('app.maintenance.store');

        return new CacheBasedMaintenanceMode(
            $this->container->make('cache'),
            $store === '' ? $this->config->string('cache.default') : $store,
            'hypervel:foundation:down'
        );
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->string('app.maintenance.driver', 'file');
    }
}
