<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Events;

class FeaturesPurged
{
    /**
     * Create a new event instance.
     *
     * @param array<string> $features the feature names
     */
    public function __construct(
        public array $features,
    ) {
    }
}
