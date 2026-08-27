<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hypervel\Engine\Channel;

class Mutex
{
    /**
     * @var array<string, Channel>
     */
    protected static array $channels = [];

    /**
     * Acquire a mutex lock for the given key.
     *
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     * @return bool True when the acquisition token was accepted, false when the push failed
     */
    public static function lock(string $key, float $timeout = -1): bool
    {
        if (! isset(static::$channels[$key])) {
            static::$channels[$key] = new Channel(1);
        }

        $channel = static::$channels[$key];

        return $channel->push(1, $timeout);
    }

    /**
     * Release a mutex lock for the given key.
     *
     * @param float $timeout Timeout in seconds
     * @return bool True when a held token was released, false when no token was held or the pop failed
     */
    public static function unlock(string $key, float $timeout = 5): bool
    {
        if (! isset(static::$channels[$key])) {
            return false;
        }

        $channel = static::$channels[$key];

        if ($channel->getLength() === 0 || $channel->pop($timeout) === false) {
            return false;
        }

        // A fast waiter may have released this channel and published a replacement.
        if ((static::$channels[$key] ?? null) === $channel && $channel->getLength() === 0) {
            unset(static::$channels[$key]);
            $channel->close();
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
            unset(static::$channels[$key]);
            $channel->close();
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        foreach (static::$channels as $channel) {
            $channel->close();
        }

        static::$channels = [];
    }
}
