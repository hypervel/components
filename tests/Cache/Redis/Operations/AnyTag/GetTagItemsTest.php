<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the GetTagItems operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class GetTagItemsTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testTagItemsReturnsKeyValuePairs(): void
    {
        $connection = $this->mockConnection();

        // GetTaggedKeys mock
        $connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(2);
        $connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['foo', 'bar']);

        // MGET to fetch values
        $connection->shouldReceive('mget')
            ->once()
            ->with(['prefix:foo', 'prefix:bar'])
            ->andReturn([serialize('value1'), serialize('value2')]);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $items = iterator_to_array($redis->anyTagOps()->getTagItems()->execute(['users']));

        $this->assertSame(['foo' => 'value1', 'bar' => 'value2'], $items);
    }

    /**
     * @test
     */
    public function testTagItemsSkipsNonExistentKeys(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(3);
        $connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['foo', 'bar', 'baz']);

        // bar doesn't exist (returns null)
        $connection->shouldReceive('mget')
            ->once()
            ->with(['prefix:foo', 'prefix:bar', 'prefix:baz'])
            ->andReturn([serialize('value1'), null, serialize('value3')]);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $items = iterator_to_array($redis->anyTagOps()->getTagItems()->execute(['users']));

        $this->assertSame(['foo' => 'value1', 'baz' => 'value3'], $items);
    }

    /**
     * @test
     */
    public function testTagItemsDeduplicatesAcrossTags(): void
    {
        $connection = $this->mockConnection();

        // First tag 'users' has keys foo, bar
        $connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(2);
        $connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['foo', 'bar']);

        // Second tag 'posts' has keys bar, baz (bar is duplicate)
        $connection->shouldReceive('hlen')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(2);
        $connection->shouldReceive('hkeys')
            ->once()
            ->with('prefix:_any:tag:posts:entries')
            ->andReturn(['bar', 'baz']);

        // MGET called twice (batches of keys from each tag)
        $connection->shouldReceive('mget')
            ->once()
            ->with(['prefix:foo', 'prefix:bar'])
            ->andReturn([serialize('v1'), serialize('v2')]);
        $connection->shouldReceive('mget')
            ->once()
            ->with(['prefix:baz']) // bar already seen, only baz
            ->andReturn([serialize('v3')]);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $items = iterator_to_array($redis->anyTagOps()->getTagItems()->execute(['users', 'posts']));

        // bar should only appear once
        $this->assertCount(3, $items);
        $this->assertSame('v1', $items['foo']);
        $this->assertSame('v2', $items['bar']);
        $this->assertSame('v3', $items['baz']);
    }
}
