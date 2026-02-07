<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Redis\Pool\RedisPool as StandaloneRedisPool;

/**
 * @deprecated Use \Hypervel\Redis\Pool\RedisPool instead.
 */
class RedisPool extends StandaloneRedisPool
{
}
