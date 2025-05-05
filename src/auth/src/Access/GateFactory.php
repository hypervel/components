<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Hyperf\Contract\ContainerInterface;
use Hypervel\Auth\Contracts\Factory as AuthFactoryContract;

use function Hyperf\Support\make;

class GateFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $userResolver = $container->get(AuthFactoryContract::class)->userResolver();

        return make(Gate::class, compact('container', 'userResolver'));
    }
}
