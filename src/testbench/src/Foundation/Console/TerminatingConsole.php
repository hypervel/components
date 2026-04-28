<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Support\Collection;

/**
 * @internal
 */
final class TerminatingConsole
{
    /**
     * The terminating callbacks.
     *
     * @var array<int, callable():void>
     */
    private static array $beforeTerminatingCallbacks = [];

    /**
     * Register a callback to be run before terminating the command.
     *
     * @param callable():void $callback
     */
    public static function before(callable $callback): void
    {
        array_unshift(self::$beforeTerminatingCallbacks, $callback);
    }

    /**
     * Register a callback to be run before terminating the command when condition is true.
     *
     * @param callable():void $callback
     */
    public static function beforeWhen(bool $condition, callable $callback): void
    {
        if ($condition === true) {
            self::before($callback);
        }
    }

    /**
     * Handle terminating console.
     */
    public static function handle(): void
    {
        (new Collection(self::$beforeTerminatingCallbacks))
            ->each(static function ($callback) {
                call_user_func($callback);
            });

        self::flush();
    }

    /**
     * Purge terminating console callbacks.
     */
    public static function flush(): void
    {
        self::$beforeTerminatingCallbacks = [];
    }
}
