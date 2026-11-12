<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Swoole\Coroutine\CanceledException;
use Throwable;

class FailureReporter
{
    /**
     * Report a contained failure without allowing reporting to escape.
     */
    public static function report(Throwable $throwable): void
    {
        // @phpstan-ignore hypervel.cancellation.exactType (a terminal reporter: a cleanup failure carrying cancellation is still reported)
        if ($throwable instanceof CanceledException) {
            return;
        }

        try {
            app(ExceptionHandler::class)->report($throwable);
        } catch (Throwable $reportingFailure) {
            try {
                error_log((string) $throwable . PHP_EOL . (string) $reportingFailure);
            } catch (Throwable) {
            }
        }
    }
}
