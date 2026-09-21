<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations\AnyTag;

use Generator;
use Hypervel\Cache\Redis\Operations\AnyTag\Flush;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTaggedKeys;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

/**
 * Tests for the Flush operation (union tags).
 */
class FlushTest extends RedisCacheTestCase
{
    /**
     * @test
     */
    public function testFlushDeletesCacheEntriesReverseIndexesAndScannedFields(): void
    {
        $connection = $this->mockConnection();

        // Mock GetTaggedKeys to return cache keys
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['key1', 'key2']));

        $connection->expects('evalWithShaCache')
            ->with(
                m::type('string'),
                ['prefix:_any:tag:users:entries', 'prefix:key1:_any:tags', 'prefix:key2:_any:tags', 'prefix:key1', 'prefix:key2'],
                [1, 2, 'key1', 'key2'],
            )->andReturn(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->andReturn(1)->ordered();

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

        $connection->expects('evalWithShaCache')
            ->with(
                m::type('string'),
                ['prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries', 'prefix:user_key1:_any:tags', 'prefix:post_key1:_any:tags', 'prefix:user_key1', 'prefix:post_key1'],
                [2, 2, 'user_key1', 'post_key1'],
            )->andReturn(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries'], ['users', 'posts'])
            ->andReturn(1)->ordered();

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $operation = new Flush($store->getContext(), $getTaggedKeys);

        $result = $operation->execute(['users', 'posts']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushWithNoEntriesChecksForEmptyTags(): void
    {
        $connection = $this->mockConnection();

        // Mock GetTaggedKeys to return empty
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator([]));

        $connection->shouldNotReceive('del');
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->andReturn(1);

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

        $connection->expects('evalWithShaCache')
            ->with(
                m::type('string'),
                ['prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries', 'prefix:shared_key:_any:tags', 'prefix:user_only:_any:tags', 'prefix:post_only:_any:tags', 'prefix:shared_key', 'prefix:user_only', 'prefix:post_only'],
                [2, 3, 'shared_key', 'user_only', 'post_only'],
            )->andReturn(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries', 'prefix:_any:tag:posts:entries'], ['users', 'posts'])
            ->andReturn(1)->ordered();

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

        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['mykey']));

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['custom_prefix:_any:tag:users:entries', 'custom_prefix:mykey:_any:tags', 'custom_prefix:mykey'], [1, 1, 'mykey'])
            ->andReturn(1)->ordered();
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['custom_prefix:_any:tag:registry', 'custom_prefix:_any:tag:users:entries'], ['users'])
            ->andReturn(1)->ordered();

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
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');

        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->shouldReceive('execute')
            ->once()
            ->with('users')
            ->andReturn($this->arrayToGenerator(['key1', 'key2']));

        // Cluster mode: NO pipeline calls
        $connection->shouldNotReceive('pipeline');

        $connection->expects('hdel')
            ->with('prefix:_any:tag:users:entries', 'key1', 'key2')
            ->andReturn(2)->ordered();

        // Sequential del for reverse indexes
        $connection->shouldReceive('del')
            ->once()
            ->with('prefix:key1:_any:tags', 'prefix:key2:_any:tags')
            ->andReturn(2)->ordered();

        // Sequential unlink for cache keys
        $connection->shouldReceive('unlink')
            ->once()
            ->with('prefix:key1', 'prefix:key2')
            ->andReturn(2)->ordered();

        $connection->expects('hlen')->with('prefix:_any:tag:users:entries')->andReturn(0)->ordered();

        // Sequential zrem for registry
        $connection->shouldReceive('zrem')
            ->once()
            ->with('prefix:_any:tag:registry', 'users')
            ->andReturn(1)->ordered();
        $connection->expects('hlen')->with('prefix:_any:tag:users:entries')->andReturn(0)->ordered();

        $operation = new Flush($store->getContext(), $getTaggedKeys);
        $result = $operation->execute(['users']);
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testFlushClusterModeWithMultipleTags(): void
    {
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');

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
        $connection->expects('hdel')->with('prefix:_any:tag:users:entries', 'user_key', 'post_key')->andReturn(1);
        $connection->expects('hdel')->with('prefix:_any:tag:posts:entries', 'user_key', 'post_key')->andReturn(1);
        $connection->expects('hlen')->with('prefix:_any:tag:users:entries')->twice()->andReturn(0);
        $connection->expects('hlen')->with('prefix:_any:tag:posts:entries')->twice()->andReturn(0);
        $connection->shouldReceive('del')->andReturn(1);
        $connection->shouldReceive('unlink')->andReturn(1);
        $connection->shouldReceive('zrem')->andReturn(1);

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

        // Mock hlen/hkeys for GetTaggedKeys internal calls
        $connection->shouldReceive('hlen')
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(1);
        $connection->shouldReceive('hkeys')
            ->with('prefix:_any:tag:users:entries')
            ->andReturn(['mykey']);

        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:users:entries', 'prefix:mykey:_any:tags', 'prefix:mykey'], [1, 1, 'mykey'])
            ->andReturn(1);
        $connection->expects('evalWithShaCache')
            ->with(m::type('string'), ['prefix:_any:tag:registry', 'prefix:_any:tag:users:entries'], ['users'])
            ->andReturn(1);

        $store = $this->createStore($connection);
        $store->setTagMode('any');
        $result = $store->anyTagOps()->flush()->execute(['users']);
        $this->assertTrue($result);
    }

    public function testClusterFlushPreservesRegistrationWhenTheHashWasRepopulated(): void
    {
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')->with('users')->andReturn($this->arrayToGenerator([]));

        $connection->expects('hlen')->with('prefix:_any:tag:users:entries')->andReturn(1);
        $connection->shouldNotReceive('zrem');

        $this->assertTrue((new Flush($store->getContext(), $getTaggedKeys))->execute(['users']));
    }

    public function testClusterFlushRestoresRegistrationWhenAWriterRacesWithDeregistration(): void
    {
        [$store, , $connection] = $this->createClusterStore(tagMode: 'any');
        $getTaggedKeys = m::mock(GetTaggedKeys::class);
        $getTaggedKeys->expects('execute')->with('users')->andReturn($this->arrayToGenerator([]));

        $connection->expects('hlen')->with('prefix:_any:tag:users:entries')->andReturn(0)->ordered();
        $connection->expects('zrem')->with('prefix:_any:tag:registry', 'users')->andReturn(1)->ordered();
        $connection->expects('hlen')->with('prefix:_any:tag:users:entries')->andReturn(1)->ordered();
        $connection->expects('zadd')->with('prefix:_any:tag:registry', ['NX'], StoreContext::MAX_EXPIRY, 'users')->andReturn(1)->ordered();

        $this->assertTrue((new Flush($store->getContext(), $getTaggedKeys))->execute(['users']));
    }

    /**
     * Convert an array to a generator.
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
