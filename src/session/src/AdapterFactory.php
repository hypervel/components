<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hyperf\Contract\SessionInterface;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Container\Container;

class AdapterFactory
{
    public function __invoke(Container $container): SessionInterface
    {
        return new SessionAdapter(
            $container->get(SessionContract::class)
        );
    }
}
