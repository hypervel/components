<?php

declare(strict_types=1);

namespace Hypervel\Cache;

/**
 * Fallback implementations for stores without native multi-key operations.
 *
 * Stores should provide native many() / putMany() implementations when their
 * backend supports batching. Do not use this on repository wrappers such as
 * TaggedCache, where Repository::many() preserves events, sentinels, defaults,
 * and wrapper-store raw reads.
 */
trait RetrievesMultipleKeys
{
    /**
     * Retrieve multiple items from the cache by key.
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        $return = [];

        $keys = collect($keys)->mapWithKeys(function ($value, $key) {
            return [is_string($key) ? $key : $value => is_string($key) ? $value : null];
        })->all();

        foreach ($keys as $key => $default) {
            /* @phpstan-ignore arguments.count (some clients don't accept a default) */
            $return[$key] = $this->get($key, $default);
        }

        return $return;
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, int $seconds): bool
    {
        $manyResult = null;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $seconds);

            $manyResult = is_null($manyResult) ? $result : $result && $manyResult;
        }

        return $manyResult ?: false;
    }
}
