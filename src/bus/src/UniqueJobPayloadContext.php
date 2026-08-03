<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Queue\ShouldBeUnique;
use WeakMap;

class UniqueJobPayloadContext
{
    /**
     * The unique job metadata waiting to be consumed during payload creation.
     *
     * @var null|WeakMap<object, array{laravel_unique_job_cache_store: ?string, laravel_unique_job_key: string}>
     */
    protected static ?WeakMap $metadata = null;

    /**
     * Register unique job metadata for payload creation.
     */
    public static function register(ShouldBeUnique $job): void
    {
        // @phpstan-ignore assign.propertyType (PHPStan falsely rejects an empty WeakMap for this invariant closed-shape value.)
        $metadata = static::$metadata ??= new WeakMap;

        // Uses Laravel's keys for cross-framework queue interoperability.
        $metadata[$job] = [
            'laravel_unique_job_cache_store' => static::getCacheStore($job),
            'laravel_unique_job_key' => UniqueLock::getKey($job),
        ];
    }

    /**
     * Consume unique job metadata for payload creation.
     *
     * @return null|array{laravel_unique_job_cache_store: ?string, laravel_unique_job_key: string}
     */
    public static function consume(object $job): ?array
    {
        if (static::$metadata === null) {
            return null;
        }

        $metadata = static::$metadata;

        if (! isset($metadata[$job])) {
            if (count($metadata) === 0) {
                static::$metadata = null;
            }

            return null;
        }

        $value = $metadata[$job];

        unset($metadata[$job]);

        if (count($metadata) === 0) {
            static::$metadata = null;
        }

        return $value;
    }

    /**
     * Determine the cache store used by the unique job to acquire locks.
     */
    protected static function getCacheStore(ShouldBeUnique $job): ?string
    {
        return method_exists($job, 'uniqueVia')
            ? $job->uniqueVia()->getName()
            : config('cache.default');
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$metadata = null;
    }
}
