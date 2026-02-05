<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Engine\Coroutine as Co;
use Hypervel\Engine\Exception\CoroutineDestroyedException;
use Hypervel\Engine\Exception\RunningInNonCoroutineException;
use Throwable;

class Coroutine
{
    protected static bool $enableReportException = true;

    /**
     * Returns the current coroutine ID.
     *
     * Returns -1 when running in non-coroutine context.
     */
    public static function id(): int
    {
        return Co::id();
    }

    /**
     * Register a callback to be executed when the coroutine exits.
     */
    public static function defer(callable $callable): void
    {
        Co::defer(static function () use ($callable) {
            try {
                $callable();
            } catch (Throwable $throwable) {
                static::printLog($throwable);
            }
        });
    }

    /**
     * Sleep for the given number of seconds.
     */
    public static function sleep(float $seconds): void
    {
        usleep(intval($seconds * 1000 * 1000));
    }

    /**
     * Returns the parent coroutine ID.
     *
     * Returns 0 when running in the top level coroutine.
     *
     * @throws RunningInNonCoroutineException When running in non-coroutine context
     * @throws CoroutineDestroyedException When the coroutine has been destroyed
     */
    public static function parentId(?int $coroutineId = null): int
    {
        return Co::pid($coroutineId);
    }

    /**
     * Alias of Coroutine::parentId().
     *
     * @throws RunningInNonCoroutineException When running in non-coroutine context
     * @throws CoroutineDestroyedException When the coroutine has been destroyed
     */
    public static function pid(?int $coroutineId = null): int
    {
        return Co::pid($coroutineId);
    }

    /**
     * Create a new coroutine.
     *
     * @return int The coroutine ID, or -1 if creation failed
     */
    public static function create(callable $callable): int
    {
        $coroutine = Co::create(static function () use ($callable) {
            try {
                $callable();
            } catch (Throwable $throwable) {
                static::printLog($throwable);
            }
        });

        try {
            return $coroutine->getId();
        } catch (Throwable) {
            return -1;
        }
    }

    /**
     * Create a coroutine with a copy of the parent coroutine context.
     *
     * @param array<string> $keys Context keys to copy (empty = all keys)
     */
    public static function fork(callable $callable, array $keys = []): int
    {
        $cid = static::id();
        $callable = static function () use ($callable, $cid, $keys) {
            Context::copy($cid, $keys);
            $callable();
        };

        return static::create($callable);
    }

    /**
     * Determine if currently running in a coroutine.
     */
    public static function inCoroutine(): bool
    {
        return Co::id() > 0;
    }

    /**
     * Get coroutine statistics.
     */
    public static function stats(): array
    {
        return Co::stats();
    }

    /**
     * Determine if a coroutine with the given ID exists.
     */
    public static function exists(int $id): bool
    {
        return Co::exists($id);
    }

    /**
     * Get a list of all coroutine IDs.
     *
     * @return iterable<int>
     */
    public static function list(): iterable
    {
        return Co::list();
    }

    /**
     * Enable or disable exception reporting in coroutines.
     */
    public static function enableReportException(bool $enableReportException): void
    {
        static::$enableReportException = $enableReportException;
    }

    /**
     * Report an exception through the exception handler.
     */
    protected static function printLog(Throwable $throwable): void
    {
        if (! ApplicationContext::hasContainer() || ! static::$enableReportException) {
            return;
        }

        $container = ApplicationContext::getContainer();

        if ($container->has(ExceptionHandlerContract::class)) {
            $container->get(ExceptionHandlerContract::class)
                ->report($throwable);
        }
    }
}
