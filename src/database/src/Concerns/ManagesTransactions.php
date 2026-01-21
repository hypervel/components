<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Closure;
use Hypervel\Database\DeadlockException;
use RuntimeException;
use Throwable;

/**
 * @mixin \Hypervel\Database\Connection
 */
trait ManagesTransactions
{
    /**
     * @template TReturn of mixed
     *
     * Execute a Closure within a transaction.
     *
     * @param  (\Closure(static): TReturn)  $callback
     * @return TReturn
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            // We'll simply execute the given callback within a try / catch block and if we
            // catch any exception we can rollback this transaction so that none of this
            // gets actually persisted to a database or stored in a permanent fashion.
            try {
                $callbackResult = $callback($this);
            }

            // If we catch an exception we'll rollback this transaction and try again if we
            // are not out of attempts. If we are out of attempts we will just throw the
            // exception back out, and let the developer handle an uncaught exception.
            catch (Throwable $e) {
                $this->handleTransactionException(
                    $e, $currentAttempt, $attempts
                );

                continue;
            }

            $levelBeingCommitted = $this->transactions;

            try {
                if ($this->transactions == 1) {
                    $this->fireConnectionEvent('committing');
                    $this->getPdo()->commit();
                }

                $this->transactions = max(0, $this->transactions - 1);
            } catch (Throwable $e) {
                $this->handleCommitTransactionException(
                    $e, $currentAttempt, $attempts
                );

                continue;
            }

            $this->transactionsManager?->commit(
                $this->getName(),
                $levelBeingCommitted,
                $this->transactions
            );

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }

    /**
     * Handle an exception encountered when running a transacted statement.
     *
     * @throws \Throwable
     */
    protected function handleTransactionException(Throwable $e, int $currentAttempt, int $maxAttempts): void
    {
        // On a deadlock, MySQL rolls back the entire transaction so we can't just
        // retry the query. We have to throw this exception all the way out and
        // let the developer handle it in another way. We will decrement too.
        if ($this->causedByConcurrencyError($e) &&
            $this->transactions > 1) {
            $this->transactions--;

            $this->transactionsManager?->rollback(
                $this->getName(), $this->transactions
            );

            throw new DeadlockException($e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }

        // If there was an exception we will rollback this transaction and then we
        // can check if we have exceeded the maximum attempt count for this and
        // if we haven't we will return and try this query again in our loop.
        $this->rollBack();

        if ($this->causedByConcurrencyError($e) &&
            $currentAttempt < $maxAttempts) {
            return;
        }

        throw $e;
    }

    /**
     * Start a new database transaction.
     *
     * @throws \Throwable
     */
    public function beginTransaction(): void
    {
        foreach ($this->beforeStartingTransaction as $callback) {
            $callback($this);
        }

        $this->createTransaction();

        $this->transactions++;

        $this->transactionsManager?->begin(
            $this->getName(), $this->transactions
        );

        $this->fireConnectionEvent('beganTransaction');
    }

    /**
     * Create a transaction within the database.
     *
     * @throws \Throwable
     */
    protected function createTransaction(): void
    {
        if ($this->transactions == 0) {
            $this->reconnectIfMissingConnection();

            try {
                $this->executeBeginTransactionStatement();
            } catch (Throwable $e) {
                $this->handleBeginTransactionException($e);
            }
        } elseif ($this->transactions >= 1 && $this->queryGrammar->supportsSavepoints()) {
            $this->createSavepoint();
        }
    }

    /**
     * Create a save point within the database.
     *
     * @throws \Throwable
     */
    protected function createSavepoint(): void
    {
        $this->getPdo()->exec(
            $this->queryGrammar->compileSavepoint('trans'.($this->transactions + 1))
        );
    }

    /**
     * Handle an exception from a transaction beginning.
     *
     * @throws \Throwable
     */
    protected function handleBeginTransactionException(Throwable $e): void
    {
        if ($this->causedByLostConnection($e)) {
            $this->reconnect();

            $this->executeBeginTransactionStatement();
        } else {
            throw $e;
        }
    }

    /**
     * Commit the active database transaction.
     *
     * @throws \Throwable
     */
    public function commit(): void
    {
        if ($this->transactionLevel() == 1) {
            $this->fireConnectionEvent('committing');
            $this->getPdo()->commit();
        }

        [$levelBeingCommitted, $this->transactions] = [
            $this->transactions,
            max(0, $this->transactions - 1),
        ];

        $this->transactionsManager?->commit(
            $this->getName(), $levelBeingCommitted, $this->transactions
        );

        $this->fireConnectionEvent('committed');
    }

    /**
     * Handle an exception encountered when committing a transaction.
     *
     * @throws \Throwable
     */
    protected function handleCommitTransactionException(Throwable $e, int $currentAttempt, int $maxAttempts): void
    {
        $this->transactions = max(0, $this->transactions - 1);

        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            return;
        }

        if ($this->causedByLostConnection($e)) {
            $this->transactions = 0;
        }

        throw $e;
    }

    /**
     * Rollback the active database transaction.
     *
     * @throws \Throwable
     */
    public function rollBack(?int $toLevel = null): void
    {
        // We allow developers to rollback to a certain transaction level. We will verify
        // that this given transaction level is valid before attempting to rollback to
        // that level. If it's not we will just return out and not attempt anything.
        $toLevel = is_null($toLevel)
            ? $this->transactions - 1
            : $toLevel;

        if ($toLevel < 0 || $toLevel >= $this->transactions) {
            return;
        }

        // Next, we will actually perform this rollback within this database and fire the
        // rollback event. We will also set the current transaction level to the given
        // level that was passed into this method so it will be right from here out.
        try {
            $this->performRollBack($toLevel);
        } catch (Throwable $e) {
            $this->handleRollBackException($e);
        }

        $this->transactions = $toLevel;

        $this->transactionsManager?->rollback(
            $this->getName(), $this->transactions
        );

        $this->fireConnectionEvent('rollingBack');
    }

    /**
     * Perform a rollback within the database.
     *
     * @throws \Throwable
     */
    protected function performRollBack(int $toLevel): void
    {
        if ($toLevel == 0) {
            $pdo = $this->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } elseif ($this->queryGrammar->supportsSavepoints()) {
            $this->getPdo()->exec(
                $this->queryGrammar->compileSavepointRollBack('trans'.($toLevel + 1))
            );
        }
    }

    /**
     * Handle an exception from a rollback.
     *
     * @throws \Throwable
     */
    protected function handleRollBackException(Throwable $e): void
    {
        if ($this->causedByLostConnection($e)) {
            $this->transactions = 0;

            $this->transactionsManager?->rollback(
                $this->getName(), $this->transactions
            );
        }

        throw $e;
    }

    /**
     * Get the number of active transactions.
     */
    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /**
     * Execute the callback after a transaction commits.
     *
     * @throws \RuntimeException
     */
    public function afterCommit(callable $callback): void
    {
        if ($this->transactionsManager) {
            $this->transactionsManager->addCallback($callback);

            return;
        }

        throw new RuntimeException('Transactions Manager has not been set.');
    }

    /**
     * Execute the callback after a transaction rolls back.
     *
     * @throws \RuntimeException
     */
    public function afterRollBack(callable $callback): void
    {
        if ($this->transactionsManager) {
            $this->transactionsManager->addCallbackForRollback($callback);

            return;
        }

        throw new RuntimeException('Transactions Manager has not been set.');
    }
}
