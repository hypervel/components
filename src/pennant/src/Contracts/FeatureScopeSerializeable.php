<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Contracts;

interface FeatureScopeSerializeable
{
    /**
     * Serialize the feature scope for storage.
     */
    public function featureScopeSerialize(): string;
}
