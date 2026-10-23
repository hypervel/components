<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox;

use Hypervel\Bus\DispatchLockContext;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use LogicException;

class OutboxDispatcher
{
    /**
     * Create a dispatcher without borrowing a database connection.
     */
    public function __construct(
        protected DatabaseOutboxRepository $repository,
        protected ConnectionResolverInterface $resolver,
        protected DatabaseTransactionsManager $transactions,
    ) {
    }

    /**
     * Resolve the exact transaction owner and verify that its outbox is drainable.
     */
    public function databaseFor(object|string $job): ConnectionInterface
    {
        $active = [];

        foreach ($this->transactions->callbackApplicableTransactions() as $transaction) {
            $active[$transaction->connection] = true;
        }

        $explicit = $job->outboxDatabase ?? null;

        if ($explicit !== null) {
            $connection = $this->resolver->connection($explicit);

            if ($active !== [] && ! isset($active[$connection->getNameWithReadWriteType() ?? ''])) {
                throw new LogicException("Outbox database [{$explicit}] does not own an active transaction. Active connections: " . implode(', ', array_keys($active)) . '.');
            }
        } elseif (count($active) > 1) {
            throw new LogicException('Multiple database transactions are active [' . implode(', ', array_keys($active)) . ']. Select the outbox owner with onOutboxDatabase().');
        } else {
            $connection = $this->resolver->connection(array_key_first($active) ?? $this->repository->getDatabases()[0]);
        }

        $owner = $connection->getNameWithReadWriteType() ?? '';

        if (ConnectionName::parse($owner)->isRead()) {
            throw new LogicException("Outbox database [{$owner}] is a read connection. Select its base or ::write connection.");
        }

        if (! in_array($connection->getName(), $this->repository->getDatabases(), true)) {
            throw new LogicException("Outbox database [{$owner}] is not covered by queue.outbox.databases.");
        }

        return $connection;
    }

    /**
     * Store one owner group and register rollback cleanup before dispatch acceptance.
     *
     * @param non-empty-list<array<string, mixed>> $rows
     * @param non-empty-list<object|string> $jobs
     */
    public function store(ConnectionInterface $connection, array $rows, array $jobs): void
    {
        $this->repository->insert($connection, $rows);

        if ($connection->transactionLevel() === 0) {
            return;
        }

        foreach ($jobs as $job) {
            if (is_object($job) && ($snapshot = DispatchLockContext::snapshot($job)) !== null) {
                $this->transactions->addCallbackForRollback(
                    static fn () => DispatchLockContext::releaseSnapshot($snapshot),
                    $connection->getNameWithReadWriteType() ?? '',
                );
            }
        }
    }
}
