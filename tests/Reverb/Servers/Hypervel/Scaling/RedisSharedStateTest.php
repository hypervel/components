<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Tests\TestCase;
use Mockery as m;

class RedisSharedStateTest extends TestCase
{
    public function testRecoveryPatternMatchesGeneratedSharedStateKeysOnly(): void
    {
        $state = new class(m::mock(RedisProxy::class)) extends RedisSharedState {
            /**
             * Get the generated shared-state keys.
             *
             * @return list<string>
             */
            public function keysForTest(string $appId, string $channel, string $userId): array
            {
                return [
                    $this->key(self::CONNECTION_KEY_TYPE, $appId),
                    $this->channelKey($appId, $channel),
                    $this->userKey($appId, $channel, $userId),
                    $this->key(self::SUBSCRIPTION_COUNT_LOCK_KEY_TYPE, $appId, $channel),
                    $this->key(self::CACHE_MISS_LOCK_KEY_TYPE, $appId, $channel),
                    $this->key(self::CHANNEL_SMOOTHING_KEY_TYPE, $appId, $channel),
                    $this->key(self::MEMBER_SMOOTHING_KEY_TYPE, $appId, $channel, $userId),
                ];
            }
        };

        foreach ($state->keysForTest('app', 'presence-channel', 'user') as $key) {
            $this->assertTrue(fnmatch(RedisSharedState::KEY_PATTERN, $key), $key);
        }

        foreach ([
            'reverb:webhook:{app}:buffer',
            'reverb:webhook:{app}:flush',
            'reverb:webhook:{app}:processing',
            'reverb:message:123',
            'unrelated:key',
        ] as $key) {
            $this->assertFalse(fnmatch(RedisSharedState::KEY_PATTERN, $key), $key);
        }
    }

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
