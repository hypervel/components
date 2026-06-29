<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Support\Carbon;
use InvalidArgumentException;

class ArrayLock extends Lock implements RefreshableLock
{
    /**
     * The parent array cache store.
     */
    protected AbstractArrayStore $store;

    /**
     * Create a new lock instance.
     */
    public function __construct(AbstractArrayStore $store, string $name, int $seconds, ?string $owner = null)
    {
        parent::__construct($name, $seconds, $owner);

        $this->store = $store;
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        $record = $this->store->getLockRecord($this->name);
        $expiration = $record['expiresAt'] ?? Carbon::now()->addSecond();

        if ($record !== null && $expiration->isFuture()) {
            return false;
        }

        // WorkerArrayStore shares this check/write path across coroutines; keep it non-yielding.
        $this->store->putLockRecord($this->name, [
            'owner' => $this->owner,
            'expiresAt' => $this->seconds === 0 ? null : Carbon::now()->addSeconds($this->seconds),
        ]);

        return true;
    }

    /**
     * Release the lock.
     */
    public function release(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        if (! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        $this->forceRelease();

        return true;
    }

    /**
     * Release this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
        $this->store->forgetLockRecord($this->name);
    }

    /**
     * Determine if the current lock exists.
     */
    protected function exists(): bool
    {
        return $this->store->getLockRecord($this->name) !== null;
    }

    /**
     * Return the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): ?string
    {
        return $this->store->getLockRecord($this->name)['owner'] ?? null;
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        // Permanent lock with no explicit TTL requested - nothing to refresh
        if ($seconds === null && $this->seconds <= 0) {
            return true;
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Refresh requires a positive TTL. For a permanent lock, acquire it with seconds=0.'
            );
        }

        $record = $this->store->getLockRecord($this->name);

        if ($record === null) {
            return false;
        }

        if (! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        $record['expiresAt'] = Carbon::now()->addSeconds($seconds);
        $this->store->putLockRecord($this->name, $record);

        return true;
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        $record = $this->store->getLockRecord($this->name);

        if ($record === null) {
            return null;
        }

        $expiresAt = $record['expiresAt'];

        if ($expiresAt === null) {
            return null;
        }

        if ($expiresAt->isPast()) {
            return null;
        }

        return (float) Carbon::now()->diffInSeconds($expiresAt);
    }
}
