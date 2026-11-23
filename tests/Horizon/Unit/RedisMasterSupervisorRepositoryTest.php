<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\Repositories\RedisMasterSupervisorRepository;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;

class RedisMasterSupervisorRepositoryTest extends UnitTestCase
{
    public function testFindIgnoresNonArrayHmgetResponses(): void
    {
        // Redis can answer pipelined commands with false while it is still starting.
        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('isCluster')->returns(false);
        $connection->expects('pipeline')->returns([false]);

        $redis = Double::for(Factory::class)->strict();
        $redis->allows('connection')->with('horizon')->returns($connection);

        $this->assertNull((new RedisMasterSupervisorRepository($redis))->find('master'));
    }
}
