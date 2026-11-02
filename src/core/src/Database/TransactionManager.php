<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hyperf\Context\Context;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Events\TransactionCommitted;
use Hyperf\Database\Events\TransactionRolledBack;

/**
 * Tracks the open transactions of the current coroutine so a callback runs
 * once the transaction it was registered in commits, or is discarded when
 * that transaction rolls back. TransactionListener drives the state.
 */
class TransactionManager
{
    protected const CONTEXT_KEY = '_db.transactions';

    /**
     * Register a callback on the innermost open transaction.
     *
     * Without an open transaction a commit callback runs at once, and any
     * other callback is discarded as there is no transaction to wait for.
     */
    public function addCallback(callable $callback, ?string $event = null): void
    {
        $event = $this->getEvent($event);

        $this->discardTransactions(
            fn (array $transaction) => $transaction['level'] > $transaction['connection']->transactionLevel()
        );

        $transactions = $this->getTransactions();

        if ($transactions === []) {
            if ($event === TransactionCommitted::class) {
                $callback();
            }

            return;
        }

        $transactions[array_key_last($transactions)]['callbacks'][$event][] = $callback;

        $this->setTransactions($transactions);
    }

    /**
     * Record the transaction that has just begun on the connection.
     */
    public function begin(ConnectionInterface $connection): void
    {
        $level = $connection->transactionLevel();

        // The connection was at the level below before this transaction began,
        // so anything recorded deeper was dropped without a rollback event.
        $this->discardTransactions(
            fn (array $transaction) => $transaction['connection'] === $connection && $transaction['level'] >= $level
        );

        $transactions = $this->getTransactions();
        $transactions[] = ['connection' => $connection, 'level' => $level, 'callbacks' => []];

        $this->setTransactions($transactions);
    }

    /**
     * Handle a commit that has just lowered the connection's transaction level.
     */
    public function commit(ConnectionInterface $connection): void
    {
        $level = $connection->transactionLevel();

        // Only the transaction at the level above was committed, so anything
        // recorded deeper was dropped without a rollback event.
        $this->discardTransactions(
            fn (array $transaction) => $transaction['connection'] === $connection && $transaction['level'] > $level + 1
        );

        $transactions = $this->getTransactions();

        if (is_null($committed = $this->findTransaction($transactions, $connection, $level + 1))) {
            return;
        }

        $callbacks = $transactions[$committed]['callbacks'];

        unset($transactions[$committed]);
        $transactions = array_values($transactions);

        // Releasing a savepoint hands its callbacks to the enclosing transaction.
        if (! is_null($parent = $this->findTransaction($transactions, $connection, $level))) {
            $transactions[$parent]['callbacks'] = array_merge_recursive($transactions[$parent]['callbacks'], $callbacks);

            $this->setTransactions($transactions);

            return;
        }

        // Forget the transaction first so callbacks registered while these
        // run are not attached to a transaction that has already committed.
        $this->setTransactions($transactions);

        foreach ($callbacks[TransactionCommitted::class] ?? [] as $callback) {
            $callback();
        }
    }

    /**
     * Handle a rollback that has just lowered the connection's transaction level.
     */
    public function rollback(ConnectionInterface $connection): void
    {
        $level = $connection->transactionLevel();

        $this->discardTransactions(
            fn (array $transaction) => $transaction['connection'] === $connection && $transaction['level'] > $level
        );
    }

    /**
     * Get the callbacks of the given event registered on the open transactions.
     *
     * @deprecated callbacks run when their own transaction commits or rolls back
     */
    public function getCallbacks(?string $event = null): array
    {
        $event = $this->getEvent($event);

        return array_merge(...array_map(
            fn (array $transaction) => $transaction['callbacks'][$event] ?? [],
            $this->getTransactions()
        ));
    }

    /**
     * Remove the callbacks of the given event from the open transactions.
     *
     * @deprecated callbacks run when their own transaction commits or rolls back
     */
    public function clearCallbacks(?string $event): void
    {
        $event = $this->getEvent($event);

        $this->setTransactions(array_map(function (array $transaction) use ($event) {
            unset($transaction['callbacks'][$event]);

            return $transaction;
        }, $this->getTransactions()));
    }

    /**
     * Run and remove the callbacks of the given event on the open transactions.
     *
     * @deprecated callbacks run when their own transaction commits or rolls back
     */
    public function runCallbacks(?string $event = null): void
    {
        $callbacks = $this->getCallbacks($event);

        $this->clearCallbacks($event);

        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    public function getEvent(?string $event = null): string
    {
        return $event ?? TransactionCommitted::class;
    }

    /**
     * Get the open transactions of the current coroutine, innermost last.
     */
    protected function getTransactions(): array
    {
        return Context::get(self::CONTEXT_KEY, []);
    }

    protected function setTransactions(array $transactions): void
    {
        Context::set(self::CONTEXT_KEY, $transactions);
    }

    /**
     * Find the innermost recorded transaction of the connection at the given level.
     */
    protected function findTransaction(array $transactions, ConnectionInterface $connection, int $level): ?int
    {
        foreach (array_reverse($transactions, true) as $index => $transaction) {
            if ($transaction['connection'] === $connection && $transaction['level'] === $level) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Forget the matching transactions as rolled back and run their rollback callbacks.
     *
     * Hyperf also lowers the level without a rollback event when a rollback
     * loses the connection or a nested transaction deadlocks, so a transaction
     * recorded deeper than its connection's level has already rolled back.
     *
     * @param callable(array): bool $rolledBack
     */
    protected function discardTransactions(callable $rolledBack): void
    {
        $remaining = [];
        $discarded = [];

        foreach ($this->getTransactions() as $transaction) {
            if ($rolledBack($transaction)) {
                $discarded[] = $transaction;
            } else {
                $remaining[] = $transaction;
            }
        }

        if ($discarded === []) {
            return;
        }

        $this->setTransactions($remaining);

        foreach (array_reverse($discarded) as $transaction) {
            foreach ($transaction['callbacks'][TransactionRolledBack::class] ?? [] as $callback) {
                $callback();
            }
        }
    }
}
