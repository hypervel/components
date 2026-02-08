<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Support\LazyCollection;
use Hypervel\Cache\Redis\Operations\AllTag\GetEntries;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the GetEntries operation.
 *
 * @internal
 * @coversNothing
 */
class GetEntriesTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testGetEntriesReturnsLazyCollection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['key1' => 1, 'key2' => 2];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', 0, '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertInstanceOf(LazyCollection::class, $entries);
        $this->assertSame(['key1', 'key2'], $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesWithEmptyTagReturnsEmptyCollection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return [];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', 0, '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame([], $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesWithMultipleTags(): void
    {
        $connection = $this->mockConnection();

        // First tag
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['user_key1' => 1, 'user_key2' => 2];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', 0, '*', 1000)
            ->andReturnNull();

        // Second tag
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:posts:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['post_key1' => 1];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:posts:entries', 0, '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries']);

        // Should combine entries from both tags
        $this->assertSame(['user_key1', 'user_key2', 'post_key1'], $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesDeduplicatesWithinTag(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['key1' => 1, 'key2' => 2];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', 0, '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        // array_unique is applied within each tag
        $this->assertCount(2, $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesHandlesNullScanResult(): void
    {
        $connection = $this->mockConnection();
        // zScan returns null/false when done or empty
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame([], $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesHandlesFalseScanResult(): void
    {
        $connection = $this->mockConnection();
        // zScan can return false in some cases
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturn(false);

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame([], $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesWithEmptyTagIdsArrayReturnsEmptyCollection(): void
    {
        $connection = $this->mockConnection();
        // No zScan calls should be made
        $connection->shouldNotReceive('zScan');

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute([]);

        $this->assertSame([], $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('zScan')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['key1' => 1];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries', 0, '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame(['key1'], $entries->all());
    }

    /**
     * @test
     */
    public function testGetEntriesHandlesPaginatedResults(): void
    {
        $connection = $this->mockConnection();

        // First page
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 123; // Non-zero cursor indicates more data

                return ['key1' => 1, 'key2' => 2];
            });

        // Second page (returns remaining entries)
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', 123, '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0; // Zero cursor indicates end

                return ['key3' => 3];
            });

        // Final call with cursor 0 returns null (phpredis behavior)
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', 0, '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries']);

        $this->assertSame(['key1', 'key2', 'key3'], $entries->all());
    }

    /**
     * @test
     *
     * Documents that deduplication is per-tag, not global. If the same key
     * exists in multiple tags, it will appear multiple times in the result.
     * This is intentional - the Flush operation handles this gracefully
     * (deleting a non-existent key is a no-op).
     */
    public function testGetEntriesDoesNotDeduplicateAcrossTags(): void
    {
        $connection = $this->mockConnection();

        // First tag has 'shared_key'
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['shared_key' => 1, 'user_only' => 2];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', 0, '*', 1000)
            ->andReturnNull();

        // Second tag also has 'shared_key'
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:posts:entries', m::any(), '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) {
                $cursor = 0;

                return ['shared_key' => 1, 'post_only' => 2];
            });
        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:posts:entries', 0, '*', 1000)
            ->andReturnNull();

        $store = $this->createStore($connection);
        $operation = new GetEntries($store->getContext());

        $entries = $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries']);

        // 'shared_key' appears twice - once from each tag
        $this->assertSame(['shared_key', 'user_only', 'shared_key', 'post_only'], $entries->all());
    }
}
