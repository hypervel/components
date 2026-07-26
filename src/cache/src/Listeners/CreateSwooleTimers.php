<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTimer;
use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;
use RuntimeException;
use Throwable;

class CreateSwooleTimers extends BaseListener
{
    public function __construct(Container $container, protected SwooleTimer $timer)
    {
        parent::__construct($container);
    }

    /**
     * Create timers for all configured Swoole cache stores.
     */
    public function handle(AfterWorkerStart $event): void
    {
        if (! $this->shouldRegisterTimers($event)) {
            return;
        }

        $timerIds = [];

        try {
            foreach ($this->swooleStores() as $name => $config) {
                $timerId = $this->timer->tick(
                    $config['eviction_interval'] ?? 10000,
                    fn () => $this->store($name)->evictRecords(),
                );

                if ($timerId === false) {
                    throw new RuntimeException("Unable to register the Swoole cache eviction timer for store [{$name}].");
                }

                $timerIds[] = $timerId;

                $timerId = $this->timer->tick(
                    $config['interval_refresh_interval'] ?? 1000,
                    fn () => $this->store($name)->refreshIntervalCaches(),
                );

                if ($timerId === false) {
                    throw new RuntimeException("Unable to register the Swoole cache interval refresh timer for store [{$name}].");
                }

                $timerIds[] = $timerId;
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
     * Determine if this worker should own Swoole cache timers.
     */
    protected function shouldRegisterTimers(AfterWorkerStart $event): bool
    {
        return $event->workerId === 0 && ! $event->server->taskworker;
    }

    /**
     * Get a Swoole cache store.
     */
    protected function store(string $name): SwooleStore
    {
        /** @var SwooleStore */
        return $this->container->make('cache')->store($name)->getStore();
    }
}
