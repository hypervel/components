<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Throwable;

/** @internal */
class BatchEnqueueResult
{
    /**
     * Describe a bulk delivery by the offsets supplied by the batch.
     * A successful bulk call needs no individual outcomes.
     *
     * @param list<int> $accepted
     * @param list<int> $unknown
     * @param list<int> $rejected
     */
    public function __construct(
        public readonly ?Throwable $failure = null,
        public readonly array $accepted = [],
        public readonly array $unknown = [],
        public readonly array $rejected = [],
    ) {
    }

    /**
     * Classify unattempted members as rejected, preserving earlier confirmed or uncertain sends.
     */
    public static function failed(Throwable $failure, array $jobs, array $accepted = [], array $unknown = []): self
    {
        return new self($failure, $accepted, $unknown, array_values(array_diff(array_keys($jobs), $accepted, $unknown)));
    }
}
