<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Cache\Repository as Cache;
use Throwable;
use WeakMap;

/**
 * Track dispatch lock ownership until a queue accepts the job.
 *
 * @internal
 *
 * @phpstan-type UniqueMetadata array{
 *     laravel_unique_job_cache_store: null|string,
 *     laravel_unique_job_key: string,
 *     laravel_unique_job_lock_owner: string
 * }
 * @phpstan-type LockProvenance array{cache: Cache, key: string, owner: string}
 * @phpstan-type LockRecord array{
 *     metadata: null|UniqueMetadata,
 *     unique: null|LockProvenance,
 *     debounce: null|LockProvenance,
 *     delegated: bool
 * }
 * @phpstan-type LockSnapshot array{
 *     unique: null|LockProvenance,
 *     debounce: null|LockProvenance
 * }
 */
class DispatchLockContext
{
    /**
     * The lock records owned by live dispatch operations.
     *
     * @var null|WeakMap<object, LockRecord>
     */
    protected static ?WeakMap $records = null;

    /**
     * Register an acquired unique lock.
     */
    public static function registerUnique(
        object $job,
        Cache $cache,
        ?string $cacheStore,
        string $key,
        string $owner,
    ): void {
        /** @var WeakMap<object, LockRecord> $records */
        // @phpstan-ignore assign.propertyType (PHPStan rejects an empty WeakMap for this invariant closed-shape value.)
        $records = static::$records ??= new WeakMap;
        $record = $records[$job] ?? static::newRecord();

        // Uses Laravel's keys for cross-framework queue interoperability.
        $record['metadata'] = [
            'laravel_unique_job_cache_store' => $cacheStore,
            'laravel_unique_job_key' => $key,
            'laravel_unique_job_lock_owner' => $owner,
        ];
        $record['unique'] = compact('cache', 'key', 'owner');
        $records[$job] = $record;
    }

    /**
     * Register an acquired debounce lock.
     */
    public static function registerDebounce(
        object $job,
        Cache $cache,
        string $key,
        string $owner,
    ): void {
        /** @var WeakMap<object, LockRecord> $records */
        // @phpstan-ignore assign.propertyType (PHPStan rejects an empty WeakMap for this invariant closed-shape value.)
        $records = static::$records ??= new WeakMap;
        $record = $records[$job] ?? static::newRecord();
        $record['debounce'] = compact('cache', 'key', 'owner');
        $records[$job] = $record;
    }

    /**
     * Peek at hidden unique-job payload metadata.
     *
     * @return null|UniqueMetadata
     */
    public static function peekPayloadMetadata(object $job): ?array
    {
        return static::record($job)['metadata'] ?? null;
    }

    /**
     * Determine whether a dispatch owns any locks.
     */
    public static function has(object $job): bool
    {
        return static::record($job) !== null;
    }

    /**
     * Delegate a dispatch's lock ownership to a registered callback.
     */
    public static function delegate(object $job): bool
    {
        return static::setDelegated($job, true);
    }

    /**
     * Claim lock ownership in a delegated callback.
     */
    public static function claim(object $job): bool
    {
        return static::setDelegated($job, false);
    }

    /**
     * Mark a dispatch as accepted by a queue.
     */
    public static function accept(object $job): void
    {
        $records = static::$records;

        if ($records !== null) {
            unset($records[$job]);
        }
    }

    /**
     * Release locks still owned by a dispatch.
     */
    public static function release(object $job): void
    {
        $records = static::$records;

        if ($records === null || ! isset($records[$job])) {
            return;
        }

        $record = $records[$job];

        if ($record['delegated']) {
            return;
        }

        unset($records[$job]);

        static::releaseSnapshot([
            'unique' => $record['unique'],
            'debounce' => $record['debounce'],
        ]);
    }

    /**
     * Take a release snapshot for work accepted by an in-memory timer.
     *
     * @return null|LockSnapshot
     */
    public static function snapshot(object $job): ?array
    {
        $record = static::record($job);

        if ($record === null || $record['delegated']) {
            return null;
        }

        return [
            'unique' => $record['unique'],
            'debounce' => $record['debounce'],
        ];
    }

    /**
     * Release locks from an in-memory timer snapshot.
     *
     * @param LockSnapshot $snapshot
     */
    public static function releaseSnapshot(array $snapshot): void
    {
        $exception = null;

        try {
            if ($snapshot['unique'] !== null) {
                UniqueLock::releaseOwned(
                    $snapshot['unique']['cache'],
                    $snapshot['unique']['key'],
                    $snapshot['unique']['owner'],
                );
            }
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            if ($snapshot['debounce'] !== null) {
                DebounceLock::releaseOwned(
                    $snapshot['debounce']['cache'],
                    $snapshot['debounce']['key'],
                    $snapshot['debounce']['owner'],
                );
            }
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Get a dispatch's current lock record.
     *
     * @return null|LockRecord
     */
    protected static function record(object $job): ?array
    {
        if (static::$records === null || ! isset(static::$records[$job])) {
            return null;
        }

        return static::$records[$job];
    }

    /**
     * Set whether a dispatch's lock ownership has been delegated.
     */
    protected static function setDelegated(object $job, bool $delegated): bool
    {
        $records = static::$records;

        if ($records === null || ! isset($records[$job])) {
            return false;
        }

        $record = $records[$job];
        $record['delegated'] = $delegated;
        $records[$job] = $record;

        return true;
    }

    /**
     * Create an empty dispatch lock record.
     *
     * @return LockRecord
     */
    protected static function newRecord(): array
    {
        return [
            'metadata' => null,
            'unique' => null,
            'debounce' => null,
            'delegated' => false,
        ];
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$records = null;
    }
}
