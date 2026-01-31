<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Psr\Container\ContainerInterface;
use Throwable;

class ApplicationFactory
{
    public function __invoke(ContainerInterface $container)
    {
        try {
            return $container->get(KernelContract::class)
                ->getArtisan();
        } catch (Throwable $throwable) {
            (new ErrorRenderer())
                ->render($throwable);
        }

        exit;
    }
}
