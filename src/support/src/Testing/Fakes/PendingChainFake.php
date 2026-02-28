<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use Hypervel\Foundation\Bus\PendingChain;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Queue\CallQueuedClosure;

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
    public function dispatch(): PendingDispatch
    {
        if (is_string($this->job)) {
            $firstJob = new $this->job(...func_get_args());
        } elseif ($this->job instanceof Closure) {
            $firstJob = CallQueuedClosure::create($this->job);
        } else {
            $firstJob = $this->job;
        }

        $firstJob->allOnConnection($this->connection);
        $firstJob->allOnQueue($this->queue);
        $firstJob->chain($this->chain);
        $firstJob->delay($this->delay);
        $firstJob->chainCatchCallbacks = $this->catchCallbacks();

        return $this->bus->dispatch($firstJob);
    }
}
