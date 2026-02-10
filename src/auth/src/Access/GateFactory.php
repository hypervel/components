<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Container\Container;

class GateFactory
{
    public function __invoke(Container $container)
    {
        $userResolver = $container->get(AuthFactoryContract::class)->userResolver();

        return new Gate($container, $userResolver);
    }
}
