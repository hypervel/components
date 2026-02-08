<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Contracts\Filesystem\Factory as FactoryContract;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Psr\Container\ContainerInterface;

class FilesystemFactory
{
    public function __invoke(ContainerInterface $container): FilesystemContract
    {
        return $container->get(FactoryContract::class)
            ->disk();
    }
}
