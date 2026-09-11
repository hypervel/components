<?php

declare(strict_types=1);

namespace Hypervel\Bus\Events;

use Hypervel\Bus\Batch;
use Throwable;

class BatchCanceled
{
    /**
     * Create a new event instance.
     *
     * @param null|Throwable $exception the exception that caused the cancellation
     */
    public function __construct(
        public Batch $batch,
        public ?Throwable $exception = null,
    ) {
    }
}
