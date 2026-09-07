<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

use Hypervel\Scout\EngineOperation;
use Throwable;

interface EngineOperationObserver
{
    /**
     * Start observing an engine operation.
     */
    public function starting(EngineOperation $operation): mixed;

    /**
     * Finish observing an engine operation.
     */
    public function finished(
        EngineOperation $operation,
        mixed $token,
        ?Throwable $exception
    ): void;
}
