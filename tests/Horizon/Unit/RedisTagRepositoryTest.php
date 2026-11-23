<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\Repositories\RedisTagRepository;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;

class RedisTagRepositoryTest extends UnitTestCase
{
    public function testUntaggedJobsDoNotQueryMonitoredTags(): void
    {
        $redis = Double::for(Factory::class)->strict();
        $redis->expects('connection')->never();

        $this->assertSame([], (new RedisTagRepository($redis))->monitored([]));
    }
}
