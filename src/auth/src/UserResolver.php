<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Contracts\Container\Container;

class UserResolver
{
    public function __invoke(Container $container): Closure
    {
        return $container->get(AuthManager::class)
            ->userResolver();
    }
}
