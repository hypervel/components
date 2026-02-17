<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Contracts\Container\Container;
use Swoole\Server as SwooleServer;

class SwooleServerFactory
{
    /**
     * Create the underlying Swoole server instance.
     */
    public function __invoke(Container $container): SwooleServer
    {
        $factory = $container->make(ServerFactory::class);

        return $factory->getServer()->getServer();
    }
}
