<?php

declare(strict_types=1);

namespace Hypervel\Cache\Listeners;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Collection;

abstract class BaseListener
{
    public function __construct(
        protected Container $container,
        protected Repository $config,
    ) {
    }

    protected function swooleStores(): Collection
    {
        return collect($this->config->array('cache.stores'))->where('driver', 'swoole');
    }
}
