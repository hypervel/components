<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Contracts\Container\Container;

class DispatcherFactory
{
    public function __invoke(Container $container): Dispatcher
    {
        return new Dispatcher(
            $container,
            fn (?string $connection = null) => $container->get(QueueFactoryContract::class)->connection($connection)
        );
    }
}
