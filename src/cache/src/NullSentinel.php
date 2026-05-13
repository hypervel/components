<?php

declare(strict_types=1);

namespace Hypervel\Cache;

/**
 * Namespace for the sentinel array value stored in place of null by the
 * nullable cache wrapper methods.
 *
 * Used by Repository::rememberNullable(), rememberForeverNullable(),
 * searNullable(), and flexibleNullable() to distinguish "key absent" from
 * "key present with null value" — the cache contract itself can't express
 * that distinction via get() alone.
 *
 * The sentinel is an array constant, not an object. This is deliberate:
 * PHP's unserialize() allowed_classes option (used by stores when the
 * cache.serializable_classes config is set) only restricts object
 * deserialization. Scalars and arrays round-trip unchanged regardless of
 * the allowed_classes value. An object sentinel would silently become
 * __PHP_Incomplete_Class on any cache configured with a restrictive
 * serializable_classes list that didn't include the sentinel class.
 *
 * Identity is checked with strict === against NullSentinel::VALUE, which
 * is safe because PHP's array equality compares keys and values recursively.
 * Collision risk is effectively zero: a caller would have to independently
 * cache a value whose structure is exactly
 * ['__hypervel_cache_null_sentinel' => true].
 */
final class NullSentinel
{
    /**
     * Sentinel value stored in place of null by the nullable cache methods.
     */
    public const VALUE = ['__hypervel_cache_null_sentinel' => true];

    /**
     * Unwrap a value read from the cache — sentinel becomes null; anything else passes through.
     *
     * Used at the boundaries where the cache layer returns to public API callers,
     * so the sentinel never leaks through get/many/remember/flexible/etc.
     */
    public static function unwrap(mixed $value): mixed
    {
        return $value === self::VALUE ? null : $value;
    }
}
