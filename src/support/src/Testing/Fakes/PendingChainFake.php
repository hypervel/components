<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Hypervel\Foundation\Bus\PendingChain;

class PendingChainFake extends PendingChain
{
    /**
     * Create a new pending chain instance.
     *
     * @param BusFake $bus the fake bus instance
     */
    public function __construct(
        public BusFake $bus,
        public mixed $job,
        public array $chain = []
    ) {
    }

    /**
     * Dispatch the job with the given arguments.
     */
    public function dispatch(mixed ...$arguments): mixed
    {
        return $this->bus->dispatch($this->prepareFirstJob($arguments));
    }
}
