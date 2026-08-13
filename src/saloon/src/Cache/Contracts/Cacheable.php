<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Cache\Contracts;

use DateInterval;
use DateTimeInterface;
use UnitEnum;

interface Cacheable
{
    /**
     * Get the cache duration.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int;

    /**
     * Get the cache store name.
     */
    public function cacheStore(): UnitEnum|string|null;
}
