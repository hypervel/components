<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Support\Attributes\TestsOnly;
use InvalidArgumentException;

/**
 * Worker-lifetime LRU cache of compiled rule plans.
 *
 * Plans are keyed by captured declarations containing only plain data.
 * Plans are pure data — safe to cache across requests. A cached null-equivalent
 * (un-cacheable) is represented by returning null from get(), not stored.
 *
 * Bounded by $maxSize with LRU eviction. Typical apps have <1000 unique rules;
 * the cap prevents pathological growth in apps that dynamically generate rules.
 */
final class RulePlanCache
{
    private const int DEFAULT_MAX_SIZE = 2048;

    private static int $maxSize = self::DEFAULT_MAX_SIZE;

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
     * Get a cached plan using its already-prepared key.
     *
     * Return null for rules with executable or opaque state, or when no plan exists.
     */
    public static function get(?string $key): ?AttributePlan
    {
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
     * Store a compiled plan under its already-prepared key.
     */
    public static function put(?string $key, AttributePlan $plan): void
    {
        if ($key === null) {
            return;
        }

        // Remove before evicting so replacement neither evicts another entry
        // nor leaves this key at its old position.
        unset(self::$plans[$key]);

        while (count(self::$plans) >= self::$maxSize) {
            unset(self::$plans[array_key_first(self::$plans)]);
        }

        self::$plans[$key] = $plan;
    }

    /**
     * Set the maximum cache size.
     *
     * The cache size persists in a static property for the worker lifetime
     * and affects every subsequent validation rule plan cache write.
     */
    #[TestsOnly]
    public static function setMaxSize(int $size): void
    {
        if ($size < 1) {
            throw new InvalidArgumentException('The rule plan cache size must be at least 1.');
        }

        self::$maxSize = $size;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$maxSize = self::DEFAULT_MAX_SIZE;
        self::$plans = [];
    }
}
