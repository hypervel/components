<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Container\Container;
use Hypervel\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Throwable;

trait DetectsConcurrencyErrors
{
    /**
     * Determine if the given exception was caused by a concurrency error such as a deadlock or serialization failure.
     */
    protected function causedByConcurrencyError(Throwable $e): bool
    {
        $container = Container::getInstance();

        $detector = $container->has(ConcurrencyErrorDetectorContract::class)
            ? $container->make(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector();

        return $detector->causedByConcurrencyError($e);
    }
}
