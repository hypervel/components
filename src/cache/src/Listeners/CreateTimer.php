<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Framework\Events\OnManagerStart;
use Hypervel\Support\Facades\Cache;
use Swoole\Timer;

class CreateTimer extends BaseListener
{
    /**
     * Create eviction timers for all configured Swoole cache stores.
     */
    public function handle(OnManagerStart $event): void
    {
        $this->swooleStores()->each(function (array $config, string $name) {
            Timer::tick($config['eviction_interval'] ?? 10000, function () use ($name) {
                /** @var \Hypervel\Cache\SwooleStore */
                $store = Cache::store($name)->getStore();

                $store->evictRecords();
            });
        });
    }
}
