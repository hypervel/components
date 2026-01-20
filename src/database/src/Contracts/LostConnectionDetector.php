<?php

declare(strict_types=1);

namespace Hypervel\Database\Contracts;

use Throwable;

interface LostConnectionDetector
{
    /**
     * Determine if the given exception was caused by a lost connection.
     */
    public function causedByLostConnection(Throwable $e): bool;
}
