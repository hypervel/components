<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;

class CacheFailedOver
{
    /**
     * Create a new event instance.
     *
     * @param null|string $storeName The backing cache store that failed
     * @param null|string $failoverStoreName The configured failover store handling the operation
     */
    public function __construct(
        public readonly ?string $storeName,
        public readonly Throwable $exception,
        public readonly ?string $failoverStoreName = null,
    ) {
    }
}
