<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Increment operation (union tags).
 */
class IncrementTest extends RedisCacheTestCase
{
    public function testIncrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        // Lua script returns the incremented value
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                $this->assertStringContainsString('INCRBY', $script);
                $this->assertStringContainsString('TTL', $script);
                $this->assertStringContainsString((string) StoreContext::MAX_EXPIRY, $script);
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(15); // New value after increment

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->increment()->execute('counter', 5, ['stats']);
        $this->assertSame(15, $result);
    }

    public function testIncrementWithTagsUpdatesMetadataAfterAClusterCounterSucceeds(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->expects('multi')->twice()->andReturnSelf();
        $connection->expects('incrBy')->with('prefix:counter', 5)->andReturnSelf();
        $connection->expects('ttl')->with('prefix:counter')->andReturnSelf();
        $connection->expects('exec')->twice()->andReturn([15, -1], []);
        $connection->expects('smembers')->with('prefix:counter:_any:tags')->andReturn([]);
        $connection->expects('del')->with('prefix:counter:_any:tags')->andReturnSelf();
        $connection->expects('sadd')->with('prefix:counter:_any:tags', 'stats')->andReturnSelf();
        $connection->expects('hSet')
            ->with('prefix:_any:tag:stats:entries', 'counter', StoreContext::TAG_FIELD_VALUE)
            ->andReturn(1);
        $connection->expects('zadd')
            ->with('prefix:_any:tag:registry', ['GT'], StoreContext::MAX_EXPIRY, 'stats')
            ->andReturn(1);

        $this->assertSame(15, $redis->anyTagOps()->increment()->execute('counter', 5, ['stats']));
    }

    public function testIncrementWithTagsReturnsFalseWithoutMetadataWhenClusterTransactionFails(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->expects('multi')->andReturnSelf();
        $connection->expects('incrBy')->with('prefix:counter', 5)->andReturnSelf();
        $connection->expects('ttl')->with('prefix:counter')->andReturnSelf();
        $connection->expects('exec')->andReturnFalse();
        $connection->shouldNotReceive('smembers');
        $connection->shouldNotReceive('sadd');
        $connection->shouldNotReceive('hSet');
        $connection->shouldNotReceive('zadd');

        $this->assertFalse($redis->anyTagOps()->increment()->execute('counter', 5, ['stats']));
    }

    public function testIncrementWithTagsReturnsFalseWithoutMetadataWhenClusterCounterFails(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->expects('multi')->andReturnSelf();
        $connection->expects('incrBy')->with('prefix:counter', 5)->andReturnSelf();
        $connection->expects('ttl')->with('prefix:counter')->andReturnSelf();
        $connection->expects('exec')->andReturn([false, -1]);
        $connection->shouldNotReceive('smembers');
        $connection->shouldNotReceive('sadd');
        $connection->shouldNotReceive('hSet');
        $connection->shouldNotReceive('zadd');

        $this->assertFalse($redis->anyTagOps()->increment()->execute('counter', 5, ['stats']));
    }
}
