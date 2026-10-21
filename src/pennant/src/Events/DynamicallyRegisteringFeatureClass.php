<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Events;

class DynamicallyRegisteringFeatureClass
{
    /**
     * Create a new event instance.
     *
     * @param class-string $feature the feature class
     */
    public function __construct(
        public string $feature,
    ) {
    }
}
