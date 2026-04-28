<?php

declare(strict_types=1);

namespace Hypervel\Validation;

/**
 * Worker-lifetime LRU cache of compiled rule plans.
 *
 * Plans are keyed by the normalized rule definition (string-only arrays).
 * Plans are pure data — safe to cache across requests. A cached null-equivalent
 * (un-cacheable) is represented by returning null from get(), not stored.
 *
 * Bounded by $maxSize with LRU eviction. Typical apps have <1000 unique rules;
 * the cap prevents pathological growth in apps that dynamically generate rules.
 */
final class RulePlanCache
{
    private static int $maxSize = 2048;

    /**
     * The cached plans, ordered least-recently-used first.
     *
     * PHP associative arrays preserve insertion order, so the array itself
     * doubles as the LRU queue: the oldest entry is always at the head and
     * the most-recently-used entry is always at the tail. A hit refreshes
     * recency by unset-then-reinsert (both O(1)), and eviction pops the
     * head via array_key_first (O(1)).
     *
     * @var array<string, AttributePlan>
     */
    private static array $plans = [];

    /**
     * Get a cached plan for the given rule array.
     *
     * Returns null if the rules contain non-string elements (un-cacheable)
     * or if no cached plan exists.
     *
     * @param list<mixed> $rules
     */
    public static function get(array $rules): ?AttributePlan
    {
        $key = self::cacheKey($rules);

        if ($key === null || ! isset(self::$plans[$key])) {
            return null;
        }

        // Refresh recency: unset then re-insert moves the entry to the end
        // (most-recently-used position) in O(1).
        $plan = self::$plans[$key];
        unset(self::$plans[$key]);
        self::$plans[$key] = $plan;

        return $plan;
    }

    /**
     * Store a compiled plan in the cache.
     *
     * @param list<mixed> $rules
     */
    public static function put(array $rules, AttributePlan $plan): void
    {
        $key = self::cacheKey($rules);

        if ($key === null) {
            return;
        }

        // Remove any existing entry for this key FIRST. Two reasons:
        //  1. A re-put at capacity must not evict an unrelated entry.
        //     Without this unset, count() stays at maxSize, the loop below
        //     evicts array_key_first(), and only THEN does the final
        //     assignment land — silently displacing an innocent entry.
        //  2. A re-put must refresh the key to most-recently-used. Just
        //     overwriting the existing slot would leave it at its current
        //     (old) insertion position.
        unset(self::$plans[$key]);

        while (count(self::$plans) >= self::$maxSize) {
            unset(self::$plans[array_key_first(self::$plans)]);
        }

        self::$plans[$key] = $plan;
    }

    /**
     * Reset cache. Called by AfterEachTestSubscriber between tests.
     */
    public static function flushState(): void
    {
        self::$maxSize = 2048;
        self::$plans = [];
    }

    /**
     * Set the maximum cache size. Intended for tests.
     */
    public static function setMaxSize(int $size): void
    {
        self::$maxSize = $size;
    }

    /**
     * Produce a stable cache key for string-only rule arrays.
     *
     * Returns null if the array contains any non-string element (un-cacheable
     * because Rule objects and Closures can't produce a stable key).
     *
     * @param list<mixed> $rules
     */
    private static function cacheKey(array $rules): ?string
    {
        $parts = [];

        foreach ($rules as $item) {
            if (! is_string($item)) {
                return null;
            }
            $parts[] = $item;
        }

        return implode('|', $parts);
    }
}
