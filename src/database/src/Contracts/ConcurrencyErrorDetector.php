<?php

declare(strict_types=1);

namespace Hypervel\Database\Contracts;

use Throwable;

interface ConcurrencyErrorDetector
{
    /**
     * Determine if the given exception was caused by a concurrency error such as a deadlock or serialization failure.
     */
    public function causedByConcurrencyError(Throwable $e): bool;
}
