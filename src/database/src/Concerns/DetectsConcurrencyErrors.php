<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Hyperf\Context\ApplicationContext;
use Hypervel\Database\ConcurrencyErrorDetector;
use Hypervel\Database\Contracts\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Throwable;

trait DetectsConcurrencyErrors
{
    /**
     * Determine if the given exception was caused by a concurrency error such as a deadlock or serialization failure.
     */
    protected function causedByConcurrencyError(Throwable $e): bool
    {
        $container = ApplicationContext::getContainer();

        $detector = $container->has(ConcurrencyErrorDetectorContract::class)
            ? $container->get(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector();

        return $detector->causedByConcurrencyError($e);
    }
}
