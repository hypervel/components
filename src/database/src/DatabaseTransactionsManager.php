<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Context\Context;
use Hypervel\Support\Collection;

/**
 * Manages database transaction callbacks in a coroutine-safe manner.
 *
 * Uses Hyperf Context to store transaction state per-coroutine, ensuring
 * that concurrent requests don't interfere with each other's transactions.
 */
class DatabaseTransactionsManager
{
    protected const CONTEXT_COMMITTED = '__db.transactions.committed';

    protected const CONTEXT_PENDING = '__db.transactions.pending';

    protected const CONTEXT_CURRENT = '__db.transactions.current';

    /**
     * Get all committed transactions for the current coroutine.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    protected function getCommittedTransactionsInternal(): Collection
    {
        return Context::get(self::CONTEXT_COMMITTED, new Collection());
    }

    /**
     * Set committed transactions for the current coroutine.
     *
     * @param Collection<int, DatabaseTransactionRecord> $transactions
     */
    protected function setCommittedTransactions(Collection $transactions): void
    {
        Context::set(self::CONTEXT_COMMITTED, $transactions);
    }

    /**
     * Get all pending transactions for the current coroutine.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    protected function getPendingTransactionsInternal(): Collection
    {
        return Context::get(self::CONTEXT_PENDING, new Collection());
    }

    /**
     * Set pending transactions for the current coroutine.
     *
     * @param Collection<int, DatabaseTransactionRecord> $transactions
     */
    protected function setPendingTransactions(Collection $transactions): void
    {
        Context::set(self::CONTEXT_PENDING, $transactions);
    }

    /**
     * Get current transaction map for the current coroutine.
     *
     * @return array<string, null|DatabaseTransactionRecord>
     */
    protected function getCurrentTransaction(): array
    {
        return Context::get(self::CONTEXT_CURRENT, []);
    }

    /**
     * Set current transaction for a connection.
     */
    protected function setCurrentTransactionForConnection(string $connection, ?DatabaseTransactionRecord $transaction): void
    {
        $current = $this->getCurrentTransaction();
        $current[$connection] = $transaction;
        Context::set(self::CONTEXT_CURRENT, $current);
    }

    /**
     * Get current transaction for a connection.
     */
    protected function getCurrentTransactionForConnection(string $connection): ?DatabaseTransactionRecord
    {
        return $this->getCurrentTransaction()[$connection] ?? null;
    }

    /**
     * Start a new database transaction.
     */
    public function begin(string $connection, int $level): void
    {
        $pending = $this->getPendingTransactionsInternal();

        $newTransaction = new DatabaseTransactionRecord(
            $connection,
            $level,
            $this->getCurrentTransactionForConnection($connection)
        );

        $pending->push($newTransaction);
        $this->setPendingTransactions($pending);
        $this->setCurrentTransactionForConnection($connection, $newTransaction);
    }

    /**
     * Commit the root database transaction and execute callbacks.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    public function commit(string $connection, int $levelBeingCommitted, int $newTransactionLevel): Collection
    {
        $this->stageTransactions($connection, $levelBeingCommitted);

        $currentForConnection = $this->getCurrentTransactionForConnection($connection);
        if ($currentForConnection !== null) {
            $this->setCurrentTransactionForConnection($connection, $currentForConnection->parent);
        }

        if (! $this->afterCommitCallbacksShouldBeExecuted($newTransactionLevel)
            && $newTransactionLevel !== 0) {
            return new Collection();
        }

        // Clear pending transactions for this connection at or above the committed level
        $pending = $this->getPendingTransactionsInternal()->reject(
            fn ($transaction) => $transaction->connection === $connection
                && $transaction->level >= $levelBeingCommitted
        )->values();
        $this->setPendingTransactions($pending);

        $committed = $this->getCommittedTransactionsInternal();
        [$forThisConnection, $forOtherConnections] = $committed->partition(
            fn ($transaction) => $transaction->connection === $connection
        );

        $this->setCommittedTransactions($forOtherConnections->values());

        $forThisConnection->map->executeCallbacks();

        return $forThisConnection;
    }

    /**
     * Move relevant pending transactions to a committed state.
     */
    public function stageTransactions(string $connection, int $levelBeingCommitted): void
    {
        $pending = $this->getPendingTransactionsInternal();
        $committed = $this->getCommittedTransactionsInternal();

        $toStage = $pending->filter(
            fn ($transaction) => $transaction->connection === $connection
                                 && $transaction->level >= $levelBeingCommitted
        );

        $this->setCommittedTransactions($committed->merge($toStage));

        $this->setPendingTransactions(
            $pending->reject(
                fn ($transaction) => $transaction->connection === $connection
                                     && $transaction->level >= $levelBeingCommitted
            )
        );
    }

    /**
     * Rollback the active database transaction.
     */
    public function rollback(string $connection, int $newTransactionLevel): void
    {
        if ($newTransactionLevel === 0) {
            $this->removeAllTransactionsForConnection($connection);
        } else {
            $pending = $this->getPendingTransactionsInternal()->reject(
                fn ($transaction) => $transaction->connection === $connection
                                     && $transaction->level > $newTransactionLevel
            )->values();
            $this->setPendingTransactions($pending);

            $currentForConnection = $this->getCurrentTransactionForConnection($connection);
            if ($currentForConnection !== null) {
                do {
                    $this->removeCommittedTransactionsThatAreChildrenOf($currentForConnection);
                    $currentForConnection->executeCallbacksForRollback();
                    $currentForConnection = $currentForConnection->parent;
                    $this->setCurrentTransactionForConnection($connection, $currentForConnection);
                } while (
                    $currentForConnection !== null
                    && $currentForConnection->level > $newTransactionLevel
                );
            }
        }
    }

    /**
     * Remove all pending, completed, and current transactions for the given connection name.
     */
    protected function removeAllTransactionsForConnection(string $connection): void
    {
        $currentForConnection = $this->getCurrentTransactionForConnection($connection);

        for ($current = $currentForConnection; $current !== null; $current = $current->parent) {
            $current->executeCallbacksForRollback();
        }

        $this->setCurrentTransactionForConnection($connection, null);

        $this->setPendingTransactions(
            $this->getPendingTransactionsInternal()->reject(
                fn ($transaction) => $transaction->connection === $connection
            )->values()
        );

        $this->setCommittedTransactions(
            $this->getCommittedTransactionsInternal()->reject(
                fn ($transaction) => $transaction->connection === $connection
            )->values()
        );
    }

    /**
     * Remove all transactions that are children of the given transaction.
     */
    protected function removeCommittedTransactionsThatAreChildrenOf(DatabaseTransactionRecord $transaction): void
    {
        $committed = $this->getCommittedTransactionsInternal();

        [$removedTransactions, $remaining] = $committed->partition(
            fn ($committed) => $committed->connection === $transaction->connection
                               && $committed->parent === $transaction
        );

        $this->setCommittedTransactions($remaining);

        // Recurse down children
        $removedTransactions->each(
            fn ($removed) => $this->removeCommittedTransactionsThatAreChildrenOf($removed)
        );
    }

    /**
     * Register a transaction callback.
     */
    public function addCallback(callable $callback): void
    {
        if ($current = $this->callbackApplicableTransactions()->last()) {
            $current->addCallback($callback);
            return;
        }

        $callback();
    }

    /**
     * Register a callback for transaction rollback.
     */
    public function addCallbackForRollback(callable $callback): void
    {
        if ($current = $this->callbackApplicableTransactions()->last()) {
            $current->addCallbackForRollback($callback);
        }
    }

    /**
     * Get the transactions that are applicable to callbacks.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    public function callbackApplicableTransactions(): Collection
    {
        return $this->getPendingTransactionsInternal();
    }

    /**
     * Determine if after commit callbacks should be executed for the given transaction level.
     */
    public function afterCommitCallbacksShouldBeExecuted(int $level): bool
    {
        return $level === 0;
    }

    /**
     * Get all of the pending transactions.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    public function getPendingTransactions(): Collection
    {
        return $this->getPendingTransactionsInternal();
    }

    /**
     * Get all of the committed transactions.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    public function getCommittedTransactions(): Collection
    {
        return $this->getCommittedTransactionsInternal();
    }
}
