<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Events;

use Hypervel\Queue\SerializesModels;

class FeatureUpdated
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $feature the feature name
     * @param mixed $scope the scope of the feature update
     * @param mixed $value the new feature value
     */
    public function __construct(
        public string $feature,
        public mixed $scope,
        public mixed $value,
    ) {
    }
}
