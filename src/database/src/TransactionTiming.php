<?php

declare(strict_types=1);

namespace Hypervel\Database;

/**
 * The timing of an outermost transaction, shared by its begin and completion events.
 *
 * Either event may be delivered after the transaction has ended, such as when the
 * dispatcher defers it, so both look up this same instance.
 *
 * @internal
 */
class TransactionTiming
{
    /**
     * Indicates if the database manager's monitors received the transaction's begin event.
     */
    public bool $beginDelivered = false;

    /**
     * The duration of the transaction in milliseconds, set when it completes.
     */
    public ?float $duration = null;

    /**
     * Create a new transaction timing instance.
     *
     * @param int $nextHandlerKey the key the connection's next duration handler would take when the transaction began
     * @param null|string $connectionName the connection name, including its read / write type, when the transaction began
     */
    public function __construct(
        public readonly float $startedAt,
        public readonly int $nextHandlerKey,
        public readonly ?string $connectionName,
    ) {
    }
}
