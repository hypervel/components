<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Queue\Factory as QueueManager;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class WorkerFactory
{
    public function __invoke(ContainerInterface $container): Worker
    {
        return new Worker(
            $container->get(QueueManager::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(ExceptionHandlerContract::class),
            fn () => false,
        );
    }
}
