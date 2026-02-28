<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess;

use Swoole\Process;

/**
 * Collect coroutine-enabled Swoole processes by name.
 */
class ProcessCollector
{
    /**
     * @var array<string, array<Process>>
     */
    protected static array $processes = [];

    /**
     * Add a process to the collector under the given name.
     */
    public static function add(string $name, Process $process): void
    {
        static::$processes[$name][] = $process;
    }

    /**
     * Get all processes registered under the given name.
     *
     * @return Process[]
     */
    public static function get(string $name): array
    {
        return static::$processes[$name] ?? [];
    }

    /**
     * Get all collected processes.
     *
     * @return Process[]
     */
    public static function all(): array
    {
        $result = [];
        foreach (static::$processes as $processes) {
            $result = array_merge($result, $processes);
        }
        return $result;
    }

    /**
     * Determine if the collector is empty.
     */
    public static function isEmpty(): bool
    {
        return static::$processes === [];
    }
}
