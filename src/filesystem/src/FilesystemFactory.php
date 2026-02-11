<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Contracts\Filesystem\Factory as FactoryContract;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Contracts\Container\Container;

class FilesystemFactory
{
    public function __invoke(Container $container): FilesystemContract
    {
        return $container->get(FactoryContract::class)
            ->disk();
    }
}
