<?php

declare(strict_types=1);

namespace Hypervel\Redis;

final class PhpRedis
{
    private const NULL_INITIAL_CURSOR_VERSION = '6.1.0';

    /**
     * Determine if phpredis uses null as the initial SCAN-family cursor.
     */
    public static function usesNullInitialScanCursor(): bool
    {
        return version_compare(self::version(), self::NULL_INITIAL_CURSOR_VERSION, '>=');
    }

    /**
     * Get the initial cursor value for SCAN-family operations.
     *
     * phpredis >= 6.1.0 expects null for the initial cursor; older versions use 0.
     *
     * @see https://github.com/laravel/framework/issues/53082
     */
    public static function initialScanCursor(): ?int
    {
        return self::usesNullInitialScanCursor() ? null : 0;
    }

    /**
     * Get the installed phpredis extension version.
     */
    public static function version(): string
    {
        return phpversion('redis') ?: '0';
    }
}
