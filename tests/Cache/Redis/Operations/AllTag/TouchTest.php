<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Matching\Argument;

class TouchTest extends RedisCacheTestCase
{
    public function testTouchRoundsTagScoreUpAtFractionalSecondInLuaMode(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockConnection();
        $connection->expects('evalWithShaCache')
            ->with(
                Argument::type('string'),
                ['prefix:mykey', 'prefix:_all:tag:users:entries'],
                [60, 1061, 'mykey'],
            )
            ->returns(1);

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

        [$store, $client] = $this->createClusterStore();
        $client->expects('expire')
            ->with('prefix:mykey', 60)
            ->returns(true);
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1061.0, 'mykey')
            ->returns(0);

        $this->assertTrue($store->allTagOps()->touch()->execute(
            'mykey',
            60,
            ['_all:tag:users:entries'],
        ));
    }

    public function testTouchReturnsFalseWhenClusterMembershipWriteFails(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('expire')->returns(true);
        $client->expects('zadd')->returns(false);

        $this->assertFalse($store->allTagOps()->touch()->execute(
            'mykey',
            60,
            ['_all:tag:users:entries'],
        ));
    }
}
