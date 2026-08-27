<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Support\CarbonImmutable;

class WorkerArrayStore extends AbstractArrayStore
{
    /**
     * The maximum records reclaimed from each map per write.
     */
    private const int RECLAMATION_LIMIT = 8;

    /**
     * The array of stored values.
     *
     * @var array<string, array{value: mixed, expiresAt: float}>
     */
    protected array $storage = [];

    /**
     * The array of locks.
     *
     * @var array<string, array{owner: ?string, expiresAt: ?CarbonImmutable}>
     */
    protected array $locks = [];

    /**
     * Get the cached item for the given key.
     *
     * @return null|array{value: mixed, expiresAt: float}
     */
    protected function getCacheItem(string $key): ?array
    {
        return $this->storage[$key] ?? null;
    }

    /**
     * Store the cached item for the given key.
     *
     * @param array{value: mixed, expiresAt: float} $item
     */
    protected function putCacheItem(string $key, array $item): void
    {
        $this->storage[$key] = $item;
    }

    /**
     * Remove the cached item for the given key.
     */
    protected function forgetCacheItem(string $key): bool
    {
        if (! array_key_exists($key, $this->storage)) {
            return false;
        }

        unset($this->storage[$key]);

        return true;
    }

    /**
     * Remove all cached items.
     */
    protected function clearCacheItems(): void
    {
        $this->storage = [];
    }

    /**
     * Get all cached items.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    protected function getCacheItems(): array
    {
        return $this->storage;
    }

    /**
     * Store the lock record for the given name.
     *
     * @param array{owner: ?string, expiresAt: ?CarbonImmutable} $record
     */
    public function putLockRecord(string $name, array $record): void
    {
        $this->reclaimExpiredRecords();
        $this->locks[$name] = $record;
    }

    /**
     * Remove the lock record for the given name.
     */
    public function forgetLockRecord(string $name): void
    {
        unset($this->locks[$name]);
    }

    /**
     * Remove all lock records.
     */
    public function clearLockRecords(): void
    {
        $this->locks = [];
    }

    /**
     * Get all lock records.
     *
     * @return array<string, array{owner: ?string, expiresAt: ?CarbonImmutable}>
     */
    protected function getLockRecords(): array
    {
        return $this->locks;
    }

    /**
     * Reclaim a fixed number of expired value and lock records.
     */
    protected function reclaimExpiredRecords(?float $currentTimestamp = null): void
    {
        if ($this->storage === [] && $this->locks === []) {
            return;
        }

        $currentTime = $this->locks === [] ? null : CarbonImmutable::now();

        if ($this->storage !== []) {
            $currentTimestamp ??= $currentTime !== null
                ? $currentTime->getPreciseTimestamp(3) / 1000
                : $this->currentPreciseTimestamp();

            $this->reclaimExpiredValues($currentTimestamp);
        }

        if ($currentTime !== null) {
            $this->reclaimExpiredLocks($currentTime);
        }
    }

    /**
     * Reclaim expired values within the per-write limit.
     */
    private function reclaimExpiredValues(float $currentTimestamp): void
    {
        $limit = min(self::RECLAMATION_LIMIT, count($this->storage));

        for ($index = 0; $index < $limit && $this->storage !== []; ++$index) {
            if (key($this->storage) === null) {
                reset($this->storage);
            }

            $key = key($this->storage);
            $item = $this->storage[$key];
            next($this->storage);

            if ($item['expiresAt'] !== 0.0 && $this->isCacheItemExpired($item, $currentTimestamp)) {
                unset($this->storage[$key]);
            }
        }
    }

    /**
     * Reclaim expired locks within the per-write limit.
     */
    private function reclaimExpiredLocks(CarbonImmutable $currentTime): void
    {
        $limit = min(self::RECLAMATION_LIMIT, count($this->locks));

        for ($index = 0; $index < $limit && $this->locks !== []; ++$index) {
            if (key($this->locks) === null) {
                reset($this->locks);
            }

            $key = key($this->locks);
            $record = $this->locks[$key];
            next($this->locks);

            if ($record['expiresAt'] !== null
                && $this->isLockRecordExpired($record['expiresAt'], $currentTime)) {
                unset($this->locks[$key]);
            }
        }
    }
}
