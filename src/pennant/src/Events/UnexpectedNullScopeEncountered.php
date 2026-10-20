<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Events;

class UnexpectedNullScopeEncountered
{
    /**
     * Create a new event instance.
     *
     * @param string $feature the feature name
     */
    public function __construct(
        public string $feature,
    ) {
    }
}
