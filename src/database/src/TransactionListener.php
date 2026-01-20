<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\ConnectionResolverInterface;
use Hyperf\Database\Events;
use Hyperf\Database\Events\ConnectionEvent;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\Container\ContainerInterface;

class TransactionListener implements ListenerInterface
{
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    public function listen(): array
    {
        return [
            Events\TransactionBeginning::class,
            Events\TransactionCommitted::class,
            Events\TransactionRolledBack::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof ConnectionEvent) {
            return;
        }

        $transactionLevel = $this->container->get(ConnectionResolverInterface::class)
            ->connection($event->connectionName)
            ->transactionLevel();
        if ($transactionLevel !== 0) {
            return;
        }

        $this->container->get(TransactionManager::class)
            ->runCallbacks(get_class($event));
    }
}
