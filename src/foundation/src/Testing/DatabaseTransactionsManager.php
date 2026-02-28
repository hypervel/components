<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

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
     * Create a new database transaction manager instance.
     *
     * @param array<int, null|string> $connectionsTransacting
     */
    public function __construct(array $connectionsTransacting)
    {
        $this->connectionsTransacting = $connectionsTransacting;
    }

    /**
     * Register a transaction callback.
     *
     * If there are no applicable transactions (only the RefreshDatabase wrapper),
     * the callback executes immediately. Otherwise, it's queued for after commit.
     */
    public function addCallback(callable $callback): void
    {
        // If there are no transactions, we'll run the callbacks right away. Also, we'll run it
        // right away when we're in test mode and we only have the wrapping transaction. For
        // every other case, we'll queue up the callback to run after the commit happens.
        if ($this->callbackApplicableTransactions()->count() === 0) {
            $callback();
            return;
        }

        $this->callbackApplicableTransactions()->last()->addCallback($callback);
    }

    /**
     * Get the transactions that are applicable to callbacks.
     *
     * Skips the RefreshDatabase wrapper transaction(s) so callbacks are only
     * associated with transactions created within the test itself.
     *
     * @return Collection<int, \Hypervel\Database\DatabaseTransactionRecord>
     */
    public function callbackApplicableTransactions(): Collection
    {
        return $this->getPendingTransactions()->skip(count($this->connectionsTransacting))->values();
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
