<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hypervel\Engine\Channel;

class Mutex
{
    /**
     * @var array<string, null|Channel>
     */
    protected static array $channels = [];

    /**
     * Acquire a mutex lock for the given key.
     *
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     * @return bool True if lock acquired, false if timeout or channel closing
     */
    public static function lock(string $key, float $timeout = -1): bool
    {
        if (! isset(static::$channels[$key])) {
            static::$channels[$key] = new Channel(1);
        }

        $channel = static::$channels[$key];
        $channel->push(1, $timeout);
        if ($channel->isTimeout() || $channel->isClosing()) {
            return false;
        }

        return true;
    }

    /**
     * Release a mutex lock for the given key.
     *
     * @param float $timeout Timeout in seconds
     * @return bool True if unlocked successfully, false if timeout (unlock called more than once)
     */
    public static function unlock(string $key, float $timeout = 5): bool
    {
        if (isset(static::$channels[$key])) {
            $channel = static::$channels[$key];
            $channel->pop($timeout);
            if ($channel->isTimeout()) {
                // unlock more than once
                return false;
            }
        }

        return true;
    }

    /**
     * Clear and close the mutex channel for the given key.
     */
    public static function clear(string $key): void
    {
        if (isset(static::$channels[$key])) {
            $channel = static::$channels[$key];
            static::$channels[$key] = null;
            $channel->close();
        }
    }
}
