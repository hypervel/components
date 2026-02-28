<?php

declare(strict_types=1);

namespace Hypervel\Server;

class ServerManager
{
    protected static array $container = [];

    /**
     * Add a server entry to the registry.
     */
    public static function add(string $name, array $value): void
    {
        static::$container[$name] = $value;
    }

    /**
     * Get a server entry by name.
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        return static::$container[$name] ?? $default;
    }

    /**
     * Determine if a server entry exists.
     */
    public static function has(string $name): bool
    {
        return isset(static::$container[$name]);
    }

    /**
     * Get all registered server entries.
     */
    public static function list(): array
    {
        return static::$container;
    }

    /**
     * Clear all registered server entries.
     */
    public static function clear(): void
    {
        static::$container = [];
    }
}
