<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;

class DispatcherFactory
{
    public function __invoke(Container $container): Dispatcher
    {
        return new Dispatcher(
            $container,
            fn (?string $connection = null) => $container->make(QueueFactoryContract::class)->connection($connection)
        );
    }
}
