<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTimer;
use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;

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

        $this->swooleStores()->each(function (array $config, string $name) {
            $this->timer->tick(
                $config['eviction_interval'] ?? 10000,
                fn () => $this->store($name)->evictRecords(),
            );

            $this->timer->tick(
                $config['interval_refresh_interval'] ?? 1000,
                fn () => $this->store($name)->refreshIntervalCaches(),
            );
        });
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
