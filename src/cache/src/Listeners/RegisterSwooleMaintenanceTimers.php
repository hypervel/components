<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Cache\SwooleStore;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Coordinator\Timer;
use Hypervel\Core\Events\AfterWorkerStart;
use InvalidArgumentException;
use Throwable;

class RegisterSwooleMaintenanceTimers extends BaseListener
{
    public function __construct(
        Container $container,
        protected Timer $timer,
        Repository $config,
    ) {
        parent::__construct($container, $config);
    }

    /**
     * Register maintenance timers for all configured Swoole cache stores.
     */
    public function handle(AfterWorkerStart $event): void
    {
        if ($event->workerId !== 0 || $event->server->taskworker) {
            return;
        }

        $storeIntervals = [];

        foreach ($this->swooleStores()->keys() as $name) {
            $name = (string) $name;
            $storeIntervals[$name] = [
                'eviction' => $this->intervalInSeconds("cache.stores.{$name}.eviction_interval"),
                'refresh' => $this->intervalInSeconds("cache.stores.{$name}.interval_refresh_interval"),
            ];
        }

        $stores = [];

        foreach (array_keys($storeIntervals) as $name) {
            $stores[$name] = $this->store($name);
        }

        $timerIds = [];

        try {
            foreach ($storeIntervals as $name => $intervals) {
                $store = $stores[$name];

                $timerIds[] = $this->timer->tick(
                    $intervals['eviction'],
                    fn () => $store->evictRecords(),
                );

                $timerIds[] = $this->timer->tick(
                    $intervals['refresh'],
                    fn () => $store->refreshIntervalCaches(),
                );
            }
        } catch (Throwable $throwable) {
            for ($index = count($timerIds) - 1; $index >= 0; --$index) {
                try {
                    $this->timer->clear($timerIds[$index]);
                } catch (Throwable) {
                    // Preserve the timer registration failure.
                }
            }

            throw $throwable;
        }
    }

    /**
     * Get a Swoole cache store.
     */
    protected function store(string $name): SwooleStore
    {
        /** @var SwooleStore */
        return $this->container->make('cache')->store($name)->getStore();
    }

    /**
     * Get a configured maintenance interval in seconds.
     */
    protected function intervalInSeconds(string $key): float
    {
        $milliseconds = $this->config->integer($key);

        if ($milliseconds <= 0) {
            throw new InvalidArgumentException(
                "Configuration value for key [{$key}] must be greater than zero."
            );
        }

        return $milliseconds / 1000;
    }
}
