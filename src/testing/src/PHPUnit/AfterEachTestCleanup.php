<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Throwable;

/**
 * Process-local after-each cleanup callbacks for apps and packages.
 *
 * This class intentionally does not expose `flushState()` and should not be
 * added to `AfterEachTestSubscriber`; callbacks are suite-level registrations
 * that must persist for the PHPUnit worker lifetime.
 */
class AfterEachTestCleanup
{
    /**
     * The registered after-each cleanup callbacks.
     *
     * @var array<string, Closure(): void>
     */
    protected static array $callbacks = [];

    /**
     * Register a callback to flush test state after every test.
     *
     * The callback persists in static state for the PHPUnit worker
     * lifetime and runs after every subsequent test.
     */
    #[BootOrTestsOnly]
    public static function flushUsing(string $name, callable $callback): void
    {
        static::$callbacks[$name] = Closure::fromCallable($callback);
    }

    /**
     * Run registered callbacks.
     *
     * @throws Throwable
     */
    public static function runCallbacks(): void
    {
        /** @var null|Throwable $exception */
        $exception = null;

        foreach (static::$callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $throwable) {
                $exception = Coroutine::preferCancellation($exception, $throwable);
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Forget a registered callback by name.
     *
     * This removes one process-local cleanup registration from the current
     * PHPUnit worker.
     */
    #[BootOrTestsOnly]
    public static function forget(string $name): void
    {
        unset(static::$callbacks[$name]);
    }

    /**
     * Forget all registered callbacks.
     *
     * This removes process-local cleanup registrations for the current
     * PHPUnit worker, including callbacks discovered from app and package
     * metadata.
     */
    #[BootOrTestsOnly]
    public static function forgetCallbacks(): void
    {
        static::$callbacks = [];
    }
}
