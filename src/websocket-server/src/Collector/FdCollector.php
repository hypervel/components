<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Collector;

class FdCollector
{
    /** @var array<int, class-string> */
    protected static array $fds = [];

    /**
     * Register a file descriptor with its handler class.
     *
     * @param class-string $class
     */
    public static function set(int $id, string $class): void
    {
        static::$fds[$id] = $class;
    }

    /**
     * Get the handler class for the given file descriptor.
     *
     * @param null|class-string $default
     * @return null|class-string
     */
    public static function get(int $id, ?string $default = null): ?string
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
     * @return array<int, class-string>
     */
    public static function list(): array
    {
        return static::$fds;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$fds = [];
    }
}
