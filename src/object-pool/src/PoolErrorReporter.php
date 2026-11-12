<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Support\FailureReporter;
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
        FailureReporter::report($exception);
    }
}
