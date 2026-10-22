<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Queue\Concerns\InsertsDatabaseRows;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use InvalidArgumentException;

class DatabaseOutboxRepository
{
    use InsertsDatabaseRows;
    use InteractsWithTime;

    public const array RETRY_DELAYS = [1, 5, 15, 30, 60, 300, 600];

    /**
     * Create a repository that resolves connections only for the current operation.
     *
     * @param list<string> $databases
     */
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        protected array $databases,
        protected string $table,
        protected int $retryAfter,
    ) {
        if ($databases === []) {
            throw new InvalidArgumentException('At least one outbox database must be configured.');
        }

        if ($retryAfter <= 0) {
            throw new InvalidArgumentException('The outbox retry_after must be greater than zero.');
        }
    }

    /**
     * Get the configured outbox sources.
     *
     * @return list<string>
     */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    /**
     * Resolve a configured outbox source in the current coroutine.
     */
    public function getConnection(string $database): ConnectionInterface
    {
        if (! in_array($database, $this->databases, true)) {
            throw new InvalidArgumentException("Outbox database [{$database}] is not configured in queue.outbox.databases.");
        }

        return $this->resolver->connection($database);
    }

    /**
     * Insert finalized rows on the connection that owns the business transaction.
     *
     * @param non-empty-list<array<string, mixed>> $rows
     */
    public function insert(ConnectionInterface $connection, array $rows): void
    {
        $this->insertDatabaseRows($connection, $this->table, $rows);
    }

    /**
     * Claim eligible messages without retaining a database resource in the result.
     *
     * @return list<OutboxMessage>
     */
    public function claim(string $database, int $limit): array
    {
        $connection = $this->getConnection($database);
        $now = $this->currentTime();
        $token = (string) Str::uuid();
        $expiresAt = $now + $this->retryAfter;
        $lock = $connection instanceof PdoConnection ? $connection->lockForPopping() : true;
        $limit = min($limit, $this->maxBindings($connection) - 3);

        if ($lock === 'FOR UPDATE SKIP LOCKED' && $connection->getDriverName() === 'pgsql') {
            $rows = $this->claimOnPostgres($connection, $limit, $now, $expiresAt, $token);
        } elseif ($lock === 'FOR UPDATE SKIP LOCKED') {
            $rows = $this->claimWithSkipLocked($connection, $limit, $now, $expiresAt, $token);
        } else {
            $rows = $this->claimWithConditionalUpdate($connection, $limit, $now, $expiresAt, $token);
        }

        usort($rows, static fn (object $first, object $second): int => [(int) $first->eligible_at, (int) $first->id] <=> [(int) $second->eligible_at, (int) $second->id]);

        return array_map(static fn (object $row): OutboxMessage => new OutboxMessage(
            (int) $row->id,
            $row->uuid,
            $row->lease_token,
            (int) $row->attempts,
            $row->connection,
            $row->queue,
            $row->payload,
            $row->options === null ? [] : json_decode($row->options, true, flags: JSON_THROW_ON_ERROR),
            $row->deliver_at === null ? null : (int) $row->deliver_at,
            (int) $row->eligible_at,
            (int) $row->created_at,
        ), $rows);
    }

    /**
     * Claim a PostgreSQL batch in one statement, retaining its original eligibility.
     *
     * @return list<object>
     */
    protected function claimOnPostgres(ConnectionInterface $connection, int $limit, int $now, int $expiresAt, string $token): array
    {
        $table = $connection->getQueryGrammar()->wrapTable($this->table);

        return $connection->selectFromWriteConnection(
            "WITH candidates AS (
                SELECT id, available_at FROM {$table}
                WHERE available_at <= ? ORDER BY available_at, id
                LIMIT ? FOR UPDATE SKIP LOCKED
            )
            UPDATE {$table} AS outgoing
            SET lease_token = ?, available_at = ?, attempts = outgoing.attempts + 1
            FROM candidates WHERE outgoing.id = candidates.id
            RETURNING outgoing.*, candidates.available_at AS eligible_at",
            [$now, $limit, $token, $expiresAt],
        );
    }

    /**
     * Claim rows on engines that support skipping another relay's locked rows.
     *
     * @return list<object>
     */
    protected function claimWithSkipLocked(ConnectionInterface $connection, int $limit, int $now, int $expiresAt, string $token): array
    {
        return $connection->transaction(function () use ($connection, $limit, $now, $expiresAt, $token): array {
            $rows = $this->query($connection)
                ->where('available_at', '<=', $now)
                ->orderBy('available_at')->orderBy('id')
                ->limit($limit)->lock('FOR UPDATE SKIP LOCKED')
                ->get()->all();

            if ($rows === []) {
                return [];
            }

            $this->query($connection)->whereIn('id', array_column($rows, 'id'))->increment('attempts', 1, [
                'lease_token' => $token,
                'available_at' => $expiresAt,
            ]);

            foreach ($rows as $row) {
                $row->eligible_at = $row->available_at;
                $row->available_at = $expiresAt;
                $row->lease_token = $token;
                ++$row->attempts;
            }

            return $rows;
        });
    }

    /**
     * Win eligible candidates through one atomic update on engines without skip-locked support.
     *
     * @return list<object>
     */
    protected function claimWithConditionalUpdate(ConnectionInterface $connection, int $limit, int $now, int $expiresAt, string $token): array
    {
        $candidates = $this->query($connection)
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')->orderBy('id')->limit($limit)
            ->pluck('available_at', 'id')->all();

        if ($candidates === []) {
            return [];
        }

        $ids = array_keys($candidates);
        $this->query($connection)
            ->whereIn('id', $ids)->where('available_at', '<=', $now)
            ->increment('attempts', 1, ['lease_token' => $token, 'available_at' => $expiresAt]);

        $rows = $this->query($connection)->whereIn('id', $ids)->where('lease_token', $token)->get()->all();

        foreach ($rows as $row) {
            $row->eligible_at = $candidates[$row->id];
        }

        return $rows;
    }

    /**
     * Settle a validated result without modifying rows claimed by a newer relay.
     *
     * @param non-empty-list<OutboxMessage> $messages
     * @return int the number of rows still owned and settled
     */
    public function settle(string $database, array $messages, OutboxBatchResult $result): int
    {
        $connection = $this->getConnection($database);
        $token = $messages[0]->leaseToken;
        $failed = array_values(array_diff(array_column($messages, 'id'), $result->accepted, $result->deferred));

        return $connection->transaction(function () use ($connection, $token, $result, $failed): int {
            $settled = 0;
            $maxBindings = $this->maxBindings($connection);

            foreach (array_chunk($result->accepted, $maxBindings - 1) as $ids) {
                $settled += $this->query($connection)->where('lease_token', $token)->whereIn('id', $ids)->delete();
            }

            foreach (array_chunk($result->deferred, $maxBindings - 2) as $ids) {
                $grammar = $connection->getQueryGrammar();
                $settled += $this->query($connection)->where('lease_token', $token)->whereIn('id', $ids)->update([
                    'lease_token' => null,
                    'available_at' => new Expression($grammar->wrap('deliver_at')),
                    'attempts' => new Expression($grammar->wrap('attempts') . ' - 1'),
                ]);
            }

            if ($failed !== []) {
                $settled += $this->releaseFailed($connection, $token, $failed, $result->failure->getMessage());
            }

            return $settled;
        });
    }

    /**
     * Back off failed rows with seven retry deadlines rather than one case per row.
     *
     * @param non-empty-list<int> $ids
     */
    protected function releaseFailed(ConnectionInterface $connection, string $token, array $ids, string $error): int
    {
        $now = $this->currentTime();
        $deadlines = array_map(static fn (int $delay): int => $now + random_int(max(1, (int) ceil($delay / 2)), $delay), self::RETRY_DELAYS);
        $error = mb_substr($error, 0, 4096);
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($this->table);
        $attempts = $grammar->wrap('attempts');
        $cases = [];

        foreach (array_keys($deadlines) as $index) {
            $cases[] = $index === count($deadlines) - 1 ? 'ELSE ?' : 'WHEN ' . $attempts . ' <= ' . ($index + 1) . ' THEN ?';
        }

        $case = 'CASE ' . implode(' ', $cases) . ' END';
        $affected = 0;

        foreach (array_chunk($ids, $this->maxBindings($connection) - count($deadlines) - 2) as $chunk) {
            $parameters = implode(', ', array_fill(0, count($chunk), '?'));
            $affected += $connection->update(
                "UPDATE {$table} SET available_at = {$case}, lease_token = NULL, last_error = ? WHERE lease_token = ? AND id IN ({$parameters})",
                [...$deadlines, $error, $token, ...$chunk],
            );
        }

        return $affected;
    }

    /**
     * Get a bounded set of pending rows for operator inspection.
     *
     * @return list<object>
     */
    public function pending(string $database, int $limit): array
    {
        return $this->query($this->getConnection($database))->orderBy('id')->limit($limit)->get()->all();
    }

    /**
     * Find a stored message by its job UUID on the writer.
     */
    public function find(string $database, string $uuid): ?object
    {
        return $this->query($this->getConnection($database))->where('uuid', $uuid)->first();
    }

    /**
     * Read backlog counts and the age of the first currently eligible row.
     *
     * @return array{total: int, eligible: int, delayed: int, leased: int, retrying: int, oldest_eligible_age: ?int}
     */
    public function statistics(string $database): array
    {
        $connection = $this->getConnection($database);
        $now = $this->currentTime();
        $counts = $this->query($connection)->selectRaw(
            'COUNT(*) AS total,
             SUM(CASE WHEN available_at <= ? THEN 1 ELSE 0 END) AS eligible,
             SUM(CASE WHEN lease_token IS NULL AND available_at > ? AND attempts = 0 THEN 1 ELSE 0 END) AS delayed,
             SUM(CASE WHEN lease_token IS NOT NULL AND available_at > ? THEN 1 ELSE 0 END) AS leased,
             SUM(CASE WHEN attempts > 0 AND (lease_token IS NULL OR available_at <= ?) THEN 1 ELSE 0 END) AS retrying,
             MIN(CASE WHEN available_at <= ? THEN available_at END) AS oldest_eligible',
            [$now, $now, $now, $now, $now],
        )->first();

        return [
            'total' => (int) $counts->total,
            'eligible' => (int) $counts->eligible,
            'delayed' => (int) $counts->delayed,
            'leased' => (int) $counts->leased,
            'retrying' => (int) $counts->retrying,
            'oldest_eligible_age' => $counts->oldest_eligible === null ? null : $now - (int) $counts->oldest_eligible,
        ];
    }

    /**
     * Retry an attempted message unless a live relay owns it.
     */
    public function retry(string $database, string $uuid): int
    {
        $now = $this->currentTime();

        return $this->retryable($this->getConnection($database), $now)->where('uuid', $uuid)->update([
            'available_at' => $now,
            'lease_token' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Retry the current backlog in bounded primary-key chunks, preserving attempt counts.
     */
    public function retryAll(string $database): int
    {
        $connection = $this->getConnection($database);
        $now = $this->currentTime();
        $ceiling = $this->retryable($connection, $now)->max('id');

        if ($ceiling === null) {
            return 0;
        }

        $lastId = 0;
        $affected = 0;
        $limit = min(1000, $this->maxBindings($connection) - 5);

        do {
            $ids = $this->retryable($connection, $now)->where('id', '>', $lastId)->where('id', '<=', $ceiling)
                ->orderBy('id')->limit($limit)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $affected += $this->retryable($connection, $now)->whereIn('id', $ids)->update([
                'available_at' => $now,
                'lease_token' => null,
                'last_error' => null,
            ]);
            $lastId = (int) end($ids);
        } while (count($ids) === $limit);

        return $affected;
    }

    /**
     * Delete an inspected row only while its lease remains inactive and unchanged.
     */
    public function forget(string $database, int $id, ?string $leaseToken): bool
    {
        return $this->withoutLiveLease($this->query($this->getConnection($database)), $this->currentTime())
            ->where('id', $id)->where('lease_token', $leaseToken)->delete() === 1;
    }

    /**
     * Select attempted rows that are safe for an operator to reschedule.
     */
    protected function retryable(ConnectionInterface $connection, int $now): Builder
    {
        return $this->withoutLiveLease($this->query($connection), $now)->where('attempts', '>', 0);
    }

    /**
     * Exclude rows currently owned by a publishing relay.
     */
    protected function withoutLiveLease(Builder $query, int $now): Builder
    {
        return $query->where(static function (Builder $query) use ($now): void {
            $query->whereNull('lease_token')->orWhere('available_at', '<=', $now);
        });
    }

    /**
     * Query the configured table on the writer, including for operator mutations.
     */
    protected function query(ConnectionInterface $connection): Builder
    {
        return $connection->table($this->table)->useWritePdo();
    }
}
