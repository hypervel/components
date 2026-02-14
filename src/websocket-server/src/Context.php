<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Closure;
use Hypervel\Context\Context as CoContext;
use Hypervel\Support\Arr;

class Context
{
    public const FD = 'ws.fd';

    protected static array $container = [];

    /**
     * Set a value in the WebSocket context.
     */
    public static function set(string $id, mixed $value): mixed
    {
        $fd = CoContext::get(Context::FD, 0);
        $key = sprintf('%d.%s', $fd, $id);
        data_set(self::$container, $key, $value);
        return $value;
    }

    /**
     * Get a value from the WebSocket context.
     */
    public static function get(string $id, mixed $default = null, ?int $fd = null): mixed
    {
        $fd ??= CoContext::get(Context::FD, 0);
        $key = sprintf('%d.%s', $fd, $id);
        return data_get(self::$container, $key, $default);
    }

    /**
     * Determine if a value exists in the WebSocket context.
     */
    public static function has(string $id, ?int $fd = null): bool
    {
        $fd ??= CoContext::get(Context::FD, 0);
        $key = sprintf('%d.%s', $fd, $id);
        return data_get(self::$container, $key) !== null;
    }

    /**
     * Remove a value from the WebSocket context.
     */
    public static function destroy(string $id): void
    {
        $fd = CoContext::get(Context::FD, 0);
        unset(self::$container[strval($fd)][$id]);
    }

    /**
     * Release all context data for a file descriptor.
     */
    public static function release(?int $fd = null): void
    {
        $fd ??= CoContext::get(Context::FD, 0);
        unset(self::$container[strval($fd)]);
    }

    /**
     * Copy context data from one file descriptor to the current one.
     *
     * @param array<string> $keys
     */
    public static function copy(int $fromFd, array $keys = []): void
    {
        $fd = CoContext::get(Context::FD, 0);
        $from = self::$container[$fromFd];
        self::$container[$fd] = ($keys ? Arr::only($from, $keys) : $from);
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
     * Get the entire context container.
     */
    public static function getContainer(): array
    {
        return self::$container;
    }
}
