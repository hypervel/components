<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Contracts\FactoryContract;
use Psr\Container\ContainerInterface;

class UserResolver
{
    public function __invoke(ContainerInterface $container): array
    {
        return $container->get(FactoryContract::class)
            ->userResolver();
    }
}
