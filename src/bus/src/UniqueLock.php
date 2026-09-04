<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\UniqueFor;

class UniqueLock
{
    use ReadsQueueAttributes;

    /**
     * Create a new unique lock manager instance.
     */
    public function __construct(
        protected Cache $cache,
    ) {
    }

    /**
     * Attempt to acquire a lock for the given job.
     */
    public function acquire(mixed $job): bool
    {
        [$uniqueFor, $cache, $key, $supportsOwnership] = $this->resolveLockValues($job);

        return $this->acquireResolvedLock(
            $job,
            $cache,
            $key,
            $uniqueFor,
            $supportsOwnership,
        ) !== false;
    }

    /**
     * Attempt to acquire and register a lock owned by a dispatch operation.
     */
    public function acquireForDispatch(object $job): bool
    {
        [$uniqueFor, $cache, $key, $supportsOwnership] = $this->resolveLockValues($job);
        $cacheStore = $cache instanceof Repository ? $cache->getName() : null;

        $owner = $this->acquireResolvedLock(
            $job,
            $cache,
            $key,
            $uniqueFor,
            $supportsOwnership,
            captureOwner: true,
        );

        if ($owner === false) {
            return false;
        }

        DispatchLockContext::registerUnique($job, $cache, $cacheStore, $key, $owner);

        return true;
    }

    /**
     * Resolve the values needed to acquire a unique lock.
     *
     * @return array{int, Cache, string, bool}
     */
    protected function resolveLockValues(mixed $job): array
    {
        $uniqueFor = method_exists($job, 'uniqueFor')
            ? $job->uniqueFor()
            : ($this->getAttributeValue($job, UniqueFor::class, 'uniqueFor') ?? 0);

        $cache = method_exists($job, 'uniqueVia')
            ? ($job->uniqueVia() ?? $this->cache)
            : $this->cache;

        return [
            $uniqueFor,
            $cache,
            static::getKey($job),
            $cache->getStore() instanceof LockProvider,
        ];
    }

    /**
     * Acquire a unique lock using resolved values.
     *
     * @return false|string false when the lock was not acquired, otherwise its owner or an empty string when no owner was captured
     */
    protected function acquireResolvedLock(
        mixed $job,
        Cache $cache,
        string $key,
        int $uniqueFor,
        bool $supportsOwnership,
        bool $captureOwner = false,
    ): false|string {
        // @phpstan-ignore method.notFound (lock() is on LockProvider, which concrete stores implement)
        $lock = $cache->lock($key, $uniqueFor);

        if (! $lock->get()) {
            return false;
        }

        $usesQueueable = isset(class_uses_recursive($job)[Queueable::class]);
        // Dispatch cleanup needs an owner even when the job cannot carry Queueable state.
        $owner = $supportsOwnership && ($captureOwner || $usesQueueable)
            ? $lock->owner()
            : '';

        if ($usesQueueable && $supportsOwnership) {
            $job->uniqueLockOwner = $owner;
        }

        return $owner;
    }

    /**
     * Release the lock for the given job.
     */
    public function release(mixed $job): void
    {
        $cache = method_exists($job, 'uniqueVia')
            ? ($job->uniqueVia() ?? $this->cache)
            : $this->cache;

        $owner = isset(class_uses_recursive($job)[Queueable::class])
            ? $job->uniqueLockOwner
            : '';

        static::releaseOwned($cache, static::getKey($job), $owner);
    }

    /**
     * Release a unique lock from already-resolved provenance.
     *
     * @internal
     */
    public static function releaseOwned(Cache $cache, string $key, string $owner = ''): void
    {
        if ($owner !== '') {
            if ($cache->getStore() instanceof LockProvider) {
                // @phpstan-ignore method.notFound (restoreLock() is on LockProvider, which concrete stores implement)
                $cache->restoreLock($key, $owner)->release();
            }

            return;
        }

        // @phpstan-ignore method.notFound (lock() is on LockProvider, which concrete stores implement)
        $cache->lock($key)->forceRelease();
    }

    /**
     * Generate the lock key for the given job.
     */
    public static function getKey(mixed $job): string
    {
        $uniqueId = method_exists($job, 'uniqueId')
            ? $job->uniqueId()
            : ($job->uniqueId ?? '');

        $jobName = method_exists($job, 'displayName')
            ? hash('xxh128', $job->displayName())
            : get_class($job);

        // Uses Laravel's prefix for cross-framework queue interoperability.
        return 'laravel_unique_job:' . $jobName . ':' . $uniqueId;
    }
}
