<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Redis;

use Hypervel\Redis\RedisProxy;
use UnitEnum;

interface Factory
{
    /**
     * Get a Redis connection by name.
     */
    public function connection(UnitEnum|string|null $name = null): RedisProxy;
}
