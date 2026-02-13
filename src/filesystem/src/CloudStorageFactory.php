<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Filesystem\Cloud as CloudContract;
use Hypervel\Contracts\Filesystem\Factory as FactoryContract;

class CloudStorageFactory
{
    public function __invoke(Container $container): CloudContract
    {
        /** @var \Hypervel\Filesystem\FilesystemManager $manager */
        $manager = $container->make(FactoryContract::class);

        return $manager->cloud();
    }
}
