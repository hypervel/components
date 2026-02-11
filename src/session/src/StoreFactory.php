<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Contracts\Session\Factory;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Container\Container;

class StoreFactory
{
    public function __invoke(Container $container): SessionContract
    {
        return $container->get(Factory::class)
            ->driver();
    }
}
