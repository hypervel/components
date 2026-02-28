<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Event\Contracts\ListenerProvider;

class EventDispatcherFactory
{
    public function __invoke(Container $container)
    {
        $listeners = $container->make(ListenerProvider::class);
        $stdoutLogger = $container->make(StdoutLoggerInterface::class);
        $dispatcher = new EventDispatcher($listeners, $stdoutLogger, $container);

        $dispatcher->setQueueResolver(fn () => $container->make(QueueFactoryContract::class));

        $dispatcher->setTransactionManagerResolver(
            fn () => $container->has('db.transactions')
                ? $container->make('db.transactions')
                : null
        );

        return $dispatcher;
    }
}
