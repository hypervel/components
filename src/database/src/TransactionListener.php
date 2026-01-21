<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Events\ConnectionEvent;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
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
            TransactionBeginning::class,
            TransactionCommitted::class,
            TransactionRolledBack::class,
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
