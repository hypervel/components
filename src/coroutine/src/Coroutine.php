<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Engine\Coroutine as Co;
use Hypervel\Engine\Exceptions\CoroutineDestroyedException;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class Coroutine
{
    protected static bool $enableReportException = true;

    /**
     * @var array<callable>
     */
    protected static array $afterCreatedCallbacks = [];

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
     * Register a callback to be called after a coroutine is created.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs for every subsequently created coroutine. Callbacks
     * run synchronously during child startup and must not suspend.
     */
    public static function afterCreated(callable $callback): void
    {
        static::$afterCreatedCallbacks[] = $callback;
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
                static::reportUncaught($throwable);
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
     */
    public static function create(callable $callable): int
    {
        return self::createWithContext($callable, [], null);
    }

    /**
     * Create a coroutine whose lifecycle is owned by a framework wrapper.
     *
     * The wrapper runs at native child entry. It must not suspend outside the
     * supplied runner and must invoke that runner exactly once.
     *
     * @param Closure(Closure(): void): void $wrapper
     *
     * @internal
     */
    public static function createOwned(callable $callable, Closure $wrapper): int
    {
        return self::createWithContext($callable, [], $wrapper);
    }

    /**
     * Create a coroutine with a copy of the parent coroutine context.
     *
     * @param array<string> $keys Context keys to copy (empty = all keys)
     */
    public static function fork(callable $callable, array $keys = []): int
    {
        $context = CoroutineContext::captureFrom($keys);

        return self::createWithContext($callable, $context, null);
    }

    /**
     * Create an owned coroutine with a copy of the parent coroutine context.
     *
     * The wrapper runs at native child entry. It must not suspend outside the
     * supplied runner and must invoke that runner exactly once.
     *
     * @param Closure(Closure(): void): void $wrapper
     * @param array<string> $keys Context keys to copy (empty = all keys)
     *
     * @internal
     */
    public static function forkOwned(callable $callable, Closure $wrapper, array $keys = []): int
    {
        $context = CoroutineContext::captureFrom($keys);

        return self::createWithContext($callable, $context, $wrapper);
    }

    /**
     * Create a coroutine after installing its initial context.
     */
    private static function createWithContext(callable $callable, array $context, ?Closure $wrapper): int
    {
        $coroutine = Co::create(static function () use ($callable, $context, $wrapper): void {
            try {
                if ($wrapper === null) {
                    self::runChild($callable, $context);

                    return;
                }

                $wrapper(static function () use ($callable, $context): void {
                    self::runChild($callable, $context);
                });
            } catch (Throwable $throwable) {
                static::reportUncaught($throwable);
            }
        });

        return $coroutine->getId();
    }

    /**
     * Install startup context and run a child callable.
     */
    private static function runChild(callable $callable, array $context): void
    {
        CoroutineContext::setMany($context);

        foreach (static::$afterCreatedCallbacks as $callback) {
            try {
                $callback();
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                static::printLog($throwable);
            }
        }

        $callable();
    }

    /**
     * Wait for the given coroutines to finish.
     *
     * A false return may mean that no supplied coroutine remained active or
     * that the timeout elapsed. It is not a general failure signal.
     *
     * @param list<int> $coroutineIds
     */
    public static function join(array $coroutineIds, float $timeout = -1): bool
    {
        return Co::join($coroutineIds, $timeout);
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
     *
     * Boot-only. The flag persists in a static property for the worker lifetime
     * and applies to every coroutine spawned across the process.
     */
    public static function enableReportException(bool $enableReportException): void
    {
        static::$enableReportException = $enableReportException;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$enableReportException = true;
        static::$afterCreatedCallbacks = [];
    }

    /**
     * Report an exception through the exception handler.
     *
     * @throws CanceledException When the failure or reporting path is canceled
     */
    protected static function printLog(Throwable $throwable): void
    {
        if ($throwable instanceof CanceledException) {
            throw $throwable;
        }

        if (! static::$enableReportException) {
            return;
        }

        try {
            $container = Container::getInstance();

            if ($container->has(ExceptionHandlerContract::class)) {
                $container->make(ExceptionHandlerContract::class)
                    ->report($throwable);
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            try {
                error_log((string) $throwable);
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable) {
            }
        }
    }

    /**
     * Report an exception without allowing cancellation to escape a terminal coroutine boundary.
     */
    protected static function reportUncaught(Throwable $throwable): void
    {
        if ($throwable instanceof CanceledException) {
            return;
        }

        try {
            static::printLog($throwable);
        } catch (CanceledException) {
        }
    }
}
