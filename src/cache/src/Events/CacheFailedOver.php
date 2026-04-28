<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;

class CacheFailedOver
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly ?string $storeName,
        public readonly Throwable $exception,
    ) {
    }
}
