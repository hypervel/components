<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hyperf\Contract\SessionInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Session\Session as SessionContract;

class AdapterFactory
{
    public function __invoke(Container $container): SessionInterface
    {
        return new SessionAdapter(
            $container->make(SessionContract::class)
        );
    }
}
