<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;

abstract class UnitTestCase extends TestCase
{
    /**
     * Create a Redis factory for the given Horizon connection.
     */
    protected function redisFactory(RedisProxy $connection): Factory
    {
        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->once()->with('horizon')->andReturn($connection);

        return $redis;
    }
}
