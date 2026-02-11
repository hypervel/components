<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hyperf\Event\Contract\ListenerInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Collection;

abstract class BaseListener implements ListenerInterface
{
    public function __construct(protected Container $container)
    {
    }

    protected function swooleStores(): Collection
    {
        $config = $this->container->get('config')->get('cache.stores');

        return collect($config)->where('driver', 'swoole');
    }
}
