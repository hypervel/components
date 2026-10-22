<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\QueueOutbox\Fixtures;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;

/**
 * Compare PostgreSQL claim writes without changing production lease behavior.
 * Only accepted settlement is valid for this benchmark-only schema.
 */
class ReservationRepository extends DatabaseOutboxRepository
{
    /**
     * Keep indexed eligibility stable and filter live leases through an unindexed deadline.
     */
    protected function claimOnPostgres(ConnectionInterface $connection, int $limit, int $now, int $expiresAt, string $token): array
    {
        $table = $connection->getQueryGrammar()->wrapTable($this->table);

        return $connection->selectFromWriteConnection(
            "WITH candidates AS (
                SELECT id, available_at FROM {$table}
                WHERE available_at <= ? AND (lease_token IS NULL OR reserved_until <= ?)
                ORDER BY available_at, id LIMIT ? FOR UPDATE SKIP LOCKED
            )
            UPDATE {$table} AS outgoing
            SET lease_token = ?, reserved_until = ?, attempts = outgoing.attempts + 1
            FROM candidates WHERE outgoing.id = candidates.id
            RETURNING outgoing.*, candidates.available_at AS eligible_at",
            [$now, $now, $limit, $token, $expiresAt],
        );
    }
}
