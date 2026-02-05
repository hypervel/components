<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hypervel\Engine\Channel;

class Locker
{
    /**
     * @var array<string, Channel|null>
     */
    protected static array $channels = [];

    /**
     * Acquire a lock for the given key.
     *
     * Returns true if this is the first lock acquisition (owner),
     * or false if waiting on an existing lock.
     */
    public static function lock(string $key): bool
    {
        if (! isset(static::$channels[$key])) {
            static::$channels[$key] = new Channel(1);
            return true;
        }

        $channel = static::$channels[$key];
        $channel->pop(-1);
        return false;
    }

    /**
     * Release the lock for the given key.
     */
    public static function unlock(string $key): void
    {
        if (isset(static::$channels[$key])) {
            $channel = static::$channels[$key];
            static::$channels[$key] = null;
            $channel->close();
        }
    }
}
