<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Contracts\Filesystem\Cloud as CloudContract;
use Hypervel\Contracts\Filesystem\Factory as FactoryContract;
use Psr\Container\ContainerInterface;

class CloudStorageFactory
{
    public function __invoke(ContainerInterface $container): CloudContract
    {
        return $container->get(FactoryContract::class)
            ->cloud(CloudContract::class);
    }
}
