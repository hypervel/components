<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Reports secondary failures at boundaries that must keep the failure they are already handling.
 */
final class FailureReporter
{
    /**
     * Prevent construction of this static utility.
     */
    private function __construct()
    {
    }

    /**
     * Report a secondary failure without ever throwing.
     */
    public static function report(Throwable $exception): void
    {
        // @phpstan-ignore hypervel.cancellation.exactType (a terminal reporter: a cleanup failure carrying cancellation is still reported)
        if ($exception instanceof CanceledException) {
            return;
        }

        try {
            $container = Container::getInstance();

            if ($container->has(ExceptionHandler::class)) {
                $container->make(ExceptionHandler::class)->report($exception);

                return;
            }
        } catch (CanceledException) {
            return;
        } catch (Throwable) {
        }

        try {
            error_log((string) $exception);
        } catch (Throwable) {
        }
    }

    /**
     * Select the kept failure like Coroutine::preferCancellation() and report the one it displaces.
     */
    public static function preferCancellation(?Throwable $kept, Throwable $next): Throwable
    {
        $selected = Coroutine::preferCancellation($kept, $next);
        $displaced = $selected === $next ? $kept : $next;

        if ($displaced !== null && $displaced !== $selected) {
            self::report($displaced);
        }

        return $selected;
    }
}
