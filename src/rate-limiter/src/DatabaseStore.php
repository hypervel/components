<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

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
        $this->ensureOutsideTransaction($connection);

        return $connection->transaction(function (ConnectionInterface $connection) use ($key, $policy): LimitResult {
            [$value, $availableAt, $expiresAt] = $this->stateForUpdate($connection, $key);
            $result = $this->calculateConsume(
                $policy,
                $this->currentDatabaseTimeInMicroseconds($connection),
                $value,
                $availableAt,
                $expiresAt,
            );

            if ($result->allowed()) {
                $this->writeState($connection, $key, $value, $availableAt, $expiresAt);
            }

            return $result;
        }, attempts: 3);
    }

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : LimitResult)
     */
    public function inspect(string $key, AdmissionPolicy|Backoff $policy): LimitResult|BackoffResult
    {
        $connection = $this->connections->connection($this->connectionName);
        $row = $connection->table($this->table)
            ->useWritePdo()
            ->where('key', $key)
            ->first();
        [$value, $availableAt, $expiresAt] = $row === null
            ? [0, 0, 0]
            : $this->stateFromRow($row);

        return $this->calculateInspection(
            $policy,
            $this->currentDatabaseTimeInMicroseconds($connection),
            $value,
            $availableAt,
            $expiresAt,
        );
    }

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(string $key, Backoff $backoff): BackoffResult
    {
        $connection = $this->connections->connection($this->connectionName);
        $this->ensureOutsideTransaction($connection);

        return $connection->transaction(function (ConnectionInterface $connection) use ($key, $backoff): BackoffResult {
            [$value, $availableAt, $expiresAt] = $this->stateForUpdate($connection, $key);
            $result = $this->calculateFailure(
                $backoff,
                $this->currentDatabaseTimeInMicroseconds($connection),
                $value,
                $availableAt,
                $expiresAt,
            );

            $this->writeState($connection, $key, $value, $availableAt, $expiresAt);

            return $result;
        }, attempts: 3);
    }

    /**
     * Clear the state for a physical limiter key.
     */
    public function clear(string $key): bool
    {
        $connection = $this->connections->connection($this->connectionName);
        $this->ensureOutsideTransaction($connection);

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
        $this->ensureOutsideTransaction($connection);
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
     * Insert an empty state row if the key does not exist.
     */
    protected function insertStateRow(ConnectionInterface $connection, string $key): void
    {
        $connection->table($this->table)->insertOrIgnore([
            'key' => $key,
            'value' => 0,
            'available_at' => 0,
            'expires_at' => 0,
        ]);
    }

    /**
     * Lock and read state, inserting an empty row when necessary.
     *
     * @return array{int, int, int}
     */
    protected function stateForUpdate(ConnectionInterface $connection, string $key): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE, so writing first acquires its database writer lock.
            $this->insertStateRow($connection, $key);
        } else {
            // Lock established rows first. Insert-first makes concurrent InnoDB transactions
            // repeatedly deadlock while upgrading duplicate-key shared locks.
            $state = $this->findStateForUpdate($connection, $key);

            if ($state !== null) {
                return $state;
            }

            $this->insertStateRow($connection, $key);
        }

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
            $this->integerValue($row->available_at ?? null, 'available_at'),
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
        int $availableAt,
        int $expiresAt,
    ): void {
        $connection->table($this->table)
            ->where('key', $key)
            ->update([
                'value' => $value,
                'available_at' => $availableAt,
                'expires_at' => $expiresAt,
            ]);
    }

    /**
     * Ensure limiter mutations own their database transaction.
     */
    protected function ensureOutsideTransaction(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() > 0) {
            throw new LogicException(
                'Database rate limiter mutations cannot run inside an active transaction on the selected connection. '
                . 'Configure a dedicated rate limiter connection or call the limiter outside the transaction.'
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
