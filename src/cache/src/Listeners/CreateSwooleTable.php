<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hyperf\Framework\Event\BeforeServerStart;
use Hypervel\Cache\SwooleTableManager;

class CreateSwooleTable extends BaseListener
{
    public function listen(): array
    {
        return [
            BeforeServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        $this->swooleStores()->each(function (array $config) {
            $this->container->make(SwooleTableManager::class)->get($config['table']);
        });
    }
}
