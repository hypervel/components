<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\Repositories\RedisMasterSupervisorRepository;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisMasterSupervisorRepositoryTest extends UnitTestCase
{
    public function testFindIgnoresNonArrayHmgetResponses(): void
    {
        // Redis can answer pipelined commands with false while it is still starting.
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturn(false);
        $connection->shouldReceive('pipeline')->once()->andReturn([false]);

        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->with('horizon')->andReturn($connection);

        $this->assertNull((new RedisMasterSupervisorRepository($redis))->find('master'));
    }
}
