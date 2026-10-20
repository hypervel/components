<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Contracts;

interface FeatureScopeable
{
    /**
     * Cast the object to a feature scope identifier for the given driver.
     */
    public function toFeatureIdentifier(string $driver): mixed;
}
