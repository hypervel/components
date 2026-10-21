<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Throwable;

/**
 * @internal
 */
final class TerminatingConsole
{
    /**
     * The terminating callbacks.
     *
     * @var array<int, callable(?int):void>
     */
    private static array $beforeTerminatingCallbacks = [];

    /**
     * Register a callback to be run before terminating the command.
     *
     * @param callable(?int):void $callback
     */
    public static function before(callable $callback): void
    {
        array_unshift(self::$beforeTerminatingCallbacks, $callback);
    }

    /**
     * Register a callback to be run before terminating the command when condition is true.
     *
     * @param callable(?int):void $callback
     */
    public static function beforeWhen(bool $condition, callable $callback): void
    {
        if ($condition === true) {
            self::before($callback);
        }
    }

    /**
     * Handle terminating console.
     *
     * @param null|int $signal The signal that is terminating the command
     */
    public static function handle(?int $signal = null): void
    {
        $callbacks = self::$beforeTerminatingCallbacks;
        self::$beforeTerminatingCallbacks = [];
        $failure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback($signal);
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        self::flush();

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Purge terminating console callbacks.
     */
    public static function flush(): void
    {
        self::$beforeTerminatingCallbacks = [];
    }
}
