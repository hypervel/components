<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Support\MultipleInstanceManager;

/**
 * @mixin Driver
 */
class ConcurrencyManager extends MultipleInstanceManager
{
    /**
     * Get a driver instance by name.
     */
    public function driver(?string $name = null): mixed
    {
        return $this->instance($name);
    }

    /**
     * Create an instance of the coroutine concurrency driver.
     */
    public function createCoroutineDriver(): CoroutineDriver
    {
        return new CoroutineDriver;
    }

    /**
     * Create an instance of the process concurrency driver.
     */
    public function createProcessDriver(): ProcessDriver
    {
        return new ProcessDriver($this->app->make(ProcessFactory::class));
    }

    /**
     * Create an instance of the sync concurrency driver.
     */
    public function createSyncDriver(): SyncDriver
    {
        return new SyncDriver;
    }

    /**
     * Get the default instance name.
     */
    public function getDefaultInstance(): string
    {
        return $this->app['config']['concurrency.default']
            ?? $this->app['config']['concurrency.driver']
            ?? 'coroutine';
    }

    /**
     * Set the default instance name.
     *
     * WARNING: Mutates process-global config. Not safe for per-request use under Swoole.
     */
    public function setDefaultInstance(string $name): void
    {
        $this->app['config']['concurrency.default'] = $name;
        $this->app['config']['concurrency.driver'] = $name;
    }

    /**
     * Get the instance specific configuration.
     */
    public function getInstanceConfig(string $name): array
    {
        return $this->app['config']->get(
            'concurrency.driver.' . $name,
            ['driver' => $name],
        );
    }
}
