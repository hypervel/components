<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the GetTagItems operation (union tags).
 */
class GetTagItemsTest extends RedisCacheTestCase
{
    public function testTagItemsReturnsKeyValuePairs(): void
    {
        $connection = $this->mockConnection();

        // GetTaggedKeys mock
        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(2);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['foo', 'bar']);

        // MGET to fetch values
        $connection->client()->expects('mget')
            ->with(['prefix:foo', 'prefix:bar'])
            ->returns([serialize('value1'), serialize('value2')]);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $items = iterator_to_array($redis->anyTagOps()->getTagItems()->execute(['users']));

        $this->assertSame(['foo' => 'value1', 'bar' => 'value2'], $items);
    }

    public function testTagItemsSkipsNonExistentKeys(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(3);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['foo', 'bar', 'baz']);

        // bar doesn't exist (returns null)
        $connection->client()->expects('mget')
            ->with(['prefix:foo', 'prefix:bar', 'prefix:baz'])
            ->returns([serialize('value1'), null, serialize('value3')]);

        $redis = $this->createStore($connection);
        $redis->setTagMode('any');
        $items = iterator_to_array($redis->anyTagOps()->getTagItems()->execute(['users']));

        $this->assertSame(['foo' => 'value1', 'baz' => 'value3'], $items);
    }

    public function testTagItemsDeduplicatesAcrossTags(): void
    {
        $connection = $this->mockConnection();

        // First tag 'users' has keys foo, bar
        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->returns(2);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->returns(['foo', 'bar']);

        // Second tag 'posts' has keys bar, baz (bar is duplicate)
        $connection->client()->expects('hlen')
            ->with('prefix:_any:tag:posts:entries')
            ->returns(2);
        $connection->client()->expects('hkeys')
            ->with('prefix:_any:tag:posts:entries')
            ->returns(['bar', 'baz']);

        // MGET called twice (batches of keys from each tag)
        $connection->client()->expects('mget')
            ->with(['prefix:foo', 'prefix:bar'])
            ->returns([serialize('v1'), serialize('v2')]);
        $connection->client()->expects('mget')
            ->with(['prefix:baz']) // bar already seen, only baz
            ->returns([serialize('v3')]);

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
