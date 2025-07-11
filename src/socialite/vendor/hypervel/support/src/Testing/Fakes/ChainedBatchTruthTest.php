<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use Hypervel\Bus\PendingBatch;

class ChainedBatchTruthTest
{
    /**
     * Create a new truth test instance.
     *
     * @param Closure $callback the underlying truth test
     */
    public function __construct(
        protected Closure $callback
    ) {
    }

    /**
     * Invoke the truth test with the given pending batch.
     */
    public function __invoke(PendingBatch $pendingBatch): bool
    {
        return call_user_func($this->callback, $pendingBatch);
    }
}
