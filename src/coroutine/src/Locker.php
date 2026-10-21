<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Engine\Channel;
use Swoole\Coroutine\CanceledException;

class Locker
{
    /**
     * @var array<string, Channel>
     */
    protected static array $channels = [];

    /**
     * Acquire the lock for the given key, or wait until its owner releases it.
     *
     * Returns true when the caller becomes the owner, which must release the lock
     * in a finally block. Returns false once the owner has released it. A waiter
     * then re-checks the shared result and, when the owner left none, calls lock()
     * again, so one waiter becomes the next owner instead of all repeating the work.
     *
     * @param float $timeout Seconds to wait for the owner (values less than or equal to zero wait indefinitely)
     *
     * @throws WaitTimeoutException When the owner does not release the lock in time
     * @throws CanceledException When the waiting coroutine is canceled
     */
    public static function lock(string $key, float $timeout = -1): bool
    {
        if (! isset(static::$channels[$key])) {
            static::$channels[$key] = new Channel(1);
            return true;
        }

        $channel = static::$channels[$key];
        $channel->pop($timeout);

        // Nothing is ever pushed, so every wake is a failed pop whose state is current.
        if ($channel->isCanceled()) {
            throw new CanceledException("Waiting for the [{$key}] lock was canceled.");
        }

        if ($channel->isTimeout()) {
            throw new WaitTimeoutException("Waiting for the [{$key}] lock timed out after {$timeout} seconds.");
        }

        return false;
    }

    /**
     * Release the lock for the given key.
     */
    public static function unlock(string $key): void
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
