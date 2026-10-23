<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\Repositories\RedisTagRepository;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisTagRepositoryTest extends UnitTestCase
{
    public function testUntaggedJobsDoNotQueryMonitoredTags(): void
    {
        $redis = m::mock(Factory::class);
        $redis->shouldNotReceive('connection');

        $this->assertSame([], (new RedisTagRepository($redis))->monitored([]));
    }
}
