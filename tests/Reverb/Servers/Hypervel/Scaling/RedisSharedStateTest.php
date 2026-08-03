<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Tests\TestCase;
use Mockery as m;

class RedisSharedStateTest extends TestCase
{
    public function testPresenceSubscribeUsesOneAtomicScript(): void
    {
        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function (string $script, array $keys, array $arguments): bool {
                $this->assertStringContainsString("redis.call('INCR', KEYS[1])", $script);
                $this->assertStringContainsString("redis.call('INCR', KEYS[2])", $script);
                $this->assertCount(2, $keys);
                $this->assertSame([], $arguments);

                return true;
            })
            ->andReturn([1, 1]);

        $result = (new RedisSharedState($redis))->subscribe('app', 'presence-channel', 'user');

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
        $this->assertSame(1, $result->subscriptionCount);
    }

    public function testPresenceUnsubscribeUsesOneAtomicScript(): void
    {
        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function (string $script, array $keys, array $arguments): bool {
                $this->assertStringContainsString("redis.call('DECR', KEYS[1])", $script);
                $this->assertStringContainsString("redis.call('DECR', KEYS[2])", $script);
                $this->assertCount(2, $keys);
                $this->assertSame([], $arguments);

                return true;
            })
            ->andReturn([0, 0]);

        $result = (new RedisSharedState($redis))->unsubscribe('app', 'presence-channel', 'user');

        $this->assertTrue($result->channelVacated);
        $this->assertTrue($result->memberRemoved);
        $this->assertSame(0, $result->subscriptionCount);
    }

    public function testLockAcquisitionRequiresTheExactTransformedBoolean(): void
    {
        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('set')->twice()->andReturn(1, true);
        $state = new RedisSharedState($redis);

        $this->assertFalse($state->tryCacheMissLock('app', 'channel'));
        $this->assertTrue($state->tryCacheMissLock('app', 'channel'));
    }
}
