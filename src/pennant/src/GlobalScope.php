<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

use Hypervel\Pennant\Contracts\FeatureScopeSerializeable;

class GlobalScope implements FeatureScopeSerializeable
{
    /**
     * Serialize the feature scope for storage.
     */
    public function featureScopeSerialize(): string
    {
        return '__hypervel_global';
    }
}
