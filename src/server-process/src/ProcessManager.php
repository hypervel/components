<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess;

use Hypervel\Contracts\ServerProcess\ProcessInterface;
use RuntimeException;

class ProcessManager
{
    /** @var array<int, ProcessInterface> */
    protected static array $processes = [];

    protected static bool $running = false;

    /**
     * Register a server process.
     */
    public static function register(ProcessInterface $process): void
    {
        if (static::$running) {
            throw new RuntimeException('Processes are running, please register before BeforeMainServerStart is dispatched.');
        }

        static::$processes[] = $process;
    }

    /**
     * Get all registered processes.
     *
     * @return array<int, ProcessInterface>
     */
    public static function all(): array
    {
        return static::$processes;
    }

    /**
     * Flush all static state back to defaults.
     */
    public static function flushState(): void
    {
        static::$processes = [];
        static::$running = false;
    }

    /**
     * Determine if the processes are running.
     */
    public static function isRunning(): bool
    {
        return static::$running;
    }

    /**
     * Set the running state.
     */
    public static function setRunning(bool $running): void
    {
        static::$running = $running;
    }
}
