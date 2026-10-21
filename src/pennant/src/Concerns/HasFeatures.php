<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Concerns;

use Hypervel\Pennant\Feature;
use Hypervel\Pennant\PendingScopedFeatureInteraction;

trait HasFeatures
{
    /**
     * Get a scoped feature interaction for the class.
     */
    public function features(?string $store = null): PendingScopedFeatureInteraction
    {
        return Feature::store($store)->for($this);
    }
}
