<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hyperf\Redis\Redis;
use Hypervel\Cache\Contracts\RefreshableLock;
use Hypervel\Cache\RedisLock;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class CacheRedisLockTest extends TestCase
{
    public function testLockImplementsRefreshableLock()
    {
        [$lock] = $this->getLock();

        $this->assertInstanceOf(RefreshableLock::class, $lock);
    }

    public function testLockCanBeAcquired()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('set')
            ->once()
            ->with('foo', m::type('string'), ['EX' => 10, 'NX'])
            ->andReturn(true);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeAcquiredWithoutExpiration()
    {
        [$lock, $redis] = $this->getLock(seconds: 0);

        $redis->shouldReceive('setnx')
            ->once()
            ->with('foo', m::type('string'))
            ->andReturn(true);

        $this->assertTrue($lock->acquire());
    }

    public function testLockCanBeReleased()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('eval')
            ->once()
            ->with(m::type('string'), ['foo', $lock->owner()], 1)
            ->andReturn(1);

        $this->assertTrue($lock->release());
    }

    public function testLockCanBeForceReleased()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('del')
            ->once()
            ->with('foo');

        $lock->forceRelease();
        $this->assertTrue(true);
    }

    public function testRefreshExtendsLockTtl()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('eval')
            ->once()
            ->with(m::type('string'), ['foo', $lock->owner(), 10], 1)
            ->andReturn(1);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshWithCustomTtl()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('eval')
            ->once()
            ->with(m::type('string'), ['foo', $lock->owner(), 30], 1)
            ->andReturn(1);

        $this->assertTrue($lock->refresh(30));
    }

    public function testRefreshReturnsFalseWhenNotOwned()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('eval')
            ->once()
            ->with(m::type('string'), ['foo', $lock->owner(), 10], 1)
            ->andReturn(0);

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshWithZeroSecondsMakesLockPermanent()
    {
        [$lock, $redis] = $this->getLock(seconds: 0);

        // Should call PERSIST to remove expiry (make permanent)
        $redis->shouldReceive('eval')
            ->once()
            ->with(m::type('string'), ['foo', $lock->owner()], 1)
            ->andReturn(1);

        $this->assertTrue($lock->refresh());
    }

    public function testRefreshWithZeroSecondsReturnsFalseWhenNotOwned()
    {
        [$lock, $redis] = $this->getLock(seconds: 0);

        $redis->shouldReceive('eval')
            ->once()
            ->with(m::type('string'), ['foo', $lock->owner()], 1)
            ->andReturn(0);

        $this->assertFalse($lock->refresh());
    }

    public function testRefreshWithExplicitZeroMakesLockPermanent()
    {
        [$lock, $redis] = $this->getLock(seconds: 10);

        // Calling refresh(0) should use PERSIST even if lock was created with TTL
        $redis->shouldReceive('eval')
            ->once()
            ->with(m::type('string'), ['foo', $lock->owner()], 1)
            ->andReturn(1);

        $this->assertTrue($lock->refresh(0));
    }

    public function testGetRemainingLifetimeReturnsSeconds()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('ttl')
            ->once()
            ->with('foo')
            ->andReturn(5);

        $this->assertSame(5.0, $lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenKeyDoesNotExist()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('ttl')
            ->once()
            ->with('foo')
            ->andReturn(-2);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsNullWhenNoExpiry()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('ttl')
            ->once()
            ->with('foo')
            ->andReturn(-1);

        $this->assertNull($lock->getRemainingLifetime());
    }

    public function testGetRemainingLifetimeReturnsZeroWhenExpired()
    {
        [$lock, $redis] = $this->getLock();

        $redis->shouldReceive('ttl')
            ->once()
            ->with('foo')
            ->andReturn(0);

        $this->assertSame(0.0, $lock->getRemainingLifetime());
    }

    /**
     * Get a RedisLock instance with mocked dependencies.
     */
    protected function getLock(int $seconds = 10): array
    {
        $redis = m::mock(Redis::class);

        $lock = new RedisLock($redis, 'foo', $seconds);

        return [$lock, $redis];
    }
}
