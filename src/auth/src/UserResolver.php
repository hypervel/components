<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Container\Container;

class UserResolver
{
    public function __invoke(Container $container): array
    {
        return $container->get(AuthFactoryContract::class)
            ->userResolver();
    }
}
