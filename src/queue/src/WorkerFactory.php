<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Queue\Factory as QueueManager;

class WorkerFactory
{
    public function __invoke(Container $container): Worker
    {
        return new Worker(
            $container->make(QueueManager::class),
            $container->make(Dispatcher::class),
            $container->make(ExceptionHandlerContract::class),
            fn () => false,
        );
    }
}
