<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\RateLimiter\Concerns\CalculatesRateLimits;
use Hypervel\RateLimiter\Contracts\PrunableStore;
use Hypervel\RateLimiter\Contracts\Store;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

class DatabaseStore implements PrunableStore, Store
{
    use CalculatesRateLimits;

    protected const int MAX_PRUNE_CHUNK_SIZE = 10_000;

    /**
     * Create a new database rate limiter store.
     */
    public function __construct(
        protected ConnectionResolverInterface $connections,
        protected ?string $connectionName,
        protected string $table,
    ) {
    }

    /**
     * Atomically consume capacity from an admission policy.
     */
    public function consume(string $key, AdmissionPolicy $policy): LimitResult
    {
        $connection = $this->connections->connection($this->connectionName);
        $this->ensureCanMutate($connection);

        return $this->mutateState($connection, $key, function (
            ConnectionInterface $connection,
            array $state,
        ) use ($key, $policy): LimitResult {
            [$value, $secondaryValue, $expiresAt] = $state;
            $result = $this->calculateConsume(
                $policy,
                $this->currentDatabaseTimeInMicroseconds($connection),
                $value,
                $secondaryValue,
                $expiresAt,
            );

            if ($result->allowed()) {
                $this->writeState($connection, $key, $value, $secondaryValue, $expiresAt);
            }

            return $result;
        });
    }

    /**
     * Atomically extend a cooldown block.
     */
    public function block(string $key, int $durationMicroseconds): CooldownResult
    {
        $connection = $this->connections->connection($this->connectionName);
        $this->ensureCanMutate($connection);

        return $this->mutateState($connection, $key, function (
            ConnectionInterface $connection,
            array $state,
        ) use ($key, $durationMicroseconds): CooldownResult {
            [$value, $secondaryValue, $expiresAt] = $state;
            $result = $this->calculateCooldownBlock(
                $durationMicroseconds,
                $this->currentDatabaseTimeInMicroseconds($connection),
                $value,
                $secondaryValue,
                $expiresAt,
            );

            $this->writeState($connection, $key, $value, $secondaryValue, $expiresAt);

            return $result;
        });
    }

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : ($policy is Cooldown ? CooldownResult : LimitResult))
     */
    public function inspect(
        string $key,
        AdmissionPolicy|Backoff|Cooldown $policy,
    ): LimitResult|BackoffResult|CooldownResult {
        $connection = $this->connections->connection($this->connectionName);
        $row = $connection->table($this->table)
            ->useWritePdo()
            ->where('key', $key)
            ->first();
        [$value, $secondaryValue, $expiresAt] = $row === null
            ? [0, 0, 0]
            : $this->stateFromRow($row);

        return $this->calculateInspection(
            $policy,
            $this->currentDatabaseTimeInMicroseconds($connection),
            $value,
            $secondaryValue,
            $expiresAt,
        );
    }

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(string $key, Backoff $backoff): BackoffResult
    {
        $connection = $this->connections->connection($this->connectionName);
        $this->ensureCanMutate($connection);

        return $this->mutateState($connection, $key, function (
            ConnectionInterface $connection,
            array $state,
        ) use ($key, $backoff): BackoffResult {
            [$value, $secondaryValue, $expiresAt] = $state;
            $result = $this->calculateFailure(
                $backoff,
                $this->currentDatabaseTimeInMicroseconds($connection),
                $value,
                $secondaryValue,
                $expiresAt,
            );

            $this->writeState($connection, $key, $value, $secondaryValue, $expiresAt);

            return $result;
        });
    }

    /**
     * Clear the state for a physical limiter key.
     */
    public function clear(string $key): bool
    {
        $connection = $this->connections->connection($this->connectionName);
        $this->ensureCanMutate($connection);

        return $connection
            ->table($this->table)
            ->where('key', $key)
            ->delete() > 0;
    }

    /**
     * Prune expired state in bounded batches.
     */
    public function pruneExpired(int $chunkSize = 1000): int
    {
        if ($chunkSize < 1 || $chunkSize > self::MAX_PRUNE_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'The rate limiter prune chunk size must be between 1 and %d.',
                self::MAX_PRUNE_CHUNK_SIZE,
            ));
        }

        $connection = $this->connections->connection($this->connectionName);
        $this->ensureCanMutate($connection);
        $cutoff = $this->currentDatabaseTimeInMicroseconds($connection);
        $pruned = 0;

        do {
            $keys = [];

            foreach ($connection->table($this->table)
                ->useWritePdo()
                ->where('expires_at', '<=', $cutoff)
                ->limit($chunkSize)
                ->pluck('key') as $key) {
                if (! is_string($key)) {
                    throw new UnexpectedValueException('The stored database rate limiter key is invalid.');
                }

                $keys[] = $key;
            }

            if ($keys === []) {
                break;
            }

            $pruned += $connection->table($this->table)
                ->whereIn('key', $keys)
                ->where('expires_at', '<=', $cutoff)
                ->delete();
        } while (count($keys) === $chunkSize);

        return $pruned;
    }

    /**
     * Mutate locked state, initializing a missing non-SQLite row in a new transaction.
     *
     * @template TResult of BackoffResult|CooldownResult|LimitResult
     * @param Closure(ConnectionInterface, array{int, int, int}): TResult $callback
     * @return TResult
     */
    protected function mutateState(
        ConnectionInterface $connection,
        string $key,
        Closure $callback,
    ): BackoffResult|CooldownResult|LimitResult {
        $result = $connection->transaction(function (ConnectionInterface $connection) use ($key, $callback): BackoffResult|CooldownResult|LimitResult|null {
            $state = $this->stateForUpdate($connection, $key);

            return $state === null ? null : $callback($connection, $state);
        }, attempts: 3);

        if ($result !== null) {
            return $result;
        }

        // End the missing-row transaction so InnoDB releases its gap lock before
        // initialization. PostgreSQL shares this path so every non-SQLite driver
        // uses the same initialization order.
        return $connection->transaction(function (ConnectionInterface $connection) use ($key, $callback): BackoffResult|CooldownResult|LimitResult {
            return $callback($connection, $this->initializeStateRowForUpdate($connection, $key));
        }, attempts: 3);
    }

    /**
     * Return an empty state row for a physical limiter key.
     *
     * @return array{key: string, value: int, secondary_value: int, expires_at: int}
     */
    protected function emptyStateRow(string $key): array
    {
        return [
            'key' => $key,
            'value' => 0,
            'secondary_value' => 0,
            'expires_at' => 0,
        ];
    }

    /**
     * Insert an empty state row if the key does not exist.
     */
    protected function insertStateRow(ConnectionInterface $connection, string $key): void
    {
        $connection->table($this->table)->insertOrIgnore($this->emptyStateRow($key));
    }

    /**
     * Initialize and lock state for a missing non-SQLite physical limiter key.
     *
     * @return array{int, int, int}
     */
    protected function initializeStateRowForUpdate(ConnectionInterface $connection, string $key): array
    {
        $connection->table($this->table)->upsert(
            $this->emptyStateRow($key),
            'key',
            ['key' => $key],
        );

        $state = $this->findStateForUpdate($connection, $key);

        // The upsert created or exclusively locked the row, so a concurrent clear or
        // prune cannot remove it before this transaction's locking read.
        if ($state === null) {
            throw new UnexpectedValueException('The database rate limiter state row could not be read after insertion.');
        }

        return $state;
    }

    /**
     * Lock and read state, initializing SQLite state under its writer lock.
     *
     * @return null|array{int, int, int}
     */
    protected function stateForUpdate(ConnectionInterface $connection, string $key): ?array
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return $this->findStateForUpdate($connection, $key);
        }

        // SQLite ignores FOR UPDATE, so writing first acquires its database writer lock.
        $this->insertStateRow($connection, $key);
        $state = $this->findStateForUpdate($connection, $key);

        if ($state === null) {
            throw new UnexpectedValueException('The database rate limiter state row could not be read after insertion.');
        }

        return $state;
    }

    /**
     * Find and lock numeric state for a physical limiter key.
     *
     * @return null|array{int, int, int}
     */
    protected function findStateForUpdate(ConnectionInterface $connection, string $key): ?array
    {
        $row = $connection->table($this->table)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->stateFromRow($row);
    }

    /**
     * Get and validate numeric state from a database row.
     *
     * @return array{int, int, int}
     */
    protected function stateFromRow(object $row): array
    {
        return [
            $this->integerValue($row->value ?? null, 'value'),
            $this->integerValue($row->secondary_value ?? null, 'secondary_value'),
            $this->integerValue($row->expires_at ?? null, 'expires_at'),
        ];
    }

    /**
     * Write numeric state for a physical limiter key.
     */
    protected function writeState(
        ConnectionInterface $connection,
        string $key,
        int $value,
        int $secondaryValue,
        int $expiresAt,
    ): void {
        $connection->table($this->table)
            ->where('key', $key)
            ->update([
                'value' => $value,
                'secondary_value' => $secondaryValue,
                'expires_at' => $expiresAt,
            ]);
    }

    /**
     * Ensure the connection can safely mutate limiter state.
     */
    protected function ensureCanMutate(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() > 0) {
            throw new LogicException(
                'Database rate limiter mutations cannot run inside an active transaction on the selected connection. '
                . 'Configure a dedicated rate limiter connection or call the limiter outside the transaction.'
            );
        }

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $isolationLevel = $connection->getConfig('isolation_level');

        // At stronger isolation levels, PostgreSQL can abort a locking read after a
        // concurrent update commits, exhausting the limiter's transaction attempts.
        if ($isolationLevel !== null
            && (! is_string($isolationLevel) || strcasecmp($isolationLevel, 'read committed') !== 0)) {
            $connectionName = $connection->getName() ?? $this->connectionName ?? 'default';

            throw new InvalidArgumentException(
                "PostgreSQL database rate limiter connection [{$connectionName}] must use READ COMMITTED transaction isolation."
            );
        }
    }

    /**
     * Get the authoritative current time in epoch microseconds.
     */
    private function currentDatabaseTimeInMicroseconds(ConnectionInterface $connection): int
    {
        $value = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->scalar(
                'SELECT FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) * 1000000)',
                useReadPdo: false,
            ),
            'pgsql' => $connection->scalar(
                'SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint',
                useReadPdo: false,
            ),
            'sqlite' => $this->currentTimeInMicroseconds(),
            default => throw new InvalidArgumentException(sprintf(
                'Database driver [%s] is not supported by the rate limiter.',
                $connection->getDriverName(),
            )),
        };

        $timestamp = $this->integerValue($value, 'current_time');

        if ($timestamp <= 0) {
            throw new UnexpectedValueException('The database rate limiter clock returned an invalid timestamp.');
        }

        return $timestamp;
    }

    /**
     * Normalize an exact non-negative integer returned by a database driver.
     */
    private function integerValue(mixed $value, string $column): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = (int) $value;

            if ((string) $integer !== $value) {
                throw new UnexpectedValueException(
                    "The stored database rate limiter [{$column}] value is invalid."
                );
            }

            $value = $integer;
        }

        if (! is_int($value) || $value < 0 || $value > AdmissionPolicy::MAX_INTEGER) {
            throw new UnexpectedValueException(
                "The stored database rate limiter [{$column}] value is invalid."
            );
        }

        return $value;
    }
}
