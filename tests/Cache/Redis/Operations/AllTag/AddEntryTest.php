<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Operations\AllTag\AddEntry;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

/**
 * Tests for the AddEntry operation.
 *
 * @internal
 * @coversNothing
 */
class AddEntryTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testAddEntryWithTtl(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 300, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 300, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithZeroTtlStoresNegativeOne(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithNegativeTtlStoresNegativeOne(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', -5, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithUpdateWhenNxCondition(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'NX');
    }

    /**
     * @test
     */
    public function testAddEntryWithUpdateWhenXxCondition(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['XX'], -1, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'XX');
    }

    /**
     * @test
     */
    public function testAddEntryWithUpdateWhenGtCondition(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['GT'], now()->timestamp + 60, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, ['_all:tag:users:entries'], 'GT');
    }

    /**
     * @test
     */
    public function testAddEntryWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 60, 'mykey')
            ->andReturn($connection);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', now()->timestamp + 60, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1, 1]);

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, ['_all:tag:users:entries', '_all:tag:posts:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryWithEmptyTagsArrayDoesNothing(): void
    {
        $connection = $this->mockConnection();

        // No pipeline or zadd calls should be made
        $connection->shouldNotReceive('pipeline');
        $connection->shouldNotReceive('zadd');

        $store = $this->createStore($connection);
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 60, []);
    }

    /**
     * @test
     */
    public function testAddEntryUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        $connection->shouldReceive('pipeline')->once()->andReturn($connection);

        $connection->shouldReceive('zadd')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn($connection);

        $connection->shouldReceive('exec')
            ->once()
            ->andReturn([1]);

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new AddEntry($store->getContext());

        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeUsesSequentialCommands(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        // Should use sequential zadd calls directly on connection
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', now()->timestamp + 300, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 300, ['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeWithMultipleTags(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should NOT use pipeline in cluster mode
        $connection->shouldNotReceive('pipeline');

        // Should use sequential zadd calls for each tag
        $expectedScore = now()->timestamp + 60;
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', $expectedScore, 'mykey')
            ->andReturn(1);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:posts:entries', $expectedScore, 'mykey')
            ->andReturn(1);
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:comments:entries', $expectedScore, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 60, ['_all:tag:users:entries', '_all:tag:posts:entries', '_all:tag:comments:entries']);
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeWithUpdateWhenFlag(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Should use zadd with NX flag as array (phpredis requires array for options)
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', ['NX'], -1, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 0, ['_all:tag:users:entries'], 'NX');
    }

    /**
     * @test
     */
    public function testAddEntryClusterModeWithZeroTtlStoresNegativeOne(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Score should be -1 for forever items (TTL = 0)
        $connection->shouldReceive('zadd')
            ->once()
            ->with('prefix:_all:tag:users:entries', -1, 'mykey')
            ->andReturn(1);

        $operation = new AddEntry($store->getContext());
        $operation->execute('mykey', 0, ['_all:tag:users:entries']);
    }
}
