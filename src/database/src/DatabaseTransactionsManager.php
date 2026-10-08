<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Context\CoroutineContext;
use Hypervel\Support\Collection;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Manages database transaction callbacks in a coroutine-safe manner.
 *
 * Uses Context to store transaction state per-coroutine, ensuring
 * that concurrent requests don't interfere with each other's transactions.
 */
class DatabaseTransactionsManager
{
    /**
     * Get all committed transactions for the current coroutine.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    protected function getCommittedTransactionsInternal(): Collection
    {
        return DatabaseTransactionState::current()->committed;
    }

    /**
     * Get all pending transactions for the current coroutine.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    protected function getPendingTransactionsInternal(): Collection
    {
        return DatabaseTransactionState::current()->pending;
    }

    /**
     * Get current transaction for a connection.
     */
    protected function getCurrentTransactionForConnection(string $connection): ?DatabaseTransactionRecord
    {
        return DatabaseTransactionState::current()->current[$connection] ?? null;
    }

    /**
     * Start a new database transaction.
     */
    public function begin(string $connection, int $level): void
    {
        $state = DatabaseTransactionState::current();

        $newTransaction = new DatabaseTransactionRecord(
            $connection,
            $level,
            $state->current[$connection] ?? null
        );

        $state->pending->push($newTransaction);
        $state->current[$connection] = $newTransaction;
    }

    /**
     * Commit the root database transaction and execute callbacks.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    public function commit(string $connection, int $levelBeingCommitted, int $newTransactionLevel): Collection
    {
        $this->stageTransactions($connection, $levelBeingCommitted);

        $state = DatabaseTransactionState::current();
        $currentForConnection = $state->current[$connection] ?? null;
        if ($currentForConnection !== null) {
            $state->current[$connection] = $currentForConnection->parent;
        }

        if (! $this->afterCommitCallbacksShouldBeExecuted($newTransactionLevel)
            && $newTransactionLevel !== 0) {
            return new Collection;
        }

        // Clear pending transactions for this connection at or above the committed level
        $state->pending = $state->pending->reject(
            fn ($transaction) => $transaction->connection === $connection
                && $transaction->level >= $levelBeingCommitted
        )->values();
        [$forThisConnection, $forOtherConnections] = $state->committed->partition(
            fn ($transaction) => $transaction->connection === $connection
        );

        $state->committed = $forOtherConnections->values();

        $this->executeCommitCallbacks($forThisConnection);

        return $forThisConnection;
    }

    /**
     * Move relevant pending transactions to a committed state.
     */
    public function stageTransactions(string $connection, int $levelBeingCommitted): void
    {
        $state = DatabaseTransactionState::current();

        $toStage = $state->pending->filter(
            fn ($transaction) => $transaction->connection === $connection
                                 && $transaction->level >= $levelBeingCommitted
        );

        $state->committed = $state->committed->merge($toStage);

        $state->pending = $state->pending->reject(
            fn ($transaction) => $transaction->connection === $connection
                                 && $transaction->level >= $levelBeingCommitted
        );
    }

    /**
     * Rollback the active database transaction.
     */
    public function rollback(string $connection, int $newTransactionLevel): void
    {
        if ($newTransactionLevel === 0) {
            $this->removeAllTransactionsForConnection($connection);

            return;
        }

        $state = DatabaseTransactionState::current();
        $state->pending = $state->pending->reject(
            fn ($transaction) => $transaction->connection === $connection
                                 && $transaction->level > $newTransactionLevel
        )->values();

        $transactions = new Collection;
        $currentForConnection = $state->current[$connection] ?? null;

        while ($currentForConnection !== null
            && $currentForConnection->level > $newTransactionLevel) {
            $transactions->push($currentForConnection);
            $transactions = $transactions->concat(
                $this->removeCommittedTransactionsThatAreChildrenOf($currentForConnection)
            );
            $currentForConnection = $currentForConnection->parent;
        }

        $state->current[$connection] = $currentForConnection;

        [$stagedTransactions, $remainingCommitted] = $state->committed->partition(
            fn (DatabaseTransactionRecord $committed): bool => $transactions->contains(
                fn (DatabaseTransactionRecord $transaction): bool => $transaction === $committed
            )
        );
        $state->committed = $remainingCommitted->values();

        $this->executeRollbackCallbacks(
            $transactions
                ->concat($stagedTransactions)
                ->uniqueStrict()
                ->sortByDesc(static fn (DatabaseTransactionRecord $transaction): int => $transaction->level)
                ->values()
        );
    }

    /**
     * Remove all pending, completed, and current transactions for the given connection name.
     */
    protected function removeAllTransactionsForConnection(string $connection): void
    {
        $state = DatabaseTransactionState::current();
        [$committedForConnection, $committedForOtherConnections] = $state->committed->partition(
            fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection
        );

        $currentTransactions = new Collection;
        $currentForConnection = $state->current[$connection] ?? null;

        for ($current = $currentForConnection; $current !== null; $current = $current->parent) {
            $currentTransactions->push($current);
        }

        $transactions = $committedForConnection
            ->concat($currentTransactions)
            ->uniqueStrict()
            ->sortByDesc(static fn (DatabaseTransactionRecord $transaction): int => $transaction->level)
            ->values();

        // User callbacks must observe the transaction records as already detached.
        $state->current[$connection] = null;

        $state->pending = $state->pending->reject(
            fn ($transaction) => $transaction->connection === $connection
        )->values();

        $state->committed = $committedForOtherConnections->values();

        $this->executeRollbackCallbacks($transactions);
    }

    /**
     * Remove and return all committed descendants of the given transaction.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    protected function removeCommittedTransactionsThatAreChildrenOf(
        DatabaseTransactionRecord $transaction
    ): Collection {
        $state = DatabaseTransactionState::current();

        [$removedTransactions, $remaining] = $state->committed->partition(
            fn ($committed) => $committed->connection === $transaction->connection
                               && $committed->parent === $transaction
        );

        $state->committed = $remaining;

        foreach ($removedTransactions as $removedTransaction) {
            $removedTransactions = $removedTransactions->concat(
                $this->removeCommittedTransactionsThatAreChildrenOf($removedTransaction)
            );
        }

        return $removedTransactions;
    }

    /**
     * Execute commit callbacks for every detached transaction.
     *
     * @param Collection<int, DatabaseTransactionRecord> $transactions
     */
    protected function executeCommitCallbacks(Collection $transactions): void
    {
        $exception = null;

        foreach ($transactions as $transaction) {
            try {
                $transaction->executeCallbacks();
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Execute rollback callbacks for every detached transaction.
     *
     * @param Collection<int, DatabaseTransactionRecord> $transactions
     */
    protected function executeRollbackCallbacks(Collection $transactions): void
    {
        $cancellation = null;
        $exception = null;

        foreach ($transactions as $transaction) {
            try {
                $transaction->executeCallbacksForRollback();
            } catch (CanceledException $canceledException) {
                $cancellation ??= $canceledException;
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($cancellation !== null) {
            throw $cancellation;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Register a transaction callback.
     *
     * A null connection selects the most recently started applicable transaction on any
     * connection. The callback runs when that transaction and its enclosing stack on the
     * same connection commit.
     *
     * @param null|string $connection base name from Connection::getName()
     */
    public function addCallback(callable $callback, ?string $connection = null): void
    {
        if ($current = $this->latestApplicableTransaction($connection)) {
            $current->addCallback($callback);
            return;
        }

        $callback();
    }

    /**
     * Register a callback for transaction rollback.
     *
     * A null connection selects the most recently started applicable transaction on any
     * connection. The callback runs when that transaction and its enclosing stack on the
     * same connection roll back.
     *
     * @param null|string $connection base name from Connection::getName()
     */
    public function addCallbackForRollback(callable $callback, ?string $connection = null): void
    {
        if ($current = $this->latestApplicableTransaction($connection)) {
            $current->addCallbackForRollback($callback);
        }
    }

    /**
     * Get the latest applicable transaction, optionally limited to a connection.
     */
    protected function latestApplicableTransaction(?string $connection): ?DatabaseTransactionRecord
    {
        $transactions = $this->callbackApplicableTransactions();

        if ($connection === null) {
            return $transactions->last();
        }

        $current = null;

        foreach ($transactions as $transaction) {
            if ($transaction->connection === $connection) {
                $current = $transaction;
            }
        }

        return $current;
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

    /**
     * Check whether there are pending transactions in non-coroutine storage.
     */
    public static function hasNonCoroutinePendingTransactions(): bool
    {
        /** @var null|DatabaseTransactionState $state */
        $state = CoroutineContext::getFromNonCoroutine(DatabaseTransactionState::CONTEXT_KEY);

        return $state !== null && $state->pending->isNotEmpty();
    }

    /**
     * Copy transaction state from the current coroutine to non-coroutine storage.
     *
     * Tests only. This copies coroutine-local transaction state into worker-global
     * storage, so request-time use can leak or race across concurrent requests.
     */
    public static function copyToNonCoroutineState(): void
    {
        CoroutineContext::setNonCoroutine(
            DatabaseTransactionState::CONTEXT_KEY,
            DatabaseTransactionState::current(),
        );
    }

    /**
     * Restore the test lifecycle's transaction state in the current coroutine.
     *
     * Tests only. This deliberately shares transaction ownership across setup,
     * test and teardown; normal child coroutines must own separate transactions.
     */
    public static function copyFromNonCoroutineState(): void
    {
        $state = CoroutineContext::getFromNonCoroutine(DatabaseTransactionState::CONTEXT_KEY);

        if ($state !== null) {
            CoroutineContext::set(DatabaseTransactionState::CONTEXT_KEY, $state);
        }
    }

    /**
     * Clear all transaction state from non-coroutine storage.
     *
     * Tests only. This mutates worker-global transaction state, so request-time
     * use can race with concurrent requests using the same storage.
     */
    public static function clearNonCoroutineState(): void
    {
        CoroutineContext::clearFromNonCoroutine([DatabaseTransactionState::CONTEXT_KEY]);
    }
}
