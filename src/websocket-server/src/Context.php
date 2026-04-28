<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Support\Arr;

class Context
{
    public const FD = 'ws.fd';

    protected static array $storage = [];

    /**
     * Set a value in the WebSocket context.
     */
    public static function set(string $id, mixed $value): mixed
    {
        $fd = CoroutineContext::get(Context::FD, 0);
        $key = sprintf('%d.%s', $fd, $id);
        data_set(self::$storage, $key, $value);
        return $value;
    }

    /**
     * Get a value from the WebSocket context.
     */
    public static function get(string $id, mixed $default = null, ?int $fd = null): mixed
    {
        $fd ??= CoroutineContext::get(Context::FD, 0);
        $key = sprintf('%d.%s', $fd, $id);
        return data_get(self::$storage, $key, $default);
    }

    /**
     * Determine if a value exists in the WebSocket context.
     */
    public static function has(string $id, ?int $fd = null): bool
    {
        $fd ??= CoroutineContext::get(Context::FD, 0);
        $key = sprintf('%d.%s', $fd, $id);
        return data_get(self::$storage, $key) !== null;
    }

    /**
     * Remove a value from the WebSocket context.
     */
    public static function forget(string $id): void
    {
        $fd = CoroutineContext::get(Context::FD, 0);
        unset(self::$storage[strval($fd)][$id]);
    }

    /**
     * Release all context data for a file descriptor.
     */
    public static function release(?int $fd = null): void
    {
        $fd ??= CoroutineContext::get(Context::FD, 0);
        unset(self::$storage[strval($fd)]);
    }

    /**
     * Copy context data from another file descriptor into the current one.
     *
     * Merges into the current FD's context — existing values that are
     * not in the source are preserved. Matching keys are overwritten.
     *
     * @param array<string> $keys
     */
    public static function copyFrom(int $fromFd, array $keys = []): void
    {
        $fd = CoroutineContext::get(Context::FD, 0);
        $from = self::$storage[$fromFd];
        $map = $keys ? Arr::only($from, $keys) : $from;

        foreach ($map as $key => $value) {
            self::$storage[$fd][$key] = $value;
        }
    }

    /**
     * Override a value in the WebSocket context using a callback.
     */
    public static function override(string $id, Closure $closure): mixed
    {
        $value = null;
        if (self::has($id)) {
            $value = self::get($id);
        }
        $value = $closure($value);
        self::set($id, $value);
        return $value;
    }

    /**
     * Get a value from the context, or set and return a default.
     */
    public static function getOrSet(string $id, mixed $value): mixed
    {
        if (! self::has($id)) {
            return self::set($id, value($value));
        }
        return self::get($id);
    }

    /**
     * Get the entire context storage.
     */
    public static function getStorage(): array
    {
        return self::$storage;
    }

    /**
     * Reset the entire context storage.
     */
    public static function flushState(): void
    {
        self::$storage = [];
    }
}
