<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Decrement operation (intersection tags).
 */
class DecrementTest extends RedisCacheTestCase
{
    public function testDecrementWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('decrby')
            ->with('prefix:counter', 1)
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('exec')
            ->returns([5, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(5, $result);
    }

    public function testDecrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->returns($connection->client());

        $connection->client()->expects('decrby')
            ->with('prefix:counter', 10)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([-5, 0]); // 0 means tag membership already existed

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            10,
            ['_all:tag:users:entries']
        );

        $this->assertSame(-5, $result);
    }

    public function testDecrementWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // ZADD NX for each tag
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', ['NX'], -1, 'counter')
            ->returns($connection->client());

        $connection->client()->expects('decrby')
            ->with('prefix:counter', 1)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([9, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertSame(9, $result);
    }

    public function testDecrementWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // No ZADD calls expected
        $connection->client()->expects('decrby')
            ->with('prefix:counter', 1)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([-1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            []
        );

        $this->assertSame(-1, $result);
    }

    public function testDecrementInClusterModeUsesSequentialCommands(): void
    {
        [$store, $client] = $this->createClusterStore();

        $client->expects('decrby')
            ->with('prefix:counter', 1)
            ->returns(0)
            ->ordered();
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->returns(1)
            ->ordered();

        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(0, $result);
    }

    public function testDecrementInClusterModeDoesNotPublishTagsWhenTheCounterFails(): void
    {
        [$store, $client] = $this->createClusterStore();

        $client->expects('decrby')->with('prefix:counter', 1)->returns(false);
        $client->expects('zadd')->never();

        $this->assertFalse($store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        ));
    }

    public function testDecrementReturnsFalseOnPipelineFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());
        $connection->client()->allows('decrby')->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns(false);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->decrement()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }
}
