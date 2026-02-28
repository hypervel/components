<?php

declare(strict_types=1);

namespace Hyperf\Coroutine;

use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Coroutine as Co;
use Hypervel\Engine\Exception\CoroutineDestroyedException;
use Hypervel\Engine\Exception\RunningInNonCoroutineException;
use Hypervel\ExceptionHandler\Formatter\FormatterInterface;
use Throwable;

class Coroutine
{
    /**
     * @var array<callable>
     */
    private static array $afterCreatedCallbacks = [];

    /**
     * Returns the current coroutine ID.
     * Returns -1 when running in non-coroutine context.
     */
    public static function id(): int
    {
        return Co::id();
    }

    /**
     * Register a callback to be called after a coroutine is created.
     */
    public static function afterCreated(callable $callback): void
    {
        static::$afterCreatedCallbacks[] = $callback;
    }

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

    public static function sleep(float $seconds): void
    {
        usleep(intval($seconds * 1000 * 1000));
    }

    /**
     * Returns the parent coroutine ID.
     * Returns 0 when running in the top level coroutine.
     *
     * @throws RunningInNonCoroutineException when running in non-coroutine context
     * @throws CoroutineDestroyedException when the coroutine has been destroyed
     */
    public static function parentId(?int $coroutineId = null): int
    {
        return Co::pid($coroutineId);
    }

    /**
     * The alias of Coroutine::parentId().
     *
     * @throws CoroutineDestroyedException when running in non-coroutine context
     * @throws RunningInNonCoroutineException when the coroutine has been destroyed
     */
    public static function pid(?int $coroutineId = null): int
    {
        return Co::pid($coroutineId);
    }

    /**
     * @return int Returns the coroutine ID of the coroutine just created.
     *             Returns -1 when coroutine create failed.
     */
    public static function create(callable $callable): int
    {
        $coroutine = Co::create(static function () use ($callable) {
            try {
                // Execute afterCreated callbacks.
                foreach (static::$afterCreatedCallbacks as $callback) {
                    try {
                        $callback();
                    } catch (Throwable $throwable) {
                        static::printLog($throwable);
                    }
                }
                $callable();
            } catch (Throwable $throwable) {
                static::printLog($throwable);
            }
        });

        return $coroutine->getId();
    }

    /**
     * Create a coroutine with a copy of the parent coroutine context.
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

    public static function inCoroutine(): bool
    {
        return Co::id() > 0;
    }

    public static function stats(): array
    {
        return Co::stats();
    }

    public static function exists(int $id): bool
    {
        return Co::exists($id);
    }

    /**
     * @return iterable<int>
     */
    public static function list(): iterable
    {
        return Co::list();
    }

    /**
     * Flush after created callbacks.
     */
    public static function flushAfterCreated(): void
    {
        static::$afterCreatedCallbacks = [];
    }

    private static function printLog(Throwable $throwable): void
    {
        $container = Container::getInstance();
        if ($container->has(StdoutLoggerInterface::class)) {
            $logger = $container->make(StdoutLoggerInterface::class);
            if ($container->has(FormatterInterface::class)) {
                $formatter = $container->make(FormatterInterface::class);
                $logger->warning($formatter->format($throwable));
            } else {
                $logger->warning((string) $throwable);
            }
        }
    }
}
