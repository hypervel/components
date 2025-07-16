<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hyperf\DbConnection\Connection;
use Hyperf\DbConnection\Exception\QueryException;

class DatabaseLock extends Lock
{
    /**
     * The database connection instance.
     */
    protected Connection $connection;

    /**
     * The database table name.
     */
    protected string $table;

    /**
     * The prune probability odds.
     */
    protected array $lottery;

    /**
     * The default number of seconds that a lock should be held.
     */
    protected int $defaultTimeoutInSeconds;

    /**
     * Create a new lock instance.
     */
    public function __construct(
        Connection $connection,
        string $table,
        string $name,
        int $seconds,
        ?string $owner = null,
        array $lottery = [2, 100],
        int $defaultTimeoutInSeconds = 86400
    ) {
        parent::__construct($name, $seconds, $owner);

        $this->connection = $connection;
        $this->table = $table;
        $this->lottery = $lottery;
        $this->defaultTimeoutInSeconds = $defaultTimeoutInSeconds;
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        try {
            $this->connection->table($this->table)->insert([
                'key' => $this->name,
                'owner' => $this->owner,
                'expiration' => $this->expiresAt(),
            ]);

            $acquired = true;
        } catch (QueryException) {
            $updated = $this->connection->table($this->table)
                ->where('key', $this->name)
                ->where(function ($query) {
                    return $query->where('owner', $this->owner)->orWhere('expiration', '<=', $this->currentTime());
                })->update([
                    'owner' => $this->owner,
                    'expiration' => $this->expiresAt(),
                ]);

            $acquired = $updated >= 1;
        }

        if (random_int(1, $this->lottery[1]) <= $this->lottery[0]) {
            $this->connection->table($this->table)->where('expiration', '<=', $this->currentTime())->delete();
        }

        return $acquired;
    }

    /**
     * Release the lock.
     */
    public function release(): bool
    {
        if ($this->isOwnedByCurrentProcess()) {
            $this->connection->table($this->table)
                ->where('key', $this->name)
                ->where('owner', $this->owner)
                ->delete();

            return true;
        }

        return false;
    }

    /**
     * Releases this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
        $this->connection->table($this->table)
            ->where('key', $this->name)
            ->delete();
    }

    /**
     * Returns the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): string
    {
        return optional($this->connection->table($this->table)->where('key', $this->name)->first())->owner ?? '';
    }

    /**
     * Get the UNIX timestamp indicating when the lock should expire.
     */
    protected function expiresAt(): int
    {
        $lockTimeout = $this->seconds > 0 ? $this->seconds : $this->defaultTimeoutInSeconds;

        return $this->currentTime() + $lockTimeout;
    }
}