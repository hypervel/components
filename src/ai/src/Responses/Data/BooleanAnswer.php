<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

class BooleanAnswer extends Answer
{
    /**
     * Create a new boolean answer instance.
     *
     * @param float $probability the probability that the answer is "true"
     */
    public function __construct(
        public readonly float $probability,
    ) {
    }

    /**
     * Determine if the probability meets the given threshold.
     */
    public function isTrue(float $threshold = 0.5): bool
    {
        return $this->probability >= $threshold;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return ['probability' => $this->probability];
    }
}
