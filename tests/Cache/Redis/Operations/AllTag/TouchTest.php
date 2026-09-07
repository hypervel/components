<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

class TouchTest extends RedisCacheTestCase
{
    public function testTouchRoundsTagScoreUpAtFractionalSecondInLuaMode(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->with(
                m::type('string'),
                ['prefix:mykey', 'prefix:_all:tag:users:entries'],
                [60, 1061, 'mykey'],
            )
            ->andReturn(1);

        $store = $this->createStore($connection);

        $this->assertTrue($store->allTagOps()->touch()->execute(
            'mykey',
            60,
            ['_all:tag:users:entries'],
        ));
    }

    public function testTouchRoundsTagScoreUpAtFractionalSecondInClusterMode(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        [$store, , $connection] = $this->createClusterStore();
        $connection->shouldReceive('expire')
            ->once()
            ->with('prefix:mykey', 60)
            ->andReturn(true);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', 1061, 'mykey')
            ->andReturn(0);

        $this->assertTrue($store->allTagOps()->touch()->execute(
            'mykey',
            60,
            ['_all:tag:users:entries'],
        ));
    }

    public function testTouchReturnsFalseWhenClusterMembershipWriteFails(): void
    {
        [$store, , $connection] = $this->createClusterStore();
        $connection->shouldReceive('expire')->once()->andReturn(true);
        $connection->shouldReceive('zadd')->once()->andReturn(false);

        $this->assertFalse($store->allTagOps()->touch()->execute(
            'mykey',
            60,
            ['_all:tag:users:entries'],
        ));
    }
}
