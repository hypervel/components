<?php

declare(strict_types=1);

namespace Hypervel\Support;

class ClearStatCache
{
    /**
     * Interval at which to clear filesystem stat cache. Values below 1 indicate
     * the stat cache should ALWAYS be cleared. Otherwise, the value is the number
     * of seconds between clear operations.
     */
    private static int $interval = 1;

    /**
     * When the filesystem stat cache was last cleared.
     */
    private static int $lastCleared = 0;

    /**
     * Clear the filesystem stat cache if the interval has elapsed.
     */
    public static function clear(?string $filename = null): void
    {
        $now = time();
        if (1 > self::$interval
            || ! self::$lastCleared
            || (self::$lastCleared + self::$interval < $now)
        ) {
            static::forceClear($filename);
            self::$lastCleared = $now;
        }
    }

    /**
     * Force clear the filesystem stat cache regardless of interval.
     */
    public static function forceClear(?string $filename = null): void
    {
        if ($filename !== null) {
            clearstatcache(true, $filename);
        } else {
            clearstatcache();
        }
    }

    /**
     * Get the clear interval in seconds.
     */
    public static function getInterval(): int
    {
        return self::$interval;
    }

    /**
     * Set the clear interval in seconds.
     */
    public static function setInterval(int $interval): void
    {
        self::$interval = $interval;
    }

    /**
     * Reset the state to defaults.
     */
    public static function reset(): void
    {
        self::$interval = 1;
        self::$lastCleared = 0;
    }
}
