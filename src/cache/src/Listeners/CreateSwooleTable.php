<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Cache\SwooleTableManager;
use Hypervel\Core\Events\BeforeServerStart;

class CreateSwooleTable extends BaseListener
{
    /**
     * Create Swoole tables for all configured Swoole cache stores.
     */
    public function handle(BeforeServerStart $event): void
    {
        $tables = $this->container->make(SwooleTableManager::class);

        $this->swooleStores()->each(function (array $config) use ($tables): void {
            $tables->get($config['table']);
        });

        $tables->seal();
    }
}
