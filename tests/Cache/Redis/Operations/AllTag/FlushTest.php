<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Operations\AllTag\Flush;
use Hypervel\Cache\Redis\Operations\AllTag\GetEntries;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the Flush operation.
 */
class FlushTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testFlushDeletesCacheEntriesAndScannedMemberships(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection(['key1', 'key2']));

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_all:tag:users:entries', 'prefix:key1', 'prefix:key2'], [1, 'key1', 'key2'])
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushWithMultipleTagsDeletesAllScannedEntries(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return cache keys from multiple tags
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries', '_all:tag:posts:entries'])
            ->andReturn(new LazyCollection(['user_key1', 'user_key2', 'post_key1']));

        $connection->expects('evalWithShaCache')
            ->with(
                m::type('string'),
                ['prefix:_all:tag:users:entries', 'prefix:_all:tag:posts:entries', 'prefix:user_key1', 'prefix:user_key2', 'prefix:post_key1'],
                [2, 'user_key1', 'user_key2', 'post_key1'],
            )
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries']);
    }

    /**
     * @test
     */
    public function testFlushWithNoEntriesDoesNotDeleteTagSets(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return empty collection
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection([]));

        $connection->shouldNotReceive('del');
        $connection->shouldNotReceive('evalWithShaCache');

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushChunksLargeEntrySets(): void
    {
        $connection = $this->mockConnection();

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

        // First chunk: 1000 entries.
        $firstChunkArgs = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunkArgs[] = "prefix:key{$i}";
        }
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_all:tag:users:entries', ...$firstChunkArgs], [1, ...array_slice($entries, 0, 1000)])
            ->andReturn(1);

        // Second chunk: 500 entries.
        $secondChunkArgs = [];
        for ($i = 1001; $i <= 1500; ++$i) {
            $secondChunkArgs[] = "prefix:key{$i}";
        }
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_all:tag:users:entries', ...$secondChunkArgs], [1, ...array_slice($entries, 1000)])
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    public function testFlushReleasesScanConnectionsBeforeCheckingOutEachDeletionChunk(): void
    {
        $connection = $this->mockConnection();
        $entries = [];

        for ($i = 1; $i <= 1001; ++$i) {
            $entries["key{$i}"] = 1;
        }

        $connection->shouldReceive('zScan')
            ->once()
            ->with('prefix:_all:tag:users:entries', null, '*', 1000)
            ->andReturnUsing(function ($key, &$cursor) use ($entries) {
                $cursor = 0;

                return $entries;
            });

        $firstChunk = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunk[] = "prefix:key{$i}";
        }

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_all:tag:users:entries', ...$firstChunk], [1, ...array_slice(array_keys($entries), 0, 1000)])
            ->andReturn(1);
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_all:tag:users:entries', 'prefix:key1001'], [1, 'key1001'])
            ->andReturn(1);

        $active = false;
        $proxy = m::mock(RedisProxy::class);
        $proxy->shouldReceive('isCluster')->once()->andReturnFalse();
        $proxy->shouldReceive('withConnection')
            ->times(3)
            ->with(m::type('callable'), false)
            ->andReturnUsing(function (callable $callback) use ($connection, &$active) {
                $this->assertFalse($active);
                $active = true;

                try {
                    return $callback($connection);
                } finally {
                    $active = false;
                }
            });

        $redis = m::mock(RedisFactory::class);
        $redis->shouldReceive('connection')
            ->times(4)
            ->with('default')
            ->andReturn($proxy);

        $store = new RedisStore($redis, 'prefix:', 'default');
        $context = $store->getContext();
        $operation = new Flush($context, new GetEntries($context));

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();

        // Mock GetEntries to return cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection(['mykey']));

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['custom_prefix:_all:tag:users:entries', 'custom_prefix:mykey'], [1, 'mykey'])
            ->andReturn(1);

        $store = $this->createStore($connection, 'custom_prefix:');
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushWithEmptyTagIds(): void
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

        $operation->execute([]);
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
            ->andReturn(new LazyCollection(['key']));

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_all:tag:my-special-tag:entries', 'prefix:key'], [1, 'key'])
            ->andReturn(1);

        $store = $this->createStore($connection);
        $operation = new Flush($store->getContext(), $getEntries);

        $operation->execute(['_all:tag:my-special-tag:entries']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeUsesSequentialDel(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Mock GetEntries to return cache keys
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection(['key1', 'key2']));

        // Cluster mode should NOT use pipeline
        $connection->shouldNotReceive('pipeline');

        $connection->expects('zrem')
            ->with('prefix:_all:tag:users:entries', 'key1', 'key2')
            ->andReturn(2)->ordered();

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2)->ordered();

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeChunksLargeSets(): void
    {
        [$store, , $connection] = $this->createClusterStore();

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
        $connection->shouldNotReceive('pipeline');

        // First chunk: 1000 entries (sequential DEL)
        $firstChunkArgs = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $firstChunkArgs[] = "prefix:key{$i}";
        }
        $connection->expects('zrem')
            ->with('prefix:_all:tag:users:entries', ...array_slice($entries, 0, 1000))
            ->andReturn(1000)->ordered();
        $connection->shouldReceive('del')
            ->once()
            ->with(...$firstChunkArgs)
            ->andReturn(1000)->ordered();

        // Second chunk: 500 entries (sequential DEL)
        $secondChunkArgs = [];
        for ($i = 1001; $i <= 1500; ++$i) {
            $secondChunkArgs[] = "prefix:key{$i}";
        }
        $connection->expects('zrem')
            ->with('prefix:_all:tag:users:entries', ...array_slice($entries, 1000))
            ->andReturn(500)->ordered();
        $connection->shouldReceive('del')
            ->once()
            ->with(...$secondChunkArgs)
            ->andReturn(500)->ordered();

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeWithMultipleTags(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Mock GetEntries to return cache keys from multiple tags
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries', '_all:tag:posts:entries'])
            ->andReturn(new LazyCollection(['user_key1', 'user_key2', 'post_key1']));

        // Cluster mode should NOT use pipeline
        $connection->shouldNotReceive('pipeline');

        $connection->expects('zrem')
            ->with('prefix:_all:tag:users:entries', 'user_key1', 'user_key2', 'post_key1')
            ->andReturn(2)->ordered();
        $connection->expects('zrem')
            ->with('prefix:_all:tag:posts:entries', 'user_key1', 'user_key2', 'post_key1')
            ->andReturn(1)->ordered();

        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:user_key1', 'prefix:user_key2', 'prefix:post_key1')
            ->andReturn(3)->ordered();

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries', '_all:tag:posts:entries']);
    }

    /**
     * @test
     */
    public function testFlushInClusterModeWithNoEntries(): void
    {
        [$store, , $connection] = $this->createClusterStore();

        // Mock GetEntries to return empty collection
        $getEntries = m::mock(GetEntries::class);
        $getEntries->shouldReceive('execute')
            ->once()
            ->with(['_all:tag:users:entries'])
            ->andReturn(new LazyCollection([]));

        // Cluster mode should NOT use pipeline
        $connection->shouldNotReceive('pipeline');

        $connection->shouldNotReceive('del');
        $connection->shouldNotReceive('zrem');

        $operation = new Flush($store->getContext(), $getEntries);
        $operation->execute(['_all:tag:users:entries']);
    }
}
