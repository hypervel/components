<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Features;

use Closure;
use Hypervel\Support\Collection;

/**
 * Collection for deferred testing feature callbacks.
 */
final class FeaturesCollection extends Collection
{
    /**
     * Handle attribute callbacks.
     */
    public function handle(?Closure $callback = null): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $this->each($callback ?? static fn ($attribute) => value($attribute));
    }
}
