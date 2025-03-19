<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hyperf\Contract\SessionInterface;
use Hypervel\Session\Contracts\Session as SessionContract;
use Psr\Container\ContainerInterface;

class AdapterFactory
{
    public function __invoke(ContainerInterface $container): SessionInterface
    {
        return new SessionAdapter(
            $container->get(SessionContract::class)
        );
    }
}
