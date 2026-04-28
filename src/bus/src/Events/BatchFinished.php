<?php

declare(strict_types=1);

namespace Hypervel\Bus\Events;

use Hypervel\Bus\Batch;

class BatchFinished
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Batch $batch,
    ) {
    }
}
