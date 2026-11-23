<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Carbon\CarbonInterval;
use Closure;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\MultipleInstanceManager;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @method array run(Closure|array $tasks, CarbonInterval|int|null $timeout = null)
 *
 * @mixin Driver
 */
class ConcurrencyManager extends MultipleInstanceManager
{
    /**
     * Get a driver instance by name.
     *
     * @return Driver
     */
    public function driver(UnitEnum|string|null $name = null): mixed
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

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

    // Laravel's fork driver is intentionally omitted because Swoole coroutines
    // are Hypervel's native lightweight execution model.

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
        return $this->config->string('concurrency.default');
    }

    /**
     * Set the default concurrency driver name.
     *
     * Mutates process-global config; per-request use races across coroutines.
     */
    #[BootOnly]
    public function setDefaultInstance(string $name): void
    {
        $this->config->set('concurrency.default', $name);
    }

    /**
     * Get the instance specific configuration.
     */
    public function getInstanceConfig(string $name): array
    {
        return $this->config->array(
            'concurrency.driver.' . $name,
            ['driver' => $name],
        );
    }
}
