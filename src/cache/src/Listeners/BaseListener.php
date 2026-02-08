<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hyperf\Event\Contract\ListenerInterface;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\Collection;
use Psr\Container\ContainerInterface;

abstract class BaseListener implements ListenerInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    protected function swooleStores(): Collection
    {
        $config = $this->container->get(Repository::class)->get('cache.stores');

        return collect($config)->where('driver', 'swoole');
    }
}
