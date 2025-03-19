<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Queue\Contracts\Factory as QueueFactoryContract;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

class EventDispatcherFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $listeners = $container->get(ListenerProviderInterface::class);
        $stdoutLogger = $container->get(StdoutLoggerInterface::class);
        $dispatcher = new EventDispatcher($listeners, $stdoutLogger, $container);

        $dispatcher->setQueueResolver(fn () => $container->get(QueueFactoryContract::class));

        return $dispatcher;
    }
}
