<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Throwable;

class FailureReporter
{
    /**
     * Report a contained failure without allowing reporting to escape.
     */
    public static function report(Throwable $throwable): void
    {
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
