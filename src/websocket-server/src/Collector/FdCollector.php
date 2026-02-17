<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Collector;

class FdCollector
{
    /** @var array<int, Fd> */
    protected static array $fds = [];

    /**
     * Register a file descriptor with its handler class.
     */
    public static function set(int $id, string $class): void
    {
        static::$fds[$id] = new Fd($id, $class);
    }

    /**
     * Get the Fd instance for the given ID.
     */
    public static function get(int $id, ?Fd $default = null): ?Fd
    {
        return static::$fds[$id] ?? $default;
    }

    /**
     * Determine if a file descriptor is registered.
     */
    public static function has(int $id): bool
    {
        return isset(static::$fds[$id]);
    }

    /**
     * Remove a file descriptor from the collector.
     */
    public static function del(int $id): void
    {
        unset(static::$fds[$id]);
    }

    /**
     * Get all registered file descriptors.
     *
     * @return array<int, Fd>
     */
    public static function list(): array
    {
        return static::$fds;
    }
}
