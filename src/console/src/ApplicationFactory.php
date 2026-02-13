<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Container\Container;
use Throwable;

class ApplicationFactory
{
    public function __invoke(Container $container)
    {
        try {
            return $container->make(KernelContract::class)
                ->getArtisan();
        } catch (Throwable $throwable) {
            (new ErrorRenderer())
                ->render($throwable);
        }

        exit;
    }
}
