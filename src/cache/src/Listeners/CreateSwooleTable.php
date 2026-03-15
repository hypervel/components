<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Cache\SwooleTableManager;
use Hypervel\Framework\Events\BeforeServerStart;

class CreateSwooleTable extends BaseListener
{
    /**
     * Create Swoole tables for all configured Swoole cache stores.
     */
    public function handle(BeforeServerStart $event): void
    {
        $this->swooleStores()->each(function (array $config) {
            $this->container->make(SwooleTableManager::class)->get($config['table']);
        });
    }
}
