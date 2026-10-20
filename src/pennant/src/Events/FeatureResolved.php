<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Events;

use Hypervel\Queue\SerializesModels;

class FeatureResolved
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $feature the feature name
     * @param mixed $scope the scope of the feature check
     * @param mixed $value the result value of the feature check
     */
    public function __construct(
        public string $feature,
        public mixed $scope,
        public mixed $value,
    ) {
    }
}
