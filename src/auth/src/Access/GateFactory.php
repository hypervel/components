<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Container\Container;

class GateFactory
{
    public function __invoke(Container $container)
    {
        $userResolver = $container->make(AuthManager::class)->userResolver();

        return new Gate($container, $userResolver);
    }
}
