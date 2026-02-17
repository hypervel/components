<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Framework\Events\OnManagerStart;
use Hypervel\Support\Facades\Cache;
use Swoole\Timer;

class CreateTimer extends BaseListener
{
    public function listen(): array
    {
        return [
            OnManagerStart::class,
        ];
    }

    public function process(object $event): void
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
