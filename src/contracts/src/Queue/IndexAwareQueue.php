<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

interface IndexAwareQueue
{
    /**
     * Pop the next job using its position in the worker's queue priority list.
     */
    public function pop(?string $queue = null, int $index = 0): ?Job;
}
