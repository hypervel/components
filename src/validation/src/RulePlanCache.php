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

    /** @var array<string, AttributePlan> */
    private static array $plans = [];

    /** @var list<string> LRU order — most recently used at end */
    private static array $order = [];

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

        if ($key === null) {
            return null;
        }

        if (isset(self::$plans[$key])) {
            self::$order = array_values(array_filter(
                self::$order,
                static fn (string $k): bool => $k !== $key,
            ));
            self::$order[] = $key;

            return self::$plans[$key];
        }

        return null;
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

        while (count(self::$plans) >= self::$maxSize && self::$order !== []) {
            $evict = array_shift(self::$order);
            unset(self::$plans[$evict]);
        }

        self::$plans[$key] = $plan;
        self::$order[] = $key;
    }

    /**
     * Reset cache. Called by AfterEachTestSubscriber between tests.
     */
    public static function flushState(): void
    {
        self::$maxSize = 2048;
        self::$plans = [];
        self::$order = [];
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
