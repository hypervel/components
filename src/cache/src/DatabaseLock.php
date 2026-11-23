<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DetectsConcurrencyErrors;
use Hypervel\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Throwable;

class DatabaseLock extends Lock implements RefreshableLock
{
    use DetectsConcurrencyErrors;

    /**
     * The database connection resolver.
     */
    protected ConnectionResolverInterface $resolver;

    /**
     * The connection name.
     */
    protected ?string $connectionName;

    /**
     * The database table name.
     */
    protected string $table;

    /**
     * The prune probability odds.
     *
     * @var null|array{int, int}|array{}
     */
    protected ?array $lottery;

    /**
     * The default number of seconds that a lock should be held.
     */
    protected int $defaultTimeoutInSeconds;

    /**
     * Create a new lock instance.
     *
     * @param null|array{int, int}|array{} $lottery the prune probability odds, or null to disable automatic pruning
     */
    public function __construct(
        ConnectionResolverInterface $resolver,
        ?string $connectionName,
        string $name,
        string $table,
        int $seconds,
        ?string $owner = null,
        ?array $lottery = [2, 100],
        int $defaultTimeoutInSeconds = 86400
    ) {
        parent::__construct($name, $seconds, $owner);

        $this->resolver = $resolver;
        $this->connectionName = $connectionName;
        $this->table = $table;
        $this->lottery = $lottery;
        $this->defaultTimeoutInSeconds = $defaultTimeoutInSeconds;
    }

    /**
     * Get a fresh connection from the pool.
     */
    protected function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connectionName);
    }

    /**
     * Attempt to acquire the lock.
     *
     * @throws Throwable
     */
    public function acquire(): bool
    {
        $connection = $this->connection();
        $driver = $connection->getDriverName();

        // Every SQLite write takes the database-wide write lock, even an insert that ignores the existing key or an
        // update that matches nothing, so a live lock held by another owner is refused with a read instead.
        if ($driver === 'sqlite') {
            $owner = $connection->table($this->table)
                ->useWritePdo()
                ->where('key', $this->name)
                ->where('expiration', '>', $this->currentTime())
                ->value('owner');

            if ($owner !== null && $owner !== $this->owner) {
                return false;
            }
        }

        $record = [
            'key' => $this->name,
            'owner' => $this->owner,
            'expiration' => $this->expiresAt(),
        ];

        try {
            // MySQL's INSERT IGNORE truncates overlong names, leaving locks that cannot be released by their full name.
            $acquired = in_array($driver, ['mysql', 'mariadb'], true)
                ? $connection->table($this->table)->insert($record)
                : $connection->table($this->table)->insertOrIgnore($record) > 0;
        } catch (UniqueConstraintViolationException) {
            // Only an existing row means the lock may still be taken over; other failures, such as a busy database, propagate.
            $acquired = false;
        }

        if (! $acquired) {
            $updated = $connection->table($this->table)
                ->where('key', $this->name)
                ->where(function ($query) {
                    return $query->where('owner', $this->owner)->orWhere('expiration', '<=', $this->currentTime());
                })->update([
                    'owner' => $this->owner,
                    'expiration' => $this->expiresAt(),
                ]);

            $acquired = $updated >= 1 || $this->ownsLockAfterUnchangedUpdate($connection);
        }

        if (count($this->lottery ?? []) === 2 && random_int(1, $this->lottery[1]) <= $this->lottery[0]) {
            $this->pruneExpiredLocks();
        }

        return $acquired;
    }

    /**
     * Release the lock.
     *
     * @throws Throwable
     */
    public function release(): bool
    {
        $connection = $this->connection();

        try {
            return $connection->table($this->table)
                ->where('key', $this->name)
                ->where('owner', $this->owner)
                ->delete() > 0;
        } catch (Throwable $e) {
            // Inside a transaction the error may have aborted the transaction, so the caller must see it.
            if ($this->causedByConcurrencyError($e) && $connection->transactionLevel() === 0) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Releases this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
        $this->connection()->table($this->table)
            ->where('key', $this->name)
            ->delete();
    }

    /**
     * Delete locks that are past expiration.
     *
     * @throws Throwable
     */
    public function pruneExpiredLocks(): void
    {
        $connection = $this->connection();

        try {
            $connection->table($this->table)
                ->where('expiration', '<=', $this->currentTime())
                ->delete();
        } catch (Throwable $e) {
            // Inside a transaction the error may have aborted the transaction, so the caller must see it.
            if (! $this->causedByConcurrencyError($e) || $connection->transactionLevel() > 0) {
                throw $e;
            }
        }
    }

    /**
     * Return the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): ?string
    {
        return $this->connection()->table($this->table)
            ->useWritePdo()
            ->where('key', $this->name)
            ->where('expiration', '>', $this->currentTime())
            ->first()
            ?->owner;
    }

    /**
     * Determine whether this owner holds the live lock after an update that changed no row.
     *
     * MySQL and MariaDB count only changed rows, so an owner writing the
     * expiration its lock already has, such as within the second it was
     * acquired, updates none. Other drivers count matched rows, so their
     * zero means the lock is not this owner's.
     */
    protected function ownsLockAfterUnchangedUpdate(ConnectionInterface $connection): bool
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        // A locking read on the writer cannot confirm ownership from an older snapshot;
        // MariaDB snapshot isolation may instead report a concurrency error, which must propagate.
        return $connection->table($this->table)
            ->sharedLock()
            ->where('key', $this->name)
            ->where('owner', $this->owner)
            ->where('expiration', '>', $this->currentTime())
            ->exists();
    }

    /**
     * Get the UNIX timestamp indicating when the lock should expire.
     */
    protected function expiresAt(?int $seconds = null): int
    {
        $seconds ??= $this->seconds;

        $lockTimeout = $seconds > 0 ? $seconds : $this->defaultTimeoutInSeconds;

        return $this->availableAt($lockTimeout);
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * Database locks are never truly permanent: non-positive acquisition
     * durations use the default timeout so a crashed process cannot leave an
     * unreclaimable row. refresh(null) reapplies that acquisition rule.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds !== null && $seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }

        $connection = $this->connection();

        $updated = $connection->table($this->table)
            ->where('key', $this->name)
            ->where('owner', $this->owner)
            ->where('expiration', '>', $this->currentTime())
            ->update([
                'expiration' => $this->expiresAt($seconds),
            ]);

        return $updated >= 1 || $this->ownsLockAfterUnchangedUpdate($connection);
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        $now = $this->currentTime();

        $lock = $this->connection()->table($this->table)
            ->useWritePdo()
            ->where('key', $this->name)
            ->where('expiration', '>', $now)
            ->first();

        if ($lock === null) {
            return null;
        }

        return (float) ($lock->expiration - $now);
    }

    /**
     * Get the name of the database connection being used to manage the lock.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection()->getName();
    }
}
