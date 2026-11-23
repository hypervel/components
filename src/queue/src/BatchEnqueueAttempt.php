<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use Throwable;

/**
 * An unmarked offset is definitely unsent and can be withdrawn. Mark uncertain
 * sends before calling the transport so a lost reply cannot finish the batch early.
 *
 * @internal
 */
class BatchEnqueueAttempt
{
    private array $accepted = [];

    private array $unknown = [];

    /**
     * Track only this call and the callbacks registered for it.
     *
     * @param Closure(list<int>): void $onDeferredRejection
     */
    public function __construct(
        public readonly Closure $onDeferredRejection,
        public readonly ?string $batchTransactionConnection,
    ) {
    }

    /**
     * Keep the weight of sends that may have reached the transport.
     */
    public function attempting(array $offsets): void
    {
        $this->unknown += array_fill_keys($offsets, true);
    }

    /**
     * Record a confirmed write before running any post-send listeners.
     */
    public function accepted(array $offsets): void
    {
        $this->accepted += array_fill_keys($offsets, true);
        $this->rejected($offsets);
    }

    /**
     * Clear uncertainty when the transport confirms rejection.
     */
    public function rejected(array $offsets): void
    {
        foreach ($offsets as $offset) {
            unset($this->unknown[$offset]);
        }
    }

    /**
     * Withdraw only jobs this deferred operation definitely did not publish.
     */
    public function releaseRejected(Throwable $failure, array $jobs): void
    {
        $rejected = $this->failed($failure, $jobs)->rejected;
        if ($rejected !== []) {
            ($this->onDeferredRejection)($rejected);
        }
    }

    /**
     * Classify the original offsets after a failure.
     */
    public function failed(Throwable $failure, array $jobs): BatchEnqueueResult
    {
        return BatchEnqueueResult::failed($failure, $jobs, array_keys($this->accepted), array_keys($this->unknown));
    }
}
