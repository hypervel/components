<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\ReplicableContext;
use Hypervel\Database\DatabaseTransactionRecord;

/**
 * Feature values held for the current coroutine.
 *
 * Stores are shared by every request in the worker, so the values they memoize live
 * here instead. Each request, job and child coroutine starts with its own values.
 */
class PennantState implements ReplicableContext
{
    /**
     * The coroutine context key for this state.
     */
    public const string CONTEXT_KEY = '__pennant.state';

    /**
     * The values memoized by the store decorators, keyed by connection, store, partition, feature and scope.
     *
     * Drivers without a database connection use an empty connection name.
     *
     * @var array<string, array<string, array<string, array<string, array<string, mixed>>>>>
     */
    public array $values = [];

    /**
     * The values held by array drivers, keyed by store, partition, feature and scope.
     *
     * @var array<string, array<string, array<string, array<string, mixed>>>>
     */
    public array $arrayValues = [];

    /**
     * The connections whose memoized values are forgotten if an open transaction rolls back, keyed by the transaction record's object ID.
     *
     * @var array<int, array<string, true>>
     */
    public array $transactions = [];

    /**
     * Get the state of the current coroutine.
     */
    public static function current(): self
    {
        return CoroutineContext::get(self::CONTEXT_KEY) ?? CoroutineContext::set(self::CONTEXT_KEY, new self);
    }

    /**
     * Forget the values memoized for the connection if the given transaction rolls back.
     */
    public function forgetOnRollback(DatabaseTransactionRecord $record, string $connection): void
    {
        $id = spl_object_id($record);

        if (isset($this->transactions[$id])) {
            $this->transactions[$id][$connection] = true;

            return;
        }

        $this->transactions[$id] = [$connection => true];

        // Commit and rollback callbacks always run once the record is detached, so its ID is never reused while tracked.
        $record->addCallback(function () use ($id): void {
            unset($this->transactions[$id]);
        });

        $record->addCallbackForRollback(function () use ($id): void {
            foreach ($this->transactions[$id] ?? [] as $connection => $tracked) {
                unset($this->values[$connection]);
            }

            unset($this->transactions[$id]);
        });
    }

    /**
     * Create the state for a copied coroutine context.
     *
     * The copy keeps the memoized and array values, except values for connections in an open
     * transaction, which the copy can't see roll back. Transactions are never inherited.
     */
    public function replicate(): static
    {
        $state = clone $this;
        $state->transactions = [];

        foreach ($this->transactions as $connections) {
            foreach ($connections as $connection => $tracked) {
                unset($state->values[$connection]);
            }
        }

        return $state;
    }
}
