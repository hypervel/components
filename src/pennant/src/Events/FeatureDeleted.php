<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Events;

use Hypervel\Queue\SerializesModels;

class FeatureDeleted
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $feature the feature name
     * @param mixed $scope the scope of the feature deletion
     */
    public function __construct(
        public string $feature,
        public mixed $scope,
    ) {
    }
}
