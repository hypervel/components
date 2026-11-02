<?php

declare(strict_types=1);

namespace Hypervel\Database;

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

        // Hyperf fires these events after the transaction level has changed.
        $manager = $this->container->get(TransactionManager::class);

        match (true) {
            $event instanceof Events\TransactionBeginning => $manager->begin($event->connection),
            $event instanceof Events\TransactionCommitted => $manager->commit($event->connection),
            $event instanceof Events\TransactionRolledBack => $manager->rollback($event->connection),
            default => null,
        };
    }
}
