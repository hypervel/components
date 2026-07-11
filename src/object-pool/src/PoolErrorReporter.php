<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Throwable;

final class PoolErrorReporter
{
    /**
     * Prevent construction of this static utility.
     */
    private function __construct()
    {
    }

    /**
     * Report a cleanup failure without ever throwing.
     */
    public static function report(Throwable $exception): void
    {
        try {
            $container = Container::getInstance();

            if ($container->has(ExceptionHandler::class)) {
                $container->make(ExceptionHandler::class)->report($exception);

                return;
            }
        } catch (Throwable) {
        }

        try {
            error_log((string) $exception);
        } catch (Throwable) {
        }
    }
}
