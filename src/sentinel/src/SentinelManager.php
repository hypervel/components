<?php

declare(strict_types=1);

namespace Hypervel\Sentinel;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Sentinel\Drivers\Driver;
use Hypervel\Sentinel\Drivers\Hypervel;
use Hypervel\Support\Manager;
use Hypervel\Support\Str;
use InvalidArgumentException;

class SentinelManager extends Manager
{
    /**
     * Create default "hypervel" driver.
     */
    protected function createHypervelDriver(): Hypervel
    {
        // @phpstan-ignore return.type (The manager's container is the application.)
        return new Hypervel(fn (): Application => $this->getContainer());
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'hypervel';
    }

    /**
     * Get a driver instance or fallback to default.
     *
     * Only names without a registered creator fall back, so a registered driver
     * that fails to build can't silently give way to the default.
     *
     * @throws InvalidArgumentException
     */
    public function driverOrFallback(?string $driver): Driver
    {
        if ($driver !== null
            && ! isset($this->customCreators[$driver])
            && ! method_exists($this, 'create' . Str::studly($driver) . 'Driver')) {
            $driver = null;
        }

        return $this->driver($driver);
    }
}
