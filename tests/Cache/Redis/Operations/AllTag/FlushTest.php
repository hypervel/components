<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hyperf\Collection\LazyCollection;
use Hypervel\Cache\Redis\Operations\AllTag\Flush;
use Hypervel\Cache\Redis\Operations\AllTag\GetEntries;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for the Flush operation.
 *
 * @internal
 * @coversNothing
 */
class FlushTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testFlushDeletesCacheEntriesAndTagSets(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock GetEntries to return cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection(['key1', 'key2']));

        // Should delete the cache entries (with prefix) via pipeline
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2);

        // Should delete the tag sorted set
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries'], ['users']);
    }

    /**
     * @test
     */
    public function testFlushWithMultipleTagsDeletesAllEntriesAndTagSets(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock GetEntries to return cache keys from multiple tags
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries', '_all:tag:posts:entries'])
            ->andReturn(new LazyCollection(['user_key1', 'user_key2', 'post_key1']));

        // Should delete all cache entries (with prefix) via pipeline
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:user_key1', 'prefix:user_key2', 'prefix:post_key1')
            ->andReturn(3);

        // Should delete both tag sorted sets in a single batched call
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries', 'prefix:_all:tag:posts:entries')
            ->andReturn(2);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries'], ['users', 'posts']);
    }

    /**
     * @test
     */
    public function testFlushWithNoEntriesStillDeletesTagSets(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return empty collection
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection([]));

        // No cache entries to delete
        $connection->shouldNotReceive('del')->with(m::pattern('/^prefix:(?!tag:)/'));

        // Should still delete the tag sorted set
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries'], ['users']);
    }

    /**
     * @test
     */
    public function testFlushChunksLargeEntrySets(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Create more than CHUNK_SIZE (1000) entries
        $entries = [];
        for ($i = 1; $i <= 1500; ++$i) {
            $entries[] = "key{$i}";
        }

        // Mock GetEntries to return many cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection($entries));

        // First chunk: 1000 entries (via pipeline on client)
        $firstChunkArgs = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunkArgs[] = "prefix:key{$i}";
        }
        $client->shouldReceive('del')
            ->once()
            ->with(...$firstChunkArgs)
            ->andReturn(1000);

        // Second chunk: 500 entries (via pipeline on client)
        $secondChunkArgs = [];
        for ($i = 1001; $i <= 1500; ++$i) {
            $secondChunkArgs[] = "prefix:key{$i}";
        }
        $client->shouldReceive('del')
            ->once()
            ->with(...$secondChunkArgs)
            ->andReturn(500);

        // Should delete the tag sorted set
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries'], ['users']);
    }

    /**
     * @test
     */
    public function testFlushUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock GetEntries to return cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection(['mykey']));

        // Should use custom prefix for cache entries (via pipeline on client)
        $client->shouldReceive('del')
            ->once()
            ->with('custom_prefix:mykey')
            ->andReturn(1);

        // Should use custom prefix for tag sorted set
        $connection->shouldReceive('del')
            ->once()
            ->with('custom_prefix:_all:tag:users:entries')
            ->andReturn(1);

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries'], ['users']);
    }

    /**
     * @test
     */
    public function testFlushWithEmptyTagIdsAndTagNames(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries - will be called with empty array
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with([])
            ->andReturn(new LazyCollection([]));

        // No del calls should be made for entries or tags
        $connection->shouldNotReceive('del');

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute([], []);
    }

    /**
     * @test
     */
    public function testFlushTagKeyFormat(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->andReturn(new LazyCollection([]));

        // Verify the tag key format: "tag:{name}:entries"
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:my-special-tag:entries')
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:my-special-tag:entries'], ['my-special-tag']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeUsesSequentialDel(): void
    {
        [$store, $clusterClient, $clusterConnection] = $this->createClusterStore();

        // Mock GetEntries to return cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection(['key1', 'key2']));

        // Cluster mode should NOT use pipeline
        $clusterClient->shouldNotReceive('pipeline');

        // Should delete cache entries directly (sequential DEL)
        $clusterClient->shouldReceive('del')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2);

        // Should delete the tag sorted set
        $clusterConnection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries'], ['users']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeChunksLargeSets(): void
    {
        [$store, $clusterClient, $clusterConnection] = $this->createClusterStore();

        // Create more than CHUNK_SIZE (1000) entries
        $entries = [];
        for ($i = 1; $i <= 1500; ++$i) {
            $entries[] = "key{$i}";
        }

        // Mock GetEntries to return many cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection($entries));

        // Cluster mode should NOT use pipeline
        $clusterClient->shouldNotReceive('pipeline');

        // First chunk: 1000 entries (sequential DEL)
        $firstChunkArgs = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunkArgs[] = "prefix:key{$i}";
        }
        $clusterClient->shouldReceive('del')
            ->once()
            ->with(...$firstChunkArgs)
            ->andReturn(1000);

        // Second chunk: 500 entries (sequential DEL)
        $secondChunkArgs = [];
        for ($i = 1001; $i <= 1500; ++$i) {
            $secondChunkArgs[] = "prefix:key{$i}";
        }
        $clusterClient->shouldReceive('del')
            ->once()
            ->with(...$secondChunkArgs)
            ->andReturn(500);

        // Should delete the tag sorted set
        $clusterConnection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries'], ['users']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeWithMultipleTags(): void
    {
        [$store, $clusterClient, $clusterConnection] = $this->createClusterStore();

        // Mock GetEntries to return cache keys from multiple tags
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries', '_all:tag:posts:entries'])
            ->andReturn(new LazyCollection(['user_key1', 'user_key2', 'post_key1']));

        // Cluster mode should NOT use pipeline
        $clusterClient->shouldNotReceive('pipeline');

        // Should delete all cache entries (sequential DEL)
        $clusterClient->shouldReceive('del')
            ->once()
            ->with('prefix:user_key1', 'prefix:user_key2', 'prefix:post_key1')
            ->andReturn(3);

        // Should delete both tag sorted sets
        $clusterConnection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries', 'prefix:_all:tag:posts:entries')
            ->andReturn(2);

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries'], ['users', 'posts']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeWithNoEntries(): void
    {
        [$store, $clusterClient, $clusterConnection] = $this->createClusterStore();

        // Mock GetEntries to return empty collection
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection([]));

        // Cluster mode should NOT use pipeline
        $clusterClient->shouldNotReceive('pipeline');

        // No cache entries to delete - del should NOT be called on cluster client
        $clusterClient->shouldNotReceive('del');

        // Should still delete the tag sorted set
        $clusterConnection->shouldReceive('del')
            ->once()
            ->with('prefix:_all:tag:users:entries')
            ->andReturn(1);

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries'], ['users']);
    }
}
