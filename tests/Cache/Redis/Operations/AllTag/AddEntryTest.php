<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Operations\AllTag\AddEntry;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the AddEntry operation.
 */
class AddEntryTest extends RedisCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
    }

    public function testAddEntryWithTtl(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1301.0, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 300, ['_all:tag:users:entries']);
    }

    public function testAddEntryWithZeroTtlStoresNegativeOne(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', -1.0, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }

    public function testAddEntryWithNegativeTtlStoresNegativeOne(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', -1.0, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', -5, ['_all:tag:users:entries']);
    }

    public function testAddEntryWithUpdateWhenNxCondition(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'NX');
    }

    public function testAddEntryWithUpdateWhenXxCondition(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['XX'], -1, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'XX');
    }

    public function testAddEntryWithUpdateWhenGtCondition(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['GT'], 1061, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, ['_all:tag:users:entries'], 'GT');
    }

    public function testAddEntryWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1061.0, 'mykey')
            ->returns($connection->client());
        $connection->client()->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', 1061.0, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1, 1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, ['_all:tag:users:entries', '_all:tag:posts:entries']);
    }

    public function testAddEntryWithEmptyTagsArrayDoesNothing(): void
    {
        $connection = $this->mockConnection();

        // No pipeline or zadd calls should be made
        $connection->client()->expects('pipeline')->never();
        $connection->client()->expects('zadd')->never();

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, []);
    }

    public function testAddEntryUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $connection->client()->expects('pipeline')->returns($connection->client());

        $connection->client()->expects('zadd')
            ->with('custom_prefix:_all:tag:users:entries', -1.0, 'mykey')
            ->returns($connection->client());

        $connection->client()->expects('exec')
            ->returns([1]);

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }

    public function testAddEntryClusterModeUsesSequentialCommands(): void
    {
        [$store, $client] = $this->createClusterStore();

        // Should use sequential zadd calls directly on connection
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', 1301.0, 'mykey')
            ->returns(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 300, ['_all:tag:users:entries']);
    }

    public function testAddEntryClusterModeWithMultipleTags(): void
    {
        [$store, $client] = $this->createClusterStore();

        // Should use sequential zadd calls for each tag
        $expectedScore = 1061.0;
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->returns(1);
        $client->expects('zadd')
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->returns(1);
        $client->expects('zadd')
            ->with('prefix:_all:tag:comments:entries', $expectedScore, 'mykey')
            ->returns(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 60, ['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    public function testAddEntryClusterModeWithUpdateWhenFlag(): void
    {
        [$store, $client] = $this->createClusterStore();

        // Should use zadd with NX flag as array (phpredis requires array for options)
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'mykey')
            ->returns(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'NX');
    }

    public function testAddEntryClusterModeWithZeroTtlStoresNegativeOne(): void
    {
        [$store, $client] = $this->createClusterStore();

        // Score should be -1 for forever items (TTL = 0)
        $client->expects('zadd')
            ->with('prefix:_all:tag:users:entries', -1.0, 'mykey')
            ->returns(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }
}
