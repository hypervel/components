<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Filesystem\Contracts\Cloud as CloudContract;
use Hypervel\Filesystem\Contracts\Factory as FactoryContract;
use Psr\Container\ContainerInterface;

class CloudStorageFactory
{
    public function __invoke(ContainerInterface $container): CloudContract
    {
        return $container->get(FactoryContract::class)
            ->cloud(CloudContract::class);
    }
}
