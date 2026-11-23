<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the Forever operation (intersection tags).
 */
class ForeverTest extends RedisCacheTestCase
{
    public function testForeverStoresValueWithTagsInPipelineMode(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('set')
            ->with('prefix:mykey', serialize('myvalue'))
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', -1.0, 'mykey')
            ->returns($connection->client())
            ->ordered();

        $connection->client()->expects('exec')
            ->returns([true, 0]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // ZADD for each tag with score -1
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', -1.0, 'mykey')
            ->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', -1.0, 'mykey')
            ->returns($connection->client());

        // SET for cache value
        $connection->client()->expects('set')
            ->with('prefix:mykey', serialize('myvalue'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries', '_all:tag:posts:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverWithEmptyTags(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        // SET for cache value only
        $connection->client()->expects('set')
            ->with('prefix:mykey', serialize('myvalue'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            []
        );

        $this->assertTrue($result);
    }

    public function testForeverInClusterModeUsesSequentialCommands(): void
    {
        [$store, $client] = $this->createClusterStore();

        $client->expects('set')
            ->with('prefix:mykey', serialize('myvalue'))
            ->returns(true)
            ->ordered();

        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', -1.0, 'mykey')
            ->returns(0)
            ->ordered();

        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverReturnsFalseOnFailure(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());
        $connection->client()->allows('set')->returns($connection->client());

        // SET returns false (failure)
        $connection->client()->expects('exec')
            ->returns([false, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertFalse($result);
    }

    public function testForeverReturnsFalseWhenPipelineMembershipWriteFails(): void
    {
        $connection = $this->mockConnection();
        $connection->client()->expects('pipeline')->returns($connection->client());
        $connection->client()->expects('set')->returns($connection->client());
        $connection->client()->expects('zadd')->returns($connection->client());
        $connection->client()->expects('exec')->returns([true, false]);

        $store = $this->createStore($connection);

        $this->assertFalse($store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        ));
    }

    public function testForeverInClusterModeDoesNotPublishTagsWhenTheValueWriteFails(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('set')->with('prefix:mykey', serialize('myvalue'))->returns(false);
        $client->expects('zadd')->never();

        $this->assertFalse($store->allTagOps()->forever()->execute('mykey', 'myvalue', ['_all:tag:users:entries']));
    }

    public function testForeverReturnsFalseWhenClusterMembershipWriteFails(): void
    {
        [$store, $client] = $this->createClusterStore();
        $client->expects('set')->returns(true);
        $client->expects('zadd')->returns(false);

        $this->assertFalse($store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        ));
    }

    public function testForeverUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('custom:_all:tag:users:entries', -1.0, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('set')
            ->with('custom:mykey', serialize('myvalue'))
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

        $store = $this->createStore($connection, 'custom:');
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            'myvalue',
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }

    public function testForeverWithNumericValue(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->allows('zadd')->returns($connection->client());

        // Numeric values are NOT serialized (optimization)
        $connection->client()->expects('set')
            ->with('prefix:mykey', 42)
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([true, 1]);

        $store = $this->createStore($connection);
        $result = $store->allTagOps()->forever()->execute(
            'mykey',
            42,
            ['_all:tag:users:entries']
        );

        $this->assertTrue($result);
    }
}
