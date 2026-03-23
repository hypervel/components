<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class CacheLocksFlushing
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly ?string $storeName,
    ) {
    }
}
