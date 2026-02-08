<?php

declare(strict_types=1);

namespace Hypervel\Log\Adapter;

use Hypervel\Contracts\Config\Repository;
use Psr\Container\ContainerInterface;

class HyperfLogFactory
{
    public function __invoke(ContainerInterface $container): LogFactoryAdapter
    {
        return new LogFactoryAdapter(
            $container,
            $container->get(Repository::class)
        );
    }
}
