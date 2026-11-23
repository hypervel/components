<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Container\Container;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class RedisSharedStateTest extends TestCase
{
    use VerifiesDoubles;

    public function testRecoveryPatternMatchesGeneratedSharedStateKeysOnly(): void
    {
        $state = new class(Double::for(RedisProxy::class)->strict()) extends RedisSharedState {
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
        [$redis, $connection] = $this->redisConnection();
        $connection->expects('evalWithShaCache')
            ->with(Argument::capture($script), Argument::capture($keys), [])
            ->returns([1, 1]);

        $result = (new RedisSharedState($redis))->subscribe('app', 'presence-channel', 'user');

        $this->assertTrue($result->channelOccupied);
        $this->assertTrue($result->memberAdded);
        $this->assertSame(1, $result->subscriptionCount);
        $this->assertStringContainsString("redis.call('INCR', KEYS[1])", $script);
        $this->assertStringContainsString("redis.call('INCR', KEYS[2])", $script);
        $this->assertCount(2, $keys);
    }

    public function testPresenceUnsubscribeUsesOneAtomicScript(): void
    {
        [$redis, $connection] = $this->redisConnection();
        $connection->expects('evalWithShaCache')
            ->with(Argument::capture($script), Argument::capture($keys), [])
            ->returns([0, 0]);

        $result = (new RedisSharedState($redis))->unsubscribe('app', 'presence-channel', 'user');

        $this->assertTrue($result->channelVacated);
        $this->assertTrue($result->memberRemoved);
        $this->assertSame(0, $result->subscriptionCount);
        $this->assertStringContainsString("redis.call('DECR', KEYS[1])", $script);
        $this->assertStringContainsString("redis.call('DECR', KEYS[2])", $script);
        $this->assertCount(2, $keys);
    }

    /**
     * Create a proxy that forwards script calls to a borrowed connection.
     *
     * @return array{RedisProxy, DoubleInterface&RedisConnection}
     */
    protected function redisConnection(): array
    {
        $connection = Double::for(RedisConnection::class)->strict();
        $connection->allows('getConnection')->returns($connection);
        $connection->allows('shouldTransform');
        $connection->allows('getEventDispatcher')->returns(null);
        $connection->allows('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $manager = Double::for(new PoolManager(new Container))->passthru();
        $manager->allows('pool')->with('reverb')->returns($pool);

        return [new RedisProxy($manager, 'reverb', new RedisSentinelFactory), $connection];
    }
}
