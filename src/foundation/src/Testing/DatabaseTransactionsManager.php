<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionsManager as BaseManager;
use Hypervel\Support\Collection;

/**
 * Testing-aware transaction manager that properly handles RefreshDatabase's wrapper transaction.
 *
 * When RefreshDatabase wraps tests in a transaction, this manager ensures that afterCommit
 * callbacks execute at the appropriate time (when returning to the wrapper level) rather
 * than waiting for level 0 which never happens during wrapped tests.
 */
class DatabaseTransactionsManager extends BaseManager
{
    /**
     * The names of the connections transacting during tests.
     *
     * @var array<int, null|string>
     */
    protected array $connectionsTransacting;

    /**
     * The transaction records owned by the test wrappers.
     *
     * @var array<int, DatabaseTransactionRecord>
     */
    protected array $wrappingTransactions = [];

    /**
     * Create a new database transaction manager instance.
     *
     * @param array<int, null|string> $connectionsTransacting
     */
    public function __construct(array $connectionsTransacting)
    {
        $this->connectionsTransacting = $connectionsTransacting;
    }

    /**
     * Begin the test wrappers and remember their transaction records.
     */
    public function beginTransactions(DatabaseManager $database): void
    {
        foreach ($this->connectionsTransacting as $name) {
            /** @var Connection $connection */
            $connection = $database->connection($name);
            $connection->setTransactionManager($this);
            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();

            try {
                $connection->beginTransaction();
                $this->wrappingTransactions[] = $this->getCurrentTransactionForConnection($connection->getNameWithReadWriteType() ?? '');
            } finally {
                if ($dispatcher !== null) {
                    $connection->setEventDispatcher($dispatcher);
                }
            }
        }
    }

    /**
     * Get the transactions that are applicable to callbacks.
     *
     * Only actual wrapper records are excluded: pooled child sessions can have
     * level-one application transactions on the same connection name.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    public function callbackApplicableTransactions(): Collection
    {
        return $this->getPendingTransactions()->reject(
            fn (DatabaseTransactionRecord $transaction): bool => in_array($transaction, $this->wrappingTransactions, true)
        )->values();
    }

    /**
     * Determine if after commit callbacks should be executed for the given transaction level.
     *
     * Returns true at level 1 (the RefreshDatabase wrapper level) instead of level 0,
     * since the wrapper transaction is never committed during tests.
     */
    public function afterCommitCallbacksShouldBeExecuted(int $level): bool
    {
        return $level === 1;
    }
}
