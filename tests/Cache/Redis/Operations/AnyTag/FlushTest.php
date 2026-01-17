<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Generator;
use Hypervel\Cache\Redis\Operations\AnyTag\Flush;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTaggedKeys;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the Flush operation (union tags).
 *
 * @internal
 * @coversNothing
 */
class FlushTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testFlushDeletesCacheEntriesReverseIndexesAndTagHashes(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock GetTaggedKeys to return cache keys
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['key1', 'key2']));

        // Pipeline mode expectations
        $client->shouldReceive('pipeline')->andReturn($client);

        // Should delete reverse indexes via pipeline
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:key1:_any:tags', 'prefix:key2:_any:tags')
            ->andReturn($client);

        // Should unlink cache entries via pipeline
        $client->shouldReceive('unlink')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn($client);

        // First exec for chunk processing
        $client->shouldReceive('exec')->andReturn([2, 2]);

        // Should delete the tag hash and remove from registry via pipeline
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn($client);
        $client->shouldReceive('zrem')
            ->once()
            ->with('prefix:_any:tag:registry', 'users')
            ->andReturn($client);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys);

        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushWithMultipleTags(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock GetTaggedKeys to return keys from multiple tags
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['user_key1']));
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('posts')
            ->andReturn($this->arrayToGenerator(['post_key1']));

        // Pipeline mode expectations
        $client->shouldReceive('pipeline')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('unlink')->andReturn($client);
        $client->shouldReceive('zrem')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys);

        $result = $operation->execute(['users', 'posts']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushWithNoEntriesStillDeletesTagHashes(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock GetTaggedKeys to return empty
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator([]));

        // Pipeline mode - only tag hash deletion, no chunk processing
        $client->shouldReceive('pipeline')->andReturn($client);
        $client->shouldReceive('del')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn($client);
        $client->shouldReceive('zrem')
            ->once()
            ->with('prefix:_any:tag:registry', 'users')
            ->andReturn($client);
        $client->shouldReceive('exec')->once()->andReturn([1, 1]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys);

        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushDeduplicatesKeysAcrossTags(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock GetTaggedKeys - both tags have 'shared_key'
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['shared_key', 'user_only']));
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('posts')
            ->andReturn($this->arrayToGenerator(['shared_key', 'post_only']));

        // Pipeline mode - shared_key should only appear once due to buffer deduplication
        $client->shouldReceive('pipeline')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('unlink')->andReturn($client);
        $client->shouldReceive('zrem')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys);

        $result = $operation->execute(['users', 'posts']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushUsesCorrectPrefix(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['mykey']));

        $client->shouldReceive('pipeline')->andReturn($client);

        // Should use custom prefix for reverse index
        $client->shouldReceive('del')
            ->once()
            ->with('custom_prefix:mykey:_any:tags')
            ->andReturn($client);

        // Should use custom prefix for cache key
        $client->shouldReceive('unlink')
            ->once()
            ->with('custom_prefix:mykey')
            ->andReturn($client);

        $client->shouldReceive('exec')->andReturn([1, 1]);

        // Should use custom prefix for tag hash
        $client->shouldReceive('del')
            ->once()
            ->with('custom_prefix:_any:tag:users:entries')
            ->andReturn($client);

        // Should use custom prefix for registry
        $client->shouldReceive('zrem')
            ->once()
            ->with('custom_prefix:_any:tag:registry', 'users')
            ->andReturn($client);

        $store = $this->createStore($connection, 'custom_prefix:');
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys);

        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushClusterModeUsesSequentialCommands(): void
    {
        [$store, $clusterClient] = $this->createClusterStore(tagMode: 'any');

        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['key1', 'key2']));

        // Cluster mode: NO pipeline calls
        $clusterClient->shouldNotReceive('pipeline');

        // Sequential del for reverse indexes
        $clusterClient->shouldReceive('del')
            ->once()
            ->with('prefix:key1:_any:tags', 'prefix:key2:_any:tags')
            ->andReturn(2);

        // Sequential unlink for cache keys
        $clusterClient->shouldReceive('unlink')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2);

        // Sequential del for tag hash
        $clusterClient->shouldReceive('del')
            ->once()
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);

        // Sequential zrem for registry
        $clusterClient->shouldReceive('zrem')
            ->once()
            ->with('prefix:_any:tag:registry', 'users')
            ->andReturn(1);

        $operation = new Flush($store->getContext(), $getTaggedKeys);
        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushClusterModeWithMultipleTags(): void
    {
        [$store, $clusterClient] = $this->createClusterStore(tagMode: 'any');

        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['user_key']));
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('posts')
            ->andReturn($this->arrayToGenerator(['post_key']));

        // Sequential commands for chunks
        $clusterClient->shouldReceive('del')->andReturn(1);
        $clusterClient->shouldReceive('unlink')->andReturn(1);
        $clusterClient->shouldReceive('zrem')->andReturn(1);

        $operation = new Flush($store->getContext(), $getTaggedKeys);
        $result = $operation->execute(['users', 'posts']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushViaRedisStoreMethod(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->_mockClient;

        // Mock hlen/hkeys for GetTaggedKeys internal calls
        $client->shouldReceive('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);
        $client->shouldReceive('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['mykey']);

        // Pipeline mode
        $client->shouldReceive('pipeline')->andReturn($client);
        $client->shouldReceive('del')->andReturn($client);
        $client->shouldReceive('unlink')->andReturn($client);
        $client->shouldReceive('zrem')->andReturn($client);
        $client->shouldReceive('exec')->andReturn([]);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $result = $store->anyTagOps()->flush()->execute(['users']);
        $this->assertTrue($result);
    }

    /**
     * Helper to convert array to generator.
     *
     * @param array<string> $items
     * @return Generator<string>
     */
    private function arrayToGenerator(array $items): Generator
    {
        foreach ($items as $item) {
            yield $item;
        }
    }
}
