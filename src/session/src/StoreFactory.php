<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Session\Contracts\Factory;
use Hypervel\Session\Contracts\Session as SessionContract;
use Psr\Container\ContainerInterface;

class StoreFactory
{
    public function __invoke(ContainerInterface $container): SessionContract
    {
        return $container->get(Factory::class)
            ->driver();
    }
}
