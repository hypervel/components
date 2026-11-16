<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Increment operation (intersection tags).
 */
class IncrementTest extends RedisCacheTestCase
{
    public function testIncrementWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('incrby')
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
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(5, $result);
    }

    public function testIncrementWithCustomValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->returns($connection->client());

        $connection->client()->expects('incrby')
            ->with('prefix:counter', 10)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([15, 0]); // 0 means tag membership already existed

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            10,
            ['_all:tag:users:entries']
        );

        $this->assertSame(15, $result);
    }

    public function testIncrementWithMultipleTags(): void
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

        $connection->client()->expects('incrby')
            ->with('prefix:counter', 1)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertSame(1, $result);
    }

    public function testIncrementWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // No ZADD calls expected
        $connection->client()->expects('incrby')
            ->with('prefix:counter', 1)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            []
        );

        $this->assertSame(1, $result);
    }

    public function testIncrementInClusterModeUsesSequentialCommands(): void
    {
        [$store, $client] = $this->createClusterStore();

        $client->expects('incrby')
            ->with('prefix:counter', 1)
            ->returns(10)
            ->ordered();
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'counter')
            ->returns(1)
            ->ordered();

        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertSame(10, $result);
    }

    public function testIncrementInClusterModeDoesNotPublishTagsWhenTheCounterFails(): void
    {
        [$store, $client] = $this->createClusterStore();

        $client->expects('incrby')->with('prefix:counter', 1)->returns(false);
        $client->expects('zadd')->never();

        $this->assertFalse($store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        ));
    }

    public function testIncrementReturnsFalseOnPipelineFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());
        $connection->client()->allows('incrby')->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns(false);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->increment()->execute(
            'counter',
            1,
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }
}
