<?php

declare(strict_types=1);

namespace Hypervel\Log\Adapter;

use Hypervel\Contracts\Container\Container;

class HyperfLogFactory
{
    public function __invoke(Container $container): LogFactoryAdapter
    {
        return new LogFactoryAdapter(
            $container,
            $container->get('config')
        );
    }
}
