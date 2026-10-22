<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox;

use Throwable;

readonly class OutboxBatchResult
{
    /**
     * Create a publication result using outbox row IDs, not transport IDs.
     *
     * @param array<int> $accepted
     * @param array<int> $deferred
     */
    public function __construct(
        public array $accepted = [],
        public array $deferred = [],
        public ?Throwable $failure = null,
    ) {
    }
}
