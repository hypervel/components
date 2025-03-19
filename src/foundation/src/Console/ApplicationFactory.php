<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
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
