<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Decrement operation (union tags).
 */
class DecrementTest extends RedisCacheTestCase
{
    public function testDecrementWithTagsReturnsNewValue(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function ($script, $keys, $args) {
                $this->assertStringContainsString('DECRBY', $script);
                $this->assertStringContainsString('TTL', $script);
                $this->assertStringContainsString((string) StoreContext::MAX_EXPIRY, $script);
                $this->assertCount(2, $keys);

                return true;
            })
            ->andReturn(5); // New value after decrement

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $result = $redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']);
        $this->assertSame(5, $result);
    }

    public function testDecrementWithTagsUpdatesMetadataAfterAClusterCounterSucceeds(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->expects('multi')->twice()->andReturnSelf();
        $connection->expects('decrBy')->with('prefix:counter', 5)->andReturnSelf();
        $connection->expects('ttl')->with('prefix:counter')->andReturnSelf();
        $connection->expects('exec')->twice()->andReturn([5, -1], []);
        $connection->expects('smembers')->with('prefix:counter:_any:tags')->andReturn([]);
        $connection->expects('del')->with('prefix:counter:_any:tags')->andReturnSelf();
        $connection->expects('sadd')->with('prefix:counter:_any:tags', 'stats')->andReturnSelf();
        $connection->expects('hSet')
            ->with('prefix:_any:tag:stats:entries', 'counter', StoreContext::TAG_FIELD_VALUE)
            ->andReturn(1);
        $connection->expects('zadd')
            ->with('prefix:_any:tag:registry', ['GT'], StoreContext::MAX_EXPIRY, 'stats')
            ->andReturn(1);

        $this->assertSame(5, $redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']));
    }

    public function testDecrementWithTagsReturnsFalseWithoutMetadataWhenClusterTransactionFails(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->expects('multi')->andReturnSelf();
        $connection->expects('decrBy')->with('prefix:counter', 5)->andReturnSelf();
        $connection->expects('ttl')->with('prefix:counter')->andReturnSelf();
        $connection->expects('exec')->andReturnFalse();
        $connection->shouldNotReceive('smembers');
        $connection->shouldNotReceive('sadd');
        $connection->shouldNotReceive('hSet');
        $connection->shouldNotReceive('zadd');

        $this->assertFalse($redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']));
    }

    public function testDecrementWithTagsReturnsFalseWithoutMetadataWhenClusterCounterFails(): void
    {
        [$redis, , $connection] = $this->createClusterStore(tagMode: 'any');

        $connection->expects('multi')->andReturnSelf();
        $connection->expects('decrBy')->with('prefix:counter', 5)->andReturnSelf();
        $connection->expects('ttl')->with('prefix:counter')->andReturnSelf();
        $connection->expects('exec')->andReturn([false, -1]);
        $connection->shouldNotReceive('smembers');
        $connection->shouldNotReceive('sadd');
        $connection->shouldNotReceive('hSet');
        $connection->shouldNotReceive('zadd');

        $this->assertFalse($redis->anyTagOps()->decrement()->execute('counter', 5, ['stats']));
    }
}
